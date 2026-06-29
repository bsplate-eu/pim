<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_spam_senders', function (Blueprint $table) {
            $table->id();
            $table->string('from_email'); // przechowywany małymi literami
            $table->timestamps();

            $table->unique('from_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_spam_senders');
    }
};
