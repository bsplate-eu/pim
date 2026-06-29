<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\NodeTrait;
use Spatie\Translatable\HasTranslations;

class Category extends Model
{

    use HasTranslations;
    use NodeTrait;

    protected $table = 'categories';
    protected $fillable = ['_lft', '_rgt', 'parent_id', 'name', 'external_id'];

    public $translatable = ['name'];

    public static function toTreeSelect(array $excluded = [])
    {
        return self::query()
            ->get()
            ->whereNotIn('id', $excluded)
            ->toTree()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'label' => $category->name,
                    'children' => self::mapChildren($category->children),
                ];
            });
    }


    private static function mapChildren($children)
    {
        return $children->map(function ($category) {
            return [
                'id' => $category->id,
                'label' => $category->name,
                'children' => self::mapChildren($category->children),
            ];
        });
    }
}
