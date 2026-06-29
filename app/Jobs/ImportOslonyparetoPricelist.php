<?php

namespace App\Jobs;

use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class ImportOslonyparetoPricelist implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $products;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        $pricelist = Pricelist::firstOrCreate(['slug' => 'oslonyparetopl'], [
            'name' => 'oslonypareto.pl',
            'currency' => 'PLN',
        ]);

        $input_path = storage_path("/app/oslonypareto_pl/pricelist.csv");

        $headers = [];
        $row = 0;
        $input = fopen($input_path, 'r');
        while (($line = fgetcsv($input, null, ';')) !== FALSE) {
            if ($row == 0) {
                $headers = $line;
            } else {
                $line = array_pad($line, count($headers), null);
                $line = array_combine($headers, $line);
                if(!empty($line['external_id'])){

                    $this->products[(int)$line['external_id']] = $line;
                }


            }
            $row++;
        }
        fclose($input);


        $prices = Product::select(['id','external_id'])->get()->map(function ($item) use ($pricelist) {
            $product = $this->products[$item->external_id] ?? null;

            if (!$product) {
                return null;
            }

            return [
                'pricelist_id' => $pricelist->id,
                'product_id' => $item->id,
                'price' => (float)$product['price'],
            ];
        })->filter()->toArray();

        PricelistProduct::upsert($prices, ['pricelist_id', 'product_id'], ['price']);
    }
}

