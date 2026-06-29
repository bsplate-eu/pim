<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostPlannerSettings extends Model
{
    protected $table = 'cost_planner_settings';

    protected $fillable = [
        'cost_names',
        'statuses',
        'categories',
        'types',
        'currencies',
    ];

    protected $casts = [
        'cost_names' => 'array',
        'statuses'   => 'array',
        'categories' => 'array',
        'types'      => 'array',
        'currencies' => 'array',
    ];

    public const ALLOWED_COLORS = [
        'gray', 'blue', 'red', 'green', 'amber', 'orange',
        'yellow', 'purple', 'pink', 'indigo', 'cyan',
    ];

    public static function instance(): self
    {
        $row = self::query()->first();
        if (!$row) {
            $row = self::create([
                'cost_names' => [],
                'statuses'   => [
                    ['name' => 'Zapłacone',  'color' => 'green'],
                    ['name' => 'Do zapłaty', 'color' => 'red'],
                ],
                'categories' => [
                    ['name' => 'Wynagrodzenia', 'color' => 'orange'],
                    ['name' => 'Operacyjne',    'color' => 'green'],
                    ['name' => 'Software',      'color' => 'blue'],
                    ['name' => 'Zadłużenie',    'color' => 'red'],
                ],
                'types' => [
                    ['name' => 'Stałe',   'color' => 'orange'],
                    ['name' => 'Zmienne', 'color' => 'blue'],
                ],
                'currencies' => ['PLN', 'EUR', 'USD', 'GBP'],
            ]);
        }
        return $row;
    }

    public function toPayload(): array
    {
        return [
            'cost_names' => $this->cost_names ?? [],
            'statuses'   => $this->statuses   ?? [],
            'categories' => $this->categories ?? [],
            'types'      => $this->types      ?? [],
            'currencies' => $this->currencies ?? [],
        ];
    }
}
