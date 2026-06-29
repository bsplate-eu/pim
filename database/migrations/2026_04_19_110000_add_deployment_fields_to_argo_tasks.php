<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('argo_tasks', function (Blueprint $table) {
            $table->string('deployment_status', 32)->nullable()->after('priority')->index();
            $table->boolean('edycja_admin')->default(false)->after('deployment_status');
        });
    }

    public function down(): void
    {
        Schema::table('argo_tasks', function (Blueprint $table) {
            $table->dropIndex(['deployment_status']);
            $table->dropColumn(['deployment_status', 'edycja_admin']);
        });
    }
};
