<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cztery booleany etapow -> jedno `stage_id`.
 *
 * Etapy i tak wykluczaly sie wzajemnie, wiec cztery kolumny opisywaly jeden
 * stan. Przy etapach zakladanych z Ustawien kolumna-na-etap konczy sie tym, ze
 * kazdy nowy etap wymaga migracji — stad klucz obcy do slownika.
 *
 * Znaczniki spoza linii etapow (has_project, team_steel, bez_wspornikow,
 * projekty_gotowe) zostaja nietkniete.
 */
return new class extends Migration
{
    /** @var array<string,string> stara kolumna => nazwa etapu w slowniku */
    private array $map = [
        'etap_1' => 'Etap 1',
        'etap_2' => 'Etap 2',
        'etap_3' => 'Etap 3',
        'gotowe' => 'Gotowe',
    ];

    public function up(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->after('product_code')
                ->constrained('production_stages')->nullOnDelete();
        });

        // Przenosimy to, co bylo pozaznaczane. Kolejnosc petli = kolejnosc etapow,
        // wiec przy (teoretycznie niemozliwym) podwojnym zaznaczeniu wygrywa dalszy.
        foreach ($this->map as $column => $stageName) {
            $stageId = DB::table('production_stages')->where('name', $stageName)->value('id');

            if ($stageId !== null) {
                DB::table('production_items')->where($column, true)->update(['stage_id' => $stageId]);
            }
        }

        Schema::table('production_items', function (Blueprint $table) {
            $table->dropColumn(array_keys($this->map));
        });
    }

    public function down(): void
    {
        Schema::table('production_items', function (Blueprint $table) {
            foreach (array_keys($this->map) as $column) {
                $table->boolean($column)->default(false)->index();
            }
        });

        foreach ($this->map as $column => $stageName) {
            $stageId = DB::table('production_stages')->where('name', $stageName)->value('id');

            if ($stageId !== null) {
                DB::table('production_items')->where('stage_id', $stageId)->update([$column => true]);
            }
        }

        Schema::table('production_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stage_id');
        });
    }
};
