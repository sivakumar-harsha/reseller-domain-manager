<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per issued refresh token, together with the access token issued in
 * the same exchange. Only SHA-256 hashes (hex) of the random tokens are
 * stored, never the tokens themselves.
 *
 * A row belongs to a token family (one login). Refreshing marks the row
 * rotated and inserts a successor in the same family; presenting a rotated
 * token again revokes the whole family. All instants are UTC.
 */
class CreateRefreshTokens extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'token_hash'         => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'family_id'          => ['type' => 'CHAR', 'constraint' => 32, 'null' => false],
            'device_label'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'access_token_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'access_expires_at'  => ['type' => 'DATETIME', 'null' => false],
            'expires_at'         => ['type' => 'DATETIME', 'null' => false],
            // Set when this token has been exchanged for a successor.
            'rotated_at'         => ['type' => 'DATETIME', 'null' => true],
            'revoked_at'         => ['type' => 'DATETIME', 'null' => true],
            'last_used_at'       => ['type' => 'DATETIME', 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addUniqueKey('access_token_hash');
        $this->forge->addKey('family_id');
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('refresh_tokens', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('refresh_tokens', true);
    }
}
