<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cached domain inventory. ResellerClub stays the authoritative source.
 *
 * Provider-derived columns are nullable: field names and availability are
 * still to be verified against the official API documentation, and NULL
 * means "unknown", not "false". Raw provider payloads are deliberately not
 * stored (decision D7 is still open). All instants are stored in UTC.
 */
class CreateDomains extends Migration
{
    public function up(): void
    {
        $flag = ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => true];

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'rc_order_id'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'domain_name'       => ['type' => 'VARCHAR', 'constraint' => 253, 'null' => false],
            'rc_customer_id'    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'registered_at'     => ['type' => 'DATETIME', 'null' => true],
            'expires_at'        => ['type' => 'DATETIME', 'null' => true],
            'auto_renew'        => $flag,
            'privacy_enabled'   => $flag,
            'registrar_lock'    => $flag,
            'nameservers_json'  => ['type' => 'TEXT', 'null' => true],
            // SHA-256 hex of the normalised fields, to detect changes cheaply.
            'content_hash'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'first_seen_at'     => ['type' => 'DATETIME', 'null' => false],
            'last_synced_at'    => ['type' => 'DATETIME', 'null' => false],
            'last_seen_sync_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            // Set when a successful full sync no longer lists the domain.
            'is_missing'        => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 0],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('rc_order_id');
        $this->forge->addUniqueKey('domain_name');
        $this->forge->addKey('expires_at');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('last_seen_sync_id', 'sync_runs', 'id', 'CASCADE', 'SET NULL');

        $this->forge->createTable('domains', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('domains', true);
    }
}
