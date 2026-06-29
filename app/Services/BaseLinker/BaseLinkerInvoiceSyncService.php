<?php

namespace App\Services\BaseLinker;

use App\Models\Connect\BaseSettings;
use App\Models\Connect\Invoice;
use App\Models\Connect\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BaseLinkerInvoiceSyncService
{
    private const BATCH_LIMIT = 100;
    private const MAX_BATCHES_PER_RUN = 20;

    /** @var array<int,string>|null */
    private ?array $seriesCache = null;

    public function __construct(
        private readonly BaseLinkerClient $client,
        private readonly BaseSettings $settings,
    ) {
    }

    public static function fromSettings(BaseSettings $settings): self
    {
        return new self(new BaseLinkerClient($settings->api_key ?? ''), $settings);
    }

    /**
     * Pełna synchronizacja faktur dla danego Base'a. Cursor po `id_from`.
     *
     * @return array{fetched:int, new:int, updated:int}
     */
    public function syncInvoices(?Carbon $dateFrom = null): array
    {
        $fetched = 0;
        $new = 0;
        $updated = 0;

        try {
            $lastId = (int) ($this->settings->last_invoice_id ?? 0);
            $batches = 0;
            $maxIdSeen = $lastId;

            do {
                $params = ['id_from' => $lastId + 1];
                if ($lastId === 0 && $dateFrom) {
                    $params = ['date_from' => $dateFrom->timestamp];
                }

                $invoices = $this->client->getInvoices($params);
                $count = count($invoices);
                $fetched += $count;

                foreach ($invoices as $payload) {
                    [$isNew] = $this->persistInvoice($payload);
                    if ($isNew) {
                        $new++;
                    } else {
                        $updated++;
                    }
                    $maxIdSeen = max($maxIdSeen, (int) ($payload['invoice_id'] ?? 0));
                }

                if ($count < self::BATCH_LIMIT) {
                    break;
                }

                $lastId = $maxIdSeen;
                $batches++;
            } while ($batches < self::MAX_BATCHES_PER_RUN);

            if ($maxIdSeen > (int) ($this->settings->last_invoice_id ?? 0)) {
                $this->settings->forceFill(['last_invoice_id' => $maxIdSeen])->save();
            }
        } catch (\Throwable $e) {
            Log::error('BaseLinker invoice sync failed: ' . $e->getMessage(), [
                'base_settings_id' => $this->settings->id,
                'label' => $this->settings->label,
                'exception' => $e,
            ]);
            throw $e;
        }

        return [
            'fetched' => $fetched,
            'new' => $new,
            'updated' => $updated,
        ];
    }

    /**
     * Synchronizuje faktury dla pojedynczego zamówienia (ręczny refresh).
     *
     * @return array<int,Invoice>
     */
    public function syncInvoicesForOrder(int $baselinkerOrderId): array
    {
        $invoices = $this->client->getInvoices(['order_id' => $baselinkerOrderId]);
        $result = [];
        foreach ($invoices as $payload) {
            [, $invoice] = $this->persistInvoice($payload);
            $result[] = $invoice;
        }
        return $result;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:bool, 1:Invoice}
     */
    private function persistInvoice(array $payload): array
    {
        $blInvoiceId = (int) ($payload['invoice_id'] ?? 0);
        $existing = Invoice::where('baselinker_invoice_id', $blInvoiceId)->first();
        $isNew = $existing === null;

        $data = $this->mapInvoiceData($payload);

        if ($existing) {
            $existing->fill($data)->save();
            $invoice = $existing;
        } else {
            $invoice = Invoice::create(array_merge($data, [
                'baselinker_invoice_id' => $blInvoiceId,
                'imported_at' => now(),
            ]));
        }

        return [$isNew, $invoice];
    }

    /**
     * @param  array<string,mixed>  $p
     * @return array<string,mixed>
     */
    private function mapInvoiceData(array $p): array
    {
        $blOrderId = (int) ($p['order_id'] ?? 0);
        $localOrderId = $blOrderId > 0
            ? Order::where('baselinker_order_id', $blOrderId)->value('id')
            : null;

        // BL getInvoices: korektę rozpoznajemy po correcting_to_invoice_id > 0
        $correctedId = (int) ($p['correcting_to_invoice_id'] ?? $p['correction_to_invoice_id'] ?? 0);
        $type = $correctedId > 0 ? 'correction' : 'invoice';

        $seriesId = (int) ($p['series_id'] ?? 0);
        // BL: numer sekwencyjny = sub_id, pełny numer = number (np. "37/5/2026/BSP")
        $nr = isset($p['sub_id']) ? (int) $p['sub_id'] : (isset($p['nr']) ? (int) $p['nr'] : null);
        $nrFull = $this->buildNrFull($p);

        return [
            'order_id' => $localOrderId,
            'base_settings_id' => $this->settings->id,
            'baselinker_order_id' => $blOrderId ?: null,
            'series_id' => $seriesId ?: null,
            'series_name' => $this->resolveSeriesName($seriesId),
            'nr' => $nr,
            'nr_full' => $nrFull,
            'type' => $type,
            'corrected_invoice_id' => $correctedId ?: null,
            'issue_date' => $this->unixToDate($p['date_add'] ?? $p['issue_date'] ?? null),
            'sell_date' => $this->unixToDate($p['date_sell'] ?? $p['sell_date'] ?? null),
            'payment_date' => $this->unixToDate($p['date_pay_to'] ?? $p['date_payment'] ?? null),
            'total_netto' => (float) ($p['total_price_netto'] ?? 0),
            'total_brutto' => (float) ($p['total_price_brutto'] ?? 0),
            'currency' => $p['currency'] ?? null,
            'raw_payload' => $p,
        ];
    }

    /**
     * Buduje "nr_full". BL getInvoices zwraca gotowe pole `number` (np. "37/5/2026/BSP").
     * Fallback: składa z numeru + miesiąca + roku + serii.
     *
     * @param  array<string,mixed>  $p
     */
    private function buildNrFull(array $p): ?string
    {
        // BL daje gotowy pełny numer w polu `number`
        if (! empty($p['number'])) {
            return (string) $p['number'];
        }
        if (! empty($p['nr_full'])) {
            return (string) $p['nr_full'];
        }

        $nr = $p['sub_id'] ?? $p['nr'] ?? null;
        if ($nr === null) {
            return null;
        }

        $seriesName = $this->resolveSeriesName((int) ($p['series_id'] ?? 0));
        $month = $p['month'] ?? null;
        $year = $p['year'] ?? null;

        // Standardowy format BL: "nr/M/RRRR/SERIA"
        if ($month && $year && $seriesName) {
            return sprintf('%d/%d/%d/%s', $nr, $month, $year, $seriesName);
        }
        if ($seriesName) {
            return sprintf('%s/%s', $nr, $seriesName);
        }
        return (string) $nr;
    }

    private function resolveSeriesName(int $seriesId): ?string
    {
        if ($seriesId <= 0) {
            return null;
        }
        if ($this->seriesCache === null) {
            try {
                $list = $this->client->getSeries();
                $this->seriesCache = [];
                foreach ($list as $s) {
                    $id = (int) ($s['id'] ?? 0);
                    if ($id > 0) {
                        $this->seriesCache[$id] = (string) ($s['name'] ?? $s['format'] ?? '');
                    }
                }
            } catch (\Throwable) {
                $this->seriesCache = [];
            }
        }
        return $this->seriesCache[$seriesId] ?? null;
    }

    private function unixToDate(mixed $unix): ?Carbon
    {
        if (! $unix) {
            return null;
        }
        return Carbon::createFromTimestamp((int) $unix);
    }
}
