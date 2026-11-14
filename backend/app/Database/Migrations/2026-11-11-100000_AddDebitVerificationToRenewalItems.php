<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Financial (debit) verification of a renewal item, kept SEPARATE from the
 * provider renewal `state`. `state` says what ResellerClub reported about the
 * renewal action; `debit_verification_status` says whether an operator checked
 * ResellerClub's transaction history and found the matching debit. Provider
 * success never sets this column: only the manual verification action does.
 *
 * Existing rows receive the default 'unverified'. The `state` column and its
 * duplicate-cycle guard are not touched.
 */
class AddDebitVerificationToRenewalItems extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('renewal_items', [
            'debit_verification_status' => [
                'type'       => 'ENUM',
                'constraint' => ['unverified', 'confirmed', 'not_found', 'disputed'],
                'null'       => false,
                'default'    => 'unverified',
            ],
            'debit_verified_at'         => ['type' => 'DATETIME', 'null' => true],
            'debit_transaction_id'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'debit_verified_amount'     => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'debit_verified_currency'   => ['type' => 'CHAR', 'constraint' => 3, 'null' => true],
            'debit_verification_note'   => ['type' => 'TEXT', 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('renewal_items', [
            'debit_verification_note', 'debit_verified_currency', 'debit_verified_amount',
            'debit_transaction_id', 'debit_verified_at', 'debit_verification_status',
        ]);
    }
}
