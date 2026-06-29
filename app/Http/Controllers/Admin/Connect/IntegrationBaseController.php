<?php

namespace App\Http\Controllers\Admin\Connect;

use App\Http\Controllers\Admin\Controller;
use App\Models\Connect\BaseSettings;
use App\Models\Connect\Order;
use App\Models\Connect\OrderSyncLog;
use App\Services\BaseLinker\BaseLinkerClient;
use App\Services\BaseLinker\BaseLinkerException;
use App\Services\BaseLinker\BaseLinkerInvoiceSyncService;
use App\Services\BaseLinker\BaseLinkerOrderSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationBaseController extends Controller
{
    public function index(): Response
    {
        $bases = BaseSettings::query()
            ->orderBy('id')
            ->get()
            ->map(fn (BaseSettings $b) => [
                'id' => $b->id,
                'label' => $b->label,
                'enabled' => $b->enabled,
                'has_api_key' => $b->hasApiKey(),
                'masked_api_key' => $b->maskedApiKey(),
                'sync_interval_minutes' => $b->sync_interval_minutes,
                'last_sync_at' => $b->last_sync_at?->toIso8601String(),
                'last_sync_order_id' => $b->last_sync_order_id,
                'orders_count' => Order::where('base_settings_id', $b->id)->count(),
            ]);

        return Inertia::render('Connect/Integrations/Base/Index', [
            'bases' => $bases,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Connect/Integrations/Base/Form', [
            'base' => null,
            'recentLogs' => [],
            'stats' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateBase($request);

        $base = new BaseSettings();
        $base->label = $data['label'];
        $base->enabled = (bool) $data['enabled'];
        $base->sync_from_date = $data['sync_from_date'] ?? null;
        $base->date_filter_type = $data['date_filter_type'] ?? 'date_add';
        $base->include_archive = (bool) ($data['include_archive'] ?? false);
        $base->include_unconfirmed = (bool) ($data['include_unconfirmed'] ?? true);
        $base->sync_interval_minutes = (int) $data['sync_interval_minutes'];
        if (! empty($data['api_key'])) {
            $base->api_key = $data['api_key'];
        }
        $base->save();

        return redirect()
            ->route('crafter.connect.integrations.base.edit', $base->id)
            ->with('success', "Base „{$base->label}” utworzony.");
    }

    public function edit(BaseSettings $base): Response
    {
        $recentLogs = OrderSyncLog::query()
            ->where('base_settings_id', $base->id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (OrderSyncLog $log) => [
                'id' => $log->id,
                'trigger' => $log->trigger,
                'status' => $log->status,
                'orders_fetched' => $log->orders_fetched,
                'orders_new' => $log->orders_new,
                'orders_updated' => $log->orders_updated,
                'error_message' => $log->error_message,
                'started_at' => $log->started_at?->toIso8601String(),
                'finished_at' => $log->finished_at?->toIso8601String(),
                'duration_seconds' => $log->duration_seconds,
            ]);

        $stats = [
            'total_orders' => Order::where('base_settings_id', $base->id)->count(),
            'last_sync_at' => $base->last_sync_at?->toIso8601String(),
            'last_sync_order_id' => $base->last_sync_order_id,
        ];

        return Inertia::render('Connect/Integrations/Base/Form', [
            'base' => [
                'id' => $base->id,
                'label' => $base->label,
                'enabled' => $base->enabled,
                'has_api_key' => $base->hasApiKey(),
                'masked_api_key' => $base->maskedApiKey(),
                'sync_from_date' => $base->sync_from_date?->toDateString(),
                'date_filter_type' => $base->date_filter_type ?? 'date_add',
                'include_archive' => (bool) $base->include_archive,
                'include_unconfirmed' => $base->include_unconfirmed === null ? true : (bool) $base->include_unconfirmed,
                'sync_interval_minutes' => $base->sync_interval_minutes,
            ],
            'stats' => $stats,
            'recentLogs' => $recentLogs,
        ]);
    }

    public function update(Request $request, BaseSettings $base): RedirectResponse
    {
        $data = $this->validateBase($request, $base->id);

        $base->label = $data['label'];
        $base->enabled = (bool) $data['enabled'];
        $base->sync_from_date = $data['sync_from_date'] ?? null;
        $base->date_filter_type = $data['date_filter_type'] ?? 'date_add';
        $base->include_archive = (bool) ($data['include_archive'] ?? false);
        $base->include_unconfirmed = (bool) ($data['include_unconfirmed'] ?? true);
        $base->sync_interval_minutes = (int) $data['sync_interval_minutes'];
        if (! empty($data['api_key'])) {
            $base->api_key = $data['api_key'];
        }
        $base->save();

        return redirect()
            ->route('crafter.connect.integrations.base.edit', $base->id)
            ->with('success', 'Ustawienia zapisane.');
    }

    public function destroy(BaseSettings $base): RedirectResponse
    {
        $label = $base->label;
        $base->delete();

        return redirect()
            ->route('crafter.connect.integrations.base.index')
            ->with('success', "Base „{$label}” usunięty.");
    }

    public function testConnection(Request $request, ?BaseSettings $base = null): JsonResponse
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string'],
        ]);

        $apiKey = $data['api_key'] ?? null;
        if (empty($apiKey) && $base) {
            $apiKey = $base->api_key;
        }

        if (empty($apiKey)) {
            return response()->json([
                'ok' => false,
                'message' => 'Brak klucza API do przetestowania.',
            ], 422);
        }

        try {
            $result = (new BaseLinkerClient($apiKey))->testConnection();
            return response()->json($result);
        } catch (BaseLinkerException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function triggerSync(BaseSettings $base): JsonResponse
    {
        if (! $base->hasApiKey()) {
            return response()->json([
                'ok' => false,
                'message' => 'Najpierw skonfiguruj klucz API.',
            ], 422);
        }

        $log = BaseLinkerOrderSyncService::fromSettings($base)->syncOrders('manual');

        // Po zamówieniach odśwież też faktury i korekty (best-effort).
        // Kolejność jest istotna: zamówienia najpierw — dzięki temu świeżo
        // zaimportowane faktury od razu łapią lokalne order_id (join w Zestawieniach).
        $invoiceStats = null;
        try {
            $dateFrom = $base->sync_from_date ?: now()->subDays(30);
            $invoiceStats = BaseLinkerInvoiceSyncService::fromSettings($base)->syncInvoices($dateFrom);
            $base->forceFill(['last_invoice_sync_at' => now()])->save();
        } catch (\Throwable $e) {
            \Log::warning("Invoice sync (manual trigger) failed for base {$base->id}: " . $e->getMessage());
        }

        return response()->json([
            'ok' => $log->status === 'success',
            'log' => [
                'status' => $log->status,
                'orders_fetched' => $log->orders_fetched,
                'orders_new' => $log->orders_new,
                'orders_updated' => $log->orders_updated,
                'error_message' => $log->error_message,
                'duration_seconds' => $log->duration_seconds,
            ],
            // {fetched,new,updated} albo null gdy sync faktur się wywalił (zamówienia i tak OK)
            'invoices' => $invoiceStats,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validateBase(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'label' => [
                'required', 'string', 'max:80',
                Rule::unique('connect_base_settings', 'label')->ignore($ignoreId),
            ],
            'api_key' => ['nullable', 'string', 'min:10', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'sync_from_date' => ['nullable', 'date'],
            'date_filter_type' => ['nullable', Rule::in(['date_add', 'date_confirmed'])],
            'include_archive' => ['nullable', 'boolean'],
            'include_unconfirmed' => ['nullable', 'boolean'],
            'sync_interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);
    }
}
