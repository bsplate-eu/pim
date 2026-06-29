<?php

namespace App\Jobs\Virsal;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use OpenSpout\Reader\XLSX\Reader as XLSXReader;
use OpenSpout\Writer\XLSX\Writer as XLSXWriter;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;


class ProcessFestoExcel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $inputPath;
    public string $sheetName;
    public int $chunkSize;
    public string $targetPrefix;
    public int $zIndex; // 0-based index kolumny Z

    public function __construct(
        string $sheetName = 'DATA',
        int $chunkSize = 2000,
        string $targetPrefix = 'https://virsal.pl/image/festo/',
        int $zIndex = 25 // Z = 26 kolumna => index 25
    ) {
        $this->inputPath    = storage_path('app/virsal/festo.xlsx');
        $this->sheetName    = $sheetName;
        $this->chunkSize    = $chunkSize;
        $this->targetPrefix = $targetPrefix;
        $this->zIndex       = $zIndex;
    }

    public function handle(): void
    {
        $reader = new XLSXReader();
        $reader->open($this->inputPath);

        $header = null;
        $buffer = [];
        $part   = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== $this->sheetName) {
                continue;
            }

            foreach ($sheet->getRowIterator() as $rowIdx => $row) {
                $values = $row->toArray();

                if ($rowIdx === 1) { // nagłówek
                    $header = $values;
                    continue;
                }

                // transform kolumny Z -> https://virsal.pl/image/festo/{plik}
                $values[$this->zIndex] = $this->transformZ($values[$this->zIndex] ?? null);

                $buffer[] = Row::fromValues($values);

                if (count($buffer) >= $this->chunkSize) {
                    $this->writePart(++$part, $header, $buffer);
                    $buffer = [];
                }
            }
        }

        $reader->close();

        if (!empty($buffer)) {
            $this->writePart(++$part, $header, $buffer);
        }
    }

    private function transformZ($val): ?string
    {
        if ($val === null || $val === '') {
            return $val;
        }

        $s = trim((string)$val);
        $path = parse_url($s, PHP_URL_PATH);
        $name = $path ? basename($path) : basename($s);

        return $name ? ($this->targetPrefix . $name) : $s;
    }

    private function writePart(int $part, array $header, array $rows): void
    {
        $dir = storage_path('app/virsal/festo_2');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $out = sprintf('%s/festo_out_part_%02d.xlsx', $dir, $part);

        $writer = new XLSXWriter();
        $writer->openToFile($out);

        // nagłówek
        $headerStyle = new Style();
        $headerStyle->setFontBold();

        $writer->addRow(Row::fromValues($header, $headerStyle));

        // dane
        $writer->addRows($rows);

        $writer->close();
    }
}
