<?php

namespace App\Services\Integration\Pipelines;

use App\Contracts\ConnectorPipelineInterface;
use App\Contracts\ShopConnectorInterface;
use App\Models\Integration;
use App\Models\IntegrationConnectorRun;
use App\Services\Integration\IntegrationServiceFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

abstract class AbstractConnectorPipeline implements ConnectorPipelineInterface
{
    public const GLOBAL_STOP_CACHE_KEY = 'integrations:sync:stop-all';

    protected Integration $integration;
    protected ShopConnectorInterface $connector;
    protected IntegrationConnectorRun $run;

    protected int $batchSize;

    /**
     * Lock TTL in seconds — how long the mutex is held.
     */
    protected int $lockTtl = 900;

    public function run(Integration $integration, IntegrationConnectorRun $run): void
    {
        $this->integration = $integration;
        $this->connector   = IntegrationServiceFactory::makeConnector($integration);
        $this->run         = $run;

        $batchKey = match ($integration->type) {
            'prestashop' => 'prestashop_batch_size',
            'litecart'   => 'litecart_batch_size',
            default      => 'prestashop_batch_size',
        };
        $this->batchSize = max(1, (int) config("integrations.{$batchKey}", 10));

        $lock = Cache::lock($this->getLockKey(), $this->lockTtl);

        if (!$lock->get()) {
            $run->markFailed('Could not acquire lock — another connector is running for this integration.');
            return;
        }

        try {
            $run->markRunning();
            $this->assertNotInterrupted();
            $this->execute();
            $this->assertNotInterrupted();
            $run->markCompleted($this->buildCompletionMessage());
        } catch (\Throwable $e) {
            $run->markFailed($e->getMessage());
            Log::error("{$this->getConnectorName()} pipeline failed", [
                'integration_id' => $integration->id,
                'connector'      => $this->getConnectorName(),
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * The actual pipeline logic — implemented by each connector.
     */
    abstract protected function execute(): void;

    protected function getLockKey(): string
    {
        return "integration:{$this->integration->id}:sync";
    }

    protected function buildCompletionMessage(): string
    {
        $parts = [];
        if ($this->run->created_count > 0) $parts[] = "created: {$this->run->created_count}";
        if ($this->run->updated_count > 0) $parts[] = "updated: {$this->run->updated_count}";
        if ($this->run->skipped_count > 0) $parts[] = "skipped: {$this->run->skipped_count}";
        if ($this->run->failed_count > 0)  $parts[] = "failed: {$this->run->failed_count}";

        return $this->getConnectorName() . ' completed. ' . (implode(', ', $parts) ?: 'No items processed.');
    }

    protected function logInfo(string $message, array $context = []): void
    {
        Log::info("[{$this->getConnectorName()}] {$message}", array_merge([
            'integration_id' => $this->integration->id,
        ], $context));
    }

    protected function logWarning(string $message, array $context = []): void
    {
        Log::warning("[{$this->getConnectorName()}] {$message}", array_merge([
            'integration_id' => $this->integration->id,
        ], $context));
    }

    protected function logError(string $message, array $context = []): void
    {
        Log::error("[{$this->getConnectorName()}] {$message}", array_merge([
            'integration_id' => $this->integration->id,
        ], $context));
    }

    protected function assertNotInterrupted(): void
    {
        if (!Cache::get(self::GLOBAL_STOP_CACHE_KEY, false)) {
            return;
        }

        throw new RuntimeException('Synchronization interrupted by user.');
    }

    /**
     * Check if another run of this connector is already in progress.
     */
    public static function isRunning(int $integrationId, string $connector): bool
    {
        return IntegrationConnectorRun::query()
            ->where('integration_id', $integrationId)
            ->where('connector', $connector)
            ->where('status', 'running')
            ->exists();
    }
}
