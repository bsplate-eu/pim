<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Http\Controllers\Admin\Controller;
use App\Models\Mail\Message;
use App\Models\Mail\SenderRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reguły kierowania maili do katalogów (zakładka „Filtry"):
 *  - ogólna reguła domenowa: from_email = "@domena.pl", bez słowa-klucza,
 *  - wykluczenie: konkretny adres "info@domena.pl" ALBO "@domena.pl" + słowo w temacie.
 * Priorytety rozstrzyga MailSyncService::resolveRouting() (wykluczenie > spam > reguła ogólna).
 */
class SenderRuleController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_email'       => ['required', 'string', 'max:255'],
            'subject_contains' => ['nullable', 'string', 'max:190'],
            'catalog_id'       => ['required', 'integer', 'exists:mail_catalogs,id'],
        ]);

        $value = mb_strtolower(trim($data['from_email']));
        $isDomain = str_starts_with($value, '@');
        $valid = $isDomain
            ? (bool) preg_match('/^@[^@\s]+\.[^@\s]+$/', $value)
            : filter_var($value, FILTER_VALIDATE_EMAIL) !== false;

        if (! $valid) {
            return back()->withErrors(['from_email' => 'Podaj poprawny adres e-mail lub domenę w formacie @domena.pl']);
        }

        $keyword = trim((string) ($data['subject_contains'] ?? ''));

        SenderRule::updateOrCreate(
            ['from_email' => $value, 'subject_contains' => $keyword],
            ['catalog_id' => $data['catalog_id']]
        );

        // Zastosuj regułę WSTECZ do istniejących maili (spójnie z priorytetami silnika).
        $query = Message::query();
        if ($isDomain) {
            $query->whereRaw("SUBSTRING_INDEX(LOWER(from_email), '@', -1) = ?", [ltrim($value, '@')]);
        } else {
            $query->whereRaw('LOWER(from_email) = ?', [$value]);
        }
        if ($keyword !== '') {
            $query->whereRaw('LOWER(subject) LIKE ?', ['%'.mb_strtolower($keyword).'%']);
        }

        $update = ['catalog_id' => $data['catalog_id']];
        if (! $isDomain || $keyword !== '') {
            $update['is_spam'] = false; // wykluczenie (adres lub domena+słowo) wyciąga maile ze spamu
        }
        $count = $query->update($update);

        return back()->with('success', "Reguła zapisana — przypięto {$count} istniejących maili.");
    }

    public function destroy(SenderRule $senderRule): RedirectResponse
    {
        $senderRule->delete();

        return back()->with('success', 'Reguła usunięta.');
    }
}
