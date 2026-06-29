<?php

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $table = 'mail_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'to_recipients'   => 'array',
        'cc_recipients'   => 'array',
        'date'            => 'datetime',
        'has_attachments' => 'boolean',
        'is_read'         => 'boolean',
        'is_flagged'      => 'boolean',
        'is_trashed'      => 'boolean',
        'trashed_at'      => 'datetime',
        'is_sent'         => 'boolean',
        'is_spam'         => 'boolean',
        'size'            => 'integer',
        'uid'             => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class, 'catalog_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\AdminUser::class, 'assigned_admin_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'message_id');
    }

    /**
     * Znormalizowany temat do wątkowania: zdjęte prefiksy odpowiedzi/przekazania
     * (Re/Odp/Fwd/Fw/PD/WG/Aw/Sv…), lowercase, pojedyncze spacje.
     */
    public static function normalizeSubject(?string $subject): string
    {
        $s = mb_strtolower(trim((string) $subject));
        $s = preg_replace('/^(?:\s*(?:re|odp|fwd|fw|pd|wg|aw|sv|vs|tr|wd)\s*:\s*)+/iu', '', $s);
        $s = preg_replace('/\s+/u', ' ', (string) $s);

        return trim((string) $s);
    }

    /**
     * Klucz wątku = sha1(znormalizowany temat | adres drugiej strony, lowercase).
     * Te same osoba+temat (też nasza odpowiedź „Re: …") → ten sam klucz → jedna konwersacja.
     */
    public static function threadKeyFor(?string $subject, ?string $counterpartEmail): string
    {
        return sha1(self::normalizeSubject($subject).'|'.mb_strtolower(trim((string) $counterpartEmail)));
    }

    /** Adres „drugiej strony": dla wysłanych = pierwszy odbiorca, dla przychodzących = nadawca. */
    public function counterpartEmail(): string
    {
        if ($this->is_sent) {
            return mb_strtolower(trim((string) ($this->to_recipients[0]['email'] ?? '')));
        }

        return mb_strtolower(trim((string) $this->from_email));
    }

    /**
     * Snippet (≤200 zn.) podglądu maila. Preferuje plain text; z HTML usuwa <style>/<script>/komentarze,
     * żeby CSS/JS z treści maila nie wyciekał do podglądu (np. „a { text-decoration:none } .ReadMsgBody{…}").
     */
    public static function makeSnippet(?string $bodyText, ?string $bodyHtml): ?string
    {
        $text = trim((string) $bodyText);
        if ($text === '' && (string) $bodyHtml !== '') {
            $html = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', ' ', (string) $bodyHtml) ?? (string) $bodyHtml;
            $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
            $html = preg_replace('#<[^>]+>#', ' ', $html) ?? $html; // tagi → spacja (nie sklejaj słów z sąsiednich bloków)
            $text = trim((string) html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, 200);
    }
}
