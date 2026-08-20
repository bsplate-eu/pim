<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Obrazki wklejone w treść maila (HTML) odwołują się do załączników przez `cid:<Content-ID>`.
 * Zapisujemy Content-ID załącznika, żeby zmapować `cid:` → załącznik i podmienić w treści na
 * URL pobrania (obraz serwowany z lokalnego cache = `storage_path`, dociągany raz z IMAP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->string('content_id')->nullable()->after('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('mail_attachments', function (Blueprint $table) {
            $table->dropColumn('content_id');
        });
    }
};
