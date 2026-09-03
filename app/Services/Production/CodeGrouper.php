<?php

namespace App\Services\Production;

use App\Models\Product;
use App\Models\ProductionGroup;
use App\Models\ProductionGroupMember;
use App\Models\ProductionVariantSuffix;
use Illuminate\Support\Collection;

/**
 * Grupowanie wariantow kodu produkcyjnego.
 *
 * Trzon = kod bez sufiksu MATERIALOWEGO (ALU/GAL/INOX — lista w Ustawieniach).
 * Cyfra i pozostale litery zostaja czescia kodu, bo oznaczaja inna oslone:
 * 00.1791 nie laczy sie z 00.1792, a 30.144W ma wlasna wersje aluminiowa
 * 30.144WALU i jest wlasnym trzonem.
 *
 * Automat wylicza propozycje, ale niczego nie wlacza: grupa dziala dopiero
 * po zatwierdzeniu, a w propozycji mozna odpiac pojedyncze warianty.
 */
class CodeGrouper
{
    /** @var list<string>|null lista sufiksow materialowych, czytana raz na instancje */
    private ?array $suffixes = null;

    /**
     * Trzon kodu: kod bez JEDNEGO sufiksu materialowego z konca.
     *
     * Ucinamy wylacznie ALU/GAL/INOX (albo cokolwiek jest na liscie), a nie
     * dowolne litery. Koncowki typu „W" czy „A" oznaczaja INNA oslone: 30.144W
     * ma wlasna wersje aluminiowa 30.144WALU, wiec jest wlasnym trzonem.
     * Zwraca null, gdy kod nie ma ksztaltu NN.NNN.
     */
    public function trunk(string $code): ?string
    {
        if (! preg_match('/^\d+\.\d{3}/', $code)) {
            return null;
        }

        $clean = strtoupper(str_replace([' ', '-'], '', $code));

        // Najdluzszy pasujacy sufiks pierwszy — inaczej „WALU" ucieloby sie do „W".
        foreach ($this->suffixes() as $suffix) {
            if (str_ends_with($clean, $suffix) && strlen($clean) > strlen($suffix)) {
                return substr($clean, 0, -strlen($suffix));
            }
        }

        return $clean;
    }

    /** @return list<string> */
    private function suffixes(): array
    {
        if ($this->suffixes === null) {
            $this->suffixes = ProductionVariantSuffix::pluck('suffix')
                ->sortByDesc(fn (string $s) => strlen($s))
                ->values()
                ->all();
        }

        return $this->suffixes;
    }

    /**
     * Odswieza propozycje na podstawie katalogu.
     *
     * Nie rusza grup juz zatwierdzonych ani odrzuconych poza dorzuceniem NOWYCH
     * wariantow — inaczej kazdy przebieg kasowalby decyzje uzytkownika.
     *
     * @return array{nowych_grup:int, nowych_wariantow:int, propozycji:int}
     */
    public function refreshProposals(): array
    {
        $codes = Product::query()->distinct()->pluck('product_code');

        // Propozycje liczymy od zera: po zmianie listy sufiksow trzony sie
        // zmieniaja, wiec stare propozycje sa nieaktualne. Decyzje juz podjete
        // (zatwierdzone i odrzucone) zostaja nietkniete.
        $stale = ProductionGroup::where('status', ProductionGroup::PROPOSED)->pluck('id');

        if ($stale->isNotEmpty()) {
            ProductionGroupMember::whereIn('group_id', $stale)->delete();
            ProductionGroup::whereIn('id', $stale)->delete();
        }

        // Uwalniamy odpiete warianty, ktore po zmianie reguly naleza gdzie indziej.
        // Przyklad: 30.144W odpiety recznie od 30.144 blokowalby sam siebie —
        // wiersz czlonka zajmuje kod (unikat), wiec 30.144W nie moglby zalozyc
        // wlasnej grupy z 30.144WALU. Decyzji nie tracimy: przy poprawnej regule
        // ten wariant nigdy juz nie zostanie zaproponowany do tamtego trzonu.
        foreach (ProductionGroupMember::with('group')->where('included', false)->get() as $member) {
            if ($member->group !== null && $this->trunk($member->product_code) !== $member->group->trunk) {
                $member->delete();
            }
        }

        // trzon => lista wariantow (bez samego trzonu)
        $families = [];
        foreach ($codes as $code) {
            $trunk = $this->trunk($code);
            if ($trunk === null || $trunk === strtoupper(str_replace([' ', '-'], '', $code))) {
                continue;
            }
            $families[$trunk][] = $code;
        }

        // Grupa ma sens tylko gdy trzon istnieje jako produkt — inaczej nie ma glowy wiersza.
        $existing = $codes->flip();
        $families = array_filter(
            $families,
            fn ($variants, $trunk) => $existing->has($trunk),
            ARRAY_FILTER_USE_BOTH
        );

        // Kody i trzony zajete przez decyzje, ktorych nie ruszamy.
        $takenTrunks = ProductionGroup::pluck('trunk')->flip();
        $takenCodes = ProductionGroupMember::pluck('product_code')->flip();

        $newGroups = 0;
        $newMembers = 0;

        foreach ($families as $trunk => $variants) {
            if ($takenTrunks->has($trunk)) {
                continue;
            }

            $free = array_values(array_filter($variants, fn ($c) => ! $takenCodes->has($c)));

            if ($free === []) {
                continue;
            }

            $group = ProductionGroup::create(['trunk' => $trunk, 'status' => ProductionGroup::PROPOSED]);
            $newGroups++;

            foreach ($free as $code) {
                ProductionGroupMember::create([
                    'group_id' => $group->id,
                    'product_code' => $code,
                    'included' => true,
                ]);
                $newMembers++;
            }
        }

        return [
            'nowych_grup' => $newGroups,
            'nowych_wariantow' => $newMembers,
            'propozycji' => ProductionGroup::where('status', ProductionGroup::PROPOSED)->count(),
        ];
    }

    /**
     * Mapa dzialajacego grupowania: kod wariantu => trzon.
     * Tylko grupy zatwierdzone i tylko warianty zaznaczone.
     *
     * @return Collection<string,string>
     */
    public function activeMap(): Collection
    {
        return ProductionGroupMember::query()
            ->where('included', true)
            ->whereHas('group', fn ($q) => $q->where('status', ProductionGroup::APPROVED))
            ->with('group:id,trunk')
            ->get()
            ->mapWithKeys(fn (ProductionGroupMember $m) => [$m->product_code => $m->group->trunk]);
    }
}
