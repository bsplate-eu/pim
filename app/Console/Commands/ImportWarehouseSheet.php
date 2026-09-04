<?php

namespace App\Console\Commands;

use App\Models\WarehouseLog;
use App\Models\WarehouseSheetRow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Wciaga arkusz inwentury (XLSX) do tabeli `warehouse_sheet_rows`.
 *
 * Import jest PODMIANA calej zakladki, nie doklejaniem: arkusz jest zrodlem
 * prawdy, wiec kod, ktory z niego zniknal, ma zniknac i tutaj. Cala operacja
 * idzie w transakcji, zeby nieudany import nie zostawil polowy stanu.
 *
 * Ukladu kolumn nie zgadujemy — jest wpisany na sztywno, bo naglowki w tym
 * arkuszu sa rozjechane (F opisane jako "mondelo !", a "il." czwartej pary
 * wyladowalo w J) i czytanie ich zrobiloby wiecej szkody niz pozytku.
 */
class ImportWarehouseSheet extends Command
{
    protected $signature = 'warehouse:import-sheet
                            {file : Sciezka do pliku .xlsx}
                            {--tab= : Nazwa zakladki (domyslnie 2026 - inwentura)}
                            {--dry-run : Policz i pokaz, ale nie zapisuj}';

    protected $description = 'Import arkusza inwentury do tabeli Magazyn - Tabela';

    /**
     * Pary Miejsce/ilosc: cztery opisane w naglowku i dwie nieopisane (M/N, O/P),
     * z ktorych korzysta jeden wiersz. Litery, nie indeksy — latwiej sprawdzic
     * z arkuszem otwartym obok.
     */
    private const PAIRS = [
        1 => ['B', 'C'],
        2 => ['D', 'E'],
        3 => ['F', 'G'],
        4 => ['H', 'I'],
        5 => ['M', 'N'],
        6 => ['O', 'P'],
    ];

    private const COL_CODE = 'A';
    private const COL_STEEL_TEAM = 'K';
    private const COL_UWAGI = 'L';
    private const COL_WAGA = 'R';

    /** Pojedyncza komorka poza siatka (AD61 = "tak") — dopisujemy do uwag, zeby nie zginela. */
    private const COL_STRAY = 'AD';

    public function handle(): int
    {
        $file = $this->argument('file');
        $tab = $this->option('tab') ?: WarehouseSheetRow::DEFAULT_SHEET;

        if (! is_file($file)) {
            $this->error("Nie ma takiego pliku: $file");

            return self::FAILURE;
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$tab]);
        $sheet = $reader->load($file)->getSheetByName($tab);

        if ($sheet === null) {
            $this->error("Skoroszyt nie ma zakladki: $tab");

            return self::FAILURE;
        }

        [$rows, $skipped] = $this->readRows($sheet, $tab);

        if ($rows === []) {
            $this->error('Zakladka jest pusta — nic nie zapisuje.');

            return self::FAILURE;
        }

        $this->summary($rows, $skipped, $tab);

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: nic nie zapisano.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $tab) {
            WarehouseSheetRow::where('sheet', $tab)->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                WarehouseSheetRow::insert($chunk);
            }
        });

        // Import jest podmiana calej zakladki, wiec musi zostawic slad w dzienniku —
        // inaczej „skad sie wzielo 40 sztuk" po tygodniu nie ma odpowiedzi.
        WarehouseLog::write(
            'import',
            'sheet.import',
            sprintf(
                'Import arkusza „%s”: %d kodów, %d szt. (plik %s)',
                $tab,
                count($rows),
                array_sum(array_column($rows, 'quantity_total')),
                basename($file),
            ),
            ['meta' => ['plik' => $file, 'kodow' => count($rows)]],
            actor: 'konsola',
        );

        $this->info('Zapisano ' . count($rows) . " wierszy do zakladki: $tab");

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function readRows(Worksheet $sheet, string $tab): array
    {
        $now = now();
        $out = [];
        $skipped = [];
        $seen = [];

        // Wiersz 1 to naglowek; dane zaczynaja sie od 2.
        for ($rowNo = 2; $rowNo <= $sheet->getHighestDataRow(); $rowNo++) {
            $code = $this->text($sheet, self::COL_CODE, $rowNo);

            if ($code === null) {
                continue;
            }

            // Arkusz konczy sie wierszem podsumowania — to nie jest kod.
            if (mb_strtolower($code) === 'suma:') {
                continue;
            }

            // Unikalnosc jest w bazie; gdyby kiedys w arkuszu pojawil sie duplikat,
            // lepiej powiedziec to glosno niz wywrocic caly import na kluczu.
            if (isset($seen[$code])) {
                $skipped[] = "wiersz $rowNo: kod $code juz byl w wierszu {$seen[$code]}";

                continue;
            }

            $seen[$code] = $rowNo;

            $row = [
                'sheet' => $tab,
                'row_no' => $rowNo,
                'product_code' => $code,
                'steel_team' => $this->text($sheet, self::COL_STEEL_TEAM, $rowNo),
                'uwagi' => $this->text($sheet, self::COL_UWAGI, $rowNo),
                // Kolumna P nosi naglowek WYMIAR, ale trzyma ilosc szostej pary
                // (wiersz 567), wiec wymiaru nie ma skad wziac — zostaje pusty.
                'wymiar' => null,
                'waga' => $this->text($sheet, self::COL_WAGA, $rowNo),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $total = 0;

            foreach (self::PAIRS as $i => [$placeCol, $qtyCol]) {
                $row["place_$i"] = $this->text($sheet, $placeCol, $rowNo);
                $row["qty_$i"] = $this->number($sheet, $qtyCol, $rowNo);
                $total += (int) $row["qty_$i"];
            }

            $row['quantity_total'] = $total;

            $stray = $this->text($sheet, self::COL_STRAY, $rowNo);

            if ($stray !== null) {
                $col = self::COL_STRAY;
                $row['uwagi'] = trim(($row['uwagi'] ?? '') . " [kol. $col: $stray]");
            }

            $out[] = $row;
        }

        return [$out, $skipped];
    }

    /**
     * Tekst z komorki. Sam przecinek (kolumna L w trzech wierszach) to slad po
     * czyims klikniecu, nie tresc — traktujemy jak pusto.
     */
    private function text(Worksheet $sheet, string $column, int $row): ?string
    {
        $value = $sheet->getCell($column . $row)->getValue();

        if ($value === null) {
            return null;
        }

        // Miejsca bywaja liczbami ("15", "27") — Excel odda je jako float.
        if (is_float($value) && $value == (int) $value) {
            $value = (int) $value;
        }

        $value = trim((string) $value);

        return ($value === '' || $value === ',') ? null : $value;
    }

    /**
     * Ilosc. NULL to pole puste, 0 to policzone zero — te dwie rzeczy nie moga
     * sie zlac, bo na liscie znacza co innego.
     */
    private function number(Worksheet $sheet, string $column, int $row): ?int
    {
        $value = $sheet->getCell($column . $row)->getValue();

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $skipped
     */
    private function summary(array $rows, array $skipped, string $tab): void
    {
        $total = array_sum(array_column($rows, 'quantity_total'));
        $zero = count(array_filter($rows, fn ($r) => $r['quantity_total'] === 0));
        $notes = count(array_filter($rows, fn ($r) => $r['steel_team'] !== null || $r['uwagi'] !== null));
        $multi = count(array_filter($rows, fn ($r) => $r['place_2'] !== null));

        $this->line("Zakladka:          $tab");
        $this->line('Kodow:             ' . count($rows));
        $this->line("Sztuk lacznie:     $total");
        $this->line("Kodow ze stanem 0: $zero");
        $this->line("W 2+ miejscach:    $multi");
        $this->line("Z uwagami:         $notes");

        foreach ($skipped as $line) {
            $this->warn("POMINIETE — $line");
        }
    }
}
