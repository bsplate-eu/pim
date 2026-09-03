<?php

namespace App\Services\Production;

use App\Models\Product;
use App\Models\ProductionItem;
use App\Models\ProductionStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Przypisuje etapy do kodow na podstawie sprzedazy 12M i przedzialow z Ustawien.
 *
 * Etap jest w calosci funkcja sprzedazy — nie ma recznego nadpisania (decyzja
 * uzytkownika: „automat zawsze wygrywa"), ale przeliczenie odpala sie wylacznie
 * na zadanie, przyciskiem. Dzieki temu zmiana progow nie przestawia tabeli
 * komus pod rekami w trakcie pracy.
 */
class StageAssigner
{
    /**
     * @return array{przypisanych:int, bez_etapu:int, zmienionych:int, per_etap:array<string,int>, bez_zakresu:list<string>}
     */
    public function run(): array
    {
        $stages = ProductionStage::orderBy('position')->orderBy('id')->get();

        // Przy nachodzacych przedzialach wygrywa etap wyzej na liscie — pierwszy
        // pasujacy konczy szukanie.
        $withRange = $stages->filter(fn (ProductionStage $stage) => $stage->hasRange())->values();

        // Kody bierzemy z katalogu, nie z production_items: kod bez zadnego
        // znacznika nie ma tam jeszcze wiersza, a etap ma dostac tak samo.
        $codes = Product::query()->distinct()->pluck('product_code');

        // Jeden odczyt zamiast zapytania na kod.
        $items = ProductionItem::get(['product_code', 'sales_12m', 'stage_id'])->keyBy('product_code');

        // Etap liczy sie z tego, co widac w tabeli, a tam wariant jest wciagniety
        // w trzon. Wariant nie dostaje wlasnego etapu, a trzon dostaje go od SUMY —
        // inaczej 26.174 (0 szt. na trzonie, 36 na wariancie) siedzialby w
        // „Pominiete", mimo ze wiersz pokazuje 36.
        $groupMap = (new CodeGrouper())->activeMap();
        $groupedSales = [];
        foreach ($groupMap as $variant => $trunk) {
            $groupedSales[$trunk] = ($groupedSales[$trunk] ?? 0) + (int) ($items[$variant]?->sales_12m ?? 0);
        }

        $now = Carbon::now();
        $perStage = [];
        $assigned = 0;
        $withoutStage = 0;
        $changed = 0;

        foreach ($codes as $code) {
            $item = $items[$code] ?? null;

            // Wariant wciagniety w grupe nie ma wlasnego wiersza — zdejmujemy mu etap.
            if ($groupMap->has($code)) {
                if ($item !== null && $item->stage_id !== null) {
                    DB::table('production_items')->where('product_code', $code)
                        ->update(['stage_id' => null, 'updated_at' => $now]);
                    $changed++;
                }

                continue;
            }

            $sales = (int) ($item?->sales_12m ?? 0) + ($groupedSales[$code] ?? 0);
            $stage = $withRange->first(fn (ProductionStage $s) => $s->matches($sales));

            if ($stage === null) {
                $withoutStage++;
            } else {
                $assigned++;
                $perStage[$stage->name] = ($perStage[$stage->name] ?? 0) + 1;
            }

            $stageId = $stage?->id;

            // Bez zmiany nie ruszamy wiersza. Kod bez etapu i bez wiersza w ogole
            // pomijamy — inaczej przeliczenie zalozyloby setki pustych rekordow.
            if ($item === null) {
                if ($stageId === null) {
                    continue;
                }

                DB::table('production_items')->insert([
                    'product_code' => $code,
                    'stage_id' => $stageId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $changed++;

                continue;
            }

            if ((int) $item->stage_id === (int) $stageId) {
                continue;
            }

            DB::table('production_items')
                ->where('product_code', $code)
                ->update(['stage_id' => $stageId, 'updated_at' => $now]);
            $changed++;
        }

        return [
            'przypisanych' => $assigned,
            'bez_etapu' => $withoutStage,
            'zmienionych' => $changed,
            'per_etap' => $perStage,
            'bez_zakresu' => $stages->filter(fn (ProductionStage $s) => ! $s->hasRange())
                ->pluck('name')->values()->all(),
        ];
    }
}
