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
 * Świadomie OMIJA cenniki: obie ceny są brutto w walucie rynku, więc droga
 * „brutto → netto przez VAT → z powrotem brutto" tylko gubi grosze i wciąga cudze reguły
 * (clamp cennika porównawczego, pozycje-sierotki, nocne nadpisania cennika #18).
 *
 * ⚠️ Zestawia AUKCJĘ Z AUKCJĄ, nie przez `product_id`. Nasze aukcje mapowano po samym SKU
 * (`matchBySku`), a jeden Art.-Nr obejmuje kilka modeli aut — na EBAY_DE 884 z 1163 ofert
 * dzieli `product_id` z inną aukcją (największa grupa: 20). Wyrównanie po `product_id`
 * zrównałoby ceny różnych aut do najniższej z grupy (np. Nissan Navara 179 → 91,15).
 *
 * Klucz = Art.-Nr (nasze `sku` ↔ ich `herstellernummer`). Gdy konkurent ma pod tym numerem
 * kilka ofert, wybieramy tę, której tytuł najlepiej pasuje do naszego — po tokenach
 * RÓŻNICUJĄCYCH (model + rocznik), tak jak robi to ProductMatcher. Brak rozstrzygnięcia
 * (remis albo zero wspólnych tokenów) → oferta POMIJANA, nie zgadywana.
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
        {--limit= : ogranicz liczbę wysyłanych ofert (test na małej próbce)}
        {--show=15 : ile pozycji pokazać w raporcie}
        {--stale : NIE odfiltrowuj ofert nieobecnych w ostatnim syncu (odradzane)}';

    protected $description = 'Wyrównuje ceny naszych aukcji eBay 1:1 do ceny konkurenta (zestawienie aukcja↔aukcja po Art.-Nr)';

    public function handle(): int
    {
        $marketplace = strtoupper((string) $this->argument('marketplace'));

        $source = collect(EbayScrapService::MARKETS)
            ->search(fn (array $m) => $m['marketplace'] === $marketplace);

        if ($source === false) {
            $this->error("Nieznany rynek: {$marketplace}.");

            return self::FAILURE;
        }

        $index = $this->competitorIndex($source);
        if (empty($index)) {
            $this->error("Brak ofert konkurenta z numerem czesci dla źródła „{$source}”. Kolejność: scope:sync-ebay {$source} → scope:fill-ebay-aspects {$source}.");

            return self::FAILURE;
        }

        // `listing_status` sam z siebie NIE wystarcza: syncActiveListings tylko dopisuje to, co eBay
        // zwrócił, i nigdy nie oznacza brakujących ofert jako zakończonych — zakończona aukcja zostaje
        // w bazie jako „Active" na zawsze. Jedyny wiarygodny sygnał to `last_seen`: żywe oferty dostają
        // świeży znacznik przy każdym syncu. Bez tego filtra eBay odrzuca próby zmiany ceny
        // („Bereits beendete Angebote können nicht bearbeitet werden").
        $base = EbayOffer::query()
            ->with('product:id,product_code')
            ->where('marketplace', $marketplace)
            ->where('listing_status', 'Active');

        $stale = 0;
        if (! $this->option('stale')) {
            $lastSync = (clone $base)->max('last_seen');
            if ($lastSync !== null) {
                $cutoff = \Illuminate\Support\Carbon::parse($lastSync)->subHour();
                $stale = (clone $base)->where('last_seen', '<', $cutoff)->count();
                $base->where('last_seen', '>=', $cutoff);
            }
        }

        $offers = $base->get(['id', 'item_id', 'sku', 'title', 'price', 'currency', 'product_id', 'marketplace', 'last_seen']);

        $maxDrop = $this->option('max-drop') !== null ? (float) $this->option('max-drop') : null;
        $maxRaise = $this->option('max-raise') !== null ? (float) $this->option('max-raise') : null;

        $plan = [];
        $stat = ['brak_numeru' => 0, 'brak_oferty' => 0, 'niejednoznaczne' => 0, 'juz_11' => 0];
        $ambiguous = [];
        $guard = [];

        foreach ($offers as $o) {
            $hn = $this->normCode($o->sku) ?: $this->normCode($o->product?->product_code);
            if ($hn === '') {
                $stat['brak_numeru']++;
                continue;
            }

            $cands = $index[$hn] ?? null;
            if ($cands === null) {
                $stat['brak_oferty']++;
                continue;
            }

            $target = $this->pickPrice($o->title, $cands);
            if ($target === null) {
                $stat['niejednoznaczne']++;
                $ambiguous[] = [$hn, (float) $o->price, count($cands), (string) $o->title];
                continue;
            }

            $old = (float) $o->price;
            if (abs($target - $old) < 0.01) {
                $stat['juz_11']++;
                continue;
            }

            $pct = $old > 0 ? ($target - $old) / $old * 100 : 0.0;
            if (($maxDrop !== null && $pct < -$maxDrop) || ($maxRaise !== null && $pct > $maxRaise)) {
                $guard[] = [$o->sku, $old, $target, $pct];
                continue;
            }

            $plan[] = ['offer' => $o, 'old' => $old, 'new' => $target, 'pct' => $pct];
        }

        $this->report($marketplace, $source, $offers->count(), count($index), $stat, $plan, $ambiguous, $guard, $stale);

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

    /**
     * Oferty konkurenta pogrupowane po numerze części: [Art.-Nr => [['price','tokens'], …]].
     *
     * @return array<string,list<array{price:float,tokens:array<string,true>}>>
     */
    private function competitorIndex(string $source): array
    {
        $index = [];

        ScrapProduct::query()
            ->where('source', $source)
            ->where('is_active', true)
            ->where('excluded', false)
            ->whereNotNull('herstellernummer')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->select(['herstellernummer', 'price', 'title'])
            ->chunk(1000, function ($chunk) use (&$index) {
                foreach ($chunk as $s) {
                    $hn = $this->normCode($s->herstellernummer);
                    if ($hn !== '') {
                        $index[$hn][] = ['price' => (float) $s->price, 'tokens' => $this->tokens((string) $s->title)];
                    }
                }
            });

        return $index;
    }

    /**
     * Cena oferty konkurenta odpowiadającej naszej. null = nie da się rozstrzygnąć.
     *
     * @param  list<array{price:float,tokens:array<string,true>}>  $cands
     */
    private function pickPrice(?string $ourTitle, array $cands): ?float
    {
        // Wszystkie oferty pod tym numerem w tej samej cenie → tytuł nie ma znaczenia.
        $prices = array_unique(array_map(fn (array $c) => $c['price'], $cands));
        if (count($prices) === 1) {
            return (float) reset($prices);
        }

        // Liczą się tokeny RÓŻNICUJĄCE (model + rocznik), nie wspólny szum („stahl", „unterfahrschutz").
        $common = $cands[0]['tokens'];
        for ($i = 1, $n = count($cands); $i < $n; $i++) {
            $common = array_intersect_key($common, $cands[$i]['tokens']);
        }

        $ours = $this->tokens((string) $ourTitle);
        $best = -1;
        $bestPrice = null;
        $ties = 0;

        foreach ($cands as $c) {
            $score = count(array_intersect_key($ours, array_diff_key($c['tokens'], $common)));
            if ($score > $best) {
                $best = $score;
                $bestPrice = $c['price'];
                $ties = 1;
            } elseif ($score === $best && $c['price'] !== $bestPrice) {
                $ties++;   // remis między ofertami o RÓŻNYCH cenach — nie zgadujemy
            }
        }

        return ($best > 0 && $ties === 1) ? $bestPrice : null;
    }

    /** Numer części do porównania: bez cudzysłowów, wielkie litery (sufiks ALU zostaje — 06.048 ≠ 06.048ALU). */
    private function normCode(?string $v): string
    {
        return $v === null ? '' : strtoupper(trim(str_replace(['"', "'"], '', $v)));
    }

    /** @return array<string,true> */
    private function tokens(string $s): array
    {
        $s = preg_replace('/[^a-z0-9äöüß]+/u', ' ', mb_strtolower($s));
        $out = [];
        foreach (preg_split('/\s+/', trim((string) $s)) as $t) {
            if (mb_strlen($t) >= 2) {
                $out[$t] = true;
            }
        }

        return $out;
    }

    /** @param  list<array{offer:EbayOffer,old:float,new:float,pct:float}>  $plan */
    private function report(string $marketplace, string $source, int $offerCount, int $refCount, array $stat, array $plan, array $ambiguous, array $guard, int $stale = 0): void
    {
        $show = (int) $this->option('show');
        $sumOld = array_sum(array_column($plan, 'old'));
        $sumNew = array_sum(array_column($plan, 'new'));
        $up = count(array_filter($plan, fn ($r) => $r['new'] > $r['old']));

        $this->info("=== {$marketplace}  (odniesienie: {$source}) ===");
        $this->line('nasze aktywne aukcje            : ' . $offerCount);
        if ($stale > 0) {
            $this->line('pominięte jako zakończone       : ' . $stale . '  (status „Active", ale brak w ostatnim syncu)');
        }
        $this->line('numery części u konkurenta      : ' . $refCount);
        $this->line('bez numeru po naszej stronie    : ' . $stat['brak_numeru']);
        $this->line('konkurent nie ma tego numeru    : ' . $stat['brak_oferty']);
        $this->line('nierozstrzygnięte po tytule     : ' . $stat['niejednoznaczne']);
        $this->line('już 1:1                         : ' . $stat['juz_11']);
        $this->line('DO ZMIANY                       : ' . count($plan) . '  (w górę ' . $up . ', w dół ' . (count($plan) - $up) . ')');
        $this->line(sprintf('suma brutto zmienianych         : %.2f → %.2f  (%+.1f%%)',
            $sumOld, $sumNew, $sumOld > 0 ? ($sumNew - $sumOld) / $sumOld * 100 : 0));

        if (! empty($ambiguous)) {
            $this->newLine();
            $this->warn('NIEROZSTRZYGNIĘTE — konkurent ma kilka ofert w różnych cenach, tytuł nie wskazał jednej (' . count($ambiguous) . '):');
            foreach (array_slice($ambiguous, 0, min($show, 10)) as [$hn, $old, $n, $title]) {
                $this->line(sprintf('  %-14s nasza %8.2f | ofert konkurenta: %-2d | %s', $hn, $old, $n, mb_substr($title, 0, 45)));
            }
        }

        if (! empty($guard)) {
            $this->newLine();
            $this->warn('POMINIĘTE przez limit zmiany (' . count($guard) . '):');
            foreach (array_slice($guard, 0, min($show, 10)) as [$sku, $old, $new, $pct]) {
                $this->line(sprintf('  %-14s %8.2f → %8.2f  (%+.0f%%)', $sku, $old, $new, $pct));
            }
        }

        if (! empty($plan)) {
            $extreme = $plan;
            usort($extreme, fn ($a, $b) => abs($b['pct']) <=> abs($a['pct']));
            $this->newLine();
            $this->line('Największe zmiany:');
            foreach (array_slice($extreme, 0, $show) as $r) {
                $this->line(sprintf('  %-14s %8.2f → %8.2f  (%+.0f%%)  %s',
                    $r['offer']->sku, $r['old'], $r['new'], $r['pct'], mb_substr((string) $r['offer']->title, 0, 45)));
            }
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
