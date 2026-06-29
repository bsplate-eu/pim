<?php

namespace App\Translations\Export;

use App\Translations\LanguageLine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use function Crafter\Translations\Export\mb_strtoupper;

class TranslationsExport implements FromCollection, WithMapping, WithHeadings
{
    /**
     * @var Collection
     */
    private $exportLanguages;

    public function __construct($request)
    {
        $this->exportLanguages = collect($request->exportLanguages);
    }

    /**
     * @return Collection
     */
    public function collection()
    {
        return LanguageLine::all();
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        $headings = [
            'Group',
            'Default',
            'Created at',
        ];

        $this->exportLanguages->each(static function ($language) use (&$headings) {
            $headings[] = \mb_strtoupper($language);
        });

        return $headings;
    }

    /**
     * @param LanguageLine $languageLine
     * @return array
     */
    public function map($languageLine): array
    {
        $map = [
            $languageLine->group,
            $languageLine->key,
            $languageLine->created_at,
        ];

        $this->exportLanguages->each(function ($language) use (&$map, $languageLine) {
            array_push($map, ___($languageLine->group, $languageLine->key, [], $language));
        });

        return $map;
    }
}
