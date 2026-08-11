<?php

namespace App\Console\Commands;

use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOAuthService;
use App\Services\Ebay\EbaySellClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Etap 0 integracji kType: DIAGNOZA — ile naszych aukcji ma listę kompatybilności pojazdów
 * (fitment), a ile świeci pustką. Sam odczyt (GetItem), niczego nie zmienia na eBay.
 *
 * Użycie:  php artisan ebay:compat-audit [--marketplace=EBAY_DE] [--limit=50] [--delay=300]
 * Wynik: podsumowanie per rynek + szczegóły do storage/app/ebay/compat-audit-YYYY-MM-DD.json
 * (props pierwszego wpisu per aukcja — żeby zobaczyć, czy fitment jest po KType czy Make/Model).
 */
class EbayCompatAudit extends Command
{
    protected $signature = 'ebay:compat-audit
        {--marketplace= : tylko jeden rynek (EBAY_DE/EBAY_FR/…)}
        {--limit=0 : max aukcji do sprawdzenia (0 = wszystkie)}
        {--delay=300 : pauza między wywołaniami GetItem, ms}';

    protected $description = 'Audyt kompatybilności pojazdów (kType/fitment) na naszych aukcjach eBay — sam odczyt';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            $this->error('Konto eBay nie jest połączone (OAuth). Połącz w Connect → Integracje → Ebay.');

            return self::FAILURE;
        }

        $client = new EbaySellClient($settings, new EbayOAuthService($settings));

        // Jedna aukcja (item_id) może mieć wiele wierszy (warianty) — audyt robimy per aukcja.
        $q = EbayOffer::query()
            ->where('listing_status', 'Active')
            ->when($this->option('marketplace'), fn ($q, $mp) => $q->where('marketplace', strtoupper($mp)))
            ->select('item_id', 'marketplace', 'title', 'product_id')
            ->groupBy('item_id', 'marketplace', 'title', 'product_id');

        $items = $q->get()->unique('item_id')->values();
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $items = $items->take($limit);
        }

        $delayMs = max(0, (int) $this->option('delay'));
        $this->info("Aukcji do sprawdzenia: {$items->count()}" . ($limit > 0 ? " (limit {$limit})" : ''));

        $rows = [];
        $stats = []; // marketplace => ['with'=>n, 'without'=>n, 'error'=>n]
        $done = 0;

        foreach ($items as $offer) {
            try {
                $compat = $client->itemCompatibility($offer->item_id, $offer->marketplace);
                $has = $compat['count'] > 0;
                $key = $has ? 'with' : 'without';
                $rows[] = [
                    'item_id' => $offer->item_id,
                    'marketplace' => $offer->marketplace,
                    'title' => $offer->title,
                    'product_id' => $offer->product_id,
                    'compat_count' => $compat['count'],
                    // Próbka: jak zapisany jest fitment (KType? Make/Model?) — pierwszy wpis wystarczy.
                    'sample' => $compat['list'][0]['props'] ?? null,
                ];
            } catch (\Throwable $e) {
                $key = 'error';
                $rows[] = [
                    'item_id' => $offer->item_id,
                    'marketplace' => $offer->marketplace,
                    'title' => $offer->title,
                    'product_id' => $offer->product_id,
                    'error' => $e->getMessage(),
                ];
            }

            $mp = $offer->marketplace ?: '?';
            $stats[$mp] = $stats[$mp] ?? ['with' => 0, 'without' => 0, 'error' => 0];
            $stats[$mp][$key]++;

            $done++;
            if ($done % 50 === 0) {
                $this->line("… {$done}/{$items->count()}");
            }
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $path = 'ebay/compat-audit-' . now()->format('Y-m-d') . '.json';
        Storage::put($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->table(
            ['Rynek', 'Z fitmentem', 'Bez fitmentu', 'Błąd'],
            collect($stats)->map(fn ($s, $mp) => [$mp, $s['with'], $s['without'], $s['error']])->values()
        );
        $this->info("Szczegóły: storage/app/{$path}");

        return self::SUCCESS;
    }
}
