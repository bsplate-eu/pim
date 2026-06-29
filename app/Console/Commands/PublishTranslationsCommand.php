<?php

namespace App\Console\Commands;

use App\Translations\TranslationsProcessor;
use Illuminate\Console\Command;

class PublishTranslationsCommand extends Command
{
    public $signature = 'crafter:publish-translations';

    public $description = 'Publish translations';

    public function __construct(private TranslationsProcessor $processor)
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->processor->publishTranslations();
    }
}
