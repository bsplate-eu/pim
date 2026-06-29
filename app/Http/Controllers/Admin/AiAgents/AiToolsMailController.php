<?php

namespace App\Http\Controllers\Admin\AiAgents;

use App\Http\Controllers\Controller;
use App\Models\Mail\Account;
use App\Models\Mail\Category;
use App\Models\Mail\Message;
use App\Services\Mail\MailAiCategorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Narzędzia AI → Mail → Administrator.
 * Auto-zarządzanie pocztą AI. Funkcja #1: auto-kategoryzacja.
 */
class AiToolsMailController extends Controller
{
    public function administrator(): Response
    {
        $accounts = Account::query()
            ->orderBy('label')
            ->get(['id', 'label', 'email', 'color', 'is_active']);

        $categories = Category::query()
            ->orderBy('sort')->orderBy('name')
            ->withCount('messages')
            ->get()
            ->map(fn (Category $c) => [
                'id'             => $c->id,
                'name'           => $c->name,
                'color'          => $c->color,
                'is_system'      => $c->is_system,
                'messages_count' => $c->messages_count,
            ]);

        return Inertia::render('AiAgents/Tools/Mail/Administrator', [
            'accounts'   => $accounts,
            'categories' => $categories,
            'stats'      => [
                'accounts'      => $accounts->count(),
                'messages'      => Message::count(),
                'unread'        => Message::where('is_read', false)->count(),
                'uncategorized' => Message::whereNull('category_id')->count(),
            ],
        ]);
    }

    /**
     * Uruchamia auto-kategoryzację AI dla partii nieskategyzowanych maili (inline).
     */
    public function categorize(Request $request, MailAiCategorizer $categorizer): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->integer('limit', 25)));

        return response()->json($categorizer->categorizeUncategorized($limit));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:60', Rule::unique('mail_categories', 'name')],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        Category::create([
            'name'      => $data['name'],
            'color'     => $data['color'] ?? '#9ca3af',
            'is_system' => false,
            'sort'      => 99,
        ]);

        return back()->with('success', 'Kategoria dodana.');
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        $category->delete(); // category_id na mailach ustawi się na NULL (nullOnDelete)

        return back()->with('success', 'Kategoria usunięta.');
    }
}
