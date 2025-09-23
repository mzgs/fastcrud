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
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $tableSchemaCache = [];
    /**
     * @var array<string, array<string, string>>
     */
    private array $relationOptionsCache = [];
    private string $primaryKeyColumn = 'id';
    private const SUPPORTED_CONDITION_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'gt',
        'gte',
        'lt',
        'lte',
        'in',
        'not_in',
        'empty',
        'not_empty',
    ];

    private const SUPPORTED_SUMMARY_TYPES = ['sum', 'avg', 'min', 'max', 'count'];
    private const SUPPORTED_FORM_MODES = ['all', 'create', 'edit', 'view'];
    private const DEFAULT_FORM_MODE = 'all';

    /**
     * @var array<string, mixed>
     */
    private array $config = [
        'where' => [],
        'order_by' => [],
        'sort_disabled' => [],
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
        'column_labels' => [],
        'column_patterns' => [],
        'column_callbacks' => [],
        'column_classes' => [],
        'column_widths' => [],
        'column_cuts' => [],
        'column_highlights' => [],
        'row_highlights' => [],
        'table_meta' => [
            'name'    => null,
            'tooltip' => null,
            'icon'    => null,
            'duplicate' => false,
        ],
        'column_summaries' => [],
        'field_labels' => [],
        'panel_width' => null,
        'primary_key' => 'id',
        'form' => [
            'layouts' => [],
            'default_tabs' => [],
            'behaviours' => [
                'change_type' => [],
                'pass_var' => [],
                'pass_default' => [],
                'readonly' => [],
                'disabled' => [],
                'validation_required' => [],
                'validation_pattern' => [],
                'unique' => [],
            ],
            'all_columns' => [],
        ],
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

    public function primary_key(string $column): self
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidArgumentException('Primary key column cannot be empty.');
        }

        $this->primaryKeyColumn = $column;
        $this->config['primary_key'] = $column;

        return $this;
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

    public function setPanelWidth(string $width): self
    {
        $this->config['panel_width'] = $width;
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

    private function normalizeCssClassList(string $classes): string
    {
        $list = $this->normalizeList($classes);
        return implode(' ', $list);
    }

    private function normalizeCallable(callable|string|array $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }

        if ($callback instanceof \Closure) {
            throw new InvalidArgumentException('Closures cannot be serialized for AJAX callbacks. Use a named function or static method instead.');
        }

        if (is_array($callback) && count($callback) === 2) {
            [$target, $method] = $callback;
            if (is_object($target)) {
                $class = get_class($target);
                if (is_string($method) && $method !== '') {
                    return $class . '::' . $method;
                }
            }

            if (is_string($target) && is_string($method) && $target !== '' && $method !== '') {
                return $target . '::' . $method;
            }
        }

        throw new InvalidArgumentException('Unsupported callback type. Provide a string callable or [ClassName, method] pair.');
    }

    /**
     * @param string|array<int, string>|false|null $mode
     * @return array<int, string>
     */
    private function normalizeFormModes(string|array|false|null $mode): array
    {
        if ($mode === false || $mode === null) {
            return [self::DEFAULT_FORM_MODE];
        }

        $modes = is_array($mode) ? $mode : $this->normalizeList((string) $mode);
        if ($modes === []) {
            return [self::DEFAULT_FORM_MODE];
        }

        $normalized = [];
        foreach ($modes as $entry) {
            $candidate = strtolower(trim((string) $entry));
            if ($candidate === '') {
                continue;
            }

            if ($candidate === 'all') {
                return ['all'];
            }

            if (in_array($candidate, self::SUPPORTED_FORM_MODES, true) && $candidate !== 'all') {
                $normalized[$candidate] = true;
            }
        }

        if ($normalized === []) {
            return [self::DEFAULT_FORM_MODE];
        }

        return array_keys($normalized);
    }

    private function ensureFormLayoutBuckets(): void
    {
        if (!isset($this->config['form']['layouts']) || !is_array($this->config['form']['layouts'])) {
            $this->config['form']['layouts'] = [];
        }

        foreach (self::SUPPORTED_FORM_MODES as $mode) {
            if (!isset($this->config['form']['layouts'][$mode]) || !is_array($this->config['form']['layouts'][$mode])) {
                $this->config['form']['layouts'][$mode] = [];
            }
        }

        if (!isset($this->config['form']['layouts']['all']) || !is_array($this->config['form']['layouts']['all'])) {
            $this->config['form']['layouts']['all'] = [];
        }
    }

    private function ensureFormBehaviourBuckets(): void
    {
        if (!isset($this->config['form']['behaviours']) || !is_array($this->config['form']['behaviours'])) {
            $this->config['form']['behaviours'] = [
                'change_type' => [],
                'pass_var' => [],
                'pass_default' => [],
                'readonly' => [],
                'disabled' => [],
                'validation_required' => [],
                'validation_pattern' => [],
                'unique' => [],
            ];
            return;
        }

        $defaults = [
            'change_type' => [],
            'pass_var' => [],
            'pass_default' => [],
            'readonly' => [],
            'disabled' => [],
            'validation_required' => [],
            'validation_pattern' => [],
            'unique' => [],
        ];

        $this->config['form']['behaviours'] = array_replace($defaults, $this->config['form']['behaviours']);
    }

    private function ensureDefaultTabBuckets(): void
    {
        if (!isset($this->config['form']['default_tabs']) || !is_array($this->config['form']['default_tabs'])) {
            $this->config['form']['default_tabs'] = [];
        }

        if (!isset($this->config['form']['all_columns']) || !is_array($this->config['form']['all_columns'])) {
            $this->config['form']['all_columns'] = [];
        }
    }

    private function storeLayoutEntry(array $fields, bool $reverse, ?string $tab, array $modes): void
    {
        $this->ensureFormLayoutBuckets();

        $entry = [
            'fields'  => array_values(array_unique($fields)),
            'reverse' => $reverse,
            'tab'     => $tab,
        ];

        foreach ($modes as $mode) {
            $bucket = $mode === 'all' ? 'all' : $mode;
            $this->config['form']['layouts'][$bucket][] = $entry;
        }
    }

    private function storeBehaviourValue(string $key, string $field, mixed $value, array $modes): void
    {
        $this->ensureFormBehaviourBuckets();

        if (!isset($this->config['form']['behaviours'][$key]) || !is_array($this->config['form']['behaviours'][$key])) {
            $this->config['form']['behaviours'][$key] = [];
        }

        foreach ($modes as $mode) {
            $bucket = $mode === 'all' ? 'all' : $mode;
            if (!isset($this->config['form']['behaviours'][$key][$field]) || !is_array($this->config['form']['behaviours'][$key][$field])) {
                $this->config['form']['behaviours'][$key][$field] = [];
            }

            $this->config['form']['behaviours'][$key][$field][$bucket] = $value;
        }
    }

    private function storeBehaviourFlag(string $key, string $field, bool $flag, array $modes): void
    {
        $this->storeBehaviourValue($key, $field, $flag, $modes);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderTemplateValue(mixed $value, array $context): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}/',
            static function (array $matches) use ($context): string {
                $key = $matches[1];
                $replacement = $context[$key] ?? '';
                if (is_scalar($replacement)) {
                    return (string) $replacement;
                }

                if (is_object($replacement) && method_exists($replacement, '__toString')) {
                    return (string) $replacement;
                }

                return '';
            },
            $value
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherBehaviourForMode(string $key, string $mode): array
    {
        $this->ensureFormBehaviourBuckets();
        $behaviours = $this->config['form']['behaviours'][$key] ?? [];
        if (!is_array($behaviours)) {
            return [];
        }

        $resolved = [];
        foreach ($behaviours as $field => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $value = null;
            if (isset($definition['all'])) {
                $value = $definition['all'];
            }

            if (isset($definition[$mode])) {
                $value = $definition[$mode];
            }

            if ($value !== null) {
                $resolved[$field] = $value;
            }
        }

        return $resolved;
    }

    private function compileValidationPattern(string $pattern): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }

        $delimiter = substr($pattern, 0, 1);
        $knownDelimiters = ['/', '#', '~', '!'];
        if (!in_array($delimiter, $knownDelimiters, true)) {
            $escaped = str_replace('/', '\/', $pattern);
            $pattern = '/^' . $escaped . '$/';
        }

        set_error_handler(static function () {
            return true;
        });
        $isValid = @preg_match($pattern, '') !== false;
        restore_error_handler();

        return $isValid ? $pattern : null;
    }

    private function mergeFormConfig(array $form): void
    {
        $this->ensureFormLayoutBuckets();
        $this->ensureFormBehaviourBuckets();
        $this->ensureDefaultTabBuckets();

        unset($form['all_columns']);

        if (isset($form['layouts']) && is_array($form['layouts'])) {
            foreach ($form['layouts'] as $mode => $entries) {
                if (!is_string($mode) || !is_array($entries)) {
                    continue;
                }

                $bucket = strtolower($mode);
                if ($bucket === '') {
                    continue;
                }

                $normalizedEntries = [];
                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $fields = [];
                    if (isset($entry['fields']) && is_array($entry['fields'])) {
                        foreach ($entry['fields'] as $field) {
                            if (!is_string($field)) {
                                continue;
                            }
                            $normalizedField = $this->normalizeColumnReference($field);
                            if ($normalizedField !== '') {
                                $fields[] = $normalizedField;
                            }
                        }
                    }

                    if ($fields === []) {
                        continue;
                    }

                    $reverse = !empty($entry['reverse']);
                    $tab = null;
                    if (isset($entry['tab']) && is_string($entry['tab'])) {
                        $tabCandidate = trim($entry['tab']);
                        $tab = $tabCandidate === '' ? null : $tabCandidate;
                    }

                    $normalizedEntries[] = [
                        'fields'  => array_values(array_unique($fields)),
                        'reverse' => $reverse,
                        'tab'     => $tab,
                    ];
                }

                $this->config['form']['layouts'][$bucket] = $normalizedEntries;
            }
        }

        if (isset($form['default_tabs']) && is_array($form['default_tabs'])) {
            foreach ($form['default_tabs'] as $mode => $tab) {
                if (!is_string($mode) || !is_string($tab)) {
                    continue;
                }
                $tabName = trim($tab);
                if ($tabName === '') {
                    continue;
                }
                $this->config['form']['default_tabs'][strtolower($mode)] = $tabName;
            }
        }

        if (isset($form['labels']) && is_array($form['labels'])) {
            foreach ($form['labels'] as $field => $label) {
                if (!is_string($field) || !is_string($label)) {
                    continue;
                }

                $normalizedField = $this->normalizeColumnReference($field);
                if ($normalizedField === '') {
                    continue;
                }

                $trimmed = trim($label);
                if ($trimmed === '') {
                    unset($this->config['field_labels'][$normalizedField]);
                    continue;
                }

                $this->config['field_labels'][$normalizedField] = $trimmed;
            }
        }

        if (isset($form['behaviours']) && is_array($form['behaviours'])) {
            $behaviours = $form['behaviours'];

            if (isset($behaviours['change_type']) && is_array($behaviours['change_type'])) {
                $this->config['form']['behaviours']['change_type'] = [];
                foreach ($behaviours['change_type'] as $field => $definition) {
                    if (!is_string($field) || !is_array($definition)) {
                        continue;
                    }
                    $normalizedField = $this->normalizeColumnReference($field);
                    if ($normalizedField === '') {
                        continue;
                    }
                    $type = isset($definition['type']) ? strtolower(trim((string) $definition['type'])) : '';
                    if ($type === '') {
                        continue;
                    }

                    $this->config['form']['behaviours']['change_type'][$normalizedField] = [
                        'type'    => $type,
                        'default' => $definition['default'] ?? '',
                        'params'  => isset($definition['params']) && is_array($definition['params'])
                            ? $definition['params']
                            : [],
                    ];
                }
            }

            $modeAwareKeys = ['pass_var', 'pass_default', 'readonly', 'disabled', 'validation_required', 'validation_pattern', 'unique'];
            foreach ($modeAwareKeys as $key) {
                if (!isset($behaviours[$key]) || !is_array($behaviours[$key])) {
                    continue;
                }

                $this->config['form']['behaviours'][$key] = [];

                foreach ($behaviours[$key] as $field => $definition) {
                    if (!is_string($field) || !is_array($definition)) {
                        continue;
                    }

                    $normalizedField = $this->normalizeColumnReference($field);
                    if ($normalizedField === '') {
                        continue;
                    }

                    foreach ($definition as $mode => $value) {
                        $bucket = strtolower((string) $mode);
                        if ($bucket === '') {
                            continue;
                        }

                        $this->config['form']['behaviours'][$key][$normalizedField][$bucket] = $value;
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed>|string $condition
     *
     * @return array{column: string, operator: string, value: mixed}
     */
    private function normalizeCondition(array|string $condition, ?string $defaultColumn): array
    {
        if (is_string($condition)) {
            $condition = trim($condition);
            if ($condition === '') {
                throw new InvalidArgumentException('Highlight conditions cannot be empty.');
            }

            $normalized = strtolower($condition);
            if ($normalized === 'empty' || $normalized === '!value') {
                return [
                    'column'   => $defaultColumn ?? '',
                    'operator' => 'empty',
                    'value'    => null,
                ];
            }

            if ($normalized === 'not_empty' || $normalized === '!empty' || $normalized === 'has_value') {
                return [
                    'column'   => $defaultColumn ?? '',
                    'operator' => 'not_empty',
                    'value'    => null,
                ];
            }

            return [
                'column'   => $defaultColumn ?? '',
                'operator' => 'equals',
                'value'    => $condition,
            ];
        }

        $normalized = [];
        foreach ($condition as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalized[strtolower($key)] = $value;
        }

        $column = isset($normalized['column']) && is_string($normalized['column'])
            ? $this->normalizeColumnReference($normalized['column'])
            : ($defaultColumn ?? '');

        if ($column === '') {
            throw new InvalidArgumentException('Highlight conditions must reference a column.');
        }

        $operator = isset($normalized['operator']) ? strtolower((string) $normalized['operator']) : 'equals';
        if (!in_array($operator, self::SUPPORTED_CONDITION_OPERATORS, true)) {
            throw new InvalidArgumentException('Unsupported condition operator: ' . $operator);
        }

        $value = $normalized['value'] ?? null;

        if (in_array($operator, ['in', 'not_in'], true)) {
            if (is_string($value)) {
                $value = $this->normalizeList($value);
            }

            if (!is_array($value) || $value === []) {
                throw new InvalidArgumentException('IN/NOT IN conditions require a non-empty array of values.');
            }
        }

        if (in_array($operator, ['gt', 'gte', 'lt', 'lte'], true)) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException('Comparison operators require numeric values.');
            }
            $value = (float) $value;
        }

        if ($operator === 'contains' && !is_string($value)) {
            throw new InvalidArgumentException('Contains operator requires a string value.');
        }

        return [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];
    }

    private function evaluateCondition(array $condition, array $row): bool
    {
        $column = $condition['column'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        $current = $row[$column] ?? null;

        switch ($operator) {
            case 'empty':
                return $current === null || $current === '';
            case 'not_empty':
                return !($current === null || $current === '');
            case 'equals':
                return $current == $value; // intentional loose comparison for DB values
            case 'not_equals':
                return $current != $value;
            case 'contains':
                return is_string($current) && strpos($current, (string) $value) !== false;
            case 'gt':
                return (float) $current > (float) $value;
            case 'gte':
                return (float) $current >= (float) $value;
            case 'lt':
                return (float) $current < (float) $value;
            case 'lte':
                return (float) $current <= (float) $value;
            case 'in':
                return in_array((string) $current, array_map('strval', (array) $value), true);
            case 'not_in':
                return !in_array((string) $current, array_map('strval', (array) $value), true);
        }

        return false;
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return get_class($value);
            }
        }

        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return '[array]';
            }
        }

        return (string) $value;
    }

    private function truncateString(string $value, int $length, string $suffix = '…'): string
    {
        $length = max(1, $length);
        $stringLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($stringLength <= $length) {
            return $value;
        }

        $substr = function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);

        return $substr . $suffix;
    }

    private const PATTERN_TOKEN_REGEX = '/\{([A-Za-z0-9_]+)\}/';

    private function applyPattern(string $pattern, string $display, mixed $raw, string $column, array $row): string
    {
        return preg_replace_callback(
            self::PATTERN_TOKEN_REGEX,
            function (array $matches) use ($display, $raw, $column, $row): string {
                $token = strtolower($matches[1]);

                return match ($token) {
                    'value'  => $display,
                    'raw'    => $this->stringifyValue($raw),
                    'column' => $column,
                    'label'  => $this->resolveColumnLabel($column),
                    default  => $this->stringifyValue($row[$token] ?? ''),
                };
            },
            $pattern
        );
    }

    private function resolveColumnLabel(string $column): string
    {
        return $this->config['column_labels'][$column] ?? $this->makeTitle($column);
    }

    private function buildColumnSlug(string $column): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $column);
        $slug = trim((string) $slug, '-');

        return $slug === '' ? 'column' : strtolower($slug);
    }

    /**
     * @return array{class: string|null, style: string|null}
     */
    private function interpretWidth(string $width): array
    {
        $width = trim($width);
        if ($width === '') {
            return ['class' => null, 'style' => null];
        }

        $lower = strtolower($width);
        $styleUnits = ['px', 'rem', 'em', '%', 'vw', 'vh'];
        foreach ($styleUnits as $unit) {
            if (substr($lower, -strlen($unit)) === $unit) {
                return ['class' => null, 'style' => 'width: ' . $width . ';'];
            }
        }

        if (strpos($lower, 'calc(') !== false) {
            return ['class' => null, 'style' => 'width: ' . $width . ';'];
        }

        return ['class' => $this->normalizeCssClassList($width), 'style' => null];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRow(array $row, array $columns): array
    {
        $sourceRow = $row['__fastcrud_row'] ?? $row;

        $cells = [];
        $rawValues = [];

        if (isset($sourceRow['__fastcrud_raw']) && is_array($sourceRow['__fastcrud_raw'])) {
            $rawValues = $sourceRow['__fastcrud_raw'];
        }

        foreach ($columns as $column) {
            $value = $sourceRow[$column] ?? null;
            $rawOriginal = $rawValues[$column] ?? ($sourceRow[$column] ?? null);
            $cells[$column] = $this->presentCell($column, $value, $sourceRow, $rawOriginal);
        }

        $rowClasses = [];
        foreach ($this->config['row_highlights'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $condition = $entry['condition'] ?? null;
            $class = isset($entry['class']) ? (string) $entry['class'] : '';
            if (!is_array($condition) || $class === '') {
                continue;
            }

            if ($this->evaluateCondition($condition, $sourceRow)) {
                $rowClasses[] = $class;
            }
        }

        $meta = ['cells' => $cells];
        if ($rawValues !== []) {
            $meta['raw'] = $rawValues;
        }
        if ($rowClasses !== []) {
            $meta['row_class'] = implode(' ', $rowClasses);
        }

        return $meta;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     * @return array<int, array<string, mixed>>
     */
    private function decorateRows(array $rows, array $columns): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['__fastcrud'] = $this->presentRow($row, $columns);
            if (isset($rows[$index]['__fastcrud_primary_key'])) {
                $rows[$index]['__fastcrud']['primary_key'] = $rows[$index]['__fastcrud_primary_key'];
                $rows[$index]['__fastcrud']['primary_value'] = $rows[$index]['__fastcrud_primary_value'] ?? null;
            }
            if (isset($rows[$index]['__fastcrud_raw'])) {
                unset($rows[$index]['__fastcrud_raw']);
            }
            if (isset($rows[$index]['__fastcrud_row'])) {
                unset($rows[$index]['__fastcrud_row']);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentCell(string $column, mixed $value, array $row, mixed $rawOriginal): array
    {
        $display = $this->stringifyValue($value);
        $displayOriginal = $display;

        $html = null;

        $patternEntry = $this->config['column_patterns'][$column] ?? null;
        if ($patternEntry !== null) {
            $patternTemplate = trim((string) $patternEntry);
            if ($patternTemplate !== '') {
                $patternOutput = $this->applyPattern($patternTemplate, $display, $value, $column, $row);
                $html = $patternOutput;
                $display = $displayOriginal;
            }
        }

        if (isset($this->config['column_cuts'][$column])) {
            $cut = $this->config['column_cuts'][$column];
            if (is_array($cut) && isset($cut['length'])) {
                $suffix = isset($cut['suffix']) ? (string) $cut['suffix'] : '…';
                $display = $this->truncateString($display, (int) $cut['length'], $suffix);
            }
        }

        $tooltip = null;
        $attributes = [];
        $cellClasses = [];

        if (isset($this->config['column_callbacks'][$column])) {
            $callbackEntry = $this->config['column_callbacks'][$column];
            $callable = null;

            if (is_string($callbackEntry) && $callbackEntry !== '') {
                $callable = $callbackEntry;
            } elseif (is_array($callbackEntry) && isset($callbackEntry['callable'])) {
                $callable = (string) $callbackEntry['callable'];
            }

            if ($callable !== null && is_callable($callable)) {
                $result = call_user_func($callable, $value, $row, $column, $display);

                if ($result !== null) {
                    $stringResult = $this->stringifyValue($result);
                    $html = $stringResult;
                    $display = $stringResult;
                }
            }
        }

        if ($html === null && isset($this->config['form']['behaviours']['change_type'][$column])) {
            $change = $this->config['form']['behaviours']['change_type'][$column];
            $type = is_array($change) && isset($change['type']) ? strtolower((string) $change['type']) : '';
            if ($type === 'file') {
                $raw = $rawOriginal;
                if ($raw === null || $raw === '') {
                    $raw = $value;
                }
                $fileName = $this->stringifyValue($raw);
                $fileName = trim($fileName);
                if ($fileName !== '') {
                    $href = $this->buildPublicUploadUrl($fileName);
                    $linkText = $display;
                    $html = '<a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer">' . $this->escapeHtml($linkText) . '</a>';
                }
            } elseif ($type === 'files') {
                $raw = $rawOriginal;
                if ($raw === null || $raw === '') {
                    $raw = $value;
                }
                $names = $this->parseImageNameList($raw);
                if ($names !== []) {
                    $first = $names[0];
                    $href = $this->buildPublicUploadUrl($first);
                    $extra = count($names) > 1 ? ' (+' . (count($names) - 1) . ')' : '';
                    $text = $first . $extra;
                    $html = '<a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer">' . $this->escapeHtml($text) . '</a>';
                }
            } elseif (($type === 'image' || $type === 'images') && CrudConfig::$images_in_grid) {
                $height = (int) CrudConfig::$images_in_grid_height;
                if ($type === 'image') {
                    $raw = $rawOriginal;
                    if ($raw === null || $raw === '') {
                        $raw = $value;
                    }
                    $fileName = trim($this->stringifyValue($raw));
                    if ($fileName !== '') {
                        $src = $this->buildPublicUploadUrl($fileName);
                        $style = $height > 0 ? (' style="height: ' . $height . 'px; width: auto;"') : '';
                        $html = '<img src="' . $this->escapeHtml($src) . '" alt="" class="img-thumbnail"' . $style . ' />';
                    }
                } else {
                    $raw = $rawOriginal;
                    if ($raw === null || $raw === '') {
                        $raw = $value;
                    }
                    $names = $this->parseImageNameList($raw);
                    if ($names !== []) {
                        $first = $names[0];
                        $src = $this->buildPublicUploadUrl($first);
                        $style = $height > 0 ? (' style="height: ' . $height . 'px; width: auto;"') : '';
                        $html = '<img src="' . $this->escapeHtml($src) . '" alt="" class="img-thumbnail"' . $style . ' />';
                    }
                }
            } elseif ($type === 'color') {
                $raw = $rawOriginal;
                if ($raw === null || $raw === '') {
                    $raw = $value;
                }
                $colorValue = trim($this->stringifyValue($raw));
                if ($colorValue !== '') {
                    $accent = $this->resolveAccentColor($colorValue);
                    $swatch = '<span style="display:inline-block;width:14px;height:14px;border:1px solid rgba(0,0,0,.2);vertical-align:middle;background-color: ' . $this->escapeHtml($accent) . ';"></span>';
                    $text = $this->escapeHtml($this->stringifyValue($value));
                    $html = $swatch . ' ' . $text;
                }
            }
        }

        // Default rendering: show boolean fields as a Bootstrap switch in grid
        if ($html === null && CrudConfig::$bools_in_grid) {
            $isBoolean = false;

            // Only allow inline toggle for base table columns
            $lower = strtolower($column);
            $baseLookup = [];
            foreach ($this->getBaseTableColumns() as $baseCol) {
                if (is_string($baseCol) && $baseCol !== '') {
                    $baseLookup[strtolower($baseCol)] = true;
                }
            }

            if (isset($baseLookup[$lower])) {
                // 1) Respect explicit change_type for the field if it indicates boolean
                $behaviour = $this->config['form']['behaviours']['change_type'][$column] ?? null;
                if (is_array($behaviour) && isset($behaviour['type'])) {
                    $t = strtolower((string) $behaviour['type']);
                    if ($t === 'bool' || $t === 'checkbox' || $t === 'switch') {
                        $isBoolean = true;
                    }
                }

                // 2) Infer from schema when not explicitly set
                if (!$isBoolean) {
                    $schema = $this->getTableSchema($this->table);
                    $schemaLookup = [];
                    foreach ($schema as $name => $meta) {
                        if (is_string($name) && $name !== '' && is_array($meta)) {
                            $schemaLookup[strtolower($name)] = $meta;
                        }
                    }

                    if (isset($schemaLookup[$lower])) {
                        $mapped = $this->mapDatabaseTypeToChangeType($schemaLookup[$lower]);
                        if (is_array($mapped) && ($mapped['type'] ?? null) === 'checkbox') {
                            $isBoolean = true;
                        }
                    }
                }
            }

            if ($isBoolean) {
                // Determine checked state using raw value when available
                $raw = $rawOriginal;
                if ($raw === null || $raw === '') {
                    $raw = $value;
                }
                $checked = $this->isTruthy($raw);

                $label   = $this->resolveColumnLabel($column);
                $pkCol   = isset($row['__fastcrud_primary_key']) && is_string($row['__fastcrud_primary_key'])
                    ? (string) $row['__fastcrud_primary_key']
                    : $this->primaryKeyColumn;
                $pkValue = $row['__fastcrud_primary_value'] ?? ($row[$pkCol] ?? null);

                $html = sprintf(
                    '<div class="fastcrud-bool-cell"><div class="form-check form-switch m-0"><input type="checkbox" class="form-check-input fastcrud-bool-view" role="switch" aria-label="%s" data-fastcrud-field="%s" data-fastcrud-pk="%s" data-fastcrud-pk-value="%s" %s></div></div>',
                    $this->escapeHtml($label),
                    $this->escapeHtml($column),
                    $this->escapeHtml($pkCol),
                    $this->escapeHtml($this->stringifyValue($pkValue)),
                    $checked ? 'checked' : ''
                );
                // Keep $display as original; render() will use HTML when available
            }
        }

        if (isset($this->config['column_classes'][$column])) {
            $cellClasses[] = $this->config['column_classes'][$column];
        }

        if (isset($this->config['column_highlights'][$column])) {
            foreach ($this->config['column_highlights'][$column] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $condition = $entry['condition'] ?? null;
                $class = isset($entry['class']) ? (string) $entry['class'] : '';
                if (!is_array($condition) || $class === '') {
                    continue;
                }

                if ($this->evaluateCondition($condition, $row)) {
                    $cellClasses[] = $class;
                }
            }
        }

        $width = $this->config['column_widths'][$column] ?? null;

        return [
            'display'    => $display,
            'html'       => $html,
            'class'      => trim(implode(' ', array_filter($cellClasses, static fn(string $class): bool => $class !== ''))),
            'tooltip'    => $tooltip,
            'attributes' => $attributes,
            'width'      => $width,
            'raw'        => $rawOriginal,
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value != 0.0;
        }
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return false;
        }
        if (is_numeric($text)) {
            return (float) $text != 0.0;
        }
        $truthy  = ['true', 't', 'yes', 'y', 'on', 'enabled', 'enable', 'active', 'checked'];
        $falsy   = ['false', 'f', 'no', 'n', 'off', 'disabled', 'disable', 'inactive', 'unchecked', 'null', 'none'];
        if (in_array($text, $truthy, true)) {
            return true;
        }
        if (in_array($text, $falsy, true)) {
            return false;
        }
        // default fallback for unknown strings
        return false;
    }

    private function buildPublicUploadUrl(string $name): string
    {
        $base = CrudConfig::getUploadPath();

        if ($name !== '' && (preg_match('/^https?:\/\//i', $name) === 1 || substr($name, 0, 1) === '/')) {
            return $name;
        }

        if ($base === '') {
            $base = 'public/uploads';
        }

        // If base is not a full URL and does not start with '/', prefix with '/'
        if (preg_match('/^https?:\/\//i', $base) !== 1 && substr($base, 0, 1) !== '/') {
            $base = '/' . $base;
        }

        // Normalize join
        $base = rtrim($base, '/');
        $segment = ltrim($name, '/');
        return $base . '/' . $segment;
    }

    private function extractFileName(string $value): string
    {
        $str = trim($value);
        if ($str === '') {
            return '';
        }
        // Strip fragment and query
        $hashPos = strpos($str, '#');
        if ($hashPos !== false) {
            $str = substr($str, 0, $hashPos);
        }
        $queryPos = strpos($str, '?');
        if ($queryPos !== false) {
            $str = substr($str, 0, $queryPos);
        }
        // Normalize separators and take last segment
        $str = str_replace('\\', '/', $str);
        $parts = explode('/', $str);
        $last = end($parts);
        return $last !== false ? (string) $last : '';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function parseImageNameList(mixed $value): array
    {
        $result = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item === null) {
                    continue;
                }
                $name = $this->extractFileName((string) $item);
                if ($name !== '' && !in_array($name, $result, true)) {
                    $result[] = $name;
                }
            }
            return $result;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        // Try to parse JSON array
        if ($text !== '' && ($text[0] === '[' || $text[0] === '{')) {
            try {
                $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        $name = $this->extractFileName((string) $item);
                        if ($name !== '' && !in_array($name, $result, true)) {
                            $result[] = $name;
                        }
                    }
                    return $result;
                }
            } catch (\Throwable) {
                // fall through to CSV parsing
            }
        }

        foreach (explode(',', $text) as $item) {
            $name = trim($this->extractFileName($item));
            if ($name !== '' && !in_array($name, $result, true)) {
                $result[] = $name;
            }
        }

        return $result;
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

    /**
     * @param array<string, string>|string $labels
     */
    public function set_column_labels(array|string $labels, ?string $label = null): self
    {
        if (is_array($labels)) {
            foreach ($labels as $column => $value) {
                if (!is_string($column)) {
                    continue;
                }

                $this->set_column_labels($column, is_string($value) ? $value : null);
            }
            return $this;
        }

        $column = $this->normalizeColumnReference($labels);
        if ($column === '') {
            throw new InvalidArgumentException('Column name cannot be empty when setting labels.');
        }

        $resolvedLabel = trim((string) $label);
        if ($resolvedLabel === '') {
            throw new InvalidArgumentException('Column label cannot be empty.');
        }

        $this->config['column_labels'][$column] = $resolvedLabel;

        return $this;
    }

    /**
     * @param array<string, string>|string $labels
     */
    public function set_field_labels(array|string $labels, ?string $label = null): self
    {
        if (is_array($labels)) {
            foreach ($labels as $field => $value) {
                if (!is_string($field)) {
                    continue;
                }

                $this->set_field_labels($field, is_string($value) ? $value : null);
            }
            return $this;
        }

        $field = $this->normalizeColumnReference($labels);
        if ($field === '') {
            throw new InvalidArgumentException('Field name cannot be empty when setting labels.');
        }

        $resolvedLabel = trim((string) $label);
        if ($resolvedLabel === '') {
            throw new InvalidArgumentException('Field label cannot be empty.');
        }

        $this->config['field_labels'][$field] = $resolvedLabel;

        return $this;
    }

  
    /**
     * Apply a simple HTML/text template to the column's rendered value.
     *
     * Example:
     * ```php
     * // Produces "slug - original title" inside a <strong> wrapper
     * $crud->column_pattern('slug', '<strong>{value} - {title}</strong>');
     * ```
     */
    public function column_pattern(string|array $columns, string $pattern): self
    {
        if (is_array($columns)) {
            foreach ($columns as $column) {
                if (!is_string($column)) {
                    continue;
                }
                $this->column_pattern($column, $pattern);
            }
            return $this;
        }

        $column = $this->normalizeColumnReference($columns);
        if ($column === '') {
            throw new InvalidArgumentException('Column name cannot be empty when assigning a pattern.');
        }

        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new InvalidArgumentException('Column pattern cannot be empty.');
        }

        $this->config['column_patterns'][$column] = $pattern;

        return $this;
    }

    /**
     * @param string|array<int, string> $columns
     */
    public function column_callback(string|array $columns, callable|string|array $callback): self
    {
        $list = $this->normalizeList($columns);
        if ($list === []) {
            throw new InvalidArgumentException('column_callback requires at least one column.');
        }

        $serialized = $this->normalizeCallable($callback);

        if (!is_callable($serialized)) {
            throw new InvalidArgumentException('Provided callback is not callable: ' . $serialized);
        }

        $applied = false;

        foreach ($list as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            $this->config['column_callbacks'][$normalized] = $serialized;
            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_callback requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $columns
     * @param string|array<int, string> $classes
     */
    public function column_class(string|array $columns, string|array $classes): self
    {
        $columnList = $this->normalizeList($columns);
        if ($columnList === []) {
            throw new InvalidArgumentException('column_class requires at least one column.');
        }

        $normalizedClasses = is_array($classes)
            ? $this->normalizeList($classes)
            : $this->normalizeList((string) $classes);

        $classString = implode(' ', $normalizedClasses);

        $applied = false;

        foreach ($columnList as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            $this->config['column_classes'][$normalized] = $classString;
            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_class requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $columns
     */
    public function column_width(string|array $columns, string $width): self
    {
        $columnList = $this->normalizeList($columns);
        if ($columnList === []) {
            throw new InvalidArgumentException('column_width requires at least one column.');
        }

        $width = trim($width);
        if ($width === '') {
            throw new InvalidArgumentException('Column width cannot be empty.');
        }

        $applied = false;

        foreach ($columnList as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            $this->config['column_widths'][$normalized] = $width;
            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_width requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $columns
     */
    public function column_cut(string|array $columns, int $length, string $suffix = '…'): self
    {
        $columnList = $this->normalizeList($columns);
        if ($columnList === []) {
            throw new InvalidArgumentException('column_cut requires at least one column.');
        }

        if ($length < 1) {
            throw new InvalidArgumentException('Column cut length must be at least 1.');
        }

        $applied = false;

        foreach ($columnList as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            $this->config['column_cuts'][$normalized] = [
                'length' => $length,
                'suffix' => $suffix,
            ];
            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_cut requires at least one valid column name.');
        }

        return $this;
    }


    /**
     * @param string|array<int, string> $columns
     * @param array<string, mixed>|string $condition
     */
    public function highlight(string|array $columns, array|string $condition, string $class = 'text-warning'): self
    {
        $columnList = $this->normalizeList($columns);
        if ($columnList === []) {
            throw new InvalidArgumentException('highlight requires at least one column.');
        }

        $class = $this->normalizeCssClassList($class);

        $applied = false;

        foreach ($columnList as $column) {
            $normalizedColumn = $this->normalizeColumnReference($column);
            if ($normalizedColumn === '') {
                continue;
            }

            $normalizedCondition = $this->normalizeCondition($condition, $normalizedColumn);

            $this->config['column_highlights'][$normalizedColumn][] = [
                'condition' => $normalizedCondition,
                'class'     => $class,
            ];

            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('highlight requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * @param array<string, mixed>|string $condition
     */
    public function highlight_row(array|string $condition, string $class = 'table-warning'): self
    {
        $normalizedCondition = $this->normalizeCondition($condition, null);
        $class = $this->normalizeCssClassList($class);

        $this->config['row_highlights'][] = [
            'condition' => $normalizedCondition,
            'class'     => $class,
        ];

        return $this;
    }

    public function table_name(string $name): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Table name cannot be empty.');
        }

        $this->config['table_meta']['name'] = $name;

        return $this;
    }

    public function table_tooltip(string $tooltip): self
    {
        $tooltip = trim($tooltip);
        $this->config['table_meta']['tooltip'] = $tooltip === '' ? null : $tooltip;

        return $this;
    }

    public function table_icon(string $iconClass): self
    {
        $iconClass = $this->normalizeCssClassList($iconClass);
        $this->config['table_meta']['icon'] = $iconClass === '' ? null : $iconClass;

        return $this;
    }

    public function enable_duplicate(bool $enabled = true): self
    {
        $this->config['table_meta']['duplicate'] = (bool) $enabled;

        return $this;
    }


    /**
     * @param string|array<int, string> $columns
     */
    public function column_summary(string|array $columns, string $type = 'sum', ?string $label = null, ?int $precision = null): self
    {
        $columnList = $this->normalizeList($columns);
        if ($columnList === []) {
            throw new InvalidArgumentException('column_summary requires at least one column.');
        }

        $type = strtolower(trim($type));
        if (!in_array($type, self::SUPPORTED_SUMMARY_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported summary type: ' . $type . '| Supported types: ' . implode(', ', self::SUPPORTED_SUMMARY_TYPES) . '.');
        }

        if ($precision !== null && $precision < 0) {
            throw new InvalidArgumentException('Summary precision cannot be negative.');
        }

        $applied = false;
        $labelValue = $label ? trim($label) : null;

        foreach ($columnList as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            $this->config['column_summaries'][] = [
                'column'    => $normalized,
                'type'      => $type,
                'label'     => $labelValue,
                'precision' => $precision,
            ];

            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_summary requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string>|false $mode
     */
    public function fields(string|array $fields, bool $reverse = false, string|false $tab = false, string|array|false $mode = false): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('Field configuration list cannot be empty.');
        }

        $normalizedFields = [];
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized !== '') {
                $normalizedFields[] = $normalized;
            }
        }

        if ($normalizedFields === []) {
            throw new InvalidArgumentException('Field configuration list cannot be empty.');
        }

        $tabName = null;
        if ($tab !== false) {
            $candidate = trim((string) $tab);
            $tabName = $candidate === '' ? null : $candidate;
        }

        $modes = $this->normalizeFormModes($mode);
        $this->storeLayoutEntry($normalizedFields, $reverse, $tabName, $modes);

        return $this;
    }

    /**
     * @param string|array<int, string>|false $mode
     */
    public function default_tab(string $tabName, string|array|false $mode = false): self
    {
        $tabName = trim($tabName);
        if ($tabName === '') {
            throw new InvalidArgumentException('Default tab name cannot be empty.');
        }

        $this->ensureDefaultTabBuckets();
        $modes = $this->normalizeFormModes($mode);

        foreach ($modes as $targetMode) {
            $bucket = $targetMode === 'all' ? 'all' : $targetMode;
            $this->config['form']['default_tabs'][$bucket] = $tabName;
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     */
    public function change_type(string|array $fields, string $type, mixed $default = '', array $params = []): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('change_type requires at least one field.');
        }

        $type = strtolower(trim($type));
        if ($type === '') {
            throw new InvalidArgumentException('Field type cannot be empty.');
        }

        if (!is_array($params)) {
            $params = [];
        }

        $this->ensureFormBehaviourBuckets();

        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }

            $this->config['form']['behaviours']['change_type'][$normalized] = [
                'type'    => $type,
                'default' => $default,
                'params'  => $params,
            ];
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function pass_var(string|array $fields, mixed $value, string|array $mode = 'all'): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('pass_var requires at least one field.');
        }

        $modes = $this->normalizeFormModes($mode);
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }
            $this->storeBehaviourValue('pass_var', $normalized, $value, $modes);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function pass_default(string|array $fields, mixed $value, string|array $mode = 'all'): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('pass_default requires at least one field.');
        }

        $modes = $this->normalizeFormModes($mode);
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }
            $this->storeBehaviourValue('pass_default', $normalized, $value, $modes);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function readonly(string|array $fields, string|array $mode = 'all'): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('readonly requires at least one field.');
        }

        $modes = $this->normalizeFormModes($mode);
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }
            $this->storeBehaviourFlag('readonly', $normalized, true, $modes);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function disabled(string|array $fields, string|array $mode = 'all'): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('disabled requires at least one field.');
        }

        $modes = $this->normalizeFormModes($mode);
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }
            $this->storeBehaviourFlag('disabled', $normalized, true, $modes);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function validation_required(string|array $fields, int $minLength = 1, string|array $mode = 'all'): self
    {
        if ($minLength < 1) {
            throw new InvalidArgumentException('Minimum length for required validation must be at least 1.');
        }

        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('validation_required requires at least one field.');
        }

        $modes = $this->normalizeFormModes($mode);
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }
            $this->storeBehaviourValue('validation_required', $normalized, $minLength, $modes);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function validation_pattern(string|array $fields, string $pattern, string|array $mode = 'all'): self
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            throw new InvalidArgumentException('Validation pattern cannot be empty.');
        }

        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('validation_pattern requires at least one field.');
        }

        $modes = $this->normalizeFormModes($mode);
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }
            $this->storeBehaviourValue('validation_pattern', $normalized, $pattern, $modes);
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function unique(string|array $fields, string|array $mode = 'all'): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('unique requires at least one field.');
        }

        $modes = $this->normalizeFormModes($mode);
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }
            $this->storeBehaviourFlag('unique', $normalized, true, $modes);
        }

        return $this;
    }

    /**
     * @param string|array<int, string>|array<string, string> $fields
     */
    public function order_by(string|array $fields, string $direction = 'asc'): self
    {
        // Support associative arrays: ['status' => 'asc', 'name' => 'desc']
        if (is_array($fields) && $this->isAssociativeArray($fields)) {
            if ($fields === []) {
                throw new InvalidArgumentException('Order by field cannot be empty.');
            }

            foreach ($fields as $field => $dir) {
                if (!is_string($field)) {
                    continue;
                }

                $dir = strtoupper(trim((string) $dir));
                if (!in_array($dir, ['ASC', 'DESC'], true)) {
                    throw new InvalidArgumentException('Order direction must be ASC or DESC.');
                }

                $this->config['order_by'][] = [
                    'field'     => $field,
                    'direction' => $dir,
                ];
            }

            return $this;
        }

        // Fallback: list of fields or single field with one direction
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('Order by field cannot be empty.');
        }

        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Order direction must be ASC or DESC.');
        }

        foreach ($list as $field) {
            $this->config['order_by'][] = [
                'field'     => $field,
                'direction' => $direction,
            ];
        }

        return $this;
    }

    /**
     * Disable sorting for specific columns in the UI and server ordering.
     *
     * @param string|array<int, string> $columns
     */
    public function disable_sort(string|array $columns): self
    {
        $list = $this->normalizeList($columns);
        if ($list === []) {
            throw new InvalidArgumentException('disable_sort requires at least one column.');
        }

        $normalized = [];
        foreach ($list as $column) {
            $c = $this->normalizeColumnReference($column);
            if ($c !== '') {
                $normalized[$c] = true;
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('disable_sort requires at least one valid column name.');
        }

        $current = [];
        foreach ($this->config['sort_disabled'] as $existing) {
            if (is_string($existing) && $existing !== '') {
                $current[$existing] = true;
            }
        }

        $this->config['sort_disabled'] = array_keys($current + $normalized);

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

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string>|false $alias
     */
    public function join(string|array $fields, string $joinTable, string $joinField, string|array|false $alias = false, bool $notInsert = false): self
    {
        $fieldList = $this->normalizeList($fields);
        if ($fieldList === []) {
            throw new InvalidArgumentException('Join requires at least one field.');
        }

        $aliasList = null;
        $baseAlias = null;

        if (is_array($alias)) {
            $aliasList = [];
            foreach ($alias as $value) {
                if (!is_string($value) && !is_int($value)) {
                    $aliasList[] = null;
                    continue;
                }

                $trimmedAlias = trim((string) $value);
                $aliasList[] = $trimmedAlias === '' ? null : $trimmedAlias;
            }
        } elseif (is_string($alias)) {
            $trimmed = trim($alias);
            $baseAlias = $trimmed === '' ? null : $trimmed;
        }

        foreach ($fieldList as $index => $field) {
            $aliasValue = null;

            if ($aliasList !== null) {
                $aliasValue = $aliasList[$index] ?? null;
            } elseif ($baseAlias !== null) {
                $aliasValue = $baseAlias . ($index === 0 ? '' : '_' . ($index + 1));
            }

            if ($aliasValue === null || $aliasValue === '') {
                $aliasValue = 'j' . count($this->config['joins']);
            }

            $this->config['joins'][] = [
                'field'      => $field,
                'table'      => $joinTable,
                'join_field' => $joinField,
                'alias'      => $aliasValue,
                'not_insert' => $notInsert,
            ];
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $relName
     * @param array<string, mixed> $relWhere
     */
    public function relation(
        string|array $fields,
        string $relatedTable,
        string $relatedField,
        string|array $relName,
        array $relWhere = [],
        string|false $orderBy = false,
        bool $multi = false
    ): self {
        $fieldList = $this->normalizeList($fields);
        if ($fieldList === []) {
            throw new InvalidArgumentException('Relation requires at least one field.');
        }

        foreach ($fieldList as $field) {
            $normalizedField = $this->normalizeColumnReference($field);
            if ($normalizedField === '') {
                continue;
            }

            $this->config['relations'][] = [
                'field'         => $normalizedField,
                'table'         => $relatedTable,
                'related_field' => $relatedField,
                'related_name'  => $relName,
                'where'         => $relWhere,
                'order_by'      => $orderBy === false ? null : $orderBy,
                'multi'         => $multi,
            ];
        }

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

        if ($searchTerm !== null && $searchTerm !== '') {
            $configuredColumns = $this->config['search_columns'];
            $map = $this->getWhereColumnsMapForAllSearch(); // display => expr
            $targetExpr = null;

            if ($searchColumn !== null && $searchColumn !== '') {
                if (isset($map[$searchColumn])) {
                    $targetExpr = $map[$searchColumn];
                } elseif ($configuredColumns !== [] && in_array($searchColumn, $configuredColumns, true)) {
                    $targetExpr = $this->normalizeWhereField($searchColumn);
                }
            }

            if ($targetExpr !== null && $targetExpr !== '') {
                $placeholder = ':search_term';
                $parameters[$placeholder] = '%' . $searchTerm . '%';
                $searchClause = sprintf('%s LIKE %s', $targetExpr, $placeholder);
            } else {
                // When "All" is selected (or no specific/allowed column picked),
                // search across the visible grid columns if available,
                // otherwise fall back to configured search columns.
                $exprList = array_values($map);
                if ($exprList === []) {
                    $exprList = [];
                    foreach ($configuredColumns as $c) {
                        $expr = $this->normalizeWhereField($c);
                        if ($expr !== '') { $exprList[] = $expr; }
                    }
                }

                $parts = [];
                $value = '%' . $searchTerm . '%';
                foreach ($exprList as $idx => $expr) {
                    if ($expr === '') { continue; }
                    $ph = ':search_term_' . $idx;
                    $parameters[$ph] = $value;
                    $parts[] = sprintf('%s LIKE %s', $expr, $ph);
                }
                $searchClause = $parts !== [] ? '(' . implode(' OR ', $parts) . ')' : '';
            }

            if ($searchClause !== '') {
                $clauses[] = [
                    'glue'   => 'AND',
                    'clause' => $searchClause,
                ];
            }
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

    private function getSqlIdentifierQuotes(): array
    {
        $driver = 'mysql';
        try {
            $driver = strtolower((string) $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (PDOException) {
            $driver = 'mysql';
        }

        if ($driver === 'pgsql' || $driver === 'sqlite') {
            return ['"', '"'];
        }

        // Default to MySQL backticks
        return ['`', '`'];
    }

    private function quoteIdentifierPart(string $part): string
    {
        [$l, $r] = $this->getSqlIdentifierQuotes();
        $trimmed = trim($part);
        if ($trimmed === '') {
            return $part;
        }
        // If already quoted, return as-is
        if ((str_starts_with($trimmed, '`') && str_ends_with($trimmed, '`')) ||
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))) {
            return $trimmed;
        }

        // Escape any embedded quote of the same type
        $escaped = str_replace([$l, $r], [$l . $l, $r . $r], $trimmed);
        return $l . $escaped . $r;
    }

    private function quoteQualifiedIdentifier(string $qualified): string
    {
        $expr = trim($qualified);
        if ($expr === '') {
            return '';
        }
        // Only quote simple alias.column paths. If expression contains spaces or parentheses, return as-is.
        if (str_contains($expr, ' ') || str_contains($expr, '(') || str_contains($expr, ')')) {
            return $expr;
        }

        $parts = explode('.', $expr);
        $quoted = [];
        foreach ($parts as $p) {
            if ($p === '') { continue; }
            $quoted[] = $this->quoteIdentifierPart($p);
        }
        return implode('.', $quoted);
    }

    private function normalizeWhereField(string $column): string
    {
        $raw = trim((string) $column);
        if ($raw === '') {
            return '';
        }

        // Allow expressions; otherwise, normalize to alias.column
        if (str_contains($raw, ' ') || str_contains($raw, '(') || str_contains($raw, ')')) {
            return $raw;
        }

        // Map alias__name => alias.name
        if (str_contains($raw, '__') && !str_contains($raw, '.')) {
            $raw = $this->denormalizeColumnReference($raw);
        }

        if (!str_contains($raw, '.')) {
            $raw = 'main.' . $raw;
        }

        return $this->quoteQualifiedIdentifier($raw);
    }

    

    /**
     * Map visible display columns to WHERE-capable SQL expressions.
     * Keys are display column names as seen by the client (e.g., title, j1__name).
     * Values are SQL-qualified identifiers (e.g., `main`.`title`, j1.name).
     * Excludes subselect columns and non-LIKE-able types.
     *
     * @return array<string, string>
     */
    private function getWhereColumnsMapForAllSearch(): array
    {
        if ($this->config['custom_query'] !== null) {
            return [];
        }

        // Build alias => table map for later schema lookups
        $aliasToTable = [];
        foreach ($this->config['joins'] as $index => $join) {
            $alias = isset($join['alias']) && is_string($join['alias']) && $join['alias'] !== ''
                ? $join['alias']
                : ('j' . $index);
            $aliasToTable[$alias] = $join['table'];
        }

        // Build the available display columns: base + joins + subselects
        $available = [];
        foreach ($this->getBaseTableColumns() as $col) {
            if (is_string($col) && $col !== '') {
                $available[] = $col;
            }
        }
        foreach ($aliasToTable as $alias => $table) {
            $joinColumns = $this->getTableColumnsFor($table);
            foreach ($joinColumns as $jcol) {
                if (is_string($jcol) && $jcol !== '') {
                    $available[] = $alias . '__' . $jcol;
                }
            }
        }

        // Track subselect names to exclude from WHERE
        $subselectNames = [];
        foreach ($this->config['subselects'] as $sub) {
            $name = isset($sub['column']) ? (string) $sub['column'] : '';
            if ($name !== '') {
                $available[] = $name;
                $subselectNames[$name] = true;
            }
        }

        // Resolve visible display list
        $visible = $this->calculateVisibleColumns($available);
        if ($visible === []) {
            return [];
        }

        // Load schemas to filter out non-LIKE-able types
        $mainSchema = $this->getTableSchema($this->table);
        $joinSchemas = [];
        foreach ($aliasToTable as $alias => $table) {
            $joinSchemas[$alias] = $this->getTableSchema($table);
        }

        $isSearchableType = static function (?string $type): bool {
            if ($type === null || $type === '') {
                return true;
            }
            $t = strtolower($type);
            $paren = strpos($t, '(');
            if ($paren !== false) {
                $t = substr($t, 0, $paren);
            }
            $blocked = [
                'json','blob','tinyblob','mediumblob','longblob',
                'binary','varbinary','bit',
                'geometry','point','linestring','polygon','multipoint','multilinestring','multipolygon','geometrycollection'
            ];
            foreach ($blocked as $b) {
                if ($t === $b) {
                    return false;
                }
            }
            return true;
        };

        $map = [];
        foreach ($visible as $displayCol) {
            if (!is_string($displayCol) || $displayCol === '') {
                continue;
            }
            if (isset($subselectNames[$displayCol])) {
                continue; // cannot reference subselect alias in WHERE
            }

            if (strpos($displayCol, '__') !== false) {
                [$alias, $name] = array_map('trim', explode('__', $displayCol, 2));
                $typeMeta = $joinSchemas[$alias][$name]['type'] ?? null;
                if (!$isSearchableType(is_string($typeMeta) ? $typeMeta : null)) {
                    continue;
                }
                $expr = $this->quoteQualifiedIdentifier($alias . '.' . $name);
                $map[$displayCol] = $expr;
            } else {
                $typeMeta = $mainSchema[$displayCol]['type'] ?? null;
                if (!$isSearchableType(is_string($typeMeta) ? $typeMeta : null)) {
                    continue;
                }
                $expr = $this->quoteQualifiedIdentifier('main.' . $displayCol);
                $map[$displayCol] = $expr;
            }
        }

        return $map;
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
            $disabled = [];
            foreach ($this->config['sort_disabled'] as $dcol) {
                if (is_string($dcol) && $dcol !== '') {
                    $disabled[$dcol] = true;
                }
            }

            $orderParts = [];
            foreach ($this->config['order_by'] as $order) {
                if (!is_array($order) || !isset($order['field'], $order['direction'])) {
                    continue;
                }
                $field = (string) $order['field'];
                $normalized = $this->normalizeColumnReference($field);
                if ($normalized !== '' && isset($disabled[$normalized])) {
                    // Skip disabled columns from ORDER BY
                    continue;
                }

                // Build a safe SQL expression for ORDER BY
                // Support alias__column notation and quote identifiers when applicable.
                $expr = $this->denormalizeColumnReference($normalized);
                $isExpression = (str_contains($expr, ' ') || str_contains($expr, '(') || str_contains($expr, ')'));
                if (!$isExpression) {
                    if (strpos($expr, '.') === false) {
                        $expr = 'main.' . $expr;
                    }
                    $expr = $this->quoteQualifiedIdentifier($expr);
                }

                $dir = strtoupper((string) $order['direction']);
                if ($dir !== 'ASC' && $dir !== 'DESC') {
                    $dir = 'ASC';
                }

                $orderParts[] = $expr . ' ' . $dir;
            }

            if ($orderParts !== []) {
                $sql .= ' ORDER BY ' . implode(', ', $orderParts);
            }
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
        // Use COUNT(DISTINCT main.pk) when joins are present to avoid overcounting
        $useDistinct = $this->config['joins'] !== [];
        $pkExpr = $this->quoteQualifiedIdentifier('main.' . $this->getPrimaryKeyColumn());
        $countExpr = $useDistinct ? ('COUNT(DISTINCT ' . $pkExpr . ')') : 'COUNT(*)';
        $sql = sprintf('SELECT %s FROM %s', $countExpr, $this->buildFromClause());

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

                if (!isset($rows[$rowIndex]['__fastcrud_raw']) || !is_array($rows[$rowIndex]['__fastcrud_raw'])) {
                    $rows[$rowIndex]['__fastcrud_raw'] = [];
                }

                $rows[$rowIndex]['__fastcrud_raw'][$field] = $currentValue;

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
        $baseColumns = $this->getBaseTableColumns();
        foreach ($rows as $row) {
            $filteredRow = [
                '__fastcrud_primary_key' => $row['__fastcrud_primary_key'] ?? null,
                '__fastcrud_primary_value' => $row['__fastcrud_primary_value'] ?? null,
            ];
            foreach ($visible as $column) {
                $filteredRow[$column] = $row[$column] ?? null;
            }

            // Ensure all base table columns are present so edit forms can prefill
            // even when a field is hidden in the grid.
            foreach ($baseColumns as $baseColumn) {
                if (!array_key_exists($baseColumn, $filteredRow)) {
                    $filteredRow[$baseColumn] = $row[$baseColumn] ?? null;
                }
            }


            // Preserve the original row so hidden columns remain available for patterns/callbacks.
            $filteredRow['__fastcrud_row'] = $row;

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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTableSchema(string $table): array
    {
        if (isset($this->tableSchemaCache[$table])) {
            return $this->tableSchemaCache[$table];
        }

        $driver = null;
        try {
            $driver = strtolower((string) $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (PDOException) {
            $driver = null;
        }

        $schema = [];

        switch ($driver) {
            case 'mysql':
                $schema = $this->loadMysqlTableSchema($table);
                break;
            case 'pgsql':
                $schema = $this->loadPgsqlTableSchema($table);
                break;
            case 'sqlite':
            case 'sqlite2':
            case 'sqlite3':
                $schema = $this->loadSqliteTableSchema($table);
                break;
        }

        if ($schema === []) {
            $schema = $this->loadGenericTableSchema($table);
        }

        $this->tableSchemaCache[$table] = $schema;

        return $schema;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadMysqlTableSchema(string $table): array
    {
        $schema = [];
        $sql = sprintf('SHOW FULL COLUMNS FROM `%s`', $table);

        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException) {
            return $schema;
        }

        if ($statement === false) {
            return $schema;
        }

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $field = $row['Field'] ?? null;
            if (!is_string($field) || $field === '') {
                continue;
            }

            $type = isset($row['Type']) ? strtolower((string) $row['Type']) : null;

            $schema[$field] = [
                'type' => $type,
                'raw_type' => $row['Type'] ?? null,
                'meta' => $row,
            ];
        }

        return $schema;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadPgsqlTableSchema(string $table): array
    {
        $schema = [];

        $sql = <<<'SQL'
SELECT column_name, data_type, udt_name
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = :table
SQL;

        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            return $schema;
        }

        try {
            $statement->execute(['table' => $table]);
        } catch (PDOException) {
            return $schema;
        }

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $field = $row['column_name'] ?? null;
            if (!is_string($field) || $field === '') {
                continue;
            }

            $dataType = isset($row['data_type']) ? strtolower((string) $row['data_type']) : null;
            $udtName  = isset($row['udt_name']) ? strtolower((string) $row['udt_name']) : null;

            $schema[$field] = [
                'type' => $dataType ?: $udtName,
                'data_type' => $dataType,
                'udt_name' => $udtName,
                'meta' => $row,
            ];
        }

        return $schema;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadSqliteTableSchema(string $table): array
    {
        $schema = [];
        $sql = sprintf("PRAGMA table_info('%s')", $table);

        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException) {
            return $schema;
        }

        if ($statement === false) {
            return $schema;
        }

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $field = $row['name'] ?? null;
            if (!is_string($field) || $field === '') {
                continue;
            }

            $type = isset($row['type']) ? strtolower((string) $row['type']) : null;

            $schema[$field] = [
                'type' => $type,
                'raw_type' => $row['type'] ?? null,
                'meta' => $row,
            ];
        }

        return $schema;
    }

    /**
     * Fallback metadata loader that relies on PDO column metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadGenericTableSchema(string $table): array
    {
        $schema = [];
        $sql = sprintf('SELECT * FROM %s LIMIT 0', $table);

        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException) {
            return $schema;
        }

        if ($statement === false) {
            return $schema;
        }

        $count = $statement->columnCount();

        for ($index = 0; $index < $count; $index++) {
            $meta = $statement->getColumnMeta($index) ?: [];
            $field = $meta['name'] ?? null;
            if (!is_string($field) || $field === '') {
                continue;
            }

            $typeCandidates = [];
            if (isset($meta['native_type'])) {
                $typeCandidates[] = strtolower((string) $meta['native_type']);
            }
            if (isset($meta['pdo_type'])) {
                $typeCandidates[] = strtolower((string) $meta['pdo_type']);
            }
            foreach (['sqlite:decl_type', 'sqlite:datatype'] as $sqliteKey) {
                if (isset($meta[$sqliteKey])) {
                    $typeCandidates[] = strtolower((string) $meta[$sqliteKey]);
                }
            }

            $type = null;
            foreach ($typeCandidates as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $type = $candidate;
                    break;
                }
            }

            $schema[$field] = [
                'type' => $type,
                'meta' => $meta,
            ];
        }

        return $schema;
    }

    /**
     * Infer sensible default input types for known columns based on table schema.
     *
     * @param array<int, string> $columns
     * @return array<string, array<string, mixed>>
     */
    private function inferDefaultChangeTypes(array $columns): array
    {
        $defaults = [];
        $columnMap = [];
        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                continue;
            }
            $columnMap[strtolower($column)] = $column;
        }

        $relationDefaults = $this->mapRelationsToChangeTypes($columnMap);
        if ($relationDefaults !== []) {
            $defaults = $relationDefaults;
        }

        $schema = $this->getTableSchema($this->table);
        if ($schema === []) {
            return $defaults;
        }

        $schemaLookup = [];
        foreach ($schema as $name => $meta) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $schemaLookup[strtolower($name)] = $meta;
        }

        foreach ($columnMap as $lookupKey => $originalName) {
            if (isset($defaults[$originalName])) {
                continue;
            }
            if (!isset($schemaLookup[$lookupKey])) {
                continue;
            }

            $definition = $this->mapDatabaseTypeToChangeType($schemaLookup[$lookupKey]);
            if ($definition !== null) {
                $defaults[$originalName] = $definition;
            }
        }

        return $defaults;
    }

    /**
     * @param array<string, string> $columnMap Lowercase field => original field name
     * @return array<string, array<string, mixed>>
     */
    private function mapRelationsToChangeTypes(array $columnMap): array
    {
        if ($this->config['relations'] === []) {
            return [];
        }

        $defaults = [];

        foreach ($this->config['relations'] as $relation) {
            if (!is_array($relation) || !isset($relation['field'])) {
                continue;
            }

            $field = strtolower((string) $relation['field']);
            if ($field === '' || !isset($columnMap[$field])) {
                continue;
            }

            $originalField = $columnMap[$field];

            $options = $this->fetchRelationOptions($relation);
            $params = [];
            if ($options !== []) {
                $params['values'] = $options;
            }

            $defaults[$originalField] = [
                'type' => !empty($relation['multi']) ? 'multiselect' : 'select',
                'default' => '',
                'params' => $params,
            ];
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $relation
     * @return array<string, string>
     */
    private function fetchRelationOptions(array $relation): array
    {
        $field = isset($relation['field']) ? (string) $relation['field'] : '';
        $table = isset($relation['table']) ? (string) $relation['table'] : '';
        $relatedField = isset($relation['related_field']) ? (string) $relation['related_field'] : '';
        $nameFields = isset($relation['related_name']) ? (array) $relation['related_name'] : [];

        if ($field === '' || $table === '' || $relatedField === '') {
            return [];
        }

        $cacheKeyParts = [$table, $relatedField, $nameFields, $relation['where'] ?? [], $relation['order_by'] ?? null];
        $cacheKey = md5(json_encode($cacheKeyParts) ?: serialize($cacheKeyParts));
        if (isset($this->relationOptionsCache[$cacheKey])) {
            return $this->relationOptionsCache[$cacheKey];
        }

        $selectColumns = [$relatedField . ' AS relation_key'];
        foreach ($nameFields as $index => $nameField) {
            if (!is_string($nameField) || trim($nameField) === '') {
                continue;
            }
            $alias = sprintf('relation_value_%d', $index);
            $selectColumns[] = sprintf('%s AS %s', $nameField, $alias);
        }

        $sql = sprintf('SELECT %s FROM %s', implode(', ', $selectColumns), $table);
        $parameters = [];
        $conditions = [];

        if (!empty($relation['where']) && is_array($relation['where'])) {
            foreach ($relation['where'] as $whereField => $whereValue) {
                if (!is_string($whereField) || trim($whereField) === '') {
                    continue;
                }
                $placeholder = sprintf(':relopt_%s_%d', preg_replace('/[^a-z0-9_]+/i', '_', $field), count($parameters));
                $parameters[$placeholder] = $whereValue;
                $conditions[] = sprintf('%s = %s', $whereField, $placeholder);
            }
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if (!empty($relation['order_by']) && is_string($relation['order_by'])) {
            $sql .= ' ORDER BY ' . $relation['order_by'];
        }

        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            $this->relationOptionsCache[$cacheKey] = [];
            return [];
        }

        try {
            $statement->execute($parameters);
        } catch (PDOException) {
            $this->relationOptionsCache[$cacheKey] = [];
            return [];
        }

        $options = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ($row === false) {
                break;
            }

            $key = $row['relation_key'] ?? null;
            if ($key === null) {
                continue;
            }

            $parts = [];
            foreach ($nameFields as $index => $nameField) {
                if (!is_string($nameField) || trim($nameField) === '') {
                    continue;
                }
                $alias = sprintf('relation_value_%d', $index);
                $parts[] = $row[$alias] ?? '';
            }

            $label = trim(implode(' ', array_filter($parts, static fn($part) => $part !== null && $part !== '')));
            if ($label === '') {
                $label = (string) $key;
            }

            $options[(string) $key] = $label;
        }

        $this->relationOptionsCache[$cacheKey] = $options;

        return $options;
    }

    /**
     * @param array<string, mixed> $columnMeta
     * @return array<string, mixed>|null
     */
    private function mapDatabaseTypeToChangeType(array $columnMeta): ?array
    {
        $typeInfo = $this->detectSqlTypeInfo($columnMeta);
        $rawType = $typeInfo['raw'];
        $normalizedType = $typeInfo['normalized'];

        $params = [];
        $changeType = null;

        if ($rawType !== '' && (preg_match('/tinyint\s*\(\s*1\s*\)/', $rawType) || preg_match('/bit\s*\(\s*1\s*\)/', $rawType))) {
            $changeType = 'checkbox';
        } elseif ($normalizedType !== '' && preg_match('/\b(bool|boolean)\b/', $normalizedType)) {
            $changeType = 'checkbox';
        } elseif ($normalizedType !== '') {
            if (in_array($normalizedType, ['json', 'jsonb'], true)) {
                $changeType = 'json';
            } elseif (str_contains($normalizedType, 'text') || $normalizedType === 'xml') {
                $changeType = 'textarea';
            }
        } elseif ($normalizedType === 'date') {
            $changeType = 'date';
        } elseif ($normalizedType !== '' && (str_contains($normalizedType, 'timestamp') || str_contains($normalizedType, 'datetime'))) {
            $changeType = 'datetime-local';
        } elseif ($normalizedType !== '' && str_contains($normalizedType, 'time') && !str_contains($normalizedType, 'timestamp') && !str_contains($normalizedType, 'datetime')) {
            $changeType = 'time';
        } elseif ($normalizedType !== '' && $this->isNumericType($normalizedType)) {
            $changeType = 'number';
            if (preg_match('/\b(decimal|numeric|float|double|real|money)\b/', $normalizedType)) {
                $params['step'] = 'any';
            }
        }

        if ($changeType === null) {
            return null;
        }

        return [
            'type' => $changeType,
            'default' => '',
            'params' => $params,
        ];
    }

    /**
     * Extract raw and normalized SQL type candidates from a schema definition.
     *
     * @param array<string, mixed> $columnMeta
     * @return array{raw: string, normalized: string}
     */
    private function detectSqlTypeInfo(array $columnMeta): array
    {
        $candidates = [];
        foreach (['raw_type', 'type', 'data_type', 'udt_name'] as $key) {
            if (isset($columnMeta[$key]) && is_string($columnMeta[$key]) && $columnMeta[$key] !== '') {
                $candidates[] = strtolower((string) $columnMeta[$key]);
            }
        }

        if (isset($columnMeta['meta']) && is_array($columnMeta['meta'])) {
            foreach (['native_type', 'sqlite:decl_type', 'sqlite:datatype'] as $metaKey) {
                if (isset($columnMeta['meta'][$metaKey]) && is_string($columnMeta['meta'][$metaKey]) && $columnMeta['meta'][$metaKey] !== '') {
                    $candidates[] = strtolower((string) $columnMeta['meta'][$metaKey]);
                }
            }
        }

        $rawType = $candidates[0] ?? '';
        $normalizedType = '';

        foreach ($candidates as $candidate) {
            $normalizedCandidate = $this->normalizeSqlType($candidate);
            if ($normalizedCandidate !== '') {
                $normalizedType = $normalizedCandidate;
                break;
            }
        }

        return [
            'raw' => $rawType,
            'normalized' => $normalizedType,
        ];
    }

    private function normalizeSqlType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return '';
        }

        $type = preg_replace('/\([^\)]*\)/', '', $type) ?? $type;
        $type = str_replace(['unsigned', 'zerofill'], '', $type);
        $type = preg_replace('/\s+/', ' ', $type) ?? $type;

        return trim($type);
    }

    private function isNumericType(string $normalizedType): bool
    {
        $tokens = preg_split('/\s+/', $normalizedType) ?: [];
        $numericTokens = [
            'int',
            'integer',
            'smallint',
            'tinyint',
            'mediumint',
            'bigint',
            'decimal',
            'numeric',
            'float',
            'double',
            'real',
            'serial',
            'bigserial',
            'smallserial',
            'money',
            'year',
        ];

        foreach ($tokens as $token) {
            if (in_array($token, $numericTokens, true)) {
                return true;
            }
        }

        return false;
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

    private function denormalizeColumnReference(string $column): string
    {
        return str_replace('__', '.', $column);
    }

    /**
     * @return array<int, string>
     */
    private function getBaseTableColumns(): array
    {
        return $this->getTableColumnsFor($this->table);
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
    <div id="{$id}-meta" class="d-flex flex-wrap align-items-center gap-2 mb-2"></div>
    <div class="table-responsive">
        <table id="$id" class="table align-middle" data-table="$table" data-per-page="$perPage">
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
            <tfoot id="{$id}-summary" class="fastcrud-summary"></tfoot>
        </table>
    </div>
    <nav aria-label="Table pagination" class="d-flex flex-wrap align-items-center gap-2 justify-content-between mt-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <ul id="{$id}-pagination" class="pagination justify-content-start mb-0 flex-wrap"></ul>
            <div id="{$id}-toolbar" class="d-flex flex-wrap align-items-center gap-2"></div>
        </div>
        <div id="{$id}-range" class="text-muted small ms-auto"></div>
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
            throw new RuntimeException('Failed to execute select query: ' . $exception->getMessage(), 0, $exception);
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $rows = $this->attachPrimaryKeyMetadata($rows);
        $rows = $this->applyRelations($rows);

        $columns = $this->extractColumnNames($statement, $rows);
        [$rows, $columns] = $this->applyColumnVisibility($rows, $columns);

        $rows = $this->decorateRows($rows, $columns);

        return [$rows, $columns];
    }

    

    /**
     * @param array<int, string> $columns
     */
    private function buildHeader(array $columns): string
    {
        $cells = [];

        foreach ($columns as $column) {
            $label = $this->resolveColumnLabel($column);
            $classes = ['fastcrud-column', 'fastcrud-column-' . $this->buildColumnSlug($column)];

            $width = isset($this->config['column_widths'][$column])
                ? $this->interpretWidth((string) $this->config['column_widths'][$column])
                : ['class' => null, 'style' => null];

            if ($width['class']) {
                $classes[] = $width['class'];
            }

            $attributes = ['scope="col"', 'data-column="' . $this->escapeHtml($column) . '"'];

            $classString = trim(implode(' ', array_filter($classes, static fn(string $value): bool => $value !== '')));
            if ($classString !== '') {
                $attributes[] = 'class="' . $this->escapeHtml($classString) . '"';
            }

            if ($width['style']) {
                $attributes[] = 'style="' . $this->escapeHtml($width['style']) . '"';
            }

            $cells[] = sprintf(
                '            <th %s>%s</th>',
                implode(' ', $attributes),
                $this->escapeHtml($label)
            );
        }

        // Actions header: keep an empty sticky header cell for alignment
        $cells[] = '            <th scope="col" class="text-end fastcrud-actions fastcrud-actions-header"></th>';

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
            $columns = array_keys($rows[0]);

            return array_values(array_filter(
                $columns,
                static fn(string $column): bool => strpos($column, '__fastcrud') !== 0
            ));
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
        $normalized = str_replace('__', ' ', $column);
        return ucwords(str_replace('_', ' ', $normalized));
    }

    private function escapeHtml(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

        $widthStyle = '';
        if ($this->config['panel_width'] !== null) {
            $width = $this->escapeHtml($this->config['panel_width']);
            $widthStyle = " style=\"width: {$width};\"";
        }

        return <<<HTML
<div class="offcanvas offcanvas-start" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}"{$widthStyle}>
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="{$labelId}">Edit Record</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <form id="{$formId}" novalidate class="d-flex flex-column h-100">
            <div class="alert alert-danger d-none" id="{$errorId}" role="alert"></div>
            <div class="alert alert-success d-none" id="{$successId}" role="alert">Changes saved successfully.</div>
            <div id="{$fieldsId}" class="flex-grow-1 overflow-auto"></div>
            <div class="d-flex justify-content-end gap-2 mt-auto pt-3 border-top sticky-bottom">
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

        $widthStyle = '';
        if ($this->config['panel_width'] !== null) {
            $width = $this->escapeHtml($this->config['panel_width']);
            $widthStyle = " style=\"width: {$width};\"";
        }

        return <<<HTML
<div class="offcanvas offcanvas-start" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}"{$widthStyle}>
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
        $fieldsId    = $this->escapeHtml($id . '-edit-fields');
        $switchColor = $this->resolveAccentColor(CrudConfig::$bools_in_grid_color ?? 'primary');

        return <<<HTML
<style>
#{$containerId} table {
    position: relative;
}

#{$containerId} table thead th.fastcrud-sortable {
    cursor: pointer;
    user-select: none;
}
#{$containerId} table thead th.fastcrud-sortable .fastcrud-sort-indicator {
    opacity: 0.7;
    margin-left: 0.25rem;
    font-size: 0.9em;
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

#{$containerId} table tbody td.fastcrud-actions-cell .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

#{$containerId} .fastcrud-icon {
    width: 1rem;
    height: 1rem;
}

/* Align boolean switches neatly inside cells */
#{$containerId} table tbody td .fastcrud-bool-cell {
    display: flex;
    align-items: center;
    justify-content: center;
}
#{$containerId} table tbody td .fastcrud-bool-cell .form-switch {
    padding-left: 0; /* prevent negative offset calculations */
}
#{$containerId} table tbody td .fastcrud-bool-cell .form-check-input {
    margin-left: 0; /* keep switch fully inside the cell */
    accent-color: {$switchColor};
}
#{$containerId} table tbody td .fastcrud-bool-cell .form-check-input:checked {
    background-color: {$switchColor};
    border-color: {$switchColor};
}

</style>
HTML;
    }

    private function resolveAccentColor(string $color): string
    {
        $c = trim($color);
        if ($c === '') {
            return 'var(--bs-primary)';
        }
        $lower = strtolower($c);
        // If it looks like a CSS variable or color function or hex, return as-is
        if (str_starts_with($lower, 'var(')
            || str_starts_with($lower, 'rgb(')
            || str_starts_with($lower, 'rgba(')
            || str_starts_with($lower, 'hsl(')
            || str_starts_with($lower, 'hsla(')
            || str_starts_with($lower, '#')) {
            return $c;
        }

        // Map Bootstrap theme keys to CSS vars
        $keys = ['primary','secondary','success','danger','warning','info','light','dark'];
        if (in_array($lower, $keys, true)) {
            return 'var(--bs-' . $lower . ')';
        }

        // Fallback: return raw value
        return $c;
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
            throw new RuntimeException('Failed to execute count query: ' . $exception->getMessage(), 0, $exception);
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
            'meta'       => $this->buildMetaWithSummaries($columns, $searchTerm, $searchColumn),
        ];
    }

    private function buildMetaWithSummaries(array $columns, ?string $searchTerm, ?string $searchColumn): array
    {
        $meta = $this->buildMeta($columns);
        $meta['summaries'] = $this->buildSummaries($searchTerm, $searchColumn);

        return $meta;
    }

    private function buildColumnLookup(array $columns): array
    {
        $lookup = [];

        $register = function ($candidate) use (&$lookup): void {
            if (!is_string($candidate)) {
                return;
            }

            $normalized = $this->normalizeColumnReference($candidate);
            if ($normalized === '') {
                return;
            }

            $lookup[$normalized] = true;
        };

        foreach ($columns as $column) {
            $register($column);
        }

        foreach ($this->getBaseTableColumns() as $baseColumn) {
            $register($baseColumn);
        }

        if (isset($this->config['form']['all_columns']) && is_array($this->config['form']['all_columns'])) {
            foreach ($this->config['form']['all_columns'] as $formColumn) {
                $register($formColumn);
            }
        }

        return $lookup;
    }

    private function buildMeta(array $columns): array
    {
        $columnLookup = $this->buildColumnLookup($columns);

        $filterColumns = static function (array $source) use ($columnLookup): array {
            $filtered = [];
            foreach ($source as $column => $value) {
                if (isset($columnLookup[$column])) {
                    $filtered[$column] = $value;
                }
            }
            return $filtered;
        };

        $tableMeta = $this->config['table_meta'];
        $tableName = isset($tableMeta['name']) && is_string($tableMeta['name']) && $tableMeta['name'] !== ''
            ? $tableMeta['name']
            : $this->makeTitle($this->table);

        return [
            'table' => [
                'key'       => $this->table,
                'name'      => $tableName,
                'tooltip'   => $tableMeta['tooltip'] ?? null,
                'icon'      => $tableMeta['icon'] ?? null,
                'duplicate' => isset($tableMeta['duplicate']) ? (bool) $tableMeta['duplicate'] : false,
            ],
            'primary_key'    => $this->getPrimaryKeyColumn(),
            'columns'        => $columns,
            'labels'         => $filterColumns($this->config['column_labels']),
            'column_classes' => $filterColumns($this->config['column_classes']),
            'column_widths'  => $filterColumns($this->config['column_widths']),
            'limit_options'  => $this->config['limit_options'],
            'default_limit'  => $this->config['limit_default'] ?? $this->perPage,
            'search'         => [
                'columns'   => $this->config['search_columns'],
                'default'   => $this->config['search_default'],
                'available' => array_keys($this->getWhereColumnsMapForAllSearch()),
            ],
            'order_by'       => array_map(
                static fn(array $order): array => [
                    'field'     => $order['field'],
                    'direction' => $order['direction'],
                ],
                $this->config['order_by']
            ),
            'sort_disabled'  => array_values(array_filter(
                $this->config['sort_disabled'],
                static function ($col) use ($columnLookup): bool {
                    return is_string($col) && isset($columnLookup[$col]);
                }
            )),
            'form' => $this->buildFormMeta($columns),
        ];
    }

    private function buildFormMeta(array $columns): array
    {
        $columnLookup = $this->buildColumnLookup($columns);

        $layouts = [];
        if (isset($this->config['form']['layouts']) && is_array($this->config['form']['layouts'])) {
            foreach ($this->config['form']['layouts'] as $mode => $entries) {
                if (!is_array($entries)) {
                    continue;
                }

                $normalizedEntries = [];
                foreach ($entries as $entry) {
                    if (!is_array($entry) || !isset($entry['fields'])) {
                        continue;
                    }

                    $fields = [];
                    if (is_array($entry['fields'])) {
                        foreach ($entry['fields'] as $field) {
                            if (is_string($field) && isset($columnLookup[$field])) {
                                $fields[] = $field;
                            }
                        }
                    }

                    if ($fields === []) {
                        continue;
                    }

                    $normalizedEntries[] = [
                        'fields'  => $fields,
                        'reverse' => !empty($entry['reverse']),
                        'tab'     => isset($entry['tab']) && is_string($entry['tab']) && $entry['tab'] !== ''
                            ? $entry['tab']
                            : null,
                    ];
                }

                if ($normalizedEntries !== []) {
                    $layouts[$mode] = $normalizedEntries;
                }
            }
        }

        $defaultTabs = [];
        if (isset($this->config['form']['default_tabs']) && is_array($this->config['form']['default_tabs'])) {
            foreach ($this->config['form']['default_tabs'] as $mode => $tab) {
                if (!is_string($mode) || !is_string($tab)) {
                    continue;
                }
                $tabName = trim($tab);
                if ($tabName === '') {
                    continue;
                }
                $defaultTabs[$mode] = $tabName;
            }
        }

        $behaviours = [
            'change_type' => [],
            'pass_var' => [],
            'pass_default' => [],
            'readonly' => [],
            'disabled' => [],
            'validation_required' => [],
            'validation_pattern' => [],
            'unique' => [],
        ];

        if (isset($this->config['form']['behaviours']) && is_array($this->config['form']['behaviours'])) {
            $sourceBehaviours = $this->config['form']['behaviours'];

            if (isset($sourceBehaviours['change_type']) && is_array($sourceBehaviours['change_type'])) {
                foreach ($sourceBehaviours['change_type'] as $field => $definition) {
                    if (!is_string($field) || !isset($columnLookup[$field]) || !is_array($definition)) {
                        continue;
                    }

                    $type = isset($definition['type']) ? strtolower((string) $definition['type']) : '';
                    if ($type === '') {
                        continue;
                    }

                    $behaviours['change_type'][$field] = [
                        'type'    => $type,
                        'default' => $definition['default'] ?? '',
                        'params'  => isset($definition['params']) && is_array($definition['params']) ? $definition['params'] : [],
                    ];
                }
            }

            $modeAwareKeys = ['pass_var', 'pass_default', 'readonly', 'disabled', 'validation_required', 'validation_pattern', 'unique'];
            foreach ($modeAwareKeys as $key) {
                if (!isset($sourceBehaviours[$key]) || !is_array($sourceBehaviours[$key])) {
                    continue;
                }

                foreach ($sourceBehaviours[$key] as $field => $definition) {
                    if (!is_string($field) || !isset($columnLookup[$field]) || !is_array($definition)) {
                        continue;
                    }

                    $behaviours[$key][$field] = $definition;
                }
            }
        }

        $inferredChangeTypes = $this->inferDefaultChangeTypes(array_keys($columnLookup));
        foreach ($inferredChangeTypes as $field => $definition) {
            if (!isset($behaviours['change_type'][$field])) {
                $behaviours['change_type'][$field] = $definition;
            }
        }

        $fieldLabels = [];
        if (isset($this->config['field_labels']) && is_array($this->config['field_labels'])) {
            foreach ($this->config['field_labels'] as $field => $label) {
                if (!is_string($field) || !isset($columnLookup[$field])) {
                    continue;
                }

                if (!is_string($label)) {
                    continue;
                }

                $trimmed = trim($label);
                if ($trimmed === '') {
                    continue;
                }

                $fieldLabels[$field] = $trimmed;
            }
        }

        return [
            'layouts'      => $layouts,
            'default_tabs' => $defaultTabs,
            'behaviours'   => $behaviours,
            'labels'       => $fieldLabels,
            'all_columns'  => $this->getBaseTableColumns(),
        ];
    }

    private function buildSummaries(?string $searchTerm, ?string $searchColumn): array
    {
        if ($this->config['column_summaries'] === []) {
            return [];
        }

        $parameters = [];
        $whereClause = $this->buildWhereClause($parameters, $searchTerm, $searchColumn);
        $fromClause = $this->buildFromClause();
        $joins = $this->buildJoinClauses();

        $baseSql = sprintf('FROM %s', $fromClause);
        if ($joins !== '') {
            $baseSql .= ' ' . $joins;
        }
        if ($whereClause !== '') {
            $baseSql .= ' WHERE ' . $whereClause;
        }

        $summaries = [];

        foreach ($this->config['column_summaries'] as $entry) {
            if (!is_array($entry) || !isset($entry['column'], $entry['type'])) {
                continue;
            }

            $column = (string) $entry['column'];
            $type = strtolower((string) $entry['type']);

            if (!in_array($type, self::SUPPORTED_SUMMARY_TYPES, true)) {
                continue;
            }

            $label = isset($entry['label']) && is_string($entry['label']) && $entry['label'] !== ''
                ? $entry['label']
                : $this->resolveColumnLabel($column);

            $precision = isset($entry['precision']) && is_numeric($entry['precision'])
                ? (int) $entry['precision']
                : null;

            $columnExpression = $this->denormalizeColumnReference($column);
            if (strpos($columnExpression, '.') === false && strpos($columnExpression, '(') === false) {
                $columnExpression = 'main.' . $columnExpression;
            }

            $aggregateSql = sprintf(
                'SELECT %s(%s) AS aggregate %s',
                strtoupper($type),
                $columnExpression,
                $baseSql
            );

            $statement = $this->connection->prepare($aggregateSql);
            if ($statement === false) {
                continue;
            }

            try {
                $statement->execute($parameters);
            } catch (PDOException) {
                continue;
            }

            $value = $statement->fetchColumn();
            if ($value === false) {
                $value = null;
            }

            if ($precision !== null && $value !== null && is_numeric($value)) {
                $value = number_format((float) $value, $precision, '.', '');
            }

            $summaries[] = [
                'column' => $column,
                'type'   => $type,
                'label'  => $label,
                'value'  => $value,
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClientConfigPayload(): array
    {
        $this->ensureFormLayoutBuckets();
        $this->ensureFormBehaviourBuckets();
        $this->ensureDefaultTabBuckets();

        $allColumns = $this->getBaseTableColumns();
        $formConfig = $this->config['form'];

        if (isset($formConfig['layouts']) && is_array($formConfig['layouts'])) {
            foreach ($formConfig['layouts'] as $entries) {
                if (!is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if (!is_array($entry) || !isset($entry['fields']) || !is_array($entry['fields'])) {
                        continue;
                    }

                    foreach ($entry['fields'] as $fieldName) {
                        if (!is_string($fieldName) || $fieldName === '') {
                            continue;
                        }

                        $normalized = $this->normalizeColumnReference($fieldName);
                        if ($normalized === '' || in_array($normalized, $allColumns, true)) {
                            continue;
                        }

                        $allColumns[] = $normalized;
                    }
                }
            }
        }

        $formConfig['all_columns'] = $allColumns;
        $this->config['form']['all_columns'] = $allColumns;

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
            'column_labels'   => $this->config['column_labels'],
            'column_patterns' => $this->config['column_patterns'],
            'column_callbacks' => $this->config['column_callbacks'],
            'column_classes'  => $this->config['column_classes'],
            'column_widths'   => $this->config['column_widths'],
            'column_cuts'     => $this->config['column_cuts'],
            'column_highlights' => $this->config['column_highlights'],
            'row_highlights'    => $this->config['row_highlights'],
            'table_meta'        => $this->config['table_meta'],
            'column_summaries'  => $this->config['column_summaries'],
            'field_labels'      => $this->config['field_labels'],
            'primary_key'       => $this->primaryKeyColumn,
            'form'              => $formConfig,
            'rich_editor'       => [
                'upload_path' => CrudConfig::getUploadPath(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyClientConfig(array $payload): void
    {
        if (isset($payload['primary_key']) && is_string($payload['primary_key']) && trim($payload['primary_key']) !== '') {
            $this->primary_key($payload['primary_key']);
        }

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

        $arrayKeys = [
            'where',
            'order_by',
            'sort_disabled',
            'no_quotes',
            'joins',
            'relations',
            'subselects',
            'column_labels',
            'column_patterns',
            'column_callbacks',
            'column_classes',
            'column_widths',
            'column_cuts',
            'column_highlights',
            'row_highlights',
            'column_summaries',
        ];
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


        if (isset($payload['table_meta']) && is_array($payload['table_meta'])) {
            $meta = $payload['table_meta'];
            $this->config['table_meta'] = [
                'name'    => isset($meta['name']) && is_string($meta['name']) ? $meta['name'] : null,
                'tooltip' => isset($meta['tooltip']) && is_string($meta['tooltip']) ? $meta['tooltip'] : null,
                'icon'    => isset($meta['icon']) && is_string($meta['icon']) ? $meta['icon'] : null,
                'duplicate' => isset($meta['duplicate']) ? (bool) $meta['duplicate'] : false,
            ];
        }

        if (isset($payload['column_callbacks']) && is_array($payload['column_callbacks'])) {
            $normalized = [];
            foreach ($payload['column_callbacks'] as $column => $entry) {
                if (!is_string($column)) {
                    continue;
                }

                $normalizedColumn = $this->normalizeColumnReference($column);
                if ($normalizedColumn === '') {
                    continue;
                }

                $callable = null;
                if (is_string($entry)) {
                    $callable = $entry;
                } elseif (is_array($entry) && isset($entry['callable'])) {
                    $callable = (string) $entry['callable'];
                }

                if ($callable === null || $callable === '' || !is_callable($callable)) {
                    continue;
                }

                $normalized[$normalizedColumn] = $callable;
            }

            if ($normalized !== []) {
                $this->config['column_callbacks'] = $normalized;
            }
        }

        if (isset($payload['column_labels']) && is_array($payload['column_labels'])) {
            $labels = [];
            foreach ($payload['column_labels'] as $column => $label) {
                if (!is_string($column) || !is_string($label)) {
                    continue;
                }
                $normalizedColumn = $this->normalizeColumnReference($column);
                if ($normalizedColumn === '') {
                    continue;
                }
                $trimmed = trim($label);
                if ($trimmed === '') {
                    continue;
                }
                $labels[$normalizedColumn] = $trimmed;
            }
            $this->config['column_labels'] = $labels;
        }

        if (isset($payload['field_labels']) && is_array($payload['field_labels'])) {
            $fieldLabels = [];
            foreach ($payload['field_labels'] as $field => $label) {
                if (!is_string($field) || !is_string($label)) {
                    continue;
                }

                $normalizedField = $this->normalizeColumnReference($field);
                if ($normalizedField === '') {
                    continue;
                }

                $trimmed = trim($label);
                if ($trimmed === '') {
                    continue;
                }

                $fieldLabels[$normalizedField] = $trimmed;
            }

            $this->config['field_labels'] = $fieldLabels;
        }

        if (isset($payload['column_classes']) && is_array($payload['column_classes'])) {
            $classes = [];
            foreach ($payload['column_classes'] as $column => $value) {
                if (!is_string($column) || !is_string($value)) {
                    continue;
                }
                $classes[$this->normalizeColumnReference($column)] = $this->normalizeCssClassList($value);
            }
            $this->config['column_classes'] = $classes;
        }

        if (isset($payload['column_widths']) && is_array($payload['column_widths'])) {
            $widths = [];
            foreach ($payload['column_widths'] as $column => $width) {
                if (!is_string($column) || !is_string($width)) {
                    continue;
                }
                $widths[$this->normalizeColumnReference($column)] = trim($width);
            }
            $this->config['column_widths'] = $widths;
        }

        if (isset($payload['column_patterns']) && is_array($payload['column_patterns'])) {
            $patterns = [];
            foreach ($payload['column_patterns'] as $column => $patternEntry) {
                if (!is_string($column)) {
                    continue;
                }

                $normalizedColumn = $this->normalizeColumnReference($column);
                if ($normalizedColumn === '') {
                    continue;
                }

                if (is_array($patternEntry)) {
                    $patternEntry = isset($patternEntry['template']) ? (string) $patternEntry['template'] : '';
                }

                if (!is_string($patternEntry)) {
                    continue;
                }

                $template = trim($patternEntry);
                if ($template === '') {
                    continue;
                }

                $patterns[$normalizedColumn] = $template;
            }
            $this->config['column_patterns'] = $patterns;
        }

        if (isset($payload['column_cuts']) && is_array($payload['column_cuts'])) {
            $cuts = [];
            foreach ($payload['column_cuts'] as $column => $cut) {
                if (!is_string($column) || !is_array($cut) || !isset($cut['length'])) {
                    continue;
                }
                $cuts[$this->normalizeColumnReference($column)] = [
                    'length' => (int) $cut['length'],
                    'suffix' => isset($cut['suffix']) ? (string) $cut['suffix'] : '…',
                ];
            }
            $this->config['column_cuts'] = $cuts;
        }


        if (isset($payload['column_highlights']) && is_array($payload['column_highlights'])) {
            $highlights = [];
            foreach ($payload['column_highlights'] as $column => $entries) {
                if (!is_string($column) || !is_array($entries)) {
                    continue;
                }
                $normalizedColumn = $this->normalizeColumnReference($column);
                $normalizedEntries = [];
                foreach ($entries as $entry) {
                    if (!is_array($entry) || !isset($entry['condition'], $entry['class'])) {
                        continue;
                    }
                    $condition = $entry['condition'];
                    $class = (string) $entry['class'];
                    if (!is_array($condition) || $class === '') {
                        continue;
                    }
                    $normalizedEntries[] = [
                        'condition' => $condition,
                        'class'     => $class,
                    ];
                }
                if ($normalizedEntries !== []) {
                    $highlights[$normalizedColumn] = $normalizedEntries;
                }
            }
            $this->config['column_highlights'] = $highlights;
        }

        if (isset($payload['row_highlights']) && is_array($payload['row_highlights'])) {
            $rowHighlights = [];
            foreach ($payload['row_highlights'] as $entry) {
                if (!is_array($entry) || !isset($entry['condition'], $entry['class'])) {
                    continue;
                }
                $condition = $entry['condition'];
                $class = (string) $entry['class'];
                if (!is_array($condition) || $class === '') {
                    continue;
                }
                $rowHighlights[] = [
                    'condition' => $condition,
                    'class'     => $class,
                ];
            }
            $this->config['row_highlights'] = $rowHighlights;
        }

        if (isset($payload['column_summaries']) && is_array($payload['column_summaries'])) {
            $summaries = [];
            foreach ($payload['column_summaries'] as $entry) {
                if (!is_array($entry) || !isset($entry['column'], $entry['type'])) {
                    continue;
                }
                $column = $this->normalizeColumnReference((string) $entry['column']);
                $type = strtolower((string) $entry['type']);
                if (!in_array($type, self::SUPPORTED_SUMMARY_TYPES, true)) {
                    continue;
                }
                $summaries[] = [
                    'column'    => $column,
                    'type'      => $type,
                    'label'     => isset($entry['label']) ? (string) $entry['label'] : null,
                    'precision' => isset($entry['precision']) && is_numeric($entry['precision'])
                        ? (int) $entry['precision']
                        : null,
                ];
            }
            $this->config['column_summaries'] = $summaries;
        }

        if (isset($payload['form']) && is_array($payload['form'])) {
            $this->mergeFormConfig($payload['form']);
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

    private function getPrimaryKeyColumn(): string
    {
        return $this->primaryKeyColumn;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachPrimaryKeyMetadata(array $rows): array
    {
        $primaryKey = $this->getPrimaryKeyColumn();

        foreach ($rows as $index => $row) {
            $rows[$index]['__fastcrud_primary_key'] = $primaryKey;
            $rows[$index]['__fastcrud_primary_value'] = $row[$primaryKey] ?? null;
        }

        return $rows;
    }

    /**
     * Update a record and return the fresh row data.
     *
     * @param string $primaryKeyColumn Column name for the primary key
     * @param mixed $primaryKeyValue Value of the key used to locate the record
     * @param array<string, mixed> $fields Column => value map to update
     * @return array<string, mixed>|null
     */
    public function updateRecord(string $primaryKeyColumn, mixed $primaryKeyValue, array $fields, string $mode = 'edit'): ?array
    {
        $primaryKeyColumn = trim($primaryKeyColumn);
        if ($primaryKeyColumn === '') {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        // Always validate against the base table schema, not the current visible columns
        $columns = $this->getTableColumnsFor($this->table);
        if (!in_array($primaryKeyColumn, $columns, true)) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['create', 'edit', 'view'], true)) {
            $mode = 'edit';
        }

        $currentRow = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
        if ($currentRow === null) {
            throw new InvalidArgumentException('Record not found for update.');
        }

        $readonly = $this->gatherBehaviourForMode('readonly', $mode);
        $disabled = $this->gatherBehaviourForMode('disabled', $mode);

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

            if (isset($readonly[$column]) || isset($disabled[$column])) {
                continue;
            }

            $filtered[$column] = $value;
        }

        $context = array_merge($currentRow, $fields, $filtered);

        $passDefaults = $this->gatherBehaviourForMode('pass_default', $mode);
        foreach ($passDefaults as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $needsDefault = !array_key_exists($column, $filtered)
                || $filtered[$column] === null
                || $filtered[$column] === '';

            if ($needsDefault) {
                $filtered[$column] = $this->renderTemplateValue($value, $context);
            }
        }

        $passVars = $this->gatherBehaviourForMode('pass_var', $mode);
        foreach ($passVars as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $filtered[$column] = $this->renderTemplateValue($value, $context);
        }

        if ($filtered === []) {
            return $currentRow;
        }

        $context = array_merge($currentRow, $filtered);

        $errors = [];

        $required = $this->gatherBehaviourForMode('validation_required', $mode);
        foreach ($required as $column => $minLength) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $value = $filtered[$column] ?? ($context[$column] ?? null);
            $length = 0;
            if ($value !== null) {
                if (is_string($value)) {
                    $normalized = trim($value);
                    $length = function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized);
                } elseif (is_numeric($value)) {
                    $stringValue = (string) $value;
                    $length = function_exists('mb_strlen') ? mb_strlen($stringValue) : strlen($stringValue);
                }
            }

            if ($length < (int) $minLength) {
                $errors[$column] = 'This field is required.';
            }
        }

        $patterns = $this->gatherBehaviourForMode('validation_pattern', $mode);
        foreach ($patterns as $column => $pattern) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            if (!array_key_exists($column, $filtered)) {
                continue;
            }

            $value = $filtered[$column];
            if ($value === null || $value === '') {
                continue;
            }

            $regex = $this->compileValidationPattern((string) $pattern);
            if ($regex === null) {
                continue;
            }

            if (@preg_match($regex, (string) $value) !== 1) {
                $errors[$column] = 'Value does not match the expected format.';
            }
        }

        $uniqueRules = $this->gatherBehaviourForMode('unique', $mode);
        foreach ($uniqueRules as $column => $flag) {
            if (!$flag || !in_array($column, $columns, true)) {
                continue;
            }

            if (!array_key_exists($column, $filtered)) {
                continue;
            }

            $value = $filtered[$column];
            if ($value === null || $value === '') {
                continue;
            }

            $sql = sprintf(
                'SELECT COUNT(*) FROM %s WHERE %s = :value AND %s <> :pk',
                $this->table,
                $column,
                $primaryKeyColumn
            );

            $statement = $this->connection->prepare($sql);
            if ($statement === false) {
                continue;
            }

            try {
                $statement->execute([
                    ':value' => $value,
                    ':pk'    => $primaryKeyValue,
                ]);
            } catch (PDOException) {
                continue;
            }

            $count = (int) $statement->fetchColumn();
            if ($count > 0) {
                $errors[$column] = 'This value must be unique.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validation failed.', $errors);
        }

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

        // Validate against base table columns (not just visible columns)
        // to support cases where the primary key isn't displayed in the grid.
        $columns = $this->getTableColumnsFor($this->table);
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
     * Duplicate a record by copying its fields into a new row.
     * Returns the newly created row, or null on failure.
     *
     * @param string $primaryKeyColumn
     * @param mixed $primaryKeyValue
     * @return array<string, mixed>|null
     */
    public function duplicateRecord(string $primaryKeyColumn, mixed $primaryKeyValue): ?array
    {
        // 1) Validate PK column
        $primaryKeyColumn = trim($primaryKeyColumn);
        if ($primaryKeyColumn === '') {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        $columns = $this->getTableColumnsFor($this->table);
        if (!in_array($primaryKeyColumn, $columns, true)) {
            throw new InvalidArgumentException(sprintf('Unknown primary key column "%s".', $primaryKeyColumn));
        }

        // 2) Load source row
        $source = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
        if ($source === null) {
            throw new InvalidArgumentException('Record not found for duplication.');
        }

        // 3) Copy all base-table columns except the PK (exactly as requested)
        $fields = [];
        foreach ($columns as $column) {
            if ($column === $primaryKeyColumn) {
                continue; // remove id
            }
            if (array_key_exists($column, $source)) {
                $fields[$column] = $source[$column];
            }
        }

        if ($fields === []) {
            throw new RuntimeException('Nothing to duplicate.');
        }

        // 4) Insert new row
        $placeholders = [];
        $parameters = [];
        foreach ($fields as $column => $value) {
            $ph = ':col_' . $column;
            $placeholders[] = $ph;
            $parameters[$ph] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_keys($fields)),
            implode(', ', $placeholders)
        );

        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare insert statement.');
        }

        try {
            $statement->execute($parameters);
        } catch (PDOException $exception) {
            if ($this->isDuplicateKeyException($exception)) {
                // Try to resolve by adjusting unique columns and retry once
                $adjusted = $this->resolveDuplicateByAdjustingUniqueColumns($fields);
                if ($adjusted !== null) {
                    $fields = $adjusted;
                    // rebuild placeholders and parameters
                    $placeholders = [];
                    $parameters = [];
                    foreach ($fields as $column => $value) {
                        $ph = ':col_' . $column;
                        $placeholders[] = $ph;
                        $parameters[$ph] = $value;
                    }
                    $sql = sprintf(
                        'INSERT INTO %s (%s) VALUES (%s)',
                        $this->table,
                        implode(', ', array_keys($fields)),
                        implode(', ', $placeholders)
                    );
                    $statement = $this->connection->prepare($sql);
                    if ($statement === false) {
                        throw new RuntimeException('Failed to prepare retry insert statement.');
                    }
                    try {
                        $statement->execute($parameters);
                    } catch (PDOException $retryException) {
                        $message = trim($retryException->getMessage() ?: '');
                        if ($message !== '') {
                            throw new RuntimeException('Failed to duplicate record: ' . $message, 0, $retryException);
                        }
                        throw new RuntimeException('Failed to duplicate record.', 0, $retryException);
                    }
                } else {
                    // Could not auto-resolve
                    $message = trim($exception->getMessage() ?: '');
                    if ($message !== '') {
                        throw new RuntimeException('Failed to duplicate record: ' . $message, 0, $exception);
                    }
                    throw new RuntimeException('Failed to duplicate record.', 0, $exception);
                }
            } else {
                $message = trim($exception->getMessage() ?: '');
                if ($message !== '') {
                    throw new RuntimeException('Failed to duplicate record: ' . $message, 0, $exception);
                }
                throw new RuntimeException('Failed to duplicate record.', 0, $exception);
            }
        }

        // 5) Return new row (try lastInsertId first)
        try {
            $newPk = $this->connection->lastInsertId();
            if (is_string($newPk) && $newPk !== '' && $newPk !== '0') {
                return $this->findRowByPrimaryKey($primaryKeyColumn, $newPk);
            }
        } catch (PDOException) {
            // ignore, fallback below
        }

        // Fallback: last by PK desc
        try {
            $sql = sprintf('SELECT * FROM %s ORDER BY %s DESC LIMIT 1', $this->table, $primaryKeyColumn);
            $fallbackStmt = $this->connection->query($sql);
            if ($fallbackStmt !== false) {
                $row = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                return is_array($row) ? $row : null;
            }
        } catch (PDOException) {
            // ignore
        }

        return null;
    }

    private function isDuplicateKeyException(PDOException $exception): bool
    {
        // MySQL: SQLSTATE 23000, error code 1062; generic message contains 'Duplicate entry'
        $code = $exception->getCode();
        $message = strtolower((string) $exception->getMessage());
        $info0 = is_array($exception->errorInfo ?? null) ? ($exception->errorInfo[0] ?? null) : null;
        $info1 = is_array($exception->errorInfo ?? null) ? ($exception->errorInfo[1] ?? null) : null;
        if ((string) $info0 === '23000' && (int) $info1 === 1062) {
            return true;
        }
        if ((string) $code === '23000' && str_contains($message, 'duplicate')) {
            return true;
        }
        return false;
    }

    /**
     * Attempt to adjust values for unique single-column indexes by appending a copy suffix.
     * Returns updated fields or null if no adjustment is possible.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>|null
     */
    private function resolveDuplicateByAdjustingUniqueColumns(array $fields): ?array
    {
        $driver = null;
        try {
            $driver = strtolower((string) $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (PDOException) {
            $driver = null;
        }

        if ($driver !== 'mysql') {
            return null; // only support MySQL auto-resolution for now
        }

        $uniqueColumns = $this->getMysqlUniqueSingleColumns($this->table);
        if ($uniqueColumns === []) {
            return null;
        }

        $updated = $fields;
        $changed = false;

        foreach ($uniqueColumns as $column) {
            if (!array_key_exists($column, $updated)) {
                continue;
            }
            $value = $updated[$column];
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            // Find an unused variant by appending (copy), (copy 2), ...
            $base = $this->stripCopySuffix($value);
            $candidate = $base . ' (copy)';
            $attempt = 2;
            while ($this->valueExistsForColumn($column, $candidate) && $attempt < 100) {
                $candidate = $base . ' (copy ' . $attempt . ')';
                $attempt++;
            }
            if (!$this->valueExistsForColumn($column, $candidate)) {
                $updated[$column] = $candidate;
                $changed = true;
            }
        }

        return $changed ? $updated : null;
    }

    private function stripCopySuffix(string $value): string
    {
        $trimmed = rtrim($value);
        // Remove trailing " (copy)" or " (copy N)"
        $trimmed = (string) preg_replace('/\s*\(copy(?:\s+\d+)?\)$/i', '', $trimmed);
        return $trimmed;
    }

    private function valueExistsForColumn(string $column, string $value): bool
    {
        $sql = sprintf('SELECT COUNT(*) FROM %s WHERE %s = :v', $this->table, $column);
        $stmt = $this->connection->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        try {
            $stmt->execute([':v' => $value]);
            $count = (int) $stmt->fetchColumn();
            return $count > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function getMysqlUniqueSingleColumns(string $table): array
    {
        $columns = [];
        $sql = sprintf('SHOW INDEX FROM `%s`', $table);
        try {
            $stmt = $this->connection->query($sql);
        } catch (PDOException) {
            return $columns;
        }
        if ($stmt === false) {
            return $columns;
        }
        $indexes = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            // Rows: Table, Non_unique(0=unique), Key_name, Seq_in_index, Column_name, ...
            $nonUnique = isset($row['Non_unique']) ? (int) $row['Non_unique'] : 1;
            $keyName = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            $seq = isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : 0;
            $col = isset($row['Column_name']) ? (string) $row['Column_name'] : '';
            if ($nonUnique === 0 && $keyName !== 'PRIMARY' && $col !== '') {
                if (!isset($indexes[$keyName])) {
                    $indexes[$keyName] = [];
                }
                $indexes[$keyName][$seq] = $col;
            }
        }
        foreach ($indexes as $keyName => $parts) {
            ksort($parts, SORT_NUMERIC);
            $cols = array_values($parts);
            if (count($cols) === 1) {
                $col = $cols[0];
                if ($col !== $this->getPrimaryKeyColumn() && !in_array($col, $columns, true)) {
                    $columns[] = $col;
                }
            }
        }
        return $columns;
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
     * Public accessor to fetch a single row by its primary key.
     *
     * @return array<string, mixed>|null
     */
    public function getRecord(string $primaryKeyColumn, mixed $primaryKeyValue): ?array
    {
        $primaryKeyColumn = trim($primaryKeyColumn);
        if ($primaryKeyColumn === '') {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        // Validate against base table columns (not just visible columns)
        $columns = $this->getTableColumnsFor($this->table);
        if (!in_array($primaryKeyColumn, $columns, true)) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        return $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
    }

    /**
     * Generate jQuery AJAX script for loading table data with pagination.
     */
    private function generateAjaxScript(): string
    {
        $id = $this->escapeHtml($this->id);
        $editRowClass = trim(CrudConfig::$edit_row_highlight_class ?? '');
        if ($editRowClass === '') {
            $editRowClass = 'table-warning';
        }
        $editRowClass = $this->escapeHtml($editRowClass);

        return <<<SCRIPT
<script>
(function($) {
    $(document).ready(function() {
        var tableId = '$id';
        var editHighlightClass = '$editRowClass';
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
        var richEditorConfig = clientConfig.rich_editor || {};
        var paginationContainer = $('#' + tableId + '-pagination');
        var currentPage = 1;
        var columnsCache = [];
        var baseColumns = [];
        var primaryKeyColumn = null;
        var metaConfig = {};
        var metaInitialized = false;
        var perPageOptions = [];
        var searchConfig = { columns: [], default: null };
        var currentSearchTerm = '';
        var currentSearchColumn = null;
        var columnLabels = {};
        var columnClasses = {};
        var columnWidths = {};
        var orderBy = [];
        var duplicateEnabled = false;
        var sortDisabled = {};
        var formConfig = {
            layouts: {},
            default_tabs: {},
            behaviours: {},
            labels: {},
            all_columns: []
        };
        var currentFieldErrors = {};
        // Cache for on-demand row fetches (keyed by tableId + '::' + pkCol + '::' + pkVal)
        var rowCache = {};

        var toolbar = $('#' + tableId + '-toolbar');
        var rangeDisplay = $('#' + tableId + '-range');
        var metaContainer = $('#' + tableId + '-meta');
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
        if (editOffcanvasElement.length) {
            // Clear highlight as soon as the panel starts closing (no wait for animation)
            editOffcanvasElement.on('hide.bs.offcanvas', function() {
                try {
                    table.find('tbody tr.fastcrud-editing').each(function() {
                        var trEl = $(this);
                        var had = trEl.data('fastcrudHadClass');
                        if (had !== 1 && had !== '1') { trEl.removeClass(editHighlightClass); }
                        trEl.removeClass('fastcrud-editing').removeData('fastcrudHadClass');
                    });
                } catch (e) {}
            });
            // Cleanup heavy widgets after the panel is fully hidden
            editOffcanvasElement.on('hidden.bs.offcanvas', function() {
                destroyRichEditors(editFieldsContainer);
                destroyFilePonds(editFieldsContainer);
            });
        }

        var viewOffcanvasElement = $('#' + tableId + '-view-panel');
        var viewContentContainer = $('#' + tableId + '-view-content');
        var viewEmptyNotice = $('#' + tableId + '-view-empty');
        var viewHeading = $('#' + tableId + '-view-label');
        var viewOffcanvasInstance = null;
        var summaryFooter = $('#' + tableId + '-summary');

        // FilePond state and asset loader for image fields
        var filePondState = window.FastCrudFilePond || {};
        if (!filePondState.coreScriptUrl) {
            filePondState.coreScriptUrl = 'https://unpkg.com/filepond/dist/filepond.min.js';
        }
        if (!filePondState.coreStyleUrl) {
            filePondState.coreStyleUrl = 'https://unpkg.com/filepond/dist/filepond.min.css';
        }
        if (!filePondState.previewScriptUrl) {
            filePondState.previewScriptUrl = 'https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.js';
        }
        if (!filePondState.previewStyleUrl) {
            filePondState.previewStyleUrl = 'https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css';
        }
        if (!filePondState.posterScriptUrl) {
            filePondState.posterScriptUrl = 'https://unpkg.com/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.js';
        }
        if (!filePondState.posterStyleUrl) {
            filePondState.posterStyleUrl = 'https://unpkg.com/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.css';
        }
        if (typeof filePondState.loaded === 'undefined') {
            filePondState.loaded = (typeof window.FilePond !== 'undefined');
        }
        if (typeof filePondState.loading === 'undefined') {
            filePondState.loading = false;
        }
        if (!Array.isArray(filePondState.queue)) {
            filePondState.queue = [];
        }
        window.FastCrudFilePond = filePondState;

        function getUploadPublicBase() {
            var base = String(richEditorConfig.upload_path || '/public/uploads');
            if (!/^https?:\/\//i.test(base) && base.charAt(0) !== '/') {
                base = '/' + base;
            }
            return base;
        }

        function joinPublicUrl(base, name) {
            if (!name) { return String(base || ''); }
            var b = String(base || '');
            if (b && b.charAt(b.length - 1) !== '/') { b += '/'; }
            var seg = String(name).replace(/^\/+/, '');
            return b + seg;
        }

        function toPublicUrl(value) {
            var v = String(value || '');
            if (!v) { return ''; }
            if (/^https?:\/\//i.test(v) || v.charAt(0) === '/') { return v; }
            return joinPublicUrl(getUploadPublicBase(), v);
        }

        function extractFileName(value) {
            var str = String(value || '').trim();
            if (!str) { return ''; }
            var hashIndex = str.indexOf('#');
            if (hashIndex !== -1) {
                str = str.slice(0, hashIndex);
            }
            var queryIndex = str.indexOf('?');
            if (queryIndex !== -1) {
                str = str.slice(0, queryIndex);
            }
            var parts = str.split('/');
            var segment = parts[parts.length - 1] || '';
            if (!segment) { return ''; }
            var lastSlash = segment.lastIndexOf('/');
            var lastBackslash = segment.lastIndexOf(String.fromCharCode(92));
            var separatorIndex = Math.max(lastSlash, lastBackslash);
            if (separatorIndex !== -1) {
                return segment.slice(separatorIndex + 1) || '';
            }
            return segment;
        }

        function parseImageNameList(value) {
            var result = [];
            if (Array.isArray(value)) {
                value.forEach(function(item) {
                    if (item === null || typeof item === 'undefined') {
                        return;
                    }
                    var name = extractFileName(item);
                    if (name && result.indexOf(name) === -1) {
                        result.push(name);
                    }
                });
                return result;
            }

            var text = String(value || '');
            if (!text.length) {
                return result;
            }

            text.split(',').forEach(function(item) {
                var name = extractFileName(item);
                if (name && result.indexOf(name) === -1) {
                    result.push(name);
                }
            });

            return result;
        }

        function imageNamesToString(list) {
            if (!Array.isArray(list) || !list.length) {
                return '';
            }
            return list.join(',');
        }

        function setImageNamesOnInput(input, list) {
            if (!input || !input.length) {
                return;
            }
            input.val(imageNamesToString(parseImageNameList(list)));
        }

        function addImageNameToInput(input, candidate) {
            if (!input || !input.length) {
                return;
            }
            var name = extractFileName(candidate);
            if (!name) {
                return;
            }
            var current = parseImageNameList(input.val());
            if (current.indexOf(name) === -1) {
                current.push(name);
            }
            input.val(imageNamesToString(current));
        }

        function removeImageNameFromInput(input, candidate) {
            if (!input || !input.length) {
                return;
            }
            var name = extractFileName(candidate);
            if (!name) {
                return;
            }
            var current = parseImageNameList(input.val());
            var filtered = current.filter(function(entry) {
                return entry !== name;
            });
            if (filtered.length !== current.length) {
                input.val(imageNamesToString(filtered));
            }
        }

        function clearImageNameMap(input) {
            if (!input || !input.length) {
                return;
            }
            input.removeData('fastcrudNameMap');
        }

        function ensureImageNameMap(input) {
            if (!input || !input.length) {
                return {};
            }
            var existing = input.data('fastcrudNameMap');
            if (!existing || typeof existing !== 'object') {
                existing = {};
                input.data('fastcrudNameMap', existing);
            } else {
                input.data('fastcrudNameMap', existing);
            }
            return existing;
        }

        function mapImageNameToKey(input, key, name) {
            if (!input || !input.length || !key) {
                return;
            }
            var map = ensureImageNameMap(input);
            if (name) {
                map[key] = name;
            }
            input.data('fastcrudNameMap', map);
        }

        function removeImageNameForKey(input, key) {
            if (!input || !input.length || !key) {
                return;
            }
            var map = input.data('fastcrudNameMap');
            if (map && typeof map === 'object' && Object.prototype.hasOwnProperty.call(map, key)) {
                delete map[key];
                input.data('fastcrudNameMap', map);
            }
        }

        function findImageNameForKey(input, key) {
            if (!input || !input.length || !key) {
                return '';
            }
            var map = input.data('fastcrudNameMap');
            if (map && typeof map === 'object' && Object.prototype.hasOwnProperty.call(map, key)) {
                return map[key];
            }
            return '';
        }

        function appendStylesheetOnce(href, id) {
            if (!href) { return; }
            var markerId = id || ('fastcrud-style-' + Math.random().toString(36).slice(2));
            if (document.getElementById(markerId)) {
                return;
            }
            var link = document.createElement('link');
            link.id = markerId;
            link.rel = 'stylesheet';
            link.href = href;
            document.head.appendChild(link);
        }

        function withFilePondAssets(callback) {
            if (typeof callback !== 'function') {
                return;
            }
            if (typeof window.FilePond !== 'undefined' && typeof window.FilePond.create === 'function' && typeof window.FilePondPluginImagePreview !== 'undefined') {
                filePondState.loaded = true;
                callback();
                return;
            }
            filePondState.queue.push(callback);
            if (filePondState.loading) {
                return;
            }
            filePondState.loading = true;

            // Load styles first
            appendStylesheetOnce(filePondState.coreStyleUrl, 'fastcrud-filepond-core-css');
            appendStylesheetOnce(filePondState.previewStyleUrl, 'fastcrud-filepond-preview-css');
            appendStylesheetOnce(filePondState.posterStyleUrl, 'fastcrud-filepond-poster-css');

            // Ensure poster images are contained (avoid cropping)
            try {
                var containStyleId = 'fastcrud-filepond-contain-css';
                if (!document.getElementById(containStyleId)) {
                    var styleTag = document.createElement('style');
                    styleTag.id = containStyleId;
                    // Keep CSS on a single JS line to avoid syntax errors inside heredoc
                    styleTag.textContent = '.filepond--file-poster img{width:100%;height:100%;object-fit:contain;}';
                    document.head.appendChild(styleTag);
                }
            } catch (e) {}

            // Load FilePond core JS, then plugin JS
            var coreScript = document.createElement('script');
            coreScript.src = filePondState.coreScriptUrl;
            coreScript.referrerPolicy = 'no-referrer';
            coreScript.onload = function() {
                var previewScript = document.createElement('script');
                previewScript.src = filePondState.previewScriptUrl;
                previewScript.referrerPolicy = 'no-referrer';
                previewScript.onload = function() {
                    var posterScript = document.createElement('script');
                    posterScript.src = filePondState.posterScriptUrl;
                    posterScript.referrerPolicy = 'no-referrer';
                    posterScript.onload = function() {
                        try {
                            if (window.FilePond && typeof window.FilePond.registerPlugin === 'function') {
                                if (window.FilePondPluginImagePreview) {
                                    window.FilePond.registerPlugin(window.FilePondPluginImagePreview);
                                }
                                if (window.FilePondPluginFilePoster) {
                                    window.FilePond.registerPlugin(window.FilePondPluginFilePoster);
                                }
                            }
                        } catch (e) {}
                        filePondState.loaded = true;
                        filePondState.loading = false;
                        var queued = filePondState.queue.slice();
                        filePondState.queue.length = 0;
                        queued.forEach(function(fn) {
                            try { fn(); } catch (error) { if (window.console && console.error) console.error(error); }
                        });
                    };
                    posterScript.onerror = function() {
                        filePondState.loading = false;
                        filePondState.queue.length = 0;
                        if (window.console && console.error) console.error('FastCrud: failed to load FilePond file poster script');
                    };
                    document.head.appendChild(posterScript);
                };
                previewScript.onerror = function() {
                    filePondState.loading = false;
                    filePondState.queue.length = 0;
                    if (window.console && console.error) console.error('FastCrud: failed to load FilePond image preview script');
                };
                document.head.appendChild(previewScript);
            };
            coreScript.onerror = function() {
                filePondState.loading = false;
                filePondState.queue.length = 0;
                if (window.console && console.error) console.error('FastCrud: failed to load FilePond core script');
            };
            document.head.appendChild(coreScript);
        }

        function destroyFilePonds(container) {
            if (!container || !container.length) {
                return;
            }
            if (typeof window.FilePond === 'undefined' || typeof window.FilePond.find !== 'function') {
                return;
            }
            try {
                var inputs = container.find('input.fastcrud-filepond').toArray();
                var ponds = window.FilePond.find(inputs);
                (ponds || []).forEach(function(pond) {
                    try { pond.destroy(); } catch (e) {}
                });
            } catch (e) {}
        }

        var richEditorState = window.FastCrudRichEditor || {};
        if (!richEditorState.scriptUrl) {
            richEditorState.scriptUrl = 'https://mzgs.net/tinymce5/tinymce.min.js';
        }
        if (!richEditorState.baseConfig) {
            richEditorState.baseConfig = {
                menubar: false,
                height: 500,
                branding: false,
                paste_data_images: true,
                automatic_uploads: true,
                powerpaste_word_import: 'merge',
                powerpaste_html_import: 'merge',
                powerpaste_allow_local_images: true,
                images_upload_url: window.location.pathname,
                images_upload_base_path: richEditorConfig.upload_path || '/public/uploads',
                images_upload_credentials: true,
                valid_elements: '*[*]',
                images_file_types: 'jpeg,jpg,jpe,jfi,jif,jfif,png,gif,bmp,webp,svg',
                file_picker_types: 'file image media',
                plugins: 'advlist textcolor anchor autolink fullscreen image lists link media code preview searchreplace table visualblocks wordcount pagebreak powerpaste',
                toolbar: 'undo redo | formatselect bold italic removeformat | forecolor backcolor | alignleft aligncenter alignright alignjustify | table bullist numlist pagebreak hr| link image media insertfile | fullscreen code preview',
                spellchecker_dialog: true,
                license_key: 'gpl'
            };
        }
        if (richEditorConfig.upload_path) {
            richEditorState.baseConfig.images_upload_base_path = richEditorConfig.upload_path;
        }
        if (richEditorConfig.upload_url) {
            richEditorState.baseConfig.images_upload_url = richEditorConfig.upload_url;
        }
        richEditorState.baseConfig.images_upload_credentials = true;
        if (!Array.isArray(richEditorState.queue)) {
            richEditorState.queue = [];
        }
        if (typeof richEditorState.loaded === 'undefined') {
            richEditorState.loaded = typeof window.tinymce !== 'undefined';
        }
        if (typeof richEditorState.loading === 'undefined') {
            richEditorState.loading = false;
        }
        window.FastCrudRichEditor = richEditorState;

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

            if (Array.isArray(meta.columns)) {
                columnsCache = meta.columns;
            }

            if (typeof meta.primary_key === 'string' && meta.primary_key.length) {
                primaryKeyColumn = meta.primary_key;
            }

            columnLabels = meta.labels && typeof meta.labels === 'object' ? meta.labels : {};
            columnClasses = meta.column_classes && typeof meta.column_classes === 'object' ? meta.column_classes : {};
            columnWidths = meta.column_widths && typeof meta.column_widths === 'object' ? meta.column_widths : {};

            if (meta.form && typeof meta.form === 'object') {
                formConfig = {
                    layouts: meta.form.layouts && typeof meta.form.layouts === 'object' ? meta.form.layouts : {},
                    default_tabs: meta.form.default_tabs && typeof meta.form.default_tabs === 'object' ? meta.form.default_tabs : {},
                    behaviours: meta.form.behaviours && typeof meta.form.behaviours === 'object' ? meta.form.behaviours : {},
                    labels: meta.form.labels && typeof meta.form.labels === 'object' ? meta.form.labels : {},
                    all_columns: Array.isArray(meta.form.all_columns) ? meta.form.all_columns : []
                };
                clientConfig.form = meta.form;
            } else {
                formConfig = {
                    layouts: {},
                    default_tabs: {},
                    behaviours: {},
                    labels: {},
                    all_columns: []
                };
                delete clientConfig.form;
            }

            if (Array.isArray(formConfig.all_columns) && formConfig.all_columns.length) {
                baseColumns = formConfig.all_columns.slice();
            } else {
                baseColumns = columnsCache.slice();
            }

            var tableMeta = meta.table && typeof meta.table === 'object' ? meta.table : {};
            duplicateEnabled = !!tableMeta.duplicate;

            updateMetaContainer(tableMeta);
            // sort disabled list from meta
            sortDisabled = {};
            if (Array.isArray(meta.sort_disabled)) {
                meta.sort_disabled.forEach(function(col){ if (col) { sortDisabled[String(col)] = true; } });
            }
            applyHeaderMetadata();
            // Read initial sort from meta and sync client config
            if (Array.isArray(meta.order_by)) {
                orderBy = meta.order_by.slice();
                clientConfig.order_by = orderBy.slice();
            } else {
                orderBy = [];
                clientConfig.order_by = [];
            }
            updateSortIndicators();
            ensureSortHandlers();

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

            if (meta.search && (Array.isArray(meta.search.columns) || Array.isArray(meta.search.available))) {
                searchConfig = {
                    columns: Array.isArray(meta.search.columns) ? meta.search.columns : [],
                    available: Array.isArray(meta.search.available) ? meta.search.available : [],
                    default: meta.search.default || null,
                };
                clientConfig.search_columns = meta.search.columns;
                clientConfig.search_default = meta.search.default || null;

                // Only apply default search column on initial load.
                if (!metaInitialized && !currentSearchColumn && typeof searchConfig.default === 'string' && searchConfig.default !== '') {
                    currentSearchColumn = searchConfig.default;
                }

                ensureSearchControls();
                if (searchSelect) {
                    searchSelect.val(currentSearchColumn || '');
                }
            } else {
                searchConfig = { columns: [], default: null };
                ensureSearchControls();
            }

            renderSummaries(meta.summaries || []);
            refreshTooltips();

            metaInitialized = true;
        }

        function ensureSearchControls() {
            if (!toolbar.length) {
                return;
            }

            // If already initialized, don't rebuild
            if (searchGroup) {
                return;
            }

            searchGroup = $('<div class="input-group fastcrud-search-group" style="max-width: 24rem;"></div>');

            // Always render the select and include an "All" option first
            searchSelect = $('<select class="form-select"></select>');
            // "All" option: empty value so it maps to null in state
            var allOption = $('<option></option>').attr('value', '').text('All Columns');
            if (!currentSearchColumn) {
                allOption.attr('selected', 'selected');
            }
            searchSelect.append(allOption);

            // If configured list is provided, use it strictly; otherwise fallback to visible/available
            var optionOrder = (Array.isArray(searchConfig.columns) && searchConfig.columns.length)
                ? searchConfig.columns
                : (searchConfig.available || []);

            $.each(optionOrder, function(_, column) {
                var option = $('<option></option>').attr('value', column).text(makeLabel(column));
                if (column === currentSearchColumn) {
                    option.attr('selected', 'selected');
                }
                searchSelect.append(option);
            });
            searchSelect.on('change', function() {
                var val = $(this).val();
                currentSearchColumn = val ? String(val) : undefined; // omit from request when "All"
            });
            searchGroup.append(searchSelect);

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

        function updateMetaContainer(tableMeta) {
            if (!metaContainer.length) {
                return;
            }

            metaContainer.empty();

            if (!tableMeta || (!tableMeta.name && !tableMeta.icon && !tableMeta.tooltip)) {
                metaContainer.addClass('d-none');
                return;
            }

            var wrapper = $('<div class="d-flex align-items-center gap-2"></div>');

            if (tableMeta.icon) {
                wrapper.append($('<i></i>').addClass(tableMeta.icon));
            }

            if (tableMeta.name) {
                var title = $('<h5 class="mb-0"></h5>').text(tableMeta.name);
                if (tableMeta.tooltip) {
                    title.attr('title', tableMeta.tooltip).attr('data-bs-toggle', 'tooltip');
                }
                wrapper.append(title);
            } else if (tableMeta.tooltip) {
                wrapper.append($('<span class="text-muted"></span>').text(tableMeta.tooltip));
            }

            metaContainer.removeClass('d-none').append(wrapper);
        }

        function applyHeaderMetadata() {
            var headerCells = table.find('thead th').not('.fastcrud-actions');
            headerCells.each(function(index) {
                var column = columnsCache[index];
                if (!column) {
                    return;
                }

                var cell = $(this);
                cell.empty();
                var label = $('<span class="fastcrud-sort-label"></span>').text(makeLabel(column));
                cell.append(label);
                // Mark sortable only when not disabled
                if (!sortDisabled[column]) {
                    cell.addClass('fastcrud-sortable');
                } else {
                    cell.removeClass('fastcrud-sortable');
                }
                applyWidthToElement(cell, columnWidths[column]);
            });
        }

        function normalizeFieldForHeader(field) {
            if (!field) { return ''; }
            return String(field).replace(/\./g, '__');
        }

        function denormalizeHeaderColumn(column) {
            if (!column) { return ''; }
            return String(column).replace(/__/g, '.');
        }

        function findOrderIndex(column) {
            if (!Array.isArray(orderBy) || !orderBy.length) { return -1; }
            for (var i = 0; i < orderBy.length; i++) {
                var f = String(orderBy[i].field || '');
                if (normalizeFieldForHeader(f) === column) { return i; }
            }
            return -1;
        }

        function getDirectionForColumn(column) {
            var idx = findOrderIndex(column);
            if (idx === -1) { return null; }
            var dir = String(orderBy[idx].direction || '').toLowerCase();
            return (dir === 'asc' || dir === 'desc') ? dir : null;
        }

        function setOrder(column, direction, additive) {
            // column is header-normalized; store denormalized in config
            if (!column) { return; }
            var field = denormalizeHeaderColumn(column);
            var dir = String(direction || 'asc').toUpperCase();
            if (dir !== 'ASC' && dir !== 'DESC') { dir = 'ASC'; }

            if (additive) {
                var idx = findOrderIndex(column);
                if (idx >= 0) {
                    orderBy[idx] = { field: field, direction: dir };
                } else {
                    orderBy.push({ field: field, direction: dir });
                }
            } else {
                orderBy = [{ field: field, direction: dir }];
            }
            clientConfig.order_by = orderBy.slice();
        }

        function updateSortIndicators() {
            var headerCells = table.find('thead th').not('.fastcrud-actions');
            headerCells.each(function(index) {
                var cell = $(this);
                var column = columnsCache[index];
                if (!column) { return; }
                cell.find('.fastcrud-sort-indicator').remove();
                var dir = getDirectionForColumn(column);
                if (dir === 'asc') {
                    cell.append('<span class="fastcrud-sort-indicator" aria-hidden="true">▲</span>');
                    cell.attr('aria-sort', 'ascending');
                } else if (dir === 'desc') {
                    cell.append('<span class="fastcrud-sort-indicator" aria-hidden="true">▼</span>');
                    cell.attr('aria-sort', 'descending');
                } else {
                    cell.removeAttr('aria-sort');
                }
            });
        }

        var sortHandlersBound = false;
        function ensureSortHandlers() {
            if (sortHandlersBound) { return; }
            table.on('click.fastcrudSort', 'thead th.fastcrud-sortable', function(event) {
                event.preventDefault();
                event.stopPropagation();
                var cell = $(this);
                var column = String(cell.attr('data-column') || '').trim();
                if (!column) {
                    // Fallback: derive from index
                    var index = cell.index();
                    column = columnsCache[index] || '';
                }
                if (!column) { return false; }
                if (sortDisabled[column]) { return false; }
                var current = getDirectionForColumn(column);
                var next = (current === 'asc') ? 'desc' : 'asc';
                var additive = !!(event.shiftKey);
                setOrder(column, next || 'asc', additive);
                updateSortIndicators();
                loadTableData(1);
                return false;
            });
            sortHandlersBound = true;
        }

        function applyWidthToElement(element, widthValue) {
            if (!element || !element.length) {
                return;
            }

            element.css('width', '');

            if (!widthValue) {
                return;
            }

            var width = String(widthValue).trim();
            if (!width) {
                return;
            }

            if (/\s/.test(width)) {
                width.split(/\s+/).forEach(function(token) {
                    if (token) {
                        element.addClass(token);
                    }
                });
                return;
            }

            var lower = width.toLowerCase();
            var units = ['px', 'rem', 'em', '%', 'vw', 'vh'];
            var useStyle = false;
            for (var index = 0; index < units.length; index++) {
                var unit = units[index];
                if (lower.slice(-unit.length) === unit) {
                    useStyle = true;
                    break;
                }
            }

            if (!useStyle && lower.indexOf('calc(') !== -1) {
                useStyle = true;
            }

            if (useStyle) {
                element.css('width', width);
            } else {
                element.addClass(width);
            }
        }

        function renderSummaries(summaries) {
            if (!summaryFooter.length) {
                return;
            }

            summaryFooter.empty();

            if (!Array.isArray(summaries) || !summaries.length || !columnsCache.length) {
                summaryFooter.addClass('d-none');
                return;
            }

            summaryFooter.removeClass('d-none');

            $.each(summaries, function(_, summary) {
                var row = $('<tr ></tr>');
                var targetColumn = summary.column;
                var labelText = summary.label || makeLabel(targetColumn);
                var renderedValue = summary.value === null || typeof summary.value === 'undefined' || summary.value === ''
                    ? '—'
                    : String(summary.value);

                $.each(columnsCache, function(columnIndex, column) {
                    var cell = $('<td></td>');
                    applyWidthToElement(cell, columnWidths[column]);

                    if (columnIndex === 0) {
                        if (column === targetColumn) {
                            cell.text(labelText + ': ' + renderedValue).addClass('fw-semibold');
                        } else {
                            cell.text(labelText).addClass('text-muted');
                        }
                    } else if (column === targetColumn) {
                        cell.text(renderedValue).addClass('fw-semibold');
                    } else {
                        cell.html('&nbsp;');
                    }

                    row.append(cell);
                });

                row.append('<td class="text-end fastcrud-actions-cell">&nbsp;</td>');
                summaryFooter.append(row);
            });
        }

        function refreshTooltips() {
            if (!window.bootstrap || !bootstrap.Tooltip) {
                return;
            }

            var tooltipTargets = table.find('[data-bs-toggle="tooltip"]').get();
            tooltipTargets.forEach(function(target) {
                var existing = bootstrap.Tooltip.getInstance(target);
                if (existing) {
                    existing.dispose();
                }
                bootstrap.Tooltip.getOrCreateInstance(target);
            });

            var metaTargets = metaContainer.find('[data-bs-toggle="tooltip"]').get();
            metaTargets.forEach(function(target) {
                var existing = bootstrap.Tooltip.getInstance(target);
                if (existing) {
                    existing.dispose();
                }
                bootstrap.Tooltip.getOrCreateInstance(target);
            });
        }

        function withRichEditorAssets(callback) {
            if (typeof callback !== 'function') {
                return;
            }

            if (typeof window.tinymce !== 'undefined') {
                richEditorState.loaded = true;
                callback();
                return;
            }

            richEditorState.queue.push(callback);

            if (richEditorState.loading) {
                return;
            }

            richEditorState.loading = true;

            var script = document.createElement('script');
            script.src = richEditorState.scriptUrl;
            script.referrerPolicy = 'no-referrer';
            script.onload = function() {
                richEditorState.loaded = true;
                richEditorState.loading = false;
                var queued = richEditorState.queue.slice();
                richEditorState.queue.length = 0;
                queued.forEach(function(fn) {
                    try {
                        fn();
                    } catch (error) {
                        console.error(error);
                    }
                });
            };
            script.onerror = function() {
                richEditorState.loading = false;
                console.error('FastCrud: failed to load TinyMCE assets from ' + richEditorState.scriptUrl);
                richEditorState.queue.length = 0;
            };

            document.head.appendChild(script);
        }

        function initializeRichEditors(container) {
            if (!container || !container.length) {
                return;
            }

            var editors = container.find('textarea.fastcrud-rich-editor');
            if (!editors.length) {
                return;
            }

            withRichEditorAssets(function() {
                if (!window.tinymce || typeof window.tinymce.init !== 'function') {
                    return;
                }

                editors.each(function() {
                    var textarea = $(this);
                    var element = textarea.get(0);
                    if (!element) {
                        return;
                    }

                    if (element.id) {
                        var existingEditor = window.tinymce.get(element.id);
                        if (existingEditor) {
                            existingEditor.remove();
                        }
                    }

                    var overrides = textarea.data('fastcrudEditorConfig');
                    var config = $.extend(true, {}, richEditorState.baseConfig || {});
                    if (overrides && typeof overrides === 'object') {
                        config = $.extend(true, config, overrides);
                    }

                    if (config.selector) {
                        delete config.selector;
                    }
                    if (config.target && config.target !== element) {
                        delete config.target;
                    }

                    config.target = element;

                    var existingSetup = config.setup;
                    config.setup = function(editor) {
                        editor.on('change keyup blur', function() {
                            textarea.val(editor.getContent());
                        });
                        if (typeof existingSetup === 'function') {
                            existingSetup(editor);
                        }
                    };

                    if (!config.images_upload_url) {
                        config.images_upload_url = richEditorConfig.upload_url || window.location.pathname;
                    }
                    config.images_upload_credentials = true;

                    if (typeof config.images_upload_handler !== 'function') {
                        config.images_upload_handler = function(blobInfo, success, failure, progress) {
                            var uploadUrl = config.images_upload_url || richEditorConfig.upload_url || window.location.pathname;
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', uploadUrl);
                            xhr.withCredentials = true;

                            xhr.onload = function() {
                                if (xhr.status < 200 || xhr.status >= 300) {
                                    failure('Upload failed with status ' + xhr.status);
                                    return;
                                }
                                var response;
                                var rawResponse = xhr.responseText || '';
                                try {
                                    response = JSON.parse(rawResponse || '{}');
                                } catch (error) {
                                    if (window.console && typeof window.console.error === 'function') {
                                        console.error('FastCrud TinyMCE upload JSON parse error', error, rawResponse);
                                    }
                                    failure('Upload returned invalid JSON.');
                                    return;
                                }
                                if (!response || response.success !== true || !response.location) {
                                    var message = response && response.error ? response.error : 'Upload failed.';
                                    failure(message);
                                    return;
                                }
                                success(response.location);
                            };

                            xhr.onerror = function() {
                                failure('Upload failed due to a network error.');
                            };

                            if (xhr.upload && typeof progress === 'function') {
                                xhr.upload.onprogress = function(event) {
                                    if (event.lengthComputable) {
                                        progress((event.loaded / event.total) * 100);
                                    }
                                };
                            }

                            var formData = new FormData();
                            formData.append('file', blobInfo.blob(), blobInfo.filename());
                            formData.append('fastcrud_ajax', '1');
                            formData.append('action', 'upload_image');
                            if (tableName) {
                                formData.append('table', tableName);
                            }
                            if (tableId) {
                                formData.append('id', tableId);
                            }

                            xhr.send(formData);
                        };
                    }

                    window.tinymce.init(config);
                });
            });
        }

        function destroyRichEditors(container) {
            if (!container || !container.length) {
                return;
            }

            if (!window.tinymce || !window.tinymce.editors) {
                return;
            }

            var editors = Array.prototype.slice.call(window.tinymce.editors || []);
            editors.forEach(function(editor) {
                if (!editor) {
                    return;
                }
                var element = editor.targetElm || (typeof editor.getElement === 'function' ? editor.getElement() : null);
                if (!element) {
                    return;
                }
                if ($(element).closest(container).length) {
                    editor.remove();
                }
            });
        }

        // Note: previously had a jQuery-based builder for custom buttons here.
        // It was unused and removed to reduce dead code.

        var actionIcons = {
            view: '<svg xmlns="http://www.w3.org/2000/svg" class="fastcrud-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
            edit: '<svg xmlns="http://www.w3.org/2000/svg" class="fastcrud-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 21h4l11-11a2.828 2.828 0 1 0-4-4L4 17v4Z"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.5 6.5 17.5 9.5"/></svg>',
            delete: '<svg xmlns="http://www.w3.org/2000/svg" class="fastcrud-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 6h18"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 6V4.5A1.5 1.5 0 0 1 9.5 3h5A1.5 1.5 0 0 1 16 4.5V6"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.5 6 17.6 19.25a1.75 1.75 0 0 1-1.74 1.6H8.14a1.75 1.75 0 0 1-1.74-1.6L5.5 6"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 11v6"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 11v6"/></svg>',
            duplicate: '<svg xmlns="http://www.w3.org/2000/svg" class="fastcrud-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect width="11" height="11" x="9.5" y="9.5" rx="2" ry="2" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/></svg>'
        };

        // Note: previously had a jQuery-based builder for the action cell here.
        // The code now uses `buildActionCellHtml` to generate HTML strings directly.

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
            if (columnLabels && Object.prototype.hasOwnProperty.call(columnLabels, column)) {
                return columnLabels[column];
            }

            var words = column.replace(/_/g, ' ').split(' ');

            for (var index = 0; index < words.length; index++) {
                if (words[index].length > 0) {
                    words[index] = words[index].charAt(0).toUpperCase() + words[index].slice(1);
                }
            }

            return words.join(' ');
        }

        function resolveFieldLabel(column) {
            if (formConfig.labels && Object.prototype.hasOwnProperty.call(formConfig.labels, column)) {
                var label = formConfig.labels[column];
                if (typeof label === 'string' && label.length) {
                    return label;
                }
            }

            return makeLabel(column);
        }

        function makeSlug(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'tab';
        }

        function resolveBehaviour(key, field, mode) {
            if (!formConfig.behaviours || !formConfig.behaviours[key]) {
                return undefined;
            }

            var definition = formConfig.behaviours[key][field];
            if (!definition) {
                return undefined;
            }

            var value = typeof definition.all !== 'undefined' ? definition.all : undefined;
            if (mode && typeof definition[mode] !== 'undefined') {
                value = definition[mode];
            }

            return value;
        }

        function resolveBehavioursForField(field, mode) {
            var behaviours = {};
            if (formConfig.behaviours && formConfig.behaviours.change_type && formConfig.behaviours.change_type[field]) {
                behaviours.change_type = formConfig.behaviours.change_type[field];
            }

            var keys = ['pass_var', 'pass_default', 'readonly', 'disabled', 'validation_required', 'validation_pattern', 'unique'];
            keys.forEach(function(key) {
                var value = resolveBehaviour(key, field, mode);
                if (typeof value !== 'undefined') {
                    behaviours[key] = value;
                }
            });

            return behaviours;
        }

        function buildFormLayout(mode) {
            mode = mode || 'edit';

            var instructions = [];
            if (formConfig.layouts) {
                if (Array.isArray(formConfig.layouts.all)) {
                    instructions = instructions.concat(formConfig.layouts.all);
                }
                if (formConfig.layouts[mode] && Array.isArray(formConfig.layouts[mode])) {
                    instructions = instructions.concat(formConfig.layouts[mode]);
                }
            }

            var hasWhitelist = false;
            var whitelistOrder = [];
            var hiddenFields = {};
            var fieldTabMap = {};
            var tabOrder = [];
            var columnUniverse = baseColumns.length ? baseColumns.slice() : columnsCache.slice();
            var columnLookup = {};
            columnUniverse.forEach(function(field) {
                columnLookup[field] = true;
            });

            instructions.forEach(function(entry) {
                if (!entry || !Array.isArray(entry.fields)) {
                    return;
                }

                var tabName = entry.tab && String(entry.tab).length ? String(entry.tab) : null;
                var resolvedFields = entry.fields.filter(function(field) {
                    return columnUniverse.length === 0 || columnLookup[field];
                });

                if (!resolvedFields.length) {
                    return;
                }

                if (entry.reverse) {
                    resolvedFields.forEach(function(field) {
                        hiddenFields[field] = true;
                    });
                    return;
                }

                hasWhitelist = true;
                resolvedFields.forEach(function(field) {
                    if (whitelistOrder.indexOf(field) === -1) {
                        whitelistOrder.push(field);
                    }
                    if (tabName) {
                        fieldTabMap[field] = tabName;
                        if (tabOrder.indexOf(tabName) === -1) {
                            tabOrder.push(tabName);
                        }
                    } else if (!Object.prototype.hasOwnProperty.call(fieldTabMap, field)) {
                        fieldTabMap[field] = null;
                    }
                });
            });

            var ordering;
            if (hasWhitelist && whitelistOrder.length) {
                ordering = whitelistOrder.slice();
            } else {
                var fallback = columnUniverse.length ? columnUniverse : columnsCache;
                ordering = fallback.slice().filter(function(field) {
                    return !hiddenFields[field];
                });
            }

            ordering = ordering.filter(function(field) {
                return field !== primaryKeyColumn;
            });

            var defaultTab = null;
            if (formConfig.default_tabs) {
                if (typeof formConfig.default_tabs.all === 'string' && formConfig.default_tabs.all.length) {
                    defaultTab = formConfig.default_tabs.all;
                }
                if (mode && typeof formConfig.default_tabs[mode] === 'string' && formConfig.default_tabs[mode].length) {
                    defaultTab = formConfig.default_tabs[mode];
                }
            }

            var hasTabs = tabOrder.length > 0;
            var normalizedFields = ordering.map(function(field) {
                return {
                    name: field,
                    tab: fieldTabMap[field] || null
                };
            });

            if (hasTabs) {
                var fallbackTab = defaultTab || (tabOrder.length ? tabOrder[0] : null);
                normalizedFields = normalizedFields.map(function(item) {
                    if (!item.tab && fallbackTab) {
                        item.tab = fallbackTab;
                    }
                    if (item.tab && tabOrder.indexOf(item.tab) === -1) {
                        tabOrder.push(item.tab);
                    }
                    return item;
                });

                if (fallbackTab && tabOrder.indexOf(fallbackTab) === -1) {
                    tabOrder.unshift(fallbackTab);
                }
            } else {
                normalizedFields.forEach(function(item) {
                    if (item.tab && tabOrder.indexOf(item.tab) === -1) {
                        tabOrder.push(item.tab);
                    }
                });
            }

            return {
                fields: normalizedFields,
                tabs: tabOrder.filter(function(tab) { return !!tab; }),
                defaultTab: defaultTab
            };
        }

        function interpolateTemplate(template, context) {
            if (typeof template !== 'string') {
                return template;
            }

            return template.replace(/\{([A-Za-z0-9_]+)\}/g, function(_, token) {
                if (!Object.prototype.hasOwnProperty.call(context, token)) {
                    return '';
                }

                var value = context[token];
                if (value === null || typeof value === 'undefined') {
                    return '';
                }

                return String(value);
            });
        }

        function compileClientPattern(pattern) {
            if (typeof pattern !== 'string') {
                return null;
            }

            var trimmed = pattern.trim();
            if (!trimmed.length) {
                return null;
            }

            var delimiter = trimmed.charAt(0);
            var body = trimmed;
            var flags = '';
            var closingIndex = trimmed.lastIndexOf(delimiter);

            if ((delimiter === '/' || delimiter === '#') && closingIndex > 0) {
                body = trimmed.slice(1, closingIndex);
                flags = trimmed.slice(closingIndex + 1);
            }

            try {
                return new RegExp(body, flags.replace(/[^gimuy]/g, ''));
            } catch (error) {
                return null;
            }
        }

        function clearFormAlerts() {
            editError.addClass('d-none').text('');
            editSuccess.addClass('d-none');
            clearFieldErrors();
        }

        function clearFieldErrors() {
            if (!editFieldsContainer.length) {
                return;
            }

            editFieldsContainer.find('.is-invalid').removeClass('is-invalid');
            editFieldsContainer.find('.fastcrud-field-feedback').remove();
        }

        function buildJsonErrorMessage(error) {
            var detail = '';
            if (error && typeof error.message === 'string') {
                detail = error.message.replace(/\s+/g, ' ').trim();
            } else if (typeof error === 'string') {
                detail = error.replace(/\s+/g, ' ').trim();
            }
            return detail ? 'Invalid JSON: ' + detail : 'Invalid JSON.';
        }

        function setInlineFieldError(input, message) {
            if (!input) {
                return;
            }

            var jqInput = input.jquery ? input : $(input);
            if (!jqInput.length) {
                return;
            }

            var text = String(message || '').trim();
            if (text === '') {
                clearInlineFieldError(jqInput);
                return;
            }

            var group = jqInput.closest('.mb-3, .form-check');
            var feedback;
            if (group.length) {
                feedback = group.find('.fastcrud-field-feedback').first();
                if (!feedback.length) {
                    feedback = $('<div class="invalid-feedback fastcrud-field-feedback" data-fastcrud-inline="1"></div>');
                    group.append(feedback);
                }
            } else {
                feedback = jqInput.siblings('.fastcrud-field-feedback').first();
                if (!feedback.length) {
                    feedback = $('<div class="invalid-feedback fastcrud-field-feedback" data-fastcrud-inline="1"></div>');
                    jqInput.after(feedback);
                }
            }

            feedback.attr('data-fastcrud-inline', '1');
            feedback.text(text);
            jqInput.addClass('is-invalid');
        }

        function clearInlineFieldError(input) {
            if (!input) {
                return;
            }

            var jqInput = input.jquery ? input : $(input);
            if (!jqInput.length) {
                return;
            }

            jqInput.removeClass('is-invalid');

            var group = jqInput.closest('.mb-3, .form-check');
            var feedback;
            if (group.length) {
                feedback = group.find('.fastcrud-field-feedback[data-fastcrud-inline="1"]').first();
            } else {
                feedback = jqInput.siblings('.fastcrud-field-feedback[data-fastcrud-inline="1"]').first();
            }

            if (feedback && feedback.length) {
                feedback.remove();
            }
        }

        function applyFieldErrors(errors) {
            clearFieldErrors();
            if (!errors || typeof errors !== 'object') {
                return;
            }

            currentFieldErrors = errors;

            Object.keys(errors).forEach(function(field) {
                var message = errors[field];
                var selector = '[data-fastcrud-field="' + field + '"]';
                var input = editFieldsContainer.find(selector);
                if (!input.length) {
                    input = editForm.find(selector);
                }
                if (!input.length) {
                    return;
                }

                input.addClass('is-invalid');
                var feedback = $('<div class="invalid-feedback fastcrud-field-feedback"></div>').text(message);
                var group = input.closest('.mb-3');
                if (group.length) {
                    if (!group.find('.fastcrud-field-feedback').length) {
                        group.append(feedback);
                    }
                } else {
                    input.after(feedback);
                }
            });
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
                if (rangeDisplay.length) {
                    rangeDisplay.text('');
                }
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

            if (rangeDisplay.length) {
                var startRange = totalRows === 0 ? 0 : ((current - 1) * pagination.per_page) + 1;
                var endRange = totalRows === 0 ? 0 : Math.min(current * pagination.per_page, totalRows);
                rangeDisplay.text('Showing ' + startRange + '-' + endRange + ' of ' + totalRows);
            }
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

        // Helper functions for batched row rendering
        function escapeHtml(value) {
            var s = (value === null || typeof value === 'undefined') ? '' : String(value);
            return s
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function deriveWidthAttr(widthValue) {
            var w = String(widthValue || '').trim();
            if (!w) { return { style: '', className: '' }; }
            var lower = w.toLowerCase();
            var units = ['px','rem','em','%','vw','vh'];
            var isStyle = lower.indexOf('calc(') !== -1;
            if (!isStyle) {
                for (var i = 0; i < units.length; i++) { if (lower.endsWith(units[i])) { isStyle = true; break; } }
            }
            if (isStyle) { return { style: 'width: ' + escapeHtml(w) + ';', className: '' }; }
            return { style: '', className: escapeHtml(w) };
        }

        function buildActionCellHtml() {
            var html = '<td class="text-end fastcrud-actions-cell"><div class="btn-group btn-group-sm" role="group">';
            if (duplicateEnabled) {
                // Place duplicate button to the left of other action buttons
                html += '<button type="button" class="btn btn-sm btn-info fastcrud-duplicate-btn" title="Duplicate" aria-label="Duplicate record">' + actionIcons.duplicate + '</button>';
            }
            html += '<button type="button" class="btn btn-sm btn-secondary fastcrud-view-btn" title="View" aria-label="View record">' + actionIcons.view + '</button>';
            html += '<button type="button" class="btn btn-sm btn-primary fastcrud-edit-btn" title="Edit" aria-label="Edit record">' + actionIcons.edit + '</button>';
            html += '<button type="button" class="btn btn-sm btn-danger fastcrud-delete-btn" title="Delete" aria-label="Delete record">' + actionIcons.delete + '</button>';
            html += '</div></td>';
            return html;
        }

        function populateTableRows(rows) {
            var tbody = table.find('tbody');
            var totalColumns = table.find('thead th').length || 1;

            if (!rows || rows.length === 0) {
                tbody.html('');
                showEmptyRow(totalColumns, 'No records found.');
                return;
            }

            var html = '';
            $.each(rows, function(_, row) {
                var rowMeta = row.__fastcrud || {};
                var cellsMeta = rowMeta.cells || {};
                var rawValues = rowMeta.raw || {};
                var rowPrimaryKeyColumn = row.__fastcrud_primary_key || rowMeta.primary_key || primaryKeyColumn;
                var primaryValue;
                if (Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined') {
                    primaryValue = row.__fastcrud_primary_value;
                } else if (rowPrimaryKeyColumn) {
                    primaryValue = row[rowPrimaryKeyColumn];
                } else {
                    primaryValue = null;
                }
                var rowData = $.extend({}, row);
                delete rowData.__fastcrud;
                if (rowData.__fastcrud_raw) { delete rowData.__fastcrud_raw; }
                if (rawValues && typeof rawValues === 'object') {
                    Object.keys(rawValues).forEach(function(key) {
                        var rawValue = rawValues[key];
                        if (typeof rawValue !== 'undefined') { rowData[key] = rawValue; }
                    });
                }

                var rowClass = rowMeta.row_class ? ' class="' + escapeHtml(rowMeta.row_class) + '"' : '';
                var cells = '';
                $.each(columnsCache, function(colIndex, column) {
                    var cellMeta = cellsMeta[column] || {};
                    var displayValue;
                    if (typeof cellMeta.display !== 'undefined') {
                        displayValue = cellMeta.display;
                    } else {
                        var rawValue = row[column];
                        displayValue = (rawValue === null || typeof rawValue === 'undefined') ? '' : rawValue;
                    }

                    var cls = cellMeta.class ? String(cellMeta.class) : (columnClasses[column] || '');
                    var widthValue = (cellMeta.width || columnWidths[column]);
                    var widthAttr = deriveWidthAttr(widthValue);
                    var classParts = [];
                    if (cls) { classParts.push(escapeHtml(cls)); }
                    if (widthAttr.className) { classParts.push(widthAttr.className); }
                    var classAttr = classParts.length ? (' class="' + classParts.join(' ') + '"') : '';
                    var styleAttr = widthAttr.style ? (' style="' + widthAttr.style + '"') : '';

                    var attrs = '';
                    if (cellMeta.tooltip) {
                        attrs += ' title="' + escapeHtml(cellMeta.tooltip) + '" data-bs-toggle="tooltip"';
                    }
                    if (cellMeta.attributes && typeof cellMeta.attributes === 'object') {
                        $.each(cellMeta.attributes, function(attrKey, attrValue) {
                            var k = String(attrKey);
                            var v = (attrValue === null || typeof attrValue === 'undefined') ? '' : String(attrValue);
                            attrs += ' ' + escapeHtml(k) + '="' + escapeHtml(v) + '"';
                        });
                    }

                    var inner = '';
                    if (cellMeta.html) {
                        inner = String(cellMeta.html);
                    } else {
                        inner = escapeHtml(displayValue);
                    }

                    cells += '<td' + classAttr + styleAttr + attrs + '>' + inner + '</td>';
                });

                cells += buildActionCellHtml();
                var trAttrs = '';
                if (rowPrimaryKeyColumn) {
                    trAttrs += ' data-fastcrud-pk="' + escapeHtml(String(rowPrimaryKeyColumn)) + '"';
                }
                if (typeof primaryValue !== 'undefined') {
                    trAttrs += ' data-fastcrud-pk-value="' + escapeHtml(String(primaryValue)) + '"';
                }
                html += '<tr' + rowClass + trAttrs + '>' + cells + '</tr>';
            });

            tbody.html(html);
        }

        function loadTableData(page) {
            currentPage = page || 1;
            // Clear row cache to avoid stale data after reloads
            rowCache = {};

            var tbody = table.find('tbody');
            var totalColumns = table.find('thead th').length || 1;

            tbody.html('<tr><td colspan="' + totalColumns + '" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div> Loading...</td></tr>');

            var payload = {
                fastcrud_ajax: '1',
                action: 'fetch',
                table: tableName,
                id: tableId,
                page: currentPage,
                per_page: perPage > 0 ? perPage : 0,
                search_term: currentSearchTerm,
                config: JSON.stringify(clientConfig)
            };
            if (typeof currentSearchColumn !== 'undefined' && currentSearchColumn !== null && String(currentSearchColumn).length) {
                payload.search_column = currentSearchColumn;
            }

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: payload,
                success: function(response) {
                    if (response && response.success) {
                        applyMeta(response.meta || {});

                        if (Array.isArray(response.columns) && response.columns.length) {
                            columnsCache = response.columns;
                        }
                        if (!primaryKeyColumn) {
                            primaryKeyColumn = findPrimaryKey(columnsCache);
                        }

                        populateTableRows(response.data || []);
                        refreshTooltips();

                        renderSummaries(metaConfig.summaries || []);

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

            if (!row) {
                showFormError('Unable to determine primary key for editing.');
                return;
            }

            var rowPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!rowPrimaryKeyColumn) {
                showFormError('Unable to determine primary key for editing.');
                return;
            }

            var primaryKeyValue = Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined'
                ? row.__fastcrud_primary_value
                : row[rowPrimaryKeyColumn];

            editForm.data('primaryKeyColumn', rowPrimaryKeyColumn);
            editForm.data('primaryKeyValue', primaryKeyValue);

            if (!primaryKeyColumn) {
                primaryKeyColumn = rowPrimaryKeyColumn;
            }

            if (primaryKeyValue === null || typeof primaryKeyValue === 'undefined' || String(primaryKeyValue).length === 0) {
                showFormError('Missing primary key value for selected record.');
                return;
            }

            if (editLabel.length) {
                editLabel.text('Edit Record ' + primaryKeyValue);
            }

            destroyRichEditors(editFieldsContainer);
            editFieldsContainer.empty();
            editForm.find('input[type="hidden"][data-fastcrud-field]').remove();

            var templateContext = $.extend({}, row);

            var layout = buildFormLayout('edit');
            var fields = layout.fields.slice();
            if (!fields.length) {
                var fallbackColumns = baseColumns.length ? baseColumns : columnsCache;
                fields = fallbackColumns
                    .filter(function(column) { return column !== rowPrimaryKeyColumn; })
                    .map(function(column) { return { name: column, tab: null }; });
            }

            var visibleFields = [];
            fields.forEach(function(field) {
                if (field.name === rowPrimaryKeyColumn) {
                    return;
                }
                visibleFields.push(field);
            });

            var useTabs = Array.isArray(layout.tabs) && layout.tabs.length > 0 && visibleFields.some(function(field) {
                return !!field.tab;
            });
            var tabsNav = null;
            var tabsContent = null;
            var tabEntries = {};
            var defaultTabName = layout.defaultTab || null;

            if (useTabs) {
                tabsNav = $('<ul class="nav nav-tabs mb-3" role="tablist"></ul>');
                tabsContent = $('<div class="tab-content"></div>');
                editFieldsContainer.append(tabsNav).append(tabsContent);
            }

            function ensureTab(tabName) {
                if (!tabsNav || !tabsContent) {
                    return null;
                }

                if (!tabName) {
                    return null;
                }

                if (tabEntries[tabName]) {
                    return tabEntries[tabName];
                }

                var slug = makeSlug(tabName);
                var tabId = editFormId + '-tab-' + slug;
                var navItem = $('<li class="nav-item" role="presentation"></li>');
                var navButton = $('<button class="nav-link" data-bs-toggle="tab" type="button" role="tab"></button>')
                    .attr('id', tabId + '-tab')
                    .attr('data-bs-target', '#' + tabId)
                    .attr('aria-controls', tabId)
                    .attr('aria-selected', 'false')
                    .text(tabName);
                navItem.append(navButton);
                tabsNav.append(navItem);

                var pane = $('<div class="tab-pane fade" role="tabpanel"></div>')
                    .attr('id', tabId)
                    .attr('aria-labelledby', tabId + '-tab');
                tabsContent.append(pane);

                tabEntries[tabName] = { nav: navButton, pane: pane };
                return tabEntries[tabName];
            }

            if (useTabs) {
                layout.tabs.forEach(function(tabName) {
                    if (tabName) {
                        ensureTab(tabName);
                    }
                });
            }

            visibleFields.forEach(function(field) {
                var column = field.name;
                var behaviours = resolveBehavioursForField(column, 'edit');
                var changeMeta = behaviours.change_type || {};
                var changeType = String(changeMeta.type || 'text').toLowerCase();
                if (changeType === 'dropdown') {
                    changeType = 'select';
                }
                var params = changeMeta.params || {};
                if (!params || typeof params !== 'object') {
                    params = {};
                }
                var fieldId = editFormId + '-' + column;
                var labelForId = fieldId;
                var saveColumn = column;

                var currentValue = typeof row[column] !== 'undefined' && row[column] !== null ? row[column] : '';
                if ((currentValue === null || currentValue === '') && typeof behaviours.pass_default !== 'undefined') {
                    currentValue = interpolateTemplate(behaviours.pass_default, templateContext);
                }
                if ((currentValue === null || currentValue === '') && typeof changeMeta.default !== 'undefined' && changeMeta.default !== null) {
                    currentValue = changeMeta.default;
                }
                if (typeof behaviours.pass_var !== 'undefined') {
                    currentValue = interpolateTemplate(behaviours.pass_var, templateContext);
                }
                if (currentValue === null || typeof currentValue === 'undefined') {
                    currentValue = '';
                }

                templateContext[column] = currentValue;

                if (changeType === 'hidden') {
                    var hiddenInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', column)
                        .attr('data-fastcrud-type', 'hidden')
                        .val(currentValue);
                    editForm.append(hiddenInput);
                    return;
                }

                var container = editFieldsContainer;
                if (useTabs) {
                    var targetTab = field.tab || defaultTabName || (layout.tabs.length ? layout.tabs[0] : null);
                    if (targetTab) {
                        var entry = ensureTab(targetTab);
                        if (entry && entry.pane) {
                            container = entry.pane;
                        }
                    }
                }

                var group = $('<div class="mb-3"></div>').attr('data-fastcrud-group', column);
                var input;
                var compound = null; // optional wrapper for composite inputs (e.g., color)
                var colorPicker = null; // used when changeType === 'color'
                var dataType = changeType;
                var normalizedValue = currentValue;

                if (changeType === 'textarea') {
                    input = $('<textarea class="form-control"></textarea>')
                        .attr('id', fieldId)
                        .attr('rows', params.rows && Number(params.rows) > 0 ? Number(params.rows) : 3)
                        .val(normalizedValue);
                } else if (changeType === 'rich_editor') {
                    var startingValue = normalizedValue === null || typeof normalizedValue === 'undefined'
                        ? ''
                        : String(normalizedValue);
                    input = $('<textarea class="form-control editor-instance fastcrud-rich-editor"></textarea>')
                        .attr('id', fieldId)
                        .attr('rows', params.rows && Number(params.rows) > 0 ? Number(params.rows) : 6)
                        .val(startingValue);
                    dataType = 'rich_editor';

                    var editorConfig = {};
                    if (typeof params.height !== 'undefined' && params.height !== null) {
                        var heightCandidate = params.height;
                        var numericHeight = Number(heightCandidate);
                        editorConfig.height = Number.isFinite(numericHeight) && numericHeight > 0 ? numericHeight : heightCandidate;
                    }
                    if (params.editor && typeof params.editor === 'object') {
                        editorConfig = $.extend(true, editorConfig, params.editor);
                    }
                    if (!$.isEmptyObject(editorConfig)) {
                        input.data('fastcrudEditorConfig', editorConfig);
                    }
                } else if (changeType === 'json') {
                    // JSON editor: textarea with optional pretty-print and live validation
                    var jsonText = '';
                    try {
                        var s = (normalizedValue === null || typeof normalizedValue === 'undefined') ? '' : String(normalizedValue);
                        var t = s.trim();
                        if (t.length) {
                            var parsed = JSON.parse(t);
                            if (params.pretty === false) {
                                jsonText = t;
                            } else {
                                jsonText = JSON.stringify(parsed, null, 2);
                            }
                        } else {
                            jsonText = '';
                        }
                    } catch (e) {
                        jsonText = String(normalizedValue || '');
                    }
                    input = $('<textarea class="form-control fastcrud-json" style="font-family: monospace;"></textarea>')
                        .attr('id', fieldId)
                        .attr('rows', params.rows && Number(params.rows) > 0 ? Number(params.rows) : 6)
                        .val(jsonText);
                    // Lightweight live validation for JSON content
                    input.on('input blur', function() {
                        var el = $(this);
                        var value = String(el.val() || '').trim();
                        if (value === '') {
                            clearInlineFieldError(el);
                            return;
                        }
                        try {
                            JSON.parse(value);
                            clearInlineFieldError(el);
                        } catch (jsonError) {
                            setInlineFieldError(el, buildJsonErrorMessage(jsonError));
                        }
                    });
                    dataType = 'json';
                } else if (changeType === 'select') {
                    input = $('<select class="form-select"></select>').attr('id', fieldId);
                    var optionMap = params.values || params.options || {};
                    var optionsList = [];
                    if ($.isArray(optionMap)) {
                        optionMap.forEach(function(optionValue) {
                            optionsList.push({ value: optionValue, label: optionValue });
                        });
                    } else if (typeof optionMap === 'object') {
                        Object.keys(optionMap).forEach(function(key) {
                            optionsList.push({ value: key, label: optionMap[key] });
                        });
                    }
                    if (params.placeholder) {
                        input.append($('<option></option>').attr('value', '').text(params.placeholder));
                    }
                    optionsList.forEach(function(option) {
                        input.append($('<option></option>').attr('value', option.value).text(option.label));
                    });
                    input.val(String(normalizedValue));
                } else if (changeType === 'image' || changeType === 'images') {
                    var isMultipleImages = changeType === 'images';
                    // Always use the declared column; no mapping via params.save_to or base column checks

                    var normalizedList = parseImageNameList(currentValue);
                    var initialValueString = isMultipleImages
                        ? imageNamesToString(normalizedList)
                        : (normalizedList.length ? normalizedList[0] : '');
                    if (!isMultipleImages) {
                        normalizedList = initialValueString ? [initialValueString] : [];
                    }
                    normalizedValue = initialValueString;

                    // Use FilePond for image uploads with preview; store value in a hidden field
                    var hiddenInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', saveColumn)
                        .attr('data-fastcrud-type', 'hidden')
                        .val(String(initialValueString || ''));
                    input = $('<input type="file" class="fastcrud-filepond" accept="image/*" />')
                        .attr('id', fieldId + '-file');
                    if (isMultipleImages) {
                        input.attr('multiple', 'multiple');
                    }
                    labelForId = fieldId + '-file';
                    dataType = changeType;
                } else if (changeType === 'file' || changeType === 'files') {
                    var isMultipleFiles = (changeType === 'files');
                    var normalizedListFiles = parseImageNameList(currentValue);
                    var initialFilesValue = isMultipleFiles
                        ? imageNamesToString(normalizedListFiles)
                        : (normalizedListFiles.length ? normalizedListFiles[0] : '');
                    var hiddenInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', saveColumn)
                        .attr('data-fastcrud-type', 'hidden')
                        .val(String(initialFilesValue || ''));
                    input = $('<input type="file" class="fastcrud-filepond" />')
                        .attr('id', fieldId + '-file');
                    if (isMultipleFiles) {
                        input.attr('multiple', 'multiple');
                    }
                    if (params.accept) {
                        input.attr('accept', params.accept);
                    }
                    labelForId = fieldId + '-file';
                    dataType = isMultipleFiles ? 'files' : 'file';
                } else if (changeType === 'multiselect') {
                    input = $('<select class="form-select" multiple></select>').attr('id', fieldId);
                    var multiMap = params.values || params.options || {};
                    var multiOptions = [];
                    if ($.isArray(multiMap)) {
                        multiMap.forEach(function(optionValue) {
                            multiOptions.push({ value: optionValue, label: optionValue });
                        });
                    } else if (typeof multiMap === 'object') {
                        Object.keys(multiMap).forEach(function(key) {
                            multiOptions.push({ value: key, label: multiMap[key] });
                        });
                    }
                    multiOptions.forEach(function(option) {
                        input.append($('<option></option>').attr('value', option.value).text(option.label));
                    });
                    var selectedValues;
                    if ($.isArray(normalizedValue)) {
                        selectedValues = normalizedValue;
                    } else {
                        selectedValues = String(normalizedValue).split(',').map(function(value) {
                            return value.trim();
                        }).filter(function(value) {
                            return value.length > 0;
                        });
                    }
                    input.val(selectedValues);
                } else if (changeType === 'date' || changeType === 'datetime' || changeType === 'datetime-local') {
                    input = $('<input class="form-control" />')
                        .attr('id', fieldId)
                        .attr('type', changeType === 'date' ? 'date' : 'datetime-local')
                        .val(String(normalizedValue));
                    dataType = changeType === 'date' ? 'date' : 'datetime';
                } else if (changeType === 'time') {
                    input = $('<input type="time" class="form-control" />')
                        .attr('id', fieldId)
                        .val(String(normalizedValue));
                } else if (changeType === 'email') {
                    input = $('<input type="email" class="form-control" />')
                        .attr('id', fieldId)
                        .val(String(normalizedValue));
                } else if (changeType === 'color') {
                    var startColor = String(normalizedValue || '').trim();
                    if (!startColor) { startColor = '#000000'; }
                    // Text input is the value holder submitted to server
                    input = $('<input type="text" class="form-control" />')
                        .attr('id', fieldId)
                        .attr('placeholder', '#RRGGBB')
                        .val(startColor);
                    dataType = 'color';
                    // Color picker on the left inside an input-group
                    colorPicker = $('<input type="color" class="form-control form-control-color" />')
                        .attr('id', fieldId + '-picker')
                        .val(startColor)
                        .css({ minWidth: '3rem' });
                    compound = $('<div class="input-group align-items-stretch"></div>');
                    var addon = $('<span class="input-group-text p-0"></span>');
                    addon.append(colorPicker);
                    compound.append(addon).append(input);
                    // Keep values in sync both ways
                    colorPicker.on('input change', function() {
                        try { input.val(String(colorPicker.val() || '')).trigger('input').trigger('change'); } catch (e) {}
                    });
                    input.on('input change', function() {
                        try {
                            var v = String(input.val() || '').trim();
                            if (/^#([0-9a-fA-F]{6})$/.test(v)) { colorPicker.val(v); }
                        } catch (e) {}
                    });
                    // Clicking or focusing the hex input opens the color picker
                    function openColorPicker() {
                        try {
                            if (input.prop('disabled') || input.prop('readonly')) { return; }
                            colorPicker.trigger('click');
                        } catch (e) {}
                    }
                    input.on('focus', openColorPicker);
                    input.on('click', function() { openColorPicker(); });
                } else if (changeType === 'number' || changeType === 'int' || changeType === 'integer' || changeType === 'float' || changeType === 'decimal') {
                    input = $('<input type="number" class="form-control" />')
                        .attr('id', fieldId)
                        .val(String(normalizedValue));
                    if (params.step) {
                        input.attr('step', params.step);
                    }
                    if (params.min) {
                        input.attr('min', params.min);
                    }
                    if (params.max) {
                        input.attr('max', params.max);
                    }
                    dataType = 'number';
                } else if (changeType === 'password') {
                    input = $('<input type="password" class="form-control" />')
                        .attr('id', fieldId)
                        .val('');
                    dataType = 'password';
                } else if (changeType === 'bool' || changeType === 'checkbox' || changeType === 'switch') {
                    group.removeClass('mb-3').addClass('form-check mb-3');
                    input = $('<input type="checkbox" class="form-check-input" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', column)
                        .attr('data-fastcrud-type', 'checkbox');
                    var isChecked = normalizedValue === true || normalizedValue === 1 || normalizedValue === '1' || normalizedValue === 'true';
                    input.prop('checked', isChecked);
                    var checkboxLabel = $('<label class="form-check-label"></label>')
                        .attr('for', fieldId)
                        .text(resolveFieldLabel(column));
                    group.append(input).append(checkboxLabel);
                    dataType = 'checkbox';
                } else {
                    input = $('<input type="text" class="form-control" />')
                        .attr('id', fieldId)
                        .val(String(normalizedValue));
                    dataType = 'text';
                }

                if (!input) {
                    return;
                }

                if (changeType !== 'bool' && changeType !== 'checkbox') {
                    group.append($('<label class="form-label"></label>').attr('for', labelForId).text(resolveFieldLabel(column)));
                    if (changeType === 'color' && compound) {
                        group.append(compound);
                    } else {
                        group.append(input);
                    }
                    if (changeType === 'image' || changeType === 'images' || changeType === 'file' || changeType === 'files') {
                        // Append the hidden value holder so it gets included on submit
                        group.append(hiddenInput);
                    }
                }

                if (params.placeholder && input.is('input, textarea')) {
                    input.attr('placeholder', params.placeholder);
                }

                if (params.maxlength && input.is('input, textarea')) {
                    input.attr('maxlength', params.maxlength);
                }

                if (params.class) {
                    input.addClass(params.class);
                }

                if (changeType !== 'image' && changeType !== 'images' && changeType !== 'file' && changeType !== 'files') {
                    input.attr('data-fastcrud-field', column);
                    input.attr('data-fastcrud-type', dataType);
                }

                if (behaviours.validation_required) {
                    input.attr('data-fastcrud-required', behaviours.validation_required);
                    if (!input.is(':checkbox')) {
                        input.attr('required', 'required');
                    }
                }

                if (behaviours.validation_pattern) {
                    input.attr('data-fastcrud-pattern', behaviours.validation_pattern);
                    if (typeof behaviours.validation_pattern === 'string' && behaviours.validation_pattern.length) {
                        var htmlPattern = behaviours.validation_pattern;
                        var delimiter = htmlPattern.charAt(0);
                        var lastIndex = htmlPattern.lastIndexOf(delimiter);
                        if ((delimiter === '/' || delimiter === '#') && lastIndex > 0) {
                            htmlPattern = htmlPattern.slice(1, lastIndex);
                        }
                        if (htmlPattern) {
                            input.attr('pattern', htmlPattern);
                        }
                    }
                }

                if (behaviours.readonly) {
                    if (input.is('select')) {
                        input.prop('disabled', true);
                    } else {
                        input.prop('readonly', true);
                    }
                    group.addClass('fastcrud-field-readonly');
                    if (changeType === 'color' && colorPicker) {
                        try { colorPicker.prop('disabled', true); } catch (e) {}
                    }
                    if (changeType === 'image' || changeType === 'images' || changeType === 'file' || changeType === 'files') {
                        // Prevent posting hidden value when field is readonly
                        try { hiddenInput.prop('disabled', true); } catch (e) {}
                    }
                }

                if (behaviours.disabled) {
                    input.prop('disabled', true);
                    group.addClass('fastcrud-field-disabled');
                    if (changeType === 'color' && colorPicker) {
                        try { colorPicker.prop('disabled', true); } catch (e) {}
                    }
                    if (changeType === 'image' || changeType === 'images' || changeType === 'file' || changeType === 'files') {
                        try { hiddenInput.prop('disabled', true); } catch (e) {}
                    }
                }

                if (behaviours.pass_var) {
                    input.attr('data-fastcrud-pass-var', behaviours.pass_var);
                }
                if (behaviours.pass_default) {
                    input.attr('data-fastcrud-pass-default', behaviours.pass_default);
                }
                if (behaviours.unique) {
                    input.attr('data-fastcrud-unique', '1');
                }

                container.append(group);

                if (changeType === 'image' || changeType === 'images') {
                    // Initialize FilePond after appending to DOM
                    withFilePondAssets(function() {
                        var fileInput = group.find('#' + $.escapeSelector(fieldId + '-file'));
                        var valueInput = group.find('#' + $.escapeSelector(fieldId));
                        if (!fileInput.length || !valueInput.length || !window.FilePond) {
                            return;
                        }

                        try {
                            var isMultipleImages = changeType === 'images';
                            if (isMultipleImages) {
                                setImageNamesOnInput(valueInput, valueInput.val());
                            } else {
                                var singleNames = parseImageNameList(valueInput.val());
                                if (singleNames.length > 1) {
                                    valueInput.val(singleNames[0]);
                                } else if (singleNames.length === 1) {
                                    valueInput.val(singleNames[0]);
                                } else {
                                    valueInput.val('');
                                }
                                clearImageNameMap(valueInput);
                            }

                            var currentNames = parseImageNameList(valueInput.val());
                            var initialFiles = currentNames.map(function(name) {
                                var url = toPublicUrl(name);
                                return {
                                    source: url,
                                    options: {
                                        type: 'local',
                                        file: { name: name },
                                        metadata: { poster: url, storedName: name }
                                    }
                                };
                            });

                            if (isMultipleImages) {
                                var initialMap = ensureImageNameMap(valueInput);
                                initialFiles.forEach(function(item) {
                                    var key = item && item.source ? item.source : '';
                                    var storedName = item && item.options && item.options.metadata ? item.options.metadata.storedName : '';
                                    if (key && storedName) {
                                        initialMap[key] = storedName;
                                    }
                                });
                            }

                            var stylePanelAspect = (params.panelAspectRatio || params.aspectRatio);
                            var pond = window.FilePond.create(fileInput.get(0), {
                                allowMultiple: isMultipleImages,
                                allowReorder: isMultipleImages,
                                allowImagePreview: true,
                                imagePreviewHeight: params.previewHeight ? Number(params.previewHeight) : 170,
                                allowFilePoster: true,
                                filePosterHeight: params.posterHeight ? Number(params.posterHeight) : 120,
                                stylePanelAspectRatio: stylePanelAspect || undefined,
                                credits: false,
                                files: initialFiles,
                                server: {
                                    process: function(fieldName, file, metadata, load, error, progress, abort) {
                                        var xhr = new XMLHttpRequest();
                                        xhr.open('POST', window.location.pathname);
                                        xhr.withCredentials = true;
                                        xhr.upload.onprogress = function(e) {
                                            progress(e.lengthComputable, e.loaded, e.total);
                                        };
                                        xhr.onload = function() {
                                            if (xhr.status < 200 || xhr.status >= 300) {
                                                error('Upload failed with status ' + xhr.status);
                                                return;
                                            }
                                            var response;
                                            var raw = xhr.responseText || '';
                                            try { response = JSON.parse(raw || '{}'); } catch (e) {
                                                error('Upload returned invalid JSON.');
                                                return;
                                            }
                                            if (!response || response.success !== true || !response.location) {
                                                error(response && response.error ? response.error : 'Upload failed.');
                                                return;
                                            }

                                            var storedName = '';
                                            if (response.name) {
                                                storedName = String(response.name);
                                            }
                                            if (!storedName && response.location) {
                                                storedName = extractFileName(response.location);
                                            }
                                            if (!storedName && file && file.name) {
                                                storedName = extractFileName(file.name);
                                            }

                                            if (storedName) {
                                                if (isMultipleImages) {
                                                    addImageNameToInput(valueInput, storedName);
                                                } else {
                                                    valueInput.val(storedName);
                                                }
                                                valueInput.trigger('change');
                                            }

                                            var serverKey = response.location ? String(response.location) : storedName;
                                            if (isMultipleImages && serverKey) {
                                                mapImageNameToKey(valueInput, serverKey, storedName);
                                            }

                                            load(serverKey || storedName || '');
                                        };
                                        xhr.onerror = function() { error('Upload failed due to a network error.'); };
                                        var formData = new FormData();
                                        formData.append('file', file, file.name);
                                        formData.append('fastcrud_ajax', '1');
                                        formData.append('action', 'upload_filepond');
                                        formData.append('kind', 'image');
                                        if (tableName) { formData.append('table', tableName); }
                                        if (tableId) { formData.append('id', tableId); }
                                        formData.append('column', saveColumn);
                                        xhr.send(formData);
                                        return { abort: function() { xhr.abort(); abort(); } };
                                    },
                                    fetch: function(url, load, error, progress, abort) {
                                        try {
                                            var target = url;
                                            if (target && typeof target === 'string' && !/^https?:\/\//i.test(target) && target.charAt(0) !== '/' && !/^blob:/i.test(target) && !/^data:/i.test(target)) {
                                                target = toPublicUrl(target);
                                            }
                                            var xhr = new XMLHttpRequest();
                                            xhr.open('GET', target);
                                            xhr.responseType = 'blob';
                                            xhr.onload = function() { load(xhr.response); };
                                            xhr.onerror = function() { error('Failed to fetch image.'); };
                                            xhr.onprogress = function(e) { progress(e.lengthComputable, e.loaded, e.total); };
                                            xhr.send();
                                            return { abort: function() { try { xhr.abort(); } catch (e) {} abort(); } };
                                        } catch (e) {
                                            error('Failed to fetch image.');
                                            abort();
                                        }
                                    },
                                    revert: function(uniqueId, load, error) {
                                        if (isMultipleImages) {
                                            removeImageNameFromInput(valueInput, uniqueId);
                                            removeImageNameForKey(valueInput, uniqueId);
                                            valueInput.trigger('change');
                                        } else {
                                            valueInput.val('');
                                            valueInput.trigger('change');
                                            clearImageNameMap(valueInput);
                                        }
                                        load();
                                    },
                                    load: function(source, load, error, progress, abort) {
                                        try {
                                            var target = source;
                                            if (target && typeof target === 'string' && !/^https?:\/\//i.test(target) && target.charAt(0) !== '/' && !/^blob:/i.test(target) && !/^data:/i.test(target)) {
                                                target = toPublicUrl(target);
                                            }
                                            var xhr = new XMLHttpRequest();
                                            xhr.open('GET', target);
                                            xhr.responseType = 'blob';
                                            xhr.onload = function() { load(xhr.response); };
                                            xhr.onerror = function() { error('Failed to load image.'); };
                                            xhr.onprogress = function(e) { progress(e.lengthComputable, e.loaded, e.total); };
                                            xhr.send();
                                            return { abort: function() { xhr.abort(); abort(); } };
                                        } catch (e) {
                                            error('Failed to load image.');
                                            abort();
                                        }
                                    }
                                }
                            });

                            // Apply width: use full width for multi-image grids by default
                            var pondWidth = (function() {
                                var explicit = (params.width || params.pondWidth || params.previewWidth || '').toString().trim();
                                if (explicit) return explicit;
                                return isMultipleImages ? '100%' : '200px';
                            })();
                            // Try to enforce max width robustly (some FilePond updates adjust inline styles)
                            try {
                                var applyPondWidth = function(el, value) {
                                    try {
                                        el.style.setProperty('max-width', String(value), 'important');
                                        el.style.setProperty('width', '100%', 'important');
                                        // ensure it can shrink from block-level width if needed
                                        el.style.setProperty('display', 'block');
                                    } catch (e) {}
                                };
                                applyPondWidth(pond.element, pondWidth);
                                // Re-apply on next tick and when pond is ready (in case FilePond mutates styles)
                                setTimeout(function() { applyPondWidth(pond.element, pondWidth); }, 0);
                                if (pond && typeof pond.on === 'function') {
                                    pond.on('ready', function() { applyPondWidth(pond.element, pondWidth); });
                                }
                            } catch (e) {}

                            // Provide a CSS fallback for single-image ponds so width sticks even if inline styles change
                            if (!isMultipleImages) {
                                try {
                                    $(pond.element).addClass('fastcrud-filepond-single');
                                    var singleCssId = 'fastcrud-filepond-single-css';
                                    if (!document.getElementById(singleCssId)) {
                                        var styleSingle = document.createElement('style');
                                        styleSingle.id = singleCssId;
                                        styleSingle.type = 'text/css';
                                        styleSingle.appendChild(document.createTextNode(
                                            '.fastcrud-filepond-single.filepond--root{max-width:200px !important;width:100% !important;display:block;}'
                                        ));
                                        document.head.appendChild(styleSingle);
                                    }
                                } catch (e) {}
                            }

                            // For multi-image fields, add a responsive grid layout for previews
                            if (isMultipleImages) {
                                try {
                                    $(pond.element).addClass('fastcrud-filepond-grid');
                                    var gridCssId = 'fastcrud-filepond-grid-css';
                                    if (!document.getElementById(gridCssId)) {
                                        var style = document.createElement('style');
                                        style.id = gridCssId;
                                        style.type = 'text/css';
                                        style.appendChild(document.createTextNode(
                                            '.fastcrud-filepond-grid .filepond--item{width:calc(33.333% - 0.5em)}' +
                                            '@media (max-width: 640px){.fastcrud-filepond-grid .filepond--item{width:calc(50% - 0.5em)}}' +
                                            '@media (min-width: 1200px){.fastcrud-filepond-grid .filepond--item{width:calc(20% - 0.5em)}}'
                                        ));
                                        document.head.appendChild(style);
                                    }
                                } catch (e) {}
                            }

                            if (isMultipleImages) {
                                pond.on('removefile', function(error, file) {
                                    if (!file) {
                                        return;
                                    }
                                    var key = file.serverId || file.source || '';
                                    var storedName = '';
                                    if (file.getMetadata && typeof file.getMetadata === 'function') {
                                        storedName = file.getMetadata('storedName') || '';
                                    }
                                    if (!storedName) {
                                        storedName = findImageNameForKey(valueInput, key) || extractFileName(key || file.filename);
                                    }
                                    if (storedName) {
                                        removeImageNameFromInput(valueInput, storedName);
                                        valueInput.trigger('change');
                                    }
                                    if (key) {
                                        removeImageNameForKey(valueInput, key);
                                    }
                                });
                                // Keep hidden input order in sync when user reorders items
                                pond.on('reorderfiles', function(files) {
                                    try {
                                        var ordered = [];
                                        (files || pond.getFiles() || []).forEach(function(item) {
                                            if (!item) return;
                                            var key = item.serverId || item.source || '';
                                            var name = '';
                                            if (item.getMetadata && typeof item.getMetadata === 'function') {
                                                name = item.getMetadata('storedName') || '';
                                            }
                                            if (!name) {
                                                name = findImageNameForKey(valueInput, key) || extractFileName(key || item.filename);
                                            }
                                            if (name && ordered.indexOf(name) === -1) {
                                                ordered.push(name);
                                            }
                                        });
                                        setImageNamesOnInput(valueInput, ordered);
                                        valueInput.trigger('change');
                                    } catch (e) {}
                                });
                            } else {
                                pond.on('removefile', function() {
                                    valueInput.val('').trigger('change');
                                    clearImageNameMap(valueInput);
                                });
                            }

                            pond.on('processfile', function(error, file) {
                                if (error || !file) {
                                    return;
                                }
                                var key = file.serverId || file.source || '';
                                var storedName = '';
                                if (file.getMetadata && typeof file.getMetadata === 'function') {
                                    storedName = file.getMetadata('storedName') || '';
                                }
                                if (!storedName) {
                                    storedName = findImageNameForKey(valueInput, key) || extractFileName(key || file.filename);
                                    if (file.setMetadata && storedName) {
                                        file.setMetadata('storedName', storedName, true);
                                    }
                                }
                                if (file.setMetadata) {
                                    var posterCandidate = key && (/^https?:\/\//i.test(key) || key.charAt(0) === '/' || /^blob:/i.test(key) || /^data:/i.test(key))
                                        ? key
                                        : (storedName ? toPublicUrl(storedName) : '');
                                    if (posterCandidate) {
                                        file.setMetadata('poster', posterCandidate, true);
                                    }
                                }
                                if (isMultipleImages && key && storedName) {
                                    mapImageNameToKey(valueInput, key, storedName);
                                }
                            });
                        } catch (e) {}
                    });
                } else if (changeType === 'file' || changeType === 'files') {
                    // Initialize FilePond for generic files (with optional multi-select, no image preview)
                    withFilePondAssets(function() {
                        var fileInput = group.find('#' + $.escapeSelector(fieldId + '-file'));
                        var valueInput = group.find('#' + $.escapeSelector(fieldId));
                        if (!fileInput.length || !valueInput.length || !window.FilePond) {
                            return;
                        }

                        try {
                            var isMultipleFiles = (changeType === 'files');
                            var initialFiles = [];
                            if (isMultipleFiles) {
                                var list = parseImageNameList(valueInput.val());
                                initialFiles = list.map(function(fname) {
                                    return { source: toPublicUrl(fname), options: { type: 'local', file: { name: fname } } };
                                });
                            } else {
                                var name = String(valueInput.val() || '').trim();
                                if (name) {
                                    initialFiles = [{ source: toPublicUrl(name), options: { type: 'local', file: { name: name } } }];
                                }
                            }

                            var pond = window.FilePond.create(fileInput.get(0), {
                                allowMultiple: isMultipleFiles,
                                allowReorder: isMultipleFiles,
                                allowImagePreview: false,
                                allowFilePoster: false,
                                credits: false,
                                files: initialFiles,
                                server: {
                                    process: function(fieldName, file, metadata, load, error, progress, abort) {
                                        var xhr = new XMLHttpRequest();
                                        xhr.open('POST', window.location.pathname);
                                        xhr.withCredentials = true;
                                        xhr.upload.onprogress = function(e) { progress(e.lengthComputable, e.loaded, e.total); };
                                        xhr.onload = function() {
                                            if (xhr.status < 200 || xhr.status >= 300) {
                                                error('Upload failed with status ' + xhr.status);
                                                return;
                                            }
                                            var response; var raw = xhr.responseText || '';
                                            try { response = JSON.parse(raw || '{}'); } catch (e) {
                                                error('Upload returned invalid JSON.');
                                                return;
                                            }
                                            if (!response || response.success !== true || !response.location) {
                                                error(response && response.error ? response.error : 'Upload failed.');
                                                return;
                                            }

                                            var storedName = '';
                                            if (response.name) { storedName = String(response.name); }
                                            if (!storedName && response.location) { storedName = extractFileName(response.location); }
                                            if (!storedName && file && file.name) { storedName = extractFileName(file.name); }

                                            if (storedName) {
                                                if (isMultipleFiles) { addImageNameToInput(valueInput, storedName); }
                                                else { valueInput.val(storedName); }
                                                valueInput.trigger('change');
                                            }
                                            var serverKey = response.location ? String(response.location) : storedName;
                                            if (isMultipleFiles && serverKey) { mapImageNameToKey(valueInput, serverKey, storedName); }
                                            load(serverKey || storedName || '');
                                        };
                                        xhr.onerror = function() { error('Upload failed due to a network error.'); };
                                        var formData = new FormData();
                                        formData.append('file', file, file.name);
                                        formData.append('fastcrud_ajax', '1');
                                        formData.append('action', 'upload_filepond');
                                        formData.append('kind', 'file');
                                        if (tableName) { formData.append('table', tableName); }
                                        if (tableId) { formData.append('id', tableId); }
                                        formData.append('column', saveColumn);
                                        xhr.send(formData);
                                        return { abort: function() { xhr.abort(); abort(); } };
                                    },
                                    fetch: function(url, load, error, progress, abort) {
                                        try {
                                            var target = url;
                                            if (target && typeof target === 'string' && !/^https?:\/\//i.test(target) && target.charAt(0) !== '/' && !/^blob:/i.test(target) && !/^data:/i.test(target)) {
                                                target = toPublicUrl(target);
                                            }
                                            var xhr = new XMLHttpRequest();
                                            xhr.open('GET', target);
                                            xhr.responseType = 'blob';
                                            xhr.onload = function() { if (xhr.status >= 200 && xhr.status < 300) { load(xhr.response); } else { error('Failed to fetch file'); } };
                                            xhr.onerror = function() { error('Network error while fetching file'); };
                                            xhr.send();
                                            return { abort: function() { try { xhr.abort(); } catch (e) {} abort(); } };
                                        } catch (e) { error('Failed to fetch file'); }
                                    }
                                }
                            });

                            if (isMultipleFiles) {
                                pond.on('removefile', function(error, file) {
                                    if (!file) { return; }
                                    var key = file.serverId || file.source || '';
                                    var storedName = '';
                                    if (file.getMetadata && typeof file.getMetadata === 'function') {
                                        storedName = file.getMetadata('storedName') || '';
                                    }
                                    if (!storedName) {
                                        storedName = findImageNameForKey(valueInput, key) || extractFileName(key || file.filename);
                                    }
                                    if (storedName) { removeImageNameFromInput(valueInput, storedName); valueInput.trigger('change'); }
                                    if (key) { removeImageNameForKey(valueInput, key); }
                                });
                                pond.on('reorderfiles', function(files) {
                                    try {
                                        var ordered = [];
                                        (files || pond.getFiles() || []).forEach(function(item) {
                                            if (!item) return;
                                            var key = item.serverId || item.source || '';
                                            var name = '';
                                            if (item.getMetadata && typeof item.getMetadata === 'function') {
                                                name = item.getMetadata('storedName') || '';
                                            }
                                            if (!name) { name = findImageNameForKey(valueInput, key) || extractFileName(key || item.filename); }
                                            if (name && ordered.indexOf(name) === -1) { ordered.push(name); }
                                        });
                                        setImageNamesOnInput(valueInput, ordered);
                                        valueInput.trigger('change');
                                    } catch (e) {}
                                });
                            } else {
                                pond.on('removefile', function() { valueInput.val('').trigger('change'); });
                            }

                            pond.on('processfile', function(error, file) {
                                if (error || !file) { return; }
                                var key = file.serverId || file.source || '';
                                var storedName = '';
                                if (file.getMetadata && typeof file.getMetadata === 'function') { storedName = file.getMetadata('storedName') || ''; }
                                if (!storedName) {
                                    storedName = findImageNameForKey(valueInput, key) || extractFileName(key || (file.filename || ''));
                                    if (file.setMetadata && storedName) { file.setMetadata('storedName', storedName, true); }
                                }
                                if (isMultipleFiles && key && storedName) { mapImageNameToKey(valueInput, key, storedName); }
                                if (storedName) {
                                    if (isMultipleFiles) { addImageNameToInput(valueInput, storedName); }
                                    else { valueInput.val(storedName); }
                                    valueInput.trigger('change');
                                }
                            });
                        } catch (e) {}
                    });
                }
            });

            if (useTabs) {
                var availableTabs = Object.keys(tabEntries);
                var activeTab = defaultTabName && tabEntries[defaultTabName] ? defaultTabName : (availableTabs[0] || null);
                if (activeTab) {
                    Object.keys(tabEntries).forEach(function(name) {
                        var entry = tabEntries[name];
                        if (!entry) {
                            return;
                        }

                        if (name === activeTab) {
                            entry.nav.addClass('active').attr('aria-selected', 'true');
                            entry.pane.addClass('show active');
                        } else {
                            entry.nav.removeClass('active').attr('aria-selected', 'false');
                            entry.pane.removeClass('show active');
                        }
                    });
                }
            }

            initializeRichEditors(editFieldsContainer);

            var behaviourSources = [formConfig.behaviours.pass_var || {}, formConfig.behaviours.pass_default || {}];
            var createdHiddenFields = {};
            behaviourSources.forEach(function(source) {
                Object.keys(source).forEach(function(fieldName) {
                    if (fieldName === rowPrimaryKeyColumn) {
                        return;
                    }

                    if (visibleFields.some(function(field) { return field.name === fieldName; })) {
                        return;
                    }

                    if (createdHiddenFields[fieldName]) {
                        return;
                    }

                    var behaviours = resolveBehavioursForField(fieldName, 'edit');
                    var value = '';
                    if (behaviours.pass_var) {
                        value = interpolateTemplate(behaviours.pass_var, templateContext);
                    } else if (behaviours.pass_default) {
                        value = interpolateTemplate(behaviours.pass_default, templateContext);
                    }

                    var hiddenId = editFormId + '-' + fieldName;
                    var hiddenField = $('<input type="hidden" />')
                        .attr('id', hiddenId)
                        .attr('data-fastcrud-field', fieldName)
                        .attr('data-fastcrud-type', 'hidden')
                        .val(value);
                    editForm.append(hiddenField);
                    templateContext[fieldName] = value;
                    createdHiddenFields[fieldName] = true;
                });
            });

            applyFieldErrors(currentFieldErrors);

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

            var viewPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!primaryKeyColumn && viewPrimaryKeyColumn) {
                primaryKeyColumn = viewPrimaryKeyColumn;
            }

            if (viewHeading.length) {
                var headingText = 'View Record';
                var primaryValue;
                if (Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined') {
                    primaryValue = row.__fastcrud_primary_value;
                } else if (viewPrimaryKeyColumn && typeof row[viewPrimaryKeyColumn] !== 'undefined') {
                    primaryValue = row[viewPrimaryKeyColumn];
                }

                if (typeof primaryValue !== 'undefined' && primaryValue !== null && String(primaryValue).length > 0) {
                    headingText += ' ' + primaryValue;
                }
                viewHeading.text(headingText);
            }

            var viewLayout = buildFormLayout('view');
            var viewFields = viewLayout.fields.length
                ? viewLayout.fields.slice()
                : columnsCache.map(function(column) {
                    return { name: column, tab: null };
                });

            var viewVisibleFields = [];
            viewFields.forEach(function(field) {
                if (field.name === viewPrimaryKeyColumn) {
                    return;
                }
                viewVisibleFields.push(field);
            });

            viewContentContainer.removeClass('list-group list-group-flush');

            var viewUsesTabs = Array.isArray(viewLayout.tabs) && viewLayout.tabs.length > 0 && viewVisibleFields.some(function(field) {
                return !!field.tab;
            });
            var viewTabsNav = null;
            var viewTabsContent = null;
            var viewTabEntries = {};
            var viewDefaultTab = viewLayout.defaultTab || null;
            var viewTabBaseId = tableId + '-view';

            if (viewUsesTabs) {
                viewTabsNav = $('<ul class="nav nav-tabs mb-3" role="tablist"></ul>');
                viewTabsContent = $('<div class="tab-content"></div>');
                viewContentContainer.append(viewTabsNav).append(viewTabsContent);
            } else {
                viewContentContainer.addClass('list-group list-group-flush');
            }

            function ensureViewTab(tabName) {
                if (!viewTabsNav || !viewTabsContent) {
                    return null;
                }

                if (!tabName) {
                    return null;
                }

                if (viewTabEntries[tabName]) {
                    return viewTabEntries[tabName];
                }

                var slug = makeSlug(tabName);
                var tabId = viewTabBaseId + '-tab-' + slug;
                var navItem = $('<li class="nav-item" role="presentation"></li>');
                var navButton = $('<button class="nav-link" data-bs-toggle="tab" type="button" role="tab"></button>')
                    .attr('id', tabId + '-tab')
                    .attr('data-bs-target', '#' + tabId)
                    .attr('aria-controls', tabId)
                    .attr('aria-selected', 'false')
                    .text(tabName);
                navItem.append(navButton);
                viewTabsNav.append(navItem);

                var pane = $('<div class="tab-pane fade" role="tabpanel"></div>')
                    .attr('id', tabId)
                    .attr('aria-labelledby', tabId + '-tab');
                var paneList = $('<div class="list-group list-group-flush"></div>');
                pane.append(paneList);
                viewTabsContent.append(pane);

                viewTabEntries[tabName] = { nav: navButton, pane: pane, list: paneList };
                return viewTabEntries[tabName];
            }

            var viewHasContent = false;
            viewVisibleFields.forEach(function(field) {
                var column = field.name;
                var container = viewContentContainer;

                if (viewUsesTabs) {
                    var targetTab = field.tab || viewDefaultTab || (viewLayout.tabs.length ? viewLayout.tabs[0] : null);
                    if (targetTab) {
                        var entry = ensureViewTab(targetTab);
                        if (entry && entry.list) {
                            container = entry.list;
                        } else if (entry && entry.pane) {
                            container = entry.pane;
                        }
                    }
                }

                var label = resolveFieldLabel(column);
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

                var valueElem = $('<div class="text-break"></div>');
                try {
                    var viewBehaviours = resolveBehavioursForField(column, 'view');
                    var changeMeta = (viewBehaviours && viewBehaviours.change_type) ? viewBehaviours.change_type : {};
                    var changeType = String((changeMeta && changeMeta.type) || '').toLowerCase();
                    if (changeType === 'file') {
                        var name = String(value || '').trim();
                        if (name) {
                            var href = toPublicUrl(name);
                            var link = $('<a></a>')
                                .attr('href', href)
                                .attr('target', '_blank')
                                .attr('rel', 'noopener noreferrer')
                                .text(displayValue);
                            valueElem.empty().append(link);
                        } else {
                            valueElem.text('N/A');
                        }
                    } else if (changeType === 'files') {
                        var filesList = parseImageNameList(value);
                        if (filesList && filesList.length) {
                            var listContainer = $('<div></div>');
                            filesList.forEach(function(item) {
                                var url = toPublicUrl(item);
                                var link = $('<a class="d-block mb-1"></a>')
                                    .attr('href', url)
                                    .attr('target', '_blank')
                                    .attr('rel', 'noopener noreferrer')
                                    .text(item);
                                listContainer.append(link);
                            });
                            valueElem.empty().append(listContainer);
                        } else {
                            valueElem.text('N/A');
                        }
                    } else if (changeType === 'image' || changeType === 'images') {
                        var list = parseImageNameList(value);
                        if (list && list.length) {
                            var grid = $('<div class="row row-cols-2 row-cols-sm-3 row-cols-lg-5 g-2"></div>');
                            list.forEach(function(item) {
                                var url = toPublicUrl(item);
                                var col = $('<div class="col"></div>');
                                var link = $('<a></a>')
                                    .attr('href', url)
                                    .attr('target', '_blank')
                                    .attr('rel', 'noopener noreferrer');
                                var img = $('<img class="img-fluid img-thumbnail" />')
                                    .attr('src', url)
                                    .attr('alt', item);
                                link.append(img);
                                col.append(link);
                                grid.append(col);
                            });
                            valueElem.empty().append(grid);
                        } else {
                            valueElem.text('N/A');
                        }
                    } else if (changeType === 'color') {
                        var c = String(value || '').trim();
                        if (!c) { c = '#000000'; }
                        var swatch = $('<span></span>')
                            .css({ display: 'inline-block', width: '14px', height: '14px', verticalAlign: 'middle', border: '1px solid rgba(0,0,0,.2)', backgroundColor: c });
                        valueElem.empty().append(swatch).append(' ').append(document.createTextNode(String(displayValue)));
                    } else if (changeType === 'json') {
                        var txt = String(value || '').trim();
                        if (!txt) {
                            valueElem.text('N/A');
                        } else {
                            try { txt = JSON.stringify(JSON.parse(txt), null, 2); } catch (e) {}
                            var pre = $('<pre class="mb-0 text-break"></pre>').text(txt);
                            valueElem.empty().append(pre);
                        }
                    } else {
                        valueElem.text(displayValue);
                    }
                } catch (e) {
                    valueElem.text(displayValue);
                }
                item.append(valueElem);
                container.append(item);
                viewHasContent = true;
            });

            if (viewUsesTabs) {
                var availableViewTabs = Object.keys(viewTabEntries);
                var activeViewTab = viewDefaultTab && viewTabEntries[viewDefaultTab]
                    ? viewDefaultTab
                    : (availableViewTabs[0] || null);
                if (activeViewTab) {
                    Object.keys(viewTabEntries).forEach(function(name) {
                        var entry = viewTabEntries[name];
                        if (!entry) {
                            return;
                        }

                        if (name === activeViewTab) {
                            entry.nav.addClass('active').attr('aria-selected', 'true');
                            entry.pane.addClass('show active');
                        } else {
                            entry.nav.removeClass('active').attr('aria-selected', 'false');
                            entry.pane.removeClass('show active');
                        }
                    });
                }
            }

            if (!viewHasContent) {
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
            currentFieldErrors = {};

            if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                window.tinymce.triggerSave();
            }

            var submitButton = editForm.find('button[type="submit"]');
            var originalText = submitButton.text();
            submitButton.prop('disabled', true).text('Saving...');

            // Ensure FilePond uploads (if any) finish before collecting values
            function waitForFilePondUploads() {
                return new Promise(function(resolve) {
                    if (!window.FilePond || typeof window.FilePond.find !== 'function') {
                        resolve();
                        return;
                    }
                    var inputs = editForm.find('input.fastcrud-filepond').toArray();
                    var ponds = window.FilePond.find(inputs);
                    if (!ponds || !ponds.length) {
                        resolve();
                        return;
                    }
                    var tasks = [];
                    ponds.forEach(function(pond) {
                        try {
                            tasks.push(pond.processFiles());
                        } catch (e) {}
                    });
                    if (!tasks.length) {
                        resolve();
                        return;
                    }
                    Promise.all(tasks).then(function() { resolve(); }).catch(function() { resolve(); });
                });
            }

            function collectAndSubmit() {
                var fields = {};
                var fieldErrors = {};
                var validationPassed = true;

                editForm.find('[data-fastcrud-field]').each(function() {
                    var input = $(this);
                    var column = input.data('fastcrudField');
                    if (!column) {
                        return;
                    }
                    if (column === primaryColumn) {
                        return;
                    }

                    if (input.prop('disabled')) {
                        return;
                    }

                    var type = String(input.data('fastcrudType') || input.attr('data-fastcrud-type') || 'text').toLowerCase();
                    var rawValue;
                    var valueForField = null;
                    var lengthForValidation = 0;

                    if (type === 'checkbox') {
                        rawValue = input.is(':checked');
                        valueForField = rawValue ? '1' : '0';
                        lengthForValidation = rawValue ? 1 : 0;
                    } else if (type === 'multiselect') {
                        rawValue = input.val() || [];
                        var trimmedValues = $.map(rawValue, function(item) {
                            if (item === null || typeof item === 'undefined') {
                                return null;
                            }
                            var normalized = String(item).trim();
                            return normalized.length ? normalized : null;
                        });
                        lengthForValidation = trimmedValues.length;
                        valueForField = trimmedValues.length ? trimmedValues.join(',') : null;
                    } else if (type === 'json') {
                        rawValue = input.val();
                        if (rawValue === null || typeof rawValue === 'undefined') {
                            valueForField = null;
                            lengthForValidation = 0;
                            clearInlineFieldError(input);
                        } else {
                            var jsonCandidate = String(rawValue).trim();
                            if (jsonCandidate === '') {
                                valueForField = null;
                                lengthForValidation = 0;
                                clearInlineFieldError(input);
                            } else {
                                try {
                                    JSON.parse(jsonCandidate);
                                    valueForField = jsonCandidate; // keep user formatting
                                    lengthForValidation = jsonCandidate.length;
                                    clearInlineFieldError(input);
                                } catch (e) {
                                    validationPassed = false;
                                    var message = buildJsonErrorMessage(e);
                                    fieldErrors[column] = message;
                                    setInlineFieldError(input, message);
                                    valueForField = jsonCandidate;
                                    lengthForValidation = jsonCandidate.length;
                                }
                            }
                        }
                    } else {
                        rawValue = input.val();
                        if (rawValue === null || typeof rawValue === 'undefined') {
                            valueForField = null;
                            lengthForValidation = 0;
                        } else {
                            var normalizedValue = String(rawValue).trim();
                            if (normalizedValue === '') {
                                valueForField = null;
                                lengthForValidation = 0;
                            } else {
                                valueForField = normalizedValue;
                                lengthForValidation = normalizedValue.length;
                            }
                        }
                    }

                    var requiredMin = parseInt(input.attr('data-fastcrud-required') || '', 10);
                    if (!Number.isNaN(requiredMin) && requiredMin > 0) {
                        if (lengthForValidation < requiredMin) {
                            validationPassed = false;
                            fieldErrors[column] = 'This field is required.';
                            input.addClass('is-invalid');
                        }
                    }

                    var patternRaw = input.attr('data-fastcrud-pattern');
                    if (patternRaw && valueForField !== null && valueForField !== '' && type !== 'multiselect') {
                        var regex = compileClientPattern(patternRaw);
                        if (regex && !regex.test(String(valueForField))) {
                            validationPassed = false;
                            fieldErrors[column] = 'Value does not match the expected format.';
                            input.addClass('is-invalid');
                        }
                    }

                    fields[column] = valueForField;
                });

                if (!validationPassed) {
                    currentFieldErrors = fieldErrors;
                    applyFieldErrors(fieldErrors);
                    showFormError('Please fix the highlighted fields.');
                    submitButton.prop('disabled', false).text(originalText);
                    return false;
                }

                var offcanvas = getEditOffcanvasInstance();
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
                        fields: JSON.stringify(fields),
                        config: JSON.stringify(clientConfig)
                    },
                    success: function(response) {
                        if (response && response.success) {
                            editSuccess.addClass('d-none');
                            currentFieldErrors = {};
                            try {
                                var key = rowCacheKey(primaryColumn, String(primaryValue));
                                if (response.row) {
                                    rowCache[key] = response.row;
                                } else if (rowCache[key]) {
                                    delete rowCache[key];
                                }
                            } catch (e) {}
                            loadTableData(currentPage);
                        } else {
                            var message = response && response.error ? response.error : 'Failed to update record.';
                            if (response && response.errors) {
                                currentFieldErrors = response.errors;
                                applyFieldErrors(response.errors);
                            }
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

            waitForFilePondUploads().then(collectAndSubmit);
            return false;
        }

        function getPkInfoFromElement(el) {
            var jqEl = $(el);
            var tr = jqEl.closest('tr');
            var pkCol = tr.attr('data-fastcrud-pk') || primaryKeyColumn || '';
            var pkVal = tr.attr('data-fastcrud-pk-value');
            if (!pkCol || typeof pkVal === 'undefined') { return null; }
            return { column: pkCol, value: pkVal };
        }

        function rowCacheKey(pkCol, pkVal) {
            return tableId + '::' + String(pkCol) + '::' + String(pkVal);
        }

        function fetchRowByPk(pkCol, pkVal) {
            var key = rowCacheKey(pkCol, pkVal);
            if (rowCache[key]) { return Promise.resolve(rowCache[key]); }
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: window.location.pathname,
                    type: 'GET',
                    dataType: 'json',
                    data: {
                        fastcrud_ajax: '1',
                        action: 'read',
                        table: tableName,
                        id: tableId,
                        primary_key_column: pkCol,
                        primary_key_value: pkVal,
                        config: JSON.stringify(clientConfig)
                    },
                    success: function(response) {
                        if (response && response.success && response.row) {
                            rowCache[key] = response.row;
                            resolve(response.row);
                        } else {
                            reject(new Error(response && response.error ? response.error : 'Record not found'));
                        }
                    },
                    error: function(_, __, error) {
                        reject(new Error(error || 'Failed to fetch record'));
                    }
                });
            });
        }

        table.on('click', '.fastcrud-view-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for viewing.'); return false; }
            // If any row is highlighted for editing, clear it when switching to view
            try {
                table.find('tbody tr.fastcrud-editing').each(function() {
                    var trEl = $(this);
                    var had = trEl.data('fastcrudHadClass');
                    if (had !== 1 && had !== '1') { trEl.removeClass(editHighlightClass); }
                    trEl.removeClass('fastcrud-editing').removeData('fastcrudHadClass');
                });
            } catch (e) {}
            fetchRowByPk(pk.column, pk.value)
                .then(function(row){ showViewPanel(row || {}); })
                .catch(function(err){ showError('Failed to load record: ' + (err && err.message ? err.message : err)); });
            return false;
        });

        table.on('click', '.fastcrud-edit-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            // Highlight the row being edited
            try {
                var tr = $(this).closest('tr');
                // Clear previous edit highlight, but only remove the configured class if we added it
                table.find('tbody tr.fastcrud-editing').each(function() {
                    var trEl = $(this);
                    var had = trEl.data('fastcrudHadClass');
                    if (had !== 1 && had !== '1') { trEl.removeClass(editHighlightClass); }
                    trEl.removeClass('fastcrud-editing').removeData('fastcrudHadClass');
                });
                var parts = String(editHighlightClass || '').split(/\s+/).filter(function(s){ return s.length > 0; });
                var hasAll = true;
                for (var i = 0; i < parts.length; i++) { if (!tr.hasClass(parts[i])) { hasAll = false; break; } }
                var alreadyHas = hasAll ? 1 : 0;
                tr.data('fastcrudHadClass', alreadyHas);
                tr.addClass('fastcrud-editing');
                if (!alreadyHas) { tr.addClass(editHighlightClass); }
            } catch (e) {}
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for editing.'); return false; }
            fetchRowByPk(pk.column, pk.value)
                .then(function(row){ showEditForm(row || {}); })
                .catch(function(err){ showError('Failed to load record: ' + (err && err.message ? err.message : err)); });
            return false;
        });

        // Inline toggle for boolean switches in grid
        table.on('change', 'input.fastcrud-bool-view', function(event) {
            var input = $(this);
            if (input.data('fastcrudUpdating')) {
                return;
            }
            var field = String(input.attr('data-fastcrud-field') || '').trim();
            var pkCol = String(input.attr('data-fastcrud-pk') || '').trim();
            var pkVal = input.attr('data-fastcrud-pk-value');
            if (!field || !pkCol || typeof pkVal === 'undefined') {
                // revert change if metadata is missing
                input.prop('checked', !input.is(':checked'));
                return;
            }

            var newValue = input.is(':checked') ? '1' : '0';
            var wasChecked = !input.is(':checked');
            input.prop('disabled', true);
            input.data('fastcrudUpdating', true);

            var payloadFields = {};
            payloadFields[field] = newValue;

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: {
                    fastcrud_ajax: '1',
                    action: 'update',
                    table: tableName,
                    id: tableId,
                    primary_key_column: pkCol,
                    primary_key_value: pkVal,
                    fields: JSON.stringify(payloadFields),
                    config: JSON.stringify(clientConfig)
                },
                success: function(response) {
                    if (response && response.success) {
                        loadTableData(currentPage);
                    } else {
                        var message = response && response.error ? response.error : 'Failed to update value.';
                        if (window.console && console.error) console.error('FastCrud toggle error:', message);
                        input.prop('checked', wasChecked);
                    }
                },
                error: function(_, __, error) {
                    if (window.console && console.error) console.error('FastCrud toggle request failed:', error);
                    input.prop('checked', wasChecked);
                },
                complete: function() {
                    input.prop('disabled', false);
                    input.removeData('fastcrudUpdating');
                }
            });
        });

        function requestDelete(row) {
            if (!row) {
                showError('Unable to determine primary key for deletion.');
                return;
            }

            var rowPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!rowPrimaryKeyColumn) {
                showError('Unable to determine primary key for deletion.');
                return;
            }

            var primaryValue;
            if (Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined') {
                primaryValue = row.__fastcrud_primary_value;
            } else {
                primaryValue = row[rowPrimaryKeyColumn];
            }

            if (!primaryKeyColumn) {
                primaryKeyColumn = rowPrimaryKeyColumn;
            }

            if (typeof primaryValue === 'undefined' || primaryValue === null || String(primaryValue).length === 0) {
                showError('Missing primary key value for selected record.');
                return;
            }

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
                    primary_key_column: rowPrimaryKeyColumn,
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

        function requestDuplicate(row) {
            if (!row) {
                showError('Unable to determine primary key for duplication.');
                return;
            }

            var rowPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!rowPrimaryKeyColumn) {
                showError('Unable to determine primary key for duplication.');
                return;
            }

            var primaryValue;
            if (Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined') {
                primaryValue = row.__fastcrud_primary_value;
            } else {
                primaryValue = row[rowPrimaryKeyColumn];
            }

            if (!primaryKeyColumn) {
                primaryKeyColumn = rowPrimaryKeyColumn;
            }

            if (typeof primaryValue === 'undefined' || primaryValue === null || String(primaryValue).length === 0) {
                showError('Missing primary key value for selected record.');
                return;
            }

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: {
                    fastcrud_ajax: '1',
                    action: 'duplicate',
                    table: tableName,
                    id: tableId,
                    primary_key_column: rowPrimaryKeyColumn,
                    primary_key_value: primaryValue,
                    config: JSON.stringify(clientConfig)
                },
                success: function(response) {
                    if (response && response.success) {
                        // Trigger event with both source and new rows
                        try {
                            table.trigger('fastcrud:duplicate', {
                                tableId: tableId,
                                row: row,
                                newRow: response.row || null
                            });
                        } catch (e) {}
                        loadTableData(currentPage);
                    } else {
                        var message = response && response.error ? response.error : 'Failed to duplicate record.';
                        showError(message);
                    }
                },
                error: function(_, __, error) {
                    showError('Failed to duplicate record: ' + error);
                }
            });
        }

        table.on('click', '.fastcrud-delete-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for deletion.'); return false; }
            requestDelete({ __fastcrud_primary_key: pk.column, __fastcrud_primary_value: pk.value });
            return false;
        });

        // Removed handler for unused custom buttons.

        table.on('click', '.fastcrud-duplicate-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for duplication.'); return false; }
            requestDuplicate({ __fastcrud_primary_key: pk.column, __fastcrud_primary_value: pk.value });
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
