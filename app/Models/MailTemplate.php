<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailTemplate extends Model
{
    protected $fillable = [
        'key',
        'name',
        'subject',
        'body_html',
        'variables',
        'lang',
        'is_active',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Render subject + body by substituting {{ variable }} placeholders.
     *
     * @param  array<string,string|int|null>  $data
     * @return array{subject:string, html:string}
     */
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
