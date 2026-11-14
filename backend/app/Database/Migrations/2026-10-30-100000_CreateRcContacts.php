<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ResellerClub contacts: one row per contact, identified by the provider's
 * customer id and contact id together. Contact ids are not assumed to be
 * globally unique.
 *
 * Contact details are stored as plain text (approved decision); access is
 * controlled by database permissions and the authenticated API, not by
 * field-level encryption. Only the approved fields are stored, never raw
 * provider responses. All instants are stored in UTC.
 */
class CreateRcContacts extends Migration
{
    public function up(): void
    {
        $text = ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true];

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'rc_customer_id'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'rc_contact_id'    => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'name'             => $text,
            'company'          => $text,
            'email'            => $text,
            'tel_country_code' => $text,
            'tel_number'       => $text,
            'address1'         => $text,
            'address2'         => $text,
            'address3'         => $text,
            'city'             => $text,
            'state'            => $text,
            'country'          => $text,
            'postal_code'      => $text,
            'first_seen_at'    => ['type' => 'DATETIME', 'null' => false],
            'last_synced_at'   => ['type' => 'DATETIME', 'null' => false],
            'created_at'       => ['type' => 'DATETIME', 'null' => false],
            'updated_at'       => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['rc_customer_id', 'rc_contact_id'], 'uq_rc_contacts_customer_contact');

        $this->forge->createTable('rc_contacts', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('rc_contacts', true);
    }
}
