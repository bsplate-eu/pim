<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LinkPreviewController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|url|max:2048',
        ]);
        $url = $data['url'];

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        // Blokada SSRF do prywatnych hostów
        if (preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/i', $host)) {
            return response()->json(['error' => 'Blocked host'], 422);
        }

        $preview = Cache::remember('link_preview:' . sha1($url), now()->addHours(24), function () use ($url, $host) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 PIM-LinkPreview/1.0'])
                    ->get($url);

                if (!$response->ok()) {
                    return ['url' => $url, 'host' => $host, 'title' => $url, 'description' => null, 'image' => null, 'favicon' => null];
                }

                $html = (string) $response->body();

                return self::parseOgMetadata($html, $url, $host);
            } catch (\Throwable $e) {
                return ['url' => $url, 'host' => $host, 'title' => $url, 'description' => null, 'image' => null, 'favicon' => null];
            }
        });

        return response()->json(['preview' => $preview]);
    }

    private static function parseOgMetadata(string $html, string $url, string $host): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $meta = function (string $query) use ($xpath): ?string {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length) {
                $val = $nodes->item(0)->getAttribute('content');
                return $val !== '' ? $val : null;
            }
            return null;
        };

        $title = $meta('//meta[@property="og:title"]')
            ?? $meta('//meta[@name="twitter:title"]');

        if (!$title) {
            $titleNodes = $xpath->query('//title');
            if ($titleNodes && $titleNodes->length) {
                $title = trim($titleNodes->item(0)->textContent);
            }
        }

        $description = $meta('//meta[@property="og:description"]')
            ?? $meta('//meta[@name="description"]')
            ?? $meta('//meta[@name="twitter:description"]');

        $image = $meta('//meta[@property="og:image"]')
            ?? $meta('//meta[@name="twitter:image"]');

        $favicon = null;
        $iconNodes = $xpath->query('//link[contains(@rel,"icon")]');
        if ($iconNodes && $iconNodes->length) {
            $favicon = $iconNodes->item(0)->getAttribute('href') ?: null;
        }

        // Relative URL → absolute
        $absolutize = function (?string $u) use ($url) {
            if (!$u) return null;
            if (preg_match('#^https?://#i', $u)) return $u;
            if (str_starts_with($u, '//')) return 'https:' . $u;
            $parts = parse_url($url);
            $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (str_starts_with($u, '/')) return $base . $u;
            return $base . '/' . ltrim($u, '/');
        };

        return [
            'url'         => $url,
            'host'        => $host,
            'title'       => $title ?: $url,
            'description' => $description,
            'image'       => $absolutize($image),
            'favicon'     => $absolutize($favicon),
        ];
    }
}
