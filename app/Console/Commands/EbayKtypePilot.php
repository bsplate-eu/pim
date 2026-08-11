<?php

namespace App\Console\Commands;

use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOAuthService;
use App\Services\Ebay\EbaySellClient;
use App\Services\Ebay\EbayTaxonomyClient;
use Illuminate\Console\Command;

/**
 * PILOT kType: rozpoznanie bazy pojazdów eBaya (Taxonomy API) pod kątem naszych aukcji.
 * Sam odczyt — niczego nie zmienia na eBay. Etapy odpalane opcjami (bez redeployu):
 *
 *   ebay:ktype-pilot                             → kategoria pierwszej aukcji DE + lista właściwości pojazdów
 *   ebay:ktype-pilot --item=123456…              → jw. dla konkretnej aukcji
 *   ebay:ktype-pilot --category=179845 --property=Marke                → wartości właściwości
 *   ebay:ktype-pilot --category=179845 --property=KType --filters="Marke:Volkswagen,Modell:Eos"
 */
class EbayKtypePilot extends Command
{
    protected $signature = 'ebay:ktype-pilot
        {--item= : ItemID aukcji (domyślnie pierwsza aktywna zmapowana DE)}
        {--category= : ID kategorii (pomija GetItem)}
        {--property= : wypisz wartości tej właściwości pojazdu}
        {--filters= : zawężenie, np. "Marke:Volkswagen,Modell:Eos"}
        {--marketplace=EBAY_DE : rynek}';

    protected $description = 'Pilot kType: rozpoznanie Taxonomy API (kategorie, właściwości pojazdów, wartości) — sam odczyt';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            $this->error('Brak App ID / Cert ID w ustawieniach integracji eBay.');

            return self::FAILURE;
        }

        $marketplace = strtoupper((string) $this->option('marketplace'));
        $taxonomy = new EbayTaxonomyClient($settings->client_id, $settings->client_secret);

        // 1. Kategoria: z opcji albo z GetItem wskazanej/pierwszej aukcji.
        $categoryId = (string) $this->option('category');
        if ($categoryId === '') {
            if (! $settings->isOauthConnected()) {
                $this->error('GetItem wymaga połączonego konta (OAuth) — albo podaj --category.');

                return self::FAILURE;
            }
            $offer = EbayOffer::query()
                ->where('listing_status', 'Active')
                ->where('marketplace', $marketplace)
                ->whereNotNull('product_id')
                ->when($this->option('item'), fn ($q, $id) => $q->where('item_id', $id))
                ->with('product.attributeValues.attribute')
                ->first();
            if (! $offer) {
                $this->error('Nie znalazłem pasującej aukcji.');

                return self::FAILURE;
            }

            $client = new EbaySellClient($settings, new EbayOAuthService($settings));
            $cat = $client->itemCategory($offer->item_id, $offer->marketplace);
            $categoryId = $cat['id'];

            $this->info("Aukcja {$offer->item_id} [{$offer->marketplace}]: {$offer->title}");
            $this->info("Kategoria: {$cat['id']} — {$cat['name']}");

            // Podpowiedź: atrybuty pojazdu z naszego produktu (wejście do filtrów).
            if ($offer->product) {
                $attrs = [];
                foreach ($offer->product->attributeValues as $av) {
                    if (in_array($av->attribute->slug, ['make', 'model', 'year-start', 'year-stop', 'engine'])) {
                        $attrs[] = $av->attribute->slug . '=' . ($av->name['en'] ?? $av->slug);
                    }
                }
                $this->info('Produkt PIM: ' . $offer->product->product_code . ' | ' . implode('; ', $attrs));
            }
        }

        // 2. Drzewo kategorii + właściwości pojazdów w tej kategorii.
        $treeId = $taxonomy->categoryTreeId($marketplace);
        $this->info("Drzewo kategorii {$marketplace}: {$treeId}");

        $props = $taxonomy->compatibilityProperties($treeId, $categoryId);
        if ($props === []) {
            $this->warn("Kategoria {$categoryId}: BRAK właściwości kompatybilności (kategoria nie wspiera fitmentu?).");

            return self::SUCCESS;
        }
        $this->table(['name', 'localizedName'], collect($props)->map(fn ($p) => [$p['name'] ?? '', $p['localizedName'] ?? ''])->all());

        // 3. Opcjonalnie: wartości wskazanej właściwości (z filtrami).
        $property = (string) $this->option('property');
        if ($property !== '') {
            $filters = [];
            foreach (array_filter(explode(',', (string) $this->option('filters'))) as $pair) {
                [$k, $v] = array_pad(explode(':', $pair, 2), 2, '');
                if ($k !== '' && $v !== '') {
                    $filters[trim($k)] = trim($v);
                }
            }

            $values = $taxonomy->compatibilityPropertyValues($treeId, $categoryId, $property, $filters);
            $label = $filters ? ' przy ' . json_encode($filters, JSON_UNESCAPED_UNICODE) : '';
            $this->info("Wartości [{$property}]{$label}: " . count($values));
            foreach (array_slice($values, 0, 40) as $v) {
                $this->line("  {$v}");
            }
            if (count($values) > 40) {
                $this->line('  … (+' . (count($values) - 40) . ')');
            }
        }

        return self::SUCCESS;
    }
}
