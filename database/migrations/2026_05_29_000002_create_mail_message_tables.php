<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->string('name');                 // nazwa wyświetlana, np. INBOX
            $table->string('path');                 // ścieżka IMAP
            $table->string('delimiter', 8)->nullable();
            $table->unsignedBigInteger('uid_validity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0); // high-water mark synchronizacji
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'path']);
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained('mail_folders')->cascadeOnDelete();
            $table->unsignedBigInteger('uid');       // IMAP UID (unikatowy w folderze)
            $table->string('message_id', 512)->nullable();
            $table->text('subject')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->timestamp('date')->nullable();
            $table->text('snippet')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->unsignedInteger('size')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_flagged')->default(false);
            $table->string('in_reply_to', 512)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'folder_id', 'uid']);
            $table->index(['account_id', 'date']);
            $table->index('is_read');
            $table->index('from_email');
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->unsignedInteger('part_index')->default(0); // pozycja do pobrania na żądanie z IMAP
            $table->string('filename');
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_attachments');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_folders');
    }
};
