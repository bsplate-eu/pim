<?php

namespace App\Http\Controllers\Admin;

use App\Models\SummaryMonth;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CostPlannerSummaryController extends Controller
{
    /**
     * Dozwolone źródła zamówień (order_source z BaseLinkera).
     * Edytuj tę listę, gdy poznasz dokładne wartości dla BSP DE.
     */
    private const ALLOWED_SOURCES = [
        'ebay',
        'BSP [DE]',
        'BSP DE',
        'bsp_black_steel_plate_gmbh',
    ];

    public function index(): Response
    {
        // Policz pozycje (FV + KOR) per miesiąc — z numeru faktury.
        $counts = [];
        foreach ($this->collectRows() as $row) {
            $key = $row['year'] . '-' . $row['month'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $months = SummaryMonth::query()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn (SummaryMonth $m) => [
                'id'              => $m->id,
                'label'           => $m->label,
                'year'            => $m->year,
                'month'           => $m->month,
                'positions_count' => $counts[$m->year . '-' . $m->month] ?? 0,
            ]);

        return Inertia::render('Summaries/Index', [
            'months' => $months,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'year'  => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        if (SummaryMonth::where('year', $validated['year'])->where('month', $validated['month'])->exists()) {
            return back()->withErrors(['month' => 'Taki miesiąc już istnieje.']);
        }

        $month = SummaryMonth::create([
            'year'  => $validated['year'],
            'month' => $validated['month'],
            'label' => SummaryMonth::buildLabel($validated['year'], $validated['month']),
        ]);

        return redirect()->route('crafter.cost-planner.summaries.show', $month->id)
            ->with('message', 'Miesiąc utworzony.');
    }

    public function show(SummaryMonth $summaryMonth): Response
    {
        $rows = $this->collectRows($summaryMonth->year, $summaryMonth->month);

        // Sortowanie: po numerze z FV/korekty (rosnąco).
        usort($rows, fn ($a, $b) => [$a['nr'], $a['id']] <=> [$b['nr'], $b['id']]);

        return Inertia::render('Summaries/Show', [
            'month' => $summaryMonth->only(['id', 'label', 'year', 'month']),
            'rows'  => array_values($rows),
        ]);
    }

    public function destroy(SummaryMonth $summaryMonth): RedirectResponse
    {
        $summaryMonth->delete();

        return redirect()->route('crafter.cost-planner.summaries.index')
            ->with('message', 'Miesiąc usunięty.');
    }

    public function refresh(SummaryMonth $summaryMonth): RedirectResponse
    {
        // Dane liczone są na żywo — wystarczy przeładować widok.
        return back()->with('message', 'Zestawienie odświeżone.');
    }

    /**
     * Eksport miesiąca do XLS w układzie księgowym (jak wzór "Tabelle X-RRRR Verkauft").
     * Kolumny: A Datum | B Betrag | C S/H | D Gegenkonto | E Konto | F Rechnungsnummer | G Buchungstext
     */
    public function export(SummaryMonth $summaryMonth): StreamedResponse
    {
        $rows = $this->collectRows($summaryMonth->year, $summaryMonth->month);
        usort($rows, fn ($a, $b) => [$a['nr'], $a['id']] <=> [$b['nr'], $b['id']]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Tabelle {$summaryMonth->month}-{$summaryMonth->year} Verkauft");

        // Nagłówki — jak we wzorze (uwaga: 'Buchungstext ' ma spację na końcu) + dodana kolumna H 'Name'.
        $headers = ['Datum', 'Betrag', 'S/H', 'Gegenkonto', 'Konto', 'Rechnungsnummer', 'Buchungstext ', 'Name'];
        $sheet->fromArray($headers, null, 'A1');

        $r = 2;
        foreach ($rows as $row) {
            $datum = $row['issue_date'] ? Carbon::parse($row['issue_date'])->format('d.m.Y') : '';

            // A = data utworzenia, B = kwota brutto, F = numer FV/korekty, G = rodzaj dokumentu,
            // H = imię i nazwisko. C–E zostają puste.
            $sheet->setCellValueExplicit("A{$r}", $datum, DataType::TYPE_STRING);
            $sheet->setCellValue("B{$r}", (float) $row['total_brutto']);
            $sheet->setCellValueExplicit("F{$r}", (string) $row['nr_full'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("G{$r}", (string) $row['doc_type_de'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("H{$r}", (string) ($row['customer_name'] ?? ''), DataType::TYPE_STRING);
            $r++;
        }

        $filename = "Tabelle {$summaryMonth->month}-{$summaryMonth->year} Verkauft.xls";
        $writer = new Xls($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.ms-excel',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    /**
     * Zbiera faktury (FV) i korekty (KOR) z dozwolonych źródeł.
     * Każda faktura/korekta = osobny wiersz. Miesiąc/rok parsowane z numeru (nr_full).
     * Korekta bez własnego order_id dziedziczy źródło/datę po fakturze nadrzędnej.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectRows(?int $year = null, ?int $month = null): array
    {
        $raw = DB::table('connect_invoices as i')
            ->leftJoin('orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('connect_invoices as p', 'p.baselinker_invoice_id', '=', 'i.corrected_invoice_id')
            ->leftJoin('orders as po', 'po.id', '=', 'p.order_id')
            ->selectRaw('i.id, i.type, i.nr, i.nr_full, i.issue_date, '
                . 'i.total_brutto, i.currency, '
                . 'COALESCE(o.order_source, po.order_source) AS source, '
                . 'COALESCE(o.date_add, po.date_add) AS order_date, '
                . "COALESCE(NULLIF(o.invoice_fullname, ''), o.delivery_fullname, "
                . "NULLIF(po.invoice_fullname, ''), po.delivery_fullname) AS customer_name")
            ->get();

        $rows = [];
        foreach ($raw as $r) {
            if (! $r->source || ! in_array($r->source, self::ALLOWED_SOURCES, true)) {
                continue;
            }

            $parsed = $this->parseNrFull($r->nr_full);
            if (! $parsed) {
                continue;
            }

            if ($year !== null && ($parsed['year'] !== $year || $parsed['month'] !== $month)) {
                continue;
            }

            $rows[] = [
                'id'           => (int) $r->id,
                'type'         => $r->type, // invoice | correction
                'doc_type_de'  => $r->type === 'correction' ? 'Korrekturrechnung' : 'Rechnung',
                'nr'           => (int) ($r->nr ?? $parsed['nr']),
                'nr_full'      => $r->nr_full,
                'customer_name' => $r->customer_name ?: null,
                'total_brutto' => (float) $r->total_brutto,
                'currency'     => $r->currency,
                'issue_date'   => $r->issue_date ? substr($r->issue_date, 0, 10) : null,
                'order_date' => $r->order_date ? substr($r->order_date, 0, 10) : null,
                'source'     => $r->source,
                'year'       => $parsed['year'],
                'month'      => $parsed['month'],
            ];
        }

        return $rows;
    }

    /**
     * Parsuje numer faktury w formacie {nr}/{miesiąc}/{rok}[/{seria}].
     * Np. "37/5/2026/BSP" → nr=37, month=5, year=2026.
     *
     * @return array{nr:int, month:int, year:int}|null
     */
    private function parseNrFull(?string $nrFull): ?array
    {
        if (! $nrFull) {
            return null;
        }

        if (preg_match('#^(\d+)\s*/\s*(\d{1,2})\s*/\s*(\d{4})#', $nrFull, $m)) {
            return [
                'nr'    => (int) $m[1],
                'month' => (int) $m[2],
                'year'  => (int) $m[3],
            ];
        }

        return null;
    }
}
