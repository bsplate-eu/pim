<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poszerza kolumny integrations.key, integrations.url, integrations.sheet_id
 * z VARCHAR na TEXT, żeby pomieściły ciphertext Laravel encrypted cast
 * (~3× długości plaintext: payload JSON + IV + HMAC, base64).
 *
 * Po tej migracji należy:
 *   1) Uruchomić: php artisan integration:encrypt-secrets --dry-run
 *   2) Jeśli OK: php artisan integration:encrypt-secrets
 *   3) Dodać encrypted casts w app/Models/Integration.php
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->text('key')->change();
            $table->text('url')->change();
            $table->text('sheet_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // UWAGA: jeśli w tabeli są już ciphertexty (dłuższe niż 255)
        // rollback obetnie dane. Najpierw odszyfruj przez command.
        Schema::table('integrations', function (Blueprint $table) {
            $table->string('key', 255)->change();
            $table->string('url', 255)->change();
            $table->string('sheet_id', 255)->nullable()->change();
        });
    }
};
