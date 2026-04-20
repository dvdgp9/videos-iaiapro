<?php
declare(strict_types=1);

namespace App\Database;

use App\Support\Env;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO singleton. Reads connection settings from the .env loaded at bootstrap.
 * Configured for MariaDB/MySQL with utf8mb4, strict mode, and exceptions.
 */
final class Db
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME');
        $user = Env::get('DB_USER');
        $pass = Env::get('DB_PASS', '');

        if ($name === null || $user === null) {
            throw new RuntimeException('Missing DB_NAME / DB_USER in env');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                "SET time_zone='+00:00', sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE'",
        ];

        try {
            self::$instance = new PDO($dsn, $user, $pass, $opts);
        } catch (PDOException $e) {
            // Don't leak credentials in the error message.
            throw new RuntimeException('Database connection failed', 0, $e);
        }

        return self::$instance;
    }
}
