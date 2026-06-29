<?php

namespace App\Models\Traits;

use Illuminate\Support\Str;

trait Sluggable
{
    protected static function bootSluggable()
    {
        static::creating(function ($model) {
            $source = $model->sluggable()['source'] ?? 'name';
            $separator = $model->sluggable()['separator'] ?? '-';

            $slug = $model->slug ?? Str::slug($model->$source, $separator);

            if (self::query()
                    ->when($model->exists, fn($q) => $q->where('id', '<>', $model->id))
                    ->where('slug', $slug)
                    ->count() > 0) {

                $index = 1;
                while (self::query()->where('slug', "$slug-$index")->count() > 0) {
                    $index++;
                }
                $slug = "$slug-$index";
            }

            $model->setAttribute('slug', $slug);
        });
    }

    abstract public function sluggable(): array;
}
