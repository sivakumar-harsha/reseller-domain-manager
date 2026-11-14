<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

/**
 * The ONLY renewal object handed to the batch API controller. It exposes
 * nothing but local batch creation and the replay lookup: no processItem(),
 * no processBatch(), no executeForUser(), so the controller cannot reach
 * renewal execution even by mistake. Pure delegation, no logic of its own.
 */
final class RenewalBatchGateway
{
    public function __construct(private readonly RenewalExecutionService $service)
    {
    }

    /**
     * Stores a batch of LOCAL pending records. Never renews, invoices or
     * charges anything. See RenewalExecutionService::createBatch().
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return array{batch_id: int, replayed: bool}
     *
     * @throws RenewalException
     */
    public function createBatch(int $userId, string $idempotencyKey, string $invoiceOption, int $years, array $lines): array
    {
        return $this->service->createBatch($userId, $idempotencyKey, $invoiceOption, $years, $lines);
    }

    /**
     * Read-only: the user's own active batch id per domain. See RenewalExecutionService::findActiveBatchIds().
     *
     * @param list<int> $domainIds
     *
     * @return array<int, int>
     *
     * @throws RenewalException
     */
    public function findActiveBatchIds(int $userId, array $domainIds): array
    {
        return $this->service->findActiveBatchIds($userId, $domainIds);
    }

    /**
     * Read-only replay lookup. See RenewalExecutionService::findExistingBatch().
     *
     * @param list<int> $domainIds
     *
     * @throws RenewalException
     */
    public function findExistingBatch(int $userId, string $idempotencyKey, string $invoiceOption, int $years, array $domainIds): ?int
    {
        return $this->service->findExistingBatch($userId, $idempotencyKey, $invoiceOption, $years, $domainIds);
    }
}
