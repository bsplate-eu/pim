<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupa wariantow kodu — jeden wiersz produkcji zamiast kilku.
 *
 * `trunk` to glowa wiersza. Warianty siedza w `members`; trzon celowo nie ma
 * tam wiersza, bo z definicji nalezy do grupy i nie da sie go odpiac.
 *
 * Grupa dziala dopiero w statusie `approved` — propozycja niczego nie zmienia
 * w tabeli produkcji.
 */
class ProductionGroup extends Model
{
    public const PROPOSED = 'proposed';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'production_groups';

    protected $fillable = ['trunk', 'status', 'approved_at'];

    protected $casts = ['approved_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(ProductionGroupMember::class, 'group_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
