<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('mail_catalogs')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index('parent_id');
        });

        Schema::table('mail_messages', function (Blueprint $table) {
            $table->foreignId('catalog_id')->nullable()->after('category_id')
                ->constrained('mail_catalogs')->nullOnDelete();
            $table->index('catalog_id');
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_id');
        });

        Schema::dropIfExists('mail_catalogs');
    }
};
