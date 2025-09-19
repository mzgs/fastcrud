<?php
declare(strict_types=1);

namespace FastCrud;

use InvalidArgumentException;
use JsonException;
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
     * @var array<string, array<int, string>>
     */
    private array $tableColumnCache = [];
    /**
     * @var array<string, mixed>
     */
    private array $config = [
        'where' => [],
        'order_by' => [],
        'no_quotes' => [],
        'limit_options' => [5, 10, 25, 50, 100],
        'limit_default' => null,
        'search_columns' => [],
        'search_default' => null,
        'joins' => [],
        'relations' => [],
        'custom_query' => null,
        'subselects' => [],
        'visible_columns' => null,
        'columns_reverse' => false,
    ];

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

    public static function fromAjax(string $table, ?string $id, array|string|null $configPayload, ?PDO $connection = null): self
    {
        $instance = new self($table, $connection);

        if ($id !== null && $id !== '') {
            $instance->id = $id;
        }

        $decoded = null;

        if (is_string($configPayload) && $configPayload !== '') {
            try {
                $decoded = json_decode($configPayload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }
        } elseif (is_array($configPayload)) {
            $decoded = $configPayload;
        }

        if (is_array($decoded)) {
            $instance->applyClientConfig($decoded);
        }

        return $instance;
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
        $this->config['limit_default'] = $perPage;
        return $this;
    }

    /**
     * @param string|array<int, string|int> $values
     * @return array<int, string>
     */
    private function normalizeList(string|array $values): array
    {
        if (is_string($values)) {
            $values = explode(',', $values);
        }

        $normalized = [];
        foreach ($values as $value) {
            if (is_int($value)) {
                $normalized[] = (string) $value;
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function limit(int $limit): self
    {
        return $this->setPerPage($limit);
    }

    /**
     * @param string|array<int, string|int> $limits
     */
    public function limit_list(string|array $limits): self
    {
        $list = $this->normalizeList($limits);

        if ($list === []) {
            throw new InvalidArgumentException('Limit list cannot be empty.');
        }

        $parsed = [];
        foreach ($list as $item) {
            if (strtolower($item) === 'all') {
                $parsed[] = 'all';
                continue;
            }

            if (!is_numeric($item)) {
                continue;
            }

            $value = (int) $item;
            if ($value > 0) {
                $parsed[] = $value;
            }
        }

        if ($parsed === []) {
            throw new InvalidArgumentException('Limit list must contain at least one positive integer or "all" option.');
        }

        $this->config['limit_options'] = $parsed;

        if ($this->config['limit_default'] === null && isset($parsed[0]) && is_int($parsed[0])) {
            $this->setPerPage($parsed[0]);
        }

        return $this;
    }

    public function columns(string|array $columns, bool $reverse = false): self
    {
        $list = $this->normalizeList($columns);

        if ($list === []) {
            throw new InvalidArgumentException('Columns list cannot be empty.');
        }

        $transformed = [];
        foreach ($list as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized !== '') {
                $transformed[] = $normalized;
            }
        }

        if ($transformed === []) {
            throw new InvalidArgumentException('Columns list cannot be empty.');
        }

        $this->config['visible_columns'] = $transformed;
        $this->config['columns_reverse'] = $reverse;

        return $this;
    }

    public function order_by(string $field, string $direction = 'asc'): self
    {
        $field = trim($field);
        if ($field === '') {
            throw new InvalidArgumentException('Order by field cannot be empty.');
        }

        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Order direction must be ASC or DESC.');
        }

        $this->config['order_by'][] = [
            'field'     => $field,
            'direction' => $direction,
        ];

        return $this;
    }

    public function search_columns(string|array $columns, string|false $default = false): self
    {
        $list = $this->normalizeList($columns);

        if ($list === []) {
            throw new InvalidArgumentException('Search columns cannot be empty.');
        }

        $this->config['search_columns'] = $list;
        $this->config['search_default'] = $default === false ? null : trim((string) $default);

        return $this;
    }

    public function no_quotes(string|array $fields): self
    {
        $list = $this->normalizeList($fields);

        $this->config['no_quotes'] = array_values(array_unique(array_merge($this->config['no_quotes'], $list)));

        return $this;
    }

    /**
     * @param string|array<int, string>|array<string, mixed> $fields
     */
    public function where(string|array $fields, mixed $whereValue = false, string $glue = 'AND'): self
    {
        $this->addWhereCondition($fields, $whereValue, $glue);

        return $this;
    }

    /**
     * @param string|array<int, string>|array<string, mixed> $fields
     */
    public function or_where(string|array $fields, mixed $whereValue = false): self
    {
        $this->addWhereCondition($fields, $whereValue, 'OR');

        return $this;
    }

    public function join(string $field, string $joinTable, string $joinField, string|false $alias = false, bool $notInsert = false): self
    {
        $aliasValue = $alias === false || $alias === '' ? null : $alias;
        if ($aliasValue === null) {
            $aliasValue = 'j' . count($this->config['joins']);
        }

        $this->config['joins'][] = [
            'field'      => $field,
            'table'      => $joinTable,
            'join_field' => $joinField,
            'alias'      => $aliasValue,
            'not_insert' => $notInsert,
        ];

        return $this;
    }

    /**
     * @param string|array<int, string> $relName
     * @param array<string, mixed> $relWhere
     */
    public function relation(
        string $field,
        string $relatedTable,
        string $relatedField,
        string|array $relName,
        array $relWhere = [],
        string|false $orderBy = false,
        bool $multi = false
    ): self {
        $this->config['relations'][] = [
            'field'         => $field,
            'table'         => $relatedTable,
            'related_field' => $relatedField,
            'related_name'  => $relName,
            'where'         => $relWhere,
            'order_by'      => $orderBy === false ? null : $orderBy,
            'multi'         => $multi,
        ];

        return $this;
    }

    public function query(string $query): self
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException('Custom query cannot be empty.');
        }

        $this->config['custom_query'] = $query;

        return $this;
    }

    public function subselect(string $columnName, string $sql): self
    {
        $columnName = trim($columnName);
        if ($columnName === '') {
            throw new InvalidArgumentException('Subselect column name cannot be empty.');
        }

        $this->config['subselects'][] = [
            'column' => $columnName,
            'sql'    => $sql,
        ];

        return $this;
    }

    /**
     * @param string|array<int, string>|array<string, mixed> $fields
     */
    private function addWhereCondition(string|array $fields, mixed $whereValue, string $glue): void
    {
        $glue = strtoupper($glue) === 'OR' ? 'OR' : 'AND';

        if (is_array($fields) && $this->isAssociativeArray($fields)) {
            foreach ($fields as $expression => $value) {
                if (!is_string($expression)) {
                    continue;
                }

                $this->config['where'][] = $this->buildWhereEntry($expression, $value, $glue);
            }

            return;
        }

        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (!is_string($field)) {
                    continue;
                }

                $this->config['where'][] = $this->buildWhereEntry($field, $whereValue, $glue);
            }

            return;
        }

        if ($whereValue === false) {
            $raw = trim($fields);
            if ($raw !== '') {
                $this->config['where'][] = [
                    'glue'     => $glue,
                    'raw'      => $raw,
                    'column'   => null,
                    'operator' => null,
                    'value'    => null,
                ];
            }

            return;
        }

        $this->config['where'][] = $this->buildWhereEntry($fields, $whereValue, $glue);
    }

    private function isAssociativeArray(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (!is_int($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWhereEntry(string $expression, mixed $value, string $glue): array
    {
        [$column, $operator] = $this->parseCondition($expression, $value);

        return [
            'glue'     => $glue,
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseCondition(string $expression, mixed $value): array
    {
        $trimmed = trim($expression);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Condition expression cannot be empty.');
        }

        $pattern = '/\s*(IS NOT NULL|IS NULL|NOT LIKE|LIKE|NOT IN|IN|>=|<=|<>|!=|=|>|<|!)$/i';
        if (!preg_match($pattern, $trimmed, $matches)) {
            return [$trimmed, '='];
        }

        $operator = strtoupper($matches[1]);
        $column   = trim(substr($trimmed, 0, -strlen($matches[0])));

        if ($column === '') {
            $column = $trimmed;
        }

        if ($operator === '!') {
            $operator = is_array($value) ? 'NOT IN' : '!=';
        }

        return [$column, $operator];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function buildWhereClause(array &$parameters, ?string $searchTerm = null, ?string $searchColumn = null): string
    {
        if ($this->config['where'] === [] && ($searchTerm === null || $searchTerm === '')) {
            return '';
        }

        $clauses = [];
        $counter = 0;

        foreach ($this->config['where'] as $condition) {
            if (isset($condition['raw']) && is_string($condition['raw'])) {
                $clauses[] = [
                    'glue'   => $condition['glue'],
                    'clause' => $condition['raw'],
                ];
                continue;
            }

            $column   = $condition['column'];
            $operator = strtoupper((string) $condition['operator']);
            $value    = $condition['value'];

            if ($column === null || $operator === '') {
                continue;
            }

            $clause = '';
            if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
                $clause = sprintf('%s %s', $column, $operator);
            } elseif (in_array($operator, ['IN', 'NOT IN'], true) && is_array($value)) {
                if ($value === []) {
                    continue;
                }

                $placeholders = [];
                $index = 0;
                foreach ($value as $item) {
                    $placeholder = sprintf(':w_%d_%d', $counter, $index++);
                    if (in_array($column, $this->config['no_quotes'], true)) {
                        $placeholders[] = (string) $item;
                    } else {
                        $parameters[$placeholder] = $item;
                        $placeholders[] = $placeholder;
                    }
                }

                $list = implode(', ', $placeholders);
                $clause = sprintf('%s %s (%s)', $column, $operator, $list);
            } else {
                if (in_array($column, $this->config['no_quotes'], true)) {
                    $clause = sprintf('%s %s %s', $column, $operator, (string) $value);
                } else {
                    $placeholder = sprintf(':w_%d', $counter);
                    $parameters[$placeholder] = $value;
                    $clause = sprintf('%s %s %s', $column, $operator, $placeholder);
                }
            }

            if ($clause !== '') {
                $clauses[] = [
                    'glue'   => $condition['glue'],
                    'clause' => $clause,
                ];
            }

            $counter++;
        }

        if ($searchTerm !== null && $searchTerm !== '' && $this->config['search_columns'] !== []) {
            $columns = $this->config['search_columns'];
            $targetColumn = null;

            if ($searchColumn !== null && $searchColumn !== '' && in_array($searchColumn, $columns, true)) {
                $targetColumn = $searchColumn;
            }

            $placeholder = ':search_term';
            $parameters[$placeholder] = '%' . $searchTerm . '%';

            if ($targetColumn !== null) {
                $searchClause = sprintf('%s LIKE %s', $targetColumn, $placeholder);
            } else {
                $parts = array_map(
                    static fn(string $column): string => sprintf('%s LIKE %s', $column, $placeholder),
                    $columns
                );
                $searchClause = '(' . implode(' OR ', $parts) . ')';
            }

            $clauses[] = [
                'glue'   => 'AND',
                'clause' => $searchClause,
            ];
        }

        if ($clauses === []) {
            return '';
        }

        $sql = '';
        foreach ($clauses as $index => $entry) {
            $prefix = $index === 0 ? '' : ' ' . $entry['glue'] . ' ';
            $sql   .= $prefix . $entry['clause'];
        }

        return $sql;
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function buildSelectQuery(?int $limit = null, ?int $offset = null, ?string $searchTerm = null, ?string $searchColumn = null): array
    {
        $selectParts = ['main.*'];

        foreach ($this->config['subselects'] as $subselect) {
            $column = $subselect['column'];
            $sql    = $subselect['sql'];
            $selectParts[] = sprintf('(%s) AS %s', $sql, $column);
        }

        foreach ($this->config['joins'] as $index => $join) {
            $alias = $join['alias'] ?? ('j' . $index);
            $columns = $this->getTableColumnsFor($join['table']);
            foreach ($columns as $column) {
                $selectParts[] = sprintf('%s.%s AS %s__%s', $alias, $column, $alias, $column);
            }
        }

        $sql = sprintf('SELECT %s FROM %s', implode(', ', $selectParts), $this->buildFromClause());

        $joins = $this->buildJoinClauses();
        if ($joins !== '') {
            $sql .= ' ' . $joins;
        }

        $parameters = [];
        $whereClause = $this->buildWhereClause($parameters, $searchTerm, $searchColumn);
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        if ($this->config['order_by'] !== []) {
            $orderParts = array_map(
                static function (array $order): string {
                    $field = $order['field'];
                    if (
                        strpos($field, '.') === false &&
                        strpos($field, '(') === false &&
                        strpos($field, ' ') === false
                    ) {
                        $field = 'main.' . $field;
                    }

                    return $field . ' ' . $order['direction'];
                },
                $this->config['order_by']
            );
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d', $limit);
            if ($offset !== null) {
                $sql .= sprintf(' OFFSET %d', $offset);
            }
        }

        return [
            'sql'    => $sql,
            'params' => $parameters,
        ];
    }

    private function buildFromClause(): string
    {
        if ($this->config['custom_query'] !== null) {
            return '(' . $this->config['custom_query'] . ') AS main';
        }

        return sprintf('%s AS main', $this->table);
    }

    private function buildJoinClauses(): string
    {
        if ($this->config['joins'] === []) {
            return '';
        }

        $parts = [];
        foreach ($this->config['joins'] as $index => $join) {
            $alias = $join['alias'] ?? ('j' . $index);
            $left  = strpos($join['field'], '.') !== false ? $join['field'] : 'main.' . $join['field'];
            $parts[] = sprintf(
                'LEFT JOIN %s AS %s ON %s = %s.%s',
                $join['table'],
                $alias,
                $left,
                $alias,
                $join['join_field']
            );
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function buildCountQuery(?string $searchTerm = null, ?string $searchColumn = null): array
    {
        $sql = sprintf('SELECT COUNT(*) FROM %s', $this->buildFromClause());

        $joins = $this->buildJoinClauses();
        if ($joins !== '') {
            $sql .= ' ' . $joins;
        }

        $parameters = [];
        $whereClause = $this->buildWhereClause($parameters, $searchTerm, $searchColumn);
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        return [
            'sql'    => $sql,
            'params' => $parameters,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyRelations(array $rows): array
    {
        if ($rows === [] || $this->config['relations'] === []) {
            return $rows;
        }

        foreach ($this->config['relations'] as $index => $relation) {
            $field        = $relation['field'];
            $relatedTable = $relation['table'];
            $relatedField = $relation['related_field'];
            $nameFields   = (array) $relation['related_name'];

            if ($field === '' || $relatedTable === '' || $relatedField === '') {
                continue;
            }

            $values = [];
            foreach ($rows as $row) {
                if (!array_key_exists($field, $row)) {
                    continue;
                }

                $currentValue = $row[$field];

                if ($currentValue === null || $currentValue === '') {
                    continue;
                }

                if (!empty($relation['multi']) && is_string($currentValue)) {
                    foreach ($this->splitValues($currentValue) as $value) {
                        $values[] = $value;
                    }
                } else {
                    $values[] = $currentValue;
                }
            }

            $values = array_values(array_unique(array_map('strval', $values)));
            if ($values === []) {
                continue;
            }

            $placeholders = [];
            $parameters   = [];
            foreach ($values as $valueIndex => $value) {
                $placeholder = sprintf(':rel_%d_%d', $index, $valueIndex);
                $placeholders[] = $placeholder;
                $parameters[$placeholder] = $value;
            }

            $selectColumns = [$relatedField . ' AS relation_key'];
            foreach ($nameFields as $nameIndex => $nameField) {
                $alias = sprintf('relation_value_%d', $nameIndex);
                $selectColumns[] = sprintf('%s AS %s', $nameField, $alias);
            }

            $query = sprintf(
                'SELECT %s FROM %s WHERE %s IN (%s)',
                implode(', ', $selectColumns),
                $relatedTable,
                $relatedField,
                implode(', ', $placeholders)
            );

            if (!empty($relation['where']) && is_array($relation['where'])) {
                $conditions = [];
                foreach ($relation['where'] as $whereField => $whereValue) {
                    $placeholder = sprintf(':rel_%d_w_%s', $index, count($parameters));
                    $parameters[$placeholder] = $whereValue;
                    $conditions[] = sprintf('%s = %s', $whereField, $placeholder);
                }

                if ($conditions !== []) {
                    $query .= ' AND ' . implode(' AND ', $conditions);
                }
            }

            if (!empty($relation['order_by']) && is_string($relation['order_by'])) {
                $query .= ' ORDER BY ' . $relation['order_by'];
            }

            $statement = $this->connection->prepare($query);
            if ($statement === false) {
                continue;
            }

            try {
                $statement->execute($parameters);
            } catch (PDOException) {
                continue;
            }

            $map = [];
            while ($relatedRow = $statement->fetch(PDO::FETCH_ASSOC)) {
                $key = $relatedRow['relation_key'] ?? null;
                if ($key === null) {
                    continue;
                }

                $parts = [];
                foreach ($nameFields as $nameIndex => $nameField) {
                    $alias = sprintf('relation_value_%d', $nameIndex);
                    $parts[] = $relatedRow[$alias] ?? '';
                }

                $map[(string) $key] = trim(implode(' ', array_filter($parts, static fn($part) => $part !== null)));
            }

            foreach ($rows as $rowIndex => $row) {
                if (!array_key_exists($field, $row)) {
                    continue;
                }

                $currentValue = $row[$field];

                if (!empty($relation['multi']) && is_string($currentValue)) {
                    $labels = [];
                    foreach ($this->splitValues($currentValue) as $value) {
                        $labels[] = $map[$value] ?? $value;
                    }
                    $rows[$rowIndex][$field] = implode(', ', $labels);
                } else {
                    $key = (string) $currentValue;
                    $rows[$rowIndex][$field] = $map[$key] ?? $currentValue;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function applyColumnVisibility(array $rows, array $columns): array
    {
        $visible = $this->calculateVisibleColumns($columns);

        if ($visible === $columns) {
            return [$rows, $columns];
        }

        $filteredRows = [];
        foreach ($rows as $row) {
            $filteredRow = [];
            foreach ($visible as $column) {
                $filteredRow[$column] = $row[$column] ?? null;
            }
            $filteredRows[] = $filteredRow;
        }

        return [$filteredRows, $visible];
    }

    /**
     * @param array<int, string> $available
     * @return array<int, string>
     */
    private function calculateVisibleColumns(array $available): array
    {
        $configured = $this->config['visible_columns'];
        if ($configured === null) {
            return $available;
        }

        $availableLookup = array_flip($available);

        if ($this->config['columns_reverse']) {
            $result = [];
            foreach ($available as $column) {
                if (!in_array($column, $configured, true)) {
                    $result[] = $column;
                }
            }

            return $result !== [] ? $result : $available;
        }

        $result = [];
        $added = [];

        foreach ($configured as $column) {
            if ($column === '*') {
                foreach ($available as $candidate) {
                    if (!isset($added[$candidate])) {
                        $result[] = $candidate;
                        $added[$candidate] = true;
                    }
                }
                continue;
            }

            if (isset($availableLookup[$column]) && !isset($added[$column])) {
                $result[] = $column;
                $added[$column] = true;
            }
        }

        return $result !== [] ? $result : $available;
    }

    private function splitValues(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function getTableColumnsFor(string $table): array
    {
        if (isset($this->tableColumnCache[$table])) {
            return $this->tableColumnCache[$table];
        }

        $sql = sprintf('SELECT * FROM %s LIMIT 0', $table);

        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException) {
            $this->tableColumnCache[$table] = [];
            return $this->tableColumnCache[$table];
        }

        $columns = [];
        $count = $statement->columnCount();

        for ($index = 0; $index < $count; $index++) {
            $meta = $statement->getColumnMeta($index) ?: [];
            $name = $meta['name'] ?? null;
            if (is_string($name)) {
                $columns[] = $name;
            }
        }

        $this->tableColumnCache[$table] = $columns;

        return $columns;
    }

    private function normalizeColumnReference(string $column): string
    {
        $column = trim($column);
        if ($column === '') {
            return '';
        }

        if (strpos($column, '.') !== false && strpos($column, '__') === false) {
            [$prefix, $name] = array_map('trim', explode('.', $column, 2));
            if ($prefix !== '' && $name !== '') {
                return $prefix . '__' . $name;
            }
        }

        return $column;
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
        $styles     = $this->buildActionColumnStyles($this->id);
        $colspan    = $this->escapeHtml((string) (count($columns) + 1));
        $offcanvas  = $this->buildEditOffcanvas($id) . $this->buildViewOffcanvas($id);

        $configJson = '{}';
        try {
            $configJson = json_encode($this->buildClientConfigPayload(), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $configJson = '{}';
        }
        $configAttr = $this->escapeHtml($configJson);

        return <<<HTML
<div id="{$id}-container" data-fastcrud-config="{$configAttr}">
    <div id="{$id}-toolbar" class="d-flex flex-wrap align-items-center gap-2 mb-3"></div>
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
$styles
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
    private function fetchData(
        ?int $limit = null,
        ?int $offset = null,
        ?string $searchTerm = null,
        ?string $searchColumn = null
    ): array {
        $query = $this->buildSelectQuery($limit, $offset, $searchTerm, $searchColumn);

        $statement = $this->connection->prepare($query['sql']);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare select query.');
        }

        try {
            $statement->execute($query['params']);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to execute select query.', 0, $exception);
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $rows = $this->applyRelations($rows);

        $columns = $this->extractColumnNames($statement, $rows);
        [$rows, $columns] = $this->applyColumnVisibility($rows, $columns);

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

            $cells[] = '            <td class="text-end fastcrud-actions-cell"><div class="btn-group btn-group-sm" role="group">'
                . '<button type="button" class="btn btn-sm btn-outline-secondary fastcrud-view-btn">View</button>'
                . '<button type="button" class="btn btn-sm btn-outline-primary fastcrud-edit-btn">Edit</button>'
                . '<button type="button" class="btn btn-sm btn-outline-danger fastcrud-delete-btn">Delete</button>'
                . '</div></td>';

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

        $cells[] = '            <th scope="col" class="text-end fastcrud-actions fastcrud-actions-header">Actions</th>';

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

    private function buildViewOffcanvas(string $id): string
    {
        $escapedId = $this->escapeHtml($id);
        $labelId   = $escapedId . '-view-label';
        $panelId   = $escapedId . '-view-panel';
        $contentId = $escapedId . '-view-content';
        $emptyId   = $escapedId . '-view-empty';

        return <<<HTML
<div class="offcanvas offcanvas-start" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="{$labelId}">View Record</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <div class="alert alert-info d-none" id="{$emptyId}" role="alert">No record selected.</div>
        <div id="{$contentId}" class="list-group list-group-flush flex-grow-1 overflow-auto"></div>
    </div>
</div>
HTML;
    }

    private function buildActionColumnStyles(string $id): string
    {
        $containerId = $this->escapeHtml($id . '-container');

        return <<<HTML
<style>
#{$containerId} table {
    position: relative;
}

#{$containerId} table thead th.fastcrud-actions,
#{$containerId} table tbody td.fastcrud-actions-cell {
    position: sticky;
    right: 0;
    background-color: var(--bs-body-bg, #ffffff);
    min-width: 14rem;
}

#{$containerId} table thead th.fastcrud-actions {
    z-index: 3;
}

#{$containerId} table tbody td.fastcrud-actions-cell {
    z-index: 2;
    box-shadow: -6px 0 6px -6px rgba(0, 0, 0, 0.2);
}
</style>
HTML;
    }

    /**
     * Get table data as array for AJAX response with pagination.
     *
     * @param int $page Current page number (1-based)
     * @param int|null $perPage Items per page (null uses default)
     * @return array{rows: array<int, array<string, mixed>>, columns: array<int, string>, pagination: array{current_page: int, total_pages: int, total_rows: int, per_page: int}}
     */
    public function getTableData(
        int $page = 1,
        ?int $perPage = null,
        ?string $searchTerm = null,
        ?string $searchColumn = null
    ): array {
        $defaultPerPage = $this->config['limit_default'] ?? $this->perPage;
        $perPage        = $perPage ?? $defaultPerPage;
        $page           = max(1, $page);

        $countQuery = $this->buildCountQuery($searchTerm, $searchColumn);
        $countStatement = $this->connection->prepare($countQuery['sql']);
        if ($countStatement === false) {
            throw new RuntimeException('Failed to prepare count query.');
        }

        try {
            $countStatement->execute($countQuery['params']);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to execute count query.', 0, $exception);
        }

        $totalRows = (int) $countStatement->fetchColumn();

        $limitValue = ($perPage !== null && $perPage > 0) ? $perPage : null;

        if ($limitValue !== null) {
            $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $limitValue) : 1;
            $totalPages = max(1, $totalPages);
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $limitValue;
        } else {
            $totalPages = 1;
            $page       = 1;
            $offset     = null;
        }

        [$rows, $columns] = $this->fetchData($limitValue, $offset, $searchTerm, $searchColumn);

        $effectivePerPage = $limitValue ?? ($totalRows > 0 ? $totalRows : max(count($rows), 1));

        return [
            'rows'       => $rows,
            'columns'    => $columns,
            'pagination' => [
                'current_page' => $page,
                'total_pages'  => $totalPages,
                'total_rows'   => $totalRows,
                'per_page'     => $effectivePerPage,
            ],
            'meta'       => $this->buildMeta($columns),
        ];
    }

    private function buildMeta(array $columns): array
    {
        return [
            'table'          => $this->table,
            'columns'        => $columns,
            'limit_options'  => $this->config['limit_options'],
            'default_limit'  => $this->config['limit_default'] ?? $this->perPage,
            'search'         => [
                'columns' => $this->config['search_columns'],
                'default' => $this->config['search_default'],
            ],
            'order_by'       => array_map(
                static fn(array $order): array => [
                    'field'     => $order['field'],
                    'direction' => $order['direction'],
                ],
                $this->config['order_by']
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClientConfigPayload(): array
    {
        return [
            'per_page'       => $this->perPage,
            'where'          => $this->config['where'],
            'order_by'       => $this->config['order_by'],
            'no_quotes'      => $this->config['no_quotes'],
            'limit_options'  => $this->config['limit_options'],
            'limit_default'  => $this->config['limit_default'],
            'search_columns' => $this->config['search_columns'],
            'search_default' => $this->config['search_default'],
            'joins'          => $this->config['joins'],
            'relations'      => $this->config['relations'],
            'custom_query'   => $this->config['custom_query'],
            'subselects'     => $this->config['subselects'],
            'visible_columns' => $this->config['visible_columns'],
            'columns_reverse' => $this->config['columns_reverse'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyClientConfig(array $payload): void
    {
        if (isset($payload['per_page'])) {
            $perPageCandidate = (int) $payload['per_page'];
            if ($perPageCandidate > 0) {
                $this->perPage = $perPageCandidate;
                $this->config['limit_default'] = $perPageCandidate;
            } elseif ($perPageCandidate === 0) {
                $this->perPage = 0;
                $this->config['limit_default'] = 0;
            }
        }

        $arrayKeys = ['where', 'order_by', 'no_quotes', 'joins', 'relations', 'subselects'];
        foreach ($arrayKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $this->config[$key] = $payload[$key];
            }
        }

        if (isset($payload['limit_options']) && is_array($payload['limit_options'])) {
            $this->config['limit_options'] = array_values($payload['limit_options']);
        }

        if (isset($payload['limit_default']) && is_numeric($payload['limit_default'])) {
            $this->config['limit_default'] = (int) $payload['limit_default'];
        }

        if (isset($payload['search_columns'])) {
            $this->config['search_columns'] = $this->normalizeList($payload['search_columns']);
        }

        if (array_key_exists('search_default', $payload)) {
            $default = $payload['search_default'];
            $this->config['search_default'] = is_string($default) && $default !== '' ? $default : null;
        }

        if (isset($payload['custom_query']) && is_string($payload['custom_query']) && trim($payload['custom_query']) !== '') {
            $this->config['custom_query'] = $payload['custom_query'];
        }

        if (isset($payload['subselects']) && is_array($payload['subselects'])) {
            $this->config['subselects'] = $payload['subselects'];
        }

        if (isset($payload['visible_columns'])) {
            $columns = $this->normalizeList($payload['visible_columns']);
            $normalized = [];
            foreach ($columns as $column) {
                $value = $this->normalizeColumnReference($column);
                if ($value !== '') {
                    $normalized[] = $value;
                }
            }
            $this->config['visible_columns'] = $normalized;
        }

        if (isset($payload['columns_reverse'])) {
            $this->config['columns_reverse'] = (bool) $payload['columns_reverse'];
        }
    }

    /**
     * Get column names without fetching all data.
     *
     * @return array<int, string>
     */
    private function getColumnNames(): array
    {
        $query = $this->buildSelectQuery(1, 0);

        $statement = $this->connection->prepare($query['sql']);
        if ($statement === false) {
            return [];
        }

        try {
            $statement->execute($query['params']);
        } catch (PDOException) {
            return [];
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $columns = $this->extractColumnNames($statement, $rows);

        return $this->calculateVisibleColumns($columns);
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
     * Delete a record by its primary key value.
     */
    public function deleteRecord(string $primaryKeyColumn, mixed $primaryKeyValue): bool
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

        $sql       = sprintf('DELETE FROM %s WHERE %s = :pk', $this->table, $primaryKeyColumn);
        $statement = $this->connection->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Failed to prepare delete statement.');
        }

        try {
            $statement->execute([':pk' => $primaryKeyValue]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to delete record.', 0, $exception);
        }

        return $statement->rowCount() > 0;
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
        var container = $('#' + tableId + '-container');
        var rawConfig = container.attr('data-fastcrud-config');
        var clientConfig = {};
        if (rawConfig) {
            try {
                clientConfig = JSON.parse(rawConfig);
            } catch (error) {
                clientConfig = {};
            }
        }
        var paginationContainer = $('#' + tableId + '-pagination');
        var currentPage = 1;
        var columnsCache = [];
        var primaryKeyColumn = null;
        var metaConfig = {};
        var metaInitialized = false;
        var perPageOptions = [];
        var searchConfig = { columns: [], default: null };
        var currentSearchTerm = '';
        var currentSearchColumn = null;

        var toolbar = $('#' + tableId + '-toolbar');
        var searchGroup = null;
        var searchInput = null;
        var searchSelect = null;
        var searchButton = null;
        var clearButton = null;

        var editFormId = tableId + '-edit-form';
        var editForm = $('#' + editFormId);
        var editFieldsContainer = $('#' + tableId + '-edit-fields');
        var editError = $('#' + tableId + '-edit-error');
        var editSuccess = $('#' + tableId + '-edit-success');
        var editLabel = $('#' + tableId + '-edit-label');
        var editOffcanvasElement = $('#' + tableId + '-edit-panel');
        var editOffcanvasInstance = null;

        var viewOffcanvasElement = $('#' + tableId + '-view-panel');
        var viewContentContainer = $('#' + tableId + '-view-content');
        var viewEmptyNotice = $('#' + tableId + '-view-empty');
        var viewHeading = $('#' + tableId + '-view-label');
        var viewOffcanvasInstance = null;

        function getEditOffcanvasInstance() {
            if (editOffcanvasInstance) {
                return editOffcanvasInstance;
            }

            var element = editOffcanvasElement.get(0);
            if (!element) {
                return null;
            }

            editOffcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(element);
            return editOffcanvasInstance;
        }

        function getViewOffcanvasInstance() {
            if (viewOffcanvasInstance) {
                return viewOffcanvasInstance;
            }

            var element = viewOffcanvasElement.get(0);
            if (!element) {
                return null;
            }

            viewOffcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(element);
            return viewOffcanvasInstance;
        }

        function applyMeta(meta) {
            if (!meta || typeof meta !== 'object') {
                return;
            }

            metaConfig = meta;

            if (Array.isArray(meta.limit_options) && meta.limit_options.length) {
                perPageOptions = meta.limit_options;
                clientConfig.limit_options = meta.limit_options;
            }

            if (!metaInitialized) {
                var defaultLimit = meta.default_limit;
                if (typeof defaultLimit === 'number' && defaultLimit > 0) {
                    perPage = defaultLimit;
                }
                clientConfig.per_page = perPage;
            }

            if (meta.search && Array.isArray(meta.search.columns)) {
                searchConfig = {
                    columns: meta.search.columns,
                    default: meta.search.default || null,
                };
                clientConfig.search_columns = meta.search.columns;
                clientConfig.search_default = meta.search.default || null;

                if (!currentSearchColumn && typeof searchConfig.default === 'string' && searchConfig.default !== '') {
                    currentSearchColumn = searchConfig.default;
                }

                ensureSearchControls();
            } else {
                searchConfig = { columns: [], default: null };
                ensureSearchControls();
            }

            metaInitialized = true;
        }

        function ensureSearchControls() {
            if (!toolbar.length) {
                return;
            }

            if (!Array.isArray(searchConfig.columns) || searchConfig.columns.length === 0) {
                if (searchGroup) {
                    searchGroup.remove();
                    searchGroup = null;
                    searchInput = null;
                    searchSelect = null;
                    searchButton = null;
                    clearButton = null;
                }
                return;
            }

            if (searchGroup) {
                return;
            }

            searchGroup = $('<div class="input-group input-group-sm fastcrud-search-group" style="max-width: 24rem;"></div>');

            if (searchConfig.columns.length > 1) {
                searchSelect = $('<select class="form-select"></select>');
                $.each(searchConfig.columns, function(_, column) {
                    var option = $('<option></option>').attr('value', column).text(makeLabel(column));
                    if (column === currentSearchColumn) {
                        option.attr('selected', 'selected');
                    }
                    searchSelect.append(option);
                });
                searchSelect.on('change', function() {
                    currentSearchColumn = $(this).val() || null;
                });
                searchGroup.append(searchSelect);
            }

            searchInput = $('<input type="search" class="form-control" placeholder="Search..." aria-label="Search">');
            searchInput.on('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    triggerSearch();
                }
            });

            searchGroup.append(searchInput);

            searchButton = $('<button class="btn btn-outline-primary" type="button">Search</button>');
            searchButton.on('click', function() {
                triggerSearch();
            });

            clearButton = $('<button class="btn btn-outline-secondary" type="button">Clear</button>');
            clearButton.on('click', function() {
                currentSearchTerm = '';
                if (searchInput) {
                    searchInput.val('');
                }
                loadTableData(1);
            });

            searchGroup.append(searchButton).append(clearButton);

            toolbar.append(searchGroup);
        }

        function triggerSearch() {
            if (!searchInput) {
                return;
            }

            currentSearchTerm = searchInput.val() || '';
            loadTableData(1);
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

            var options = perPageOptions.length ? perPageOptions : [5, 10, 25, 50, 100];
            var select = null;

            if (options.length > 1) {
                select = $('<select></select>')
                    .addClass('form-select form-select-sm border-secondary')
                    .attr('style', 'width: auto; height: 38px; padding: 0.375rem 2rem 0.375rem 0.75rem;');

                $.each(options, function(_, value) {
                    var optionValue = value;
                    var optionLabel = value;

                    if (value === 'all') {
                        optionValue = 'all';
                        optionLabel = 'All';
                    }

                    var option = $('<option></option>')
                        .attr('value', optionValue)
                        .text(optionLabel);

                    if ((value === 'all' && perPage === 0) || (value !== 'all' && parseInt(value, 10) === perPage)) {
                        option.attr('selected', 'selected');
                    }

                    select.append(option);
                });

                select.on('change', function() {
                    var selected = $(this).val();
                if (selected === 'all') {
                    perPage = 0;
                    clientConfig.per_page = 0;
                    loadTableData(1);
                    return;
                }

                var parsed = parseInt(selected, 10);
                if (!isNaN(parsed) && parsed > 0) {
                    perPage = parsed;
                    clientConfig.per_page = parsed;
                    loadTableData(1);
                }
            });

                var selectItem = $('<li class="page-item me-3"></li>').append(select);
                paginationContainer.append(selectItem);
            }

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

                var actionCell = $('<td class="text-end fastcrud-actions-cell"></td>');
                var buttonGroup = $('<div class="btn-group btn-group-sm" role="group"></div>');

                var viewButton = $('<button type="button" class="btn btn-sm btn-outline-secondary fastcrud-view-btn">View</button>');
                viewButton.data('row', $.extend({}, row));
                buttonGroup.append(viewButton);

                var editButton = $('<button type="button" class="btn btn-sm btn-outline-primary fastcrud-edit-btn">Edit</button>');
                editButton.data('row', $.extend({}, row));
                buttonGroup.append(editButton);

                var deleteButton = $('<button type="button" class="btn btn-sm btn-outline-danger fastcrud-delete-btn">Delete</button>');
                deleteButton.data('row', $.extend({}, row));
                buttonGroup.append(deleteButton);

                actionCell.append(buttonGroup);
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
                    per_page: perPage > 0 ? perPage : 0,
                    search_term: currentSearchTerm,
                    search_column: currentSearchColumn,
                    config: JSON.stringify(clientConfig)
                },
                success: function(response) {
                    if (response && response.success) {
                        applyMeta(response.meta || {});

                        columnsCache = response.columns || [];
                        primaryKeyColumn = findPrimaryKey(columnsCache);

                        tbody.fadeOut(100, function() {
                            populateTableRows(response.data || []);
                            tbody.fadeIn(100);
                        });

                        if (response.pagination) {
                            buildPagination(response.pagination);
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

            if (viewOffcanvasInstance) {
                viewOffcanvasInstance.hide();
            }

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

            var offcanvas = getEditOffcanvasInstance();
            if (offcanvas) {
                offcanvas.show();
            }
        }

        function showViewPanel(row) {
            if (editOffcanvasInstance) {
                editOffcanvasInstance.hide();
            }

            var offcanvas = getViewOffcanvasInstance();
            if (!offcanvas) {
                return;
            }

            viewContentContainer.empty();
            viewEmptyNotice.addClass('d-none').text('No record selected.');

            if (!row || $.isEmptyObject(row)) {
                viewEmptyNotice.removeClass('d-none');
                offcanvas.show();
                return;
            }

            if (!columnsCache || columnsCache.length === 0) {
                viewEmptyNotice.text('Column metadata unavailable.').removeClass('d-none');
                offcanvas.show();
                return;
            }

            if (viewHeading.length) {
                var headingText = 'View Record';
                var primaryValue = primaryKeyColumn ? row[primaryKeyColumn] : null;
                if (typeof primaryValue !== 'undefined' && primaryValue !== null && String(primaryValue).length > 0) {
                    headingText += ' ' + primaryValue;
                }
                viewHeading.text(headingText);
            }

            var hasContent = false;
            $.each(columnsCache, function(_, column) {
                var label = makeLabel(column);
                var value = row[column];
                if (typeof value === 'undefined' || value === null) {
                    value = '';
                }

                if (typeof value === 'object') {
                    try {
                        value = JSON.stringify(value);
                    } catch (serializationError) {
                        value = String(value);
                    }
                }

                var displayValue = String(value);
                if (displayValue.length === 0) {
                    displayValue = 'N/A';
                }

                var item = $('<div class="list-group-item"></div>');
                item.append($('<div class="fw-semibold text-muted mb-1"></div>').text(label));
                item.append($('<div class="text-break"></div>').text(displayValue));
                viewContentContainer.append(item);
                hasContent = true;
            });

            if (!hasContent) {
                viewEmptyNotice.text('No fields available for this record.').removeClass('d-none');
            }

            offcanvas.show();
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
                var value = input.val();
                if (value === '') {
                    value = null;
                }
                fields[column] = value;
            });

            var submitButton = editForm.find('button[type="submit"]');
            var originalText = submitButton.text();
            submitButton.prop('disabled', true).text('Saving...');

            var offcanvas = getEditOffcanvasInstance();

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
                    fields: JSON.stringify(fields),
                    config: JSON.stringify(clientConfig)
                },
                success: function(response) {
                    if (response && response.success) {
                        editSuccess.removeClass('d-none');
                        if (response.message) {
                            editSuccess.text(response.message);
                        }
                        loadTableData(currentPage);
                        if (offcanvas) {
                            setTimeout(function() {
                                offcanvas.hide();
                            }, 1500);
                        }
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

        table.on('click', '.fastcrud-view-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var row = $(this).data('row');
            showViewPanel(row || {});
            return false;
        });

        table.on('click', '.fastcrud-edit-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var row = $(this).data('row');
            showEditForm(row || {});
            return false;
        });

        function requestDelete(row) {
            if (!primaryKeyColumn) {
                showError('Unable to determine primary key for deletion.');
                return;
            }

            if (!row || typeof row[primaryKeyColumn] === 'undefined') {
                showError('Missing primary key value for selected record.');
                return;
            }

            var primaryValue = row[primaryKeyColumn];
            var confirmationMessage = 'Are you sure you want to delete record ' + primaryValue + '?';
            if (!window.confirm(confirmationMessage)) {
                return;
            }

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: {
                    fastcrud_ajax: '1',
                    action: 'delete',
                    table: tableName,
                    id: tableId,
                    primary_key_column: primaryKeyColumn,
                    primary_key_value: primaryValue,
                    config: JSON.stringify(clientConfig)
                },
                success: function(response) {
                    if (response && response.success) {
                        loadTableData(currentPage);
                    } else {
                        var message = response && response.error ? response.error : 'Failed to delete record.';
                        showError(message);
                    }
                },
                error: function(_, __, error) {
                    showError('Failed to delete record: ' + error);
                }
            });
        }

        table.on('click', '.fastcrud-delete-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var row = $(this).data('row');
            requestDelete(row || {});
            return false;
        });

        editForm.off('submit.fastcrud').on('submit.fastcrud', submitEditForm);

        window.FastCrudTables = window.FastCrudTables || {};
        window.FastCrudTables[tableId] = {
            reload: function() {
                loadTableData(currentPage);
            },
            search: function(term, column) {
                currentSearchTerm = term || '';
                if (typeof column !== 'undefined' && column !== null) {
                    currentSearchColumn = column;
                    if (searchSelect) {
                        searchSelect.val(column);
                    }
                }

                if (searchInput && currentSearchTerm !== undefined) {
                    searchInput.val(currentSearchTerm);
                }

                loadTableData(1);
            },
            clearSearch: function() {
                currentSearchTerm = '';
                if (searchInput) {
                    searchInput.val('');
                }
                loadTableData(1);
            },
            setPerPage: function(value) {
                if (value === 'all') {
                    perPage = 0;
                } else {
                    var parsed = parseInt(value, 10);
                    if (!isNaN(parsed) && parsed > 0) {
                        perPage = parsed;
                    }
                }
                loadTableData(1);
            },
            getMeta: function() {
                return metaConfig;
            }
        };

        loadTableData(1);
    });
})(jQuery);
</script>
SCRIPT;
    }
}
