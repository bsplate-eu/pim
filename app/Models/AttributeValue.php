<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class AttributeValue extends Model {

    use HasTranslations;

    protected $table = 'attribute_values';
    protected $fillable = ['attribute_id', 'name', 'slug'];

    public $translatable = ['name'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $slug = $model->slug ?? Str::slug($model->name);
            if (self::query()->when($model->exists, fn($q) => $q->where('id', '<>', $model->id))->where('attribute_id', $model->attribute_id)->where('slug', $slug)->count() > 0) {
                $index = 1;
                while (self::query()->where('slug', "$slug-$index")->count() > 0) {
                    $index++;
                }

                $slug = "$slug-$index";
            }

            $model->setAttribute('slug', $slug);
        });
    }

    public function attribute() {
        return $this->belongsTo(Attribute::class);
    }
}
