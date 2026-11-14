<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Append-only audit trail of manual debit verifications: one row per accepted
 * change, with the previous and new status, the evidence recorded and the
 * operator. Rows are never updated or deleted by the application, and the
 * foreign keys are RESTRICT so the item and user cannot disappear under them.
 */
class CreateRenewalDebitVerifications extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'renewal_item_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'user_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'previous_status' => ['type' => 'ENUM', 'constraint' => ['unverified', 'confirmed', 'not_found', 'disputed'], 'null' => false],
            'new_status'      => ['type' => 'ENUM', 'constraint' => ['unverified', 'confirmed', 'not_found', 'disputed'], 'null' => false],
            'transaction_id'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'amount'          => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'currency'        => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'note'            => ['type' => 'TEXT', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['renewal_item_id', 'id'], false, false, 'idx_rdv_item');
        $this->forge->addForeignKey('renewal_item_id', 'renewal_items', 'id', '', 'RESTRICT', 'fk_rdv_item');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'RESTRICT', 'fk_rdv_user');

        $this->forge->createTable('renewal_debit_verifications', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('renewal_debit_verifications', true);
    }
}
