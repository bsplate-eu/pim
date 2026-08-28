<?php

namespace App\Models\Ksef;

use Illuminate\Database\Eloquent\Model;

/**
 * Faktura z KSeF (Argo HQ → KSeF → per firma).
 * @see \App\Http\Controllers\Admin\KsefController
 */
class KsefInvoice extends Model
{
    protected $table = 'ksef_invoices';

    protected $fillable = [
        'company',
        'kind',
        'issue_date',
        'number',
        'contractor',
        'contractor_nip',
        'bank_account',
        'bank_accounts',
        'items_text',
        'category',
        'due_date',
        'period_year',
        'period_month',
        'period_quarter',
        'amount',
        'currency',
        'amount_pln',
        'fx_rate',
        'fx_date',
        'status',
        'ksef_ref',
        'pdf_path',
        'xml',
        'source',
        'imported_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'fx_date' => 'date',
        'amount' => 'decimal:2',
        'amount_pln' => 'decimal:2',
        'fx_rate' => 'decimal:6',
        'bank_accounts' => 'array',
        'imported_at' => 'datetime',
    ];

    /** XML bywa duży — nie wystawiamy go domyślnie do JSON/Inertia. */
    protected $hidden = ['xml'];

    /**
     * Typy pozycji: faktura z KSeF/ręczna + daniny publiczne wpisywane z ręki.
     * Etykieta jest zarazem prefiksem numeru pozycji („PIT-4 07/2026"), więc nie zmieniaj
     * jej w oderwaniu od danych — stare wpisy zostaną z poprzednim brzmieniem.
     */
    public const KINDS = [
        'invoice' => 'FV kosztowa',
        'rumunia' => 'Rumunia',
        'paxy' => 'PAXY',
        'zus' => 'ZUS',
        'vat' => 'VAT',
        'pit4' => 'PIT-4',
        'oss' => 'OSS',
    ];

    /** Typy o kształcie faktury (kontrahent, numer, daty) — reszta to daniny z okresem. */
    public const INVOICE_KINDS = ['invoice', 'rumunia', 'paxy'];

    /** Typy z walutą narzuconą na sztywno — użytkownik jej nie wybiera. */
    public const FIXED_CURRENCY = [
        'rumunia' => 'EUR',
        'paxy' => 'PLN',
        'oss' => 'EUR',
    ];

    /** Typy rozliczane kwartalnie (reszta miesięcznie). */
    public const QUARTERLY_KINDS = ['oss'];

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Ręcznie wpisany koszt — tylko taki wolno edytować i kasować (z KSeF przyjdzie z powrotem). */
    public function isManual(): bool
    {
        return $this->source === 'manual';
    }

    /** „07/2026" albo „Q3/2026" — okres, za jaki jest danina; null dla faktur. */
    public function periodLabel(): ?string
    {
        if (! $this->period_year) {
            return null;
        }
        if ($this->period_quarter) {
            return 'Q' . $this->period_quarter . '/' . $this->period_year;
        }
        if ($this->period_month) {
            return str_pad((string) $this->period_month, 2, '0', STR_PAD_LEFT) . '/' . $this->period_year;
        }

        return (string) $this->period_year;
    }
}
