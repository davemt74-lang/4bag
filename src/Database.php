<?php

declare(strict_types=1);

namespace FourBag;

use PDO;
use PDOException;

final class Database
{
    public static function connect(): PDO
    {
        $dsn = getenv('FOURBAG_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=fourbag;charset=utf8mb4';
        $user = getenv('FOURBAG_DB_USER') ?: 'fourbag';
        $password = getenv('FOURBAG_DB_PASSWORD') ?: '';

        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('FourBag database connection failed.', (int) $e->getCode(), $e);
        }
    }
}
