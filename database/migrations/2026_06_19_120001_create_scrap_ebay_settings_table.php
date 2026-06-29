<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argo Connect → Integracje → Ebay.
 * Ustawienia integracji eBay (keyset + monitorowany sprzedawca).
 * client_secret przechowywany zaszyfrowany (Crypt), jak api_key w connect_base_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrap_ebay_settings', function (Blueprint $table) {
            $table->id();
            $table->string('label')->default('eBay');
            $table->string('client_id')->nullable();        // App ID — jawny identyfikator
            $table->text('client_secret')->nullable();       // Cert ID — zaszyfrowany
            $table->string('seller')->default('scutprotectionsrl');
            $table->string('marketplace', 16)->default('EBAY_DE');
            $table->string('keyword')->default('Unterfahrschutz'); // q (wymagane przez Browse API)
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedInteger('last_sync_count')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrap_ebay_settings');
    }
};
