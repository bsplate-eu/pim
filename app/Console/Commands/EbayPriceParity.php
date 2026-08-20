<?php

namespace App\Console\Commands;

use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Models\Scrap\ScrapProduct;
use App\Services\Ebay\EbayOAuthService;
use App\Services\Ebay\EbayScrapService;
use App\Services\Ebay\EbaySellClient;
use Illuminate\Console\Command;

/**
 * Wyrównanie NASZYCH cen eBay 1:1 do cen konkurenta ze scrapingu (Argo Scope).
 *
 * Świadomie OMIJA cenniki. Cena konkurenta i nasza są brutto w tej samej walucie rynku,
 * więc droga „brutto → netto przez VAT → z powrotem brutto" tylko gubi grosze i wciąga
 * cudze reguły (clamp cennika porównawczego, pozycje-sierotki, nocne nadpisania cennika #18).
 *
 * Odniesienie = NAJNIŻSZA aktywna oferta konkurenta zmapowana na nasz produkt. Konkurent
 * wystawia ten sam Art.-Nr wielokrotnie (do 13 ofert, rozrzut nawet +136%), a klient w wynikach
 * eBay widzi właśnie najtańszą.
 *
 * Domyślnie DRY-RUN — bez `--apply` nic nie leci na eBay.
 *   php artisan ebay:price-parity EBAY_DE
 *   php artisan ebay:price-parity EBAY_DE --max-drop=20 --apply
 */
class EbayPriceParity extends Command
{
    protected $signature = 'ebay:price-parity
        {marketplace=EBAY_DE : rynek naszych aukcji (EBAY_DE|EBAY_FR|EBAY_ES|EBAY_IT|EBAY_GB|EBAY_CH)}
        {--apply : REALNIE wyślij nowe ceny na eBay (bez tej flagi tylko raport)}
        {--max-drop= : pomiń oferty, które spadłyby o więcej niż N%}
        {--max-raise= : pomiń oferty, które wzrosłyby o więcej niż N%}
        {--limit= : ogranicz liczbę wysyłanych ofert (test na małej próbce)}';

    protected $description = 'Wyrównuje ceny naszych aukcji eBay 1:1 do najniższej ceny konkurenta ze scrapingu';

    public function handle(): int
    {
        $marketplace = strtoupper((string) $this->argument('marketplace'));

        $source = collect(EbayScrapService::MARKETS)
            ->search(fn (array $m) => $m['marketplace'] === $marketplace);

        if ($source === false) {
            $this->error("Nieznany rynek: {$marketplace}.");

            return self::FAILURE;
        }

        // Cena konkurenta per NASZ produkt. Tylko aktywne, zmapowane i nie-wykluczone oferty;
        // przy kilku ofertach na ten sam produkt bierzemy najniższą.
        $competitor = ScrapProduct::query()
            ->where('source', $source)
            ->where('is_active', true)
            ->where('excluded', false)
            ->whereNotNull('product_id')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->selectRaw('product_id, MIN(price) as price')
            ->groupBy('product_id')
            ->pluck('price', 'product_id');

        if ($competitor->isEmpty()) {
            $this->error("Brak zmapowanych cen konkurenta dla źródła „{$source}”. Najpierw: scope:sync-ebay {$source} → scope:fill-ebay-aspects {$source} → scope:match-products {$source}.");

            return self::FAILURE;
        }

        $offers = EbayOffer::query()
            ->where('marketplace', $marketplace)
            ->where('listing_status', 'Active')
            ->whereNotNull('product_id')
            ->get(['id', 'item_id', 'sku', 'title', 'price', 'currency', 'product_id', 'marketplace']);

        $maxDrop = $this->option('max-drop') !== null ? (float) $this->option('max-drop') : null;
        $maxRaise = $this->option('max-raise') !== null ? (float) $this->option('max-raise') : null;

        $plan = [];
        $noRef = 0;
        $already = 0;
        $skippedGuard = [];

        foreach ($offers as $o) {
            $target = (float) ($competitor[$o->product_id] ?? 0);
            if ($target <= 0) {
                $noRef++;
                continue;
            }

            $old = (float) $o->price;
            if (abs($target - $old) < 0.01) {
                $already++;
                continue;
            }

            $pct = $old > 0 ? ($target - $old) / $old * 100 : 0.0;

            if (($maxDrop !== null && $pct < -$maxDrop) || ($maxRaise !== null && $pct > $maxRaise)) {
                $skippedGuard[] = [$o->sku, $old, $target, $pct];
                continue;
            }

            $plan[] = ['offer' => $o, 'old' => $old, 'new' => $target, 'pct' => $pct];
        }

        $this->report($marketplace, $source, $offers->count(), $competitor->count(), $noRef, $already, $plan, $skippedGuard);

        if (empty($plan)) {
            $this->info('Nic do zmiany.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('TRYB SUCHY — nic nie wysłano. Dodaj --apply, żeby zmienić ceny na żywych aukcjach.');

            return self::SUCCESS;
        }

        return $this->push($plan);
    }

    /** @param  list<array{offer:EbayOffer,old:float,new:float,pct:float}>  $plan */
    private function report(string $marketplace, string $source, int $offerCount, int $refCount, int $noRef, int $already, array $plan, array $guard): void
    {
        $sumOld = array_sum(array_column($plan, 'old'));
        $sumNew = array_sum(array_column($plan, 'new'));
        $up = count(array_filter($plan, fn ($r) => $r['new'] > $r['old']));
        $down = count($plan) - $up;

        $this->info("=== {$marketplace}  (odniesienie: {$source}) ===");
        $this->line('nasze aktywne zmapowane aukcje : ' . $offerCount);
        $this->line('produkty z ceną konkurenta     : ' . $refCount);
        $this->line('bez ceny konkurenta (pomijam)  : ' . $noRef);
        $this->line('już 1:1 (pomijam)              : ' . $already);
        $this->line('DO ZMIANY                      : ' . count($plan) . "  (w górę {$up}, w dół {$down})");
        $this->line(sprintf('suma brutto                    : %.2f → %.2f  (%+.1f%%)',
            $sumOld, $sumNew, $sumOld > 0 ? ($sumNew - $sumOld) / $sumOld * 100 : 0));

        if (! empty($guard)) {
            $this->newLine();
            $this->warn('POMINIĘTE przez limit zmiany (' . count($guard) . '):');
            foreach (array_slice($guard, 0, 10) as [$sku, $old, $new, $pct]) {
                $this->line(sprintf('  %-14s %8.2f → %8.2f  (%+.0f%%)', $sku, $old, $new, $pct));
            }
        }

        $extreme = $plan;
        usort($extreme, fn ($a, $b) => abs($b['pct']) <=> abs($a['pct']));
        $this->newLine();
        $this->line('Największe zmiany:');
        foreach (array_slice($extreme, 0, 15) as $r) {
            $this->line(sprintf('  %-14s %8.2f → %8.2f  (%+.0f%%)  %s',
                $r['offer']->sku, $r['old'], $r['new'], $r['pct'], mb_substr((string) $r['offer']->title, 0, 45)));
        }
    }

    /** @param  list<array{offer:EbayOffer,old:float,new:float,pct:float}>  $plan */
    private function push(array $plan): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            $this->error('Konto eBay nie jest połączone (OAuth) — nie mogę zmieniać cen.');

            return self::FAILURE;
        }

        if ($limit = $this->option('limit')) {
            $plan = array_slice($plan, 0, (int) $limit);
        }

        $client = new EbaySellClient($settings, new EbayOAuthService($settings));
        $ok = 0;
        $err = 0;

        $bar = $this->output->createProgressBar(count($plan));
        $bar->start();

        foreach ($plan as $r) {
            $o = $r['offer'];
            try {
                $client->revisePrice($o->item_id, (string) $o->sku, $r['new'], $o->marketplace);
                $o->forceFill(['price' => $r['new']])->save();
                $ok++;
            } catch (\Throwable $e) {
                $err++;
                $this->newLine();
                $this->warn("  {$o->sku} / {$o->item_id}: " . $e->getMessage());
            }
            usleep(300_000);   // ~0.3 s — limity Trading API
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Wysłano: {$ok}, błędów: {$err}.");

        return $err > 0 ? self::FAILURE : self::SUCCESS;
    }
}
