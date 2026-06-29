<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Models\Scrap\EbaySettings;
use App\Models\Scrap\ScrapProduct;
use App\Services\Ebay\EbayBrowseClient;
use App\Services\Ebay\EbayOAuthService;
use App\Services\Ebay\EbayScrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Argo Connect → Integracje → Ebay.
 * Ustawienia integracji eBay + tabela pobranych ofert konkurenta.
 * Wzorzec: Connect\IntegrationBaseController.
 */
class IntegrationEbayController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = EbaySettings::first();

        return Inertia::render('Connect/Integrations/Ebay/Index', [
            'settings' => $settings ? [
                'id' => $settings->id,
                'label' => $settings->label,
                'client_id' => $settings->client_id,
                'has_secret' => $settings->hasCredentials(),
                'masked_secret' => $settings->maskedClientSecret(),
                'seller' => $settings->seller,
                'marketplace' => $settings->marketplace,
                'keyword' => $settings->keyword,
                'enabled' => $settings->enabled,
                'last_sync_at' => $settings->last_sync_at?->toIso8601String(),
                'last_sync_count' => $settings->last_sync_count,
                'ru_name' => $settings->ru_name,
                'oauth_connected' => $settings->isOauthConnected(),
                'oauth_connected_at' => $settings->oauth_connected_at?->toIso8601String(),
                'ebay_user_id' => $settings->ebay_user_id,
            ] : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'seller' => ['required', 'string', 'max:120'],
            'marketplace' => ['required', 'string', 'max:16'],
            'keyword' => ['required', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'],
            'ru_name' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = EbaySettings::first() ?? new EbaySettings();
        $settings->label = $data['label'];
        $settings->client_id = $data['client_id'] ?? null;
        $settings->seller = $data['seller'];
        $settings->marketplace = $data['marketplace'];
        $settings->keyword = $data['keyword'];
        $settings->enabled = (bool) $data['enabled'];
        $settings->ru_name = $data['ru_name'] ?? null;
        if (! empty($data['client_secret'])) {
            $settings->client_secret = $data['client_secret']; // szyfrowane w modelu
        }
        $settings->save();

        return redirect()
            ->route('crafter.connect.integrations.ebay.index')
            ->with('success', 'Ustawienia eBay zapisane.');
    }

    public function testConnection(Request $request): JsonResponse
    {
        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');
        $settings = EbaySettings::first();

        // Jeśli pola puste — użyj zapisanych.
        if (empty($clientId) && $settings) {
            $clientId = $settings->client_id;
        }
        if (empty($clientSecret) && $settings) {
            $clientSecret = $settings->client_secret;
        }

        if (empty($clientId) || empty($clientSecret)) {
            return response()->json(['ok' => false, 'message' => 'Brak App ID / Cert ID do przetestowania.'], 422);
        }

        $client = new EbayBrowseClient($clientId, $clientSecret, $request->input('marketplace', $settings->marketplace ?? 'EBAY_DE'));
        $result = $client->testConnection(
            $request->input('seller', $settings->seller ?? 'scutprotectionsrl'),
            $request->input('keyword', $settings->keyword ?? 'Unterfahrschutz'),
        );

        return response()->json($result);
    }

    public function sync(): JsonResponse
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            return response()->json(['ok' => false, 'message' => 'Najpierw zapisz App ID i Cert ID.'], 422);
        }

        try {
            $stats = EbayScrapService::fromSettings($settings)->sync(false);
            return response()->json([
                'ok' => true,
                'message' => "Pobrano {$stats['fetched']} ofert (nowych: {$stats['new']}, zaktualizowanych: {$stats['updated']}).",
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** Krok 1 OAuth: przekieruj sprzedawcę na stronę zgody eBay (user-token do Sell/Trading API). */
    public function oauthConnect(Request $request): RedirectResponse
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials() || empty($settings->ru_name)) {
            return redirect()->route('crafter.connect.integrations.ebay.index')
                ->with('error', 'Najpierw zapisz App ID, Cert ID i RuName, potem połącz konto.');
        }

        $state = Str::random(40);
        $request->session()->put('ebay_oauth_state', $state);

        return redirect()->away((new EbayOAuthService($settings))->authorizationUrl($state));
    }

    /** Krok 2 OAuth: callback od eBay z kodem autoryzacji → zapis refresh-tokena. */
    public function oauthCallback(Request $request): RedirectResponse
    {
        $back = redirect()->route('crafter.connect.integrations.ebay.index');
        $settings = EbaySettings::first();

        if (! $settings) {
            return $back->with('error', 'Brak ustawień eBay.');
        }
        if ($request->input('state') !== $request->session()->pull('ebay_oauth_state')) {
            return $back->with('error', 'Nieprawidłowy state (ochrona CSRF) — spróbuj połączyć ponownie.');
        }
        if (! $request->filled('code')) {
            return $back->with('error', 'eBay nie zwrócił kodu: ' . $request->input('error_description', $request->input('error', 'brak')));
        }

        try {
            (new EbayOAuthService($settings))->exchangeCode($request->input('code'));
            return $back->with('success', 'Konto eBay połączone — można pobierać oferty.');
        } catch (\Throwable $e) {
            return $back->with('error', 'Błąd autoryzacji eBay: ' . $e->getMessage());
        }
    }

    /** Rozłącz konto eBay (usuń refresh-token + cache access-tokenu). */
    public function oauthDisconnect(): RedirectResponse
    {
        if ($settings = EbaySettings::first()) {
            (new EbayOAuthService($settings))->disconnect();
        }

        return redirect()->route('crafter.connect.integrations.ebay.index')
            ->with('success', 'Konto eBay rozłączone.');
    }
}
