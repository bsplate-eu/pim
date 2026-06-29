<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Jednorazowe zaszyfrowanie istniejacych plaintext key/url/sheet_id w `integrations`.
 *
 * KONTEKST: model App\Models\Integration dostaje encrypted casts (key/url/sheet_id/webhook_secret).
 * Istniejace rekordy maja te pola jako PLAINTEXT — bez tej migracji kazdy odczyt
 * ($integration->key) rzucalby DecryptException po wdrozeniu modelu.
 *
 * Zastepuje wzmiankowana w 2026_04_15_000001 komende `integration:encrypt-secrets`,
 * ktora nie zostala dostarczona w paczce.
 *
 * MUSI byc uruchomione PO 2026_04_15_000001_widen_integrations_for_encryption (kolumny TEXT)
 * i PRZED wdrozeniem modelu Integration z encrypted castami.
 *
 * Idempotentna: wartosci juz zaszyfrowane (decrypt sie udaje) sa pomijane.
 * Szyfruje biezacym APP_KEY aplikacji.
 */
return new class extends Migration {
    private array $columns = ['key', 'url', 'sheet_id'];

    public function up(): void
    {
        DB::table('integrations')->orderBy('id')->each(function ($row) {
            $updates = [];

            foreach ($this->columns as $col) {
                $value = $row->{$col} ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                // Juz zaszyfrowane? (decrypt sie powiedzie) -> pomin
                try {
                    Crypt::decryptString($value);
                    continue;
                } catch (\Throwable $e) {
                    // plaintext -> szyfruj
                }

                $updates[$col] = Crypt::encryptString($value);
            }

            if ($updates) {
                DB::table('integrations')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // Odwracalne: deszyfruj z powrotem do plaintext (wymagane przed zwezeniem kolumn w 000001 down()).
        DB::table('integrations')->orderBy('id')->each(function ($row) {
            $updates = [];

            foreach ($this->columns as $col) {
                $value = $row->{$col} ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                try {
                    $updates[$col] = Crypt::decryptString($value);
                } catch (\Throwable $e) {
                    // juz plaintext -> pomin
                }
            }

            if ($updates) {
                DB::table('integrations')->where('id', $row->id)->update($updates);
            }
        });
    }
};
