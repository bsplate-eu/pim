<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argo Mail — wykluczenia z grupowania (wątkowania).
 * Maile pasujące do reguły (nadawca/@domena + opcjonalny fragment tytułu) NIE są zwijane
 * w jeden wątek — każdy stoi osobno. Sens: zamówienia z Allegro/Amazon (jeden adres,
 * podobny temat) zlepiały się w jedną rozmowę; teraz każde zamówienie = osobny wiersz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_thread_excludes', function (Blueprint $table) {
            $table->id();
            $table->string('from_email');                  // adres lub @domena (lowercase)
            $table->string('subject_contains')->default(''); // '' = każdy temat tego nadawcy
            $table->timestamps();

            $table->unique(['from_email', 'subject_contains']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_thread_excludes');
    }
};
