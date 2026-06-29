<?php

namespace App\Services\Mail;

use App\Models\Mail\Category;
use App\Models\Mail\Message;
use App\Services\Ai\AiContentRuntimeConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Auto-kategoryzacja maili przez AI (OpenAI chat completions, reuse konfiguracji PIM).
 * Klasyfikuje do JEDNEJ z istniejących kategorii; przy braku dopasowania → „Inne”.
 */
class MailAiCategorizer
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * Kategoryzuje nieskategoryzowane maile (category_id IS NULL).
     *
     * @return array{ok: bool, processed: int, categorized: int, message?: string}
     */
    public function categorizeUncategorized(int $limit = 25): array
    {
        if (! AiContentRuntimeConfig::hasOpenAiApiKeyConfigured()) {
            return ['ok' => false, 'processed' => 0, 'categorized' => 0,
                'message' => 'Brak skonfigurowanego klucza OpenAI (Narzędzia AI → Ustawienia).'];
        }

        $categories = Category::query()->orderBy('sort')->orderBy('name')->get();
        if ($categories->isEmpty()) {
            return ['ok' => false, 'processed' => 0, 'categorized' => 0, 'message' => 'Brak zdefiniowanych kategorii.'];
        }

        $messages = Message::query()
            ->whereNull('category_id')
            ->orderByDesc('date')
            ->limit($limit)
            ->get();

        $processed = 0;
        $categorized = 0;

        foreach ($messages as $message) {
            $processed++;
            try {
                $category = $this->classify($message, $categories);
                if ($category) {
                    $message->forceFill(['category_id' => $category->id, 'categorized_by' => 'ai'])->save();
                    $categorized++;
                }
            } catch (\Throwable) {
                // pojedynczy błąd nie przerywa całego batcha
                continue;
            }
        }

        return ['ok' => true, 'processed' => $processed, 'categorized' => $categorized];
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    public function classify(Message $message, Collection $categories): ?Category
    {
        $list = $categories->map(fn (Category $c) => '- '.$c->name)->implode("\n");

        $excerpt = trim((string) ($message->snippet ?: mb_substr(strip_tags((string) $message->body_text), 0, 500)));
        $excerpt = mb_substr($excerpt, 0, 600);

        $system = "Jesteś klasyfikatorem firmowej poczty. Przypisz e-mail do DOKŁADNIE jednej kategorii z listy. "
            ."Odpowiedz wyłącznie nazwą kategorii, bez żadnego dodatkowego tekstu. Jeśli nic nie pasuje — wybierz „Inne”.\n\n"
            ."Kategorie:\n".$list;

        $user = 'Od: '.($message->from_name ? $message->from_name.' ' : '').'<'.($message->from_email ?: '?').">\n"
            .'Temat: '.($message->subject ?: '(brak)')."\n"
            .'Treść (fragment): '.$excerpt;

        $reply = $this->chat($system, $user);
        if ($reply === null || $reply === '') {
            return null;
        }

        $needle = mb_strtolower(trim($reply));

        $match = $categories->first(fn (Category $c) => mb_strtolower($c->name) === $needle)
            ?? $categories->first(fn (Category $c) => str_contains($needle, mb_strtolower($c->name)));

        return $match ?? $categories->firstWhere('name', 'Inne');
    }

    private function chat(string $system, string $user): ?string
    {
        $timeout = AiContentRuntimeConfig::openAiRequestTimeout();

        $headers = [
            'Authorization' => 'Bearer '.AiContentRuntimeConfig::openAiApiKey(),
            'Content-Type'  => 'application/json',
        ];
        $org = AiContentRuntimeConfig::openAiOrganization();
        if ($org !== null && $org !== '') {
            $headers['OpenAI-Organization'] = $org;
        }

        $response = Http::timeout($timeout)
            ->connectTimeout(min(30, $timeout))
            ->withHeaders($headers)
            ->post(self::URL, [
                'model'       => AiContentRuntimeConfig::copilotOpenAiModel(),
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'temperature' => 0,
                'max_tokens'  => 20,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 200));
        }

        $text = $response->json('choices.0.message.content');

        return is_string($text) ? trim($text) : null;
    }
}
