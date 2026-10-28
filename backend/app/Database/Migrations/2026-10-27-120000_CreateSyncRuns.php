<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * History of read-only synchronisation runs against the provider.
 * All instants are stored in UTC.
 */
class CreateSyncRuns extends Migration
{
    public function up(): void
    {
        $counter = ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0];

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'trigger_type' => [
                'type'       => 'ENUM',
                'constraint' => ['manual', 'scheduled'],
                'null'       => false,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['running', 'success', 'partial', 'failed'],
                'null'       => false,
                'default'    => 'running',
            ],
            'started_at'    => ['type' => 'DATETIME', 'null' => false],
            'finished_at'   => ['type' => 'DATETIME', 'null' => true],
            'pages_fetched' => $counter,
            'records_seen'  => $counter,
            'inserted'      => $counter,
            'updated'       => $counter,
            'unchanged'     => $counter,
            'error_count'   => $counter,
            // Sanitised summary only: never credentials, tokens or full URLs.
            'error_summary' => ['type' => 'TEXT', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => false],
            'updated_at'    => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('started_at');
        $this->forge->addKey('status');

        $this->forge->createTable('sync_runs', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('sync_runs', true);
    }
}
