<?php

namespace App\Imports;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    private Collection $products;
    private string $locale;
    private Collection $attributes;
    private Collection $categories;

    public function __construct($request)
    {
        $this->locale = $request['locale'];
        app()->setLocale($this->locale);
        $this->attributes = Attribute::query()->with('values')->get()->keyBy('slug');
        $this->categories = Category::query()->get();
    }

    /**
    * @param Collection $collection
    */
    public function collection(Collection $collection)
    {
        $this->products = Product::query()->whereIn('id', $collection->pluck('id'))->get()->keyBy('id');

        $collection->each(function ($row) {
            $data = [
                'source_id' => $row['source_id'],
                'external_id' => $row['external_id'],
                'ean' => $row['ean'],
                'product_code' => $row['product_code'],
                'category' => $row['category'],
                'name' => $row['name'],
                'width' => $row['width'],
                'weight' => $row['weight'],
                'info_1' => $row['info_1'],
                'info_2' => $row['info_2'],
                'info_3' => $row['info_3'],
                'enabled' => $row['enabled'] ?? 0,
            ];
            $product = $this->products->get($row['id']);
            if($product) {
                $product->update($data);
            }else{
                $product = Product::create($data);
            }

            $category_ids = $this->categories->whereIn('name', explode(',',$row['categories']))->pluck('id')->toArray();
            $product->categories()->sync($category_ids);


            $attribute_values = $this->getAttributes($row);
            $product->attributeValues()->sync($attribute_values);
        });


    }

    private function getAttributes($row)
    {

        $attributes = [];
        collect($row)
            ->filter(fn($values, $key) => str_contains('attribute_', $key))
            ->each(function ($values, $key) use (&$attributes) {

            $attribute = $this->attributes->get(Str::slug(str_replace('attribute_', '', $key)));

            if ($attribute) {

                $attribute_values = $attribute->values->keyBy("name.{$this->locale}");
                $source_values = explode(',', $values);

                foreach ($source_values as $value) {
                    if (!empty($value)) {
                        $value = trim($value);
                        $slug = Str::slug($value);
                        $attribute_value = $attribute_values->get($slug);
                        if($attribute_value) {
                            $attributes[] = $attribute_value->id;
                        }
                    }
                }
            }
        });

        return $attributes;
    }
}
