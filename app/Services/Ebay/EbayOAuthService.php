<?php

namespace App\Services\Ebay;

use App\Models\Scrap\EbaySettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OAuth user-context (Authorization Code + refresh) dla Sell/Trading API — pobieranie WŁASNYCH
 * ofert i zmiana cen. Różni się od EbayBrowseClient::token() (client_credentials, monitoring).
 *
 * Flow: authorizationUrl() → sprzedawca loguje się na eBay i zgadza → callback z `code`
 * → exchangeCode() zapisuje refresh-token (szyfrowany, ~18 mies.) → accessToken() odświeża
 * access-token (2h) z Cache, a po wygaśnięciu sam pobiera nowy z refresh-tokena.
 *
 * Wymóg po stronie eBay Developer: RuName (redirect) + scope sell.inventory na keysecie PRD.
 */
class EbayOAuthService
{
    private string $api = 'https://api.ebay.com';
    private string $authUi = 'https://auth.ebay.com/oauth2/authorize';

    /** Scope'y user-context potrzebne do pobierania ofert i zmiany cen (Trading + Sell). */
    public const SCOPES = [
        'https://api.ebay.com/oauth/api_scope/sell.inventory',
        'https://api.ebay.com/oauth/api_scope/sell.account',
    ];

    public function __construct(private EbaySettings $settings) {}

    /** URL zgody eBay — tam przekierowujemy sprzedawcę. redirect_uri to RuName (nie URL!). */
    public function authorizationUrl(string $state): string
    {
        return $this->authUi . '?' . http_build_query([
            'client_id' => $this->settings->client_id,
            'redirect_uri' => $this->settings->ru_name,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'prompt' => 'login',
        ]);
    }

    /** Callback: kod autoryzacyjny → refresh-token (DB, szyfrowany) + access-token (Cache). */
    public function exchangeCode(string $code): void
    {
        $res = Http::asForm()
            ->withBasicAuth($this->settings->client_id, $this->settings->client_secret)
            ->post($this->api . '/identity/v1/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->settings->ru_name,
            ]);

        if (! $res->successful() || empty($res['refresh_token'])) {
            throw new \RuntimeException('eBay OAuth (authorization_code) błąd: ' . $res->body());
        }

        $this->settings->forceFill([
            'oauth_refresh_token' => $res['refresh_token'],
            'oauth_refresh_expires_at' => now()->addSeconds((int) ($res['refresh_token_expires_in'] ?? 47_304_000)),
            'oauth_scopes' => $res['scope'] ?? implode(' ', self::SCOPES),
            'oauth_connected_at' => now(),
        ])->save();

        $this->cacheAccessToken((string) $res['access_token'], (int) ($res['expires_in'] ?? 7200));
    }

    /** User access-token (Bearer) — z Cache; po wygaśnięciu odświeżany z refresh-tokena. */
    public function accessToken(): string
    {
        if ($cached = Cache::get($this->cacheKey())) {
            return $cached;
        }

        $refresh = $this->settings->oauth_refresh_token;
        if (! $refresh) {
            throw new \RuntimeException('Konto eBay nie jest połączone (brak refresh-tokena). Połącz konto w Connect → Integracja eBay.');
        }

        $res = Http::asForm()
            ->withBasicAuth($this->settings->client_id, $this->settings->client_secret)
            ->post($this->api . '/identity/v1/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
                'scope' => implode(' ', self::SCOPES),
            ]);

        if (! $res->successful() || empty($res['access_token'])) {
            throw new \RuntimeException('eBay OAuth (refresh_token) błąd: ' . $res->body());
        }

        $token = (string) $res['access_token'];
        $this->cacheAccessToken($token, (int) ($res['expires_in'] ?? 7200));

        return $token;
    }

    /** Rozłącz konto — usuń refresh-token i cache access-tokenu. */
    public function disconnect(): void
    {
        Cache::forget($this->cacheKey());
        $this->settings->forceFill([
            'oauth_refresh_token' => null,
            'oauth_refresh_expires_at' => null,
            'oauth_scopes' => null,
            'oauth_connected_at' => null,
            'ebay_user_id' => null,
        ])->save();
    }

    private function cacheAccessToken(string $token, int $expiresIn): void
    {
        Cache::put($this->cacheKey(), $token, max(60, $expiresIn - 120)); // bufor 2 min przed wygaśnięciem
    }

    private function cacheKey(): string
    {
        return 'ebay.user.token.' . md5((string) $this->settings->client_id);
    }
}
