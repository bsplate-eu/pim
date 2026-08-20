<?php

namespace App\Services\Ebay\Listing;

/**
 * „Skóra" aukcji — statyczny szablon HTML, w który wpada nasza treść, żeby podgląd w PIM
 * pokazywał to, co zobaczy kupujący, a nie goły opis.
 *
 * To ten sam plik, który siedzi w BaseLinkerze jako szablon „BSP - DE" (`ebay/bsp-de-v2.html`).
 * Ma nagłówek z logo, kolumnę zdjęcia, tabelę Hersteller/Art.-Nr./Produktzustand, galerię
 * i stopkę — a w miejsce `[opis]` wchodzi nasz opis, który CSS zamienia w tabelę parametrów.
 *
 * Skóra NIE jest wysyłana na eBay przez PIM: aukcje składa BaseLinker. Trzymamy ją, bo bez niej
 * podgląd kłamie — opis wygląda inaczej samotnie niż w docelowym layoucie, a to właśnie
 * struktura opisu decyduje o wyglądzie tabeli.
 *
 * Pliki: `resources/ebay/skins/{MARKETPLACE}.html`. Brak pliku = brak skóry (podgląd pokaże
 * sam opis), więc dołożenie rynku sprowadza się do wrzucenia pliku.
 */
class EbayAuctionSkin
{
    /** Tagi BaseLinkera obecne w szablonie. */
    private const TAG_TITLE = '[nazwa_oferty]';
    private const TAG_SKU = '[sku]';
    private const TAG_IMAGE = '[obrazek]';
    private const TAG_GALLERY = '[dodatkowe_obrazki]';
    private const TAG_DESC = '[opis]';

    public static function exists(string $marketplace): bool
    {
        return is_file(self::path($marketplace));
    }

    /** Lista rynków, dla których mamy skórę — do pokazania w UI. @return list<string> */
    public static function available(): array
    {
        $files = glob(resource_path('ebay/skins/*.html')) ?: [];

        return array_values(array_map(fn ($f) => basename($f, '.html'), $files));
    }

    /**
     * Złóż podgląd aukcji. Zwraca null, gdy dla rynku nie ma skóry.
     *
     * @param  list<string>  $images  adresy zdjęć produktu (pierwsze = główne)
     */
    public static function wrap(
        string $marketplace,
        string $title,
        string $sku,
        array $images,
        string $description,
    ): ?string {
        if (! self::exists($marketplace)) {
            return null;
        }

        $skin = (string) file_get_contents(self::path($marketplace));
        $main = $images[0] ?? '';

        // BaseLinker wstawia w [dodatkowe_obrazki] po jednym <div> na zdjęcie, a szablon
        // stylizuje bezpośrednie dzieci kontenera — odtwarzamy dokładnie ten kształt.
        $gallery = collect(array_slice($images, 1))
            ->map(fn ($url) => '<div><img src="'.e($url).'" alt="'.e($title).'"></div>')
            ->implode('');

        return str_replace(
            [self::TAG_TITLE, self::TAG_SKU, self::TAG_IMAGE, self::TAG_GALLERY, self::TAG_DESC],
            [e($title), e($sku), e($main), $gallery, $description],
            $skin,
        );
    }

    private static function path(string $marketplace): string
    {
        // Nazwa rynku wchodzi do ścieżki — przepuszczamy wyłącznie kształt EBAY_XX,
        // żeby żadne „../" nie wyprowadziło odczytu poza katalog skór.
        $safe = preg_replace('/[^A-Z_]/', '', strtoupper($marketplace)) ?? '';

        return resource_path('ebay/skins/'.$safe.'.html');
    }
}
