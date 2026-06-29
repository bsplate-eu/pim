<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();

            $table->string('label');                 // np. "Biuro / kontakt@firma.pl"
            $table->string('email')->unique();        // adres skrzynki
            $table->string('color', 16)->nullable();  // kolor zakładki (tab)

            // IMAP (odbiór)
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 16)->nullable()->default('ssl'); // ssl|tls|starttls|null

            // SMTP (wysyłka)
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption', 16)->nullable()->default('ssl'); // ssl|tls|starttls|null

            // Uwierzytelnianie
            $table->string('username');                              // zwykle = email
            $table->string('auth_type', 16)->default('password');    // password|oauth2
            $table->text('password')->nullable();                    // App Password (szyfrowane castem)
            $table->text('oauth_token')->nullable();                 // przyszłość: OAuth2 (szyfrowane castem)

            // Synchronizacja
            $table->unsignedSmallInteger('sync_window_months')->default(6); // ile mies. wstecz pobieramy
            $table->string('sync_status', 16)->default('idle');             // idle|syncing|error
            $table->text('sync_error')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
