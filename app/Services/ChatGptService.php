<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use App\Models\Product;

class ChatGptService
{
    public static function generate(array $settings, array $response_format = [])
    {
        $result = OpenAI::chat()->create([
            'model' => $settings['model'],
            'messages' => [
                [
                    "role" => "system",
                    "content" => $settings['system_content']
                ],
                [
                    "role" => "user",
                    "content" => $settings['user_content']
                ],
            ],
            'temperature' => (float)$settings['temperature'],
            'max_tokens' => (int)$settings['max_tokens'],
            'top_p' => (float)$settings['top_p'],
            'frequency_penalty' => (float)$settings['frequency_penalty'],
            'presence_penalty' => (float)$settings['presence_penalty'],
            'response_format' => $response_format
        ]);

        return $result->choices[0]->message->content;
    }

    public static function generateProductContent(array $settings, array $product, string $locale = 'pl')
    {
        $schema = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'product_content_generation',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => ['string', 'null'],
                            'description' => 'Zoptymalizowana nazwa produktu'
                        ],
                        'info_1' => [
                            'type' => ['string', 'null'],
                            'description' => 'Szczegółowy opis produktu'
                        ],
                        'info_2' => [
                            'type' => ['string', 'null'],
                            'description' => 'Krótki opis/cechy produktu (opcjonalne)'
                        ],
                        'info_3' => [
                            'type' => ['string', 'null'],
                            'description' => 'Dodatkowe informacje/instrukcje (opcjonalne)'
                        ],
                        'meta_url' => [
                            'type' => ['string', 'null'],
                            'description' => 'Meta URL dla SEO'
                        ],
                        'meta_title' => [
                            'type' => ['string', 'null'],
                            'description' => 'Meta tytuł dla SEO'
                        ],
                        'meta_description' => [
                            'type' => ['string', 'null'],
                            'description' => 'Meta opis dla SEO'
                        ],
                        'meta_keywords' => [
                            'type' => ['string', 'null'],
                            'description' => 'Lista słów kluczowych'
                        ]
                    ],
                    'required' => [
                        'name',
                        'info_1',
                        'info_2',
                        'info_3',
                        'meta_url',
                        'meta_title',
                        'meta_description',
                        'meta_keywords',
                    ],
                    'additionalProperties' => false
                ],
                'strict' => true
            ]
        ];



        $settings = [
            'model' => 'gpt-4o-2024-08-06',
            'system_content' => $settings['system_content'],
            'user_content' => self::getUserPrompt($product, $settings['user_content'], $locale),
            'temperature' => $settings['temperature'] ?? 0.7,
            'max_tokens' => $settings['max_tokens'] ?? 2000,
            'top_p' => $settings['top_p'] ?? 1.0,
            'frequency_penalty' => $settings['frequency_penalty'] ?? 0.0,
            'presence_penalty' => $settings['presence_penalty'] ?? 0.0
        ];

        $response = self::generate($settings, $schema);

        return json_decode($response, true);
    }


    private static function getUserPrompt(array $productData, string $userPrompt, string $locale)
    {
        return "DANE PRODUKTU (język: {$locale}):
" . json_encode($productData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "

INSTRUKCJE UŻYTKOWNIKA:
" . $userPrompt;
    }
}
