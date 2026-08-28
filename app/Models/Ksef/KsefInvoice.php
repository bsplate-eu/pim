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
        'issue_date',
        'number',
        'contractor',
        'bank_account',
        'bank_accounts',
        'items_text',
        'category',
        'due_date',
        'amount',
        'currency',
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
        'amount' => 'decimal:2',
        'bank_accounts' => 'array',
        'imported_at' => 'datetime',
    ];

    /** XML bywa duży — nie wystawiamy go domyślnie do JSON/Inertia. */
    protected $hidden = ['xml'];

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
