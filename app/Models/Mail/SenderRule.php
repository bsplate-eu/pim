<?php

namespace App\Models\Mail;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reguła „nadawca → osoba/katalog" — stosowana przy synchronizacji nowych maili.
 */
class SenderRule extends Model
{
    use HasFactory;

    protected $table = 'mail_sender_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'assigned_admin_user_id' => 'integer',
        'catalog_id'             => 'integer',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_user_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class, 'catalog_id');
    }
}
