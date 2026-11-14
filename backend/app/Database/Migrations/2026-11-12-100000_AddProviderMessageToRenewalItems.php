<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Keeps ResellerClub's own explanation of a renewal result (its error message
 * or action description, sanitised and capped) next to the fixed `error_code`,
 * so the app can show the real provider response instead of only a code.
 * Existing rows receive NULL. No other column or constraint is touched.
 */
class AddProviderMessageToRenewalItems extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('renewal_items', [
            'provider_message' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'error_code'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('renewal_items', 'provider_message');
    }
}
