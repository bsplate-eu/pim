<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use App\Models\Source;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
class VirsalProductsImport implements ToCollection, WithChunkReading, WithHeadingRow, WithCustomCsvSettings
{

    public function __construct()
    {
        $name = 'Virsal';
        $this->source = Source::firstOrCreate(['name' => $name]);
        $this->root_category = Category::firstWhere([
            ['parent_id', '=', null],
            ['name->pl', '=', $name],
        ]) ?? Category::create([
            'parent_id' => null,
            'name' => ['pl' => $name],
        ]);
        $this->pricelist = Pricelist::firstOrCreate(['slug' => 'virsal'], ['name' => 'Virsal', 'currency' => 'PLN']);
    }

    public function collection(Collection $rows)
    {
        $prices = [];
        foreach ($rows as $data) {

            $categories = explode('>', $data['product_category']);

            $product = Product::updateOrCreate([
                'source_id' => $this->source->id,
                'external_id' => $data['model'],
            ], [
                'product_code' => $data['model'],
                'category' => $categories[array_key_last($categories)],
                'name' => ['pl' => $data['name_pl']],
                'info_1' => ['pl' => $data['description_pl']],
                'enabled' => true,
            ]);

            $prices[] = [
                'pricelist_id' => $this->pricelist->id,
                'product_id' => $product->id,
                'price' => (float)$data['price'],
            ];

            $parent_id = $this->root_category->id;
            foreach ($categories as $categoryName) {
                $category = $this->findOrCreateCategory($categoryName, $parent_id);
                $parent_id = $category->id;
            }

            if(isset($category)){
                $product->categories()->sync([$category->id]);
            }


            if ($product->media()->count() == 0 && !empty($data['image'])) {
                try {
                    $url = $this->encodeUrl($data['image']);
                    $product->addMediaFromUrl($url)->toMediaCollection('images');
                } catch (\Exception $exception) {
                    dump($exception->getMessage());
                }
            }

        }

        PricelistProduct::upsert($prices, ['pricelist_id', 'product_id'], ['price']);
    }
    private function encodeUrl(string $url): string
    {
        $parts = parse_url($url);

        $scheme = $parts['scheme'] ?? 'http';
        $host   = $parts['host']   ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path']   ?? '';
        $query  = isset($parts['query']) ? '?' . $parts['query'] : '';
        $frag   = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        // Rozbij ścieżkę na segmenty i encoduj każdy po urldecode (żeby nie dublować %)
        $segments = array_map('urldecode', explode('/', $path));
        $segments = array_map('rawurlencode', $segments);
        $encodedPath = implode('/', $segments);

        return "{$scheme}://{$host}{$port}{$encodedPath}{$query}{$frag}";
    }
    private function findOrCreateCategory(string $name, ?int $parentId = null): Category
    {
        $q = Category::query()
            ->where('parent_id', $parentId)
            ->where('name->pl', $name);

        if ($cat = $q->first()) {
            return $cat;
        }

        $cat = new Category([
            'name' => ['pl' => $name],
        ]);

        if ($parentId) {
            $parent = Category::findOrFail($parentId);
            $cat->appendToNode($parent)->save();
        }

        return $cat->refresh();
    }


    public function chunkSize(): int
    {
        return 100; // Przetwarzaj po 1000 wierszy, np.
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'input_encoding' => 'UTF-8',
        ];
    }
}
