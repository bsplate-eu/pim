<?php

namespace App\Models;

use App\Models\Traits\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Attribute extends Model {

    use HasTranslations;
    use Sluggable;

    protected $table = 'attributes';
    protected $fillable = ['name', 'slug', 'order'];
    public $translatable = ['name'];

    public function sluggable(): array
    {
        return [
            'source' => 'name',
            'separator' => '-'
        ];
    }

    public function values()
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function storeValues($attribute_values){
        $attribute_values = collect($attribute_values);

        $this->values()->whereNotIn('id', $attribute_values->pluck('id')->toArray())->delete();

        $new_attribute_values = $attribute_values->filter(fn($value) => !isset($value['id']));
        if ($new_attribute_values->count()) {
            $this->values()->createMany($new_attribute_values->values()->toArray());

        }

        $edited_attribute_values = $attribute_values->filter(fn($value) => isset($value['id']));
        if ($edited_attribute_values->count()) {
            $edited_attribute_values->each(fn($value) => AttributeValue::where('id', $value['id'])->update(['name' => $value['name']]));
        }
    }
}
