<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();         // e.g. "user_invitation"
            $table->string('name');                  // human-readable name (PL)
            $table->string('subject');
            $table->longText('body_html');           // WYSIWYG/HTML body with {{ variable }} placeholders
            $table->json('variables')->nullable();   // [{ "key":"user_full_name", "label":"Pełne imię" }, ...]
            $table->string('lang', 5)->default('pl');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_email');
            $table->string('subject')->nullable();
            $table->string('template_key')->nullable()->index();
            $table->string('status', 16)->index(); // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
        });

        // ---- permissions (Spatie) ----
        $permissions = [
            'crafter.mail.view',
            'crafter.mail.edit',
            'crafter.mail.templates.edit',
            'crafter.mail.logs.view',
        ];
        $now = Carbon::now();

        $adminRoleId = DB::table('roles')
            ->where('guard_name', 'crafter')
            ->where('name', 'Administrator')
            ->value('id');

        foreach ($permissions as $name) {
            $existing = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', 'crafter')
                ->value('id');

            $permissionId = $existing ?: DB::table('permissions')->insertGetId([
                'name' => $name,
                'guard_name' => 'crafter',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($adminRoleId && ! DB::table('role_has_permissions')
                    ->where('permission_id', $permissionId)
                    ->where('role_id', $adminRoleId)
                    ->exists()
            ) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id' => $adminRoleId,
                ]);
            }
        }

        // ---- seed default "user_invitation" template ----
        if (! DB::table('mail_templates')->where('key', 'user_invitation')->exists()) {
            DB::table('mail_templates')->insert([
                'key' => 'user_invitation',
                'name' => 'Zaproszenie użytkownika do panelu',
                'subject' => 'Zaproszenie do panelu {{ app_name }}',
                'body_html' => <<<HTML
<p>Cześć!</p>
<p>Użytkownik <strong>{{ user_full_name }}</strong> zaprosił Cię do panelu <strong>{{ app_name }}</strong>.</p>
<p>Kliknij w poniższy link, aby utworzyć konto i ustawić hasło:</p>
<p><a href="{{ invitation_url }}">{{ invitation_url }}</a></p>
<p>Jeśli nie spodziewasz się tego maila, po prostu go zignoruj.</p>
HTML,
                'variables' => json_encode([
                    ['key' => 'app_name',        'label' => 'Nazwa aplikacji'],
                    ['key' => 'user_full_name',  'label' => 'Imię i nazwisko zapraszającego'],
                    ['key' => 'email',           'label' => 'Adres e-mail zaproszonego'],
                    ['key' => 'invitation_url',  'label' => 'Link aktywacyjny'],
                ]),
                'lang' => 'pl',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
        Schema::dropIfExists('mail_templates');

        DB::table('permissions')
            ->where('guard_name', 'crafter')
            ->whereIn('name', [
                'crafter.mail.view',
                'crafter.mail.edit',
                'crafter.mail.templates.edit',
                'crafter.mail.logs.view',
            ])->delete();
    }
};
