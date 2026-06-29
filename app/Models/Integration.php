<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{

    const TYPES = [
        'baselinker' => 'Baselinker',
        'prestashop' => 'Prestashop',
        'selly'      => 'Selly',
        'litecart'   => 'LiteCart',
        'opencart'   => 'OpenCart',
    ];

    protected $table = 'integrations';
    protected $fillable = ['category_id', 'type', 'manufacturer', 'name', 'key', 'url', 'sheet_id', 'enabled', 'webhook_secret'];

    /**
     * Pola wrażliwe — nie wyciekaj w JSON/toArray (Inertia, API responses).
     * Dostęp przez explicit property ($integration->key) — tak, ale nie przez serializację.
     */
    protected $hidden = ['key', 'webhook_secret'];

    protected $casts = [
        'enabled'        => 'boolean',
        'key'            => 'encrypted',
        'url'            => 'encrypted',
        'sheet_id'       => 'encrypted',
        'webhook_secret' => 'encrypted',
    ];

    /**
     * Hash HMAC-SHA256 dla webhook'ow (zastepuje przewidywalne md5("password_{id}")).
     * Uzywane przez BaselinkerController do weryfikacji auth.
     */
    public function computeWebhookHash(): string
    {
        if (empty($this->webhook_secret)) {
            return '';
        }
        return hash_hmac('sha256', "{$this->type}:{$this->id}", $this->webhook_secret);
    }

    /**
     * URL webhook'a dla user'a do skopiowania do panelu Baselinker / Selly.
     */
    public function getWebhookUrl(): string
    {
        return url("/api/{$this->type}/{$this->id}?key={$this->computeWebhookHash()}");
    }

    public function getStoragePath()
    {
        return "app/integrations/{$this->id}.csv";
    }

    public function integrationSources(): HasMany
    {
        return $this->hasMany(IntegrationSource::class, 'integration_id', 'id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(\App\Models\IntegrationSyncLog::class, 'integration_id', 'id');
    }

    public function latestSyncLog(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\IntegrationSyncLog::class, 'integration_id', 'id')->latestOfMany();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function syncIntegrationSources(array $integration_sources)
    {
        $integration_sources = collect($integration_sources);

        IntegrationSource::where('integration_id', $this->id)
            ->whereNotIn('source_id', $integration_sources->pluck('source_id')->toArray())
            ->delete();

        foreach ($integration_sources as $integration_source) {
            IntegrationSource::updateOrCreate([
                'integration_id' => $this->id,
                'source_id' => $integration_source['source_id'],
            ], [
                'template_id' => $integration_source['template_id'],
                'pricelist_id' => $integration_source['pricelist_id'],
                'tax' => $integration_source['tax'],
                'multiplier' => $integration_source['multiplier'] ?? 1,
            ]);
        }
    }

    public function addAllEnabledProducts()
    {
        $integration_sources = $this->integrationSources->keyBy('source_id');
        $integration_source_ids = $integration_sources->pluck('id')->toArray();
        $source_ids = $integration_sources->pluck('source_id')->toArray();


        $newProducts = Product::select('products.id', 'products.source_id')
            ->leftJoin('integration_products', function ($join) {
                $join->on('products.id', '=', 'integration_products.product_id')
                    ->where('integration_products.integration_id', '=', $this->id);
            })
            ->whereNull('integration_products.product_id')
            ->whereIn('source_id', $source_ids)
            ->get()
            ->map(fn($model) => [
                'integration_id' => $this->id,
                'integration_source_id' => $integration_sources->get($model->source_id)?->id,
                'product_id' => $model->id
            ])
            ->toArray();

        if (!empty($newProducts)) {
            IntegrationProduct::insert($newProducts);
        }

        IntegrationProduct::where('integration_id', $this->id)
            ->whereNull('integration_source_id')
            ->update(['integration_source_id' => $integration_sources->first()->id]);

        IntegrationProduct::where('integration_id', $this->id)
            ->whereNotIn('integration_source_id', $integration_source_ids)
            ->delete();
    }


    public function generateApiData()
    {
        if (in_array($this->type, ['selly', 'baselinker'])) {
            // Generuj webhook_secret (32-byte random) jesli nie istnieje
            if (empty($this->webhook_secret)) {
                $this->webhook_secret = \Illuminate\Support\Str::random(64);
            }
            // Klucz w URL = HMAC-SHA256(secret, "{type}:{id}") - nieprzewidywalny dla atakujacego.
            // Stary md5("password_{id}") nadal akceptowany w BaselinkerController jako legacy
            // (do usuniecia gdy wszystkie integracje zaktualizuja URL w panelach BL/Selly).
            $key = $this->computeWebhookHash();
            $this->update([
                'key' => $key,
                'url' => url("/api/{$this->type}/{$this->id}?key={$key}"),
                'webhook_secret' => $this->webhook_secret,
            ]);
        }
    }
}
