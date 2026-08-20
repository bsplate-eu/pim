<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Załączniki maili WYSŁANYCH nie istnieją na serwerze IMAP (folder lokalny „__SENT_LOCAL"),
 * więc ich treści nie da się dociągnąć leniwie jak dla odebranych. Trzymamy je lokalnie na dysku;
 * `storage_path` = ścieżka na dysku `local` (NULL → treść pobierana z IMAP po part_index, jak dotąd).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->string('storage_path')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->dropColumn('storage_path');
        });
    }
};
