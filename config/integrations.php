<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nazwy plików connectora (PIM: storage/app/*.php, sklep: root URL / plik w katalogu public)
    |--------------------------------------------------------------------------
    | Dwie osobne binaria — Presta i LiteCart nie współdzielą jednego skryptu.
    */
    // Uwaga: env('KEY', 'domyślne') w Laravelu NIE używa domyślnej wartości, gdy w .env jest KEY= (pusty string).
    'presta_connector_file' => (string) (env('PRESTA_CONNECTOR_FILE') ?: 'pim-connector-presta.php'),
    'litecart_connector_file' => (string) (env('LITECART_CONNECTOR_FILE') ?: 'pim-connector-litecart.php'),
    'opencart_connector_file' => (string) (env('OPENCART_CONNECTOR_FILE') ?: 'pim-connector-opencart.php'),

    /*
    |--------------------------------------------------------------------------
    | PrestaShop — rozmiar paczki produktów wysyłanych do connectora
    |--------------------------------------------------------------------------
    | Małe wartości (5–10) zapobiegają wieszaniu się serwera PS przy dużym imporcie.
    | Ustaw w .env: PRESTASHOP_BATCH_SIZE=10
    */
    'prestashop_batch_size' => (int) env('PRESTASHOP_BATCH_SIZE', 10),

    /*
    | LiteCart — rozmiar paczki (może być większy bo LiteCart szybciej odpowiada)
    */
    'litecart_batch_size' => (int) env('LITECART_BATCH_SIZE', 50),

    /*
    | OpenCart — rozmiar paczki (OC 3.x potrafi wieszać się przy dużych importach, więc umiarkowanie)
    */
    'opencart_batch_size' => (int) env('OPENCART_BATCH_SIZE', 20),

    /*
    |--------------------------------------------------------------------------
    | Timeout connectora (sekundy)
    |--------------------------------------------------------------------------
    */
    'connector_timeout' => (int) env('CONNECTOR_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Wymuszenie wersji IP przy połączeniu do connectora ('v4' | 'v6' | '')
    |--------------------------------------------------------------------------
    | Domyślnie 'v4'. Powód: na hostingu OVH domena sklepu (np. bsplate.fr) ma
    | rekord AAAA wskazujący na ten sam serwer, ale po IPv6 stoi tam tylko
    | DOMYŚLNY vhost (zły cert -> cURL error 60, connector 404). Poprawny
    | vhost+cert sklepu jest na IPv4. Pusty string = nie wymuszaj (auto).
    */
    'connector_ip_version' => (string) (env('INTEGRATIONS_CONNECTOR_IP') ?: 'v4'),

    /*
    |--------------------------------------------------------------------------
    | Weryfikacja certyfikatu TLS przy wywołaniach do connectorów sklepów
    |--------------------------------------------------------------------------
    | PRODUKCJA: zawsze true. Ustaw na false wyłącznie w dev/local dla
    | self-signed certów. Wyłączenie = podatność MitM (kradzież api_key).
    */
    'verify_tls' => filter_var(env('INTEGRATIONS_VERIFY_TLS', true), FILTER_VALIDATE_BOOLEAN),

];
