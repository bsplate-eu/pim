<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationSource extends Model
{
    use HasFactory;

    /**
     * Tabela powiązana z modelem.
     *
     * @var string
     */
    protected $table = 'integration_sources';

    /**
     * Atrybuty, które można masowo przypisywać.
     *
     * @var array
     */
    protected $fillable = [
        'integration_id',
        'source_id',
        'template_id',
        'pricelist_id',
        'blog_id',
        'tax',
        'multiplier',
    ];

    /**
     * Atrybuty, które powinny być rzutowane na określone typy.
     *
     * @var array
     */
    protected $casts = [
        'tax' => 'integer',
        'multiplier' => 'decimal:2',
    ];

    /**
     * Relacja do modelu Integration.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * Relacja do modelu Source.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Relacja do modelu Template.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Relacja do modelu Pricelist.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function pricelist()
    {
        return $this->belongsTo(Pricelist::class);
    }

    // TODO: enable when Blog module exists (App\Models\Blog)
    // public function blog()
    // {
    //     return $this->belongsTo(\App\Models\Blog::class);
    // }
}
