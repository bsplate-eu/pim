<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Dziennik magazynu: kto, gdzie, co i z czego na co.
 *
 * Wpisy powstaja wylacznie przez `write()` — jedno wejscie znaczy, ze zadna
 * akcja nie zapisze sie „po swojemu", z inna nazwa obszaru albo bez autora.
 */
class WarehouseLog extends Model
{
    protected $table = 'warehouse_logs';

    /** Logi sie nie zmieniaja — jest tylko moment powstania. */
    public const UPDATED_AT = null;

    /** Ekrany, z ktorych moga przychodzic akcje. Klucz => etykieta na liscie. */
    public const AREAS = [
        'm3r' => 'Magazyn M3R',
        'tabela' => 'Tabela',
        'ustawienia' => 'Ustawienia',
        'mobile' => 'Aplikacja',
        'import' => 'Import arkusza',
        'bridge' => 'ARGO Bridge',
    ];

    protected $fillable = [
        'user_id', 'user_name', 'area', 'action',
        'source_code', 'product_code', 'description', 'meta', 'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Zapisuje jedno zdarzenie. Autora bierzemy z sesji, a gdy jej nie ma
     * (komenda z konsoli, paczka z Bridge'a) — z `$actor`, zeby dziennik nie
     * mial dziur tam, gdzie dzialal automat.
     */
    public static function write(
        string $area,
        string $action,
        string $description,
        array $context = [],
        ?string $actor = null,
    ): self {
        $user = Auth::user();

        return static::create([
            'user_id' => $user?->id,
            'user_name' => static::actorName($user) ?? $actor ?? 'system',
            'area' => $area,
            'action' => $action,
            'source_code' => $context['source_code'] ?? null,
            'product_code' => $context['product_code'] ?? null,
            'description' => mb_substr($description, 0, 500),
            'meta' => $context['meta'] ?? null,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Imie i nazwisko zalogowanego. Panel loguje `AdminUser`, ktory nie ma pola
     * `name` — sklada sie je z `first_name`/`last_name`. Gdy i tego brak,
     * zostaje e-mail: log bez autora jest wart tyle co brak logu.
     */
    public static function actorName(mixed $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : ($user->name ?? $user->email ?? null);
    }
}
