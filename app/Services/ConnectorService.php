<?php

namespace App\Services;


use App\Models\Integration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ConnectorService
{

    /** @var Integration $prestashop */
    protected $prestashop;

    /**
     * Connector constructor
     *
     * @param Integration $prestashop
     */
    public function __construct(Integration $integration)
    {
        $this->prestashop = $integration;
    }

    /**
     * Connected
     *
     * @return bool
     */
    public function connected(): bool
    {
        try {
            $this->request('checkConnected');
            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Get category tree
     *
     * @return array
     * @throws GuzzleException
     */
    public function getCategoryTree(): array
    {
        $response = $this->request('getCategoryTree');
        return $response['data'];
    }

    /**
     * Get category tree
     *
     * @return array
     * @throws GuzzleException
     */
    public function getCategories(): Collection
    {
        $response = $this->request('getCategories');
        return collect($response['data']);
    }


    public function getTaxes(): Collection
    {
        $response = $this->request('getTaxes');
        return collect($response['data']['taxes'])->keyBy('rate');
    }


    /**
     * Move category
     *
     * @param string $categoryId
     * @param string $categoryParentId
     * @return void
     * @throws GuzzleException
     */
    public function moveCategory(string $categoryId, string $categoryParentId): void
    {
        $this->request('moveCategory', [
            'category_id' => $categoryId,
            'category_parent_id' => $categoryParentId,
        ]);
    }

    /**
     * Add category
     *
     * @param $categoryParentId
     * @param $name
     * @return string
     * @throws GuzzleException
     */
    public function addCategory($categoryParentId, $name): array
    {
        $response = $this->request('addCategory', [
            'category_parent_id' => $categoryParentId,
            'category_name' => $name,
        ]);

        return $response['data'];
    }

    public function updateConnector(): array
    {
        $response = $this->request('updateConnector', [
            'checksum_connector' => md5_file(storage_path('app/pim-connector.php')),
            'url_connector' => route('update-connector'),
        ]);
        return $response;
    }

    /**
     * Edit category name
     *
     * @param $categoryId
     * @param $name
     * @return mixed
     * @throws GuzzleException
     */
    public function editCategoryName($categoryId, $name)
    {
        $this->request('editCategoryName', [
            'category_id' => $categoryId,
            'category_name' => $name,
        ]);
    }

    /**
     * Update products
     *
     * @param array $products
     * @return mixed
     * @throws GuzzleException
     */
    public function updateProducts(array $products)
    {
        $this->request('updateProducts', [
            'products' => $products,
        ]);
    }

    /**
     * Delete products
     *
     * @param array $products
     * @return mixed
     * @throws GuzzleException
     */
    public function deleteProducts(array $products)
    {
        $this->request('deleteProducts', [
            'products' => $products,
        ]);
    }

    /**
     * Request
     *
     * @param string $method
     * @param array $parameters
     * @return array
     * @throws GuzzleException
     */
    private function request(string $method, array $parameters = []): array
    {
        $parameters['api_key'] = $this->prestashop->key;
        $client = new Client(['verify' => false, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 30]);
        $url = $this->prestashop->url;
        if (!Str::endsWith($url, '/')) {
            $url .= '/';
        }
        $url .= 'pim-connector.php';
        $parameters['checksum_connector'] = md5_file(storage_path('app/pim-connector.php'));
        $parameters['url_connector'] = route('update-connector');
        $parameters['method'] = $method;

        try {
            $response = $client->post($url, [
                RequestOptions::JSON => $parameters,
                'timeout' => 30,
            ]);

            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true, 512, JSON_UNESCAPED_UNICODE);


            if (isset($data['status']) && $data['status'] === 'error') {
                throw new \Exception($data['message']);
            }


            return $data;


        } catch (Throwable $exception) {
            throw new \Exception($exception->getMessage(), $exception->getCode());
        }
    }

    public function getFeatures()
    {
        $response = $this->request('getFeatures');
        return collect($response['data']);
    }

    public function addFeature($name): int
    {
        $response = $this->request('addFeature', [
            'name' => $name,
        ]);

        return (int)$response['data']['feature_id'];
    }

    public function addFeatureValue($featureId, $name): int
    {
        $response = $this->request('addFeatureValue', [
            'feature_id' => $featureId,
            'name' => $name,
        ]);

        return (int)$response['data']['feature_value_id'];
    }

    public function addProductFeatures(int $product_id, array $features)
    {
        $response = $this->request('addProductFeatures', [
            'product_id' => $product_id,
            'features' => $features,
        ]);

        return $response['data'];
    }

}
