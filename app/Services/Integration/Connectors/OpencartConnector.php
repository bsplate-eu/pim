<?php

namespace App\Services\Integration\Connectors;

use Throwable;

/**
 * Connector PUSH dla OpenCart 3.x.
 *
 * Cała logika transportu (JSON-RPC POST {api_key, method, params} -> {url}/{connector_file},
 * retry, parsing, błędy) jest w AbstractHttpConnector. Tu tylko specyfika OpenCart.
 *
 * Plik connectora po stronie sklepu: pim-connector-opencart.php (w roocie sklepu OC).
 * Metody connectora OC odpowiadają kontraktowi wołanemu przez klasę bazową:
 *   checkConnected, getCategoryTree, addCategory, updateCategory, importProducts,
 *   getLanguages, getTaxClasses, getAnalytics.
 */
class OpencartConnector extends AbstractHttpConnector
{
    protected function getConnectorFileConfigKey(): string
    {
        return 'opencart_connector_file';
    }

    protected function getUpdateConnectorRouteName(): string
    {
        return 'update-connector.opencart';
    }

    public function getRootCategoryId(): int
    {
        // OpenCart: kategorie najwyższego poziomu mają parent_id = 0.
        return 0;
    }

    public function getLanguageCodes(): array
    {
        try {
            $response = $this->request('getLanguages');

            $codes = [];
            foreach ($response['data'] ?? [] as $lang) {
                // OpenCart zwraca kod typu 'de-de' / 'en-gb' — bierzemy część iso ('de','en').
                $raw = (string) ($lang['code'] ?? $lang['iso_code'] ?? '');
                if ($raw !== '') {
                    $codes[] = explode('-', $raw)[0];
                }
            }

            return $codes ?: ['en'];
        } catch (Throwable) {
            return ['en'];
        }
    }

    public function getTaxMapping(): array
    {
        try {
            $response = $this->request('getTaxClasses');

            $list = $response['data']['tax_classes'] ?? $response['data'] ?? [];

            $taxes = [];
            foreach ($list as $tc) {
                $rate = (int) round((float) ($tc['rate'] ?? 0));
                if ($rate > 0) {
                    $taxes[$rate] = $tc['tax_class_id'] ?? $tc['id'] ?? null;
                }
            }

            return $taxes;
        } catch (Throwable) {
            return [];
        }
    }
}
