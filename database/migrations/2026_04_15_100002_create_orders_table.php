<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Identyfikacja
            $table->unsignedBigInteger('baselinker_order_id')->unique();
            $table->string('shop_order_id', 50)->nullable();
            $table->string('external_order_id', 50)->nullable();
            $table->string('order_source', 30)->nullable();
            $table->unsignedBigInteger('order_source_id')->nullable();
            $table->string('order_source_info', 200)->nullable();
            $table->unsignedBigInteger('order_status_id')->nullable();

            // Daty
            $table->timestamp('date_add')->nullable();
            $table->timestamp('date_confirmed')->nullable();
            $table->timestamp('date_in_status')->nullable();
            $table->boolean('confirmed')->default(false);

            // Klient
            $table->string('email', 150)->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('user_login', 100)->nullable();
            $table->text('user_comments')->nullable();
            $table->text('admin_comments')->nullable();

            // Płatność
            $table->char('currency', 3)->nullable();
            $table->string('payment_method', 100)->nullable();
            $table->boolean('payment_method_cod')->default(false);
            $table->decimal('payment_done', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0); // wyliczone = delivery + sum(products)

            // Dostawa
            $table->unsignedBigInteger('delivery_method_id')->nullable();
            $table->string('delivery_method', 100)->nullable();
            $table->decimal('delivery_price', 14, 2)->default(0);
            $table->string('delivery_package_module', 30)->nullable();
            $table->string('delivery_package_nr', 50)->nullable();
            $table->string('delivery_fullname', 150)->nullable();
            $table->string('delivery_company', 150)->nullable();
            $table->string('delivery_address', 200)->nullable();
            $table->string('delivery_postcode', 30)->nullable();
            $table->string('delivery_city', 100)->nullable();
            $table->string('delivery_state', 50)->nullable();
            $table->string('delivery_country', 50)->nullable();
            $table->char('delivery_country_code', 2)->nullable();
            $table->string('delivery_point_id', 50)->nullable();
            $table->string('delivery_point_name', 150)->nullable();
            $table->string('delivery_point_address', 200)->nullable();
            $table->string('delivery_point_postcode', 30)->nullable();
            $table->string('delivery_point_city', 100)->nullable();

            // Faktura
            $table->string('invoice_fullname', 200)->nullable();
            $table->string('invoice_company', 200)->nullable();
            $table->string('invoice_nip', 30)->nullable();
            $table->string('invoice_address', 250)->nullable();
            $table->string('invoice_postcode', 30)->nullable();
            $table->string('invoice_city', 100)->nullable();
            $table->string('invoice_state', 50)->nullable();
            $table->string('invoice_country', 50)->nullable();
            $table->char('invoice_country_code', 2)->nullable();
            $table->boolean('want_invoice')->default(false);

            // Extras
            $table->string('extra_field_1', 100)->nullable();
            $table->string('extra_field_2', 100)->nullable();
            $table->json('custom_extra_fields')->nullable();
            $table->unsignedTinyInteger('pick_state')->default(0);
            $table->unsignedTinyInteger('pack_state')->default(0);
            $table->unsignedTinyInteger('star')->default(0);
            $table->json('commission')->nullable();
            $table->string('order_page', 200)->nullable();

            // Meta
            $table->json('raw_payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('updated_from_api_at')->nullable();

            $table->timestamps();

            $table->index('order_status_id');
            $table->index('date_add');
            $table->index('date_confirmed');
            $table->index('email');
            $table->index('order_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
