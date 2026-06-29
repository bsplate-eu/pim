<?php

namespace App\Models\Mail;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Użytkownik systemu wyznaczony do obsługi poczty (taby „Osoby" + kolor etykiety).
 */
class MailUser extends Model
{
    use HasFactory;

    protected $table = 'mail_users';

    protected $guarded = ['id'];

    protected $casts = [
        'admin_user_id' => 'integer',
        'sort'          => 'integer',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
