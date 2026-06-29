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
class DogdesignProductsImport implements ToCollection, WithChunkReading, WithHeadingRow, WithCustomCsvSettings
{

    public function __construct()
    {
        $this->source = Source::firstOrCreate(['service_class' => 'GroomershopSource'], ['name' => 'dogdesign.pl']);
        $this->root_category = Category::firstOrCreate(['name'=> 'dogdesign.pl'], ['parent_id' => null]);
        $this->pricelist = Pricelist::firstOrCreate(['slug' => 'dogdesign'], ['name' => 'dogdesign.pl', 'currency' => 'PLN']);
        $this->integration = Integration::with('integrationSources')->firstOrCreate(['name' => 'dogdesign.pl'], [
            'category_id' => $this->root_category->id,
            'type' => 'prestashop',
            'manufacturer' => 'dogdesign.pl',
            'name' => 'dogdesign.pl',
            'key' => 'dogdesign.pl',
            'url' => 'https://dogdesign.pl/',
            'enabled' => false,
        ]);

        $this->integrationSource = $this->integration->integrationSources->first();
    }

    public function collection(Collection $rows)
    {
        $prices = [];
        $integrationProducts = [];
        foreach ($rows as $data) {
            $product = Product::firstOrCreate([
                'source_id' => $this->source->id,
                'external_id' => $data['indeks'],
            ], [
                'product_code' => $data['indeks'],
                'category' => $data['kategoria'],
                'name' => ['pl' => $data['nazwa']],
                'enabled' => true,
            ]);

            $prices[] = [
                'pricelist_id' => $this->pricelist->id,
                'product_id' => $product->id,
                'price' => (float)$data['cena_netto'],
            ];

            $integrationProducts[] = [
                'integration_id' => $this->integration->id,
                'integration_source_id' => $this->integrationSource->id,
                'product_id' => $product->id,
                'external_id' => $data['product_id'],
                'synced_at' => now()->toDateTimeString(),
            ];

            $category = Category::firstOrCreate([
                'parent_id' => $this->root_category->id,
                'name' => $data['kategoria']
            ], [
                'parent_id' => $this->root_category->id,
                'name' => $data['kategoria']
            ]);

            $product->categories()->sync([$category->id]);

//            if (!empty($data['obraz'])) {
//                try {
//                    $product->addMediaFromUrl($data['obraz'])->toMediaCollection('images');
//                } catch (\Exception $exception) {
//                    dump($exception->getMessage());
//                }
//            }

        }

        IntegrationProduct::upsert($integrationProducts, ['integration_id', 'product_id']);

        PricelistProduct::upsert($prices, ['pricelist_id', 'product_id'], ['price']);
    }


    public function chunkSize(): int
    {
        return 100; // Przetwarzaj po 1000 wierszy, np.
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
            'input_encoding' => 'UTF-8',
        ];
    }
}
