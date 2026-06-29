<?php

namespace App\Console\Commands;

use App\Translations\TranslationsProcessor;
use Illuminate\Console\Command;

class ScanAndSaveTranslationsCommand extends Command
{
    public $signature = 'crafter:scan-translations';

    public $description = 'Scan translations';

    public function __construct(private TranslationsProcessor $processor)
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->processor->scanTranslations();
    }
}
