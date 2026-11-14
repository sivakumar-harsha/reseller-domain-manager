<?php

declare(strict_types=1);

use App\Libraries\Renewal\RenewalExecutionService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Batch status is a pure function of the item state counts. No database.
 *
 * @internal
 */
final class RenewalBatchStatusTest extends CIUnitTestCase
{
    /** @return array<string, array{0: array<string, int>, 1: string}> */
    public static function cases(): array
    {
        return [
            'nothing yet'                      => [[], 'pending'],
            'all pending'                      => [['pending' => 3], 'pending'],
            'one in flight'                    => [['submitting' => 1, 'pending' => 2], 'processing'],
            'some done, some pending'          => [['succeeded' => 1, 'pending' => 2], 'processing'],
            'a failure and pending ones'       => [['failed' => 1, 'pending' => 1], 'processing'],
            'only in flight'                   => [['submitting' => 2], 'processing'],
            'all succeeded'                    => [['succeeded' => 3], 'completed'],
            'all failed'                       => [['failed' => 2], 'failed'],
            'success and failure'              => [['succeeded' => 2, 'failed' => 1], 'partial'],
            'one uncertain only'               => [['uncertain' => 1], 'needs_review'],
            'uncertain beats partial'          => [['succeeded' => 1, 'failed' => 1, 'uncertain' => 1], 'needs_review'],
            'uncertain with successes'         => [['succeeded' => 2, 'uncertain' => 1], 'needs_review'],
            'in flight beats uncertain'        => [['uncertain' => 1, 'submitting' => 1], 'processing'],
            'zero counts are ignored'          => [['pending' => 0, 'succeeded' => 1, 'failed' => 0], 'completed'],
        ];
    }

    /**
     * @dataProvider cases
     *
     * @param array<string, int> $counts
     */
    public function testBatchStatusFromItemStates(array $counts, string $expected): void
    {
        $this->assertSame($expected, RenewalExecutionService::batchStatus($counts));
    }

    public function testEveryResultIsAnApprovedBatchStatus(): void
    {
        $allowed = ['pending', 'processing', 'completed', 'partial', 'failed', 'needs_review'];

        foreach (self::cases() as [$counts]) {
            $this->assertContains(RenewalExecutionService::batchStatus($counts), $allowed);
        }
    }
}
