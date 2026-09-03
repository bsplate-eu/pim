<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductionGroup;
use App\Models\ProductionGroupMember;
use App\Models\ProductionItem;
use App\Services\Production\CodeGrouper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Produkcja → Ustawienia → Grupowanie.
 *
 * Propozycje grup wylicza CodeGrouper, ale nic nie dziala samo: grupa zaczyna
 * laczyc wiersze dopiero po zatwierdzeniu, a odpiete warianty zostaja osobno.
 */
class ProductionGroupController extends Controller
{
    /** Odswieza propozycje z katalogu. */
    public function scan(CodeGrouper $grouper): JsonResponse
    {
        return response()->json($grouper->refreshProposals());
    }

    /** Zaznacza/odznacza wariant w propozycji. */
    public function toggleMember(Request $request, ProductionGroupMember $member): RedirectResponse
    {
        $data = $request->validate(['included' => ['required', 'boolean']]);

        $member->update(['included' => $data['included']]);

        return back()->with(['message' => 'Zapisano']);
    }

    /**
     * Zatwierdza grupe.
     *
     * Przy okazji przenosi znaczniki z wciaganych wariantow na trzon (suma
     * logiczna). Bez tego oznaczenie postawione kiedys na wariancie zniknelo by
     * z ekranu razem z jego wierszem — a dotyczy tej samej oslony.
     */
    public function approve(ProductionGroup $group): RedirectResponse
    {
        $this->applyApproval($group);

        return back()->with(['message' => 'Grupa zatwierdzona']);
    }

    /**
     * Masowe zatwierdzanie/odrzucanie zaznaczonych propozycji.
     *
     * Przy 100+ propozycjach klikanie po jednej to droga przez meke, a wiekszosc
     * z nich to oczywiste pary kod + kodALU.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:production_groups,id'],
            'action' => ['required', 'string', 'in:approve,reject,revoke'],
        ]);

        $groups = ProductionGroup::with('members')->whereIn('id', $data['ids'])->get();

        foreach ($groups as $group) {
            match ($data['action']) {
                'approve' => $this->applyApproval($group),
                'reject' => $group->update(['status' => ProductionGroup::REJECTED, 'approved_at' => null]),
                'revoke' => $group->update(['status' => ProductionGroup::PROPOSED, 'approved_at' => null]),
            };
        }

        $slowo = match ($data['action']) {
            'approve' => 'zatwierdzonych',
            'reject' => 'odrzuconych',
            'revoke' => 'cofnietych',
        };

        return back()->with(['message' => $groups->count() . ' grup ' . $slowo]);
    }

    /**
     * Wlacza grupe i przenosi znaczniki z wciaganych wariantow na trzon.
     * Wspolne dla pojedynczego zatwierdzenia i operacji masowej.
     */
    private function applyApproval(ProductionGroup $group): void
    {
        $group->loadMissing('members');

        $codes = $group->members->where('included', true)->pluck('product_code');

        if ($codes->isNotEmpty()) {
            $variants = ProductionItem::whereIn('product_code', $codes)->get();

            if ($variants->isNotEmpty()) {
                $trunk = ProductionItem::firstOrNew(['product_code' => $group->trunk]);

                foreach (['has_project', 'team_steel', 'brak_zestawu', 'projekty_gotowe'] as $flag) {
                    if ($variants->contains(fn ($v) => (bool) $v->{$flag})) {
                        $trunk->{$flag} = true;
                    }
                }

                $trunk->save();

                ProductionItem::whereIn('product_code', $codes)->update([
                    'has_project' => false,
                    'team_steel' => false,
                    'brak_zestawu' => false,
                    'projekty_gotowe' => false,
                ]);
            }
        }

        $group->update(['status' => ProductionGroup::APPROVED, 'approved_at' => now()]);
    }

    /** Cofa zatwierdzenie — wiersze wracaja do osobnych kodow. */
    public function revoke(ProductionGroup $group): RedirectResponse
    {
        $group->update(['status' => ProductionGroup::PROPOSED, 'approved_at' => null]);

        return back()->with(['message' => 'Grupa cofnieta do propozycji']);
    }

    /** Odrzuca propozycje — nie grupuje i nie wraca przy kolejnym skanie. */
    public function reject(ProductionGroup $group): RedirectResponse
    {
        $group->update(['status' => ProductionGroup::REJECTED, 'approved_at' => null]);

        return back()->with(['message' => 'Propozycja odrzucona']);
    }

    /**
     * Dane zakladki „Grupowanie" — wolane z ekranu Ustawien.
     *
     * @return array<string,mixed>
     */
    public static function settingsPayload(): array
    {
        $sales = ProductionItem::pluck('sales_12m', 'product_code');
        $names = Product::query()
            ->select('product_code', 'name')
            ->get()
            ->groupBy('product_code')
            ->map(fn ($g) => htmlspecialchars_decode((string) $g->first()->name));

        $groups = ProductionGroup::with('members')->orderBy('trunk')->get()
            ->map(function (ProductionGroup $group) use ($sales, $names) {
                $members = $group->members
                    ->sortBy('product_code')
                    ->map(fn (ProductionGroupMember $m) => [
                        'id' => $m->id,
                        'product_code' => $m->product_code,
                        'included' => $m->included,
                        'sales_12m' => (int) ($sales[$m->product_code] ?? 0),
                    ])
                    ->values();

                $trunkSales = (int) ($sales[$group->trunk] ?? 0);
                $includedSales = $members->where('included', true)->sum('sales_12m');

                return [
                    'id' => $group->id,
                    'trunk' => $group->trunk,
                    'trunk_name' => $names[$group->trunk] ?? '',
                    'trunk_sales' => $trunkSales,
                    'status' => $group->status,
                    'members' => $members,
                    // Ile pokaze wiersz po zatwierdzeniu — trzon plus zaznaczone warianty.
                    'sales_after' => $trunkSales + $includedSales,
                ];
            });

        return [
            'proposed' => $groups->where('status', ProductionGroup::PROPOSED)->values(),
            'approved' => $groups->where('status', ProductionGroup::APPROVED)->values(),
            'rejected' => $groups->where('status', ProductionGroup::REJECTED)->values(),
        ];
    }
}
