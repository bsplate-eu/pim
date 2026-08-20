<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doprowadzenie szablonów eBay do kontraktu danych PIM–eBay (spec: `ebay/spec-pim.html`).
 *
 * Szablon aukcji buduje tabelę parametrów ze struktury opisu, więc naruszenia kontraktu psują
 * WYGLĄD oferty, nie tylko treść. Poprawiamy tylko to, co spec nakazuje jednoznacznie —
 * bez pisania nowych tekstów marketingowych (te wymagają native speakera, pkt 7 listy kontrolnej).
 *
 * ⚠️ Wzorce muszą znosić dwa warianty bajtów: na produkcji treść jest poprawnym UTF-8
 * („Verstärkungsprägungen"), w niektórych kopiach lokalnych diakrytyki są zdegradowane do „?".
 * Dlatego dopasowujemy po fragmentach ASCII z wieloznacznikiem w miejscu znaków diakrytycznych.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('ebay_templates')->get() as $t) {
            DB::table('ebay_templates')->where('id', $t->id)->update([
                'title' => $this->fixTitle((string) $t->title),
                'description' => $this->fixDescription((string) $t->description, (string) $t->marketplace),
            ]);
        }
    }

    /**
     * Zdejmij sierotę „EAN" doklejoną na końcu tytułu (defekt #2 w spec: nazwa skleiła się
     * z pustą kolumną importu i na aukcji kończy się słowem EAN bez wartości).
     */
    private function fixTitle(string $title): string
    {
        return trim(preg_replace('/\s+EAN\s*$/u', '', $title) ?? $title);
    }

    private function fixDescription(string $html, string $marketplace): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $html = $this->dropHeaderBlock($html);
        $html = $this->fixSentenceItem($html);
        $html = $this->guardZeroThickness($html);

        if (strtoupper($marketplace) === 'EBAY_AT' || strtoupper($marketplace) === 'EBAY_DE') {
            $html = $this->materialFromData($html);
        }

        return $html;
    }

    /**
     * Blok „Hersteller / Art.-Nr. / EAN / Produktzustand" na starcie opisu (pkt 3 listy
     * kontrolnej). Szablon aukcji ukrywa go regułą `p:first-child{display:none}` — hackiem,
     * który zjada pierwszy akapit produktom bez tego bloku. Usuwamy źródło problemu.
     *
     * Zostawiamy nagłówek sekcji („Schutz für das Auto:"), jeśli siedzi w tym samym akapicie —
     * bez niego lista pojazdu straciłaby tytuł.
     */
    private function dropHeaderBlock(string $html): string
    {
        return preg_replace_callback('/<p\b[^>]*>(.*?)<\/p>/isu', function (array $m) {
            static $done = false;
            if ($done) {
                return $m[0];
            }

            $labels = ['Hersteller:', 'Art.-Nr.:', 'EAN:', 'Produktzustand:'];
            $hits = array_filter($labels, fn ($l) => str_contains($m[1], $l));
            if (count($hits) < 2) {
                return $m[0];
            }
            $done = true;

            // Ostatnie <strong> w akapicie bywa nagłówkiem następnej listy — ratujemy je.
            if (preg_match('/(<strong\b[^>]*>(?:(?!<\/strong>).)*:<\/strong>)\s*$/isu', $m[1], $tail)) {
                return '<p>'.$tail[1].'</p>';
            }

            return '';
        }, $html, 1) ?? $html;
    }

    /**
     * „Jeder Schutz hat Verstärkungsprägungen" to zdanie, nie para etykieta/wartość — w tabeli
     * wychodzi wiersz z pustą prawą kolumną. Spec podaje wprost docelowy kształt.
     * Przy okazji wpis ląduje na swoim miejscu w kanonicznej kolejności (po Schutzlackierung).
     */
    private function fixSentenceItem(string $html): string
    {
        // „Verst?rkungspr?gungen" — kropka w miejscu diakrytyku, bo bajty bywają różne.
        $pattern = '/<li\b[^>]*>\s*<strong\b[^>]*>\s*Jeder\s+Schutz\s+hat\s+Verst.{1,3}rkungspr.{1,3}gungen\s*<\/strong>\s*<\/li>/isu';

        if (! preg_match($pattern, $html, $m)) {
            return $html;
        }

        // Etykietę bierzemy z oryginału, żeby zachować jego bajty diakrytyków.
        preg_match('/(Verst.{1,3}rkungspr.{1,3}gungen)/isu', $m[0], $word);
        $replacement = '<li><strong>'.($word[1] ?? 'Verstaerkungspraegungen').':</strong> Ja</li>';

        $html = str_replace($m[0], '', $html);

        // Wstaw po Schutzlackierung (pozycja 9 w kanonicznej kolejności); gdy jej nie ma —
        // na koniec listy technicznej.
        $after = '/(<li\b[^>]*>\s*<strong\b[^>]*>\s*Schutzlackierung:.*?<\/li>)/isu';
        if (preg_match($after, $html)) {
            return preg_replace($after, '$1'.$replacement, $html, 1) ?? $html;
        }

        return preg_replace('/(<\/ul>)(?!.*<\/ul>)/isu', $replacement.'$1', $html, 1) ?? $html;
    }

    /**
     * `Schutzdicke: 0.00 mm` — zero to nie dana, tylko jej brak (`products.width` bywa puste).
     * Spec zakazuje pozycji z pustą wartością, więc wiersz ma zniknąć, a nie kłamać.
     */
    private function guardZeroThickness(string $html): string
    {
        $pattern = '/<li\b[^>]*>\s*<strong\b[^>]*>\s*Schutzdicke:\s*<\/strong>\s*\{\{\s*\$width\s*\}\}\s*mm\s*<\/li>/isu';

        return preg_replace(
            $pattern,
            '@if(!empty($width) && (float) $width > 0)<li><strong>Schutzdicke:</strong> {{ $width }} mm</li>@endif',
            $html
        ) ?? $html;
    }

    /**
     * „Schutzmaterial: Stahl (sehr robust und flexibel)" jest wpisane na sztywno, a katalog ma
     * warianty aluminiowe — to nieprawdziwy opis towaru (osobna sekcja spec: „Materiał: dane,
     * nigdy założenie"). Bierzemy wartość z atrybutu.
     *
     * ⚠️ STOPGAP: `attribute_material` trzyma wartości po ANGIELSKU („Steel", „Aluminium"),
     * więc mapujemy je tu na niemiecki. Właściwym rozwiązaniem jest przetłumaczenie wartości
     * atrybutów u źródła — dopóki tego nie ma, każdy rynek musiałby mieć taką mapę u siebie.
     */
    private function materialFromData(string $html): string
    {
        $pattern = '/<li\b[^>]*>\s*<strong\b[^>]*>\s*Schutzmaterial:\s*<\/strong>.*?<\/li>/isu';

        $replacement = '@if(!empty($attribute_material))<li><strong>Schutzmaterial:</strong> '
            .'{{ [\'Steel\' => \'Stahl\', \'Aluminium\' => \'Aluminium\'][$attribute_material] ?? $attribute_material }}'
            .'</li>@endif';

        return preg_replace($pattern, $replacement, $html, 1) ?? $html;
    }

    public function down(): void
    {
        // Treść szablonów to dane redagowane przez człowieka — automatyczne cofanie
        // nadpisałoby jego poprawki. Przywracanie robi się z kopii bazy.
    }
};
