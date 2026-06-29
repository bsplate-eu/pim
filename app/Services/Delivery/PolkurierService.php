<?php

namespace App\Services\Delivery;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class PolkurierService
{
    private Client $client;
    private string $apiUrl;
    private array $auth;

    public function __construct(string $login, string $token, bool $sandbox = false)
    {
        $this->apiUrl = $sandbox
            ? 'https://api-sandbox.polkurier.pl'
            : 'https://api.polkurier.pl';

        $this->auth = [
            'login' => $login,
            'token' => $token
        ];

        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }


    /**
     * Pobieranie szczegółów zamówienia
     */
    public function getOrderDetails(string $orderNo): array
    {
        return $this->sendRequest('get_orders', ['orderno' => $orderNo]);
    }

    /**
     * Pobieranie listy zamówień
     */
    public function getOrders(int $page = 1, int $pageSize = 100, string $status = null): array
    {
        $data = [
            'page' => $page,
            'pagesize' => $pageSize
        ];

        if ($status) {
            $data['status'] = $status;
        }

        return $this->sendRequest('get_orders', $data);
    }

    /**
     * Wysyłanie żądania do API Polkurier
     */
    private function sendRequest(string $method, array $data): array
    {
        $payload = [
            'authorization' => $this->auth,
            'apimethod' => $method,
            'data' => $data
        ];

        try {
            $response = $this->client->post('', [
                'json' => $payload
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            if ($responseBody['status'] === 'error') {
                throw new \Exception($responseBody['response'] ?? 'Nieznany błąd API Polkurier');
            }

            return $responseBody['response'] ?? [];

        } catch (GuzzleException $e) {
            throw new \Exception('Błąd komunikacji z API Polkurier: ' . $e->getMessage());
        }
    }
}
