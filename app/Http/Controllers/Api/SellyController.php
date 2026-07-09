<?php

namespace App\Http\Controllers\Api;

use App\Exports\Admin\SellyIntegrationProductsExport;
use App\Jobs\SynchronizeIntegration;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class SellyController
{
    /** Po ilu sekundach plik feedu uznajemy za nieświeży i zlecamy regenerację w tle. */
    private const TTL_SECONDS = 6 * 3600;

    /**
     * Feed CSV dla integratora Selly (pełny katalog) — serwowany z cache; generacja w tle.
     *
     * TRYB TESTOWY: `?ids=1717,1786,...` → generuje NA ŻĄDANIE tylko wskazane produkty
     * (mały zestaw = szybko, bez cache). Do testowego pushu N produktów, zanim ruszy pełny feed.
     */
    public function download(Request $request, int $integration_id)
    {
        $integration = Integration::where('id', $integration_id)
            ->where('type', 'selly')
            ->first();

        // Auth: HMAC-SHA256(secret, "selly:{id}") — ten sam klucz co w webhook URL.
        $expected = $integration?->computeWebhookHash() ?: '';
        abort_if($expected === '' || !hash_equals($expected, (string) $request->input('key')), 403);

        // --- TRYB TESTOWY: tylko wskazane external_id, generacja na żądanie ---
        if ($request->filled('ids')) {
            $ids = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('ids')))));
            $export = new SellyIntegrationProductsExport($integration, $ids);
            $csv = Excel::raw($export, ExcelWriter::CSV);

            return response($csv, 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="selly-' . $integration->id . '-test.csv"',
            ]);
        }

        // --- TRYB NORMALNY: pełny katalog z cache; regeneracja w tle przy nieświeżym pliku ---
        $path   = storage_path('app/integrations/' . $integration->id . '.csv');
        $exists = is_file($path);
        $stale  = $exists && (time() - filemtime($path) > self::TTL_SECONDS);

        if (!$exists || $stale) {
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
