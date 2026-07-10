<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TranslationLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dziennik automatycznych tłumaczeń (Tłumaczenia → Logi).
 *
 * Pokazuje, co composer zrobił z każdym produktem: status (OK / brak dopasowania / błąd),
 * a w szczegółach — z jakiego języka na jaki i jaka nazwa PRZED→PO (kolumna `changes`).
 */
class TranslationLogController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->get('search', ''));
        $status = (string) $request->get('status', '');

        $query = TranslationLog::query()->latest('id');

        if (in_array($status, [
            TranslationLog::STATUS_OK,
            TranslationLog::STATUS_UNMATCHED,
            TranslationLog::STATUS_SKIPPED,
            TranslationLog::STATUS_ERROR,
        ], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            foreach (preg_split('/\s+/', $search) as $word) {
                $like = '%' . $word . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('external_id', 'like', $like)
                      ->orWhere('product_code', 'like', $like)
                      ->orWhere('name_pl', 'like', $like);
                });
            }
        }

        $perPage = (int) $request->get('per_page', 50);
        $logs = $query->paginate($perPage)->withQueryString();

        $payload = $logs->toArray();
        $payload['data'] = collect($logs->items())->map(fn (TranslationLog $log) => [
            'id'           => $log->id,
            'product_id'   => $log->product_id,
            'external_id'  => $log->external_id,
            'product_code' => $log->product_code,
            'name_pl'      => $log->name_pl,
            'status'       => $log->status,
            'matched'      => $log->matched,
            'source_locale' => $log->source_locale,
            'changes'      => $log->changes ?? [],
            'stats'        => $log->stats ?? [],
            'message'      => $log->message,
            'context'      => $log->context,
            'created_at'   => optional($log->created_at)->format('Y-m-d H:i:s'),
        ]);

        // Liczniki dla pigułek filtra.
        $counts = [
            'all'       => (int) TranslationLog::count(),
            'ok'        => (int) TranslationLog::where('status', TranslationLog::STATUS_OK)->count(),
            'unmatched' => (int) TranslationLog::where('status', TranslationLog::STATUS_UNMATCHED)->count(),
            'skipped'   => (int) TranslationLog::where('status', TranslationLog::STATUS_SKIPPED)->count(),
            'error'     => (int) TranslationLog::where('status', TranslationLog::STATUS_ERROR)->count(),
        ];

        return Inertia::render('TranslationLog/Index', [
            'logs'   => $payload,
            'search' => $search,
            'status' => $status,
            'counts' => $counts,
        ]);
    }
}
