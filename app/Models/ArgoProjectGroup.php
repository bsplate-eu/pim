<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArgoProjectGroup extends Model
{
    protected $table = 'argo_project_groups';

    protected $fillable = [
        'name',
        'description',
        'icon',
        'color',
        'position',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(ArgoProject::class, 'argo_project_group_id')
            ->orderBy('position')
            ->orderBy('id');
    }
}
