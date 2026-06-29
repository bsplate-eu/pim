<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Admin\Controller;
use App\Models\Mail\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:80'],
            'parent_id' => ['nullable', 'integer', 'exists:mail_catalogs,id'],
            'color'     => ['nullable', 'string', 'max:16'],
        ]);

        Catalog::create([
            'name'      => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'color'     => $data['color'] ?? null,
            'sort'      => (int) Catalog::where('parent_id', $data['parent_id'] ?? null)->max('sort') + 1,
        ]);

        return back()->with('success', 'Katalog dodany.');
    }

    public function update(Request $request, Catalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $catalog->update([
            'name'  => $data['name'],
            'color' => $data['color'] ?? $catalog->color,
        ]);

        return back()->with('success', 'Katalog zaktualizowany.');
    }

    /**
     * Przeniesienie katalogu pod inny katalog (zmiana rodzica) — „Przenieś do…".
     * Blokuje cykle: nie można przenieść katalogu do samego siebie ani do własnego podkatalogu.
     * Trafia na koniec listy w nowym rodzicu (sort = max+1).
     */
    public function move(Request $request, Catalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:mail_catalogs,id'],
        ]);
        $newParentId = $data['parent_id'] ?? null;

        if ($newParentId !== null
            && ((int) $newParentId === (int) $catalog->id
                || in_array((int) $newParentId, $this->descendantIds((int) $catalog->id), true))) {
            return back()->withErrors(['parent_id' => 'Nie można przenieść katalogu do samego siebie ani do jego podkatalogu.']);
        }

        $catalog->forceFill([
            'parent_id' => $newParentId,
            'sort'      => (int) Catalog::where('parent_id', $newParentId)->max('sort') + 1,
        ])->save();

        return back()->with('success', 'Katalog przeniesiony.');
    }

    /**
     * ID wszystkich potomków katalogu (rekurencyjnie) — do blokowania cykli przy przenoszeniu.
     *
     * @return array<int, int>
     */
    private function descendantIds(int $catalogId): array
    {
        $byParent = [];
        foreach (Catalog::get(['id', 'parent_id']) as $c) {
            $byParent[(int) ($c->parent_id ?? 0)][] = (int) $c->id;
        }

        $out = [];
        $stack = $byParent[$catalogId] ?? [];
        while ($stack) {
            $id = array_pop($stack);
            $out[] = $id;
            foreach ($byParent[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }

    /**
     * Zapis kolejności katalogów (drag&drop w Ustawieniach). Przyjmuje pełną listę ID w nowej
     * kolejności i ustawia `sort` W OBRĘBIE rodzeństwa (parent_id BEZ zmian — to tylko kolejność
     * wyświetlania, nie zmiana zagnieżdżenia). Dzięki temu drzewo nie może się rozpaść.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $catalogs = Catalog::whereIn('id', $data['ids'])->get()->keyBy('id');
        $counters = [];

        foreach ($data['ids'] as $id) {
            $catalog = $catalogs->get((int) $id);
            if (! $catalog) {
                continue;
            }
            $parentKey = (int) ($catalog->parent_id ?? 0);
            $counters[$parentKey] = ($counters[$parentKey] ?? 0) + 1;
            $catalog->forceFill(['sort' => $counters[$parentKey]])->save();
        }

        return back()->with('success', 'Kolejność katalogów zapisana.');
    }

    public function destroy(Catalog $catalog): RedirectResponse
    {
        // podkatalogi usuwają się kaskadowo; maile w nich tracą przypisanie (catalog_id → NULL)
        $catalog->delete();

        return back()->with('success', 'Katalog usunięty.');
    }
}
