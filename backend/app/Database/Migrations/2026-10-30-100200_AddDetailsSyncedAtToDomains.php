<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Records when a domain's detail enrichment last completed successfully.
 * NULL means never enriched. It is separate from last_synced_at, which
 * belongs to the list synchronisation. The instant is stored in UTC.
 */
class AddDetailsSyncedAtToDomains extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('domains', [
            'details_synced_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('domains', 'details_synced_at');
    }
}
