<?php

namespace App\Http\Controllers\Admin;

use App\Models\Ksef\KsefCategory;
use App\Models\Ksef\KsefInvoice;
use App\Models\Ksef\KsefSettings;
use App\Models\Ksef\KsefSignalSettings;
use App\Services\Ksef\DuePaymentsService;
use App\Services\Ksef\KsefClient;
use App\Services\Ksef\KsefInvoiceParser;
use App\Services\Ksef\SignalSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Argo HQ → KSeF → faktury per firma (Pareto / BSP).
 *
 * Wygląd/UX wzorowany na Planerze kosztów (CostPlanner\Show). „Zaciągnij wszystko"
 * pobiera REALNE metadane FV z KSeF 2.0 (App\Services\Ksef\KsefClient, auth tokenem).
 * Status „opłacone" i kategorie prowadzimy u siebie. PDF: na razie placeholder
 * (pełny PDF KSeF wymaga generatora wizualizacji — TODO).
 */
class KsefController extends Controller
{
    private const COMPANIES = [
        'pareto' => 'Pareto',
        'bsp' => 'BSP',
    ];

    public function pareto(Request $request): Response
    {
        return $this->show($request, 'pareto');
    }

    public function bsp(Request $request): Response
    {
        return $this->show($request, 'bsp');
    }

    private function show(Request $request, string $company): Response
    {
        abort_unless(isset(self::COMPANIES[$company]), 404);

        $filters = [
            'year' => $request->query('year', 'all'),
            'month' => $request->query('month', 'all'),
            'quarter' => $request->query('quarter', 'all'),
            'status' => $request->query('status', 'all'),
        ];

        $base = KsefInvoice::query()->where('company', $company);
        $this->applyFilters($base, $filters);

        $invoices = (clone $base)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (KsefInvoice $i) => [
                'id' => $i->id,
                'issue_date' => $i->issue_date?->toDateString(),
                'number' => $i->number,
                'contractor' => $i->contractor,
                'items_text' => $i->items_text,
                'category' => $i->category,
                'due_date' => $i->due_date?->toDateString(),
                'amount' => (float) $i->amount,
                'currency' => $i->currency,
                'status' => $i->status,
                'has_pdf' => true, // klik zawsze otwiera (placeholder lub realny PDF)
            ]);

        // Podsumowanie (jak nagłówek Planera kosztów)
        $sum = (clone $base)->sum('amount');
        $sumUnpaid = (clone $base)->where('status', 'unpaid')->sum('amount');

        // Lata dostępne w danych firmy (do filtra)
        $years = KsefInvoice::where('company', $company)
            ->selectRaw('DISTINCT YEAR(issue_date) as y')
            ->orderByDesc('y')
            ->pluck('y')
            ->filter()
            ->values();
        if ($years->isEmpty()) {
            $years = collect([(int) now()->year]);
        }

        // Kategorie (zarządzane w zakładce Ustawienia)
        $categories = KsefCategory::where('company', $company)
            ->orderBy('position')->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Ksef/Index', [
            'company' => $company,
            'companyLabel' => self::COMPANIES[$company],
            'invoices' => $invoices,
            'filters' => $filters,
            'years' => $years,
            'categories' => $categories,
            'summary' => [
                'count' => $invoices->count(),
                'sum' => (float) $sum,
                'sum_unpaid' => (float) $sumUnpaid,
            ],
            'importMeta' => [
                'imported' => KsefInvoice::where('company', $company)->count(),
            ],
        ]);
    }

    private function applyFilters($query, array $filters): void
    {
        if (($filters['year'] ?? 'all') !== 'all') {
            $query->whereYear('issue_date', (int) $filters['year']);
        }
        if (($filters['month'] ?? 'all') !== 'all') {
            $query->whereMonth('issue_date', (int) $filters['month']);
        }
        if (($filters['quarter'] ?? 'all') !== 'all') {
            $q = (int) $filters['quarter'];
            $months = [($q - 1) * 3 + 1, ($q - 1) * 3 + 2, ($q - 1) * 3 + 3];
            $query->whereIn(DB::raw('MONTH(issue_date)'), $months);
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('status', $filters['status'] === 'paid' ? 'paid' : 'unpaid');
        }
    }

    /** Edycja kategorii pojedynczej FV (inline, axios). */
    public function updateCategory(Request $request, KsefInvoice $ksefInvoice): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:120'],
        ]);
        $ksefInvoice->category = $data['category'] ?: null;
        $ksefInvoice->save();

        return response()->json(['ok' => true, 'category' => $ksefInvoice->category]);
    }

    /** Oznaczenie FV jako opłacona / nieopłacona (checkbox w kolumnie). */
    public function updateStatus(Request $request, KsefInvoice $ksefInvoice): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:paid,unpaid'],
        ]);
        $ksefInvoice->status = $data['status'];
        $ksefInvoice->save();

        return response()->json(['ok' => true, 'status' => $ksefInvoice->status]);
    }

    // ── Ustawienia → kategorie (CRUD) ──

    public function storeCategory(Request $request, string $company): JsonResponse
    {
        abort_unless(isset(self::COMPANIES[$company]), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $cat = KsefCategory::firstOrCreate(
            ['company' => $company, 'name' => $data['name']],
            ['position' => (int) KsefCategory::where('company', $company)->max('position') + 1],
        );

        return response()->json(['ok' => true, 'category' => ['id' => $cat->id, 'name' => $cat->name]]);
    }

    public function updateCategoryName(Request $request, KsefCategory $ksefCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $old = $ksefCategory->name;
        $ksefCategory->name = $data['name'];
        $ksefCategory->save();

        // Spójność: przepisz kategorię na fakturach firmy (stara → nowa nazwa).
        if ($old !== $ksefCategory->name) {
            KsefInvoice::where('company', $ksefCategory->company)
                ->where('category', $old)
                ->update(['category' => $ksefCategory->name]);
        }

        return response()->json(['ok' => true, 'category' => ['id' => $ksefCategory->id, 'name' => $ksefCategory->name]]);
    }

    public function destroyCategory(KsefCategory $ksefCategory): JsonResponse
    {
        $ksefCategory->delete();

        return response()->json(['ok' => true]);
    }

    // ── Ustawienia → powiadomienia Signal (globalne) ──

    /** Zapis globalnej konfiguracji powiadomień Signal. */
    public function updateSignalSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'phone' => ['nullable', 'string', 'max:32'],
            'api_key' => ['nullable', 'string', 'max:128'],
            'template' => ['nullable', 'string', 'max:2000'],
            'send_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $settings = KsefSignalSettings::query()->first() ?? new KsefSignalSettings();
        $settings->enabled = (bool) $data['enabled'];
        $settings->phone = $data['phone'] ?? null;
        $settings->api_key = $data['api_key'] ?? null;
        $settings->template = $data['template'] ?: KsefSignalSettings::DEFAULT_TEMPLATE;
        $settings->send_time = $data['send_time'];
        $settings->save();

        return back()->with('success', 'Ustawienia powiadomień Signal zapisane.');
    }

    /** Test wysyłki — używa wartości z formularza (bez zapisu); zwraca treść i wynik. */
    public function sendSignalTest(Request $request, DuePaymentsService $due, SignalSender $sender): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'api_key' => ['nullable', 'string', 'max:128'],
            'template' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = KsefSignalSettings::current();
        $settings->phone = $data['phone'] ?? $settings->phone;
        $settings->api_key = $data['api_key'] ?? $settings->api_key;

        $message = $due->renderTemplate($data['template'] ?: ($settings->template ?: KsefSignalSettings::DEFAULT_TEMPLATE));
        $result = $sender->send($message, $settings);

        return response()->json([
            'ok' => $result['ok'],
            'error' => $result['error'],
            'message' => $message,
        ]);
    }

    /**
     * "Zaciągnij wszystko" — REALNE pobranie metadanych FV z KSeF 2.0 (auth tokenem).
     * Zaciąga Data / Nr / Kontrahent / Kwota za wybrany zakres. Status „opłacone" i
     * kategoria są NASZE — przy ponownym imporcie ich nie nadpisujemy.
     */
    public function import(Request $request, string $company): RedirectResponse
    {
        abort_unless(isset(self::COMPANIES[$company]), 404);

        $settings = KsefSettings::where('company', $company)->first();
        if (! $settings || ! $settings->hasToken() || empty($settings->nip)) {
            return back()->with('error', 'Brak poświadczeń KSeF (token/NIP) — uzupełnij w Argo Connect → Integracje · KSEF.');
        }

        // Subject2 = nabywca (zakupowe/kosztowe, domyślne); Subject1 = sprzedawca (sprzedażowe).
        $subjectType = in_array($request->input('subject_type'), ['Subject1', 'Subject2'], true)
            ? $request->input('subject_type')
            : 'Subject2';

        [$from, $to] = $this->buildDateRange($request);

        @set_time_limit(0); // eksport async + parsowanie paczki może chwilę potrwać

        try {
            $invoices = (new KsefClient($settings))->exportInvoices($from, $to, $subjectType);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Błąd KSeF: ' . $e->getMessage());
        }

        $count = 0;
        foreach ($invoices as $inv) {
            $ksefNumber = $inv['ksef_ref'] ?? null;
            if (! $ksefNumber) {
                continue;
            }

            $contractor = $subjectType === 'Subject2'
                ? ($inv['seller']['name'] ?? $inv['seller']['nip'] ?? null)
                : ($inv['buyer']['name'] ?? $inv['buyer']['nip'] ?? null);

            $row = KsefInvoice::firstOrNew(['company' => $company, 'ksef_ref' => $ksefNumber]);
            $row->issue_date = $inv['issue_date'] ?? null;
            $row->number = $inv['number'] ?? $ksefNumber;
            $row->contractor = $contractor;
            $row->items_text = $inv['items_text'] ?? null;     // realne pozycje (P_7) z XML
            $row->xml = $inv['xml'] ?? null;                   // pełny dokument — pod PDF
            if (! empty($inv['due_date'])) {
                $row->due_date = $inv['due_date'];             // termin płatności z XML
            }
            $row->amount = is_numeric($inv['gross'] ?? null) ? (float) $inv['gross'] : (float) ($row->amount ?? 0);
            $row->currency = $inv['currency'] ?? ($row->currency ?? 'PLN');
            $row->source = 'ksef';
            $row->imported_at = now();
            if (! $row->exists) {
                $row->status = 'unpaid'; // status płatności prowadzimy u siebie (checkbox „Opłacone")
            }
            $row->save();
            $count++;
        }

        return back()->with('success', $count > 0
            ? "Zaciągnięto z KSeF {$count} faktur (" . ($subjectType === 'Subject2' ? 'zakupowe' : 'sprzedażowe') . ').'
            : 'KSeF nie zwrócił faktur dla wybranego zakresu.');
    }

    /** Zakres dat (po dacie wystawienia) z filtrów modala importu; górne ograniczenie = dziś. */
    private function buildDateRange(Request $request): array
    {
        $year = $request->input('year');
        $month = $request->input('month');
        $quarter = $request->input('quarter');
        $y = ($year && $year !== 'all') ? (int) $year : (int) now()->year;

        if ($month && $month !== 'all') {
            $from = Carbon::create($y, (int) $month, 1)->startOfMonth();
            $to = (clone $from)->endOfMonth();
        } elseif ($quarter && $quarter !== 'all') {
            $q = (int) $quarter;
            $from = Carbon::create($y, ($q - 1) * 3 + 1, 1)->startOfMonth();
            $to = (clone $from)->addMonths(2)->endOfMonth();
        } else {
            $from = Carbon::create($y, 1, 1)->startOfDay();
            $to = Carbon::create($y, 12, 31)->endOfDay();
        }

        $now = now();
        if ($to->greaterThan($now)) {
            $to = $now->copy();
        }

        return [
            new \DateTimeImmutable($from->format('Y-m-d\TH:i:s'), new \DateTimeZone('UTC')),
            new \DateTimeImmutable($to->format('Y-m-d\TH:i:s'), new \DateTimeZone('UTC')),
        ];
    }

    /**
     * Otwarcie PDF faktury (klik w kolumnie PDF) — REALNY dokument z KSeF.
     * Pobiera pełny XML po numerze KSeF, parsuje i renderuje czytelny PDF
     * (numer, strony, daty, pozycje, kwota). Oficjalna wizualizacja KSeF (1:1)
     * wymaga osobnego generatora — to uproszczony, prawdziwy podgląd danych faktury.
     */
    public function pdf(KsefInvoice $ksefInvoice): StreamedResponse
    {
        abort_unless(isset(self::COMPANIES[$ksefInvoice->company]), 404);

        // Najpierw zapisany XML (bez odpytywania KSeF); fallback: jednorazowe pobranie.
        $xml = $ksefInvoice->xml;
        if (empty($xml) && $ksefInvoice->ksef_ref) {
            try {
                $settings = KsefSettings::where('company', $ksefInvoice->company)->first();
                $xml = (new KsefClient($settings))->downloadInvoiceXml($ksefInvoice->ksef_ref);
            } catch (\Throwable $e) {
                $xml = null;
            }
        }

        $lines = $xml
            ? $this->pdfLines(KsefInvoiceParser::parse($xml), $ksefInvoice)
            : ['Brak dokumentu XML faktury (zaciagnij ponownie z KSeF).', 'Nr: ' . $ksefInvoice->number];

        $pdf = $this->buildPdf($lines);

        return response()->streamDownload(
            fn () => print($pdf),
            'FV-' . str_replace(['/', '\\'], '-', $ksefInvoice->number) . '.pdf',
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="FV.pdf"'],
        );
    }

    /** Buduje wiersze tekstu PDF z rozparsowanej faktury (fallback na dane z rekordu). */
    private function pdfLines(array $p, KsefInvoice $row): array
    {
        $lines = [];
        $lines[] = 'FAKTURA   ' . ($p['number'] ?? $row->number);
        $lines[] = 'Data wystawienia: ' . ($p['issue_date'] ?? optional($row->issue_date)->format('Y-m-d') ?? '-')
            . '    Termin platnosci: ' . ($p['due_date'] ?? optional($row->due_date)->format('Y-m-d') ?? '-');
        $lines[] = '';
        $lines[] = 'Sprzedawca: ' . ($p['seller']['name'] ?? ($row->contractor ?? '-')) . '   NIP ' . ($p['seller']['nip'] ?? '-');
        $lines[] = 'Nabywca:    ' . ($p['buyer']['name'] ?? '-') . '   NIP ' . ($p['buyer']['nip'] ?? '-');
        $lines[] = '';
        $lines[] = 'Pozycje:';
        foreach (array_slice($p['items'] ?? [], 0, 40) as $it) {
            $line = '  - ' . mb_substr((string) $it['name'], 0, 95);
            if (! empty($it['qty'])) {
                $line .= '   x' . $it['qty'];
            }
            if (! empty($it['net'])) {
                $line .= '   netto ' . $it['net'];
            }
            $lines[] = $line;
        }
        if (empty($p['items'])) {
            $lines[] = '  (brak pozycji w dokumencie)';
        }
        $lines[] = '';
        $lines[] = 'Razem brutto: ' . ($p['gross'] ?? number_format((float) $row->amount, 2, '.', '')) . ' ' . ($p['currency'] ?? $row->currency);

        return $lines;
    }

    /** Poprawny 1-stronicowy PDF o wysokości dopasowanej do liczby wierszy. Polskie znaki → ASCII. */
    private function buildPdf(array $lines): string
    {
        $lineHeight = 15;
        $height = max(220, count($lines) * $lineHeight + 70);
        $width = 620;

        $content = "BT /F1 10 Tf 40 " . ($height - 40) . " Td {$lineHeight} TL ";
        $first = true;
        foreach ($lines as $ln) {
            $ln = $this->pl2ascii((string) $ln);
            $ln = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ln);
            $content .= ($first ? '' : 'T* ') . "({$ln}) Tj ";
            $first = false;
        }
        $content .= 'ET';

        $objects = [
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => "<</Type/Page/Parent 2 0 R/MediaBox[0 0 {$width} {$height}]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>",
            4 => '<</Length ' . strlen($content) . ">>\nstream\n" . $content . "\nendstream",
            5 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<</Size " . $count . "/Root 1 0 R>>\nstartxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }

    /** Transliteracja PL → ASCII (Helvetica nie ma polskich znaków); reszta non-ASCII usuwana. */
    private function pl2ascii(string $s): string
    {
        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z',
            '„' => '"', '”' => '"', '–' => '-', '—' => '-', '×' => 'x', '€' => 'EUR', ' ' => ' ',
        ];

        return preg_replace('/[^\x20-\x7E]/u', '', strtr($s, $map)) ?? '';
    }
}
