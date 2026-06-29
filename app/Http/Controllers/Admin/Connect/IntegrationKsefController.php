<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Models\Ksef\KsefSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Argo Connect → Integracje → KSeF.
 * Poświadczenia integracji KSeF dla 2 firm (Pareto / BSP) — po jednej zakładce na firmę.
 * Wzorzec: Connect\IntegrationEbayController.
 */
class IntegrationKsefController extends Controller
{
    /** Firmy obsługiwane przez integrację — klucz => domyślna etykieta. */
    private const COMPANIES = [
        'pareto' => 'Pareto',
        'bsp' => 'BSP',
    ];

    public function index(Request $request): Response
    {
        $companies = collect(self::COMPANIES)->map(function (string $label, string $company) {
            $settings = KsefSettings::firstOrCreate(
                ['company' => $company],
                ['label' => $label],
            );

            return [
                'company' => $settings->company,
                'label' => $settings->label ?: $label,
                'nip' => $settings->nip,
                'environment' => $settings->environment,
                'has_token' => $settings->hasToken(),
                'masked_token' => $settings->maskedToken(),
                'enabled' => $settings->enabled,
                'last_sync_at' => $settings->last_sync_at?->toIso8601String(),
            ];
        })->values();

        return Inertia::render('Connect/Integrations/Ksef/Index', [
            'companies' => $companies,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'in:' . implode(',', array_keys(self::COMPANIES))],
            'nip' => ['nullable', 'string', 'max:32'],
            'environment' => ['required', 'string', 'in:test,prod'],
            'auth_token' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['required', 'boolean'],
        ]);

        $settings = KsefSettings::firstOrCreate(
            ['company' => $data['company']],
            ['label' => self::COMPANIES[$data['company']]],
        );

        $settings->nip = $data['nip'] ?? null;
        $settings->environment = $data['environment'];
        $settings->enabled = (bool) $data['enabled'];
        if (! empty($data['auth_token'])) {
            $settings->auth_token = $data['auth_token']; // szyfrowane w modelu
        }
        $settings->save();

        return redirect()
            ->route('crafter.connect.integrations.ksef.index')
            ->with('success', 'Ustawienia KSeF zapisane.');
    }
}
