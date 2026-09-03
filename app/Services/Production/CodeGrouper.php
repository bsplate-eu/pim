<?php

namespace App\Services\Production;

use App\Models\Product;
use App\Models\ProductionGroup;
use App\Models\ProductionGroupMember;
use Illuminate\Support\Collection;

/**
 * Grupowanie wariantow kodu produkcyjnego.
 *
 * Trzon = kod bez KONCOWYCH LITER. Cyfra zostaje czescia kodu, bo 00.179
 * i 00.1791 to rozne oslony — laczy sie tylko 00.1791 z 00.1791ALU.
 *
 * Automat wylicza propozycje, ale niczego nie wlacza: grupa dziala dopiero
 * po zatwierdzeniu, a w propozycji mozna odpiac pojedyncze warianty.
 */
class CodeGrouper
{
    /** Kod bez koncowych liter; null gdy kod nie ma ksztaltu NN.NNN. */
    public static function trunk(string $code): ?string
    {
        if (! preg_match('/^\d+\.\d{3}/', $code)) {
            return null;
        }

        $clean = str_replace([' ', '-'], '', $code);
        $trunk = rtrim($clean, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');

        return $trunk === '' ? null : $trunk;
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

        // trzon => lista wariantow (bez samego trzonu)
        $families = [];
        foreach ($codes as $code) {
            $trunk = self::trunk($code);
            if ($trunk === null || $trunk === $code) {
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

        $groups = ProductionGroup::with('members')->get()->keyBy('trunk');
        $newGroups = 0;
        $newMembers = 0;

        foreach ($families as $trunk => $variants) {
            $group = $groups[$trunk] ?? null;

            if ($group === null) {
                $group = ProductionGroup::create(['trunk' => $trunk, 'status' => ProductionGroup::PROPOSED]);
                $newGroups++;
                $known = [];
            } else {
                $known = $group->members->pluck('product_code')->all();
            }

            foreach (array_diff($variants, $known) as $code) {
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
