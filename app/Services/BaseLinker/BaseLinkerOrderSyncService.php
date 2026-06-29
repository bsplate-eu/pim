<?php

namespace App\Services\BaseLinker;

use App\Models\Connect\BaseSettings;
use App\Models\Connect\Order;
use App\Models\Connect\OrderProduct;
use App\Models\Connect\OrderStatus;
use App\Models\Connect\OrderSyncLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BaseLinkerOrderSyncService
{
    private const BATCH_LIMIT = 100;
    private const MAX_BATCHES_PER_RUN = 20; // twarde ograniczenie aby nie zawisnąć

    private CustomerResolver $customerResolver;

    public function __construct(
        private readonly BaseLinkerClient $client,
        private readonly BaseSettings $settings,
    ) {
        $this->customerResolver = new CustomerResolver();
    }

    public static function fromSettings(BaseSettings $settings): self
    {
        return new self(new BaseLinkerClient($settings->api_key ?? ''), $settings);
    }

    /**
     * Synchronizacja słownika statusów.
     */
    public function syncStatuses(): int
    {
        $statuses = $this->client->getOrderStatusList();
        $count = 0;
        foreach ($statuses as $s) {
            OrderStatus::updateOrCreate(
                ['baselinker_status_id' => (int) ($s['id'] ?? 0)],
                [
                    'name' => (string) ($s['name'] ?? ''),
                    'name_for_customer' => $s['name_for_customer'] ?? null,
                    'color' => $s['color'] ?? null,
                ]
            );
            $count++;
        }
        return $count;
    }

    /**
     * Pełna synchronizacja zamówień od ostatniego kursora (albo sync_from_date).
     *
     * Strategia (od fixa 2026-05-11):
     * - Używamy `date_from` (data dodania) zamiast `date_confirmed_from` — łapie też niepotwierdzone
     *   oraz zamówienia, których status zmieniono wstecznie po `date_confirmed`.
     * - Zawsze `get_unconfirmed_orders=true` (BL inaczej pomija "Nowe zamówienia").
     * - Zawsze `include_archive=true` — gdyby kiedyś klient zarchiwizował.
     * - Paginacja po `id_from = max_id+1` (BL sortuje rosnąco po `order_id`).
     */
    public function syncOrders(string $trigger = 'scheduled'): OrderSyncLog
    {
        $settings = $this->settings;

        $log = OrderSyncLog::create([
            'base_settings_id' => $settings->id,
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $totalFetched = 0;
        $totalNew = 0;
        $totalUpdated = 0;

        try {
            // Odśwież statusy (taniej niż w oddzielnym cronie)
            $this->syncStatuses();

            $cursor = $this->buildInitialCursor($settings);
            $batches = 0;
            $highestOrderId = $settings->last_sync_order_id ?? 0;

            do {
                $orders = $this->client->getOrders($cursor);
                $count = count($orders);
                $totalFetched += $count;

                foreach ($orders as $payload) {
                    [$isNew, $order] = $this->persistOrder($payload);
                    if ($isNew) {
                        $totalNew++;
                    } else {
                        $totalUpdated++;
                    }
                    $highestOrderId = max($highestOrderId, (int) $order->baselinker_order_id);
                }

                // Paginacja: BaseLinker zwraca max 100; jeśli mniej, koniec
                if ($count < self::BATCH_LIMIT) {
                    break;
                }

                // Następna partia od ostatniego pobranego ID
                $cursor = [
                    'id_from' => $highestOrderId + 1,
                    'get_unconfirmed_orders' => (bool) ($settings->include_unconfirmed ?? true),
                    'include_archive' => (bool) ($settings->include_archive ?? false),
                ];

                $batches++;
            } while ($batches < self::MAX_BATCHES_PER_RUN);

            $settings->update([
                'last_sync_at' => now(),
                'last_sync_order_id' => $highestOrderId,
            ]);

            $log->update([
                'status' => 'success',
                'orders_fetched' => $totalFetched,
                'orders_new' => $totalNew,
                'orders_updated' => $totalUpdated,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('BaseLinker sync failed: ' . $e->getMessage(), [
                'base_settings_id' => $settings->id,
                'label' => $settings->label,
                'exception' => $e,
            ]);
            $log->update([
                'status' => 'error',
                'orders_fetched' => $totalFetched,
                'orders_new' => $totalNew,
                'orders_updated' => $totalUpdated,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $log->fresh();
    }

    /**
     * Pojedyncze zamówienie (ręczny refresh z karty).
     */
    public function syncSingleOrder(int $baselinkerOrderId): ?Order
    {
        $orders = $this->client->getOrders(['order_id' => $baselinkerOrderId]);
        if (empty($orders)) {
            return null;
        }
        [, $order] = $this->persistOrder($orders[0]);
        return $order;
    }

    /**
     * Buduje początkowy zestaw parametrów getOrders.
     *
     * @return array<string,mixed>
     */
    private function buildInitialCursor(BaseSettings $settings): array
    {
        $includeUnconfirmed = (bool) ($settings->include_unconfirmed ?? true);
        $includeArchive = (bool) ($settings->include_archive ?? false);

        // Jeśli mamy kursor — kontynuujemy od niego
        if ($settings->last_sync_order_id) {
            return [
                'id_from' => $settings->last_sync_order_id + 1,
                'get_unconfirmed_orders' => $includeUnconfirmed,
                'include_archive' => $includeArchive,
            ];
        }

        // Pierwszy bieg — filtrujemy wg ustawień Base:
        // - 'date_add' (default) → łapie też niepotwierdzone i zamówienia potwierdzone wstecznie
        // - 'date_confirmed' → tylko zamówienia z `date_confirmed >= sync_from_date`
        $from = $settings->sync_from_date ?? now()->subDays(30);
        $filterKey = ($settings->date_filter_type ?? 'date_add') === 'date_confirmed'
            ? 'date_confirmed_from'
            : 'date_from';

        return [
            $filterKey => $from->timestamp,
            'get_unconfirmed_orders' => $includeUnconfirmed,
            'include_archive' => $includeArchive,
        ];
    }

    /**
     * Zapisuje (upsert) zamówienie + produkty z payloadu BaseLinker.
     *
     * @param  array<string,mixed>  $payload
     * @return array{0:bool, 1:Order}  [isNew, order]
     */
    private function persistOrder(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $blOrderId = (int) ($payload['order_id'] ?? 0);
            $existing = Order::where('baselinker_order_id', $blOrderId)->first();
            $isNew = $existing === null;

            // Najpierw — rozpoznaj/utwórz klienta
            $customer = $this->customerResolver->resolveFromOrderPayload($payload);

            $data = $this->mapOrderData($payload);
            $data['customer_id'] = $customer->id;
            $data['base_settings_id'] = $this->settings->id;

            if ($existing) {
                $existing->fill($data)->save();
                $order = $existing;
            } else {
                $order = Order::create(array_merge($data, [
                    'baselinker_order_id' => $blOrderId,
                    'imported_at' => now(),
                ]));
            }

            // Produkty — najprościej: usuwamy i wstawiamy ponownie (mało wierszy)
            $order->products()->delete();

            $productsTotal = 0;
            foreach (($payload['products'] ?? []) as $p) {
                $productData = $this->mapProductData($p);
                $order->products()->create($productData);
                $productsTotal += ($productData['price_brutto'] ?? 0) * ($productData['quantity'] ?? 0);
            }

            // Przelicz total
            $order->total_amount = $productsTotal + (float) ($order->delivery_price ?? 0);
            $order->updated_from_api_at = now();
            $order->save();

            // Zaktualizuj licznik zamówień klienta
            $customer->orders_count = $customer->orders()->count();
            $customer->save();

            return [$isNew, $order];
        });
    }

    /**
     * @param  array<string,mixed>  $p
     * @return array<string,mixed>
     */
    private function mapOrderData(array $p): array
    {
        return [
            'shop_order_id' => $p['shop_order_id'] ?? null,
            'external_order_id' => $p['external_order_id'] ?? null,
            'order_source' => $p['order_source'] ?? null,
            'order_source_id' => $p['order_source_id'] ?? null,
            'order_source_info' => $p['order_source_info'] ?? null,
            'order_status_id' => $p['order_status_id'] ?? null,

            'date_add' => $this->unixToDate($p['date_add'] ?? null),
            'date_confirmed' => $this->unixToDate($p['date_confirmed'] ?? null),
            'date_in_status' => $this->unixToDate($p['date_in_status'] ?? null),
            'confirmed' => (bool) ($p['confirmed'] ?? false),

            'email' => $p['email'] ?? null,
            'phone' => $p['phone'] ?? null,
            'user_login' => $p['user_login'] ?? null,
            'user_comments' => $p['user_comments'] ?? null,
            'admin_comments' => $p['admin_comments'] ?? null,

            'currency' => $p['currency'] ?? null,
            'payment_method' => $p['payment_method'] ?? null,
            'payment_method_cod' => (string) ($p['payment_method_cod'] ?? '0') === '1',
            'payment_done' => (float) ($p['payment_done'] ?? 0),

            'delivery_method_id' => $p['delivery_method_id'] ?? null,
            'delivery_method' => $p['delivery_method'] ?? null,
            'delivery_price' => (float) ($p['delivery_price'] ?? 0),
            'delivery_package_module' => $p['delivery_package_module'] ?? null,
            'delivery_package_nr' => $p['delivery_package_nr'] ?? null,
            'delivery_fullname' => $p['delivery_fullname'] ?? null,
            'delivery_company' => $p['delivery_company'] ?? null,
            'delivery_address' => $p['delivery_address'] ?? null,
            'delivery_postcode' => $p['delivery_postcode'] ?? null,
            'delivery_city' => $p['delivery_city'] ?? null,
            'delivery_state' => $p['delivery_state'] ?? null,
            'delivery_country' => $p['delivery_country'] ?? null,
            'delivery_country_code' => $p['delivery_country_code'] ?? null,
            'delivery_point_id' => $p['delivery_point_id'] ?? null,
            'delivery_point_name' => $p['delivery_point_name'] ?? null,
            'delivery_point_address' => $p['delivery_point_address'] ?? null,
            'delivery_point_postcode' => $p['delivery_point_postcode'] ?? null,
            'delivery_point_city' => $p['delivery_point_city'] ?? null,

            'invoice_fullname' => $p['invoice_fullname'] ?? null,
            'invoice_company' => $p['invoice_company'] ?? null,
            'invoice_nip' => $p['invoice_nip'] ?? null,
            'invoice_address' => $p['invoice_address'] ?? null,
            'invoice_postcode' => $p['invoice_postcode'] ?? null,
            'invoice_city' => $p['invoice_city'] ?? null,
            'invoice_state' => $p['invoice_state'] ?? null,
            'invoice_country' => $p['invoice_country'] ?? null,
            'invoice_country_code' => $p['invoice_country_code'] ?? null,
            'want_invoice' => (string) ($p['want_invoice'] ?? '0') === '1',

            'extra_field_1' => $p['extra_field_1'] ?? null,
            'extra_field_2' => $p['extra_field_2'] ?? null,
            'custom_extra_fields' => $p['custom_extra_fields'] ?? null,
            'pick_state' => (int) ($p['pick_state'] ?? 0),
            'pack_state' => (int) ($p['pack_state'] ?? 0),
            'star' => (int) ($p['star'] ?? 0),
            'commission' => $p['commission'] ?? null,
            'order_page' => $p['order_page'] ?? null,

            'raw_payload' => $p,
        ];
    }

    /**
     * @param  array<string,mixed>  $p
     * @return array<string,mixed>
     */
    private function mapProductData(array $p): array
    {
        return [
            'baselinker_order_product_id' => $p['order_product_id'] ?? null,
            'storage' => $p['storage'] ?? null,
            'storage_id' => $p['storage_id'] ?? null,
            'product_id' => $p['product_id'] ?? null,
            'variant_id' => $p['variant_id'] ?? null,
            'name' => $p['name'] ?? null,
            'sku' => $p['sku'] ?? null,
            'ean' => $p['ean'] ?? null,
            'location' => $p['location'] ?? null,
            'warehouse_id' => $p['warehouse_id'] ?? null,
            'auction_id' => $p['auction_id'] ?? null,
            'attributes' => $p['attributes'] ?? null,
            'price_brutto' => (float) ($p['price_brutto'] ?? 0),
            'tax_rate' => (float) ($p['tax_rate'] ?? 0),
            'quantity' => (int) ($p['quantity'] ?? 1),
            'weight' => (float) ($p['weight'] ?? 0),
            'bundle_id' => $p['bundle_id'] ?? null,
        ];
    }

    private function unixToDate(mixed $unix): ?Carbon
    {
        if (! $unix) {
            return null;
        }
        return Carbon::createFromTimestamp((int) $unix);
    }
}
