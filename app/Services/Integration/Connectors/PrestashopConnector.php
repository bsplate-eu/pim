<?php

namespace App\Services\Integration\Connectors;

class PrestashopConnector extends AbstractHttpConnector
{
    protected function getConnectorFileConfigKey(): string
    {
        return 'presta_connector_file';
    }

    protected function getUpdateConnectorRouteName(): string
    {
        return 'update-connector.presta';
    }

    public function getRootCategoryId(): int
    {
        return 2;
    }

    public function getLanguageCodes(): array
    {
        try {
            $response = $this->request('checkConnected');
            $langs = $response['data']['languages'] ?? [];
            if (!empty($langs)) {
                return array_column($langs, 'iso_code');
            }
            return [$response['data']['store_lang'] ?? 'en'];
        } catch (\Throwable) {
            return ['en'];
        }
    }
}
