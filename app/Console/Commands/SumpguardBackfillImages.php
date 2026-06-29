<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Sources\SumpguardSource;
use Illuminate\Console\Command;

class SumpguardBackfillImages extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sumpguard:backfill-images {--items : Wypisuj każdy przetworzony produkt} {--bad-names : Skasuj i pobierz na nowo zdjęcia z wadliwą (podwójną) nazwą rozszerzenia}';

    /**
     * @var string
     */
    protected $description = 'Dociąga brakujące zdjęcia dla produktów Sumpguard (które nie mają żadnych mediów)';

    public function handle(): int
    {
        $source = Source::where('service_class', 'SumpguardSource')->first();

        if (!$source) {
            $this->error('Nie znaleziono źródła Sumpguard (service_class=SumpguardSource).');
            return self::FAILURE;
        }

        $verbose = (bool) $this->option('items');
        $logger  = $verbose ? fn (string $msg) => $this->line($msg) : null;
        $service = new SumpguardSource($source);

        if ($this->option('bad-names')) {
            $this->info('Sumpguard: kasowanie i ponowne pobranie zdjęć z wadliwą nazwą...');
            $stats = $service->redownloadBadlyNamedImages($logger);
            $this->newLine();
            $this->info(sprintf(
                'Gotowe. Produktów: %d | skasowano plików: %d | pobrano: %d | pominięto: %d',
                $stats['products'],
                $stats['cleared'],
                $stats['attached'],
                $stats['skipped'],
            ));

            return self::SUCCESS;
        }

        $this->info('Backfill zdjęć Sumpguard — start...');
        $stats = $service->backfillMissingImages($logger);
        $this->newLine();
        $this->info(sprintf(
            'Gotowe. Bez mediów: %d | przetworzono: %d | podpięto plików: %d | pominięto: %d',
            $stats['products_without_media'],
            $stats['processed'],
            $stats['attached'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
