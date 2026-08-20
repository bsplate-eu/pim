<?php

namespace App\Http\Controllers\Admin\Connect\Marketplace;

use App\Http\Controllers\Admin\Controller;
use App\Models\Ebay\EbayCategory;
use App\Models\Ebay\EbayScheme;
use App\Models\Pricelist;
use App\Models\Ebay\EbayTemplate;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayInventoryClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo Connect → Marketplace → eBay → Schematy.
 * Wzorzec: Connect\Marketplace\AllegroSchemeController z OMS ARGO.
 *
 * Schemat = przepis wystawiania per (rynek, kategoria). Spina kategorię z etapu A, szablon
 * treści z etapu B i cennik. Ekran to lista z edycją w miejscu — jak w OMS, bez osobnej podstrony.
 */
class EbaySchemeController extends Controller
{
    public function index(): Response
    {
        $schemes = EbayScheme::with(['category', 'template', 'pricelist'])->orderBy('name')->get()
            ->map(fn (EbayScheme $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'marketplace' => $s->marketplace,
                'locale' => $s->locale(),
                'ebay_category_id' => $s->ebay_category_id,
                'category_label' => $s->category
                    ? "{$s->category->category_name} (#{$s->category->category_id})"
                    : null,
                'template_id' => $s->template_id,
                'template_label' => $s->template ? "{$s->template->name} ({$s->template->marketplace})" : null,
                'pricelist_id' => $s->pricelist_id,
                'price_multiplier' => $s->price_multiplier,
                'tax_percent' => $s->tax_percent,
                'default_stock' => $s->default_stock,
                'fulfillment_policy_id' => $s->fulfillment_policy_id,
                'payment_policy_id' => $s->payment_policy_id,
                'return_policy_id' => $s->return_policy_id,
                'merchant_location_key' => $s->merchant_location_key,
                'publication_mode' => $s->publication_mode,
                'enabled' => $s->enabled,
                'problems' => $s->problems(),
                // Braki blokujące AKTYWACJĘ (szkic przejdzie bez nich) — pokazujemy osobno,
                // żeby nie mylić ich z brakami blokującymi cokolwiek.
                'missing_for_active' => array_values(array_filter([
                    $s->fulfillment_policy_id ? null : 'dostawa',
                    $s->payment_policy_id ? null : 'płatności',
                    $s->return_policy_id ? null : 'zwroty',
                    $s->merchant_location_key ? null : 'lokalizacja',
                ])),
            ]);

        return Inertia::render('Connect/Marketplace/Ebay/Schemes/Index', [
            'schemes' => $schemes,
            // Tylko aktywne kategorie — nieaktywna nie ma po co trafiać do schematu.
            'categories' => EbayCategory::active()->orderBy('marketplace')->orderBy('category_name')->get()
                ->map(fn (EbayCategory $c) => [
                    'id' => $c->id,
                    'marketplace' => $c->marketplace,
                    'label' => "{$c->category_name} (#{$c->category_id})",
                    'ready' => $c->isReadyForListing(),
                ]),
            // Szablon eBay należy do jednego rynku — formularz odsiewa po nim, bo szablon
            // spoza rynku dałby schemat, którym nie da się wystawić.
            'templates' => EbayTemplate::orderBy('marketplace')->orderBy('name')->get(['id', 'name', 'marketplace', 'enabled'])
                ->map(fn (EbayTemplate $t) => [
                    'id' => $t->id,
                    'label' => $t->name.($t->enabled ? '' : ' (wyłączony)'),
                    'marketplace' => $t->marketplace,
                ]),
            'pricelists' => Pricelist::orderBy('name')->get(['id', 'name', 'currency']),
            'marketplaces' => array_keys(EbayScheme::MARKETPLACE_LOCALE),
            'marketplaceLocales' => EbayScheme::MARKETPLACE_LOCALE,
        ]);
    }

    /**
     * Polityki biznesowe i lokalizacje magazynowe konta dla danego rynku.
     * Wołane z ekranu przy zmianie rynku — eBay trzyma je per marketplace, więc lista
     * dla DE i FR bywa inna. Wymaga OAuth (Account API), stąd czytelny komunikat zamiast błędu.
     */
    public function policies(Request $request): JsonResponse
    {
        $data = $request->validate(['marketplace' => ['required', 'string', 'max:16']]);

        $settings = EbaySettings::first();
        if (! $settings || ! $settings->isOauthConnected()) {
            return response()->json([
                'error' => 'Konto eBay nie jest połączone — polityk nie da się pobrać (Integracje → Ebay).',
                'fulfillment' => [], 'payment' => [], 'return' => [], 'locations' => [],
            ]);
        }

        try {
            $client = EbayInventoryClient::fromSettings($settings);
            $policies = $client->businessPolicies($data['marketplace']);

            return response()->json($policies + ['error' => null, 'locations' => $client->inventoryLocations()]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'fulfillment' => [], 'payment' => [], 'return' => [], 'locations' => [],
            ]);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $scheme = EbayScheme::create($this->validated($request));

        return back()->with('success', $this->readinessMessage($scheme, 'Schemat utworzony'));
    }

    public function update(Request $request, EbayScheme $scheme): RedirectResponse
    {
        $scheme->update($this->validated($request));

        return back()->with('success', $this->readinessMessage($scheme->fresh(['category', 'template']), 'Schemat zapisany'));
    }

    public function destroy(EbayScheme $scheme): RedirectResponse
    {
        $scheme->delete();

        return back()->with('success', 'Schemat usunięty.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'marketplace' => ['required', 'string', 'max:16'],
            'ebay_category_id' => ['nullable', 'integer', 'exists:ebay_categories,id'],
            'template_id' => ['nullable', 'integer', 'exists:ebay_templates,id'],
            'pricelist_id' => ['nullable', 'integer', 'exists:pricelists,id'],
            'price_multiplier' => ['required', 'numeric', 'min:0.0001', 'max:1000'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'default_stock' => ['required', 'integer', 'min:0', 'max:10000'],
            'fulfillment_policy_id' => ['nullable', 'string', 'max:80'],
            'payment_policy_id' => ['nullable', 'string', 'max:80'],
            'return_policy_id' => ['nullable', 'string', 'max:80'],
            'merchant_location_key' => ['nullable', 'string', 'max:80'],
            'publication_mode' => ['required', 'in:draft,active'],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    /**
     * Zapis się udaje nawet dla niekompletnego schematu (żeby dało się go budować etapami),
     * ale komunikat od razu mówi, czego brakuje — inaczej brak wyszedłby dopiero przy wystawianiu.
     */
    private function readinessMessage(EbayScheme $scheme, string $prefix): string
    {
        $problems = $scheme->problems();

        return $problems === []
            ? "{$prefix} — gotowy do wystawiania."
            : "{$prefix}. Do uzupełnienia: ".implode(' · ', $problems).'.';
    }
}
