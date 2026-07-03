<?php
declare(strict_types=1);

namespace FastCrud;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $connection = null;

    /**
     * Retrieve a shared PDO connection using the stored CrudConfig settings.
     */
    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config = CrudConfig::getDbConfig();
        $dsn = self::buildDsn($config);

        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            throw new InvalidArgumentException('Database options must be provided as an array.');
        }

        $options = array_replace($defaultOptions, $options);

        try {
            self::$connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to establish PDO connection.', 0, $exception);
        }

        return self::$connection;
    }

    /**
     * Manually inject a PDO instance, e.g. for testing.
     */
    public static function setConnection(PDO $connection): void
    {
        self::$connection = $connection;
    }

    /**
     * Forget any previously stored PDO instance.
     */
    public static function disconnect(): void
    {
        self::$connection = null;
    }

    public static function ensureAuditLogTable(?PDO $connection = null): void
    {
        $connection ??= self::connection();

        $driver = (string) $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $schema = match ($driver) {
            'mysql' => [
                'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'VARCHAR(255)',
                'LONGTEXT',
                'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ],
            'pgsql' => [
                'id BIGSERIAL PRIMARY KEY',
                'VARCHAR(255)',
                'TEXT',
                'TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ],
            'sqlite' => [
                'id INTEGER PRIMARY KEY AUTOINCREMENT',
                'TEXT',
                'TEXT',
                'TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ],
            default => throw new InvalidArgumentException("Unsupported PDO driver: {$driver}"),
        };

        [$idColumn, $shortTextType, $longTextType, $createdAtType] = $schema;
        $actionType  = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(50)';
        $ipType      = $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(45)';
        $tableExtras = $driver === 'mysql'
            ? ",\n                INDEX fastcrud_audit_lookup (table_name, record_id),\n                INDEX fastcrud_audit_created_at (created_at)"
            : '';
        $indexes     = $driver === 'mysql' ? [] : [
            'CREATE INDEX IF NOT EXISTS fastcrud_audit_lookup ON fastcrud_audit_logs (table_name, record_id)',
            'CREATE INDEX IF NOT EXISTS fastcrud_audit_created_at ON fastcrud_audit_logs (created_at)',
        ];

        $connection->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS fastcrud_audit_logs (
                %s,
                table_name %s NOT NULL,
                record_id %s NULL,
                action %s NOT NULL,
                field_name %s NULL,
                old_value %s NULL,
                new_value %s NULL,
                user_id %s NULL,
                ip_address %s NULL,
                metadata %s NULL,
                created_at %s%s
            )',
            $idColumn,
            $shortTextType,
            $shortTextType,
            $actionType,
            $shortTextType,
            $longTextType,
            $longTextType,
            $shortTextType,
            $ipType,
            $longTextType,
            $createdAtType,
            $tableExtras
        ));

        foreach ($indexes as $sql) {
            $connection->exec($sql);
        }
    }

    private static function buildDsn(array $config): string
    {
        $driver = $config['driver'] ?? 'mysql';

        return match ($driver) {
            'mysql' => self::buildMysqlDsn($config),
            'pgsql' => self::buildPgsqlDsn($config),
            'sqlite' => self::buildSqliteDsn($config),
            default => throw new InvalidArgumentException("Unsupported PDO driver: {$driver}"),
        };
    }

    private static function buildMysqlDsn(array $config): string
    {
        $database = $config['database'] ?? null;
        if ($database === null || $database === '') {
            throw new InvalidArgumentException('A database name is required for the mysql driver.');
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = isset($config['port']) ? (int) $config['port'] : 3306;
        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $database);

        if ($charset !== null && $charset !== '') {
            $dsn .= ';charset=' . $charset;
        }

        return $dsn;
    }

    private static function buildPgsqlDsn(array $config): string
    {
        $database = $config['database'] ?? null;
        if ($database === null || $database === '') {
            throw new InvalidArgumentException('A database name is required for the pgsql driver.');
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = isset($config['port']) ? (int) $config['port'] : 5432;

        return sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
    }

    private static function buildSqliteDsn(array $config): string
    {
        $database = $config['database'] ?? null;
        if ($database === null || $database === '') {
            throw new InvalidArgumentException('A database path or :memory: value is required for the sqlite driver.');
        }

        if ($database === ':memory:') {
            return 'sqlite::memory:';
        }

        return 'sqlite:' . $database;
    }

}
