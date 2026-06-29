<?php

namespace App\Jobs;

use App\Models\IntegrationProduct;
use App\Models\Pricelist;
use App\Models\PricelistProduct;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportProductsEans implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $products = [];

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

        $integration_products = IntegrationProduct::where('integration_id', 5)->get()->keyBy('external_id');
        $input_path = storage_path("/app/ean.csv");

        $headers = [];
        $row = 0;
        $input = fopen($input_path, 'r');
        while (($line = fgetcsv($input, null, ';')) !== FALSE) {
            if ($row == 0) {
                $headers = array_map(function($header) {
                    return preg_replace('/^\x{FEFF}/u', '', $header);
                }, $line);
            } else {
                $line = array_pad($line, count($headers), null);
                $line = array_combine($headers, $line);

                if(!empty($line['external_id'])){
                    $ip = $integration_products->get($line['external_id']);
                    if($ip){
                        Product::where('id', $ip->product_id)->update([
                          'ean' => $line['ean']
                        ]);
                    }
                }


            }
            $row++;
        }
        fclose($input);

    }
}
