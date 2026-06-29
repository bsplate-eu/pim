<?php

namespace App\Exports\Admin;

use App\Models\Attribute;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithHeadings;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductsExport implements FromQuery, WithHeadings, WithMapping
{
    protected mixed $request;
    /**
     * @var \Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection
     */
    private \Illuminate\Database\Eloquent\Collection $attributes;

    public function __construct($request)
    {
        $this->request = $request;
        app()->setLocale($request['locale']);
        $this->attributes = Attribute::query()->get();
    }


    public function headings(): array
    {
        $data = [
            "id",
            "source_id",
            "external_id",
            "ean",
            "product_code",
            "categories",
            "category",
            "name",
            "width",
            "weight",
            "images",
            "info_1",
            "info_2",
            "info_3",
            "enabled",
        ];

        foreach ($this->attributes as $attribute) {
            $slug = Str::slug($attribute->name, '_');
            $data[] = "attribute_{$slug}";
        }

        return $data;
    }

    public function query()
    {
        return Product::query()
            ->with('media', 'attributeValues', 'categories')
            ->where('source_id', $this->request['source_id']);
    }

    public function map($row): array
    {
        $data = [
            $row->id,
            $this->request['source_id'],
            $row->external_id,
            $row->ean,
            $row->product_code,
            $row->categories->pluck('name')->filter()->implode(','),
            $row->category,
            $row->name,
            $row->width,
            $row->weight,
            $row->getMedia('images')->sortBy('order_column')->implode('original_url', ','),
            $row->info_1,
            $row->info_2,
            $row->info_3,
            (int)$row->enabled,
        ];

        foreach ($this->attributes as $attribute) {
            $data[] = $row->attributeValues->where('attribute_id', $attribute->id)->implode('name', ',') ?? '';
        }

        return $data;
    }
}
