<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Media\ProcessMediaTrait;
use App\Media\AutoProcessMediaTrait;
use App\Media\InteractsWithMedia;
use Illuminate\Support\Facades\Blade;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use App\Media\HasMediaPreviewsTrait;

class IntegrationProduct extends Model
{


    protected $table = 'integration_products';
    protected $fillable = ['integration_id', 'integration_source_id', 'product_id', 'external_id', 'payload_hash', 'state', 'overrides', 'synced_at'];
    protected $casts = [
        'overrides' => 'array',
    ];

    public const STATE_PENDING        = 'pending';
    public const STATE_SYNCED         = 'synced';
    public const STATE_FAILED         = 'failed';
    public const STATE_PENDING_DELETE = 'pending_delete';

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id', 'id');
    }
    public function integrationSource(): BelongsTo
    {
        return $this->belongsTo(IntegrationSource::class, 'integration_source_id', 'id');
    }


    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function getOverridedProduct(): Product
    {

        $product = $this->product;
        $overrides = $this->overrides ?? [];
        foreach ($overrides as $key => $value) {
            // null/pusty override = "brak nadpisania", NIE "wyczyść pole".
            // Bez tego override name:null zerował nazwę produktu i feed słał samą końcówkę szablonu (incydent 2026-07-02).
            if ($value === null || $value === '') {
                continue;
            }
            $product->$key = $value;
        }

        return $product;
    }

    public function getBaselinkerProduct(
        Integration       $integration,
        IntegrationSource $integrationSource,
        ?float            $price = null
    ): object
    {

        $product = $this->getOverridedProduct();

        if ($price === null) {
            $price = PricelistProduct::where('pricelist_id', $integrationSource->pricelist_id)
                ->where('product_id', $product->id)
                ->first()
                ?->price;
        }

        $category_name = $product->categories->implode('name', '/');

        $template = $integrationSource->template;
        $title = $template->getRenderedTitle($product);
        $description = $template->getRenderedDescription($product);

        return (object)[
            'id' => $product->external_id,
            'name' => $title,
            'sku' => $product->product_code,
            'ean' => $product->ean ?? '',
            'description' => $description,
            'quantity' => 100,
            'man_name' => $integration->manufacturer,
            'category_id' => md5($category_name),
            'category_name' => $category_name,
            'tax' => $integrationSource->tax,
            'price' => ceil($price * $integrationSource->multiplier),
            'images' => $product->getMedia('images')
                ->filter(fn ($m) => ($m->getCustomProperty('enabled') ?? true) !== false)
                ->sortBy('order_column')
                ->pluck('original_url')
                ->toArray(),
            'features' => [],
            'enabled' => $product->enabled,
        ];

    }


}
