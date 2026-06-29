<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Argo Mail — zmiana nazwy systemowego katalogu na wysłane: „SEND" → „Wysłane".
 * Idempotentna: gdy katalogu „SEND" nie ma (świeża instalacja), nic nie robi.
 * Podkatalogi (per skrzynka) i przypisane maile zostają — zmienia się tylko nazwa roota.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('mail_catalogs')
            ->whereNull('parent_id')
            ->where('name', 'SEND')
            ->update(['name' => 'Wysłane']);
    }

    public function down(): void
    {
        DB::table('mail_catalogs')
            ->whereNull('parent_id')
            ->where('name', 'Wysłane')
            ->update(['name' => 'SEND']);
    }
};
