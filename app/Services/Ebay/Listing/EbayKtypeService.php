<?php

namespace App\Services\Ebay\Listing;

use App\Models\Ebay\EbayOffer;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayOAuthService;
use App\Services\Ebay\EbaySellClient;
use Illuminate\Support\Facades\Artisan;
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

    /** Licznik fitmentu zapisujemy wszystkim wierszom aukcji (warianty dzielą jedną listę). */
    private function storeCount(string $itemId, string $marketplace, int $count): void
    {
        EbayOffer::where('item_id', $itemId)
            ->where('marketplace', $marketplace)
            ->update(['compat_count' => $count, 'compat_checked_at' => now()]);
    }
}
