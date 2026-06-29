<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 16)->default('#9ca3af');
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::table('mail_messages', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('folder_id')
                ->constrained('mail_categories')->nullOnDelete();
            $table->string('categorized_by', 16)->nullable()->after('category_id'); // ai|manual|rule
            $table->index('category_id');
        });

        $now = now();
        $defaults = [
            ['Klienci', '#2563eb'],
            ['Zamówienia', '#16a34a'],
            ['Faktury i płatności', '#9333ea'],
            ['Reklamacje', '#dc2626'],
            ['Dostawcy', '#ca8a04'],
            ['Marketing / Newslettery', '#0891b2'],
            ['Wewnętrzne', '#64748b'],
            ['Inne', '#9ca3af'],
        ];
        $rows = [];
        foreach ($defaults as $i => [$name, $color]) {
            $rows[] = [
                'name'       => $name,
                'color'      => $color,
                'is_system'  => true,
                'sort'       => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('mail_categories')->insert($rows);
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('categorized_by');
        });

        Schema::dropIfExists('mail_categories');
    }
};
