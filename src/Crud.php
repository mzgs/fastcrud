<?php
declare(strict_types=1);

namespace CodexCrud;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

class Crud
{
    private string $table;
    private PDO $connection;
    private string $id;

    public function __construct(string $table, ?PDO $connection = null)
    {
        $table = trim($table);
        if ($table === '') {
            throw new InvalidArgumentException('A table name is required.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Only alphanumeric table names with underscores are supported.');
        }

        $this->table = $table;
        $this->connection = $connection ?? DB::connection();
        $this->id = $this->generateId();
    }

    /**
     * Render all records from the configured table as an HTML table.
     */
    public function render(): string
    {
        [$rows, $columns] = $this->fetchData();

        if ($columns === []) {
            return '<div class="alert alert-warning">No columns available for this table.</div>';
        }

        $headerHtml = $this->buildHeader($columns);
        $bodyHtml = $this->buildBody($rows, $columns);
        $id = $this->escapeHtml($this->id);

        return <<<HTML
<div class="table-responsive">
    <table id="$id" class="table table-hover align-middle" >
        <thead>
            <tr>
$headerHtml
            </tr>
        </thead>
        <tbody>
$bodyHtml
        </tbody>
    </table>
</div>
HTML;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Retrieve every record from the target table along with column names.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function fetchData(): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->table);

        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException $exception) {
            $message = sprintf('Failed to query table "%s".', $this->table);
            $code = (int) $exception->getCode();

            throw new PDOException($message, $code, $exception);
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $columns = $this->extractColumnNames($statement, $rows);

        return [$rows, $columns];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     */
    private function buildBody(array $rows, array $columns): string
    {
        if ($rows === []) {
            $colspan = count($columns);
            $escaped = $this->escapeHtml('No records found.');

            return sprintf(
                "        <tr>\n            <td colspan=\"%d\" class=\"text-center text-muted\">%s</td>\n        </tr>",
                $colspan,
                $escaped
            );
        }

        $bodyRows = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $cells[] = sprintf(
                    '            <td>%s</td>',
                    $this->escapeHtml($this->formatValue($value))
                );
            }

            $bodyRows[] = "        <tr>\n" . implode("\n", $cells) . "\n        </tr>";
        }

        return implode("\n", $bodyRows);
    }

    /**
     * @param array<int, string> $columns
     */
    private function buildHeader(array $columns): string
    {
        $cells = [];

        foreach ($columns as $column) {
            $label = $this->makeTitle($column);

            $cells[] = sprintf(
                '            <th scope="col">%s</th>',
                $this->escapeHtml($label)
            );
        }

        return implode("\n", $cells);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, string>
     */
    private function extractColumnNames(PDOStatement $statement, array $rows): array
    {
        if ($rows !== []) {
            return array_keys($rows[0]);
        }

        $columns = [];
        $count = $statement->columnCount();

        for ($index = 0; $index < $count; $index++) {
            $meta = $statement->getColumnMeta($index) ?: [];
            $columns[] = is_string($meta['name'] ?? null) ? $meta['name'] : 'column_' . $index;
        }

        return $columns;
    }

    private function makeTitle(string $column): string
    {
        return ucwords(str_replace('_', ' ', $column));
    }

    private function escapeHtml(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function formatValue(mixed $value): string
    {
        return (string) $value;
    }

    private function generateId(): string
    {
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Exception) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        return 'codexcrud-' . $suffix;
    }
}
