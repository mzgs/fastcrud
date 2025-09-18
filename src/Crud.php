<?php
declare(strict_types=1);

namespace FastCrud;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

class Crud
{
    private string $table;
    private PDO $connection;
    private string $id;
    private int $perPage = 5;

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
     * Set the number of items per page.
     * 
     * @param int $perPage Number of items per page
     * @return $this
     */
    public function setPerPage(int $perPage): self
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException('Items per page must be at least 1.');
        }
        
        $this->perPage = $perPage;
        return $this;
    }

    /**
     * Render all records from the configured table as an HTML table.
     */
    public function render(): string
    {
        $id = $this->escapeHtml($this->id);
        $table = $this->escapeHtml($this->table);
        $perPage = $this->perPage;
        
        // Get column names for headers
        $columns = $this->getColumnNames();
        
        if ($columns === []) {
            return '<div class="alert alert-warning">No columns available for this table.</div>';
        }
        
        $headerHtml = $this->buildHeader($columns);
        $script = $this->generateAjaxScript();

        return <<<HTML
<div id="{$id}-container">
    <div class="table-responsive">
        <table id="$id" class="table table-hover align-middle" data-table="$table" data-per-page="$perPage">
            <thead>
                <tr>
$headerHtml
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="{$this->escapeHtml((string)count($columns))}" class="text-center">
                        <div class="spinner-border spinner-border-sm" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        Loading data...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <nav aria-label="Table pagination">
        <ul id="{$id}-pagination" class="pagination justify-content-start">
        </ul>
    </nav>
</div>
$script
HTML;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Retrieve records from the target table along with column names.
     *
     * @param int|null $limit Limit number of rows
     * @param int|null $offset Offset for pagination
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function fetchData(?int $limit = null, ?int $offset = null): array
    {
        $sql = sprintf('SELECT * FROM %s', $this->table);
        
        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d', $limit);
            if ($offset !== null) {
                $sql .= sprintf(' OFFSET %d', $offset);
            }
        }

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

        return 'fastcrud-' . $suffix;
    }

    /**
     * Get table data as array for AJAX response with pagination.
     *
     * @param int $page Current page number (1-based)
     * @param int|null $perPage Items per page (null uses default)
     * @return array{rows: array<int, array<string, mixed>>, columns: array<int, string>, pagination: array{current_page: int, total_pages: int, total_rows: int, per_page: int}}
     */
    public function getTableData(int $page = 1, ?int $perPage = null): array
    {
        $perPage = $perPage ?? $this->perPage;
        $page = max(1, $page);
        
        // Get total count
        $countSql = sprintf('SELECT COUNT(*) as total FROM %s', $this->table);
        $totalRows = (int) $this->connection->query($countSql)->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Calculate pagination
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        
        // Fetch paginated data
        [$rows, $columns] = $this->fetchData($perPage, $offset);
        
        return [
            'rows' => $rows,
            'columns' => $columns,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_rows' => $totalRows,
                'per_page' => $perPage
            ]
        ];
    }
    
    /**
     * Get column names without fetching all data.
     *
     * @return array<int, string>
     */
    private function getColumnNames(): array
    {
        $sql = sprintf('SELECT * FROM %s LIMIT 0', $this->table);
        
        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException $exception) {
            return [];
        }
        
        $columns = [];
        $count = $statement->columnCount();
        
        for ($index = 0; $index < $count; $index++) {
            $meta = $statement->getColumnMeta($index) ?: [];
            $columns[] = is_string($meta['name'] ?? null) ? $meta['name'] : 'column_' . $index;
        }
        
        return $columns;
    }

    /**
     * Generate jQuery AJAX script for loading table data with pagination.
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
        var perPage = table.data('per-page');
        var paginationContainer = $('#' + tableId + '-pagination');
        var currentPage = 1;
        
        function loadTableData(page) {
            currentPage = page || 1;
            
            // Show loading state with fade effect
            var tbody = table.find('tbody');
            var colspan = table.find('thead th').length;
            tbody.fadeOut(100, function() {
                tbody.html('<tr><td colspan="' + colspan + '" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading...</td></tr>');
                tbody.fadeIn(100);
            });
            
            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                data: {
                    fastcrud_ajax: '1',
                    action: 'fetch',
                    table: tableName,
                    id: tableId,
                    page: currentPage,
                    per_page: perPage
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Populate table rows with fade effect
                        tbody.fadeOut(100, function() {
                            tbody.empty();
                            
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
                                    .attr('colspan', colspan)
                                    .addClass('text-center text-muted')
                                    .text('No records found.'));
                                tbody.append(emptyRow);
                            }
                            
                            tbody.fadeIn(100);
                        });
                        
                        // Build pagination
                        if (response.pagination && response.pagination.total_pages > 1) {
                            buildPagination(response.pagination);
                        } else {
                            paginationContainer.empty();
                        }
                    } else {
                        showError('Error: ' + (response.error || 'Failed to load data'));
                    }
                },
                error: function(xhr, status, error) {
                    showError('Failed to load table data: ' + error);
                }
            });
        }
        
        function buildPagination(pagination) {
            paginationContainer.empty();
            
            var currentPage = pagination.current_page;
            var totalPages = pagination.total_pages;
            
            // Records per page selector
            var perPageOptions = [5, 10, 25, 50, 100];
            var perPageHtml = '<select class="form-select form-select-sm rounded" style="width: auto;">';
            $.each(perPageOptions, function(i, val) {
                var selected = (val == perPage) ? 'selected' : '';
                perPageHtml += '<option value="' + val + '" ' + selected + '>' + val + '</option>';
            });
            perPageHtml += '</select>';
            
            var selectItem = $('<li class="page-item me-3"></li>');
            var selectWrapper = $('<span class="page-link border-0 bg-transparent p-1"></span>');
            selectWrapper.html(perPageHtml);
            selectWrapper.find('select').on('change', function() {
                perPage = parseInt($(this).val());
                loadTableData(1);
            });
            selectItem.append(selectWrapper);
            paginationContainer.append(selectItem);
            
            // Previous button
            var prevItem = $('<li class="page-item"></li>');
            if (currentPage === 1) {
                prevItem.addClass('disabled');
            }
            prevItem.append($('<a class="page-link rounded-start" href="javascript:void(0)" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a>')
                .on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (currentPage > 1) loadTableData(currentPage - 1);
                    return false;
                }));
            paginationContainer.append(prevItem);
            
            // Page numbers
            var startPage = Math.max(1, currentPage - 2);
            var endPage = Math.min(totalPages, currentPage + 2);
            
            if (startPage > 1) {
                paginationContainer.append(createPageItem(1));
                if (startPage > 2) {
                    paginationContainer.append($('<li class="page-item disabled"><span class="page-link">...</span></li>'));
                }
            }
            
            for (var i = startPage; i <= endPage; i++) {
                paginationContainer.append(createPageItem(i, i === currentPage));
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationContainer.append($('<li class="page-item disabled"><span class="page-link">...</span></li>'));
                }
                paginationContainer.append(createPageItem(totalPages));
            }
            
            // Next button
            var nextItem = $('<li class="page-item"></li>');
            if (currentPage === totalPages) {
                nextItem.addClass('disabled');
            }
            nextItem.append($('<a class="page-link rounded-end" href="javascript:void(0)" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>')
                .on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (currentPage < totalPages) loadTableData(currentPage + 1);
                    return false;
                }));
            paginationContainer.append(nextItem);
            
            // Add info text to the right
            var infoText = $('<li class="page-item disabled ms-auto"><span class="page-link border-0 bg-transparent text-muted">Showing ' + 
                ((currentPage - 1) * pagination.per_page + 1) + '-' + 
                Math.min(currentPage * pagination.per_page, pagination.total_rows) + 
                ' of ' + pagination.total_rows + '</span></li>');
            paginationContainer.append(infoText);
        }
        
        function createPageItem(pageNum, isActive) {
            var item = $('<li class="page-item"></li>');
            if (isActive) {
                item.addClass('active');
            }
            item.append($('<a class="page-link" href="javascript:void(0)">' + pageNum + '</a>')
                .on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    loadTableData(pageNum);
                    return false;
                }));
            return item;
        }
        
        function showError(message) {
            var tbody = table.find('tbody');
            var colspan = table.find('thead th').length;
            var errorRow = $('<tr></tr>');
            errorRow.append($('<td></td>')
                .attr('colspan', colspan)
                .addClass('text-danger text-center')
                .text(message));
            tbody.html(errorRow);
        }
        
        // Initial load
        loadTableData(1);
    });
})(jQuery);
</script>
SCRIPT;
    }
}
