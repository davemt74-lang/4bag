<?php

declare(strict_types=1);

use FourBag\AuthService;
use FourBag\Database;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';

$email = trim((string)(getenv('FOURBAG_BOOTSTRAP_EMAIL') ?: ''));
$password = (string)(getenv('FOURBAG_BOOTSTRAP_PASSWORD') ?: '');
$name = trim((string)(getenv('FOURBAG_BOOTSTRAP_NAME') ?: 'FourBag Administrator'));

if ($email === '' || $password === '') {
    fwrite(STDERR, "Set FOURBAG_BOOTSTRAP_EMAIL and FOURBAG_BOOTSTRAP_PASSWORD before running this script.\n");
    exit(1);
}

$auth = new AuthService(Database::connect());
$existing = $auth->userByEmail($email);
if ($existing) {
    if ($existing['system_role'] !== 'admin') {
        fwrite(STDERR, "An account already exists for this email and is not an administrator.\n");
        exit(1);
    }
    echo "Administrator already exists: {$existing['email']}\n";
    exit(0);
}

$user = $auth->createUser($name, $email, $password, 'admin');
echo "Created FourBag administrator #{$user['id']}: {$user['email']}\n";
