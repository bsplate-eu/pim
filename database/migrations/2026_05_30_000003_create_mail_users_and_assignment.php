<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Użytkownicy systemu wyznaczeni do obsługi poczty (+ kolor etykiety).
        Schema::create('mail_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->unique()->constrained('admin_users')->cascadeOnDelete();
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Przypisanie maila do osoby.
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->foreignId('assigned_admin_user_id')->nullable()->after('catalog_id')
                ->constrained('admin_users')->nullOnDelete();
            $table->index('assigned_admin_user_id');
        });

        // Reguły „nadawca → osoba/katalog" (przypisanie na stałe).
        Schema::create('mail_sender_rules', function (Blueprint $table) {
            $table->id();
            $table->string('from_email')->unique();
            $table->foreignId('assigned_admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('catalog_id')->nullable()->constrained('mail_catalogs')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sender_rules');

        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_admin_user_id');
        });

        Schema::dropIfExists('mail_users');
    }
};
