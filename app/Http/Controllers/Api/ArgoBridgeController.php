<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarehouseBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Wejscie dla ARGO Bridge — programu, ktory stoi na maszynie w LAN-ie klienta
 * razem z Subiektem GT i sam sie do nas zglasza.
 *
 * Na razie jest tu wylacznie `ping`: Bridge melduje, ze zyje, a PIM zapisuje
 * czas i wersje. Dzieki temu kropka w Ustawieniach mowi prawde, zanim jeszcze
 * powstanie wysylka stanow.
 *
 * Uwierzytelnienie: token w naglowku `X-Argo-Token`, porownywany
 * `hash_equals` (staly czas — porownanie `===` na tokenie daje sie mierzyc).
 * Brak tokenu po naszej stronie = nikogo nie wpuszczamy.
 */
class ArgoBridgeController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        $bridge = WarehouseBridge::current();

        if (! $this->tokenMatches($request, $bridge)) {
            return response()->json(['error' => 'Nieprawidlowy token'], 401);
        }

        if (! $bridge->enabled) {
            // Celowo 403, nie 401: token jest dobry, to polaczenie jest zgaszone.
            // Bridge ma z tego wiedziec, ze nie ma po co ponawiac z nowym tokenem.
            return response()->json(['error' => 'Polaczenie wylaczone w PIM'], 403);
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

    private function tokenMatches(Request $request, WarehouseBridge $bridge): bool
    {
        $expected = (string) $bridge->api_token;
        $given = (string) $request->header('X-Argo-Token', '');

        return $expected !== '' && hash_equals($expected, $given);
    }
}
