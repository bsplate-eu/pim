<?php

namespace App\Jobs;

use App\Models\Scrap\ScrapSource;
use App\Services\Scrap\ShopScrapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pomiar sklepu konkurenta w tle (przyciski „Pobierz z …" w Argo Scope → Rumuni).
 * Wymaga workera kolejki (na prodzie: scheduled `queue:work … --queue=…,default`).
 */
class RunShopSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3900; // 65 min — Węgry (motorvedolemezek.com) wolne ~1.5s/stronę → ~45 min

    public function __construct(public string $source, public ?int $limit = null) {}

    public function handle(): void
    {
        if (! ShopScrapService::isShop($this->source)) {
            return;
        }

        ScrapSource::updateOrCreate(['source' => $this->source], ['last_status' => 'running', 'last_error' => null]);

        try {
            ShopScrapService::make($this->source)->fullSync($this->limit);
        } catch (\Throwable $e) {
            ScrapSource::updateOrCreate(
                ['source' => $this->source],
                ['last_status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 500)],
            );
            throw $e;
        }
    }
}
