<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class BaselinkerService
{
    protected $client;
    protected $token;
    protected $apiUrl = 'https://api.baselinker.com/connector.php';
    protected $requestLimit = 100; // limit 100 zapytań na minutę

    public function __construct()
    {
//        $this->token = config('services.baselinker.token');
        $this->token = '5011020-5022476-VBD9QTVVVIFDQVAS8R71X47IRQ8I6UOLR2AIA458M2YH49XRTDSEBQP8J70VFA3P';
        $this->client = new Client();
    }

    /**
     * Wykonuje zapytanie do API BaseLinker
     *
     * @param string $method Nazwa metody API
     * @param array $parameters Parametry zapytania
     * @return array Odpowiedź z API
     * @throws \Exception
     */
    public function request(string $method, array $parameters = []): array
    {
        try {
            $response = $this->client->post($this->apiUrl, [
                'headers' => [
                    'X-BLToken' => $this->token,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'method' => $method,
                    'parameters' => json_encode($parameters),
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['status']) && $result['status'] === 'ERROR') {
                throw new \Exception("Błąd BaseLinker API: " . ($result['error_message'] ?? 'Nieznany błąd'));
            }

            return $result;
        } catch (GuzzleException $e) {
            Log::error('BaseLinker API Error: ' . $e->getMessage());
            throw new \Exception('Błąd komunikacji z BaseLinker API: ' . $e->getMessage());
        }
    }

    /**
     * Pobiera zamówienia z BaseLinker
     *
     * @param array $params Parametry filtrowania zamówień
     * @return array Lista zamówień
     * @throws \Exception
     */
    public function getOrders(array $params = []): array
    {
        return $this->request('getOrders', $params);
    }

    /**
     * Pobiera listę statusów zamówień
     *
     * @return array Lista statusów
     * @throws \Exception
     */
    public function getOrderStatusList(): array
    {
        return $this->request('getOrderStatusList');
    }

    public function getOrderSources(): array
    {
        return $this->request('getOrderSources');
    }


}
