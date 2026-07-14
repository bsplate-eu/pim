<?php

namespace App\Services\Integration\Pipelines;

use App\Models\IntegrationEntityState;
use App\Models\IntegrationMediaQueueItem;
use App\Models\IntegrationProduct;
use App\Models\Product;
use Illuminate\Support\Collection;

class MediaSyncPipeline extends AbstractConnectorPipeline
{
    public function getConnectorName(): string
    {
        return 'media';
    }

    public function getQueueName(): string
    {
        return 'sync-media';
    }

    public function shouldChainAfter(): array
    {
        return [];
    }

    protected function execute(): void
    {
        // Media-delta: wykryj zmiany zdjęć (dodane/usunięte/przestawione) w już
        // zsynchronizowanych produktach i zbuduj dla nich kolejkę. Bez tego kroku
        // kolejkę napełniał wyłącznie CatalogCreate (tylko nowe produkty).
        $this->enqueueChangedMedia();

        $pendingItems = IntegrationMediaQueueItem::query()
            ->where('integration_id', $this->integration->id)
            ->where('state', IntegrationMediaQueueItem::STATE_PENDING)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();

        if ($pendingItems->isEmpty()) {
            $this->logInfo('No pending media items');
            return;
        }

        $this->run->tick(0, $pendingItems->count());

        // Group by product
        $grouped = $pendingItems->groupBy('product_id');
        $processed = 0;

        foreach ($grouped as $productId => $items) {
            $this->assertNotInterrupted();
            $externalProductId = $items->first()->external_product_id;

            // If product hasn't been created in remote shop yet, skip
            if (!$externalProductId) {
                $externalProductId = $this->resolveExternalProductId($productId);
                if (!$externalProductId) {
                    $this->logWarning('Product not yet created in shop — deferring media', ['product_id' => $productId]);
                    foreach ($items as $item) {
                        $this->assertNotInterrupted();
                        if ($item->attempts >= 3) {
                            $item->markFailed('Product not created in remote shop after 3 deferrals');
                            $this->run->incrementFailed();
                        } else {
                            $item->update(['attempts' => $item->attempts + 1]);
                            $this->run->incrementSkipped();
                        }
                        $processed++;
                    }
                    $this->run->tick($processed, $pendingItems->count());
                    continue;
                }

                // Update external_product_id for all items of this product
                IntegrationMediaQueueItem::query()
                    ->where('integration_id', $this->integration->id)
                    ->where('product_id', $productId)
                    ->whereNull('external_product_id')
                    ->update(['external_product_id' => $externalProductId]);
            }

            $this->processProductMedia($productId, $externalProductId, $items, $processed, $pendingItems->count());
            $processed += $items->count();
            $this->run->tick($processed, $pendingItems->count());
        }
    }

    /**
     * Media-delta: dla każdego zsynchronizowanego produktu policz sygnaturę aktualnej
     * galerii i porównaj z ostatnio zsynchronizowaną (IntegrationEntityState, connector=media).
     * Gdy się różni (dodano/usunięto/przestawiono/podmieniono zdjęcie) — przebuduj kolejkę
     * pełną bieżącą galerią. Connector wyśle to w trybie 'smart' (md5 + pozycja) i pogodzi sklep
     * z PIM: doda nowe, usunie brakujące, poprawi kolejność.
     */
    private function enqueueChangedMedia(): void
    {
        IntegrationProduct::query()
            ->where('integration_id', $this->integration->id)
            ->whereNotNull('external_id')
            ->where('state', '!=', IntegrationProduct::STATE_PENDING_DELETE)
            ->with('product.media')
            ->chunkById(100, function (Collection $chunk) {
                foreach ($chunk as $ip) {
                    $this->assertNotInterrupted();
                    $product = $ip->product;
                    if (!$product) {
                        continue;
                    }

                    $gallery = $this->visibleGallery($product);
                    $signature = $this->mediaSignature($gallery);

                    $state = IntegrationEntityState::firstOrNew([
                        'integration_id' => $this->integration->id,
                        'connector'      => IntegrationEntityState::CONNECTOR_MEDIA,
                        'entity_type'    => IntegrationEntityState::ENTITY_PRODUCT,
                        'entity_id'      => $product->id,
                    ]);

                    // Bez zmian względem ostatniego udanego sync — pomiń.
                    if ($state->exists
                        && $state->state === IntegrationEntityState::STATE_SYNCED
                        && $state->payload_hash === $signature) {
                        continue;
                    }

                    // Pusta galeria: nic do wysłania (tryb smart nie usuwa przy pustym zestawie).
                    // Oznacz jako zsync z bieżącą sygnaturą, żeby nie sprawdzać w kółko.
                    if ($gallery->isEmpty()) {
                        $state->fill([
                            'external_id' => (string) $ip->external_id,
                            'state'       => IntegrationEntityState::STATE_SYNCED,
                            'payload_hash'=> $signature,
                            'synced_at'   => now(),
                        ])->save();
                        continue;
                    }

                    $this->rebuildQueueForProduct($ip, $gallery);

                    $state->fill([
                        'external_id' => (string) $ip->external_id,
                        'state'       => IntegrationEntityState::STATE_PENDING,
                    ])->save();
                }
            });
    }

    /** Widoczne zdjęcia produktu (kolekcja 'images', enabled !== false), wg order_column. */
    private function visibleGallery(Product $product): Collection
    {
        return $product->getMedia('images')
            ->filter(fn ($m) => ($m->getCustomProperty('enabled') ?? true) !== false)
            ->sortBy('order_column')
            ->values();
    }

    /** Sygnatura galerii: id + kolejność + updated_at (tanio, bez czytania plików). */
    private function mediaSignature(Collection $gallery): string
    {
        $parts = $gallery->map(fn ($m) => [
            (int) $m->id,
            (int) $m->order_column,
            optional($m->updated_at)->getTimestamp(),
        ])->all();

        return hash('sha256', json_encode($parts));
    }

    /** Skasuj dotychczasową kolejkę produktu i wstaw pełną bieżącą galerię (ACTION_UPLOAD). */
    private function rebuildQueueForProduct(IntegrationProduct $ip, Collection $gallery): void
    {
        IntegrationMediaQueueItem::query()
            ->where('integration_id', $this->integration->id)
            ->where('product_id', $ip->product_id)
            ->delete();

        foreach ($gallery->values() as $idx => $media) {
            $localPath = $media->getPath();
            $md5 = (is_string($localPath) && is_file($localPath) && is_readable($localPath))
                ? md5_file($localPath)
                : md5((string) $media->uuid);

            IntegrationMediaQueueItem::create([
                'integration_id'      => $this->integration->id,
                'product_id'          => $ip->product_id,
                'media_id'            => $media->id,
                'external_product_id' => (string) $ip->external_id,
                'action'              => IntegrationMediaQueueItem::ACTION_UPLOAD,
                'priority'            => $idx,
                'source_url'          => $media->getFullUrl(),
                'md5_hash'            => $md5,
                'state'               => IntegrationMediaQueueItem::STATE_PENDING,
            ]);
        }
    }

    private function resolveExternalProductId(int $productId): ?string
    {
        return IntegrationProduct::query()
            ->where('integration_id', $this->integration->id)
            ->where('product_id', $productId)
            ->whereNotNull('external_id')
            ->value('external_id');
    }

    private function processProductMedia(int $productId, string $externalProductId, $items, int &$processed, int $total): void
    {
        // Build images array for smart sync
        $images = [];
        foreach ($items as $item) {
            if ($item->action === IntegrationMediaQueueItem::ACTION_UPLOAD) {
                $images[] = [
                    'pim_id'     => (int) $item->media_id,
                    'filename'   => basename($item->source_url ?? '') ?: "media-{$item->media_id}.jpg",
                    'priority'   => $item->priority,
                    'source_url' => $item->source_url,
                    'md5'        => $item->md5_hash,
                ];
            }
        }

        if (empty($images)) return;

        try {
            // Use importProducts with images_mode='smart' to send images
            $sku = IntegrationProduct::query()
                ->where('integration_id', $this->integration->id)
                ->where('product_id', $productId)
                ->first()
                ?->product?->product_code ?? 'pim-' . $productId;

            $result = $this->connector->importProducts([[
                'pim_id'      => (int) $productId, // OC connector identyfikuje produkt po pim_id (bez tego: "Missing pim_id")
                'sku'         => $sku,
                'external_id' => $externalProductId,
                'images_mode' => 'smart',
                'images'      => $images,
            ]]);

            $status = $result[0]['status'] ?? 'error';

            if ($status === 'ok') {
                foreach ($items as $item) {
                    $item->markSynced();
                    $this->run->incrementCreated();
                }

                // Zapisz hash stanu galerii — kolejne runy pominą produkt bez zmian.
                $product = Product::find($productId);
                if ($product) {
                    IntegrationEntityState::updateOrCreate(
                        [
                            'integration_id' => $this->integration->id,
                            'connector'      => IntegrationEntityState::CONNECTOR_MEDIA,
                            'entity_type'    => IntegrationEntityState::ENTITY_PRODUCT,
                            'entity_id'      => $productId,
                        ],
                        [
                            'external_id'  => (string) $externalProductId,
                            'state'        => IntegrationEntityState::STATE_SYNCED,
                            'payload_hash' => $this->mediaSignature($this->visibleGallery($product)),
                            'synced_at'    => now(),
                            'last_error'   => null,
                        ]
                    );
                }
            } else {
                $errMsg = $result[0]['message'] ?? 'unknown error';
                foreach ($items as $item) {
                    $item->markFailed($errMsg);
                    $this->run->incrementFailed();
                }
                $this->run->addError("media:product:{$productId}", $errMsg);
            }
        } catch (\Throwable $e) {
            $this->logError('Media sync failed for product', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
            foreach ($items as $item) {
                $item->markFailed($e->getMessage());
                $this->run->incrementFailed();
            }
            $this->run->addError("media:product:{$productId}", $e->getMessage());
        }
    }
}
