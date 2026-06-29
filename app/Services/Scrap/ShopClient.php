<?php

namespace App\Services\Scrap;

use Generator;

/**
 * Driver „mięśnie" pojedynczego sklepu konkurenta. Każde źródło ma swój (lokopi / rumunia / …),
 * a wspólny silnik (ShopScrapService) konsumuje znormalizowany strumień produktów.
 */
interface ShopClient
{
    /**
     * Strumień znormalizowanych produktów. Każdy element:
     *   ['external_id','title','price','currency','herstellernummer','ean','url','raw'].
     *
     * @param int           $delayMs    opóźnienie między żądaniami (uprzejmość)
     * @param callable|null $onProgress fn(int $done, int $total)
     */
    public function products(int $delayMs = 200, ?callable $onProgress = null): Generator;
}
