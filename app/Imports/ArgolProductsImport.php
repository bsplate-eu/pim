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
class ArgolProductsImport implements ToCollection, WithChunkReading, WithHeadingRow, WithCustomCsvSettings
{

    public function __construct()
    {
        $name = 'WeltPlast';
        $this->source = Source::firstOrCreate(['name' => $name]);
        $this->root_category = Category::firstWhere([
            ['parent_id', '=', null],
            ['name->pl', '=', $name],
        ]) ?? Category::create([
            'parent_id' => null,
            'name' => ['pl' => $name],
        ]);
        $this->pricelist = Pricelist::firstOrCreate(['slug' => 'weltplast'], ['name' => 'WeltPlast', 'currency' => 'EUR']);
    }

    public function collection(Collection $rows)
    {
        $prices = [];
        foreach ($rows as $data) {

            if(empty($data['index']) || empty($data['nazwa']) || empty($data['euro'])){
                continue;
            }

            $product = Product::updateOrCreate([
                'source_id' => $this->source->id,
                'external_id' => $data['index'],
            ], [
                'product_code' => $data['index'],
                'name' => ['pl' => $data['nazwa']],
                'info_1' => ['pl' => "Wysokość: {$data['wys_cm']}cm"],
                'enabled' => true,
            ]);

            $prices[] = [
                'pricelist_id' => $this->pricelist->id,
                'product_id' => $product->id,
                'price' => (float)$data['euro'],
            ];

        }

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
