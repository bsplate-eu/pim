<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Identyfikatory
            $table->string('email', 150)->nullable();
            $table->string('phone', 100)->nullable();       // znormalizowany (tylko cyfry + ew. prefix)
            $table->string('phone_raw', 100)->nullable();
            $table->string('user_login', 100)->nullable();  // login BL / marketplace
            $table->unsignedBigInteger('crm_client_id')->nullable();

            // Dane osobowe / firmowe
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('full_name', 200)->nullable();   // do wyszukiwania
            $table->string('company', 200)->nullable();
            $table->string('nip', 30)->nullable();

            // Ostatni znany adres dostawy
            $table->string('address', 200)->nullable();
            $table->string('postcode', 30)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 50)->nullable();
            $table->string('country', 50)->nullable();
            $table->char('country_code', 2)->nullable();

            // Statystyki / metadane
            $table->string('primary_source', 30)->nullable();
            $table->json('sources')->nullable();            // array wszystkich źródeł
            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();

            $table->timestamps();

            $table->index('email');
            $table->index('phone');
            $table->index('country_code');
            $table->index('last_order_at');
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
