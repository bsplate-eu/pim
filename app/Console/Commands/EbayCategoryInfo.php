<?php

namespace App\Console\Commands;

use App\Models\Ebay\EbayCategory;
use App\Models\Scrap\EbaySettings;
use App\Services\Ebay\EbayTaxonomyClient;
use Illuminate\Console\Command;

/**
 * Diagnostyka i „nauka" kategorii eBay pod wystawianie ofert (etap A).
 * Odpowiednik `allegro:category-info` z OMS ARGO.
 *
 * Czyta Taxonomy API tokenem aplikacyjnym (client_credentials) — NIE wymaga połączonego konta OAuth.
 *
 *   php artisan ebay:category-info --search="Unterfahrschutz"        # znajdź ID kategorii
 *   php artisan ebay:category-info 14769                             # pokaż wymogi (aspekty)
 *   php artisan ebay:category-info 9886 --marketplace=EBAY_FR --save # zapisz do ebay_categories
 */
class EbayCategoryInfo extends Command
{
    protected $signature = 'ebay:category-info
        {category? : ID kategorii w REST API (np. 14769); pomiń przy --search}
        {--marketplace=EBAY_DE : rynek (EBAY_DE, EBAY_FR, EBAY_ES…)}
        {--search= : szukaj kategorii po nazwie zamiast czytać aspekty}
        {--save : zapisz kategorię + aspekty do ebay_categories (aktywuje ją)}';

    protected $description = 'Kategorie eBay: wyszukiwanie + aspekty (Item Specifics) wymagane do wystawienia oferty';

    public function handle(): int
    {
        $settings = EbaySettings::first();
        if (! $settings || ! $settings->hasCredentials()) {
            $this->error('Brak kluczy eBay (Argo Connect → Integracje → Ebay).');

            return self::FAILURE;
        }

        $marketplace = strtoupper((string) $this->option('marketplace'));
        $taxonomy = new EbayTaxonomyClient($settings->client_id, $settings->client_secret);

        try {
            $treeId = $taxonomy->categoryTreeId($marketplace);
        } catch (\Throwable $e) {
            $this->error("Nie udało się pobrać drzewa kategorii dla {$marketplace}: ".$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Rynek <info>{$marketplace}</info> · drzewo kategorii <info>{$treeId}</info>");

        if ($query = (string) $this->option('search')) {
            return $this->search($taxonomy, $treeId, $query);
        }

        $categoryId = (string) $this->argument('category');
        if ($categoryId === '') {
            $this->error('Podaj ID kategorii albo użyj --search="nazwa".');

            return self::FAILURE;
        }

        return $this->aspects($taxonomy, $treeId, $marketplace, $categoryId);
    }

    /** Wyszukiwarka kategorii po nazwie — do znalezienia właściwego ID przed nauką. */
    private function search(EbayTaxonomyClient $taxonomy, string $treeId, string $query): int
    {
        $hits = $taxonomy->categorySuggestions($treeId, $query);
        if ($hits === []) {
            $this->warn("Brak kategorii pasujących do „{$query}”.");

            return self::SUCCESS;
        }

        $this->table(['ID', 'Nazwa', 'Ścieżka'], array_map(
            fn (array $c) => [$c['id'], $c['name'], $c['path']],
            array_slice($hits, 0, 15)
        ));

        return self::SUCCESS;
    }

    /** Aspekty kategorii: co eBay wymaga, a co dopuszcza. Z --save zapisuje do ebay_categories. */
    private function aspects(EbayTaxonomyClient $taxonomy, string $treeId, string $marketplace, string $categoryId): int
    {
        try {
            $aspects = $taxonomy->itemAspectsForCategory($treeId, $categoryId);
        } catch (\Throwable $e) {
            $this->error("Kategoria {$categoryId} ({$marketplace}): ".$e->getMessage());

            return self::FAILURE;
        }

        if ($aspects === []) {
            $this->warn("Kategoria {$categoryId} nie zwróciła żadnych aspektów — sprawdź, czy ID jest z REST API (nie z adresu strony).");

            return self::FAILURE;
        }

        $required = array_values(array_filter($aspects, fn ($a) => $a['required']));
        $optional = array_values(array_filter($aspects, fn ($a) => ! $a['required']));

        $this->newLine();
        $this->line(sprintf(
            'Kategoria <info>%s</info> — aspektów: %d (wymaganych: <comment>%d</comment>)',
            $categoryId,
            count($aspects),
            count($required)
        ));

        $row = fn (array $a) => [
            $a['name'],
            $a['mode'] === 'SELECTION_ONLY' ? 'ze słownika' : 'dowolny tekst',
            $a['cardinality'],
            $a['value_count'] ?: '—',
            implode(' / ', array_slice($a['values'], 0, 3)),
        ];
        $head = ['Aspekt', 'Tryb', 'Krotność', 'Wartości', 'Przykłady'];

        if ($required !== []) {
            $this->newLine();
            $this->line('<comment>WYMAGANE</comment> (bez nich eBay odrzuci ofertę):');
            $this->table($head, array_map($row, $required));
        }

        $this->line('Opcjonalne (pierwsze 10 z '.count($optional).'):');
        $this->table($head, array_map($row, array_slice($optional, 0, 10)));

        if (! $this->option('save')) {
            $this->newLine();
            $this->line('<fg=gray>Podgląd — nic nie zapisano. Dodaj --save, żeby nauczyć kategorię.</>');

            return self::SUCCESS;
        }

        $category = EbayCategory::updateOrCreate(
            ['marketplace' => $marketplace, 'category_id' => $categoryId],
            [
                'category_tree_id' => $treeId,
                'leaf' => true,   // aspekty zwraca tylko liść; gdyby nie był, wywołanie by nie przeszło
                'active' => true,
                'aspects' => $aspects,
                'last_synced_at' => now(),
            ]
        );

        $missing = $category->unmappedRequired();
        $this->newLine();
        $this->info("Zapisano kategorię #{$category->id} ({$marketplace} · {$categoryId}).");

        $missing === []
            ? $this->info('Wszystkie wymagane aspekty mają źródło — kategoria gotowa do wystawiania.')
            : $this->warn('Wymagane aspekty bez mapowania: '.implode(', ', $missing).' — uzupełnij w „Kategorie i parametry".');

        return self::SUCCESS;
    }
}
