<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Polaczenie z ARGO Bridge — singleton (jeden Bridge, jeden magazyn).
 *
 * Wiersz zasiewa migracja, wiec `current()` zawsze cos zwroci; `firstOrCreate`
 * jest tylko siatka bezpieczenstwa na wypadek recznego wyczyszczenia tabeli.
 */
class WarehouseBridge extends Model
{
    protected $table = 'warehouse_bridge';

    protected $fillable = [
        'enabled',
        'warehouse_symbol',
        'api_token',
        'last_seen_at',
        'last_sync_at',
        'last_codes',
        'bridge_version',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_codes' => 'integer',
    ];

    /**
     * Po tylu minutach ciszy Bridge przestaje sie liczyc jako aktywny.
     * Swiadomie luzno: to ma wylapac „padl/zgaszony komputer", a nie mrugniecie
     * sieci miedzy jednym a drugim pingiem.
     */
    public const SILENT_AFTER_MINUTES = 30;

    public static function current(): self
    {
        return static::firstOrCreate([], ['enabled' => false]);
    }

    /**
     * Stan polaczenia jednym slowem — ta sama logika zasila kropke w Ustawieniach
     * i decyzje po stronie API, wiec nie ma jak sie rozjechac.
     *
     * unconfigured — nie ma tokenu, nikt sie nie zaloguje
     * off          — token jest, ale polaczenie wylaczone recznie
     * never        — wlaczone, jeszcze ani jednego kontaktu
     * silent       — kontakt byl, ale dawno
     * connected    — swiezy kontakt
     */
    public function status(): string
    {
        if ($this->api_token === null) {
            return 'unconfigured';
        }

        if (! $this->enabled) {
            return 'off';
        }

        if ($this->last_seen_at === null) {
            return 'never';
        }

        return $this->last_seen_at->diffInMinutes(Carbon::now()) <= self::SILENT_AFTER_MINUTES
            ? 'connected'
            : 'silent';
    }
}
