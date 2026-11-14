<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per submitted renewal request (a batch of domains). Audit data: it
 * is never cascaded away, so the owner row cannot be deleted while batches
 * exist. `idempotency_key` is chosen by the client and is unique per user, so
 * a repeated submission maps to the same batch. CHECK constraints are enforced
 * by MariaDB 10.2.1 and later. All instants are stored in UTC.
 */
class CreateRenewalBatches extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            // The two ResellerClub invoice-option values approved for this feature.
            'invoice_option'  => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'years'           => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true, 'null' => false],
            'status'          => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'pending'],
            'created_at'      => ['type' => 'DATETIME', 'null' => false],
            'updated_at'      => ['type' => 'DATETIME', 'null' => false],
            'finished_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);

        // Literal table constraints; Forge appends them after the columns. Forge
        // keys raw strings by their first word, so several strings starting with
        // CONSTRAINT would overwrite each other: they are passed as ONE string.
        $this->forge->addField(implode(",\n\t", [
            "CONSTRAINT chk_renewal_batches_invoice_option CHECK (invoice_option IN ('KeepInvoice', 'NoInvoice'))",
            'CONSTRAINT chk_renewal_batches_years CHECK (years BETWEEN 1 AND 10)',
            "CONSTRAINT chk_renewal_batches_status CHECK (status IN ('pending', 'processing', 'completed', 'partial', 'failed', 'needs_review'))",
            "CONSTRAINT chk_renewal_batches_idempotency_key CHECK (idempotency_key <> '')",
        ]));

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['user_id', 'idempotency_key'], 'uq_renewal_batches_user_idempotency');
        $this->forge->addKey('status', false, false, 'idx_renewal_batches_status');
        $this->forge->addKey('created_at', false, false, 'idx_renewal_batches_created_at');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'RESTRICT', 'fk_renewal_batches_user');

        $this->forge->createTable('renewal_batches', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('renewal_batches', true);
    }
}
