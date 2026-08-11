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
 *
 * WZNAWIALNE: aukcje już zapisane w dzisiejszym JSON-ie są pomijane, nowe wyniki dopisywane —
 * całość (3338 aukcji ≈ godzina) można przelecieć porcjami: kilka wywołań z --limit=500.
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

        // Wznowienie: pomiń aukcje już sprawdzone dziś (dopisujemy do tego samego pliku).
        $path = 'ebay/compat-audit-' . now()->format('Y-m-d') . '.json';
        $existing = Storage::exists($path)
            ? collect(json_decode(Storage::get($path), true) ?: [])
            : collect();
        $doneIds = $existing->pluck('item_id')->flip();

        $items = $q->get()->unique('item_id')->reject(fn ($o) => $doneIds->has($o->item_id))->values();
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $items = $items->take($limit);
        }

        if ($items->isEmpty()) {
            $this->info("Nic do zrobienia — wszystkie aukcje już sprawdzone ({$existing->count()} w storage/app/{$path}).");
            $this->summarize($existing);

            return self::SUCCESS;
        }

        $delayMs = max(0, (int) $this->option('delay'));
        $this->info("Aukcji do sprawdzenia: {$items->count()}" . ($limit > 0 ? " (limit {$limit})" : '') . ($doneIds->count() > 0 ? " (pominięto już sprawdzone: {$doneIds->count()})" : ''));

        $rows = [];
        $done = 0;

        foreach ($items as $offer) {
            try {
                $compat = $client->itemCompatibility($offer->item_id, $offer->marketplace);
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
                $rows[] = [
                    'item_id' => $offer->item_id,
                    'marketplace' => $offer->marketplace,
                    'title' => $offer->title,
                    'product_id' => $offer->product_id,
                    'error' => $e->getMessage(),
                ];
            }

            $done++;
            if ($done % 50 === 0) {
                $this->line("… {$done}/{$items->count()}");
                // Zapis częściowy — przerwana porcja nie traci wyników.
                Storage::put($path, json_encode($existing->concat($rows)->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $all = $existing->concat($rows)->values();
        Storage::put($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->summarize(collect($all));
        $this->info("Szczegóły: storage/app/{$path}");

        return self::SUCCESS;
    }

    /** Tabelka per rynek z całości pliku (existing + bieżąca porcja). */
    private function summarize($rows): void
    {
        $stats = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $mp = $r['marketplace'] ?? '?';
            $key = isset($r['error']) ? 'error' : ((($r['compat_count'] ?? 0) > 0) ? 'with' : 'without');
            $stats[$mp] = $stats[$mp] ?? ['with' => 0, 'without' => 0, 'error' => 0];
            $stats[$mp][$key]++;
        }

        $this->newLine();
        $this->table(
            ['Rynek', 'Z fitmentem', 'Bez fitmentu', 'Błąd'],
            collect($stats)->map(fn ($s, $mp) => [$mp, $s['with'], $s['without'], $s['error']])->values()
        );
    }
}
