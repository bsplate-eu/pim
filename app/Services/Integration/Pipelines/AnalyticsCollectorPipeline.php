<?php

namespace App\Services\Integration\Pipelines;

use App\Models\IntegrationAnalytic;
use App\Models\IntegrationCategory;
use App\Models\IntegrationProduct;

class AnalyticsCollectorPipeline extends AbstractConnectorPipeline
{
    public function getConnectorName(): string
    {
        return 'analytics';
    }

    public function getQueueName(): string
    {
        return 'sync-analytics';
    }

    public function shouldChainAfter(): array
    {
        return [];
    }

    protected function execute(): void
    {
        $dateFrom = now()->subDay()->format('Y-m-d');
        $dateTo   = now()->format('Y-m-d');

        $this->logInfo('Collecting analytics', ['from' => $dateFrom, 'to' => $dateTo]);

        try {
            $data = $this->connector->getAnalytics($dateFrom, $dateTo);
        } catch (\Throwable $e) {
            $this->logError('getAnalytics failed', ['error' => $e->getMessage()]);
            $this->run->addError('analytics', $e->getMessage());
            return;
        }

        if (empty($data)) {
            $this->logInfo('No analytics data returned');
            return;
        }

        $this->run->tick(0, count($data));
        $processed = 0;

        // Build reverse mapping: external_id → PIM entity_id
        $productMap = IntegrationProduct::query()
            ->where('integration_id', $this->integration->id)
            ->whereNotNull('external_id')
            ->pluck('product_id', 'external_id')
            ->all();

        $categoryMap = IntegrationCategory::query()
            ->where('integration_id', $this->integration->id)
            ->pluck('category_id', 'external_id')
            ->all();

        foreach ($data as $row) {
            $entityType = $row['entity_type'] ?? $row['type'] ?? 'product';
            $externalId = (string) ($row['external_id'] ?? $row['id'] ?? '');
            $date       = $row['date'] ?? $dateFrom;
            $pageViews  = (int) ($row['page_views'] ?? $row['views'] ?? 0);
            $uniqueViews = (int) ($row['unique_views'] ?? 0);

            // Resolve PIM entity ID from external ID
            $entityId = match ($entityType) {
                'product'  => $productMap[$externalId] ?? null,
                'category' => $categoryMap[$externalId] ?? null,
                default    => null,
            };

            if (!$entityId) {
                $this->run->incrementSkipped();
                $processed++;
                continue;
            }

            IntegrationAnalytic::updateOrCreate(
                [
                    'integration_id' => $this->integration->id,
                    'entity_type'    => $entityType,
                    'entity_id'      => $entityId,
                    'date'           => $date,
                ],
                [
                    'external_id'  => $externalId,
                    'page_views'   => $pageViews,
                    'unique_views' => $uniqueViews,
                ]
            );

            $this->run->incrementCreated();
            $processed++;

            if ($processed % 50 === 0) {
                $this->run->tick($processed, count($data));
            }
        }

        $this->run->tick($processed, count($data));
    }
}
