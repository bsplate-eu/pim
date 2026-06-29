<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argo Connect → Integracje → KSeF.
 * Poświadczenia integracji KSeF per firma (Pareto / BSP).
 * auth_token przechowywany zaszyfrowany (Crypt) — wzorzec jak client_secret w scrap_ebay_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ksef_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company')->unique();        // 'pareto' | 'bsp'
            $table->string('label')->default('');        // nazwa wyświetlana
            $table->string('nip')->nullable();           // NIP firmy
            $table->string('environment', 16)->default('test'); // 'test' | 'prod'
            $table->text('auth_token')->nullable();      // token autoryzacyjny KSeF — zaszyfrowany
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_settings');
    }
};
