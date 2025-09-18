<?php
declare(strict_types=1);

namespace FastCrud;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

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

        $this->table      = $table;
        $this->connection = $connection ?? DB::connection();
        $this->id         = $this->generateId();
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
        $id      = $this->escapeHtml($this->id);
        $table   = $this->escapeHtml($this->table);
        $perPage = $this->perPage;

        // Get column names for headers
        $columns = $this->getColumnNames();

        if ($columns === []) {
            return '<div class="alert alert-warning">No columns available for this table.</div>';
        }

        $headerHtml = $this->buildHeader($columns);
        $script     = $this->generateAjaxScript();
        $colspan    = $this->escapeHtml((string) (count($columns) + 1));
        $offcanvas  = $this->buildEditOffcanvas($id);

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
                    <td colspan="{$colspan}" class="text-center">
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
$offcanvas
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
            $code    = (int) $exception->getCode();

            throw new PDOException($message, $code, $exception);
        }

        $rows    = $statement->fetchAll(PDO::FETCH_ASSOC);
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
                $value   = $row[$column] ?? null;
                $cells[] = sprintf(
                    '            <td>%s</td>',
                    $this->escapeHtml($this->formatValue($value))
                );
            }

            $cells[] = '            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary fastcrud-edit-btn">Edit</button></td>';

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

        $cells[] = '            <th scope="col" class="text-end">Actions</th>';

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
        $count   = $statement->columnCount();

        for ($index = 0; $index < $count; $index++) {
            $meta      = $statement->getColumnMeta($index) ?: [];
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

    private function buildEditOffcanvas(string $id): string
    {
        $escapedId = $this->escapeHtml($id);
        $labelId   = $escapedId . '-edit-label';
        $formId    = $escapedId . '-edit-form';
        $panelId   = $escapedId . '-edit-panel';
        $errorId   = $escapedId . '-edit-error';
        $successId = $escapedId . '-edit-success';
        $fieldsId  = $escapedId . '-edit-fields';

        return <<<HTML
<div class="offcanvas offcanvas-start" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="{$labelId}">Edit Record</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <form id="{$formId}" novalidate class="d-flex flex-column h-100">
            <div class="alert alert-danger d-none" id="{$errorId}" role="alert"></div>
            <div class="alert alert-success d-none" id="{$successId}" role="alert">Changes saved successfully.</div>
            <div id="{$fieldsId}" class="flex-grow-1 overflow-auto"></div>
            <div class="d-flex justify-content-end gap-2 mt-auto pt-3 border-top bg-white sticky-bottom">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
HTML;
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
        $page    = max(1, $page);

        // Get total count
        $countSql  = sprintf('SELECT COUNT(*) as total FROM %s', $this->table);
        $totalRows = (int) $this->connection->query($countSql)->fetch(PDO::FETCH_ASSOC)['total'];

        // Calculate pagination
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        // Fetch paginated data
        [$rows, $columns] = $this->fetchData($perPage, $offset);

        return [
            'rows'       => $rows,
            'columns'    => $columns,
            'pagination' => [
                'current_page' => $page,
                'total_pages'  => $totalPages,
                'total_rows'   => $totalRows,
                'per_page'     => $perPage,
            ],
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
        $count   = $statement->columnCount();

        for ($index = 0; $index < $count; $index++) {
            $meta      = $statement->getColumnMeta($index) ?: [];
            $columns[] = is_string($meta['name'] ?? null) ? $meta['name'] : 'column_' . $index;
        }

        return $columns;
    }

    /**
     * Update a record and return the fresh row data.
     *
     * @param string $primaryKeyColumn Column name for the primary key
     * @param mixed $primaryKeyValue Value of the key used to locate the record
     * @param array<string, mixed> $fields Column => value map to update
     * @return array<string, mixed>|null
     */
    public function updateRecord(string $primaryKeyColumn, mixed $primaryKeyValue, array $fields): ?array
    {
        $primaryKeyColumn = trim($primaryKeyColumn);
        if ($primaryKeyColumn === '') {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        $columns = $this->getColumnNames();
        if (!in_array($primaryKeyColumn, $columns, true)) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        $filtered = [];
        foreach ($fields as $column => $value) {
            if (!is_string($column)) {
                continue;
            }

            if ($column === $primaryKeyColumn) {
                continue;
            }

            if (!in_array($column, $columns, true)) {
                continue;
            }

            $filtered[$column] = $value;
        }

        if ($filtered !== []) {
            $placeholders = [];
            $parameters   = [];

            foreach ($filtered as $column => $value) {
                $placeholder              = ':col_' . $column;
                $placeholders[]           = sprintf('%s = %s', $column, $placeholder);
                $parameters[$placeholder] = $value;
            }

            $parameters[':pk'] = $primaryKeyValue;

            $sql = sprintf(
                'UPDATE %s SET %s WHERE %s = :pk',
                $this->table,
                implode(', ', $placeholders),
                $primaryKeyColumn
            );

            $statement = $this->connection->prepare($sql);
            if ($statement === false) {
                throw new RuntimeException('Failed to prepare update statement.');
            }

            try {
                $statement->execute($parameters);
            } catch (PDOException $exception) {
                throw new RuntimeException('Failed to update record.', 0, $exception);
            }
        }

        return $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
    }

    /**
     * Locate a single row by its primary key.
     *
     * @return array<string, mixed>|null
     */
    private function findRowByPrimaryKey(string $primaryKeyColumn, mixed $primaryKeyValue): ?array
    {
        $sql       = sprintf('SELECT * FROM %s WHERE %s = :pk LIMIT 1', $this->table, $primaryKeyColumn);
        $statement = $this->connection->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Failed to prepare record lookup.');
        }

        try {
            $statement->execute([':pk' => $primaryKeyValue]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to fetch updated record.', 0, $exception);
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
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
        var perPage = parseInt(table.data('per-page'), 10);
        if (isNaN(perPage) || perPage < 1) {
            perPage = 5;
        }
        var paginationContainer = $('#' + tableId + '-pagination');
        var currentPage = 1;
        var columnsCache = [];
        var primaryKeyColumn = null;

        var editFormId = tableId + '-edit-form';
        var editForm = $('#' + editFormId);
        var editFieldsContainer = $('#' + tableId + '-edit-fields');
        var editError = $('#' + tableId + '-edit-error');
        var editSuccess = $('#' + tableId + '-edit-success');
        var editLabel = $('#' + tableId + '-edit-label');
        var editOffcanvasElement = $('#' + tableId + '-edit-panel');
        var offcanvasInstance = null;

        function getOffcanvasInstance() {
            if (offcanvasInstance) {
                return offcanvasInstance;
            }

            var element = editOffcanvasElement.get(0);
            if (!element) {
                return null;
            }

            offcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(element);
            return offcanvasInstance;
        }

        function findPrimaryKey(columns) {
            var pattern = /(^id$|_id$)/i;
            for (var index = 0; index < columns.length; index++) {
                if (pattern.test(columns[index])) {
                    return columns[index];
                }
            }

            return columns.length ? columns[0] : null;
        }

        function makeLabel(column) {
            var words = column.replace(/_/g, ' ').split(' ');

            for (var index = 0; index < words.length; index++) {
                if (words[index].length > 0) {
                    words[index] = words[index].charAt(0).toUpperCase() + words[index].slice(1);
                }
            }

            return words.join(' ');
        }

        function clearFormAlerts() {
            editError.addClass('d-none').text('');
            editSuccess.addClass('d-none');
        }

        function showFormError(message) {
            editSuccess.addClass('d-none');
            editError.text(message).removeClass('d-none');
        }

        function showEmptyRow(colspan, message) {
            var tbody = table.find('tbody');
            var row = $('<tr></tr>');
            row.append(
                $('<td></td>')
                    .attr('colspan', colspan)
                    .addClass('text-center text-muted')
                    .text(message || 'No records found.')
            );
            tbody.append(row);
        }

        function showError(message) {
            var tbody = table.find('tbody');
            var colspan = table.find('thead th').length || 1;
            tbody.empty();
            var row = $('<tr></tr>');
            row.append(
                $('<td></td>')
                    .attr('colspan', colspan)
                    .addClass('text-danger text-center')
                    .text(message)
            );
            tbody.append(row);
        }

        function buildPagination(pagination) {
            paginationContainer.empty();
            if (!pagination) {
                return;
            }

            var current = pagination.current_page;
            var totalPages = pagination.total_pages;
            var totalRows = pagination.total_rows;
            var perPageOptions = [5, 10, 25, 50, 100];

            var select = $('<select></select>')
                .addClass('form-select form-select-sm border-secondary')
                .attr('style', 'width: auto; height: 38px; padding: 0.375rem 2rem 0.375rem 0.75rem;');

            $.each(perPageOptions, function(_, value) {
                var option = $('<option></option>')
                    .attr('value', value)
                    .text(value);
                if (value === perPage) {
                    option.attr('selected', 'selected');
                }
                select.append(option);
            });

            select.on('change', function() {
                var parsed = parseInt($(this).val(), 10);
                if (!isNaN(parsed) && parsed > 0) {
                    perPage = parsed;
                    loadTableData(1);
                }
            });

            var selectItem = $('<li class="page-item me-3"></li>').append(select);
            paginationContainer.append(selectItem);

            var prevItem = $('<li class="page-item"></li>');
            if (current === 1) {
                prevItem.addClass('disabled');
            }
            prevItem.append(
                $('<a class="page-link rounded-start" href="javascript:void(0)" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a>')
                    .on('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        if (current > 1) {
                            loadTableData(current - 1);
                        }
                        return false;
                    })
            );
            paginationContainer.append(prevItem);

            var start = Math.max(1, current - 2);
            var end = Math.min(totalPages, current + 2);

            if (start > 1) {
                paginationContainer.append(createPageItem(1, false));
                if (start > 2) {
                    paginationContainer.append($('<li class="page-item disabled"><span class="page-link">...</span></li>'));
                }
            }

            for (var pageNumber = start; pageNumber <= end; pageNumber++) {
                paginationContainer.append(createPageItem(pageNumber, pageNumber === current));
            }

            if (end < totalPages) {
                if (end < totalPages - 1) {
                    paginationContainer.append($('<li class="page-item disabled"><span class="page-link">...</span></li>'));
                }
                paginationContainer.append(createPageItem(totalPages, false));
            }

            var nextItem = $('<li class="page-item"></li>');
            if (current === totalPages) {
                nextItem.addClass('disabled');
            }
            nextItem.append(
                $('<a class="page-link rounded-end" href="javascript:void(0)" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>')
                    .on('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        if (current < totalPages) {
                            loadTableData(current + 1);
                        }
                        return false;
                    })
            );
            paginationContainer.append(nextItem);

            var infoItem = $('<li class="page-item disabled ms-auto"></li>');
            var startRange = totalRows === 0 ? 0 : ((current - 1) * pagination.per_page) + 1;
            var endRange = totalRows === 0 ? 0 : Math.min(current * pagination.per_page, totalRows);
            infoItem.append(
                $('<span class="page-link border-0 bg-transparent text-muted"></span>')
                    .text('Showing ' + startRange + '-' + endRange + ' of ' + totalRows)
            );
            paginationContainer.append(infoItem);
        }

        function createPageItem(pageNumber, isActive) {
            var item = $('<li class="page-item"></li>');
            if (isActive) {
                item.addClass('active');
            }
            item.append(
                $('<a class="page-link" href="javascript:void(0)"></a>')
                    .text(pageNumber)
                    .on('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        loadTableData(pageNumber);
                        return false;
                    })
            );
            return item;
        }

        function populateTableRows(rows) {
            var tbody = table.find('tbody');
            tbody.empty();
            var totalColumns = table.find('thead th').length || 1;

            if (!rows || rows.length === 0) {
                showEmptyRow(totalColumns, 'No records found.');
                return;
            }

            $.each(rows, function(rowIndex, row) {
                var tableRow = $('<tr></tr>');
                $.each(columnsCache, function(colIndex, column) {
                    var value = row[column];
                    if (value === null || typeof value === 'undefined') {
                        value = '';
                    }
                    tableRow.append($('<td></td>').text(value));
                });

                var actionCell = $('<td class="text-end"></td>');
                var editButton = $('<button type="button" class="btn btn-sm btn-outline-primary fastcrud-edit-btn">Edit</button>');
                editButton.data('row', $.extend({}, row));
                actionCell.append(editButton);
                tableRow.append(actionCell);
                tbody.append(tableRow);
            });
        }

        function loadTableData(page) {
            currentPage = page || 1;

            var tbody = table.find('tbody');
            var totalColumns = table.find('thead th').length || 1;

            tbody.fadeOut(100, function() {
                tbody.html('<tr><td colspan="' + totalColumns + '" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading...</td></tr>');
                tbody.fadeIn(100);
            });

            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                dataType: 'json',
                data: {
                    fastcrud_ajax: '1',
                    action: 'fetch',
                    table: tableName,
                    id: tableId,
                    page: currentPage,
                    per_page: perPage
                },
                success: function(response) {
                    if (response && response.success) {
                        columnsCache = response.columns || [];
                        primaryKeyColumn = findPrimaryKey(columnsCache);

                        tbody.fadeOut(100, function() {
                            populateTableRows(response.data || []);
                            tbody.fadeIn(100);
                        });

                        if (response.pagination && response.pagination.total_pages > 1) {
                            buildPagination(response.pagination);
                        } else {
                            paginationContainer.empty();
                        }
                    } else {
                        var errorMessage = response && response.error ? response.error : 'Failed to load data';
                        showError('Error: ' + errorMessage);
                    }
                },
                error: function(_, __, error) {
                    showError('Failed to load table data: ' + error);
                }
            });
        }

        function showEditForm(row) {
            clearFormAlerts();

            if (!row || !primaryKeyColumn) {
                showFormError('Unable to determine primary key for editing.');
                return;
            }

            var primaryKeyValue = row[primaryKeyColumn];
            editForm.data('primaryKeyColumn', primaryKeyColumn);
            editForm.data('primaryKeyValue', primaryKeyValue);

            if (primaryKeyValue === null || typeof primaryKeyValue === 'undefined') {
                showFormError('Missing primary key value for selected record.');
                return;
            }

            if (editLabel.length) {
                editLabel.text('Edit Record ' + primaryKeyValue);
            }

            editFieldsContainer.empty();

            $.each(columnsCache, function(index, column) {
                if (column === primaryKeyColumn) {
                    return;
                }

                var fieldId = editFormId + '-' + column;
                var group = $('<div class="mb-3"></div>');
                var label = $('<label class="form-label"></label>')
                    .attr('for', fieldId)
                    .text(makeLabel(column));

                var value = row[column];
                if (value === null || typeof value === 'undefined') {
                    value = '';
                }

                var input = $('<input type="text" class="form-control" />')
                    .attr('id', fieldId)
                    .attr('data-fastcrud-field', column)
                    .val(value);

                group.append(label).append(input);
                editFieldsContainer.append(group);
            });

            var offcanvas = getOffcanvasInstance();
            if (offcanvas) {
                offcanvas.show();
            }
        }

        function submitEditForm(event) {
            event.preventDefault();
            event.stopPropagation();

            var primaryColumn = editForm.data('primaryKeyColumn');
            var primaryValue = editForm.data('primaryKeyValue');

            if (!primaryColumn) {
                showFormError('Primary key column missing.');
                return false;
            }

            clearFormAlerts();

            var fields = {};
            editFieldsContainer.find('[data-fastcrud-field]').each(function() {
                var input = $(this);
                var column = input.data('fastcrudField');
                if (!column) {
                    return;
                }
                fields[column] = input.val();
            });

            var submitButton = editForm.find('button[type="submit"]');
            var originalText = submitButton.text();
            submitButton.prop('disabled', true).text('Saving...');

            var offcanvas = getOffcanvasInstance();
            if (offcanvas) {
                offcanvas.hide();
            }

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: {
                    fastcrud_ajax: '1',
                    action: 'update',
                    table: tableName,
                    id: tableId,
                    primary_key_column: primaryColumn,
                    primary_key_value: primaryValue,
                    fields: JSON.stringify(fields)
                },
                success: function(response) {
                    if (response && response.success) {
                        loadTableData(currentPage);
                    } else {
                        var message = response && response.error ? response.error : 'Failed to update record.';
                        showFormError(message);
                        if (offcanvas) {
                            offcanvas.show();
                        }
                    }
                },
                error: function(_, __, error) {
                    showFormError('Failed to update record: ' + error);
                    if (offcanvas) {
                        offcanvas.show();
                    }
                },
                complete: function() {
                    submitButton.prop('disabled', false).text(originalText);
                }
            });

            return false;
        }

        table.on('click', '.fastcrud-edit-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var row = $(this).data('row');
            showEditForm(row || {});
            return false;
        });

        editForm.off('submit.fastcrud').on('submit.fastcrud', submitEditForm);

        loadTableData(1);
    });
})(jQuery);
</script>
SCRIPT;
    }
}
