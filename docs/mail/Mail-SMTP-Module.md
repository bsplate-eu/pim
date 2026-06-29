# MAIL SMTP — pełna dokumentacja modułu

Konfiguracja SMTP w bazie + edycja szablonów maili w UI + log wysłanych maili. Cztery niezależne kawałki:

1. **SMTP settings** (Spatie Settings → DB → override `.env` w runtime)
2. **Mail templates** (CRUD w UI, render `{{ variable }}` placeholders)
3. **Mail logs** (auto-zapis przez `MessageSent` listener)
4. **Mailables** (`InvitationUserMail` + `SumpguardEmail`)

---

## 1. SMTP settings

### Architektura

```
config/app.php
    └─ MailConfigServiceProvider (boot)
        ├─ czyta MailSettings z DB (Spatie Settings)
        ├─ if (override_env) → config(['mail.mailers.smtp.*' => ...])
        └─ Event::listen(MessageSent::class, LogSentMail::class)
```

`MailConfigServiceProvider::boot()` (try/catch — silent fail jeśli tabela `settings` jeszcze nie istnieje, np. fresh install przed `migrate`).

### Klasa Settings

`app/Settings/MailSettings.php` — Spatie LaravelSettings:

```php
class MailSettings extends Settings
{
    public bool   $override_env;
    public string $host;
    public int    $port;
    public string $username;
    public string $password;       // encrypted via Crypt::encryptString
    public string $encryption;     // "tls" | "ssl" | ""
    public string $from_address;
    public string $from_name;

    public static function group(): string { return 'mail'; }

    public function passwordPlaintext(): string {
        if ($this->password === '') return '';
        try { return Crypt::decryptString($this->password); }
        catch (\Throwable $e) { return ''; }
    }

    public function setPasswordFromPlaintext(string $plain): void {
        $this->password = $plain === '' ? '' : Crypt::encryptString($plain);
    }
}
```

**Hasło zapisane w DB jest zaszyfrowane** przez `Crypt::encryptString` (APP_KEY). Pusty string = „użyj `.env`" (fallback). Frontend dostaje **`has_password`** boolean, nigdy raw value.

### Migracja

`database/migrations/2026_04_20_100000_create_mail_settings.php` — używa `SettingsMigration` (Spatie):

```php
$this->migrator->add('mail.override_env', false);
$this->migrator->add('mail.host',         (string) env('MAIL_HOST', ''));
$this->migrator->add('mail.port',         (int) env('MAIL_PORT', 587));
$this->migrator->add('mail.username',     (string) env('MAIL_USERNAME', ''));
$this->migrator->add('mail.password',     '');                                    // encrypted later
$this->migrator->add('mail.encryption',   (string) env('MAIL_ENCRYPTION', 'tls'));
$this->migrator->add('mail.from_address', (string) env('MAIL_FROM_ADDRESS', ''));
$this->migrator->add('mail.from_name',    (string) env('MAIL_FROM_NAME', ''));
```

Defaulty kopiowane z `.env` przy initial migracji — żeby stan DB == stan env w momencie wdrożenia.

### Provider override

`MailConfigServiceProvider` w `boot()`:

```php
if ($s->override_env) {
    config([
        'mail.default'                  => 'smtp',
        'mail.mailers.smtp.host'        => $s->host,
        'mail.mailers.smtp.port'        => $s->port,
        'mail.mailers.smtp.username'    => $s->username,
        'mail.mailers.smtp.password'    => $s->passwordPlaintext(),
        'mail.mailers.smtp.encryption'  => $s->encryption !== '' ? $s->encryption : null,
        'mail.from.address'             => $s->from_address,
        'mail.from.name'                => $s->from_name,
    ]);
}
```

`override_env=false` → `.env` rządzi. `override_env=true` → DB rządzi (i `.env` jest ignorowany).

Rejestracja w `config/app.php` (sekcja `providers`):
```php
App\Providers\MailConfigServiceProvider::class,
```

### Frontend — Smtp.vue

`resources/js/crafter/Pages/Mail/Smtp.vue`. Pola:
- toggle „Override .env"
- host / port / username / password (input type=password — nigdy nie pokazuje hasła, tylko `has_password` indicator)
- encryption (`tls` / `ssl` / `none`)
- from_address / from_name
- przycisk **Test** → `POST mail/smtp/test` z testowym mailem na `auth.user.email`

Pole „env_fallback" pokazuje aktualne wartości z `.env` jako placeholders w trybie `override_env=false`.

### Routes

```php
Route::get ('mail/smtp',      [MailController::class, 'smtp'])      ->name('mail.smtp');
Route::post('mail/smtp',      [MailController::class, 'smtpUpdate'])->name('mail.smtp.update');
Route::post('mail/smtp/test', [MailController::class, 'smtpTest'])  ->name('mail.smtp.test');
```

### Uprawnienia

```
crafter.mail.view              # podgląd ustawień + logi
crafter.mail.edit              # edycja SMTP
crafter.mail.templates.edit    # edycja szablonów
crafter.mail.logs.view         # log wysłanych maili
```

Migracja `2026_04_20_100100_create_mail_templates_and_logs.php` automatycznie dodaje permissions i przypisuje do roli `Administrator` (guard `crafter`).

---

## 2. Mail templates

### Tabela `mail_templates`

| Kolumna | Typ | Opis |
|---|---|---|
| `key` | string unique | np. `user_invitation`, `password_reset` |
| `name` | string | nazwa human-readable PL |
| `subject` | string | z placeholderami `{{ var }}` |
| `body_html` | longtext | WYSIWYG HTML, też z placeholderami |
| `variables` | json | `[{"key":"user_full_name","label":"Pełne imię"}, ...]` |
| `lang` | string(5) | `pl` (default) |
| `is_active` | bool | `true` |

### Model `MailTemplate`

`app/Models/MailTemplate.php`:

```php
class MailTemplate extends Model
{
    protected $fillable = ['key', 'name', 'subject', 'body_html', 'variables', 'lang', 'is_active'];
    protected $casts    = ['variables' => 'array', 'is_active' => 'boolean'];

    public function render(array $data): array
    {
        return [
            'subject' => $this->substitute($this->subject, $data),
            'html'    => $this->substitute($this->body_html, $data),
        ];
    }

    private function substitute(string $text, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/',
            static fn ($m) => (string) ($data[$m[1]] ?? ''),
            $text
        );
    }

    public static function findByKey(string $key): ?self
    {
        return static::query()->where('key', $key)->where('is_active', true)->first();
    }
}
```

`render()` podstawia `{{ user_full_name }}` → wartość z `$data['user_full_name']`. Placeholder bez wartości znika do `''`.

### Seed default template

Migracja seeduje 1 szablon `user_invitation`:

```sql
INSERT INTO mail_templates (key, name, subject, body_html, variables, lang, is_active) VALUES (
  'user_invitation',
  'Zaproszenie użytkownika do panelu',
  'Zaproszenie do panelu {{ app_name }}',
  '<p>Cześć!</p>
   <p>Użytkownik <strong>{{ user_full_name }}</strong> zaprosił Cię do panelu <strong>{{ app_name }}</strong>.</p>
   <p>Kliknij w poniższy link, aby utworzyć konto i ustawić hasło:</p>
   <p><a href="{{ invitation_url }}">{{ invitation_url }}</a></p>
   <p>Jeśli nie spodziewasz się tego maila, po prostu go zignoruj.</p>',
  '[{"key":"app_name","label":"Nazwa aplikacji"}, ...]',
  'pl', true
);
```

### Frontend — Templates.vue + TemplateEdit.vue

- `Templates.vue` — lista szablonów (key, name, lang, is_active toggle).
- `TemplateEdit.vue` — edytor (subject + WYSIWYG body), panel po prawej z listą dostępnych zmiennych (`{{ key }}` — `label`), klik wstawia placeholder.

### Routes

```php
Route::get ('mail/templates',                [MailController::class, 'templates'])     ->name('mail.templates');
Route::get ('mail/templates/{template}/edit',[MailController::class, 'templateEdit'])  ->name('mail.templates.edit');
Route::put ('mail/templates/{template}',     [MailController::class, 'templateUpdate'])->name('mail.templates.update');
```

---

## 3. Mail logs

### Tabela `mail_logs`

| Kolumna | Typ | Opis |
|---|---|---|
| `to_email` | string | adres odbiorcy |
| `subject` | string nullable | po renderze |
| `template_key` | string indexed nullable | `user_invitation` / `null` |
| `status` | string(16) indexed | `sent` / `failed` |
| `error` | text nullable | trace przy fail |
| `sent_at` | timestamp nullable | po `MessageSent` |

### Listener `LogSentMail`

`app/Listeners/LogSentMail.php` — łapie `Illuminate\Mail\Events\MessageSent`:

```php
public function handle(MessageSent $event): void
{
    try {
        $message = $event->message;
        $to = collect($message->getTo())->map(fn ($a) => $a->getAddress())->implode(', ');
        $templateKey = null;

        if ($message->getHeaders()->has('X-Template-Key')) {
            $templateKey = $message->getHeaders()->get('X-Template-Key')->getBodyAsString();
        }

        MailLog::create([
            'to_email'     => mb_substr($to, 0, 255),
            'subject'      => $message->getSubject(),
            'template_key' => $templateKey,
            'status'       => MailLog::STATUS_SENT,
            'sent_at'      => now(),
        ]);
    } catch (\Throwable $e) {
        // Never let logging break mail sending
        Log::warning('LogSentMail failed: ' . $e->getMessage());
    }
}
```

Try/catch na całość — log błędu nie może zepsuć wysłania maila. Listener zarejestrowany w `MailConfigServiceProvider::boot()`.

### Identyfikacja szablonu via header

`Mailable` ustawia header `X-Template-Key`:

```php
public function headers(): Headers
{
    return new Headers(text: ['X-Template-Key' => self::TEMPLATE_KEY]);
}
```

Listener czyta ten header → zapisuje do `mail_logs.template_key` → w UI można filtrować logi per szablon.

### Frontend — Logs.vue

Tabela: data, to, subject, template_key, status (chip), error (jeśli failed). Paginacja, filtr po `status` i `template_key`.

### Route

```php
Route::get('mail/logs', [MailController::class, 'logs'])->name('mail.logs');
```

---

## 4. Mailables

### `InvitationUserMail` (MOD)

`app/Mail/InvitationUserMail.php` — używa nowego `MailTemplate` z fallbackiem do starego blade'a:

```php
public function __construct($data)
{
    $this->data = $data;
    $template = MailTemplate::findByKey(self::TEMPLATE_KEY);  // 'user_invitation'
    if ($template) {
        $rendered = $template->render([
            'app_name'       => config('app.name'),
            'user_full_name' => $data['userFullName'] ?? '',
            'email'          => $data['email'] ?? '',
            'invitation_url' => route('crafter.invite-user.create', $data['email']),
        ]);
        $this->renderedSubject = $rendered['subject'];
        $this->renderedHtml    = $rendered['html'];
    }
}

public function content(): Content
{
    if ($this->renderedHtml !== null) {
        return new Content(htmlString: $this->renderedHtml);
    }
    // Fallback: stary blade markdown
    return new Content(markdown: 'email.invite_user', with: [
        'userFullName' => $this->data['userFullName'] ?? '',
        'email'        => $this->data['email'] ?? '',
    ]);
}
```

Jeśli szablon w DB jest aktywny → render z DB. Jeśli usunięty/wyłączony → fallback na blade view `resources/views/email/invite_user.blade.php`.

### `SumpguardEmail` (NEW)

`app/Mail/SumpguardEmail.php` — niezwiązany z templates, prosty mailable z dwoma listami (`$diffs`, `$news`) i widokiem `email.sumpguard`. Komenda diff JSON-ów wykrywa zmiany i wysyła maila do admina (sumpguard = własny system monitoringu).

---

## 5. Pliki blade

```
resources/views/email/invite_user.blade.php   # fallback blade dla InvitationUserMail
resources/views/email/sumpguard.blade.php     # widok dla SumpguardEmail
```

---

## 6. Lista plików do skopiowania (checklist)

### NEW (wgrać 1:1)

```
[  ] app/Settings/MailSettings.php
[  ] app/Models/MailTemplate.php
[  ] app/Models/MailLog.php
[  ] app/Listeners/LogSentMail.php
[  ] app/Providers/MailConfigServiceProvider.php
[  ] app/Http/Controllers/Admin/MailController.php
[  ] app/Mail/SumpguardEmail.php
[  ] resources/js/crafter/Pages/Mail/Smtp.vue
[  ] resources/js/crafter/Pages/Mail/Templates.vue
[  ] resources/js/crafter/Pages/Mail/TemplateEdit.vue
[  ] resources/js/crafter/Pages/Mail/Logs.vue
[  ] resources/views/email/invite_user.blade.php
[  ] resources/views/email/sumpguard.blade.php
[  ] database/migrations/2026_04_20_100000_create_mail_settings.php
[  ] database/migrations/2026_04_20_100100_create_mail_templates_and_logs.php
[  ] docs/Mail-SMTP-Module.md
```

### MOD (merge, nie nadpisuj)

```
[  ] app/Mail/InvitationUserMail.php  # +TEMPLATE_KEY const, +renderedSubject/Html, +headers() X-Template-Key, +fallback
[  ] config/app.php                    # +App\Providers\MailConfigServiceProvider::class w 'providers'
[  ] routes/crafter.php                # +5 routes (mail/smtp + mail/smtp/test + mail/templates + mail/logs)
[  ] resources/js/crafter/Components/Sidebar.vue  # +link do mail (jeśli dodajesz w sidebar)
```

---

## 7. Procedura wdrożenia (skrót)

1. **Wgraj pliki** (NEW + MOD).
2. **Wymagane pakiety Composer** — sprawdź czy są:
   ```bash
   composer require spatie/laravel-settings
   composer require spatie/laravel-permission   # już jest, dla user groups
   ```
3. **Migracje**:
   ```bash
   php artisan migrate
   # ↳ tworzy mail_templates + mail_logs + 4 permissions
   #   + Spatie Settings entry "mail.*"
   ```
4. **Sprawdź provider w `config/app.php`** — czy `MailConfigServiceProvider::class` jest w array `providers`.
5. **Build frontendu**:
   ```bash
   npm run build
   ```
6. **Smoke test**:
   ```bash
   php artisan tinker --execute="
     app(App\Settings\MailSettings::class)->host;
     App\Models\MailTemplate::findByKey('user_invitation')->subject;
   "
   ```
7. **W UI**:
   - **Ustawienia → Mail SMTP** — uzupełnij host/port/from + włącz `override_env=true`.
   - Wyślij test do siebie → sprawdź `mail/logs`.
   - **Mail → Szablony** → otwórz `user_invitation` → zmień brzmienie → zapisz.
   - Zaproś usera → szablon z DB powinien zadziałać.

---

## 8. Pułapki

1. **`override_env=true` ale puste pola** — `MailConfigServiceProvider` nadpisze konfig pustymi wartościami → brak wysyłki. Walidacja w `smtpUpdate()` używa `required_if:override_env,true`.
2. **Hasło: nigdy w JSON/Inertia props** — frontend dostaje tylko `has_password: bool`. Update form: pole `password` — jeśli puste, hasło nie zmienia się (zachowuje stare).
3. **`Crypt::decryptString` po zmianie `APP_KEY`** — zaszyfrowane hasło stanie się nieczytelne. `passwordPlaintext()` ma try/catch i zwraca `''` → fallback na `.env`.
4. **Spatie Settings cache** — Spatie cache'uje wartości w obrębie requestu. `MailConfigServiceProvider` jest w `boot()`, więc zmiana w UI wymaga reloadu (i tak request kończy się przekierowaniem, więc kolejny request widzi nowe wartości).
5. **`X-Template-Key` header → `LogSentMail`** — bez headera log działa, ale `template_key` jest `null`. Wszystkie własne mailable powinny dorzucić ten header, żeby filtr po szablonie działał.
6. **Listener wyrzuca exception** — całe `handle()` jest w try/catch. Failure logu = warning w `laravel.log`, nie 500 dla wysyłającego.
7. **Migration silent fail w boot** — `MailConfigServiceProvider::boot()` ma try/catch wokół `app(MailSettings::class)`. Fresh install bez migracji uruchamia się bez 500 — fallback na `.env`.

---

_Aktualizacja: 2026-05-05 — pełny opis modułu Mail SMTP do wdrożenia na świeżym PIM._
