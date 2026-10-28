<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Creates the single owner account. This is the only way an account is
 * created; there is no registration endpoint.
 *
 * The password is never accepted as a command-line argument (it would land in
 * shell history and process listings). It is read from the environment
 * variable RDM_OWNER_PASSWORD or, if that is unset, typed at a prompt. The
 * command never prints it.
 */
class CreateOwner extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'auth:create-owner';
    protected $description = 'Creates the single owner account (refuses if any user already exists).';
    protected $usage       = 'auth:create-owner --email <address>';
    protected $options     = [
        '--email' => 'Login email address of the owner.',
    ];

    public function run(array $params)
    {
        $email = $params['email'] ?? CLI::getOption('email');

        if (! is_string($email) || $email === '') {
            CLI::error('Missing --email.');

            return EXIT_ERROR;
        }

        $email = mb_strtolower(trim($email));

        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            CLI::error('The email address is not valid.');

            return EXIT_ERROR;
        }

        $db = Database::connect();

        if ($db->table('users')->countAllResults() > 0) {
            CLI::error('An account already exists. This command creates the owner only once.');

            return EXIT_ERROR;
        }

        $password = getenv('RDM_OWNER_PASSWORD');

        if ($password === false || $password === '') {
            CLI::write('RDM_OWNER_PASSWORD is not set. The password you type below will be visible on screen.', 'yellow');
            $password = CLI::prompt('Owner password');
        }

        /** @var \Config\Auth $config */
        $config = config('Auth');
        $length = strlen((string) $password);

        if ($length < $config->minPasswordLength || $length > $config->maxPasswordLength) {
            CLI::error(sprintf('The password must be %d to %d bytes long.', $config->minPasswordLength, $config->maxPasswordLength));

            return EXIT_ERROR;
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $db->table('users')->insert([
            'email'         => $email,
            'password_hash' => password_hash((string) $password, PASSWORD_DEFAULT),
            'is_active'     => 1,
            'failed_logins' => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        CLI::write('Owner account created for ' . $email . '.', 'green');

        return EXIT_SUCCESS;
    }
}
