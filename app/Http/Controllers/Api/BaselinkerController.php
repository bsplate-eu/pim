<?php

namespace App\Http\Controllers\Api;

use App\Models\Integration;
use App\Models\IntegrationProduct;
use App\Models\IntegrationSyncLog;
use App\Models\PricelistProduct;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class BaselinkerController
{
    private int $intergation_id;

    public function api(int $intergation_id, Request $rueqest)
    {
        $log = null;

        try {

            $action = $rueqest->get('action', 'FileVersion');
            $method = "get{$action}";

            throw_if(!$rueqest->filled('key'), new Exception("Odwołanie do pliku bez podania klucza."));

            // Auth weryfikacja - akceptuje OBA mechanizmy:
            //  1. Nowy (FAZA 12 audytu, 2026-05-16): HMAC-SHA256(secret, "baselinker:{id}")
            //     gdzie $integration->webhook_secret to 32-byte random encrypted w DB.
            //  2. Stary (legacy K2): md5("password_{id}") - przewidywalne, do usuniecia.
            //     Loguj jako warning zeby zidentyfikowac niezaktualizowane integracje BL.
            $integration = Integration::where('id', $intergation_id)->where('type', 'baselinker')->first();
            $expectedNew = $integration?->computeWebhookHash() ?: '';
            $expectedOld = md5("password_{$intergation_id}");

            if ($expectedNew !== '' && hash_equals($expectedNew, (string) $rueqest->key)) {
                // Nowy, bezpieczny mechanizm - OK
            } elseif (hash_equals($expectedOld, (string) $rueqest->key)) {
                // Legacy - dziala ale warning
                Log::warning('Baselinker auth z legacy md5 - zaktualizuj URL w panelu Baselinker', [
                    'integration_id' => $intergation_id,
                    'new_url'        => $integration?->getWebhookUrl(),
                ]);
            } else {
                throw new Exception("Odwołanie do pliku z nieprawidłowym kluczem: $rueqest->key");
            }

            throw_if(!method_exists($this, $method), new Exception("Odwołanie do pliku z nieprawidłową akcją: $action"));

            $this->intergation_id = $intergation_id;

            // Audit dla Status sync — tylko akcje merytoryczne, bez polling-u BL na FileVersion/SupportedMethods (co kilka sek).
            $loggable = in_array($action, ['ProductsList', 'ProductsData', 'ProductsCategories'], true);
            if ($loggable) {
                $log = IntegrationSyncLog::create([
                    'integration_id' => $intergation_id,
                    'status'         => 'running',
                    'current_item'   => $action,
                    'started_at'     => now(),
                ]);
            }

            $result = $this->{$method}($rueqest);

            if ($log) {
                $count = is_array($result) ? count($result) : 0;
                $log->update([
                    'progress'    => $count,
                    'total'       => max($count, 1),
                    'status'      => 'completed',
                    'finished_at' => now(),
                    'message'     => "BL pobrał {$action}" . ($count > 0 ? " — {$count} pozycji" : ''),
                ]);
            }

            return response()->json($result, 200, ['Content-Type' => 'application/json;charset=UTF-8', 'Charset' => 'utf-8'],
                JSON_UNESCAPED_UNICODE);


        } catch (Throwable|Exception $exception) {
            Log::error('Baselinker API error', ['exception' => $exception]);
            if ($log) {
                $log->markFailed($exception->getMessage());
            }
            return response()->json(['error' => true, 'error_code' => $exception->getCode(), 'error_text' => $exception->getMessage()], 200, ['Content-Type' => 'application/json;charset=UTF-8', 'Charset' => 'utf-8'],
                JSON_UNESCAPED_UNICODE);
        }

    }


    /**
     * Funkcja zwracająca wersję pliku wymiany danych
     * Przy tworzeniu pliku należy skonsultować numer wersji i nazwę platformy
     * z administracją systemu Baselinker
     * @param Request $request tablica z żadaniem od systemu, w przypadku tej funkcji nie używana
     * @return array $response tablica z danymi platformy z polami:
     *        platform => nazwa platformy
     *        version => numer wersji pliku
     */
    private function getFileVersion(Request $request): array
    {
        $response['platform'] = "PIM";
        $response['version'] = "0.1";
        $response['standard'] = 4;

        return $response;
    }


    /**
     * Funkcja zwracająca listę zaimplementowanych metod pliku
     * Zalecane jest pozostawienie funkcji w tej postaci niezależnie od platformy
     */
    private function getSupportedMethods(): array
    {
        $result = array();
        $methods = get_class_methods($this);

        foreach ($methods as $m) {
            if (stripos($m, 'get') === 0) {
                $result[] = substr($m, 3);
            }
        }

        return $result;
    }


    /**
     * Funkcja nawiązująca komunikację z bazą danych sklepu
     * @param Request $request tablica z żadaniem od systemu, w przypadku tej funkcji nie używana
     * @return boolean wartość logiczna określajaca sukces połączenia z bazą danych
     */
    private function getConnectDatabase(Request $request): bool
    {
        return true;
    }

    private function getProducts()
    {

        return Cache::remember("baselinker_products_$this->intergation_id", 3600, function () {
            $result = collect();

            $integration = Integration::with('integrationSources.template')
                ->where('id', $this->intergation_id)
                ->where('type', 'baselinker')
                ->first();

            throw_if(!$integration, new Exception("Niepoprawny identyfikator integracji."));

            $integration->integrationSources->each(function ($integrationSource) use ($integration, &$result) {
                app()->setLocale($integrationSource->template->locale);
                $pricelist = PricelistProduct::where('pricelist_id', $integrationSource->pricelist->id)
                    ->selectRaw('product_id, ' . PricelistProduct::EXPORT_PRICE_SQL . ' as price')
                    ->get()->keyBy('product_id');
                $integration_source_result = IntegrationProduct::with('product.media','product.categories')
                    ->where('integration_id', $integration->id)
                    ->where('integration_source_id', $integrationSource->id)
                    ->get()
                    ->map(fn($product) => $product->getBaselinkerProduct($integration, $integrationSource, $pricelist->get($product->product_id)?->price))
                    ->where('price', '>', 0);

                $result = $result->merge($integration_source_result);
            });


            IntegrationProduct::where('integration_id', $integration->id)->update(['synced_at' => now()]);

            return $result;

        });

    }

    /**
     * Funkcja zwraca szczegółowe dane wybranych produktów
     * Zwracane liczby (np ceny) powinny mieć format typu: 123456798.12 (kropka oddziela część całkowitą, 2 miejsca po przecinku)
     * @param Request $request tablica z żadaniem od systemu zawierająca pola:
     *        products_id =>            tablica z numerami id produktów
     *        fields =>                tablica z nazwami pól do zwrócenia (jeśli pusta zwracany jest cały wynik)
     * @return array $response tablica z listą produktów w formacie:
     *        id produktu =>
     * 'name' => nazwa produktu, 'ean' => Kod EAN, 'sku' => numer katalogowy, 'model' => nazwa modelu lub inny identyfikator np ISBN,
     * 'description' => opis produktu (może zawierać tagi HTML), 'description_extra1' => drugi opis produktu (np opis krótki) 'weight' => waga produktu w kg,
     * 'quantity' => dostępna ilość, 'man_name' => nazwa producenta, 'man_image' => pełny adres obrazka loga producenta,
     * 'category_id' => numer ID głównej kategorii, 'category_name' => nazwa kategori do której należy przedmiot, 'tax' => wielkość podatku w formie liczby (np 23)
     * 'price' => cena brutto w PLN,
     * 'images' => tablica z pełnymi adresami dodatkowych obrazków (pierwsze zdjęcie główne, reszta w odpowiedniej kolejności),
     * 'features' => tablica z opisem cech produktu. Poszczególny element tablicy zawiera nazwę i wartość cechy, np array('Rozdzielczość','Full HD')
     * 'variants' => tablica z wariantami produktu do wyboru (np kolor, rozmiar). Format pola opisany jest w kodzie poniżej
     */
    private function getProductsData(Request $request): array
    {
        $products_ids = explode(',', $request->products_id);
        if (count($products_ids) === 0) {
            return [];
        }

        return $this->getProducts()->whereIn('id', $products_ids)
            ->mapWithKeys(fn($p) => [$p->id => $p])
            ->toArray();
    }


    /**
     * Funkcja zwraca listę produktów z bazy sklepu
     * Zwracane liczby (np ceny) powinny mieć format typu: 123456798.12 (kropka oddziela część całkowitą, 2 miejsca po przecinku)
     * @param Request $request tablica z żadaniem od systemu zawierająca pola:
     *        category_id =>            id kategori (wartość 'all' jeśli wszystkie przedmioty)
     *        filter_limit =>        limit zwróconych kategorii w formacie SQLowym ("ilość pomijanych, ilość pobieranych")
     *        filter_sort =>            wartość po której ma być sortowana lista produktów. Możliwe wartości:
     *                                "id [ASC|DESC]", "name [ASC|DESC]", "quantity [ASC|DESC]", "price [ASC|DESC]"
     *        filter_id =>            ograniczenie wyników do konkretnego id produktu
     *        filter_ean =>            ograniczenie wyników do konkretnego ean
     *        filter_sku =>            ograniczenie wyników do konkretnego sku (numeru magazynowego)
     *        filter_name =>            filtr nazw przedmiotów (fragment szukanej nazwy lub puste pole)
     *        filter_price_from =>    dolne ograniczenie ceny (nie wyświetlane produkty z niższą ceną)
     *        filter_price_to =>        górne ograniczenie ceny
     *        filter_quantity_from =>    dolne ograniczenie ilości produktów
     *        filter_quantity_to =>    górne ograniczenie ilości produktów
     *        filter_available =>        wyświetlanie tylko produktów oznaczonych jako dostępne (wartość 1) lub niedostępne (0) lub wszystkich (pusta wartość)
     * @return array $response tablica z listą produktów w formacie:
     *        id produktu =>
     * 'name' => nazwa produktu
     * 'quantity' => dostępna ilość
     * 'price' => cena w PLN
     */
    private function getProductsList(Request $request): array
    {
        $collection = $this->getProducts();

        return $collection
            ->when($request->filled('category_id') && $request->category_id !== 'all', function ($collection) use ($request) {
                return $collection->where('category_id', $request->category_id);
            })
            ->when($request->filled('filter_id'), function ($collection) use ($request) {
                return $collection->where('id', (int)$request->filter_id);
            })
            ->when($request->filled('filter_ean'), function ($collection) use ($request) {
                return $collection->where('ean', $request->filter_ean);
            })
            ->when($request->filled('filter_sku'), function ($collection) use ($request) {
                return $collection->where('sku', $request->filter_sku);
            })
            ->when($request->filled('filter_name'), function ($collection) use ($request) {
                return $collection->where('name', $request->filter_name);
            })
            ->when($request->filled('filter_price_from'), function ($collection) use ($request) {
                return $collection->where('price', '>', (float)$request->filter_price_from);
            })
            ->when($request->filled('filter_price_to'), function ($collection) use ($request) {
                return $collection->where('price', '<', (float)$request->filter_price_to);
            })
            ->when($request->filled('filter_quantity_from'), function ($collection) use ($request) {
                return $collection->where('quantity', '>', (int)$request->filter_quantity_from);
            })
            ->when($request->filled('filter_quantity_to'), function ($collection) use ($request) {
                return $collection->where('quantity', '<', (int)$request->filter_quantity_to);
            })
            ->when($request->filled('filter_available'), function ($collection) use ($request) {
                return $collection->where('enabled', (bool)$request->filter_available);
            })
            ->when($request->filled('filter_limit'), function ($collection) use ($request) {
                return $collection->take((int)$request->filter_limit);
            })
            ->when($request->filled('filter_sort'), function ($collection) use ($request) {
                $exploded = array_map(fn($i) => trim($i), explode(' ', $request->filter_sort));
                $field = $exploded[0];
                $direction = strtolower($exploded[1] ?? 'asc');
                return $collection->sortBy([[$field, $direction]]);
            })
            ->mapWithKeys(fn($p) => [$p->id => collect($p)->only(['name', 'quantity', 'price'])])
            ->toArray();
    }

    /**
     * Funkcja zwraca listę kategorii sklepowych
     * Zwracana tabela powinna być posortowana alfabetycznie
     * W nazwie kategorii podrzędnej powinna być zawrta nazwa nadkategorii - np "Komputery/Karty graficzne" zamiast "Karty graficzne"
     * @param Request $request tablica z żadaniem od systemu, w przypadku tej funkcji nie używana
     * @return array $response tablica z listą kategori sklepowch w formacie:
     *        id kategorii => nazwa kategorii
     */
    private function getProductsCategories(Request $request): array
    {
        return $this->getProducts()->unique('category_id')->mapWithKeys(fn($i) => [$i->category_id => $i->category_name])->toArray();
    }

}
