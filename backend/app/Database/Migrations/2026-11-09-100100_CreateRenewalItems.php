<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per domain in a renewal batch, with the snapshots the request was
 * made from. Audit data: foreign keys are RESTRICT, so neither the batch nor
 * the domain can be deleted while items exist.
 *
 * Duplicate-cycle guard: `active_expiry_at` is `expiry_at_request` while the
 * item is in any state except `failed`, and NULL once it has definitely
 * failed. UNIQUE(domain_id, active_expiry_at) therefore allows only one live
 * (pending, submitting, succeeded or uncertain) item per domain and expiry
 * cycle, while a definite failure frees the slot for a retry. NULLs never
 * collide in a unique index. After a successful renewal the domain's expiry
 * moves forward, so the next cycle has a different `expiry_at_request`.
 *
 * Money is DECIMAL(12,2), never floating point. CHECK constraints and the
 * generated column need MariaDB 10.2.1+ (or MySQL 8.0.16+ for the checks).
 * All instants are stored in UTC.
 */
class CreateRenewalItems extends Migration
{
    public function up(): void
    {
        $money = ['type' => 'DECIMAL', 'constraint' => '12,2', 'unsigned' => false, 'null' => false];

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'batch_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'domain_id'           => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'rc_order_id'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'expiry_at_request'   => ['type' => 'DATETIME', 'null' => false],
            'renewal_amount'      => $money,
            'gst_amount'          => $money,
            'vat_amount'          => $money,
            'total_amount'        => $money,
            'currency'            => ['type' => 'CHAR', 'constraint' => 3, 'null' => false],
            'state'               => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'pending'],
            'provider_action_id'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'provider_invoice_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'error_code'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
            'updated_at'          => ['type' => 'DATETIME', 'null' => false],
            'completed_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);

        // Literal definitions; Forge appends them after the columns above. Forge
        // keys raw strings by their first word, so the CHECK constraints are
        // passed as ONE string (several CONSTRAINT strings would overwrite each other).
        $this->forge->addField("active_expiry_at DATETIME GENERATED ALWAYS AS (CASE WHEN state = 'failed' THEN NULL ELSE expiry_at_request END) STORED");
        $this->forge->addField(implode(",\n\t", [
            "CONSTRAINT chk_renewal_items_state CHECK (state IN ('pending', 'submitting', 'succeeded', 'failed', 'uncertain'))",
            'CONSTRAINT chk_renewal_items_amounts CHECK (renewal_amount >= 0 AND gst_amount >= 0 AND vat_amount >= 0 AND total_amount = renewal_amount + gst_amount + vat_amount)',
            "CONSTRAINT chk_renewal_items_order CHECK (rc_order_id <> '')",
        ]));

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['batch_id', 'domain_id'], 'uq_renewal_items_batch_domain');
        $this->forge->addUniqueKey(['domain_id', 'active_expiry_at'], 'uq_renewal_items_domain_active_expiry');
        $this->forge->addKey('state', false, false, 'idx_renewal_items_state');
        $this->forge->addKey('rc_order_id', false, false, 'idx_renewal_items_rc_order_id');
        $this->forge->addForeignKey('batch_id', 'renewal_batches', 'id', '', 'RESTRICT', 'fk_renewal_items_batch');
        $this->forge->addForeignKey('domain_id', 'domains', 'id', '', 'RESTRICT', 'fk_renewal_items_domain');

        $this->forge->createTable('renewal_items', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('renewal_items', true);
    }
}
