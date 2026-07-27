<?php

namespace App\Models;

use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;
use App\Media\ProcessMediaTrait;
use App\Media\AutoProcessMediaTrait;
use App\Media\InteractsWithMedia;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use App\Media\HasMediaPreviewsTrait;

class Product extends Model implements HasMedia
{

    use HasTranslations;
    use ProcessMediaTrait;
    use AutoProcessMediaTrait;
    use InteractsWithMedia;
    use HasMediaPreviewsTrait;

    const ALLOWED_MEDIA_TYPES = ['image/jpeg','image/jpg', 'image/png', 'image/gif'];
    const MAX_MEDIA_SIZE = 1024 * 1024 * 2;

    protected $table = 'products';
    protected $fillable = [
        'source_id',
        'external_id',
        'ean',
        'category',
        'name',
        'product_code',
        'width',
        'weight',
        'purchase_price_manual',
        'comment',
        'enabled',
        'info_1',
        'info_2',
        'info_3',
        'meta_url',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public $translatable = ['name', 'info_1', 'info_2', 'info_3', 'meta_url', 'meta_title', 'meta_description', 'meta_keywords',];


    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->acceptsMimeTypes(self::ALLOWED_MEDIA_TYPES);
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this->autoRegisterPreviews();
    }

    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    /**
     * Atrybuty ustawiane WYŁĄCZNIE ręcznie w PIM (nie niesie ich żadne źródło ani
     * import), więc zwykły `attributeValues()->sync()` je kasował.
     */
    const PRESERVED_ATTRIBUTE_SLUGS = ['material'];

    /**
     * Sync wartości atrybutów z zachowaniem atrybutów chronionych.
     *
     * Zasada per atrybut: jeśli przychodzący zestaw NIESIE wartość chronionego
     * atrybutu — wygrywa zestaw (świadoma zmiana). Jeśli nie niesie nic dla tego
     * atrybutu — bieżąca wartość produktu zostaje nietknięta.
     */
    public function syncAttributeValuesPreserving(array $attribute_value_ids): void
    {
        $ids = collect($attribute_value_ids)->map(fn($id) => (int) $id);
        $protected = self::protectedAttributeValues();   // [value_id => attribute_id]

        if ($protected->isNotEmpty()) {
            $incomingAttributes = $ids->map(fn($id) => $protected->get($id))->filter()->unique();

            $keep = $this->attributeValues()->pluck('attribute_values.id')
                ->map(fn($id) => (int) $id)
                ->filter(fn($id) => $protected->has($id)
                    && !$incomingAttributes->contains($protected->get($id)));

            $ids = $ids->merge($keep)->unique()->values();
        }

        $this->attributeValues()->sync($ids->all());
    }

    /** Mapa [attribute_value_id => attribute_id] dla atrybutów chronionych. */
    private static function protectedAttributeValues()
    {
        return Cache::remember('protected_attribute_values', 3600, fn() => AttributeValue::query()
            ->whereHas('attribute', fn($q) => $q->whereIn('slug', self::PRESERVED_ATTRIBUTE_SLUGS))
            ->pluck('attribute_id', 'id'))
            ->map(fn($attributeId) => (int) $attributeId);
    }

    public function pricelists()
    {
        return $this->belongsToMany(Pricelist::class)->withPivot('price');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function storeAttributes($attribute_values)
    {
        $attributes = Attribute::all()->keyBy('slug');
        $attribute_values = collect($attribute_values);
        $locales = app(GeneralSettings::class)->available_locales;

        $attribute_values = $attribute_values->map(function ($values, $slug) use ($attributes, $locales) {
            foreach ($values as $index => $value) {
                if (is_string($value) && !empty($value)) {
                    $attribute = $attributes->get($slug);
                    if($attribute){
                        $name = [];
                        foreach ($locales as $locale) {
                            $name[$locale] = $value;
                        }
                        $slug = Str::slug($value, '_');
                        $attribute_value = AttributeValue::firstOrCreate(
                            ['attribute_id' => $attribute->id, 'slug' => $slug],
                            ['name' => $name]
                        );
                        $values[$index] = $attribute_value?->id;
                    }

                }
            }

            return array_filter($values);

        });

        $this->syncAttributeValuesPreserving($attribute_values->flatten()->toArray());
    }


    public function getVariables(string $locale = 'en')
    {
        $defaults = $this->toArray();

        Cache::remember('attributes', 3600, function () {
            return Attribute::all()->keyBy('id');
        })->each(function ($attribute) use (&$defaults) {
            $slug = Str::slug($attribute->slug, '_');
            $values = $this->attributeValues->where('attribute_id', $attribute->id);
            $defaults["attribute_$slug"] = $values->count() > 0 ? $values->implode('name', ', ') : null;
        });

        return array_merge($defaults, [
            'name' => $this->getTranslation('name', $locale),
            'info_1' => $this->getTranslation('info_1', $locale),
            'info_2' => $this->getTranslation('info_2', $locale),
            'info_3' => $this->getTranslation('info_3', $locale),
        ]);

    }
}
