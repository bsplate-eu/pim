<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela ustawień planera (singleton — jeden wiersz).
        Schema::create('cost_planner_settings', function (Blueprint $table) {
            $table->id();
            $table->json('cost_names')->nullable();   // ["Krystian faktura", "ZUS", ...]
            $table->json('statuses')->nullable();     // [{name, color}]
            $table->json('categories')->nullable();   // [{name, color}]
            $table->json('types')->nullable();        // [{name, color}]
            $table->json('currencies')->nullable();   // ["PLN", "EUR"]
            $table->timestamps();
        });

        // Zmiany w items:
        Schema::table('cost_planner_items', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('type');
            $table->dropColumn(['invoice_path', 'invoice_name', 'formula_amount']);
        });

        // Konwersja ENUM status → VARCHAR (pozwala na user-defined nazwy statusów).
        DB::statement("ALTER TABLE cost_planner_items MODIFY status VARCHAR(64) NOT NULL DEFAULT 'Do zapłaty'");

        // Seed domyślnych słowników.
        DB::table('cost_planner_settings')->insert([
            'cost_names' => json_encode([], JSON_UNESCAPED_UNICODE),
            'statuses'   => json_encode([
                ['name' => 'Zapłacone',  'color' => 'green'],
                ['name' => 'Do zapłaty', 'color' => 'red'],
            ], JSON_UNESCAPED_UNICODE),
            'categories' => json_encode([
                ['name' => 'Wynagrodzenia', 'color' => 'orange'],
                ['name' => 'Operacyjne',    'color' => 'green'],
                ['name' => 'Software',      'color' => 'blue'],
                ['name' => 'Zadłużenie',    'color' => 'red'],
            ], JSON_UNESCAPED_UNICODE),
            'types' => json_encode([
                ['name' => 'Stałe',   'color' => 'orange'],
                ['name' => 'Zmienne', 'color' => 'blue'],
            ], JSON_UNESCAPED_UNICODE),
            'currencies' => json_encode(['PLN', 'EUR', 'USD', 'GBP'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('cost_planner_items', function (Blueprint $table) {
            $table->string('invoice_path')->nullable();
            $table->string('invoice_name')->nullable();
            $table->decimal('formula_amount', 12, 2)->nullable();
            $table->dropColumn('invoice_number');
        });

        DB::statement("ALTER TABLE cost_planner_items MODIFY status ENUM('paid','unpaid') NOT NULL DEFAULT 'unpaid'");

        Schema::dropIfExists('cost_planner_settings');
    }
};
