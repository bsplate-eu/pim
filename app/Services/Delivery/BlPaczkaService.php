<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;

class BlPaczkaService
{
    protected $apiUrl = 'https://api.blpaczka.com/api';
    protected $login;
    protected $apiKey;

    public function __construct(string $login, string $apiKey)
    {
        $this->login = $login;
        $this->apiKey = $apiKey;
    }

    public function getOrders(int $page = 1): array
    {
        $response = Http::post("{$this->apiUrl}/getOrders.json", [
            'auth' => [
                'login' => $this->login,
                'api_key' => $this->apiKey
            ],
            'Order' => [
                'page' => $page
            ]
        ]);

        if (!$response->successful()) {
            throw new \Exception('Błąd podczas pobierania zamówień: ' . $response->body());
        }

        $data = $response->json();

        if (!isset($data['success']) || !$data['success']) {
            throw new \Exception('Błąd API: ' . ($data['message'] ?? 'Nieznany błąd'));
        }

        return $data;

//        return $this->extractWaybillsAndPrices($data['data'] ?? []);
    }

    protected function extractWaybillsAndPrices(array $orders): Collection
    {
        $result = collect();

        foreach ($orders as $orderData) {
            if (isset($orderData['Order']) && is_array($orderData['Order'])) {
                foreach ($orderData['Order'] as $order) {
                    if (isset($order['waybill_no']) && isset($order['price'])) {
                        $result->put($order['waybill_no'], $order['price']);
                    }
                }
            }
        }

        return $result;
    }
}
