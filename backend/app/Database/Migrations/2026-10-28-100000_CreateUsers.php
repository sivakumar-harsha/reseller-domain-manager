<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The single owner account. "Exactly one owner" is enforced by the
 * `auth:create-owner` command, not by the schema; there is no registration
 * endpoint. Email uniqueness is case-insensitive through the table collation
 * (lowercase is also applied by the application). All instants are UTC.
 */
class CreateUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 254, 'null' => false],
            // password_hash() output (bcrypt today; 255 leaves room for other algorithms).
            'password_hash'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            // Reserved for optional TOTP (decision D5); stored encrypted when used.
            'totp_secret_enc' => ['type' => 'TEXT', 'null' => true],
            'is_active'       => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'null' => false, 'default' => 1],
            'failed_logins'   => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => false, 'default' => 0],
            'locked_until'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => false],
            'updated_at'      => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('email');

        $this->forge->createTable('users', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('users', true);
    }
}
