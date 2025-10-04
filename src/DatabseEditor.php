<?php
declare(strict_types=1);

namespace FastCrud;

use LogicException;
use PDO;
use PDOException;
use RuntimeException;

class DatabseEditor
{
    private static bool $initialized = false;
    /**
     * @var list<string>
     */
    private static array $messages = [];
    /**
     * @var list<string>
     */
    private static array $errors = [];
    private static bool $scriptInjected = false;
    private static bool $returnJsonResponse = false;
    private static ?string $activeTable = null;
    private static bool $downloadHandled = false;

    public static function init(?array $dbConfig = null): void
    {
        if ($dbConfig !== null) {
            CrudConfig::setDbConfig($dbConfig);
            DB::disconnect();
        }

        self::$initialized = true;
        self::maybeHandleDownloadEarly();
    }

    public static function render(bool $showHeader = true): string
    {
        if (!self::$initialized) {
            self::init();
        }

        $connection = DB::connection();
        $driver = self::detectDriver();
        self::handleRequest($connection, $driver);

        $tables = self::fetchTables($connection, $driver);
        $tableColumns = [];
        foreach ($tables as $table) {
            $tableColumns[$table] = self::fetchColumns($connection, $driver, $table);
        }

        $activeTable = self::resolveActiveTable($tables);

        return self::renderHtml($tables, $tableColumns, $driver, $activeTable, $showHeader);
    }

    private static function detectDriver(): string
    {
        $driver = CrudConfig::getDbConfig()['driver'] ?? 'mysql';
        if (!is_string($driver) || $driver === '') {
            return 'mysql';
        }

        return strtolower($driver);
    }

    private static function handleRequest(PDO $connection, string $driver): void
    {
        self::$returnJsonResponse = false;
        if ((($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }

        $action = isset($_POST['fc_db_editor_action']) ? (string) $_POST['fc_db_editor_action'] : '';
        $action = trim($action);
        if ($action === '') {
            return;
        }

        if ($action === 'download_database') {
            if (self::$downloadHandled) {
                return;
            }

            self::$downloadHandled = true;
            try {
                self::handleDownloadDatabase($connection, $driver);
            } catch (PDOException $exception) {
                self::$errors[] = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } catch (RuntimeException $exception) {
                self::$errors[] = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            return;
        }

        self::markJsonResponseIfNeeded($action);

        try {
            match ($action) {
                'add_table' => self::handleAddTable($connection, $driver),
                'rename_table' => self::handleRenameTable($connection, $driver),
                'delete_table' => self::handleDeleteTable($connection, $driver),
                'add_column' => self::handleAddColumn($connection, $driver),
                'rename_column' => self::handleRenameColumn($connection, $driver),
                'change_column_type' => self::handleChangeColumnType($connection, $driver),
                'reorder_columns' => self::handleReorderColumns($connection, $driver),
                default => null,
            };
        } catch (PDOException $exception) {
            self::$errors[] = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } catch (RuntimeException $exception) {
            self::$errors[] = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        self::respondJsonIfNeeded();
    }

    private static function maybeHandleDownloadEarly(): void
    {
        if (self::$downloadHandled) {
            return;
        }

        if ((($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }

        $action = isset($_POST['fc_db_editor_action']) ? trim((string) $_POST['fc_db_editor_action']) : '';
        if ($action !== 'download_database') {
            return;
        }

        try {
            $connection = DB::connection();
            $driver = self::detectDriver();
            self::$downloadHandled = true;
            self::handleDownloadDatabase($connection, $driver);
        } catch (PDOException $exception) {
            self::$errors[] = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } catch (RuntimeException $exception) {
            self::$errors[] = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    private static function markJsonResponseIfNeeded(string $action): void
    {
        if ($action !== 'reorder_columns') {
            return;
        }

        self::$returnJsonResponse = self::isReorderAjaxRequest();
    }

    private static function respondJsonIfNeeded(): void
    {
        if (!self::$returnJsonResponse) {
            return;
        }

        $payload = [
            'feedback' => self::renderFeedbackHtml(),
        ];

        header('Content-Type: application/json');
        echo json_encode($payload);
        self::$returnJsonResponse = false;
        self::resetFeedback();
        exit;
    }

    private static function isReorderAjaxRequest(): bool
    {
        return isset($_SERVER['HTTP_X_FASTCRUD_DB_REORDER']);
    }

    private static function handleAddTable(PDO $connection, string $driver): void
    {
        $tableName = isset($_POST['new_table']) ? trim((string) $_POST['new_table']) : '';
        if (!self::isValidIdentifier($tableName)) {
            throw new RuntimeException('Table name must contain only letters, numbers, or underscores.');
        }

        $quoted = self::quoteIdentifier($tableName, $driver);
        $sql = match ($driver) {
            'pgsql' => sprintf('CREATE TABLE %s (id SERIAL PRIMARY KEY)', $quoted),
            'sqlite' => sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY AUTOINCREMENT)', $quoted),
            default => sprintf('CREATE TABLE %s (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY)', $quoted),
        };

        $connection->exec($sql);
        self::$messages[] = sprintf('Table "%s" created.', $tableName);
        self::$activeTable = $tableName;
    }

    private static function handleRenameTable(PDO $connection, string $driver): void
    {
        $current = isset($_POST['current_table']) ? trim((string) $_POST['current_table']) : '';
        $new = isset($_POST['new_table_name']) ? trim((string) $_POST['new_table_name']) : '';

        if (!self::isValidIdentifier($current) || !self::isValidIdentifier($new)) {
            throw new RuntimeException('Table names must contain only letters, numbers, or underscores.');
        }

        if ($current === $new) {
            return;
        }

        $sql = match ($driver) {
            'mysql' => sprintf('ALTER TABLE %s RENAME TO %s', self::quoteIdentifier($current, $driver), self::quoteIdentifier($new, $driver)),
            default => sprintf('ALTER TABLE %s RENAME TO %s', self::quoteIdentifier($current, $driver), self::quoteIdentifier($new, $driver)),
        };

        $connection->exec($sql);
        self::$messages[] = sprintf('Table "%s" renamed to "%s".', $current, $new);
        self::$activeTable = $new;
    }

    private static function handleDeleteTable(PDO $connection, string $driver): void
    {
        $table = isset($_POST['table_name']) ? trim((string) $_POST['table_name']) : '';

        if (!self::isValidIdentifier($table)) {
            throw new RuntimeException('Table name must contain only letters, numbers, or underscores.');
        }

        $tables = self::fetchTables($connection, $driver);
        $matched = null;
        foreach ($tables as $existing) {
            if (strcasecmp($existing, $table) === 0) {
                $matched = $existing;
                break;
            }
        }

        if ($matched === null) {
            throw new RuntimeException(sprintf('Table "%s" does not exist.', $table));
        }

        $sql = sprintf('DROP TABLE IF EXISTS %s', self::quoteIdentifier($matched, $driver));
        $connection->exec($sql);

        self::$messages[] = sprintf('Table "%s" deleted.', $matched);

        if (self::$activeTable !== null && strcasecmp(self::$activeTable, $matched) === 0) {
            self::$activeTable = null;
        }
    }

    private static function handleDownloadDatabase(PDO $connection, string $driver): void
    {
        $dump = self::generateDatabaseDump($connection, $driver);

        $filename = 'fastcrud-database-' . date('Ymd-His') . '.sql';
        self::clearOutputBuffers();
        header('Content-Type: application/sql; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($dump));

        echo $dump;
        exit;
    }

    private static function handleAddColumn(PDO $connection, string $driver): void
    {
        $table = isset($_POST['table_name']) ? trim((string) $_POST['table_name']) : '';
        $column = isset($_POST['column_name']) ? trim((string) $_POST['column_name']) : '';
        $type = isset($_POST['column_type']) ? trim((string) $_POST['column_type']) : '';

        if (!self::isValidIdentifier($table) || !self::isValidIdentifier($column)) {
            throw new RuntimeException('Table and column names must contain only letters, numbers, or underscores.');
        }

        self::$activeTable = $table;

        if (!self::isSafeType($type)) {
            throw new RuntimeException('Column type contains unsupported characters.');
        }

        $sql = sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            self::quoteIdentifier($table, $driver),
            self::quoteIdentifier($column, $driver),
            $type
        );

        $connection->exec($sql);
        self::$messages[] = sprintf('Column "%s" added to "%s".', $column, $table);
    }

    private static function handleRenameColumn(PDO $connection, string $driver): void
    {
        $table = isset($_POST['table_name']) ? trim((string) $_POST['table_name']) : '';
        $column = isset($_POST['column_name']) ? trim((string) $_POST['column_name']) : '';
        $newName = isset($_POST['new_column_name']) ? trim((string) $_POST['new_column_name']) : '';

        if (!self::isValidIdentifier($table) || !self::isValidIdentifier($column) || !self::isValidIdentifier($newName)) {
            throw new RuntimeException('Table and column names must contain only letters, numbers, or underscores.');
        }

        self::$activeTable = $table;

        if ($column === $newName) {
            return;
        }

        $sql = match ($driver) {
            'mysql' => sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s',
                self::quoteIdentifier($table, $driver),
                self::quoteIdentifier($column, $driver),
                self::quoteIdentifier($newName, $driver)
            ),
            default => sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s',
                self::quoteIdentifier($table, $driver),
                self::quoteIdentifier($column, $driver),
                self::quoteIdentifier($newName, $driver)
            ),
        };

        $connection->exec($sql);
        self::$messages[] = sprintf('Column "%s" renamed to "%s" in "%s".', $column, $newName, $table);
    }

    private static function handleChangeColumnType(PDO $connection, string $driver): void
    {
        $table = isset($_POST['table_name']) ? trim((string) $_POST['table_name']) : '';
        $column = isset($_POST['column_name']) ? trim((string) $_POST['column_name']) : '';
        $type = isset($_POST['new_column_type']) ? trim((string) $_POST['new_column_type']) : '';

        if (!self::isValidIdentifier($table) || !self::isValidIdentifier($column)) {
            throw new RuntimeException('Table and column names must contain only letters, numbers, or underscores.');
        }

        self::$activeTable = $table;

        if (!self::isSafeType($type)) {
            throw new RuntimeException('Column type contains unsupported characters.');
        }

        $sql = match ($driver) {
            'mysql' => sprintf('ALTER TABLE %s MODIFY %s %s',
                self::quoteIdentifier($table, $driver),
                self::quoteIdentifier($column, $driver),
                $type
            ),
            'pgsql' => sprintf('ALTER TABLE %s ALTER COLUMN %s TYPE %s',
                self::quoteIdentifier($table, $driver),
                self::quoteIdentifier($column, $driver),
                $type
            ),
            'sqlite' => throw new RuntimeException('Changing column types is not supported for SQLite via this editor.'),
            default => sprintf('ALTER TABLE %s MODIFY %s %s',
                self::quoteIdentifier($table, $driver),
                self::quoteIdentifier($column, $driver),
                $type
            ),
        };

        $connection->exec($sql);
        self::$messages[] = sprintf('Column "%s" type updated for "%s".', $column, $table);
    }

    private static function handleReorderColumns(PDO $connection, string $driver): void
    {
        if ($driver !== 'mysql') {
            throw new RuntimeException('Column reordering is currently supported only for MySQL connections.');
        }

        $table = isset($_POST['table_name']) ? trim((string) $_POST['table_name']) : '';
        $orderPayload = isset($_POST['column_order']) ? trim((string) $_POST['column_order']) : '';

        if (!self::isValidIdentifier($table)) {
            throw new RuntimeException('Invalid table name provided for column reordering.');
        }

        self::$activeTable = $table;

        if ($orderPayload === '') {
            throw new RuntimeException('Column order payload is missing.');
        }

        $requestedOrder = array_values(array_filter(array_map('trim', explode(',', $orderPayload)), static function (string $value): bool {
            return $value !== '';
        }));

        if ($requestedOrder === []) {
            throw new RuntimeException('Column order payload is empty.');
        }

        $columns = self::fetchColumns($connection, $driver, $table);
        if ($columns === []) {
            throw new RuntimeException(sprintf('No columns found for table "%s".', $table));
        }

        $existingNames = array_map(static function (array $column): string {
            return (string) $column['name'];
        }, $columns);

        $lookup = [];
        foreach ($existingNames as $name) {
            $lookup[strtolower($name)] = $name;
        }

        $normalizedOrder = [];
        foreach ($requestedOrder as $name) {
            if (!self::isValidIdentifier($name)) {
                throw new RuntimeException('Column order contains an invalid identifier.');
            }
            $key = strtolower($name);
            if (!isset($lookup[$key])) {
                throw new RuntimeException(sprintf('Column "%s" does not exist on "%s".', $name, $table));
            }
            $normalizedOrder[] = $lookup[$key];
        }

        if (count($normalizedOrder) !== count($existingNames)) {
            throw new RuntimeException('Column order does not include every column.');
        }

        if (count(array_unique($normalizedOrder)) !== count($normalizedOrder)) {
            throw new RuntimeException('Column order contains duplicate entries.');
        }

        $sortedExisting = $existingNames;
        sort($sortedExisting);
        $sortedRequested = $normalizedOrder;
        sort($sortedRequested);
        if ($sortedExisting !== $sortedRequested) {
            throw new RuntimeException('Column order does not match the existing schema.');
        }

        if ($normalizedOrder === $existingNames) {
            self::$messages[] = sprintf('Column order for "%s" is already up to date.', $table);
            return;
        }

        self::applyMysqlColumnOrder($connection, $table, $normalizedOrder);

        if (!self::$returnJsonResponse) {
            self::$messages[] = sprintf('Column order updated for "%s".', $table);
        }
    }

    private static function generateDatabaseDump(PDO $connection, string $driver): string
    {
        $header = [
            '-- FastCRUD database export',
            '-- Generated: ' . gmdate('Y-m-d\TH:i:s\Z'),
            sprintf('-- Driver: %s', strtoupper($driver)),
            '',
        ];

        $body = match ($driver) {
            'mysql' => self::generateMysqlDump($connection),
            'sqlite' => self::generateSqliteDump($connection),
            default => throw new RuntimeException('Database downloads are currently supported for MySQL and SQLite connections only.'),
        };

        return implode("\n", $header) . $body;
    }

    private static function generateMysqlDump(PDO $connection): string
    {
        $tables = self::fetchMysqlTables($connection);
        if ($tables === []) {
            return "-- No tables found for this MySQL connection.\n";
        }

        $lines = [
            'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";',
            'SET FOREIGN_KEY_CHECKS = 0;',
            '',
        ];

        foreach ($tables as $table) {
            $quotedTable = self::quoteIdentifier($table, 'mysql');
            $lines[] = sprintf('-- Table structure for %s', $quotedTable);
            $lines[] = sprintf('DROP TABLE IF EXISTS %s;', $quotedTable);
            $lines[] = self::fetchMysqlCreateStatement($connection, $table) . ';';
            $lines[] = '';

            $rows = self::fetchTableData($connection, 'mysql', $table);
            $lines = array_merge($lines, self::buildInsertStatements($connection, 'mysql', $table, $quotedTable, $rows));
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function generateSqliteDump(PDO $connection): string
    {
        $tables = self::fetchSqliteTables($connection);
        if ($tables === []) {
            return "-- No tables found for this SQLite connection.\n";
        }

        $lines = [
            'PRAGMA foreign_keys=OFF;',
            '',
        ];

        foreach ($tables as $table) {
            $quotedTable = self::quoteIdentifier($table, 'sqlite');
            $lines[] = sprintf('-- Table structure for %s', $quotedTable);
            $lines[] = sprintf('DROP TABLE IF EXISTS %s;', $quotedTable);
            $lines[] = self::fetchSqliteCreateStatement($connection, $table) . ';';
            $lines[] = '';

            $rows = self::fetchTableData($connection, 'sqlite', $table);
            $lines = array_merge($lines, self::buildInsertStatements($connection, 'sqlite', $table, $quotedTable, $rows));
        }

        return implode("\n", $lines);
    }

    private static function fetchMysqlCreateStatement(PDO $connection, string $table): string
    {
        $sql = sprintf('SHOW CREATE TABLE %s', self::quoteIdentifier($table, 'mysql'));
        $statement = $connection->query($sql);
        if ($statement === false) {
            throw new RuntimeException(sprintf('Unable to fetch CREATE statement for table "%s".', $table));
        }

        $result = $statement->fetch(PDO::FETCH_ASSOC);
        if ($result === false) {
            throw new RuntimeException(sprintf('Unable to fetch CREATE statement for table "%s".', $table));
        }

        foreach ($result as $key => $value) {
            if (stripos((string) $key, 'create') !== false && is_string($value)) {
                return $value;
            }
        }

        throw new RuntimeException(sprintf('Unable to fetch CREATE statement for table "%s".', $table));
    }

    private static function fetchSqliteCreateStatement(PDO $connection, string $table): string
    {
        $statement = $connection->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :name");
        if ($statement === false) {
            throw new RuntimeException(sprintf('Unable to fetch CREATE statement for table "%s".', $table));
        }

        $statement->execute(['name' => $table]);
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        if ($result === false) {
            throw new RuntimeException(sprintf('Unable to fetch CREATE statement for table "%s".', $table));
        }

        $create = $result['sql'] ?? null;
        if (!is_string($create) || $create === '') {
            throw new RuntimeException(sprintf('Unable to fetch CREATE statement for table "%s".', $table));
        }

        return $create;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function buildInsertStatements(PDO $connection, string $driver, string $table, string $quotedTable, array $rows): array
    {
        if ($rows === []) {
            return [
                sprintf('-- No rows for %s', $quotedTable),
                '',
            ];
        }

        $columnNames = array_keys($rows[0]);
        $columnList = implode(', ', array_map(static function (string $column) use ($driver): string {
            return self::quoteIdentifier($column, $driver);
        }, $columnNames));

        $lines = [];
        foreach (array_chunk($rows, 100) as $chunk) {
            $valueRows = [];
            foreach ($chunk as $row) {
                $values = [];
                foreach ($columnNames as $column) {
                    $values[] = self::formatValueForInsert($connection, $row[$column] ?? null);
                }
                $valueRows[] = '(' . implode(', ', $values) . ')';
            }

            $lines[] = sprintf(
                'INSERT INTO %s (%s) VALUES\n  %s;',
                $quotedTable,
                $columnList,
                implode(",\n  ", $valueRows)
            );
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetchTableData(PDO $connection, string $driver, string $table): array
    {
        $sql = sprintf('SELECT * FROM %s', self::quoteIdentifier($table, $driver));
        $statement = $connection->query($sql);
        if ($statement === false) {
            return [];
        }

        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    private static function formatValueForInsert(PDO $connection, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_resource($value)) {
            $stream = stream_get_contents($value);
            if (!is_string($stream)) {
                return 'NULL';
            }

            return "X'" . bin2hex($stream) . "'";
        }

        $quoted = $connection->quote((string) $value);
        if ($quoted === false) {
            return "'" . addslashes((string) $value) . "'";
        }

        return $quoted;
    }

    private static function fetchTables(PDO $connection, string $driver): array
    {
        return match ($driver) {
            'pgsql' => self::fetchPgsqlTables($connection),
            'sqlite' => self::fetchSqliteTables($connection),
            default => self::fetchMysqlTables($connection),
        };
    }

    private static function fetchMysqlTables(PDO $connection): array
    {
        $statement = $connection->query('SHOW TABLES');
        if ($statement === false) {
            return [];
        }

        $tables = [];
        while (($row = $statement->fetchColumn()) !== false) {
            if (is_string($row)) {
                $tables[] = $row;
            }
        }

        return $tables;
    }

    private static function fetchPgsqlTables(PDO $connection): array
    {
        $sql = 'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename';
        $statement = $connection->query($sql);
        if ($statement === false) {
            return [];
        }

        $tables = [];
        while (($row = $statement->fetchColumn()) !== false) {
            if (is_string($row)) {
                $tables[] = $row;
            }
        }

        return $tables;
    }

    private static function fetchSqliteTables(PDO $connection): array
    {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
        $statement = $connection->query($sql);
        if ($statement === false) {
            return [];
        }

        $tables = [];
        while (($row = $statement->fetchColumn()) !== false) {
            if (is_string($row)) {
                $tables[] = $row;
            }
        }

        return $tables;
    }

    private static function fetchColumns(PDO $connection, string $driver, string $table): array
    {
        return match ($driver) {
            'pgsql' => self::fetchPgsqlColumns($connection, $table),
            'sqlite' => self::fetchSqliteColumns($connection, $table),
            default => self::fetchMysqlColumns($connection, $table),
        };
    }

    private static function fetchMysqlColumns(PDO $connection, string $table): array
    {
        $sql = sprintf('SHOW FULL COLUMNS FROM %s', self::quoteIdentifier($table, 'mysql'));
        $statement = $connection->query($sql);
        if ($statement === false) {
            return [];
        }

        $columns = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns[] = [
                'name' => (string) ($row['Field'] ?? ''),
                'type' => (string) ($row['Type'] ?? ''),
                'nullable' => ($row['Null'] ?? '') === 'YES',
                'default' => $row['Default'] ?? null,
                'extra' => (string) ($row['Extra'] ?? ''),
            ];
        }

        return $columns;
    }

    private static function fetchPgsqlColumns(PDO $connection, string $table): array
    {
        $sql = <<<'SQL'
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = :table
ORDER BY ordinal_position
SQL;
        $statement = $connection->prepare($sql);
        if ($statement === false) {
            return [];
        }

        $statement->execute(['table' => $table]);

        $columns = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns[] = [
                'name' => (string) ($row['column_name'] ?? ''),
                'type' => (string) ($row['data_type'] ?? ''),
                'nullable' => ($row['is_nullable'] ?? '') === 'YES',
                'default' => $row['column_default'] ?? null,
                'extra' => '',
            ];
        }

        return $columns;
    }

    private static function fetchSqliteColumns(PDO $connection, string $table): array
    {
        $sql = sprintf('PRAGMA table_info(%s)', self::quoteIdentifier($table, 'sqlite'));
        $statement = $connection->query($sql);
        if ($statement === false) {
            return [];
        }

        $columns = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $columns[] = [
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'nullable' => ((int) ($row['notnull'] ?? 0)) === 0,
                'default' => $row['dflt_value'] ?? null,
                'extra' => ((int) ($row['pk'] ?? 0)) === 1 ? 'PRIMARY KEY' : '',
            ];
        }

        return $columns;
    }

    private static function applyMysqlColumnOrder(PDO $connection, string $table, array $orderedColumns): void
    {
        if ($orderedColumns === []) {
            return;
        }

        $createSql = self::fetchMysqlCreateTable($connection, $table);
        $definitions = self::parseMysqlCreateTableColumns($createSql);

        $clauses = [];
        foreach ($orderedColumns as $index => $column) {
            if (!isset($definitions[$column])) {
                throw new RuntimeException(sprintf('Unable to determine definition for column "%s".', $column));
            }

            $position = $index === 0
                ? ' FIRST'
                : ' AFTER ' . self::quoteIdentifier($orderedColumns[$index - 1], 'mysql');

            $clauses[] = sprintf(
                'MODIFY COLUMN %s %s%s',
                self::quoteIdentifier($column, 'mysql'),
                $definitions[$column],
                $position
            );
        }

        if ($clauses === []) {
            return;
        }

        $sql = sprintf(
            'ALTER TABLE %s %s',
            self::quoteIdentifier($table, 'mysql'),
            implode(', ', $clauses)
        );

        $connection->exec($sql);
    }

    private static function fetchMysqlCreateTable(PDO $connection, string $table): string
    {
        $sql = sprintf('SHOW CREATE TABLE %s', self::quoteIdentifier($table, 'mysql'));
        $statement = $connection->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to read table definition for reordering.');
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Unable to read table definition for reordering.');
        }

        $createStatement = '';
        if (isset($row['Create Table'])) {
            $createStatement = (string) $row['Create Table'];
        } elseif (isset($row['Create Table '])) { // MariaDB quirk on some versions
            $createStatement = (string) $row['Create Table '];
        }

        $createStatement = trim($createStatement);
        if ($createStatement === '') {
            throw new RuntimeException('Received empty table definition while reordering columns.');
        }

        return $createStatement;
    }

    /**
     * @return array<string, string>
     */
    private static function parseMysqlCreateTableColumns(string $createSql): array
    {
        $start = strpos($createSql, '(');
        $end = strrpos($createSql, ')');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $body = substr($createSql, $start + 1, $end - $start - 1);
        $lines = preg_split('/\r?\n/', $body) ?: [];

        $columns = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '`') {
                continue;
            }

            $line = rtrim($line, ',');
            if (!preg_match('/^`([^`]+)`\s+(.*)$/', $line, $matches)) {
                continue;
            }

            $name = $matches[1];
            $definition = trim($matches[2]);
            if ($definition === '') {
                continue;
            }

            $columns[$name] = $definition;
        }

        return $columns;
    }

    private static function resolveActiveTable(array $tables): ?string
    {
        if ($tables === []) {
            self::$activeTable = null;

            return null;
        }

        $requested = self::$activeTable;
        if (is_string($requested) && $requested !== '') {
            foreach ($tables as $table) {
                if (strcasecmp($table, $requested) === 0) {
                    self::$activeTable = $table;

                    return $table;
                }
            }
        }

        self::$activeTable = $tables[0];

        return self::$activeTable;
    }

    private static function renderHtml(array $tables, array $tableColumns, string $driver, ?string $activeTable, bool $showHeader): string
    {
        if ($activeTable !== null && !in_array($activeTable, $tables, true)) {
            $activeTable = null;
        }

        if ($activeTable === null) {
            $activeTable = $tables[0] ?? null;
        }

        $dbConfig = CrudConfig::getDbConfig();
        $databaseName = $dbConfig['database'] ?? ($dbConfig['dbname'] ?? '');
        $databaseName = is_string($databaseName) ? trim($databaseName) : '';
        $databaseHeading = $databaseName !== '' ? $databaseName : 'Database';
        $databaseHeading = htmlspecialchars($databaseHeading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $driverLabel = strtoupper($driver);
        $tableCount = count($tables);
        $totalColumns = 0;
        foreach ($tableColumns as $columns) {
            $totalColumns += count($columns ?? []);
        }

        $host = $dbConfig['host'] ?? ($dbConfig['hostname'] ?? '');
        $host = is_string($host) ? trim($host) : '';
        $port = $dbConfig['port'] ?? '';
        $port = is_scalar($port) ? (string) $port : '';
        $schema = $dbConfig['schema'] ?? '';
        $schema = is_string($schema) ? trim($schema) : '';
        $connectionPieces = [];
        if ($host !== '') {
            $label = $port !== '' ? $host . ':' . $port : $host;
            $connectionPieces[] = $label;
        }
        if ($driver === 'sqlite') {
            $path = $dbConfig['database'] ?? ($dbConfig['path'] ?? '');
            $path = is_string($path) ? trim($path) : '';
            if ($path !== '') {
                $connectionPieces[] = \basename($path);
            }
        } elseif ($schema !== '' && strcasecmp($schema, $databaseName) !== 0) {
            $connectionPieces[] = $schema;
        }
        $connectionDisplay = $connectionPieces !== [] ? htmlspecialchars(implode(' • ', array_unique($connectionPieces)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';

        $activeTableLabel = $activeTable !== null ? htmlspecialchars($activeTable, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;
        $driverLabelEscaped = htmlspecialchars($driverLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $totalColumnsFormatted = number_format($totalColumns);
        $tableCountFormatted = number_format($tableCount);

        $html = '<div class="fastcrud-db-editor">';
        $html .= '<div class="fastcrud-db-editor__feedback" data-fc-db-feedback>' . self::renderFeedbackHtml() . '</div>';

        if ($showHeader) {
            $html .= '<section class="fc-db-editor-hero position-relative overflow-hidden rounded-4 shadow-sm">';
            $html .= '<div class="fc-db-hero__backdrop"></div>';
            $html .= '<div class="fc-db-hero__content p-3 p-lg-4">';
            $html .= '<div class="fc-db-hero__header d-flex flex-column flex-lg-row align-items-lg-center gap-2">';
            $html .= '<div class="fc-db-hero__title">';
            $html .= '<span class="fc-db-hero__eyebrow text-uppercase small text-white-50">Schema overview</span>';
            $html .= '<h2 class="h3 text-white mb-1"><i class="bi bi-database me-2"></i>' . $databaseHeading . '</h2>';
            if ($connectionDisplay !== '') {
                $html .= '<p class="mb-0 text-white-50 small">' . $connectionDisplay . '</p>';
            }
            $html .= '</div>';
            $html .= '<form method="post" class="ms-lg-auto">';
            $html .= '<input type="hidden" name="fc_db_editor_action" value="download_database">';
            $html .= '<button type="submit" class="btn btn-light fw-semibold shadow-sm px-3 py-2"><i class="bi bi-download me-2"></i>Download export</button>';
            $html .= '</form>';
            $html .= '</div>';
            $html .= '<div class="fc-db-hero__metrics mt-2">';
            $html .= '<div class="fc-db-hero__metric">';
            $html .= '<span class="fc-db-hero__metric-icon"><i class="bi bi-diagram-3"></i></span>';
            $html .= '<span class="fc-db-hero__metric-text"><span class="fc-db-hero__metric-value">' . $tableCountFormatted . '</span><span class="fc-db-hero__metric-label">' . ($tableCount === 1 ? 'Table' : 'Tables') . '</span></span>';
            $html .= '</div>';
            $html .= '<div class="fc-db-hero__metric">';
            $html .= '<span class="fc-db-hero__metric-icon"><i class="bi bi-layout-text-window"></i></span>';
            $html .= '<span class="fc-db-hero__metric-text"><span class="fc-db-hero__metric-value">' . $totalColumnsFormatted . '</span><span class="fc-db-hero__metric-label">Columns</span></span>';
            $html .= '</div>';
            $html .= '<div class="fc-db-hero__metric">';
            $html .= '<span class="fc-db-hero__metric-icon"><i class="bi bi-cpu"></i></span>';
            $html .= '<span class="fc-db-hero__metric-text"><span class="fc-db-hero__metric-value">' . $driverLabelEscaped . '</span><span class="fc-db-hero__metric-label">Driver</span></span>';
            $html .= '</div>';
            if ($activeTableLabel !== null) {
                $activeTableColumnCount = 0;
                if ($activeTable !== null) {
                    $activeTableColumnCount = count($tableColumns[$activeTable] ?? []);
                }
                $activeTableColumnCountLabel = $activeTableColumnCount === 1 ? '1 column' : $activeTableColumnCount . ' columns';
                $activeTableColumnCountLabelEscaped = htmlspecialchars($activeTableColumnCountLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $activeTableColumnCountAttr = htmlspecialchars((string) $activeTableColumnCount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<div class="fc-db-hero__metric">';
                $html .= '<span class="fc-db-hero__metric-icon"><i class="bi bi-lightning-charge"></i></span>';
                $html .= '<span class="fc-db-hero__metric-text">';
                $html .= '<span class="fc-db-hero__metric-value" data-fc-db-active-table data-fc-db-active-default="' . $activeTableLabel . '">' . $activeTableLabel . '</span>';
                $html .= '<span class="fc-db-hero__metric-label">Active table &middot; <span data-fc-db-active-count data-fc-db-active-count-value="' . $activeTableColumnCountAttr . '">' . $activeTableColumnCountLabelEscaped . '</span></span>';
                $html .= '</span>';
                $html .= '</div>';
            }
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</section>';
        }

        if ($tables === []) {
            $html .= '<section class="fc-db-editor-empty card border-0 shadow-sm mt-4">';
            $html .= '<div class="card-body p-5 text-center">';
            $html .= '<div class="display-6 text-muted mb-3"><i class="bi bi-emoji-smile"></i></div>';
            $html .= '<h3 class="fw-semibold">No tables detected yet</h3>';
            $html .= '<p class="text-muted mx-auto" style="max-width: 420px;">Connect a database or create your first table to start managing your schema. FastCRUD keeps destructive actions gated behind confirmations.</p>';
            $html .= '<div class="mt-4 d-inline-block">';
            $html .= '<form method="post" class="row g-3 justify-content-center align-items-end" data-fc-db-editor-form>';
            $html .= '<input type="hidden" name="fc_db_editor_action" value="add_table">';
            $html .= '<div class="col-12 col-md-auto">';
            $html .= '<label class="form-label text-muted">Table name</label>';
            $html .= '<input type="text" name="new_table" class="form-control form-control-lg" placeholder="e.g. customers" required pattern="[A-Za-z0-9_]+">';
            $html .= '</div>';
            $html .= '<div class="col-12 col-md-auto">';
            $html .= '<button type="submit" class="btn btn-primary btn-lg w-100"><i class="bi bi-plus-lg me-2"></i>Create table</button>';
            $html .= '</div>';
            $html .= '</form>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</section>';
        } else {
            $typeOptions = self::getColumnTypeOptions($driver);
            $reorderEnabled = $driver === 'mysql';
            $html .= '<section class="fc-db-editor-workspace mt-4">';
            $html .= '<div class="row g-4 align-items-start">';
            $html .= '<div class="col-12 col-xl-4 col-xxl-3">';
            $html .= '<aside class="fc-db-editor-sidebar card border-0 shadow-sm h-100">';
            $html .= '<div class="fc-db-sidebar__header border-bottom p-3">';
            $html .= '<div class="d-flex align-items-center justify-content-between">';
            $html .= '<h3 class="h6 mb-0 text-body-secondary text-uppercase">Tables</h3>';
            $html .= '<span class="badge bg-primary-subtle text-primary">' . $tableCountFormatted . '</span>';
            $html .= '</div>';
            $html .= '<div class="input-group input-group-sm mt-3">';
            $html .= '<span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search"></i></span>';
            $html .= '<input type="search" class="form-control border-start-0" placeholder="Search tables" autocomplete="off" data-fc-db-table-search aria-label="Search tables">';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<div class="fc-db-sidebar__list" data-fc-db-sidebar-list="">';
            $html .= '<div class="fc-db-table-list" id="fc-db-editor-table-list" role="tablist" data-fc-db-table-list="">';
            foreach ($tables as $index => $table) {
                $tableEscaped = htmlspecialchars($table, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $tabId = 'fc-db-editor-table-' . $index;
                $isActive = $table === $activeTable ? ' active' : '';
                $ariaSelected = $table === $activeTable ? 'true' : 'false';
                $columnCount = count($tableColumns[$table] ?? []);
                $columnCountAttr = htmlspecialchars((string) $columnCount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $columnBadge = $columnCount === 1 ? '1 col' : $columnCount . ' cols';
                $tableSearch = htmlspecialchars(strtolower($table), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<a class="fc-db-table-link' . $isActive . '" data-fc-db-table-name="' . $tableSearch . '" data-fc-db-table-label="' . $tableEscaped . '" data-fc-db-table-columns="' . $columnCountAttr . '" id="tab-' . $tabId . '" data-bs-toggle="list" href="#' . $tabId . '" role="tab" aria-controls="' . $tabId . '" aria-selected="' . $ariaSelected . '" title="View ' . $tableEscaped . '">';
                $html .= '<span class="fc-db-table-link__name text-truncate"><i class="bi bi-table text-primary me-2"></i>' . $tableEscaped . '</span>';
                $html .= '<span class="badge bg-body-secondary text-body fw-semibold">' . $columnBadge . '</span>';
                $html .= '</a>';
            }
            $html .= '</div>';
            $html .= '<div class="fc-db-sidebar__empty text-center text-muted small py-4 d-none" data-fc-db-sidebar-empty role="status" aria-live="polite">No tables matched your search.</div>';
            $html .= '</div>';
            $html .= '<div class="fc-db-sidebar__footer border-top p-3">';
            $html .= '<form method="post" class="row g-2 align-items-center" data-fc-db-editor-form>';
            $html .= '<input type="hidden" name="fc_db_editor_action" value="add_table">';
            $html .= '<div class="col">';
            $html .= '<label class="form-label text-muted small mb-1">New table</label>';
            $html .= '<input type="text" name="new_table" class="form-control form-control-sm" placeholder="analytics" required pattern="[A-Za-z0-9_]+">';
            $html .= '</div>';
            $html .= '<div class="col-auto">';
            $html .= '<button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i></button>';
            $html .= '</div>';
            $html .= '</form>';
            $html .= '</div>';
            $html .= '</aside>';
            $html .= '</div>';
            $html .= '<div class="col-12 col-xl-8 col-xxl-9">';
            $html .= '<div class="tab-content fc-db-editor-tab-content" id="fc-db-editor-table-content">';
            foreach ($tables as $index => $table) {
                $tableEscaped = htmlspecialchars($table, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $tabId = 'fc-db-editor-table-' . $index;
                $isActive = $table === $activeTable ? ' show active' : '';
                $columns = $tableColumns[$table] ?? [];
                $columnCount = count($columns);
                $columnSummary = $columnCount === 1 ? '1 column' : $columnCount . ' columns';
                $tipMessage = $reorderEnabled
                    ? 'Drag the handle to reorder columns. Click a column name to rename it or use the type badge to adjust definitions.'
                    : 'Click a column name to rename it or use the type badge to adjust definitions.';
                $html .= '<div class="tab-pane fade' . $isActive . '" id="' . $tabId . '" role="tabpanel" aria-labelledby="tab-' . $tabId . '">';
                $html .= '<div class="fc-db-table card border-0 shadow-sm">';
                $html .= '<div class="fc-db-table__header border-bottom p-4 d-flex flex-column flex-lg-row align-items-lg-center gap-3">';
                $html .= '<div class="d-flex align-items-center gap-3">';
                $html .= '<div data-fc-inline-container="name" class="fc-db-inline">';
                $html .= '<span class="h4 mb-0 fw-semibold text-body fc-db-inline-trigger link-primary" data-fc-inline-trigger role="button" tabindex="0" title="Rename table">' . $tableEscaped . '</span>';
                $html .= '<form method="post" class="fc-db-inline-form d-flex align-items-center gap-2 d-none" data-fc-inline-form data-fc-db-editor-form>';
                $html .= '<input type="hidden" name="fc_db_editor_action" value="rename_table">';
                $html .= '<input type="hidden" name="current_table" value="' . $tableEscaped . '">';
                $html .= '<input type="text" name="new_table_name" class="form-control form-control-sm" value="' . $tableEscaped . '" required pattern="[A-Za-z0-9_]+" placeholder="Table name">';
                $html .= '</form>';
                $html .= '</div>';
                $html .= '<span class="badge bg-primary-subtle text-primary fw-semibold">' . $columnSummary . '</span>';
                $html .= '</div>';
                $confirmMessage = htmlspecialchars(sprintf('Delete table "%s"? This action cannot be undone.', $table), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= '<form method="post" class="ms-lg-auto d-flex align-items-center" data-fc-db-editor-form>';
                $html .= '<input type="hidden" name="fc_db_editor_action" value="delete_table">';
                $html .= '<input type="hidden" name="table_name" value="' . $tableEscaped . '">';
                $html .= '<button type="submit" class="btn btn-outline-danger btn-sm" data-fc-db-confirm="' . $confirmMessage . '" title="Delete table" aria-label="Delete table"><i class="bi bi-trash me-1"></i>Delete</button>';
                $html .= '</form>';
                $html .= '</div>';
                $html .= '<div class="fc-db-table__body p-4">';
                $html .= '<div class="alert alert-info fc-db-table__tip" role="alert">' . $tipMessage . '</div>';
                if ($columns === []) {
                    $html .= '<div class="fc-db-empty-state card border-0 bg-body-tertiary text-center py-5">';
                    $html .= '<div class="text-muted mb-2"><i class="bi bi-columns-gap fs-3"></i></div>';
                    $html .= '<p class="mb-0 text-muted">No columns detected for this table.</p>';
                    $html .= '</div>';
                } else {
                    $html .= '<div class="table-responsive fc-db-table__grid">';
                    $html .= '<table class="table table-hover align-middle mb-4 fc-db-columns-table">';
                    $html .= '<thead>';
                    $html .= '<tr>';
                    if ($reorderEnabled) {
                        $html .= '<th scope="col" class="text-muted small text-center" style="width: 2.5rem;"><span class="visually-hidden">Reorder</span></th>';
                    }
                    $html .= '<th scope="col" class="text-muted text-uppercase small">Column</th>';
                    $html .= '<th scope="col" class="text-muted text-uppercase small">Type</th>';
                    $html .= '<th scope="col" class="text-center text-muted text-uppercase small">Nullable</th>';
                    $html .= '<th scope="col" class="text-muted text-uppercase small">Default</th>';
                    $html .= '<th scope="col" class="text-muted text-uppercase small">Extra</th>';
                    $html .= '</tr>';
                    $html .= '</thead>';
                    $tbodyAttributes = $reorderEnabled ? ' data-fc-db-columns data-fc-db-table="' . $tableEscaped . '"' : '';
                    $html .= '<tbody' . $tbodyAttributes . '>';
                    foreach ($columns as $column) {
                        $columnName = $column['name'];
                        $columnType = $column['type'];
                        $columnEscaped = htmlspecialchars($columnName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $typeEscaped = htmlspecialchars($columnType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $defaultEscaped = htmlspecialchars((string) ($column['default'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $extraEscaped = htmlspecialchars($column['extra'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $typeOptionsHtml = self::buildTypeOptions($typeOptions, (string) $columnType);
                        $nullableBadge = $column['nullable']
                            ? '<span class="badge bg-success-subtle text-success"><i class="bi bi-check-lg me-1"></i>Yes</span>'
                            : '<span class="badge bg-danger-subtle text-danger"><i class="bi bi-x-lg me-1"></i>No</span>';
                        $isPrimary = stripos($extraEscaped, 'primary') !== false;
                        $isAutoIncrement = stripos($extraEscaped, 'auto') !== false;
                        $badges = '';
                        if ($isPrimary) {
                            $badges .= '<span class="badge bg-warning-subtle text-warning ms-2">Primary</span>';
                        }
                        if ($isAutoIncrement) {
                            $badges .= '<span class="badge bg-info-subtle text-info ms-2">Auto</span>';
                        }
                        $html .= '<tr data-fc-db-column="' . $columnEscaped . '">';
                        if ($reorderEnabled) {
                            $html .= '<td class="text-center text-muted align-middle" data-fc-db-reorder-handle title="Drag to reorder"><i class="bi bi-grip-vertical"></i></td>';
                        }
                        $html .= '<th scope="row" class="align-middle">';
                        $html .= '<div data-fc-inline-container="name" class="fc-db-inline">';
                        $html .= '<span class="fw-semibold fc-db-inline-trigger link-body-emphasis" data-fc-inline-trigger role="button" tabindex="0" title="Rename column">' . $columnEscaped . '</span>';
                        $html .= '<form method="post" class="fc-db-inline-form d-flex align-items-center gap-2 d-none" data-fc-inline-form data-fc-db-editor-form>';
                        $html .= '<input type="hidden" name="fc_db_editor_action" value="rename_column">';
                        $html .= '<input type="hidden" name="table_name" value="' . $tableEscaped . '">';
                        $html .= '<input type="hidden" name="column_name" value="' . $columnEscaped . '">';
                        $html .= '<input type="text" name="new_column_name" class="form-control form-control-sm" value="' . $columnEscaped . '" required pattern="[A-Za-z0-9_]+" placeholder="Column name">';
                        $html .= '</form>';
                        $html .= '</div>';
                        $html .= $badges;
                        $html .= '</th>';
                        $html .= '<td class="align-middle" data-fc-inline-container="type">';
                        $html .= '<span class="badge rounded-pill bg-primary-subtle text-primary fc-db-inline-trigger font-monospace" data-fc-inline-trigger role="button" tabindex="0" title="Change column type">' . $typeEscaped . '</span>';
                        $html .= '<form method="post" class="fc-db-inline-form d-flex align-items-center gap-2 d-none" data-fc-inline-form data-fc-db-editor-form>';
                        $html .= '<input type="hidden" name="fc_db_editor_action" value="change_column_type">';
                        $html .= '<input type="hidden" name="table_name" value="' . $tableEscaped . '">';
                        $html .= '<input type="hidden" name="column_name" value="' . $columnEscaped . '">';
                        $html .= '<select name="new_column_type" class="form-select form-select-sm fc-db-type-select" required>' . $typeOptionsHtml . '</select>';
                        $html .= '</form>';
                        $html .= '</td>';
                        $html .= '<td class="text-center align-middle">' . $nullableBadge . '</td>';
                        $html .= '<td class="align-middle small text-muted">' . ($defaultEscaped !== '' ? $defaultEscaped : '<span class="text-body-secondary">—</span>') . '</td>';
                        $html .= '<td class="align-middle small text-muted">' . ($extraEscaped !== '' ? $extraEscaped : '<span class="text-body-secondary">—</span>') . '</td>';
                        $html .= '</tr>';
                    }
                    $html .= '</tbody></table>';
                    if ($reorderEnabled) {
                        $html .= '<form method="post" class="d-none" data-fc-db-editor-form data-fc-db-reorder-form>';
                        $html .= '<input type="hidden" name="fc_db_editor_action" value="reorder_columns">';
                        $html .= '<input type="hidden" name="table_name" value="' . $tableEscaped . '">';
                        $html .= '<input type="hidden" name="column_order" value="">';
                        $html .= '</form>';
                    }
                    $html .= '</div>';
                }

                $html .= '<div class="fc-db-add-column card border-0 bg-body-tertiary">';
                $html .= '<div class="card-body">';
                $html .= '<form method="post" class="row g-3 align-items-end" data-fc-db-editor-form>';
                $html .= '<input type="hidden" name="fc_db_editor_action" value="add_column">';
                $html .= '<input type="hidden" name="table_name" value="' . $tableEscaped . '">';
                $html .= '<div class="col-md-5">';
                $html .= '<label class="form-label text-muted">Column name</label>';
                $html .= '<input type="text" name="column_name" class="form-control" required pattern="[A-Za-z0-9_]+" placeholder="status">';
                $html .= '</div>';
                $html .= '<div class="col-md-5">';
                $html .= '<label class="form-label text-muted">Column type</label>';
                $html .= '<select name="column_type" class="form-select" required>';
                $html .= '<option value="" disabled selected>Select type</option>';
                foreach ($typeOptions as $option) {
                    $escapedOption = htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $html .= '<option value="' . $escapedOption . '">' . $escapedOption . '</option>';
                }
                $html .= '</select>';
                $html .= '</div>';
                $html .= '<div class="col-md-2">';
                $html .= '<button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg me-1"></i>Add</button>';
                $html .= '</div>';
                $html .= '</form>';
                $html .= '</div>';
                $html .= '</div>';

                $html .= '</div>';
                $html .= '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</section>';
        }

        $html .= '</div>';

        if (!self::$scriptInjected) {
            $html .= self::renderInlineEditorScript();
            self::$scriptInjected = true;
        }

        self::resetFeedback();

        return $html;
    }

    private static function renderAlerts(array $messages, string $type): string
    {
        if ($messages === []) {
            return '';
        }

        $class = $type === 'danger' ? 'alert-danger' : 'alert-success';
        $html = '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">';
        foreach ($messages as $message) {
            $html .= '<div>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        }
        $html .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        $html .= '</div>';

        return $html;
    }

    private static function renderFeedbackHtml(): string
    {
        $errors = self::renderAlerts(self::$errors, 'danger');
        $messages = self::renderAlerts(self::$messages, 'success');

        return $errors . $messages;
    }

    private static function resetFeedback(): void
    {
        self::$messages = [];
        self::$errors = [];
    }

    /**
     * @return list<string>
     */
    private static function getColumnTypeOptions(string $driver): array
    {
        return match ($driver) {
            'pgsql' => [
                'BIGINT', 'BIGSERIAL', 'BOOLEAN', 'DATE', 'DECIMAL(10,2)', 'DOUBLE PRECISION', 'INTEGER', 'JSON', 'JSONB', 'NUMERIC(10,2)', 'SERIAL', 'SMALLINT', 'TEXT', 'TIMESTAMP', 'UUID', 'VARCHAR(255)'
            ],
            'sqlite' => [
                'INTEGER', 'REAL', 'TEXT', 'BLOB', 'NUMERIC'
            ],
            default => [
                'BIGINT', 'BINARY(255)', 'BIT', 'BOOLEAN', 'CHAR(36)', 'DATE', 'DATETIME', 'DECIMAL(10,2)', 'DOUBLE', 'FLOAT', 'INT', 'JSON', 'LONGTEXT', 'MEDIUMTEXT', 'SMALLINT', 'TEXT', 'TIME', 'TIMESTAMP', 'TINYINT', 'TINYINT(1)', 'VARCHAR(255)'
            ],
        };
    }

    private static function buildTypeOptions(array $options, string $current): string
    {
        $html = '';
        $currentTrimmed = trim($current);
        $found = false;

        foreach ($options as $option) {
            $selected = strcasecmp($option, $currentTrimmed) === 0 ? ' selected' : '';
            if ($selected !== '') {
                $found = true;
            }

            $escaped = htmlspecialchars($option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<option value="' . $escaped . '"' . $selected . '>' . $escaped . '</option>';
        }

        if (!$found && $currentTrimmed !== '') {
            $escapedCurrent = htmlspecialchars($currentTrimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<option value="' . $escapedCurrent . '" selected>' . $escapedCurrent . '</option>' . $html;
        }

        return $html;
    }

    private static function renderInlineEditorScript(): string
    {
        return <<<'HTML'
<style>
@supports (scrollbar-gutter: stable both-edges) {
    html.fc-db-scrollbar-stable,
    html.fc-db-scrollbar-stable body {
        scrollbar-gutter: stable both-edges;
    }
}

@supports not (scrollbar-gutter: stable both-edges) {
    html.fc-db-scrollbar-stable,
    html.fc-db-scrollbar-stable body {
        overflow-y: scroll;
    }
}

.fastcrud-db-editor {
    --fc-db-radius: 1rem;
    --fc-db-radius-lg: 1.75rem;
    --fc-db-border: rgba(15, 23, 42, 0.08);
    --fc-db-border-strong: rgba(15, 23, 42, 0.18);
    --fc-db-muted: #6c757d;
    --fc-db-hero-gradient: linear-gradient(135deg, #2563eb 0%, #7c3aed 55%, #ec4899 110%);
    --fc-db-accent: #2563eb;
    --fc-db-accent-soft: rgba(37, 99, 235, 0.12);
    position: relative;
    z-index: 0;
    color: var(--bs-body-color);
}
.fastcrud-db-editor .card {
    border-radius: var(--fc-db-radius);
    border-color: var(--fc-db-border);
}
.fc-db-editor-hero {
    position: relative;
    border-radius: var(--fc-db-radius-lg);
    overflow: hidden;
    background: var(--fc-db-hero-gradient);
    color: #fff;
    box-shadow: 0 32px 60px rgba(79, 70, 229, 0.35);
}
.fc-db-hero__backdrop {
    position: absolute;
    inset: 0;
    background: radial-gradient(120% 90% at 15% 0%, rgba(255, 255, 255, 0.35) 0%, rgba(255, 255, 255, 0) 55%),
        radial-gradient(90% 80% at 85% 15%, rgba(96, 165, 250, 0.45) 0%, rgba(59, 130, 246, 0) 55%),
        var(--fc-db-hero-gradient);
    opacity: 0.9;
    pointer-events: none;
    transform: translateZ(0);
}
.fc-db-hero__content {
    position: relative;
    z-index: 1;
    color: #fff;
}
.fc-db-hero__header {
    row-gap: 0.75rem;
}
.fc-db-hero__title h2 {
    font-size: clamp(1.5rem, 1.2vw + 1.1rem, 2.1rem);
}
.fc-db-hero__title p {
    margin-top: 0.15rem;
}
.fc-db-editor-hero .text-muted,
.fc-db-editor-hero .text-body-secondary {
    color: rgba(255, 255, 255, 0.75) !important;
}
.fc-db-editor-hero .badge {
    color: #0f172a;
}
.fc-db-hero__metrics {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
    min-width: 0;
}
.fc-db-hero__metric {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.18);
    color: #fff;
    backdrop-filter: blur(6px);
    min-width: 0;
    max-width: 100%;
}
.fc-db-hero__metric-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.22);
    font-size: 0.9rem;
    flex: 0 0 auto;
}
.fc-db-hero__metric-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
    min-width: 0;
}
.fc-db-hero__metric-value {
    font-weight: 700;
    font-size: 0.95rem;
    display: inline-block;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.fc-db-hero__metric-label {
    font-size: 0.72rem;
    opacity: 0.78;
    display: block;
    min-width: 0;
}
.fc-db-editor-sidebar {
    border: 1px solid var(--fc-db-border);
    border-radius: var(--fc-db-radius);
    box-shadow: 0 24px 56px rgba(15, 23, 42, 0.12);
}
.fc-db-sidebar__header {
    background: rgba(15, 23, 42, 0.03);
}
.fc-db-sidebar__list {
    max-height: clamp(260px, 48vh, 520px);
    overflow-y: auto;
    padding-inline: 0.5rem;
}
.fc-db-sidebar__list::-webkit-scrollbar {
    width: 0.55rem;
}
.fc-db-sidebar__list::-webkit-scrollbar-thumb {
    background: rgba(99, 102, 241, 0.35);
    border-radius: 999px;
}
.fc-db-table-list {
    display: flex;
    flex-direction: column;
    gap: 0;
    margin: 0;
}
.fc-db-table-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    border: 1px solid transparent !important;
    border-radius: 0.75rem;
    margin: 0.35rem 0;
    padding: 0.9rem 1rem;
    transition: background-color 0.25s ease, box-shadow 0.25s ease, color 0.25s ease;
    color: inherit;
    text-decoration: none;
    width: 100%;
}
.fc-db-table-link:hover,
.fc-db-table-link:focus,
.fc-db-table-link.active {
    background-color: var(--fc-db-accent-soft) !important;
    background: var(--fc-db-accent-soft) !important;
    color: inherit !important;
}
.fc-db-table-link:focus-visible {
    outline: none;
    box-shadow: none;
}
.fc-db-table-link:focus {
    outline: none;
    box-shadow: none !important;
}
.fc-db-table-link.active {
    box-shadow: none !important;
    border-color: transparent !important;
}
.fc-db-table-link .badge {
    border-radius: 999px;
    font-size: 0.75rem;
    background: rgba(15, 23, 42, 0.07);
}
.fc-db-sidebar__empty {
    border-radius: var(--fc-db-radius);
    background: rgba(15, 23, 42, 0.03);
    margin: 0.75rem;
}
.fc-db-editor-tab-content > .tab-pane {
    transition: none;
}
.fc-db-table {
    border-radius: var(--fc-db-radius);
    border: 1px solid var(--fc-db-border);
    box-shadow: 0 30px 60px rgba(15, 23, 42, 0.12);
}
.fc-db-table__header {
    background: rgba(15, 23, 42, 0.02);
}
.fc-db-table__tip {
    border-radius: var(--fc-db-radius);
    border: 1px solid rgba(37, 99, 235, 0.1);
    background: rgba(37, 99, 235, 0.08);
}
.fc-db-columns-table thead th {
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #475569;
    border-bottom: 1px solid rgba(148, 163, 184, 0.35);
}
.fc-db-columns-table tbody tr:hover {
    background: rgba(37, 99, 235, 0.05);
}
.fc-db-columns-table td,
.fc-db-columns-table th {
    border-color: rgba(148, 163, 184, 0.22);
}
.fc-db-columns-table .badge {
    font-size: 0.7rem;
}
.fc-db-columns-table [data-fc-inline-trigger] {
    padding-inline: 0.25rem;
    border-radius: 0.5rem;
}
.fc-db-add-column {
    border-radius: var(--fc-db-radius);
    border: 1px dashed rgba(79, 70, 229, 0.4);
    background: rgba(79, 70, 229, 0.04);
}
.fc-db-add-column:hover {
    border-color: rgba(79, 70, 229, 0.55);
    background: rgba(79, 70, 229, 0.08);
}
.fc-db-editor-empty {
    border-radius: var(--fc-db-radius);
    border: 1px dashed rgba(148, 163, 184, 0.4);
    background: rgba(241, 245, 249, 0.6);
}
.fastcrud-db-editor [data-fc-db-reorder-handle] {
    cursor: grab;
    width: 2.5rem;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.fastcrud-db-editor [data-fc-db-reorder-handle]:hover {
    color: #2563eb;
}
.fastcrud-db-editor [data-fc-db-reorder-handle]:active {
    cursor: grabbing;
}
.fastcrud-db-editor .fc-db-reorder-ghost {
    opacity: 0.6;
}
.fastcrud-db-editor .fc-db-reorder-chosen {
    background-color: rgba(37, 99, 235, 0.08);
}
.fastcrud-db-editor [data-fc-inline-trigger] {
    cursor: pointer;
}
.fastcrud-db-editor [data-fc-inline-trigger]:focus-visible {
    outline: 3px solid rgba(59, 130, 246, 0.35);
    outline-offset: 2px;
    border-radius: 0.5rem;
}
.fastcrud-db-editor .fc-db-inline-editing {
    background-color: rgba(59, 130, 246, 0.1);
    border-radius: 0.5rem;
    padding: 0.25rem 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.fastcrud-db-editor .fc-db-inline-form input,
.fastcrud-db-editor .fc-db-inline-form select {
    min-width: 180px;
}
.fastcrud-db-editor.fc-db-editor-loading {
    pointer-events: none;
}
.fastcrud-db-editor.fc-db-editor-loading::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.65);
    backdrop-filter: blur(2px);
    z-index: 20;
}
.fastcrud-db-editor.fc-db-editor-loading::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 3rem;
    height: 3rem;
    margin: -1.5rem 0 0 -1.5rem;
    border-radius: 50%;
    border: 4px solid rgba(59, 130, 246, 0.25);
    border-top-color: rgba(59, 130, 246, 0.9);
    z-index: 21;
}
@media (prefers-reduced-motion: reduce) {
    .fc-db-table-link,
    .fc-db-hero__metric,
    .fc-db-add-column,
    .fc-db-editor-tab-content > .tab-pane {
        transition: none !important;
    }
}
@media (max-width: 991.98px) {
    .fc-db-editor-hero {
        border-radius: var(--fc-db-radius);
    }
    .fc-db-sidebar__list {
        max-height: none;
    }
}
:root[data-bs-theme="dark"] .fastcrud-db-editor,
[data-bs-theme="dark"] .fastcrud-db-editor,
.fastcrud-db-editor[data-bs-theme="dark"] {
    --fc-db-border: rgba(148, 163, 184, 0.24);
    --fc-db-border-strong: rgba(148, 163, 184, 0.32);
    --fc-db-accent: #3b82f6;
    --fc-db-accent-soft: rgba(59, 130, 246, 0.22);
    color: #e2e8f0;
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-hero__metric,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-hero__metric,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-hero__metric {
    background: rgba(30, 41, 59, 0.68);
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-hero__metric-icon,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-hero__metric-icon,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-hero__metric-icon {
    background: rgba(255, 255, 255, 0.18);
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-editor-sidebar,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-editor-sidebar,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-editor-sidebar,
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-table,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-table,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-table,
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-add-column,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-add-column,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-add-column {
    background: rgba(15, 23, 42, 0.9);
    color: #e2e8f0;
    border-color: var(--fc-db-border);
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-table-link,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-table-link,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-table-link {
    color: #e2e8f0;
    background: rgba(15, 23, 42, 0.55);
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-table-link:hover,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-table-link:hover,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-table-link:hover {
    background: var(--fc-db-accent-soft);
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-sidebar__header,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-sidebar__header,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-sidebar__header {
    background: rgba(15, 23, 42, 0.35);
    border-color: var(--fc-db-border);
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-table__tip,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-table__tip,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-table__tip {
    background: rgba(37, 99, 235, 0.2);
    color: #e2e8f0;
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-columns-table thead th,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-columns-table thead th,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-columns-table thead th {
    color: rgba(226, 232, 240, 0.8);
}
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-columns-table td,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-columns-table td,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-columns-table td,
:root[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-columns-table th,
[data-bs-theme="dark"] .fastcrud-db-editor .fc-db-columns-table th,
.fastcrud-db-editor[data-bs-theme="dark"] .fc-db-columns-table th {
    border-color: rgba(51, 65, 85, 0.6);
}
:root[data-bs-theme="dark"] .fastcrud-db-editor.fc-db-editor-loading::after,
[data-bs-theme="dark"] .fastcrud-db-editor.fc-db-editor-loading::after,
.fastcrud-db-editor[data-bs-theme="dark"].fc-db-editor-loading::after {
    background: rgba(15, 23, 42, 0.55);
}

@media (prefers-color-scheme: dark) {
    :root:not([data-bs-theme]),
    :root[data-bs-theme="auto"] {
        color-scheme: dark;
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor,
    :root[data-bs-theme="auto"] .fastcrud-db-editor {
        --fc-db-border: rgba(148, 163, 184, 0.24);
        --fc-db-border-strong: rgba(148, 163, 184, 0.32);
        --fc-db-accent: #3b82f6;
        --fc-db-accent-soft: rgba(59, 130, 246, 0.22);
        color: #e2e8f0;
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-hero__metric,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-hero__metric {
        background: rgba(30, 41, 59, 0.68);
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-hero__metric-icon,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-hero__metric-icon {
        background: rgba(255, 255, 255, 0.18);
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-editor-sidebar,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-editor-sidebar,
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-table,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-table,
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-add-column,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-add-column {
        background: rgba(15, 23, 42, 0.9);
        color: #e2e8f0;
        border-color: var(--fc-db-border);
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-table-link,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-table-link {
        color: #e2e8f0;
        background: rgba(15, 23, 42, 0.55);
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-table-link:hover,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-table-link:hover {
        background: var(--fc-db-accent-soft);
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-sidebar__header,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-sidebar__header {
        background: rgba(15, 23, 42, 0.35);
        border-color: var(--fc-db-border);
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-table__tip,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-table__tip {
        background: rgba(37, 99, 235, 0.2);
        color: #e2e8f0;
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-columns-table thead th,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-columns-table thead th {
        color: rgba(226, 232, 240, 0.8);
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-columns-table td,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-columns-table td,
    :root:not([data-bs-theme]) .fastcrud-db-editor .fc-db-columns-table th,
    :root[data-bs-theme="auto"] .fastcrud-db-editor .fc-db-columns-table th {
        border-color: rgba(51, 65, 85, 0.6);
    }
    :root:not([data-bs-theme]) .fastcrud-db-editor.fc-db-editor-loading::after,
    :root[data-bs-theme="auto"] .fastcrud-db-editor.fc-db-editor-loading::after {
        background: rgba(15, 23, 42, 0.55);
    }
}
</style>
<script>
(function(){
    if (window.FastCrudDbEditorInlineInitialised) {
        return;
    }
    window.FastCrudDbEditorInlineInitialised = true;

    var columnSortables = [];
    var sortableScriptCallbacks = [];
    var sortableScriptRequested = false;
    var tableSearchTeardown = null;
    var suppressTableAutoScroll = false;

    function getEditorRoot() {
        return document.querySelector('.fastcrud-db-editor');
    }

    function stabiliseScrollbarGutter() {
        var root = document.documentElement;
        var body = document.body;
        if (!root) {
            return;
        }
        if (!root.classList.contains('fc-db-scrollbar-stable')) {
            root.classList.add('fc-db-scrollbar-stable');
        }
        if (body && !body.classList.contains('fc-db-scrollbar-stable')) {
            body.classList.add('fc-db-scrollbar-stable');
        }
    }

    function loadSortable(callback) {
        if (typeof callback !== 'function') {
            return;
        }

        if (typeof window.Sortable === 'function') {
            callback();
            return;
        }

        sortableScriptCallbacks.push(callback);

        if (sortableScriptRequested) {
            return;
        }

        var existing = document.querySelector('script[data-fc-db-sortable]');
        if (existing) {
            sortableScriptRequested = true;
            existing.addEventListener('load', function () {
                var callbacks = sortableScriptCallbacks.slice();
                sortableScriptCallbacks = [];
                callbacks.forEach(function (cb) { cb(); });
            }, { once: true });
            existing.addEventListener('error', function () {
                sortableScriptRequested = false;
                sortableScriptCallbacks = [];
                console.error('FastCRUD: failed to load SortableJS.');
            }, { once: true });
            return;
        }

        sortableScriptRequested = true;
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
        script.async = true;
        script.setAttribute('data-fc-db-sortable', '1');
        script.onload = function () {
            var callbacks = sortableScriptCallbacks.slice();
            sortableScriptCallbacks = [];
            callbacks.forEach(function (cb) { cb(); });
        };
        script.onerror = function () {
            sortableScriptRequested = false;
            sortableScriptCallbacks = [];
            console.error('FastCRUD: failed to load SortableJS.');
        };

        document.head.appendChild(script);
    }

    function destroyColumnSortables() {
        columnSortables.forEach(function (instance) {
            if (instance && typeof instance.destroy === 'function') {
                instance.destroy();
            }
        });
        columnSortables = [];
    }

    function applyColumnOrderFromString(list, orderString) {
        if (!list || !orderString) {
            return;
        }

        var order = orderString.split(',').map(function (value) {
            return value.trim();
        }).filter(function (value) {
            return value !== '';
        });

        if (!order.length) {
            return;
        }

        var rows = Array.prototype.slice.call(list.querySelectorAll('[data-fc-db-column]'));
        var lookup = {};
        rows.forEach(function (row) {
            var name = row.getAttribute('data-fc-db-column');
            if (name) {
                lookup[name] = row;
            }
        });

        order.forEach(function (name) {
            var row = lookup[name];
            if (row) {
                list.appendChild(row);
            }
        });
    }

    function updateFeedback(content) {
        var container = document.querySelector('[data-fc-db-feedback]');
        if (!container) {
            return;
        }
        container.innerHTML = content;
    }

    function buildAlertHtml(type, message) {
        var cssClass = type === 'danger' ? 'alert-danger' : 'alert-success';
        return '<div class="alert ' + cssClass + ' alert-dismissible fade show" role="alert">'
            + message
            + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
            + '</div>';
    }

    function extractFeedbackFromHtml(html) {
        if (!html) {
            return null;
        }

        try {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var feedback = doc.querySelector('[data-fc-db-feedback]');
            return feedback ? feedback.innerHTML : null;
        } catch (parseError) {
            console.warn('FastCRUD: unable to parse HTML response for feedback.', parseError);
        }

        return null;
    }

    function updateHeroFromLink(link) {
        if (!link) {
            return;
        }

        var root = getEditorRoot();
        if (!root) {
            return;
        }

        var labelEl = root.querySelector('[data-fc-db-active-table]');
        if (labelEl) {
            var fallback = labelEl.getAttribute('data-fc-db-active-default') || '';
            var newLabel = link.getAttribute('data-fc-db-table-label') || fallback;
            labelEl.textContent = newLabel || fallback;
        }

        var countEl = root.querySelector('[data-fc-db-active-count]');
        if (countEl) {
            var countAttr = link.getAttribute('data-fc-db-table-columns');
            var numeric = countAttr ? parseInt(countAttr, 10) : NaN;
            if (!Number.isNaN(numeric)) {
                var summary = numeric === 1 ? '1 column' : numeric + ' columns';
                countEl.textContent = summary;
                countEl.setAttribute('data-fc-db-active-count-value', String(numeric));
            }
        }
    }

    function updateHeroFromActiveLink() {
        var root = getEditorRoot();
        if (!root) {
            return;
        }

        var active = root.querySelector('[data-fc-db-table-label].active');
        if (active) {
            updateHeroFromLink(active);
        }
    }

    function scrollContainerTo(container, top) {
        if (!container) {
            return;
        }

        var nextTop = Math.max(top, 0);

        if (typeof container.scrollTo === 'function') {
            try {
                container.scrollTo({ top: nextTop, behavior: 'smooth' });
                return;
            } catch (scrollError) {
                // Fallback to direct assignment when smooth scrolling is unsupported.
            }
        }

        container.scrollTop = nextTop;
    }

    function scrollActiveTableIntoView(link) {
        if (suppressTableAutoScroll) {
            suppressTableAutoScroll = false;
            return;
        }

        var root = getEditorRoot();
        if (!root) {
            return;
        }

        var container = root.querySelector('.fc-db-sidebar__list');
        if (!container) {
            return;
        }

        var target = link || root.querySelector('[data-fc-db-table-label].active');
        if (!target) {
            return;
        }

        var containerRect = container.getBoundingClientRect();
        var targetRect = target.getBoundingClientRect();
        var buffer = 8;
        var containerScrollTop = container.scrollTop;
        var viewportTop = containerScrollTop;
        var viewportBottom = viewportTop + container.clientHeight;
        var targetTop = targetRect.top - containerRect.top + containerScrollTop;
        var targetBottom = targetRect.bottom - containerRect.top + containerScrollTop;

        if (targetBottom <= viewportTop) {
            scrollContainerTo(container, targetTop - buffer);
            return;
        }

        if (targetTop >= viewportBottom) {
            scrollContainerTo(container, targetBottom - container.clientHeight + buffer);
        }
    }

    function initTableSearch() {
        if (tableSearchTeardown) {
            tableSearchTeardown();
            tableSearchTeardown = null;
        }

        var root = getEditorRoot();
        if (!root) {
            return;
        }

        var input = root.querySelector('[data-fc-db-table-search]');
        var list = root.querySelector('[data-fc-db-table-list]');
        var emptyState = root.querySelector('[data-fc-db-sidebar-empty]');

        if (!input || !list) {
            if (emptyState) {
                emptyState.classList.add('d-none');
            }
            return;
        }

        var items = Array.prototype.slice.call(list.querySelectorAll('[data-fc-db-table-label]'));
        if (!items.length) {
            if (emptyState) {
                emptyState.classList.remove('d-none');
            }
            return;
        }

        var filter = function () {
            var term = input.value.trim().toLowerCase();
            var matches = 0;

            items.forEach(function (item) {
                var name = (item.getAttribute('data-fc-db-table-name') || '').toLowerCase();
                var match = term === '' || name.indexOf(term) !== -1;
                item.classList.toggle('d-none', !match);
                if (match) {
                    matches += 1;
                }
            });

            if (emptyState) {
                emptyState.classList.toggle('d-none', matches > 0);
            }
        };

        input.addEventListener('input', filter);
        input.addEventListener('search', filter);
        filter();

        tableSearchTeardown = function () {
            input.removeEventListener('input', filter);
            input.removeEventListener('search', filter);
        };
    }

    function submitReorderForm(form, list, fallbackOrder) {
        if (!form || form.dataset.fcAjaxSubmitting === '1') {
            return;
        }

        form.dataset.fcAjaxSubmitting = '1';

        var editor = getEditorRoot();
        if (editor) {
            editor.classList.add('fc-db-editor-loading');
        }

        var action = form.getAttribute('action') || window.location.href;
        var method = (form.getAttribute('method') || 'POST').toUpperCase();
        if (method !== 'POST') {
            method = 'POST';
        }

        var formData = new FormData(form);

        fetch(action, {
            method: method,
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-FastCrud-Db-Editor': '1',
                'X-FastCrud-Db-Reorder': '1',
                'Accept': 'application/json, text/html;q=0.9'
            },
            credentials: 'same-origin'
        })
        .then(function (response) {
            return response.text().then(function (text) {
                return {
                    ok: response.ok,
                    text: text,
                    contentType: response.headers.get('Content-Type') || ''
                };
            });
        })
        .then(function (payload) {
            var handled = false;
            var contentType = payload.contentType.toLowerCase();

            if (contentType.indexOf('application/json') !== -1) {
                try {
                    var json = payload.text ? JSON.parse(payload.text) : {};
                    if (json && typeof json.feedback === 'string') {
                        updateFeedback(json.feedback);
                        handled = true;
                    }
                } catch (parseError) {
                    console.warn('FastCRUD: failed to parse JSON response.', parseError);
                }
            }

            if (!handled) {
                var feedbackHtml = extractFeedbackFromHtml(payload.text);
                if (feedbackHtml !== null) {
                    updateFeedback(feedbackHtml);
                    handled = true;
                }
            }

            if (!payload.ok) {
                throw new Error('Reorder request failed');
            }
            if (!handled) {
                updateFeedback('');
            }
        })
        .catch(function (error) {
            console.error('FastCRUD: column reorder request failed.', error);
            updateFeedback(buildAlertHtml('danger', 'Column order could not be saved. Please try again.'));
            if (list && typeof fallbackOrder === 'string' && fallbackOrder !== '') {
                applyColumnOrderFromString(list, fallbackOrder);
                list.dataset.fcDbInitialOrder = fallbackOrder;
            }
        })
        .finally(function () {
            delete form.dataset.fcAjaxSubmitting;
            var root = getEditorRoot();
            if (root) {
                root.classList.remove('fc-db-editor-loading');
            }
        });
    }

    function handleColumnReorder(list) {
        if (!list) {
            return;
        }

        var rows = list.querySelectorAll('[data-fc-db-column]');
        if (!rows.length) {
            return;
        }

        var order = Array.prototype.map.call(rows, function (row) {
            return row.getAttribute('data-fc-db-column');
        }).filter(function (value) {
            return typeof value === 'string' && value !== '';
        });

        if (!order.length) {
            return;
        }

        var previousOrder = list.dataset.fcDbInitialOrder || '';
        var serialized = order.join(',');
        if (previousOrder === serialized) {
            return;
        }
        list.dataset.fcDbInitialOrder = serialized;

        var container = list.closest('.fastcrud-db-editor__columns');
        if (!container) {
            return;
        }

        var form = container.querySelector('[data-fc-db-reorder-form]');
        if (!form) {
            return;
        }

        var input = form.querySelector('input[name="column_order"]');
        if (!input) {
            return;
        }

        input.value = serialized;
        submitReorderForm(form, list, previousOrder);
    }

    function initColumnSortables() {
        var root = getEditorRoot();
        if (!root) {
            destroyColumnSortables();
            return;
        }

        var lists = root.querySelectorAll('[data-fc-db-columns]');
        if (!lists.length) {
            destroyColumnSortables();
            return;
        }

        loadSortable(function () {
            destroyColumnSortables();

            lists.forEach(function (list) {
                var order = Array.prototype.map.call(list.querySelectorAll('[data-fc-db-column]'), function (row) {
                    return row.getAttribute('data-fc-db-column');
                }).filter(function (value) {
                    return typeof value === 'string' && value !== '';
                });

                list.dataset.fcDbInitialOrder = order.join(',');

                var sortable = new window.Sortable(list, {
                    animation: 0,
                    handle: '[data-fc-db-reorder-handle]',
                    ghostClass: 'fc-db-reorder-ghost',
                    chosenClass: 'fc-db-reorder-chosen',
                    dragClass: 'fc-db-reorder-drag',
                    onEnd: function (evt) {
                        handleColumnReorder(evt.to || list);
                    }
                });

                columnSortables.push(sortable);
            });

            scrollActiveTableIntoView();
        });
    }

    function showForm(container) {
        var trigger = container.querySelector('[data-fc-inline-trigger]');
        var form = container.querySelector('[data-fc-inline-form]');
        if (!trigger || !form) {
            return;
        }

        container.classList.add('fc-db-inline-editing');
        trigger.classList.add('d-none');
        form.classList.remove('d-none');
        form.dataset.fcInlineOpen = '1';

        var field = getFocusableField(form);
        if (field) {
            if (field.classList && field.classList.contains('fc-db-type-select')) {
                initialiseTypeSelect(field);
            }
            focusField(field);
        }
    }

    function hideForm(container) {
        if (!container) {
            return;
        }
        var trigger = container.querySelector('[data-fc-inline-trigger]');
        var form = container.querySelector('[data-fc-inline-form]');
        if (!trigger || !form) {
            return;
        }

        var fieldType = container.getAttribute('data-fc-inline-container');
        var control = form.querySelector('input, select, textarea');

        if (control) {
            var newValue = '';
            if (control.tagName === 'SELECT') {
                var selectedOption = control.options[control.selectedIndex];
                newValue = selectedOption ? selectedOption.text : control.value;
            } else {
                newValue = control.value;
            }

            newValue = newValue.trim();
            if (newValue !== '') {
                if (fieldType === 'name') {
                    trigger.textContent = newValue;
                } else if (fieldType === 'type') {
                    trigger.textContent = newValue;
                }
            }
        }

        form.classList.add('d-none');
        trigger.classList.remove('d-none');
        container.classList.remove('fc-db-inline-editing');
        delete form.dataset.fcInlineOpen;
    }

    function getFocusableField(form) {
        var candidates = form.querySelectorAll('input, select, textarea');
        for (var i = 0; i < candidates.length; i += 1) {
            var candidate = candidates[i];
            if (candidate.disabled) {
                continue;
            }
            if (candidate.tagName === 'INPUT') {
                var type = candidate.getAttribute('type');
                if (!type) {
                    type = candidate.type;
                }
                type = type ? type.toLowerCase() : 'text';
                if (type === 'hidden' || type === 'submit' || type === 'button' || type === 'checkbox' || type === 'radio') {
                    continue;
                }
            }
            if (candidate.offsetParent === null) {
                continue;
            }
            return candidate;
        }
        return null;
    }

    function focusField(field) {
        if (!field) {
            return;
        }
        var raf = window.requestAnimationFrame ? window.requestAnimationFrame.bind(window) : function (cb) {
            return setTimeout(cb, 16);
        };
        var attempts = 0;
        var tryFocus = function () {
            attempts += 1;
            if (typeof field.focus === 'function') {
                try {
                    field.focus({ preventScroll: true });
                } catch (e) {
                    field.focus();
                }
            }
            if (document.activeElement === field) {
                var isTextInput = field.tagName === 'INPUT' || field.tagName === 'TEXTAREA';
                if (isTextInput && typeof field.value === 'string') {
                    try {
                        var length = field.value.length;
                        if (typeof field.setSelectionRange === 'function') {
                            field.setSelectionRange(length, length);
                        }
                    } catch (e) {
                        /* ignore selection errors */
                    }
                }
            }
            if (document.activeElement !== field && attempts < 5) {
                raf(tryFocus);
            }
        };

        raf(tryFocus);
    }

    function initialiseTypeSelect(select) {
        var alreadyInitialised = select.dataset.fcSelectInitialised === '1';

        if (window.jQuery && typeof window.jQuery.fn.select2 === 'function') {
            var $select = window.jQuery(select);
            if (!alreadyInitialised) {
                $select.select2({
                    width: 'style',
                    dropdownParent: $select.closest('form')
                });
                select.dataset.fcSelectInitialised = '1';

                $select.on('select2:close', function () {
                    var form = select.closest('form');
                    if (form) {
                        submitForm(form);
                    }
                });
            }
            setTimeout(function () {
                $select.select2('open');
            }, 0);
        } else if (!alreadyInitialised) {
            select.dataset.fcSelectInitialised = '1';
        }
    }

    function submitForm(form) {
        if (!form || form.dataset.fcInlineSubmitting === '1') {
            return;
        }
        form.dataset.fcInlineSubmitting = '1';

        var container = form.closest('[data-fc-inline-container]');
        hideForm(container);

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    function replaceEditorFromHtml(html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var updated = doc.querySelector('.fastcrud-db-editor');
        var current = getEditorRoot();
        if (!updated || !current) {
            return false;
        }

        current.replaceWith(updated);
        initColumnSortables();
        initTableSearch();
        updateHeroFromActiveLink();
        scrollActiveTableIntoView();
        return true;
    }

    function handleAjaxSubmit(event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (!form.hasAttribute('data-fc-db-editor-form')) {
            return;
        }

        var confirmMessage = null;
        if (event.submitter && event.submitter.hasAttribute('data-fc-db-confirm')) {
            confirmMessage = event.submitter.getAttribute('data-fc-db-confirm');
        } else if (form.hasAttribute('data-fc-db-confirm')) {
            confirmMessage = form.getAttribute('data-fc-db-confirm');
        }

        if (confirmMessage && !window.confirm(confirmMessage)) {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        if (form.dataset.fcAjaxSubmitting === '1') {
            return;
        }

        form.dataset.fcAjaxSubmitting = '1';
        var editor = getEditorRoot();
        if (editor) {
            editor.classList.add('fc-db-editor-loading');
        }

        var action = form.getAttribute('action') || window.location.href;
        var method = (form.getAttribute('method') || 'POST').toUpperCase();
        if (method !== 'POST') {
            method = 'POST';
        }

        var formData = new FormData(form);
        if (event.submitter && event.submitter.name) {
            formData.append(event.submitter.name, event.submitter.value);
        }

        var inlineContainer = form.closest('[data-fc-inline-container]');
        if (inlineContainer) {
            hideForm(inlineContainer);
        }

        fetch(action, {
            method: method,
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-FastCrud-Db-Editor': '1',
                'Accept': 'text/html'
            },
            credentials: 'same-origin'
        })
        .then(function (response) {
            return response.text();
        })
        .then(function (html) {
            if (!replaceEditorFromHtml(html)) {
                window.location.reload();
            }
        })
        .catch(function () {
            window.location.reload();
        })
        .finally(function () {
            delete form.dataset.fcAjaxSubmitting;
            delete form.dataset.fcInlineSubmitting;
            var root = getEditorRoot();
            if (root) {
                root.classList.remove('fc-db-editor-loading');
            }
        });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-fc-inline-trigger]');
        if (trigger) {
            event.preventDefault();
            var container = trigger.closest('[data-fc-inline-container]');
            if (container) {
                showForm(container);
            }
            return;
        }

        var tableLink = event.target.closest('[data-fc-db-table-label]');
        if (tableLink) {
            suppressTableAutoScroll = true;
            updateHeroFromLink(tableLink);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var form = event.target.closest('[data-fc-inline-form]');
            if (!form) {
                return;
            }
            event.preventDefault();
            hideForm(form.closest('[data-fc-inline-container]'));
            return;
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        var trigger = event.target.closest('[data-fc-inline-trigger]');
        if (trigger) {
            event.preventDefault();
            var container = trigger.closest('[data-fc-inline-container]');
            if (container) {
                showForm(container);
            }
            return;
        }

        if (event.key === 'Enter') {
            var form = event.target.closest('[data-fc-inline-form]');
            if (form) {
                event.preventDefault();
                submitForm(form);
            }
        }
    });

    document.addEventListener('change', function (event) {
        var form = event.target.closest('[data-fc-inline-form]');
        if (!form) {
            return;
        }
        submitForm(form);
    });

    document.addEventListener('focusout', function (event) {
        var form = event.target.closest('[data-fc-inline-form]');
        if (!form || form.dataset.fcInlineSubmitting === '1') {
            return;
        }

        setTimeout(function () {
            if (!form.contains(document.activeElement)) {
                submitForm(form);
            }
        }, 100);
    });

    document.addEventListener('pointerdown', function (event) {
        if (event.target.closest('[data-fc-inline-form]')) {
            return;
        }
        if (event.target.closest('[data-fc-inline-trigger]')) {
            return;
        }
        if (event.target.closest('.select2-container')) {
            return;
        }

        var openForms = document.querySelectorAll('[data-fc-inline-form][data-fc-inline-open="1"]');
        openForms.forEach(function (form) {
            if (!form.contains(event.target)) {
                submitForm(form);
            }
        });
    });

    document.addEventListener('shown.bs.tab', function (event) {
        var target = event.target;
        if (target && target.matches('[data-fc-db-table-label]')) {
            updateHeroFromLink(target);
            scrollActiveTableIntoView(target);
        }
    });

    stabiliseScrollbarGutter();
    initColumnSortables();
    initTableSearch();
    updateHeroFromActiveLink();
    scrollActiveTableIntoView();
    document.addEventListener('submit', handleAjaxSubmit, true);
})();
</script>
HTML;
    }

    private static function isValidIdentifier(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    private static function quoteIdentifier(string $identifier, string $driver): string
    {
        if (!self::isValidIdentifier($identifier)) {
            throw new LogicException('Invalid identifier for quoting.');
        }

        return match ($driver) {
            'pgsql', 'sqlite' => '"' . $identifier . '"',
            default => '`' . $identifier . '`',
        };
    }

    private static function isSafeType(string $type): bool
    {
        if ($type === '') {
            return false;
        }

        return preg_match('/^[A-Za-z0-9_(), \"\']+$/', $type) === 1;
    }

    private static function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
