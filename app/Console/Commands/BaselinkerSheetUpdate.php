<?php

namespace App\Console\Commands;

use App\Services\BaselinkerService;
use App\Services\Delivery\BlPaczkaService;
use App\Services\Delivery\PolkurierService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Revolution\Google\Sheets\Facades\Sheets;

class BaselinkerSheetUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'baselinker-sheet:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    protected string $spreadsheet_id = '1LFeIQLqGKmLtdSCO4NEhxmfbgzzEWNGOd7r1IsqFNjo';

    private array $trackings = [];
    /**
     * @var array|mixed
     */
    private array $currencies = [];
    private array $sheet_list = [];
    private array $commissions = [
        'allegro' => 0.12,
        'amazon' => 0.19,
        'arena' => 0.11,
        'etsy' => 0.16,
    ];


    /**
     * Execute the console command.
     */
    public function handle()
    {

//        $date_confirmed_from = Carbon::now()->subMonths()->startOfMonth();
//        $date_confirmed_from = Carbon::now()->subDays(7)->startOfDay();
        $startOfCurrentMonth = Carbon::now()->startOfMonth()->startOfDay();
        $sevenDaysAgo = Carbon::now()->subDays(7)->startOfDay();

        $date_confirmed_from = $startOfCurrentMonth->greaterThan($sevenDaysAgo)
            ? $startOfCurrentMonth
            : $sevenDaysAgo;


        $this->getPolkurierData();
        $this->getBlPaczkaData();
        $this->getCurrencies();
        $this->syncSheets($date_confirmed_from);
        $this->getOrders($date_confirmed_from);

    }

    private function syncSheets(Carbon $date_confirmed_from)
    {

        $has_new_sheets = false;
        $now = Carbon::now();
        $this->sheet_list = Sheets::spreadsheet($this->spreadsheet_id)->sheetList();

        $period = CarbonPeriod::create(
            $date_confirmed_from->copy()->startOfMonth(),
            '1 month',
            $now->copy()->startOfMonth()
        );

        foreach ($period as $date) {
            $sheet_name = $date->format('Y-m');

            if (!in_array($sheet_name, $this->sheet_list)) {
                Sheets::spreadsheet($this->spreadsheet_id)->addSheet($sheet_name);
                $has_new_sheets = true;
            }
        }

        if ($has_new_sheets) {
            $this->sheet_list = Sheets::spreadsheet($this->spreadsheet_id)->sheetList();
        }

    }

    private function getBlPaczkaData()
    {
        $orders = Cache::remember('blpaczka_orders', 14400, function () {
            $service = new BlPaczkaService('info@argotrade24.pl', "aub5mcpkie6h38x7jd0ugb");
            $result = collect();
            for ($i = 1; $i <= 3; $i++) {

                try {
                    $response = $service->getOrders($i);

                    $orders = collect($response['data'])->map(function ($orderData) {
                        $order = $orderData['Order'] ?? [];
                        if (isset($order['waybill_no']) && isset($order['price'])) {
                            return [
                                'number' => $order['waybill_no'],
                                'price' => (float)$order['price']
                            ];
                        }
                    })->filter();
                    $result = $result->merge($orders);

                }catch (\Exception $e) {
                    Log::error('BlPaczka: ' . $e->getMessage());
                }

            }

            return $result;

        });

        $orders->each(function ($order) {
            $this->trackings[$order['number']] = $order['price'] ?? 0;
        });
    }

    private function getPolkurierData()
    {
        $orders = Cache::remember('polkurier_orders', 14400, function () {
            $service = new PolkurierService(84513, '62f4e45ef9188d78885b137f32a4ff45');
            $orders = $service->getOrders();

            return collect($orders['result'])->map(function ($order) {
                $number = collect($order['waybills'])->where('is_default', true)->first()['number'] ?? null;
                if (empty($number)) return null;

                return [
                    'number' => $number,
                    'price' => $order['price_gross']
                ];
            })->filter();
        });

        $orders->each(function ($order) {
            $this->trackings[$order['number']] = $order['price'] ?? 0;
        });
    }

    private function getCurrencies()
    {
        $this->currencies = Cache::remember('baselinker_currencies', 14400, function () {
            $response = Http::get('https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/pln.json')->json('pln');
            return array_change_key_case($response, CASE_UPPER);
        });
    }

    private function getTotalPrice($order)
    {
        if ($order['payment_done'] > 0) return $order['payment_done'];

        $total = 0;

        foreach ($order['products'] as $item) {
            $total += $item['price_brutto'] * $item['quantity'];
        }

        return $total;
    }

    private function getDeliveryPrice($order)
    {

        if (!empty($order['delivery_package_nr']) && isset($this->trackings[$order['delivery_package_nr']])) {
            return $this->trackings[$order['delivery_package_nr']];
        }

        return 0;

    }


    private function getOrders(Carbon $date_confirmed_from)
    {
        $baselinkerService = new BaselinkerService();
        $sources = $baselinkerService->getOrderSources()['sources'] ?? [];
        $excluded_sources = [0, 5011393, 5012310, 5012456];
        $allowed_statuses = [151451, 151452, 234291, 137090, 137091, 230898, 151453];
        $orders = $baselinkerService->getOrders(['date_confirmed_from' => $date_confirmed_from->unix()])['orders'] ?? [];

        collect($orders)->whereNotIn('order_source_id', $excluded_sources)
            ->whereIn('order_status_id', $allowed_statuses)
            ->groupBy(fn($order) => Carbon::parse($order['date_confirmed'])->format('Y-m'))
            ->each(function ($group, $title) use ($sources) {
                $orders = $group->map(function ($order) use ($sources) {
                    $total = $this->getTotalPrice($order);
                    $total_pln = round($total / $this->currencies[$order['currency']], 2);
                    $delivery_price = $this->getDeliveryPrice($order);
                    $goods = round($total_pln - (rand(40, 50) * $total_pln / 100), 2);

                    return [
                        'order_id' => (string)$order['order_id'],
                        'source' => ucfirst($order['order_source']) . ' - ' . $sources[$order['order_source']][$order['order_source_id']],
                        'date_confirmed' => Carbon::parse($order['date_confirmed'])->format('Y-m-d'),
                        'invoice_fullname' => $order['invoice_fullname'] ?? '-',
                        'delivery_country' => $order['delivery_country'] ?? '-',
                        'link' => "https://panel-f.baselinker.com/orders.php#order:{$order['order_id']}",
                        'products' => collect($order['products'])->implode('sku', ', '),
                        'payment_method' => $order['payment_method'],
                        'delivery_package_module' => $order['delivery_package_module'],
                        'delivery_package_nr' => $order['delivery_package_nr'],
                        'total' => str_replace('.', ',', $total),
                        'currency' => $order['currency'],
                        'total_pln' => str_replace('.', ',', $total_pln),
                        'commission' => str_replace('.', ',', $this->calculateCommission($order, $total_pln)),
                        'goods' => str_replace('.', ',', $goods),
                        'delivery_price' => str_replace('.', ',', $delivery_price),
                        'gross_profit' => '=M{index}-O{index}-P{index}',
                        'net_profit' => '=Q{index}/1,23',
                        'margin_1' => '=Q{index}*100/M{index}',
                        'margin_2' => '=Q{index}*100/O{index}',
                        'cod' => $order['payment_method_cod'] == 1 ? str_replace('.', ',', $total_pln) : 0,
                    ];
                });

                $this->appendData($title, $orders);
            });
    }

    private function calculateCommission($order, $total)
    {
        if (isset($this->commissions[$order['order_source']])) {
            $commission = $this->commissions[$order['order_source']];
            return round($total * $commission, 2);
        }
        return 0;
    }

    private function getPercentage($total, $number)
    {
        if ($total > 0) {
            return round(($number * 100) / $total, 2);
        } else {
            return 0;
        }
    }

    private function getSpreadsheetData($spreadsheet)
    {
        try {
            $rows = $spreadsheet->all();

            if (empty($rows)) {
                return collect();
            }

            $header = array_shift($rows);

            if (empty($rows)) {
                return collect();
            }

            $maxColumns = max(array_map('count', $rows));

            if (count($header) < $maxColumns) {
                $header = array_pad($header, $maxColumns, null);
            } else if (count($header) > $maxColumns) {
                $header = array_slice($header, 0, $maxColumns);
            }

            return Sheets::collection(header: $header, rows: $rows)
                ->filter(fn($order) => isset($order['order_id']) && is_numeric($order['order_id']))
                ->keyBy('order_id');

        } catch (\Exception $e) {
            Log::error('Error getting spreadsheet data: ' . $e->getMessage());
            return collect();
        }
    }



    private function appendData($title, $orders)
    {

        $sheet_id = array_search($title, $this->sheet_list);

        $spreadsheet = Sheets::spreadsheet($this->spreadsheet_id)->sheetById($sheet_id);
        $headers = [array_keys($orders[0])];
        $spreadsheetData = $this->getSpreadsheetData($spreadsheet);


        // Sprawdź czy arkusz ma nagłówki
        $hasHeaders = false;
        $headerRow = $spreadsheet->range('A1:Z1')->all();
        if (!empty($headerRow) && !empty($headerRow[0])) {
            $hasHeaders = true;
        }

        // Pobierz aktualną liczbę wierszy z danymi
        $currentRowCount = $spreadsheetData->count();

        // Jeśli nie ma nagłówków, dodaj je
        if (!$hasHeaders) {
            $spreadsheet->range('A1')->append($headers);
        }

        // Podziel zamówienia na nowe i do aktualizacji
        $newOrders = collect();
        $ordersToUpdate = collect();

        foreach ($orders as $order) {

            if (!$spreadsheetData->has($order['order_id'])) {
                $newOrders->push($order);
            } else {
                // Aktualizuj tylko kolumny związane z dostawą
                $ordersToUpdate->push($order);
            }
        }

        // Dodaj nowe zamówienia
        if ($newOrders->count() > 0) {


            // Znajdź pierwszy pusty wiersz (po istniejących danych)
            $actualStartRow = $hasHeaders ? $currentRowCount + 2 : 2;

            $formattedOrders = [];
            foreach ($newOrders as $i => $order) {
                $actualIndex = $actualStartRow + $i;
                $row = [];
                foreach ($headers[0] as $header) {
                    // Podstaw rzeczywisty indeks wiersza w formułach
                    $value = $order[$header];
                    if (in_array($header, ['gross_profit', 'net_profit', 'margin_1', 'margin_2'])) {
                        $value = str_replace('{index}', $actualIndex, $value);
                    }
                    $row[] = $value ?? '';
                }
                $formattedOrders[] = $row;
            }

            // Dodaj nowe zamówienia
            $spreadsheet->range("A$actualStartRow")->append($formattedOrders, 'USER_ENTERED');

            $this->addSummaryRow($spreadsheet, $actualIndex + 1, $headers[0]);
        }


    }

    /**
     * Aktualizuje dane dostawy dla istniejących zamówień
     */
    private function updateDeliveryData($spreadsheet, $spreadsheetData, $ordersToUpdate, $headers)
    {
        $columnsToUpdate = ['delivery_package_nr', 'delivery_price', 'cod'];

        foreach ($ordersToUpdate as $order) {
            $existingData = $spreadsheetData->get($order['order_id']);
            if (!$existingData) continue;

            // Znajdź wiersz zamówienia w arkuszu
            $rowNumber = null;
            $allData = $spreadsheet->all();
            foreach ($allData as $index => $row) {
                if (isset($row[0]) && $row[0] == $order['order_id']) {
                    $rowNumber = $index + 1; // +1 bo indeksowanie w Sheets zaczyna się od 1
                    break;
                }
            }

            if (!$rowNumber) continue;

            // Aktualizuj tylko określone kolumny
            foreach ($columnsToUpdate as $column) {
                $columnIndex = array_search($column, $headers);
                if ($columnIndex !== false) {
                    $columnLetter = $this->getColumnLetter($columnIndex + 1);
                    $value = $order[$column] ?? '';
                    $spreadsheet->range("{$columnLetter}{$rowNumber}")->update([[$value]], 'USER_ENTERED');
                }
            }
        }
    }


    private function addSummaryRow($spreadsheet, $summaryRowNumber, $headers)
    {
        // Kolumny do podsumowania
        $sumColumns = ['total_pln', 'commission', 'goods', 'delivery_price', 'gross_profit', 'net_profit', 'cod'];
        $avgColumns = ['margin_1', 'margin_2'];

        // Znajdź indeksy kolumn do podsumowania
        $columnIndices = [];
        foreach ($sumColumns as $column) {
            $index = array_search($column, $headers);
            if ($index !== false) {
                $columnIndices[$column] = $index;
            }
        }

        // Znajdź indeksy kolumn do średniej
        $avgColumnIndices = [];
        foreach ($avgColumns as $column) {
            $index = array_search($column, $headers);
            if ($index !== false) {
                $avgColumnIndices[$column] = $index;
            }
        }

        // Przygotuj wiersz podsumowania
        $summaryRow = array_fill(0, count($headers), "");
//        $summaryRow[array_search('currency', $headers)] = "Suma:";

        // Dodaj formuły SUM dla odpowiednich kolumn
        foreach ($columnIndices as $column => $index) {
            $columnLetter = $this->getColumnLetter($index + 1);
            $formula = "=SUM({$columnLetter}2:{$columnLetter}" . ($summaryRowNumber - 1) . ")";
            $summaryRow[$index] = $formula;
        }

        // Dodaj formuły AVERAGE dla kolumn margin_1 i margin_2
        foreach ($avgColumnIndices as $column => $index) {
            $columnLetter = $this->getColumnLetter($index + 1);
            $formula = "=AVERAGE({$columnLetter}2:{$columnLetter}" . ($summaryRowNumber - 1) . ")";
            $summaryRow[$index] = $formula;
        }

        // Wyczyść obszar przed dodaniem wiersza sumy
        $spreadsheet->range("A{$summaryRowNumber}:Z{$summaryRowNumber}")->clear();
        $spreadsheet->range("A{$summaryRowNumber}")->update([$summaryRow], 'USER_ENTERED');
    }


    private function getColumnLetter($columnIndex)
    {
        $columnLetter = '';
        while ($columnIndex > 0) {
            $modulo = ($columnIndex - 1) % 26;
            $columnLetter = chr(65 + $modulo) . $columnLetter;
            $columnIndex = (int)(($columnIndex - $modulo) / 26);
        }
        return $columnLetter;
    }

}
