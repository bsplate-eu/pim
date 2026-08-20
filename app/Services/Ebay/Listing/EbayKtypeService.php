<?php

namespace App\Services\Ebay\Listing;

use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOAuthService;
use App\Services\Ebay\EbaySellClient;
use App\Services\Ebay\EbayTaxonomyClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Obsługa kType (kompatybilności pojazdów) dla ekranu Marketplace → eBay → kType.
 *
 * Nie przepisuje automatu — `ebay:ktype-push` ma ~570 linii resolvera (aliasy marek, generacje
 * rzymskie z tytułu, dopasowanie modelu do bazy pojazdów eBaya, strażnik bliźniaków badge'owych)
 * i jest sprawdzony na produkcji: DE 882/1129, FR 683/943 aukcji z fitmentem. Przepisywanie tego
 * na serwis byłoby przepisywaniem działającego kodu bez powodu.
 *
 * Zamiast tego wołamy komendę jej własnym interfejsem (`--items`, `--apply`, `--marketplace`,
 * `--from-title`) i podajemy wyjście do UI. Ekran dokłada to, czego CLI nie ma: podgląd
 * aktualnego fitmentu z eBaya i wybór aukcji klikaniem zamiast przepisywania ItemID.
 */
class EbayKtypeService
{
    /** Ile aukcji maksymalnie w jednym przebiegu automatu — leci w cyklu requestu. */
    public const MAX_BATCH = 20;

    /** Rejestr komendy: item_id → status (pushed / unmatched / no_years / no_platform / …). */
    private const REGISTRY = 'ebay/ktype-pushed.json';

    /** Statusy rejestru, które da się ponowić po poprawce resolvera (reszta jest terminalna). */
    public const RETRYABLE = ['unmatched', 'no_years', 'no_platform'];

    private function client(): EbaySellClient
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            throw new \RuntimeException('Konto eBay nie jest połączone — fitment czyta się z konta sprzedawcy.');
        }

        return new EbaySellClient($settings, new EbayOAuthService($settings));
    }

    /** Rejestr wysyłek automatu: item_id → status. Pusty, gdy komenda jeszcze nie chodziła. */
    public function registry(): array
    {
        if (! Storage::exists(self::REGISTRY)) {
            return [];
        }

        return json_decode(Storage::get(self::REGISTRY), true) ?: [];
    }

    /**
     * Fitment jednej aukcji prosto z eBaya (GetItem) + zapis licznika przy ofercie.
     * To ODCZYT — niczego nie zmienia na aukcji.
     *
     * @return array{count:int, list:list<array{props:array,notes:string}>}
     */
    public function fitment(EbayOffer $offer): array
    {
        $compat = $this->client()->itemCompatibility($offer->item_id, $offer->marketplace);

        $this->storeCount($offer->item_id, $offer->marketplace, (int) $compat['count']);

        return $compat;
    }

    /**
     * Odśwież licznik fitmentu dla wskazanych aukcji (bez pokazywania listy pojazdów).
     * Jedno wywołanie GetItem na aukcję, z pauzą — eBay przycina zbyt gęste odpytywanie.
     *
     * @param  list<int>  $offerIds  klucze wierszy ebay_offers
     * @return array{checked:int, with:int, without:int, errors:list<string>}
     */
    public function refreshCounts(array $offerIds): array
    {
        $client = $this->client();

        // Jedna aukcja = wiele wierszy (warianty), a fitment jest własnością AUKCJI, nie wariantu.
        // Odpytujemy więc raz per item_id, a licznik zapisujemy wszystkim jej wierszom.
        $offers = EbayOffer::whereIn('id', $offerIds)->get()->unique('item_id')->values();

        $with = 0;
        $without = 0;
        $errors = [];

        foreach ($offers as $offer) {
            try {
                $count = (int) $client->itemCompatibility($offer->item_id, $offer->marketplace)['count'];
                $this->storeCount($offer->item_id, $offer->marketplace, $count);
                $count > 0 ? $with++ : $without++;
                usleep(300_000);
            } catch (\Throwable $e) {
                $errors[] = $offer->item_id.': '.mb_substr($e->getMessage(), 0, 120);
            }
        }

        return ['checked' => $offers->count(), 'with' => $with, 'without' => $without, 'errors' => $errors];
    }

    /**
     * Uruchom automat kType na wskazanych aukcjach.
     *
     * $apply=false → dry-run: komenda pokazuje, co BY wysłała, nie dotykając eBaya. To domyślne
     * zachowanie i tak też działa przycisk „Podgląd" w UI — fitment z cudzej marki jest gorszy
     * niż jego brak, więc wysyłka zawsze idzie po obejrzeniu.
     *
     * @param  list<string>  $itemIds  ItemID aukcji (nie id wierszy)
     * @return array{ok:bool, output:string, exit_code:int}
     */
    public function runAutomat(array $itemIds, string $marketplace, bool $apply = false, bool $fromTitle = false): array
    {
        $itemIds = array_values(array_unique(array_filter($itemIds)));
        if ($itemIds === []) {
            throw new \RuntimeException('Nie wskazano żadnej aukcji.');
        }
        if (count($itemIds) > self::MAX_BATCH) {
            throw new \RuntimeException('Naraz można obrobić najwyżej '.self::MAX_BATCH.' aukcji — automat pyta eBaya o każdy pojazd osobno.');
        }

        $options = [
            '--items' => implode(',', $itemIds),
            '--marketplace' => strtoupper($marketplace),
        ];
        if ($apply) {
            $options['--apply'] = true;
        }
        if ($fromTitle) {
            $options['--from-title'] = true;
        }

        $exitCode = Artisan::call('ebay:ktype-push', $options);

        return [
            'ok' => $exitCode === 0,
            'output' => Artisan::output(),
            'exit_code' => $exitCode,
        ];
    }

    // ─── Ręczne dopasowanie ───────────────────────────────────────────────
    //
    // Automat zostawia ogon, którego nie rozgryzie: bliźniaki badge'owe (Citroen Dispatch =
    // Peugeot Expert = Toyota Proace), modele nazwane u nas inaczej niż w bazie pojazdów eBaya,
    // wersje silnikowe. Tu człowiek wybiera pojazd z list eBaya i zapisuje wprost.

    /**
     * Nazwy właściwości pojazdu dla kategorii aukcji — RÓŻNE per rynek
     * (DE „Make/Model/Platform/Year", FR „FR_Make/…", ES „ES_Make/…").
     *
     * @return array{tree_id:string, category_id:string, make:string, model:string, platform:?string, year:string}
     */
    public function vehicleProperties(EbayOffer $offer): array
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            throw new \RuntimeException('Brak kluczy eBay.');
        }

        $taxonomy = new EbayTaxonomyClient($settings->client_id, $settings->client_secret);
        $treeId = $taxonomy->categoryTreeId($offer->marketplace);

        // Kategoria aukcji zmienia się rzadko, a GetItem to osobne wywołanie Trading API.
        $categoryId = Cache::remember(
            "ebay.item.category.{$offer->marketplace}.{$offer->item_id}",
            86400,
            fn () => $this->client()->itemCategory($offer->item_id, $offer->marketplace)['id'],
        );

        if ($categoryId === '') {
            throw new \RuntimeException('Nie udało się odczytać kategorii aukcji.');
        }

        $names = collect($taxonomy->compatibilityProperties($treeId, $categoryId))->pluck('name');
        if ($names->isEmpty()) {
            throw new \RuntimeException("Kategoria {$categoryId} ({$offer->marketplace}) nie wspiera kompatybilności pojazdów.");
        }

        $pick = fn (string $suffix) => $names->first(fn ($n) => $n === $suffix || str_ends_with($n, '_'.$suffix));

        return [
            'tree_id' => $treeId,
            'category_id' => $categoryId,
            'make' => $pick('Make') ?? 'Make',
            'model' => $pick('Model') ?? 'Model',
            'platform' => $pick('Platform'),   // nie każdy rynek/kategoria ją ma
            'year' => $pick('Year') ?? 'Year',
        ];
    }

    /**
     * Wartości jednej właściwości, zawężone tym, co już wybrano (kaskada marka → model → rok).
     *
     * @param  array<string,string>  $filters  np. ['Make' => 'Citroen']
     * @return list<string>
     */
    public function vehicleValues(EbayOffer $offer, string $property, array $filters = []): array
    {
        $settings = EbaySettings::first();
        $props = $this->vehicleProperties($offer);
        $taxonomy = new EbayTaxonomyClient($settings->client_id, $settings->client_secret);

        return $taxonomy->compatibilityPropertyValues(
            $props['tree_id'],
            $props['category_id'],
            $property,
            array_filter($filters),
        );
    }

    /**
     * Zapisz ręcznie złożoną listę pojazdów na aukcję.
     *
     * ⚠️ ReviseFixedPriceItem ZASTĘPUJE całą listę kompatybilności — nie dokłada. Dlatego UI
     * pokazuje obecny fitment przed zapisem, a tu przyjmujemy komplet wpisów, nie różnicę.
     *
     * @param  list<array<string,string>>  $entries  np. [['Make'=>'Citroen','Model'=>'Jumpy','Year'=>'2020'], …]
     * @return array{sent:int, warnings:list<string>, count:int}
     */
    public function applyManual(EbayOffer $offer, array $entries): array
    {
        if ($entries === []) {
            throw new \RuntimeException('Lista pojazdów jest pusta — nie ma czego zapisać.');
        }

        $warnings = $this->client()->reviseCompatibility($offer->item_id, $offer->marketplace, $entries);

        // Odczyt po zapisie: eBay po cichu pomija kombinacje, których nie zna, więc liczba
        // wpisów faktycznie przyjętych bywa mniejsza od wysłanej. Pokazujemy stan rzeczywisty.
        $after = $this->client()->itemCompatibility($offer->item_id, $offer->marketplace);
        $this->storeCount($offer->item_id, $offer->marketplace, (int) $after['count']);
        $this->markRegistry($offer->item_id, 'manual');

        return ['sent' => count($entries), 'warnings' => $warnings, 'count' => (int) $after['count']];
    }

    /** Odnotuj aukcję w rejestrze automatu, żeby kolejne przebiegi jej nie ruszały. */
    private function markRegistry(string $itemId, string $status): void
    {
        $registry = $this->registry();
        $registry[$itemId] = $status;
        Storage::put(self::REGISTRY, json_encode($registry, JSON_PRETTY_PRINT));
    }

    /** Licznik fitmentu zapisujemy wszystkim wierszom aukcji (warianty dzielą jedną listę). */
    private function storeCount(string $itemId, string $marketplace, int $count): void
    {
        EbayOffer::where('item_id', $itemId)
            ->where('marketplace', $marketplace)
            ->update(['compat_count' => $count, 'compat_checked_at' => now()]);
    }
}
