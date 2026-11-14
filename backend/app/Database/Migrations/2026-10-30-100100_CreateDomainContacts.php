<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Links a domain to its contacts, one contact per role. Deleting a domain
 * removes its links; a contact that is still linked cannot be deleted.
 * The role CHECK constraint is enforced by MariaDB 10.2.1 and later.
 * All instants are stored in UTC.
 */
class CreateDomainContacts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'domain_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'rc_contact_row_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'role'              => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);

        // Literal table constraint; Forge appends it after the columns.
        $this->forge->addField("CONSTRAINT chk_domain_contacts_role CHECK (role IN ('registrant', 'admin', 'tech', 'billing'))");

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['domain_id', 'role'], 'uq_domain_contacts_domain_role');
        $this->forge->addKey('rc_contact_row_id', false, false, 'idx_domain_contacts_rc_contact_row_id');
        $this->forge->addForeignKey('domain_id', 'domains', 'id', '', 'CASCADE', 'fk_domain_contacts_domain');
        $this->forge->addForeignKey('rc_contact_row_id', 'rc_contacts', 'id', '', 'RESTRICT', 'fk_domain_contacts_rc_contact');

        $this->forge->createTable('domain_contacts', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('domain_contacts', true);
    }
}
