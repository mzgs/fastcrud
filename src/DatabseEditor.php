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

    public static function init(?array $dbConfig = null): void
    {
        if ($dbConfig !== null) {
            CrudConfig::setDbConfig($dbConfig);
            DB::disconnect();
        }

        self::$initialized = true;
    }

    public static function render(): string
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

        return self::renderHtml($tables, $tableColumns, $driver);
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
        if ((($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return;
        }

        $action = isset($_POST['fc_db_editor_action']) ? (string) $_POST['fc_db_editor_action'] : '';
        $action = trim($action);
        if ($action === '') {
            return;
        }

        try {
            match ($action) {
                'add_table' => self::handleAddTable($connection, $driver),
                'rename_table' => self::handleRenameTable($connection, $driver),
                'add_column' => self::handleAddColumn($connection, $driver),
                'rename_column' => self::handleRenameColumn($connection, $driver),
                'change_column_type' => self::handleChangeColumnType($connection, $driver),
                default => null,
            };
        } catch (PDOException $exception) {
            self::$errors[] = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } catch (RuntimeException $exception) {
            self::$errors[] = htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
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
    }

    private static function handleAddColumn(PDO $connection, string $driver): void
    {
        $table = isset($_POST['table_name']) ? trim((string) $_POST['table_name']) : '';
        $column = isset($_POST['column_name']) ? trim((string) $_POST['column_name']) : '';
        $type = isset($_POST['column_type']) ? trim((string) $_POST['column_type']) : '';

        if (!self::isValidIdentifier($table) || !self::isValidIdentifier($column)) {
            throw new RuntimeException('Table and column names must contain only letters, numbers, or underscores.');
        }

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

    private static function renderHtml(array $tables, array $tableColumns, string $driver): string
    {
        $messages = self::renderAlerts(self::$messages, 'success');
        $errors = self::renderAlerts(self::$errors, 'danger');

        $html = '<div class="fastcrud-db-editor d-flex flex-column gap-4">';
        $html .= $errors . $messages;

        $html .= '<section class="fastcrud-db-editor__add-table">';
        $html .= '<div class="card shadow-sm border-0">';
        $html .= '<div class="card-header bg-primary text-white">';
        $html .= '<div class="d-flex align-items-center gap-2">';
        $html .= '<span class="fs-5"><i class="bi bi-plus-circle me-2"></i>Create Table</span>';
        $html .= '<span class="text-white-50 small">Start with a primary key column automatically.</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="card-body">';
        $html .= '<form method="post" class="row g-3 align-items-end" data-fc-db-editor-form>';
        $html .= '<input type="hidden" name="fc_db_editor_action" value="add_table">';
        $html .= '<div class="col-md-8">';
        $html .= '<label class="form-label text-muted">Table name</label>';
        $html .= '<input type="text" name="new_table" class="form-control" placeholder="e.g. posts" required pattern="[A-Za-z0-9_]+">';
        $html .= '</div>';
        $html .= '<div class="col-md-4">';
        $html .= '<button type="submit" class="btn btn-success w-100"><i class="bi bi-check-circle me-1"></i>Create Table</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</section>';

        $html .= '<section class="fastcrud-db-editor__tables">';
        $html .= '<div class="d-flex justify-content-between align-items-center mb-3">';
        $html .= '<h2 class="h5 mb-0">Schema Overview</h2>';
        $html .= '<span class="badge bg-secondary">' . count($tables) . ' tables</span>';
        $html .= '</div>';

        if ($tables === []) {
            $html .= '<div class="alert alert-info" role="alert">No tables found for the current connection.</div>';
        } else {
            $typeOptions = self::getColumnTypeOptions($driver);
            $html .= '<div class="row g-4">';
            $html .= '<div class="col-12 col-lg-4 col-xl-3">';
            $html .= '<div class="card shadow-sm border-0 h-100">';
            $html .= '<div class="card-header bg-body-tertiary fw-semibold">Tables</div>';
            $html .= '<div class="list-group list-group-flush" id="fc-db-editor-table-list" role="tablist">';
            foreach ($tables as $index => $table) {
                $tableEscaped = htmlspecialchars($table, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $tabId = 'fc-db-editor-table-' . $index;
                $isActive = $index === 0 ? ' active' : '';
                $ariaSelected = $index === 0 ? 'true' : 'false';

                $html .= '<a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center' . $isActive . '" id="tab-' . $tabId . '" data-bs-toggle="list" href="#' . $tabId . '" role="tab" aria-controls="' . $tabId . '" aria-selected="' . $ariaSelected . '">';
                $html .= '<span><i class="bi bi-table text-primary me-2"></i>' . $tableEscaped . '</span>';
                $columnCount = count($tableColumns[$table] ?? []);
                $html .= '<span class="badge rounded-pill bg-primary-subtle text-primary">' . $columnCount . '</span>';
                $html .= '</a>';
            }
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';

            $html .= '<div class="col-12 col-lg-8 col-xl-9">';
            $html .= '<div class="tab-content" id="fc-db-editor-table-content">';
            foreach ($tables as $index => $table) {
                $tableEscaped = htmlspecialchars($table, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $tabId = 'fc-db-editor-table-' . $index;
                $isActive = $index === 0 ? ' show active' : '';

                $html .= '<div class="tab-pane fade' . $isActive . '" id="' . $tabId . '" role="tabpanel" aria-labelledby="tab-' . $tabId . '">';
                $html .= '<div class="card shadow-sm border-0">';
                $html .= '<div class="card-body">';
                $html .= '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">';
                $html .= '<div class="d-flex align-items-center gap-2">';
                $html .= '<div data-fc-inline-container="name">';
                $html .= '<span class="h5 mb-0 fw-semibold fc-db-inline-trigger link-primary" data-fc-inline-trigger role="button" tabindex="0" title="Click to rename table">' . $tableEscaped . '</span>';
                $html .= '<form method="post" class="fc-db-inline-form d-inline-flex align-items-center gap-2 d-none" data-fc-inline-form data-fc-db-editor-form>';
                $html .= '<input type="hidden" name="fc_db_editor_action" value="rename_table">';
                $html .= '<input type="hidden" name="current_table" value="' . $tableEscaped . '">';
                $html .= '<input type="text" name="new_table_name" class="form-control form-control-sm" value="' . $tableEscaped . '" required pattern="[A-Za-z0-9_]+" placeholder="Table name">';
                $html .= '</form>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '</div>';
                $html .= '<p class="text-muted small mb-4">Tip: click a column name to rename it or click the type badge to change its definition.</p>';

                $html .= '<div class="fastcrud-db-editor__columns">';
                $columns = $tableColumns[$table] ?? [];
                if ($columns === []) {
                    $html .= '<div class="alert alert-warning" role="alert">No columns detected.</div>';
                } else {
                    $html .= '<div class="table-responsive">';
                    $html .= '<table class="table table-striped table-hover table-sm align-middle mb-4">';
                    $html .= '<thead class="table-light">';
                    $html .= '<tr><th scope="col">Column</th><th scope="col">Type</th><th scope="col" class="text-center">Nullable</th><th scope="col">Default</th><th scope="col">Extra</th></tr>';
                    $html .= '</thead><tbody>';
                    foreach ($columns as $column) {
                        $columnName = $column['name'];
                        $columnType = $column['type'];
                        $columnEscaped = htmlspecialchars($columnName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $typeEscaped = htmlspecialchars($columnType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $defaultEscaped = htmlspecialchars((string) ($column['default'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $extraEscaped = htmlspecialchars($column['extra'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $typeOptionsHtml = self::buildTypeOptions($typeOptions, (string) $columnType);

                        $html .= '<tr>';
                        $html .= '<th scope="row" class="align-middle" data-fc-inline-container="name">';
                        $html .= '<span class="fw-semibold fc-db-inline-trigger link-primary" data-fc-inline-trigger role="button" tabindex="0" title="Click to rename column">' . $columnEscaped . '</span>';
                        $html .= '<form method="post" class="fc-db-inline-form d-inline-flex align-items-center gap-2 d-none" data-fc-inline-form data-fc-db-editor-form>';
                        $html .= '<input type="hidden" name="fc_db_editor_action" value="rename_column">';
                        $html .= '<input type="hidden" name="table_name" value="' . $tableEscaped . '">';
                        $html .= '<input type="hidden" name="column_name" value="' . $columnEscaped . '">';
                        $html .= '<input type="text" name="new_column_name" class="form-control form-control-sm" value="' . $columnEscaped . '" required pattern="[A-Za-z0-9_]+" placeholder="Column name">';
                        $html .= '</form>';
                        $html .= '</th>';
                        $html .= '<td class="align-middle" data-fc-inline-container="type">';
                        $html .= '<span class="badge rounded-pill bg-primary-subtle text-primary fc-db-inline-trigger font-monospace" data-fc-inline-trigger role="button" tabindex="0" title="Click to change column type">' . $typeEscaped . '</span>';
                        $html .= '<form method="post" class="fc-db-inline-form d-inline-flex align-items-center gap-2 d-none" data-fc-inline-form data-fc-db-editor-form>';
                        $html .= '<input type="hidden" name="fc_db_editor_action" value="change_column_type">';
                        $html .= '<input type="hidden" name="table_name" value="' . $tableEscaped . '">';
                        $html .= '<input type="hidden" name="column_name" value="' . $columnEscaped . '">';
                        $html .= '<select name="new_column_type" class="form-select form-select-sm fc-db-type-select" required>' . $typeOptionsHtml . '</select>';
                        $html .= '</form>';
                        $html .= '</td>';
                        $html .= '<td class="text-center align-middle">' . ($column['nullable'] ? '<span class="badge bg-success-subtle text-success">Yes</span>' : '<span class="badge bg-danger-subtle text-danger">No</span>') . '</td>';
                        $html .= '<td class="align-middle">' . $defaultEscaped . '</td>';
                        $html .= '<td class="align-middle">' . $extraEscaped . '</td>';
                        $html .= '</tr>';
                    }
                    $html .= '</tbody></table>';
                    $html .= '</div>';
                }

                $html .= '<div class="card border-0 bg-body-tertiary">';
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
                $html .= '</div>';
            }
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</section>';
        $html .= '</div>';

        if (!self::$scriptInjected) {
            $html .= self::renderInlineEditorScript();
            self::$scriptInjected = true;
        }

        self::$messages = [];
        self::$errors = [];

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
                'BIGINT', 'BINARY(255)', 'BIT', 'BOOLEAN', 'CHAR(36)', 'DATE', 'DATETIME', 'DECIMAL(10,2)', 'DOUBLE', 'FLOAT', 'INT', 'JSON', 'LONGTEXT', 'MEDIUMTEXT', 'SMALLINT', 'TEXT', 'TIME', 'TIMESTAMP', 'TINYINT', 'VARCHAR(255)'
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
.fastcrud-db-editor [data-fc-inline-trigger] {
    cursor: pointer;
}
.fastcrud-db-editor [data-fc-inline-trigger]:focus {
    outline: 0;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    border-radius: 0.375rem;
}
.fastcrud-db-editor.fc-db-editor-loading {
    position: relative;
    opacity: 0.6;
    pointer-events: none;
    transition: opacity 0.2s ease;
}
.fastcrud-db-editor .fc-db-inline-editing {
    background-color: rgba(13, 110, 253, 0.08);
    border-radius: 0.375rem;
    padding: 0.25rem 0.5rem;
    display: inline-block;
}
.fastcrud-db-editor .fc-db-inline-form input,
.fastcrud-db-editor .fc-db-inline-form select {
    min-width: 160px;
}
</style>
<script>
(function(){
    if (window.FastCrudDbEditorInlineInitialised) {
        return;
    }
    window.FastCrudDbEditorInlineInitialised = true;

    function getEditorRoot() {
        return document.querySelector('.fastcrud-db-editor');
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
        if (!trigger) {
            return;
        }
        event.preventDefault();
        var container = trigger.closest('[data-fc-inline-container]');
        if (container) {
            showForm(container);
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
}
