<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OAuth user-token (Authorization Code + refresh) dla Sell/Trading API — pobieranie WŁASNYCH ofert
 * i zmiana cen. Różni się od client_credentials (Browse, monitoring konkurencji), który zostaje bez zmian.
 * refresh_token szyfrowany w modelu (Crypt), access-token trzymany w Cache (jak token aplikacyjny).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->text('oauth_refresh_token')->nullable()->after('client_secret');       // szyfrowany
            $table->timestamp('oauth_refresh_expires_at')->nullable()->after('oauth_refresh_token');
            $table->string('ru_name')->nullable()->after('oauth_refresh_expires_at');       // RuName (redirect) z eBay Developer
            $table->text('oauth_scopes')->nullable()->after('ru_name');                     // przyznane scope'y
            $table->string('ebay_user_id')->nullable()->after('oauth_scopes');              // login sprzedawcy, który autoryzował
            $table->timestamp('oauth_connected_at')->nullable()->after('ebay_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('scrap_ebay_settings', function (Blueprint $table) {
            $table->dropColumn([
                'oauth_refresh_token', 'oauth_refresh_expires_at', 'ru_name',
                'oauth_scopes', 'ebay_user_id', 'oauth_connected_at',
            ]);
        });
    }
};
