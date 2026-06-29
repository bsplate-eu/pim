<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SynchronizeIntegration;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SellyController
{
    /** Po ilu sekundach plik feedu uznajemy za nieświeży i zlecamy regenerację w tle. */
    private const TTL_SECONDS = 6 * 3600;

    /**
     * Feed CSV dla integratora Selly (pełny katalog).
     *
     * Plik powstaje w TLE (job SynchronizeIntegration, timeout 7200s) — generacja ~38s
     * dla ~1.5k produktów nie może iść w żądaniu HTTP. Tu tylko serwujemy gotowy plik z cache.
     * Self-refresh: gdy plik jest nieświeży (> TTL), zlecamy regenerację PO odpowiedzi
     * (afterResponse — nie blokuje Selly), a serwujemy bieżącą wersję.
     */
    public function download(Request $request, int $integration_id)
    {
        $integration = Integration::where('id', $integration_id)
            ->where('type', 'selly')
            ->first();

        // Auth: HMAC-SHA256(secret, "selly:{id}") — ten sam klucz co w webhook URL (getWebhookUrl()).
        $expected = $integration?->computeWebhookHash() ?: '';
        abort_if($expected === '' || !hash_equals($expected, (string) $request->input('key')), 403);

        $path   = storage_path('app/integrations/' . $integration->id . '.csv');
        $exists = is_file($path);
        $stale  = $exists && (time() - filemtime($path) > self::TTL_SECONDS);

        if (!$exists || $stale) {
            // Debounce: maks. jedna regeneracja na okno TTL; po odpowiedzi, żeby nie blokować pobrania.
            if (Cache::add("selly:regen:{$integration->id}", 1, self::TTL_SECONDS)) {
                SynchronizeIntegration::dispatch($integration->id)->afterResponse();
            }
        }

        if (!$exists) {
            abort(503, 'Feed jest właśnie generowany — spróbuj ponownie za chwilę.');
        }

        return response()->file($path, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
