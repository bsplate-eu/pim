<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            WarehouseSourceStock::where('source', 'gt')
                ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', $now))
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
