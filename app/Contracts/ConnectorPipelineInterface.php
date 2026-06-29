<?php

namespace App\Contracts;

use App\Models\Integration;
use App\Models\IntegrationConnectorRun;

interface ConnectorPipelineInterface
{
    /**
     * Connector identifier, e.g. 'catalog_create', 'catalog_delta', 'media', 'blog', 'analytics'.
     */
    public function getConnectorName(): string;

    /**
     * Queue name for this connector's jobs.
     */
    public function getQueueName(): string;

    /**
     * Execute the connector pipeline.
     */
    public function run(Integration $integration, IntegrationConnectorRun $run): void;

    /**
     * Connector names that should be dispatched after this one completes.
     * @return string[]
     */
    public function shouldChainAfter(): array;
}
