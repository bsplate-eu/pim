<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolejne znaczniki produkcyjne: etapy 1-3, „bez wspornikow" i „projekty gotowe".
 * Dzialaja tak samo jak „Projekt" i „Team Steel" — checkbox per kod, niezalezne
 * od siebie (kod moze byc zaznaczony w kilku naraz).
 */
return new class extends Migration
{
    private array $columns = [
        'etap_1',
        'etap_2',
        'etap_3',
        'bez_wspornikow',
        'projekty_gotowe',
    ];

    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $after = 'team_steel';

            foreach ($this->columns as $column) {
                $table->boolean($column)->default(false)->index()->after($after);
                $after = $column;
            }
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->dropColumn($this->columns);
        });
    }
};
