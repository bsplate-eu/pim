<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_sender_rules', function (Blueprint $table) {
            // Słowo-klucz z tematu dla reguł-wykluczeń (puste = reguła ogólna, bez warunku tematu).
            $table->string('subject_contains', 190)->default('')->after('from_email');
            // Jedna domena może mieć wiele reguł (ogólna + wykluczenia z różnymi słowami),
            // więc zdejmujemy unikat z samego from_email. Unikalność (from_email+subject_contains)
            // egzekwujemy na poziomie aplikacji (updateOrCreate) — bez indeksu, by uniknąć limitu długości na starszym MySQL.
            $table->dropUnique(['from_email']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_sender_rules', function (Blueprint $table) {
            $table->dropColumn('subject_contains');
            $table->unique('from_email');
        });
    }
};
