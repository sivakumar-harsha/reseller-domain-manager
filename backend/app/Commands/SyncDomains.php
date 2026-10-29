<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\ResellerClub\ResellerClubClient;
use App\Libraries\Sync\DomainSyncService;
use App\Libraries\Sync\SyncResult;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Read-only domain inventory sync from ResellerClub. Prints counters only;
 * credentials, URLs and provider text are never printed.
 */
class SyncDomains extends BaseCommand
{
    protected $group       = 'Sync';
    protected $name        = 'sync:domains';
    protected $description = 'Synchronises the domain inventory from ResellerClub (read-only).';
    protected $usage       = 'sync:domains [--scheduled]';
    protected $options     = [
        '--scheduled' => 'Record the run as scheduled instead of manual.',
    ];

    public function run(array $params)
    {
        $scheduled = array_key_exists('scheduled', $params) || CLI::getOption('scheduled') !== null;

        $config  = config('ResellerClub');
        $service = new DomainSyncService(Database::connect(), new ResellerClubClient($config), $config);
        $result  = $service->run($scheduled ? 'scheduled' : 'manual');

        if (! $result->ran()) {
            CLI::error(match ($result->status) {
                SyncResult::ALREADY_RUNNING => 'Another sync is already running.',
                SyncResult::LOCK_ERROR      => 'The sync lock could not be taken because of a database error. Nothing was synced; see the application log.',
                SyncResult::STORAGE_ERROR   => 'The sync run could not be recorded because of a database error. Nothing was synced; see the application log.',
                default                     => 'ResellerClub is not configured (set resellerclub.* in backend/.env).',
            });

            return EXIT_ERROR;
        }

        if ($result->status === SyncResult::RECORD_FAILED) {
            CLI::error(sprintf('Run %d finished as "%s" but its result could not be saved to sync_runs; the row will be marked abandoned by the next run.', $result->runId, (string) $result->outcome));
        }

        CLI::write(sprintf(
            'Run %d: %s. pages=%d seen=%d inserted=%d updated=%d unchanged=%d errors=%d warnings=%d skipped=%d flagged_missing=%d',
            $result->runId,
            $result->status,
            $result->pages,
            $result->seen,
            $result->inserted,
            $result->updated,
            $result->unchanged,
            $result->errors,
            $result->warnings,
            $result->skipped,
            $result->flaggedMissing,
        ));

        return $result->status === 'success' ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
