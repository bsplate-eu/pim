<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarehouseCodeExclusion;
use App\Models\WarehouseBridge;
use App\Models\WarehouseSourceStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Wejscie dla ARGO Bridge — programu, ktory stoi na maszynie w LAN-ie klienta
 * razem z Subiektem GT i sam sie do nas zglasza.
 *
 * `ping`  — melduje, ze zyje; z tego swieci sie kropka w Ustawieniach.
 * `stock` — przysyla PELNY stan wskazanego magazynu.
 *
 * Uwierzytelnienie: token w naglowku `X-Argo-Token`, porownywany `hash_equals`
 * (staly czas — porownanie `===` na tokenie daje sie mierzyc). Brak tokenu po
 * naszej stronie = nikogo nie wpuszczamy.
 */
class ArgoBridgeController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        $bridge = WarehouseBridge::current();

        if (($error = $this->guard($request, $bridge)) !== null) {
            return $error;
        }

        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:50'],
        ]);

        $bridge->forceFill([
            'last_seen_at' => Carbon::now(),
            'bridge_version' => $data['version'] ?? $bridge->bridge_version,
        ])->save();

        return response()->json([
            'ok' => true,
            // Bridge dostaje symbol magazynu z PIM, zeby nie trzymac go w dwoch
            // miejscach — zmiana w Ustawieniach dociera do niego sama.
            'warehouse_symbol' => $bridge->warehouse_symbol,
            // Kody odlozone na bok. PIM i tak je odrzuca przy zapisie, ale majac
            // te liste Bridge moze ich w ogole nie czytac z Subiekta.
            'excluded_codes' => WarehouseCodeExclusion::where('source', 'gt')
                ->orderBy('source_code')
                ->pluck('source_code')
                ->all(),
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Pelny stan magazynu. Paczka jest MIGAWKA, nie roznica: czego w niej nie ma,
     * tego na magazynie nie ma — wiersze spoza paczki kasujemy.
     *
     * Stad dwie bramki, ktore chronia przed wyzerowaniem calego magazynu przez
     * pomylke po tamtej stronie:
     *  - pusta lista pozycji jest odrzucana (pusty wynik to zwykle blad zapytania,
     *    nie pusty magazyn — ta sama lekcja co przy syncu ofert eBay),
     *  - symbol magazynu w paczce musi zgadzac sie z tym z Ustawien.
     */
    public function stock(Request $request): JsonResponse
    {
        $bridge = WarehouseBridge::current();

        if (($error = $this->guard($request, $bridge)) !== null) {
            return $error;
        }

        $data = $request->validate([
            'warehouse' => ['required', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.code' => ['required', 'string', 'max:100'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric'],
        ], [
            'items.required' => 'Paczka bez pozycji jest odrzucana — pusty wynik to zwykle blad zapytania, nie pusty magazyn.',
            'items.min' => 'Paczka bez pozycji jest odrzucana — pusty wynik to zwykle blad zapytania, nie pusty magazyn.',
        ]);

        if ($bridge->warehouse_symbol !== null
            && strcasecmp(trim($data['warehouse']), trim($bridge->warehouse_symbol)) !== 0) {
            return response()->json([
                'error' => "Paczka dotyczy magazynu {$data['warehouse']}, a w PIM ustawiony jest {$bridge->warehouse_symbol}.",
            ], 422);
        }

        $now = Carbon::now();

        // Kody potrafia sie powtorzyc w jednej paczce (ten sam towar w kilku
        // partiach) — sumujemy je, zamiast pozwolic ostatniemu wygrac.
        $items = [];
        foreach ($data['items'] as $item) {
            $code = trim($item['code']);
            if ($code === '') {
                continue;
            }

            if (! isset($items[$code])) {
                $items[$code] = ['name' => $item['name'] ?? null, 'quantity' => 0.0];
            }

            $items[$code]['quantity'] += (float) $item['quantity'];
            $items[$code]['name'] = $items[$code]['name'] ?? ($item['name'] ?? null);
        }

        // Kody odlozone na bok nie wchodza w ogole — nie zapisujemy ich, wiec nie
        // wracaja przy kolejnej paczce. Filtr stoi TUTAJ, a nie tylko po stronie
        // Bridge'a: dzieki temu dziala niezaleznie od tego, jaka wersje ma
        // maszyna po drugiej stronie. Przywrocenie kodu wpuszcza go z powrotem
        // przy najblizszej paczce.
        $excluded = WarehouseCodeExclusion::where('source', 'gt')
            ->pluck('source_code')
            ->map(fn ($code) => mb_strtoupper($code))
            ->flip();

        $skipped = 0;
        foreach (array_keys($items) as $code) {
            if ($excluded->has(mb_strtoupper($code))) {
                unset($items[$code]);
                $skipped++;
            }
        }

        if (! count($items)) {
            return response()->json([
                'error' => 'Cala paczka to kody wykluczone w PIM — nic do zapisania.',
            ], 422);
        }

        DB::transaction(function () use ($items, $now) {
            foreach (array_chunk($items, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $code => $item) {
                    $rows[] = [
                        'source' => 'gt',
                        'source_code' => $code,
                        'name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                WarehouseSourceStock::upsert($rows, ['source', 'source_code'], ['name', 'quantity', 'synced_at', 'updated_at']);
            }

            // Wszystko, czego w tej paczce nie bylo, znika — migawka zastepuje
            // poprzednia w calosci.
            //
            // Kasujemy po LISCIE KODOW, nie po znaczniku czasu: `synced_at` ma
            // dokladnosc sekundy, wiec dwie paczki w tej samej sekundzie
            // (ponowienie po timeoucie, wykluczenie i natychmiastowy retry)
            // byly nieodroznialne i zostawialy wiersze z poprzedniej.
            WarehouseSourceStock::where('source', 'gt')
                ->whereNotIn('source_code', array_keys($items))
                ->delete();
        });

        $bridge->forceFill([
            'last_seen_at' => $now,
            'last_sync_at' => $now,
            'last_codes' => count($items),
            'bridge_version' => $data['version'] ?? $bridge->bridge_version,
        ])->save();

        return response()->json([
            'ok' => true,
            'received' => count($data['items']),
            'stored' => count($items),
            // Ile pozycji odrzucilismy jako wykluczone — Bridge moze to pokazac
            // w logu, zeby roznica miedzy „wyslano" a „zapisano" nie dziwila.
            'skipped_excluded' => $skipped,
            'server_time' => $now->toIso8601String(),
        ]);
    }

    /**
     * Wspolna bramka obu wejsc. 401 = zly albo zaden token. 403 = token dobry,
     * ale polaczenie zgaszone w PIM — Bridge ma z tego wiedziec, ze ponawianie
     * z nowym tokenem nic nie da.
     */
    private function guard(Request $request, WarehouseBridge $bridge): ?JsonResponse
    {
        $expected = (string) $bridge->api_token;
        $given = (string) $request->header('X-Argo-Token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'Nieprawidlowy token'], 401);
        }

        if (! $bridge->enabled) {
            return response()->json(['error' => 'Polaczenie wylaczone w PIM'], 403);
        }

        return null;
    }
}
