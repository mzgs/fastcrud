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

    /**
     * Initialize Crud and handle AJAX requests automatically.
     * Call this method early in your application bootstrap.
     * 
     * @param array<string, mixed>|null $dbConfig Optional database configuration
     */
    public static function init(?array $dbConfig = null): void
    {
        if ($dbConfig !== null) {
            CrudConfig::setDbConfig($dbConfig);
        }
        
        CrudAjax::autoHandle();
    }

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
        $id = $this->escapeHtml($this->id);
        $table = $this->escapeHtml($this->table);
        
        $script = $this->generateAjaxScript();

        return <<<HTML
<div class="table-responsive">
    <table id="$id" class="table table-hover align-middle" data-table="$table">
        <thead>
            <tr>
                <th colspan="100%" class="text-center">
                    <div class="spinner-border spinner-border-sm" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    Loading table data...
                </th>
            </tr>
        </thead>
        <tbody>
        </tbody>
    </table>
</div>
$script
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

    /**
     * Get table data as array for AJAX response.
     *
     * @return array{rows: array<int, array<string, mixed>>, columns: array<int, string>}
     */
    public function getTableData(): array
    {
        [$rows, $columns] = $this->fetchData();
        
        return [
            'rows' => $rows,
            'columns' => $columns
        ];
    }

    /**
     * Generate jQuery AJAX script for loading table data.
     */
    private function generateAjaxScript(): string
    {
        $id = $this->escapeHtml($this->id);
        
        return <<<SCRIPT
<script>
(function($) {
    $(document).ready(function() {
        var tableId = '$id';
        var table = $('#' + tableId);
        var tableName = table.data('table');
        
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: {
                codexcrud_ajax: '1',
                action: 'fetch',
                table: tableName,
                id: tableId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var thead = table.find('thead');
                    var tbody = table.find('tbody');
                    
                    thead.empty();
                    tbody.empty();
                    
                    if (response.columns && response.columns.length > 0) {
                        var headerRow = $('<tr></tr>');
                        $.each(response.columns, function(index, column) {
                            var label = column.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
                                return l.toUpperCase();
                            });
                            headerRow.append($('<th scope="col"></th>').text(label));
                        });
                        thead.append(headerRow);
                        
                        if (response.data && response.data.length > 0) {
                            $.each(response.data, function(rowIndex, row) {
                                var bodyRow = $('<tr></tr>');
                                $.each(response.columns, function(colIndex, column) {
                                    var value = row[column] || '';
                                    bodyRow.append($('<td></td>').text(value));
                                });
                                tbody.append(bodyRow);
                            });
                        } else {
                            var emptyRow = $('<tr></tr>');
                            emptyRow.append($('<td></td>')
                                .attr('colspan', response.columns.length)
                                .addClass('text-center text-muted')
                                .text('No records found.'));
                            tbody.append(emptyRow);
                        }
                    } else {
                        thead.append($('<tr><th class="text-warning">No columns available for this table.</th></tr>'));
                    }
                } else {
                    var errorRow = $('<tr></tr>');
                    errorRow.append($('<td></td>')
                        .addClass('text-danger text-center')
                        .text('Error: ' + (response.error || 'Failed to load data')));
                    table.find('tbody').html(errorRow);
                }
            },
            error: function(xhr, status, error) {
                var errorRow = $('<tr></tr>');
                errorRow.append($('<td></td>')
                    .addClass('text-danger text-center')
                    .text('Failed to load table data: ' + error));
                table.find('tbody').html(errorRow);
            }
        });
    });
})(jQuery);
</script>
SCRIPT;
    }
}
