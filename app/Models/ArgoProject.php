<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArgoProject extends Model
{
    protected $table = 'argo_projects';

    protected $fillable = [
        'argo_project_group_id',
        'name',
        'description',
        'icon',
        'color',
        'position',
        'columns',
        'labels',
        'priorities',
    ];

    protected $casts = [
        'columns'    => 'array',
        'labels'     => 'array',
        'priorities' => 'array',
    ];

    public const DEFAULT_COLUMNS = [
        ['key' => 'do_zrobienia',     'name' => 'Do zrobienia',     'color' => 'gray'],
        ['key' => 'w_trakcie',        'name' => 'W trakcie',        'color' => 'blue'],
        ['key' => 'do_zatwierdzenia', 'name' => 'Do zatwierdzenia', 'color' => 'amber'],
        ['key' => 'done',             'name' => 'DONE',             'color' => 'green'],
        ['key' => 'informacje',       'name' => 'Informacje',       'color' => 'purple'],
    ];

    public const DEFAULT_LABELS = [
        ['name' => 'Oferta',     'color' => 'indigo'],
        ['name' => 'IT',         'color' => 'cyan'],
        ['name' => 'Marketing',  'color' => 'pink'],
        ['name' => 'Operacyjne', 'color' => 'orange'],
        ['name' => 'AFERA',      'color' => 'red'],
        ['name' => 'SPRZEDAŻ',   'color' => 'green'],
        ['name' => 'Finanse',    'color' => 'yellow'],
        ['name' => 'HR',         'color' => 'purple'],
    ];

    public const DEFAULT_PRIORITIES = [
        ['name' => 'CRITICAL', 'color' => 'red'],
        ['name' => 'MUST',     'color' => 'orange'],
        ['name' => 'SHOULD',   'color' => 'amber'],
        ['name' => 'COULD',    'color' => 'blue'],
        ['name' => 'WONT',     'color' => 'gray'],
    ];

    public const ALLOWED_COLORS = [
        'gray', 'blue', 'red', 'green', 'amber', 'orange',
        'yellow', 'purple', 'pink', 'indigo', 'cyan',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(ArgoTask::class, 'argo_project_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ArgoProjectGroup::class, 'argo_project_group_id');
    }

    public function columnsList(): array
    {
        return is_array($this->columns) && count($this->columns) ? $this->columns : self::DEFAULT_COLUMNS;
    }

    public function labelsList(): array
    {
        return is_array($this->labels) && count($this->labels) ? $this->labels : self::DEFAULT_LABELS;
    }

    public function prioritiesList(): array
    {
        return is_array($this->priorities) && count($this->priorities) ? $this->priorities : self::DEFAULT_PRIORITIES;
    }

    public function columnKeys(): array
    {
        return array_map(fn ($c) => $c['key'], $this->columnsList());
    }

    public function priorityNames(): array
    {
        return array_map(fn ($p) => $p['name'], $this->prioritiesList());
    }

    public function labelNames(): array
    {
        return array_map(fn ($l) => $l['name'], $this->labelsList());
    }

    public function columnsMap(): array
    {
        $out = [];
        foreach ($this->columnsList() as $c) {
            $out[$c['key']] = $c['name'];
        }
        return $out;
    }

    public function columnColorsMap(): array
    {
        $out = [];
        foreach ($this->columnsList() as $c) {
            $out[$c['key']] = $c['color'] ?? 'gray';
        }
        return $out;
    }

    public function labelColorsMap(): array
    {
        $out = [];
        foreach ($this->labelsList() as $l) {
            $out[$l['name']] = $l['color'] ?? 'gray';
        }
        return $out;
    }

    public function priorityColorsMap(): array
    {
        $out = [];
        foreach ($this->prioritiesList() as $p) {
            $out[$p['name']] = $p['color'] ?? 'gray';
        }
        return $out;
    }
}
