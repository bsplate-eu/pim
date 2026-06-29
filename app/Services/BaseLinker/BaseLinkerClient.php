<?php

namespace App\Services\BaseLinker;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BaseLinkerClient
{
    private const ENDPOINT = 'https://api.baselinker.com/connector.php';
    private const TIMEOUT = 30;

    public function __construct(private readonly string $apiKey)
    {
        if (trim($apiKey) === '') {
            throw new BaseLinkerException('BaseLinker API key is empty.');
        }
    }

    /**
     * Surowe wywołanie metody API.
     *
     * @param  array<string,mixed>  $parameters
     * @return array<string,mixed>
     */
    public function call(string $method, array $parameters = []): array
    {
        $response = Http::timeout(self::TIMEOUT)
            ->withHeaders(['X-BLToken' => $this->apiKey])
            ->asForm()
            ->post(self::ENDPOINT, [
                'method' => $method,
                'parameters' => json_encode($parameters, JSON_UNESCAPED_UNICODE),
            ]);

        return $this->handleResponse($method, $response);
    }

    /**
     * Test połączenia — wołamy `getOrderStatusList` (lekka metoda).
     *
     * @return array{ok:bool, message:string, statuses_count?:int}
     */
    public function testConnection(): array
    {
        try {
            $data = $this->call('getOrderStatusList');
            $count = is_array($data['statuses'] ?? null) ? count($data['statuses']) : 0;
            return [
                'ok' => true,
                'message' => 'Połączenie działa poprawnie.',
                'statuses_count' => $count,
            ];
        } catch (BaseLinkerException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Pobiera zamówienia z konkretnej daty.
     *
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function getOrders(array $filters = []): array
    {
        $data = $this->call('getOrders', $filters);
        return $data['orders'] ?? [];
    }

    /**
     * Lista statusów zamówień.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getOrderStatusList(): array
    {
        $data = $this->call('getOrderStatusList');
        return $data['statuses'] ?? [];
    }

    /**
     * Źródła zamówień.
     *
     * @return array<string,mixed>
     */
    public function getOrderSources(): array
    {
        $data = $this->call('getOrderSources');
        return $data['sources'] ?? [];
    }

    /**
     * Faktury (i korekty) z BaseLinker. Filtry: order_id, date_from, id_from, series_id, get_external_invoices.
     *
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function getInvoices(array $filters = []): array
    {
        $data = $this->call('getInvoices', $filters);
        return $data['invoices'] ?? [];
    }

    /**
     * Serie numeracji (np. "FV", "BSP").
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSeries(): array
    {
        $data = $this->call('getSeries');
        return $data['series'] ?? [];
    }

    /**
     * Dziennik zdarzeń (do inkrementalnego syncu).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getJournalList(int $lastLogId = 0, array $logsTypes = []): array
    {
        $params = ['last_log_id' => $lastLogId];
        if (! empty($logsTypes)) {
            $params['logs_types'] = $logsTypes;
        }
        $data = $this->call('getJournalList', $params);
        return $data['logs'] ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    private function handleResponse(string $method, Response $response): array
    {
        if (! $response->successful()) {
            $msg = "BaseLinker HTTP {$response->status()} dla metody {$method}";
            Log::error($msg, ['body' => $response->body()]);
            throw new BaseLinkerException($msg);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new BaseLinkerException("BaseLinker zwrócił niepoprawny JSON dla {$method}");
        }

        $status = $json['status'] ?? null;
        if ($status !== 'SUCCESS') {
            $errorCode = $json['error_code'] ?? 'UNKNOWN';
            $errorMessage = $json['error_message'] ?? 'Nieznany błąd BaseLinker';
            throw new BaseLinkerException("[{$errorCode}] {$errorMessage}");
        }

        return $json;
    }
}
