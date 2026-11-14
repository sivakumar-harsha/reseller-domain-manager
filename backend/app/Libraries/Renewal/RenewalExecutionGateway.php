<?php

declare(strict_types=1);

namespace App\Libraries\Renewal;

/**
 * The ONLY object RenewalExecutionController holds. It exposes a single
 * method, so the controller cannot reach processItem(), processBatch(),
 * executeForUser() or the provider client. Everything it forwards is
 * validated, ownership-checked, confirmed and preflighted by
 * RenewalExecutionService::executeItemForUser().
 */
final class RenewalExecutionGateway
{
    public function __construct(private readonly RenewalExecutionService $service)
    {
    }

    /**
     * @param array{domain_name: string, total_amount: string, currency: string, years: int, invoice_option: string} $confirmation
     *
     * @return array{batch_status: string, item: RenewalItemOutcome, preflight: array<string, mixed>|null}
     *
     * @throws RenewalException
     */
    public function renewItem(int $userId, int $batchId, int $itemId, array $confirmation): array
    {
        return $this->service->executeItemForUser($userId, $batchId, $itemId, $confirmation);
    }
}
