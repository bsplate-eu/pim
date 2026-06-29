<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dodaje webhook_secret dla integracji webhookowych (Baselinker, Selly).
 *
 * Cel: wzmocnienie K2 (auth na md5("password_{id}") - przewidywalne).
 * Po migracji: BaselinkerController akceptuje OBA mechanizmy:
 *  - Nowy: ?key=hmac_sha256("baselinker:{id}", $webhook_secret) - bezpieczne (32-byte random)
 *  - Stary (legacy): ?key=md5("password_{id}") - z deprecation warning w logach
 *
 * Po migracji URL'a w panelu Baselinker (przez user PIM Local) - usunac legacy w przyszlej migracji.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('key');
        });

        // Auto-fill dla istniejacych integracji (random 32 bytes, Laravel encrypted)
        DB::table('integrations')->whereNull('webhook_secret')->orderBy('id')->each(function ($row) {
            $secret = Str::random(64);
            DB::table('integrations')
                ->where('id', $row->id)
                ->update(['webhook_secret' => Crypt::encryptString($secret)]);
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
