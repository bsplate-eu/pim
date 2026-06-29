<?php

namespace App\Services\Integration;

use App\Contracts\ConnectorPipelineInterface;
use App\Contracts\ShopConnectorInterface;
use App\Models\Integration;
use App\Services\Integration\Connectors\LiteCartConnector;
use App\Services\Integration\Connectors\OpencartConnector;
use App\Services\Integration\Connectors\PrestashopConnector;
use App\Services\Integration\Pipelines\AnalyticsCollectorPipeline;
use App\Services\Integration\Pipelines\BlogSyncPipeline;
use App\Services\Integration\Pipelines\CatalogCreatePipeline;
use App\Services\Integration\Pipelines\CatalogDeltaPipeline;
use App\Services\Integration\Pipelines\MediaSyncPipeline;

class IntegrationServiceFactory
{
    public static function makeConnector(Integration $integration): ShopConnectorInterface
    {
        return match ($integration->type) {
            'prestashop' => new PrestashopConnector($integration),
            'litecart'   => new LiteCartConnector($integration),
            'opencart'   => new OpencartConnector($integration),
            default      => throw new \InvalidArgumentException("Unsupported integration type: {$integration->type}"),
        };
    }

    /**
     * @deprecated Use makePipeline() for new connector architecture.
     */
    public static function makeSyncService(Integration $integration): SyncService
    {
        $connector = self::makeConnector($integration);
        return new SyncService($integration, $connector);
    }

    public static function makePipeline(string $connector): ConnectorPipelineInterface
    {
        return match ($connector) {
            'catalog_create' => new CatalogCreatePipeline(),
            'catalog_delta'  => new CatalogDeltaPipeline(),
            'media'          => new MediaSyncPipeline(),
            'blog'           => new BlogSyncPipeline(),
            'analytics'      => new AnalyticsCollectorPipeline(),
            default          => throw new \InvalidArgumentException("Unknown connector: {$connector}"),
        };
    }
}
