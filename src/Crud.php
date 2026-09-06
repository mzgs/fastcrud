<?php
declare(strict_types=1);

namespace FastCrud;

use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

class Crud
{
    /**
     * @var array<string, callable|array<string, mixed>>
     */
    private static array $presets = [];

    private string $table;
    private PDO $connection;
    private string $id;
    private int $perPage = 5;
    /**
     * @var array<string, array<string, string>>
     */
    private array $relationOptionsCache = [];
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $queryBuilderFieldCache = null;
    /**
     * @var array<string, array{name: string, parent_column: string, parent_column_raw: string, foreign_column: string, crud: self}>
     */
    private array $nestedTables = [];
    private string $primaryKeyColumn = 'id';
    private ?string $cachedDriver = null;
    /**
     * @var array<string, array{expr: string, is_json: bool}>
     */
    private array $searchableColumnMeta = [];
    private const SUPPORTED_CONDITION_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'gt',
        'gte',
        'lt',
        'lte',
        'in',
        'not_in',
        'empty',
        'not_empty',
    ];

    private const QUERY_BUILDER_OPERATOR_CONFIG = [
        'equals'       => ['label' => 'Equals', 'requires_value' => true, 'multi' => false],
        'not_equals'   => ['label' => 'Does not equal', 'requires_value' => true, 'multi' => false],
        'contains'     => ['label' => 'Contains', 'requires_value' => true, 'multi' => false],
        'not_contains' => ['label' => 'Does not contain', 'requires_value' => true, 'multi' => false],
        'gt'           => ['label' => 'Greater than', 'requires_value' => true, 'multi' => false],
        'gte'          => ['label' => 'Greater than or equal', 'requires_value' => true, 'multi' => false],
        'lt'           => ['label' => 'Less than', 'requires_value' => true, 'multi' => false],
        'lte'          => ['label' => 'Less than or equal', 'requires_value' => true, 'multi' => false],
        'in'           => ['label' => 'Is one of', 'requires_value' => true, 'multi' => true],
        'not_in'       => ['label' => 'Is not one of', 'requires_value' => true, 'multi' => true],
        'empty'        => ['label' => 'Is empty', 'requires_value' => false, 'multi' => false],
        'not_empty'    => ['label' => 'Is not empty', 'requires_value' => false, 'multi' => false],
    ];

    private const SUPPORTED_SUMMARY_TYPES = ['sum', 'avg', 'min', 'max', 'count'];
    private const SUPPORTED_FORM_MODES = ['all', 'create', 'edit', 'view'];
    private const DEFAULT_FORM_MODE = 'all';
    private const SUPPORTED_FORM_DISPLAY_MODES = ['offcanvas', 'modal', 'side', 'inline'];
    private const DEFAULT_FORM_DISPLAY_MODE = 'offcanvas';
    private const SUPPORTED_SOFT_DELETE_MODES = ['timestamp', 'literal', 'expression'];
    private const SUPPORTED_COLUMN_FORMATTERS = ['money', 'date', 'datetime', 'badge', 'boolean'];
    private const DEFAULT_MULTI_LINK_BUTTON_CLASS = 'btn btn-sm btn-outline-secondary dropdown-toggle';
    private const DEFAULT_MULTI_LINK_MENU_CLASS = 'dropdown-menu dropdown-menu-end';
    private const DEFAULT_MULTI_LINK_CONTAINER_CLASS = 'btn-group';
    private const SESSION_CONFIG_NAMESPACE = '__fastcrud';
    private const SESSION_CONFIG_BUCKET = 'configs';
    private const SESSION_INACTIVITY_TIMEOUT = 14400;
    private const LIFECYCLE_EVENTS = [
        'before_insert',
        'after_insert',
        'before_update',
        'after_update',
        'before_delete',
        'after_delete',
        'before_fetch',
        'after_fetch',
        'before_read',
        'after_read',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $config = [
        'where'                   => [],
        'order_by'                => [],
        'sort_disabled'           => [],
        'no_quotes'               => [],
        'limit_options'           => [5, 10, 25, 50, 100],
        'limit_default'           => null,
        'compact_pagination'      => false,
        'search_columns'          => [],
        'search_default'          => null,
        'hide_search'             => false,
        'joins'                   => [],
        'relations'               => [],
        'custom_query'            => null,
        'subselects'              => [],
        'visible_columns'         => null,
        'column_visibility_rules' => [],
        'columns_reverse'         => false,
        'column_labels'           => [],
        'column_patterns'         => [],
        'column_formatters'       => [],
        'column_callbacks'        => [],
        'column_tooltips'         => [],
        'custom_columns'          => [],
        'field_callbacks'         => [],
        'custom_fields'           => [],
        'soft_delete'             => null,
        'row_ordering'            => [
            'enabled' => false,
            'column'  => null,
        ],
        'audit_log'               => [
            'enabled'          => false,
            'user_id'          => null,
            'user_id_callback' => null,
        ],
        'lifecycle_callbacks'     => [
            'before_insert' => [],
            'after_insert'  => [],
            'before_update' => [],
            'after_update'  => [],
            'before_delete' => [],
            'after_delete'  => [],
            'before_fetch'  => [],
            'after_fetch'   => [],
            'before_read'   => [],
            'after_read'    => [],
        ],
        'column_classes'          => [],
        'column_widths'           => [],
        'column_cuts'             => [],
        'default_column_truncate' => null,
        'column_highlights'       => [],
        'row_highlights'          => [],
        'action_button_sequence'  => [],
        'link_buttons'            => [],
        'multi_link_buttons'      => [],
        'inline_edit'             => [],
        'table_meta'              => [
            'title'               => null,
            'tooltip'             => null,
            'icon'                => null,
            'hide_title'          => false,
            'add'                 => true,
            'view'                => true,
            'view_condition'      => null,
            'edit'                => true,
            'edit_condition'      => null,
            'delete'              => true,
            'delete_condition'    => null,
            'duplicate'           => false,
            'duplicate_condition' => null,
            'batch_delete'        => false,
            'batch_delete_button' => false,
            'bulk_actions'        => [],
            'toolbar_actions'     => [],
            'toolbar_html'        => [],
            'delete_confirm'      => true,
            'export_csv'          => false,
        ],
        'column_summaries'        => [],
        'ui_text'                 => [],
        'style_overrides'         => [],
        'field_labels'            => [],
        'form_width'              => '30%',
        'form_display_mode'       => self::DEFAULT_FORM_DISPLAY_MODE,
        'select2'                 => false,
        'filters_enabled'         => true,
        'numbers_enabled'         => false,
        'primary_key'             => 'id',
        'query_builder'           => [
            'filters'     => [],
            'logic'       => 'AND',
            'sorts'       => [],
            'active_view' => null,
        ],
        'form'                    => [
            'layouts'      => [],
            'default_tabs' => [],
            'behaviours'   => [
                'change_type'         => [],
                'pass_var'            => [],
                'pass_default'        => [],
                'readonly'            => [],
                'disabled'            => [],
                'visible_if'          => [],
                'editable_if'         => [],
                'validation_required' => [],
                'validation_pattern'  => [],
                'max_length'          => [],
                'unique'              => [],
            ],
            'all_columns'  => [],
            'sections'     => [],
        ],
    ];

    /**
     * Initialize Crud and handle AJAX requests automatically.
     * Call this method early in your application bootstrap.
     *
     * @param PDO|array<string, mixed>|null $dbConfig Optional PDO instance or database configuration
     */
    public static function init(PDO|array|null $dbConfig = null): void
    {
        $shouldEnsureAuditTable = false;

        if ($dbConfig instanceof PDO) {
            Database::setConnection($dbConfig);
            $shouldEnsureAuditTable = true;
        } elseif ($dbConfig !== null) {
            CrudConfig::setDbConfig($dbConfig);
            $shouldEnsureAuditTable = true;
        } else {
            $config = CrudConfig::getDbConfig();
            $database = $config['database'] ?? null;
            $shouldEnsureAuditTable = is_string($database) && trim($database) !== '';
        }

        // Normal page initialization prepares the schema before the UI sends AJAX requests.
        if ($shouldEnsureAuditTable && !CrudAjax::isAjaxRequest()) {
            Database::ensureAuditLogTable();
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
        $this->connection = $connection ?? Database::connection();
        $this->id         = $this->generateId();

        $this->config['select2']                  = CrudConfig::$enable_select2;
        $this->config['filters_enabled']          = CrudConfig::$enable_filters;
        $this->config['numbers_enabled']          = CrudConfig::$enable_numbers;
        $this->config['table_meta']['hide_title'] = CrudConfig::$hide_table_title;

        $defaultTruncate = $this->resolveDefaultColumnTruncate();
        if ($defaultTruncate !== null) {
            $this->config['default_column_truncate'] = $defaultTruncate;
        }
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Register a reusable CRUD preset.
     *
     * Preset callbacks receive the Crud instance and may call any fluent API. Array presets
     * use the same serializable configuration shape that FastCRUD stores for AJAX requests.
     *
     * @param callable|array<string, mixed> $definition
     */
    public static function preset(string $name, callable|array $definition): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Preset name cannot be empty.');
        }

        self::$presets[$name] = $definition;
    }

    public static function hasPreset(string $name): bool
    {
        return isset(self::$presets[trim($name)]);
    }

    public static function clearPreset(?string $name = null): void
    {
        if ($name === null) {
            self::$presets = [];
            return;
        }

        unset(self::$presets[trim($name)]);
    }

    /**
     * Apply one or more named presets to this CRUD instance.
     *
     * @param string|array<int, string> $names
     */
    public function usePreset(string|array $names): self
    {
        $presetNames = is_array($names) ? $names : $this->normalizeList($names);
        if ($presetNames === []) {
            throw new InvalidArgumentException('usePreset requires at least one preset name.');
        }

        foreach ($presetNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            if (!array_key_exists($name, self::$presets)) {
                throw new InvalidArgumentException(sprintf('Unknown FastCRUD preset "%s".', $name));
            }

            $preset = self::$presets[$name];
            if (is_callable($preset)) {
                $result = $preset($this);
                if ($result !== null && !$result instanceof self) {
                    throw new RuntimeException(sprintf('FastCRUD preset "%s" must return null or a Crud instance.', $name));
                }
                continue;
            }

            if (is_array($preset)) {
                $this->applyClientConfig($preset);
                continue;
            }
        }

        return $this;
    }

    public function use_preset(string|array $names): self
    {
        return $this->usePreset($names);
    }

    /**
     * Override UI text for this table. Pass an associative array, or a single key/value pair.
     *
     * @param array<string, string>|string $texts
     */
    public function text(array|string $texts, ?string $value = null): self
    {
        $updates = is_array($texts) ? $texts : [$texts => $value ?? ''];
        foreach ($updates as $key => $text) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = $this->normalizeUiTextKey($key);
            if ($normalizedKey === '' || !is_scalar($text)) {
                continue;
            }

            $this->config['ui_text'][$normalizedKey] = (string) $text;
        }

        return $this;
    }

    /**
     * @param array<string, string>|string $texts
     */
    public function texts(array|string $texts, ?string $value = null): self
    {
        return $this->text($texts, $value);
    }

    /**
     * Override style classes for this table. Keys match CrudStyle property names.
     *
     * @param array<string, string>|string $styles
     */
    public function style(array|string $styles, ?string $value = null): self
    {
        $updates = is_array($styles) ? $styles : [$styles => $value ?? ''];
        foreach ($updates as $key => $style) {
            if (!is_string($key) || !is_scalar($style)) {
                continue;
            }

            $normalizedKey = trim($key);
            if ($normalizedKey === '') {
                continue;
            }

            $classList = $this->normalizeCssClassList((string) $style);
            if ($classList !== '') {
                $this->config['style_overrides'][$normalizedKey] = $classList;
            }
        }

        return $this;
    }

    /**
     * Apply theme settings with optional `styles` and `texts` keys.
     *
     * @param array<string, mixed> $theme
     */
    public function theme(array $theme): self
    {
        if (isset($theme['styles']) && is_array($theme['styles'])) {
            $this->style($theme['styles']);
        }

        if (isset($theme['style']) && is_array($theme['style'])) {
            $this->style($theme['style']);
        }

        if (isset($theme['texts']) && is_array($theme['texts'])) {
            $this->text($theme['texts']);
        }

        if (isset($theme['text']) && is_array($theme['text'])) {
            $this->text($theme['text']);
        }

        return $this;
    }

    private function getConfiguredTableTitle(): string
    {
        $tableMeta = $this->config['table_meta'] ?? [];
        if (isset($tableMeta['title']) && is_string($tableMeta['title']) && $tableMeta['title'] !== '') {
            return $tableMeta['title'];
        }

        return $this->makeTitle($this->table);
    }

    public function primary_key(string $column): self
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidArgumentException('Primary key column cannot be empty.');
        }

        $this->primaryKeyColumn      = $column;
        $this->config['primary_key'] = $column;

        return $this;
    }

    public static function fromAjax(string $table, ?string $id, array|string|null $configPayload, ?PDO $connection = null): self
    {
        $instance = new self($table, $connection);

        if ($id !== null && $id !== '') {
            $instance->id = $id;
        }

        $decoded = self::decodeClientConfigPayload($configPayload, true);

        if (is_array($decoded)) {
            $instance->applyClientConfig($decoded);
        }

        return $instance;
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function fromAjaxRequest(string $table, ?string $id, array $request, ?PDO $connection = null): self
    {
        $configReference = null;
        $configKeyUsed   = false;
        if (isset($request['config_key']) && is_string($request['config_key'])) {
            $candidate = trim($request['config_key']);
            if ($candidate !== '') {
                $configReference = $candidate;
                $configKeyUsed   = true;
            }
        }

        if ($configReference === null) {
            $configReference = $request['config'] ?? null;
        }

        $instance = new self($table, $connection);

        if ($id !== null && $id !== '') {
            $instance->id = $id;
        }

        $decoded = self::decodeClientConfigPayload($configReference, true);
        if ($configKeyUsed && !is_array($decoded)) {
            throw new RuntimeException('FastCrud session configuration expired. Reload the page.');
        }

        if (is_array($decoded)) {
            $instance->applyClientConfig($decoded);
        }

        $statePayload = self::decodeClientConfigPayload($request['config_state'] ?? null, false);
        if (is_array($statePayload)) {
            $instance->applyClientConfig($statePayload);
        }

        return $instance;
    }

    private static function ensureSessionStarted(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (headers_sent()) {
            return false;
        }

        @ini_set('session.gc_maxlifetime', (string) self::SESSION_INACTIVITY_TIMEOUT);
        @session_start();

        return session_status() === PHP_SESSION_ACTIVE;
    }

    private static function buildSessionConfigHash(array $payload): string
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            return hash('sha256', $encoded);
        } catch (JsonException) {
            return sha1(serialize($payload));
        }
    }

    private static function loadClientConfigPayloadFromSession(string $key): ?array
    {
        if ($key === '' || !self::ensureSessionStarted()) {
            return null;
        }

        $bucket = $_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET] ?? null;
        if (!is_array($bucket) || !isset($bucket[$key]) || !is_array($bucket[$key])) {
            return null;
        }

        $entry = $bucket[$key];
        if (isset($entry['payload']) && is_array($entry['payload'])) {
            return $entry['payload'];
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeClientConfigPayload(array|string|null $payload, bool $allowSessionLookup): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (!is_string($payload)) {
            return null;
        }

        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (JsonException) {
            // Fall through to optional session lookup.
        }

        if ($allowSessionLookup) {
            return self::loadClientConfigPayloadFromSession($payload);
        }

        return null;
    }

    private function storeClientConfigPayloadInSession(array $payload): ?string
    {
        if (!self::ensureSessionStarted()) {
            return null;
        }

        $hash = self::buildSessionConfigHash($payload);
        $key = 'fc_' . substr($hash, 0, 40);

        if (!isset($_SESSION[self::SESSION_CONFIG_NAMESPACE]) || !is_array($_SESSION[self::SESSION_CONFIG_NAMESPACE])) {
            $_SESSION[self::SESSION_CONFIG_NAMESPACE] = [];
        }

        if (
            !isset($_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET])
            || !is_array($_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET])
        ) {
            $_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET] = [];
        }

        $_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET][$key] = [
            'payload'    => $payload,
            'updated_at' => time(),
        ];

        if (count($_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET]) > 100) {
            uasort(
                $_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET],
                static function ($left, $right): int {
                    $leftTime = is_array($left) && isset($left['updated_at']) ? (int) $left['updated_at'] : 0;
                    $rightTime = is_array($right) && isset($right['updated_at']) ? (int) $right['updated_at'] : 0;

                    return $leftTime <=> $rightTime;
                }
            );

            while (count($_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET]) > 100) {
                array_shift($_SESSION[self::SESSION_CONFIG_NAMESPACE][self::SESSION_CONFIG_BUCKET]);
            }
        }

        return $key;
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

        $this->perPage                 = $perPage;
        $this->config['limit_default'] = $perPage;
        return $this;
    }

    public function setFormWidth(string $width): self
    {
        $this->config['form_width'] = $width;
        return $this;
    }

    public function setFormDisplayMode(string $mode, ?string $width = null): self
    {
        $this->config['form_display_mode'] = $this->normalizeFormDisplayMode($mode);
        if ($width !== null) {
            $this->setFormWidth($width);
        }
        return $this;
    }

    public function form_display_mode(string $mode, ?string $width = null): self
    {
        return $this->setFormDisplayMode($mode, $width);
    }

    private function normalizeFormDisplayMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        if (in_array($normalized, self::SUPPORTED_FORM_DISPLAY_MODES, true)) {
            return $normalized;
        }

        throw new InvalidArgumentException(
            'Unsupported form display mode. Use offcanvas, modal, side, or inline.'
        );
    }

    /**
     * Enable inline edit for the given fields (excluding boolean switches which are always inline).
     *
     * @param string|array<int, string> $fields
     */
    public function inline_edit(string|array $fields): self
    {
        $list = $this->normalizeList($fields);
        $map  = [];
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized !== '') {
                $map[$normalized] = true;
            }
        }
        $this->config['inline_edit'] = $map;
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

    /**
     * @param mixed $payload
     * @return array<string, string>
     */
    private function normalizeScalarOptions(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $options = [];
        foreach ($payload as $key => $value) {
            $key = is_string($key) ? trim($key) : '';
            if ($key !== '' && is_scalar($value)) {
                $options[$key] = (string) $value;
            }
        }

        return $options;
    }

    private function normalizeUiTextKey(string $key): string
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return '';
        }

        $key = str_replace(['-', ' '], '_', $key);
        $key = preg_replace('/[^a-z0-9_.]+/', '_', $key);
        if (!is_string($key)) {
            return '';
        }

        return trim($key, '_');
    }

    /**
     * @param array<string, mixed> $options
     * @return array{type: string, options: array<string, mixed>}
     */
    private function normalizeColumnFormatterConfig(string $type, array $options = []): array
    {
        $normalizedOptions = [];

        foreach ($options as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $normalizedOptions[$key] = $value;
                continue;
            }

            if ($key === 'map' && is_array($value)) {
                $normalizedMap = [];
                foreach ($value as $mapValue => $definition) {
                    if (!is_scalar($mapValue)) {
                        continue;
                    }

                    $mapKey = (string) $mapValue;
                    if (is_scalar($definition) || $definition === null) {
                        $normalizedMap[$mapKey] = $definition;
                        continue;
                    }

                    if (is_array($definition)) {
                        $entry = [];
                        foreach (['label', 'class', 'text_class'] as $entryKey) {
                            if (isset($definition[$entryKey]) && is_scalar($definition[$entryKey])) {
                                $entry[$entryKey] = (string) $definition[$entryKey];
                            }
                        }
                        if ($entry !== []) {
                            $normalizedMap[$mapKey] = $entry;
                        }
                    }
                }

                $normalizedOptions['map'] = $normalizedMap;
            }
        }

        return [
            'type'    => $type,
            'options' => $normalizedOptions,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{display: string, html: string|null}|null
     */
    private function applyColumnFormatter(string $column, mixed $value, array $row): ?array
    {
        $formatter = $this->config['column_formatters'][$column] ?? null;
        if (!is_array($formatter)) {
            return null;
        }

        $type = isset($formatter['type']) ? strtolower((string) $formatter['type']) : '';
        if (!in_array($type, self::SUPPORTED_COLUMN_FORMATTERS, true)) {
            return null;
        }

        $options = isset($formatter['options']) && is_array($formatter['options'])
            ? $formatter['options']
            : [];

        return match ($type) {
            'money' => $this->formatMoneyValue($value, $options),
            'date', 'datetime' => $this->formatDateValue($value, $options),
            'badge' => $this->formatBadgeValue($value, $options),
            'boolean' => $this->formatBooleanValue($value, $options),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $options
     * @return array{display: string, html: string|null}
     */
    private function formatMoneyValue(mixed $value, array $options): array
    {
        if ($value === null || $value === '') {
            return ['display' => '', 'html' => null];
        }

        if (!is_numeric($value)) {
            return ['display' => $this->stringifyValue($value), 'html' => null];
        }

        $precision = isset($options['precision']) && is_numeric($options['precision'])
            ? max(0, (int) $options['precision'])
            : 2;
        $decimalSeparator  = isset($options['decimal_separator']) && is_scalar($options['decimal_separator']) ? (string) $options['decimal_separator'] : '.';
        $thousandsSeparator = isset($options['thousands_separator']) && is_scalar($options['thousands_separator']) ? (string) $options['thousands_separator'] : ',';
        $currency          = isset($options['currency']) && is_scalar($options['currency']) ? trim((string) $options['currency']) : '';
        $position          = isset($options['position']) && strtolower((string) $options['position']) === 'after' ? 'after' : 'before';

        $formatted = number_format((float) $value, $precision, $decimalSeparator, $thousandsSeparator);
        if ($currency !== '') {
            $space     = isset($options['space']) ? (bool) $options['space'] : true;
            $separator = $space ? ' ' : '';
            $formatted = $position === 'after'
                ? $formatted . $separator . $currency
                : $currency . $separator . $formatted;
        }

        return ['display' => $formatted, 'html' => null];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{display: string, html: string|null}
     */
    private function formatDateValue(mixed $value, array $options): array
    {
        if ($value === null || $value === '') {
            return ['display' => '', 'html' => null];
        }

        $raw = $this->stringifyValue($value);
        try {
            $date = is_numeric($raw)
                ? (new \DateTimeImmutable())->setTimestamp((int) $raw)
                : new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return ['display' => $raw, 'html' => null];
        }

        $format = isset($options['format']) && is_scalar($options['format']) && trim((string) $options['format']) !== ''
            ? (string) $options['format']
            : 'Y-m-d';

        return ['display' => $date->format($format), 'html' => null];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{display: string, html: string|null}
     */
    private function formatBadgeValue(mixed $value, array $options): array
    {
        $raw       = $this->stringifyValue($value);
        $map       = isset($options['map']) && is_array($options['map']) ? $options['map'] : [];
        $entry     = array_key_exists($raw, $map) ? $map[$raw] : null;
        $label     = $raw;
        $className = isset($options['default_class']) && is_scalar($options['default_class'])
            ? trim((string) $options['default_class'])
            : 'bg-secondary';

        if (is_scalar($entry) && $entry !== '') {
            $className = (string) $entry;
        } elseif (is_array($entry)) {
            if (isset($entry['label']) && is_scalar($entry['label'])) {
                $label = (string) $entry['label'];
            }
            if (isset($entry['class']) && is_scalar($entry['class']) && trim((string) $entry['class']) !== '') {
                $className = (string) $entry['class'];
            }
        }

        if ($label === '' && isset($options['empty_label']) && is_scalar($options['empty_label'])) {
            $label = (string) $options['empty_label'];
        }

        $className = $this->normalizeCssClassList($className);
        if ($className === '') {
            $className = 'bg-secondary';
        }
        if (!str_contains(' ' . $className . ' ', ' badge ')) {
            $className = 'badge ' . $className;
        }

        $html = '<span class="' . $this->escapeHtml($className) . '">' . $this->escapeHtml($label) . '</span>';

        return ['display' => $label, 'html' => $html];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{display: string, html: string|null}
     */
    private function formatBooleanValue(mixed $value, array $options): array
    {
        $truthy = $this->isTruthy($value);
        $label  = $truthy
            ? (isset($options['true_label']) && is_scalar($options['true_label']) ? (string) $options['true_label'] : 'Yes')
            : (isset($options['false_label']) && is_scalar($options['false_label']) ? (string) $options['false_label'] : 'No');

        $className = $truthy
            ? (isset($options['true_class']) && is_scalar($options['true_class']) ? (string) $options['true_class'] : 'bg-success')
            : (isset($options['false_class']) && is_scalar($options['false_class']) ? (string) $options['false_class'] : 'bg-secondary');
        $className = $this->normalizeCssClassList($className);
        if ($className === '') {
            $className = $truthy ? 'bg-success' : 'bg-secondary';
        }
        if (!str_contains(' ' . $className . ' ', ' badge ')) {
            $className = 'badge ' . $className;
        }

        $html = '<span class="' . $this->escapeHtml($className) . '">' . $this->escapeHtml($label) . '</span>';

        return ['display' => $label, 'html' => $html];
    }

    /**
     * @return array{length:int,suffix:string}|null
     */
    private function resolveDefaultColumnTruncate(): ?array
    {
        return $this->normalizeDefaultColumnTruncateValue(
            CrudConfig::$default_column_truncate,
            'CrudConfig::$default_column_truncate'
        );
    }

    /**
     * @param array{length:mixed,suffix?:mixed}|int|null $value
     * @return array{length:int,suffix:string}|null
     */
    private function normalizeDefaultColumnTruncateValue(array|int|null $value, string $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            $length = $value;
            $suffix = '…';
        } elseif (is_array($value)) {
            if (!isset($value['length'])) {
                throw new InvalidArgumentException($context . ' requires a length.');
            }

            $length = (int) $value['length'];
            $suffix = array_key_exists('suffix', $value) ? (string) $value['suffix'] : '…';
        } else {
            throw new InvalidArgumentException($context . ' must be an integer or array.');
        }

        if ($length < 1) {
            throw new InvalidArgumentException($context . ' length must be at least 1.');
        }

        return [
            'length' => $length,
            'suffix' => $suffix,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeLinkButtonConfigPayload(array $payload): ?array
    {
        $url = isset($payload['url']) ? trim((string) $payload['url']) : '';
        if ($url === '') {
            return null;
        }

        $iconRaw   = isset($payload['icon']) ? (string) $payload['icon'] : '';
        $iconClass = $this->normalizeCssClassList($iconRaw);
        if ($iconClass === '') {
            return null;
        }

        $buttonClass = null;
        if (array_key_exists('button_class', $payload) && $payload['button_class'] !== null) {
            $buttonClassRaw   = (string) $payload['button_class'];
            $normalizedButton = $this->normalizeCssClassList($buttonClassRaw);
            if ($normalizedButton !== '') {
                $buttonClass = $normalizedButton;
            }
        }

        $label = null;
        if (array_key_exists('label', $payload) && $payload['label'] !== null) {
            $labelString = trim((string) $payload['label']);
            if ($labelString !== '') {
                $label = $labelString;
            }
        }

        $options = $this->normalizeScalarOptions($payload['options'] ?? null);

        $styles             = $this->getStyleDefaults();
        $defaultButtonClass = $styles['link_button_class'] ?? 'btn btn-sm btn-outline-secondary';

        return [
            'url'          => $url,
            'icon'         => $iconClass,
            'label'        => $label,
            'button_class' => $buttonClass ?? $defaultButtonClass,
            'options'      => $options,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLinkButtonConfigList(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $button = $this->normalizeLinkButtonConfigPayload($entry);
            if ($button !== null) {
                $normalized[] = $button;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{html:string}|null
     */
    private function normalizeToolbarHtmlConfigPayload(array $payload): ?array
    {
        $html = isset($payload['html']) ? trim((string) $payload['html']) : '';
        if ($html === '') {
            return null;
        }

        return ['html' => $html];
    }

    /**
     * @return array<int, array{html:string}>
     */
    private function normalizeToolbarHtmlList(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = [$payload];
        }

        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $entry) {
            if (is_string($entry)) {
                $entry = ['html' => $entry];
            }

            if (!is_array($entry)) {
                continue;
            }

            $html = $this->normalizeToolbarHtmlConfigPayload($entry);
            if ($html !== null) {
                $normalized[] = $html;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeMultiLinkButtonConfigPayload(array $payload): ?array
    {
        $itemsRaw = $payload['items'] ?? null;
        if (!is_array($itemsRaw) || $itemsRaw === []) {
            return null;
        }

        $items            = [];
        $hasActionItem    = false;
        $hasDuplicateItem = false;
        $hasDeleteItem    = false;
        foreach ($itemsRaw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryType = null;
            if (isset($entry['type'])) {
                $entryTypeCandidate = strtolower(trim((string) $entry['type']));
                if ($entryTypeCandidate !== '') {
                    $entryType = $entryTypeCandidate;
                }
            }

            $dividerTitle = null;
            if (array_key_exists('title', $entry) && $entry['title'] !== null) {
                $titleCandidate = trim((string) $entry['title']);
                if ($titleCandidate !== '') {
                    $dividerTitle = $titleCandidate;
                }
            }

            $isDivider = false;
            if ($entryType === 'divider') {
                $isDivider = true;
            } elseif (array_key_exists('divider', $entry) && $entry['divider']) {
                $isDivider = true;
            } elseif ($entry === [] || ($entryType === null && !isset($entry['url']) && !isset($entry['label']))) {
                $isDivider = true;
            }

            if ($isDivider) {
                $items[] = [
                    'type'  => 'divider',
                    'title' => $dividerTitle,
                ];
                continue;
            }

            if ($entryType === 'duplicate') {
                $label = $this->uiText('duplicate');
                if (array_key_exists('label', $entry) && $entry['label'] !== null) {
                    $labelCandidate = trim((string) $entry['label']);
                    if ($labelCandidate !== '') {
                        $label = $labelCandidate;
                    }
                }

                $icon        = null;
                $defaultIcon = $this->normalizeCssClassList((string) (CrudStyle::$duplicate_action_icon ?? ''));
                if ($defaultIcon !== '') {
                    $icon = $defaultIcon;
                }
                if (array_key_exists('icon', $entry) && $entry['icon'] !== null) {
                    $iconRaw   = (string) $entry['icon'];
                    $iconClass = $this->normalizeCssClassList($iconRaw);
                    if ($iconClass !== '') {
                        $icon = $iconClass;
                    }
                }

                $itemOptions = $this->normalizeScalarOptions($entry['options'] ?? null);

                $items[]          = [
                    'type'    => 'duplicate',
                    'label'   => $label,
                    'icon'    => $icon,
                    'options' => $itemOptions,
                ];
                $hasActionItem    = true;
                $hasDuplicateItem = true;
                continue;
            }

            if ($entryType === 'delete') {
                $label = $this->uiText('delete');
                if (array_key_exists('label', $entry) && $entry['label'] !== null) {
                    $labelCandidate = trim((string) $entry['label']);
                    if ($labelCandidate !== '') {
                        $label = $labelCandidate;
                    }
                }

                $icon        = null;
                $defaultIcon = $this->normalizeCssClassList((string) (CrudStyle::$delete_action_icon ?? ''));
                if ($defaultIcon !== '') {
                    $icon = $defaultIcon;
                }
                if (array_key_exists('icon', $entry) && $entry['icon'] !== null) {
                    $iconRaw   = (string) $entry['icon'];
                    $iconClass = $this->normalizeCssClassList($iconRaw);
                    if ($iconClass !== '') {
                        $icon = $iconClass;
                    }
                }

                $itemOptions = $this->normalizeScalarOptions($entry['options'] ?? null);

                $items[]       = [
                    'type'    => 'delete',
                    'label'   => $label,
                    'icon'    => $icon,
                    'options' => $itemOptions,
                ];
                $hasActionItem = true;
                $hasDeleteItem = true;
                continue;
            }

            if ($entryType === 'input') {
                $url = isset($entry['url']) ? trim((string) $entry['url']) : '';
                if ($url === '') {
                    continue;
                }

                $labelRaw = isset($entry['label']) ? (string) $entry['label'] : '';
                $label    = trim($labelRaw);
                if ($label === '') {
                    continue;
                }

                $inputName = 'exampleinput';
                if (array_key_exists('input_name', $entry) && $entry['input_name'] !== null) {
                    $nameCandidate = trim((string) $entry['input_name']);
                    if ($nameCandidate !== '') {
                        $inputName = $nameCandidate;
                    }
                }

                $prompt = null;
                if (array_key_exists('prompt', $entry) && $entry['prompt'] !== null) {
                    $promptCandidate = trim((string) $entry['prompt']);
                    if ($promptCandidate !== '') {
                        $prompt = $promptCandidate;
                    }
                }

                $icon = null;
                if (array_key_exists('icon', $entry) && $entry['icon'] !== null) {
                    $iconRaw   = (string) $entry['icon'];
                    $iconClass = $this->normalizeCssClassList($iconRaw);
                    if ($iconClass !== '') {
                        $icon = $iconClass;
                    }
                }

                $itemOptions = $this->normalizeScalarOptions($entry['options'] ?? null);

                $items[]       = [
                    'type'       => 'input',
                    'url'        => $url,
                    'label'      => $label,
                    'icon'       => $icon,
                    'options'    => $itemOptions,
                    'input_name' => $inputName,
                    'prompt'     => $prompt,
                ];
                $hasActionItem = true;
                continue;
            }

            $url = isset($entry['url']) ? trim((string) $entry['url']) : '';
            if ($url === '') {
                continue;
            }

            $labelRaw = isset($entry['label']) ? (string) $entry['label'] : '';
            $label    = trim($labelRaw);
            if ($label === '') {
                continue;
            }

            $icon = null;
            if (array_key_exists('icon', $entry) && $entry['icon'] !== null) {
                $iconRaw   = (string) $entry['icon'];
                $iconClass = $this->normalizeCssClassList($iconRaw);
                if ($iconClass !== '') {
                    $icon = $iconClass;
                }
            }

            $itemOptions = $this->normalizeScalarOptions($entry['options'] ?? null);

            $items[]       = [
                'type'    => 'link',
                'url'     => $url,
                'label'   => $label,
                'icon'    => $icon,
                'options' => $itemOptions,
            ];
            $hasActionItem = true;
        }

        if ($items === [] || !$hasActionItem) {
            return null;
        }

        if ($hasDuplicateItem) {
            $this->config['__fastcrud_duplicate_override'] = true;
        }

        if ($hasDeleteItem) {
            $this->config['__fastcrud_delete_override'] = true;
        }

        $buttonRaw = [];
        if (isset($payload['button']) && is_array($payload['button'])) {
            $buttonRaw = $payload['button'];
        }

        $buttonIcon = '';
        if (array_key_exists('icon', $buttonRaw) && $buttonRaw['icon'] !== null) {
            $iconCandidate  = (string) $buttonRaw['icon'];
            $normalizedIcon = $this->normalizeCssClassList($iconCandidate);
            if ($normalizedIcon !== '') {
                $buttonIcon = $normalizedIcon;
            }
        }

        $buttonLabel = null;
        if (array_key_exists('label', $buttonRaw) && $buttonRaw['label'] !== null) {
            $labelCandidate = trim((string) $buttonRaw['label']);
            if ($labelCandidate !== '') {
                $buttonLabel = $labelCandidate;
            }
        }

        $buttonClass = null;
        if (array_key_exists('button_class', $buttonRaw) && $buttonRaw['button_class'] !== null) {
            $buttonClassRaw   = (string) $buttonRaw['button_class'];
            $normalizedButton = $this->normalizeCssClassList($buttonClassRaw);
            if ($normalizedButton !== '') {
                $buttonClass = $normalizedButton;
            }
        }

        $menuClass = null;
        if (array_key_exists('menu_class', $buttonRaw) && $buttonRaw['menu_class'] !== null) {
            $menuClassRaw   = (string) $buttonRaw['menu_class'];
            $normalizedMenu = $this->normalizeCssClassList($menuClassRaw);
            if ($normalizedMenu !== '') {
                $menuClass = $normalizedMenu;
            }
        }

        $containerClass = null;
        if (array_key_exists('container_class', $buttonRaw) && $buttonRaw['container_class'] !== null) {
            $containerClassRaw   = (string) $buttonRaw['container_class'];
            $normalizedContainer = $this->normalizeCssClassList($containerClassRaw);
            if ($normalizedContainer !== '') {
                $containerClass = $normalizedContainer;
            }
        }

        $buttonOptions = $this->normalizeScalarOptions($buttonRaw['options'] ?? null);
        $enableFilter  = array_key_exists('enable_filter', $buttonRaw)
            ? (bool) $buttonRaw['enable_filter']
            : false;
        $highlightRowOnOpen = array_key_exists('highlight_row_on_open', $buttonRaw)
            ? (bool) $buttonRaw['highlight_row_on_open']
            : false;

        return [
            'button' => [
                'icon'            => $buttonIcon,
                'label'           => $buttonLabel,
                'button_class'    => $buttonClass ?? self::DEFAULT_MULTI_LINK_BUTTON_CLASS,
                'menu_class'      => $menuClass ?? self::DEFAULT_MULTI_LINK_MENU_CLASS,
                'container_class' => $containerClass ?? self::DEFAULT_MULTI_LINK_CONTAINER_CLASS,
                'options'         => $buttonOptions,
                'enable_filter'   => $enableFilter,
                'highlight_row_on_open' => $highlightRowOnOpen,
            ],
            'items'  => $items,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMultiLinkButtonConfigList(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $button = $this->normalizeMultiLinkButtonConfigPayload($entry);
            if ($button !== null) {
                $normalized[] = $button;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $sequence
     * @return array<int, string>
     */
    private function normalizeActionButtonSequence(mixed $sequence): array
    {
        if (!is_array($sequence)) {
            return [];
        }

        $normalized = [];
        foreach ($sequence as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $type = strtolower(trim($entry));
            if ($type === 'link' || $type === 'multi') {
                $normalized[] = $type;
            }
        }

        return $normalized;
    }

    private function appendActionButtonSequence(string $type): void
    {
        $type = strtolower(trim($type));
        if ($type !== 'link' && $type !== 'multi') {
            return;
        }

        if (!isset($this->config['action_button_sequence']) || !is_array($this->config['action_button_sequence'])) {
            $this->config['action_button_sequence'] = [];
        }

        $this->config['action_button_sequence'][] = $type;
    }

    /**
     * @return array<int, string>
     */
    private function getActionButtonSequence(): array
    {
        $sequence = $this->config['action_button_sequence'] ?? [];
        if (!is_array($sequence)) {
            $sequence = [];
        }

        $normalized                             = $this->normalizeActionButtonSequence($sequence);
        $this->config['action_button_sequence'] = $normalized;

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getNormalizedLinkButtonsConfig(): array
    {
        $stored = $this->config['link_buttons'] ?? [];
        if (!is_array($stored)) {
            $stored = [];
        }

        if (isset($this->config['link_button']) && is_array($this->config['link_button'])) {
            $stored[] = $this->config['link_button'];
            unset($this->config['link_button']);
        }

        $normalizedList = $this->normalizeLinkButtonConfigList($stored);

        $this->config['link_buttons'] = $normalizedList;

        return $normalizedList;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getNormalizedToolbarActionsConfig(): array
    {
        $tableMeta = $this->config['table_meta'] ?? [];
        $stored    = $tableMeta['toolbar_actions'] ?? [];
        if (!is_array($stored)) {
            $stored = [];
        }

        $normalized = $this->normalizeLinkButtonConfigList($stored);

        $this->config['table_meta']['toolbar_actions'] = $normalized;

        return $normalized;
    }

    /**
     * @return array<int, array{html:string}>
     */
    private function getNormalizedToolbarHtmlConfig(): array
    {
        $tableMeta = $this->config['table_meta'] ?? [];
        $normalized = $this->normalizeToolbarHtmlList($tableMeta['toolbar_html'] ?? []);

        $this->config['table_meta']['toolbar_html'] = $normalized;

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getNormalizedMultiLinkButtonsConfig(): array
    {
        $stored = $this->config['multi_link_buttons'] ?? [];
        if (!is_array($stored)) {
            $stored = [];
        }

        if (isset($this->config['multi_link_button']) && is_array($this->config['multi_link_button'])) {
            $stored[] = $this->config['multi_link_button'];
            unset($this->config['multi_link_button']);
        }

        $normalizedList = $this->normalizeMultiLinkButtonConfigList($stored);

        $this->config['multi_link_buttons'] = $normalizedList;

        return $normalizedList;
    }

    private function hasActionOverride(string $action): bool
    {
        $action = strtolower($action);
        if ($action === '') {
            return false;
        }

        $flagKey = '__fastcrud_' . $action . '_override';
        if (!empty($this->config[$flagKey])) {
            return true;
        }

        $normalized = $this->getNormalizedMultiLinkButtonsConfig();
        foreach ($normalized as $entry) {
            if (!is_array($entry) || !isset($entry['items']) || !is_array($entry['items'])) {
                continue;
            }

            foreach ($entry['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $type = isset($item['type']) ? strtolower((string) $item['type']) : 'link';
                if ($type === $action) {
                    $this->config[$flagKey] = true;
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeCallable(string|array $callback): string
    {
        if (is_string($callback)) {
            $normalized = trim($callback);
            if ($normalized === '') {
                throw new InvalidArgumentException('Callback name cannot be empty.');
            }

            return $normalized;
        }

        if (!is_array($callback) || count($callback) !== 2) {
            throw new InvalidArgumentException('Unsupported callback type. Provide a string callable or [ClassName, method] pair.');
        }

        [$class, $method] = array_values($callback);

        if (!is_string($class) || !is_string($method)) {
            throw new InvalidArgumentException('Callback array must contain two string entries: [ClassName, methodName].');
        }

        $class  = trim($class);
        $method = trim($method);

        if ($class === '' || $method === '') {
            throw new InvalidArgumentException('Callback array entries cannot be empty strings.');
        }

        return $class . '::' . $method;
    }

    /**
     * @return array{type: string, value?: string, callable?: string}
     */
    private function normalizeColumnTooltipDefinition(string|array|null $tooltip): array
    {
        if ($tooltip === null) {
            return ['type' => 'none'];
        }

        if (is_array($tooltip)) {
            if (!isset($tooltip['type']) && array_key_exists('callback', $tooltip)) {
                $callback = $tooltip['callback'];
                if (!is_string($callback) && !is_array($callback)) {
                    throw new InvalidArgumentException('Column tooltip callback must be a callable string or [ClassName, method] pair.');
                }

                $serialized = $this->normalizeCallable($callback);
                if (!is_callable($serialized)) {
                    throw new InvalidArgumentException('Provided tooltip callback is not callable: ' . $serialized);
                }

                return ['type' => 'callback', 'callable' => $serialized];
            }

            if (!isset($tooltip['type']) && array_key_exists('tooltip', $tooltip)) {
                $value = is_scalar($tooltip['tooltip']) ? trim((string) $tooltip['tooltip']) : '';

                return $value === '' ? ['type' => 'none'] : ['type' => 'static', 'value' => $value];
            }

            if (isset($tooltip['type'])) {
                $type = strtolower(trim((string) $tooltip['type']));
                if ($type === 'static') {
                    $value = isset($tooltip['value']) && is_scalar($tooltip['value'])
                        ? trim((string) $tooltip['value'])
                        : '';

                    return $value === '' ? ['type' => 'none'] : ['type' => 'static', 'value' => $value];
                }

                if ($type === 'callback') {
                    $callback = $tooltip['callable'] ?? $tooltip['callback'] ?? null;
                    if (!is_string($callback) && !is_array($callback)) {
                        throw new InvalidArgumentException('Column tooltip callback must be a callable string or [ClassName, method] pair.');
                    }

                    $serialized = $this->normalizeCallable($callback);
                    if (!is_callable($serialized)) {
                        throw new InvalidArgumentException('Provided tooltip callback is not callable: ' . $serialized);
                    }

                    return ['type' => 'callback', 'callable' => $serialized];
                }
            }

            $serialized = $this->normalizeCallable($tooltip);
            if (!is_callable($serialized)) {
                throw new InvalidArgumentException('Provided tooltip callback is not callable: ' . $serialized);
            }

            return ['type' => 'callback', 'callable' => $serialized];
        }

        $trimmed = trim($tooltip);
        if ($trimmed === '') {
            return ['type' => 'none'];
        }

        if (is_callable($trimmed)) {
            return ['type' => 'callback', 'callable' => $trimmed];
        }

        return ['type' => 'static', 'value' => $trimmed];
    }

    private function normalizePermissionRule(bool|string|array $condition): bool|string
    {
        if (is_bool($condition)) {
            return $condition;
        }

        $serialized = $this->normalizeCallable($condition);
        if (!is_callable($serialized)) {
            throw new InvalidArgumentException('Provided permission callback is not callable: ' . $serialized);
        }

        return $serialized;
    }

    /**
     * @param mixed $payload
     * @param array<string, mixed> $context
     * @return array{payload: mixed, cancelled: bool}
     */
    private function dispatchLifecycleEvent(string $event, mixed $payload, array $context, bool $expectArray = false): array
    {
        if (!in_array($event, self::LIFECYCLE_EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported lifecycle event: ' . $event);
        }

        $callbacks = $this->config['lifecycle_callbacks'][$event] ?? [];
        if ($callbacks === []) {
            return ['payload' => $payload, 'cancelled' => false];
        }

        foreach ($callbacks as $callable) {
            $result = call_user_func($callable, $payload, $context, $this);

            if ($result === false) {
                return ['payload' => $payload, 'cancelled' => true];
            }

            if ($result === null) {
                continue;
            }

            if ($expectArray && !is_array($result)) {
                throw new RuntimeException(sprintf('Lifecycle callback for %s must return an array or null/false.', $event));
            }

            $payload = $result;
        }

        return ['payload' => $payload, 'cancelled' => false];
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

    private function normalizeSectionIdentifier(string $identifier): string
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return '';
        }

        $normalized = preg_replace('/[^A-Za-z0-9_-]+/', '_', $trimmed);
        if (!is_string($normalized)) {
            $normalized = $trimmed;
        }

        $normalized = strtolower(trim($normalized, '_-'));

        return $normalized;
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

    private function ensureFormSectionBuckets(): void
    {
        if (!isset($this->config['form']['sections']) || !is_array($this->config['form']['sections'])) {
            $this->config['form']['sections'] = [];
        }

        foreach (self::SUPPORTED_FORM_MODES as $mode) {
            if (!isset($this->config['form']['sections'][$mode]) || !is_array($this->config['form']['sections'][$mode])) {
                $this->config['form']['sections'][$mode] = [];
            }
        }

        if (!isset($this->config['form']['sections']['all']) || !is_array($this->config['form']['sections']['all'])) {
            $this->config['form']['sections']['all'] = [];
        }
    }

    private function ensureFormBehaviourBuckets(): void
    {
        if (!isset($this->config['form']['behaviours']) || !is_array($this->config['form']['behaviours'])) {
            $this->config['form']['behaviours'] = [
                'change_type'         => [],
                'pass_var'            => [],
                'pass_default'        => [],
                'readonly'            => [],
                'disabled'            => [],
                'visible_if'          => [],
                'editable_if'         => [],
                'validation_required' => [],
                'validation_pattern'  => [],
                'max_length'          => [],
                'unique'              => [],
            ];
            return;
        }

        $defaults = [
            'change_type'         => [],
            'pass_var'            => [],
            'pass_default'        => [],
            'readonly'            => [],
            'disabled'            => [],
            'visible_if'          => [],
            'editable_if'         => [],
            'validation_required' => [],
            'validation_pattern'  => [],
            'max_length'          => [],
            'unique'              => [],
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

    /**
     * @param array<int, string> $columns
     */
    private function addFormColumns(array $columns): void
    {
        $this->ensureDefaultTabBuckets();

        foreach ($columns as $column) {
            if ($column !== '' && !in_array($column, $this->config['form']['all_columns'], true)) {
                $this->config['form']['all_columns'][] = $column;
            }
        }
    }

    private function storeLayoutEntry(array $fields, bool $reverse, ?string $tab, array $modes, ?string $section = null): void
    {
        $this->ensureFormLayoutBuckets();

        $entry = [
            'fields'  => array_values(array_unique($fields)),
            'reverse' => $reverse,
            'tab'     => $tab,
        ];

        if ($section !== null && $section !== '') {
            $entry['section'] = $section;
        }

        foreach ($modes as $mode) {
            $bucket                                     = $mode === 'all' ? 'all' : $mode;
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

    private function applyFormBehaviour(string|array $fields, string $key, mixed $value, string|array $mode, string $emptyMessage): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException($emptyMessage);
        }

        $modes = $this->normalizeFormModes($mode);
        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized !== '') {
                $this->storeBehaviourValue($key, $normalized, $value, $modes);
            }
        }

        return $this;
    }

    private function applyFormPermissionRule(string|array $fields, string $key, bool|string|array $condition, string|array $mode, string $emptyMessage): self
    {
        return $this->applyFormBehaviour(
            $fields,
            $key,
            $this->normalizePermissionRule($condition),
            $mode,
            $emptyMessage
        );
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function evaluatePermissionRule(mixed $rule, string $scope, string $name, string $mode = 'all', ?array $row = null): bool
    {
        if (is_bool($rule)) {
            return $rule;
        }

        if ($rule === null) {
            return true;
        }

        if (!is_string($rule) || trim($rule) === '') {
            return (bool) $rule;
        }

        $callable = trim($rule);
        if (!is_callable($callable)) {
            return true;
        }

        $context = [
            'scope' => $scope,
            'name'  => $name,
            'table' => $this->table,
            'mode'  => $mode,
        ];

        if ($scope === 'field') {
            $context['field'] = $name;
        } elseif ($scope === 'column') {
            $context['column'] = $name;
        }

        return (bool) call_user_func($callable, $row ?? [], $context, $this);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function isFieldVisible(string $field, string $mode, ?array $row = null): bool
    {
        $rules = $this->gatherBehaviourForMode('visible_if', $mode);

        return !isset($rules[$field]) || $this->evaluatePermissionRule($rules[$field], 'field', $field, $mode, $row);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function isFieldEditable(string $field, string $mode, ?array $row = null): bool
    {
        $rules = $this->gatherBehaviourForMode('editable_if', $mode);

        return !isset($rules[$field]) || $this->evaluatePermissionRule($rules[$field], 'field', $field, $mode, $row);
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, array{visible: bool, editable: bool}>
     */
    private function buildFieldPermissionState(array $fields, string $mode, ?array $row = null): array
    {
        $state = [];
        foreach ($fields as $field) {
            if (!is_string($field)) {
                continue;
            }

            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }

            $visible  = $this->isFieldVisible($normalized, $mode, $row);
            $editable = $visible && $this->isFieldEditable($normalized, $mode, $row);

            if (!$visible || !$editable) {
                $state[$normalized] = [
                    'visible'  => $visible,
                    'editable' => $editable,
                ];
            }
        }

        return $state;
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
            static function (array $matches) use ($context): string
            {
                $key         = $matches[1];
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
     * @param array<string, mixed> $data
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function applyPasswordFieldTransformations(array $data, array $source): array
    {
        foreach (array_keys($data) as $column) {
            $definition = $this->getChangeTypeDefinition($column);
            if ($definition === null) {
                continue;
            }

            $type = isset($definition['type']) ? strtolower((string) $definition['type']) : '';
            if ($type !== 'password') {
                continue;
            }

            if (!array_key_exists($column, $source)) {
                continue;
            }

            $raw = $source[$column];
            if ($raw === null || $raw === '') {
                unset($data[$column]);
                continue;
            }

            if (!is_string($raw)) {
                $raw = (string) $raw;
            }

            $params = [];
            if (isset($definition['params']) && is_array($definition['params'])) {
                $params = $definition['params'];
            }

            $algorithmCandidate = $params['algorithm'] ?? $params['algo'] ?? ($definition['default'] ?? null);
            $algorithm          = $this->normalizePasswordAlgorithm($algorithmCandidate);

            $options = [];
            if (isset($params['options']) && is_array($params['options'])) {
                $options = $params['options'];
            }

            if (isset($params['cost']) && !isset($options['cost']) && is_numeric($params['cost'])) {
                $options['cost'] = (int) $params['cost'];
            }

            try {
                $hash = password_hash($raw, $algorithm, $options);
            } catch (\ValueError $error) {
                throw new RuntimeException(
                    sprintf('Failed to hash password for field "%s": %s', $column, $error->getMessage()),
                    0,
                    $error
                );
            }

            if ($hash === false) {
                throw new RuntimeException(sprintf('Failed to hash password for field "%s".', $column));
            }

            $data[$column] = $hash;
        }

        return $data;
    }

    private function normalizePasswordAlgorithm(mixed $algorithm): string|int
    {
        if (is_int($algorithm)) {
            return $algorithm;
        }

        if (is_string($algorithm)) {
            $candidate = strtolower(trim($algorithm));
            if ($candidate === '' || $candidate === 'default' || $candidate === 'password_default') {
                return PASSWORD_DEFAULT;
            }

            if (in_array($candidate, ['bcrypt', 'password_bcrypt', '2y', '2b'], true)) {
                return PASSWORD_BCRYPT;
            }

            if (in_array($candidate, ['argon2', 'password_argon2'], true)) {
                if (defined('PASSWORD_ARGON2ID')) {
                    return PASSWORD_ARGON2ID;
                }

                if (defined('PASSWORD_ARGON2I')) {
                    return PASSWORD_ARGON2I;
                }
            }

            if (in_array($candidate, ['argon2i', 'password_argon2i'], true) && defined('PASSWORD_ARGON2I')) {
                return PASSWORD_ARGON2I;
            }

            if (in_array($candidate, ['argon2id', 'password_argon2id'], true) && defined('PASSWORD_ARGON2ID')) {
                return PASSWORD_ARGON2ID;
            }
        }

        return PASSWORD_BCRYPT;
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

    private function measureValidationLength(mixed $value, bool $trim = false): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_string($value) || is_numeric($value)) {
            $stringValue = (string) $value;
            if ($trim) {
                $stringValue = trim($stringValue);
            }

            return function_exists('mb_strlen') ? mb_strlen($stringValue) : strlen($stringValue);
        }

        if (is_bool($value)) {
            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, bool> $columnLookup
     * @param array<string, string> $errors
     */
    private function collectMaxLengthErrors(array $fields, array $columnLookup, string $mode, array &$errors): void
    {
        foreach ($this->gatherBehaviourForMode('max_length', $mode) as $column => $maxLength) {
            $limit = (int) $maxLength;
            if ($limit < 1 || !isset($columnLookup[$column]) || !array_key_exists($column, $fields)) {
                continue;
            }

            $value = $fields[$column];
            if ($value !== null && $value !== '' && $this->measureValidationLength($value) > $limit) {
                $errors[$column] = sprintf('Must be %d characters or fewer.', $limit);
            }
        }
    }

    private function compileValidationPattern(string $pattern): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }

        $delimiter       = substr($pattern, 0, 1);
        $knownDelimiters = ['/', '#', '~', '!'];
        if (!in_array($delimiter, $knownDelimiters, true)) {
            $escaped = str_replace('/', '\/', $pattern);
            $pattern = '/^' . $escaped . '$/';
        }

        set_error_handler(static function ()
        {
            return true;
        });
        $isValid = @preg_match($pattern, '') !== false;
        restore_error_handler();

        return $isValid ? $pattern : null;
    }

    private function mergeFormConfig(array $form): void
    {
        $this->ensureFormLayoutBuckets();
        $this->ensureFormSectionBuckets();
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
                    $tab     = null;
                    if (isset($entry['tab']) && is_string($entry['tab'])) {
                        $tabCandidate = trim($entry['tab']);
                        $tab          = $tabCandidate === '' ? null : $tabCandidate;
                    }

                    $section = null;
                    if (isset($entry['section']) && is_string($entry['section'])) {
                        $sectionCandidate = $this->normalizeSectionIdentifier($entry['section']);
                        $section          = $sectionCandidate === '' ? null : $sectionCandidate;
                    }

                    $normalizedEntries[] = [
                        'fields'  => array_values(array_unique($fields)),
                        'reverse' => $reverse,
                        'tab'     => $tab,
                        'section' => $section,
                    ];
                }

                $this->config['form']['layouts'][$bucket] = $normalizedEntries;
            }
        }

        if (isset($form['sections']) && is_array($form['sections'])) {
            foreach ($form['sections'] as $mode => $entries) {
                if (!is_string($mode) || !is_array($entries)) {
                    continue;
                }

                $bucket = strtolower(trim($mode));
                if ($bucket === '') {
                    continue;
                }

                $normalizedSections = [];
                foreach ($entries as $key => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $rawId = null;
                    if (isset($entry['id']) && is_string($entry['id'])) {
                        $rawId = $entry['id'];
                    } elseif (is_string($key)) {
                        $rawId = $key;
                    }

                    $sectionId = $rawId !== null ? $this->normalizeSectionIdentifier($rawId) : '';
                    if ($sectionId === '') {
                        continue;
                    }

                    $rawFields = $entry['fields'] ?? [];
                    $fieldList = [];
                    if (is_string($rawFields) || is_array($rawFields)) {
                        $fieldList = $this->normalizeList($rawFields);
                    }

                    if ($fieldList === []) {
                        continue;
                    }

                    $normalizedFields = [];
                    foreach ($fieldList as $field) {
                        $normalizedField = $this->normalizeColumnReference($field);
                        if ($normalizedField !== '') {
                            $normalizedFields[] = $normalizedField;
                        }
                    }

                    if ($normalizedFields === []) {
                        continue;
                    }

                    $title = null;
                    if (isset($entry['title']) && is_string($entry['title'])) {
                        $trimmedTitle = trim($entry['title']);
                        $title        = $trimmedTitle === '' ? null : $trimmedTitle;
                    }

                    $description = null;
                    if (isset($entry['description']) && is_string($entry['description'])) {
                        $trimmedDescription = trim($entry['description']);
                        $description        = $trimmedDescription === '' ? null : $trimmedDescription;
                    }

                    $icon = null;
                    if (isset($entry['icon']) && is_string($entry['icon'])) {
                        $iconCandidate = $this->normalizeCssClassList($entry['icon']);
                        $icon          = $iconCandidate === '' ? null : $iconCandidate;
                    }

                    $sectionClass = null;
                    if (isset($entry['class']) && is_string($entry['class'])) {
                        $classCandidate = $this->normalizeCssClassList($entry['class']);
                        $sectionClass   = $classCandidate === '' ? null : $classCandidate;
                    }

                    $titleClass = null;
                    if (isset($entry['title_class']) && is_string($entry['title_class'])) {
                        $titleClassCandidate = $this->normalizeCssClassList($entry['title_class']);
                        $titleClass          = $titleClassCandidate === '' ? null : $titleClassCandidate;
                    }

                    $collapsible = !empty($entry['collapsible']);
                    $collapsed   = false;
                    if (isset($entry['collapsed'])) {
                        $collapsed = (bool) $entry['collapsed'];
                    } elseif (isset($entry['start_collapsed'])) {
                        $collapsed = (bool) $entry['start_collapsed'];
                    }

                    $normalizedSections[$sectionId] = [
                        'id'          => $sectionId,
                        'title'       => $title,
                        'description' => $description,
                        'fields'      => array_values(array_unique($normalizedFields)),
                        'collapsible' => $collapsible,
                        'collapsed'   => $collapsible ? $collapsed : false,
                        'icon'        => $icon,
                        'class'       => $sectionClass,
                        'title_class' => $titleClass,
                    ];
                }

                $this->config['form']['sections'][$bucket] = $normalizedSections;
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
                if (!is_string($field)) {
                    continue;
                }

                $normalizedField = $this->normalizeColumnReference($field);
                if ($normalizedField === '') {
                    continue;
                }

                if ($label === null) {
                    unset($this->config['field_labels'][$normalizedField]);
                    continue;
                }

                if (!is_string($label)) {
                    continue;
                }

                $trimmed = trim($label);

                if ($trimmed === '') {
                    $this->config['field_labels'][$normalizedField] = '';
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

            $modeAwareKeys = ['pass_var', 'pass_default', 'readonly', 'disabled', 'visible_if', 'editable_if', 'validation_required', 'validation_pattern', 'max_length', 'unique'];
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
     * @param array{empty?: string, unsupported?: string} $messages
     */
    private function normalizeConditionOperatorString(string $operator, array $messages): string
    {
        $original   = $operator;
        $normalized = strtolower(trim($operator));

        if ($normalized === '') {
            if (isset($messages['empty'])) {
                throw new InvalidArgumentException($messages['empty']);
            }

            return 'equals';
        }

        $normalized = str_replace(' ', '_', $normalized);

        $synonyms = [
            '='                => 'equals',
            '=='               => 'equals',
            '==='              => 'equals',
            'eq'               => 'equals',
            '!='               => 'not_equals',
            '!=='              => 'not_equals',
            '<>'               => 'not_equals',
            'ne'               => 'not_equals',
            'not_equals'       => 'not_equals',
            '>'                => 'gt',
            'gt'               => 'gt',
            '>='               => 'gte',
            'gte'              => 'gte',
            '<'                => 'lt',
            'lt'               => 'lt',
            '<='               => 'lte',
            'lte'              => 'lte',
            'notin'            => 'not_in',
            'not_in'           => 'not_in',
            'contains'         => 'contains',
            '!contains'        => 'not_contains',
            'notcontains'      => 'not_contains',
            'not_contains'     => 'not_contains',
            'does_not_contain' => 'not_contains',
            'doesnt_contain'   => 'not_contains',
            'not_like'         => 'not_contains',
            '!~'               => 'not_contains',
            '!value'           => 'empty',
            'empty'            => 'empty',
            '!empty'           => 'not_empty',
            'not_empty'        => 'not_empty',
            'has_value'        => 'not_empty',
        ];

        $normalized = $synonyms[$normalized] ?? $normalized;

        if (!in_array($normalized, self::SUPPORTED_CONDITION_OPERATORS, true)) {
            $message = $messages['unsupported'] ?? 'Unsupported condition operator: %s';
            throw new InvalidArgumentException(sprintf($message, $original));
        }

        return $normalized;
    }

    /**
     * @param array{in_not_in?: string, comparison?: string, contains?: string} $messages
     */
    private function normalizeConditionValueForOperator(string $operator, mixed $value, array $messages): mixed
    {
        $messages = array_replace([
            'in_not_in'  => 'IN/NOT IN conditions require a non-empty array of values.',
            'comparison' => 'Comparison operators require numeric values.',
            'contains'   => 'Contains operator requires a string value.',
        ], $messages);

        if (in_array($operator, ['in', 'not_in'], true)) {
            if (is_string($value)) {
                $value = $this->normalizeList($value);
            }

            if (!is_array($value) || $value === []) {
                throw new InvalidArgumentException($messages['in_not_in']);
            }

            return $value;
        }

        if (in_array($operator, ['gt', 'gte', 'lt', 'lte'], true)) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException($messages['comparison']);
            }

            return (float) $value;
        }

        if (in_array($operator, ['contains', 'not_contains'], true) && !is_string($value)) {
            throw new InvalidArgumentException($messages['contains']);
        }

        return $value;
    }

    /**
     * @return array{column: string, operator: string, value: mixed}
     */
    private function normalizeCondition(string $column, string $operator, mixed $value): array
    {
        $normalizedColumn = $this->normalizeColumnReference($column);
        if ($normalizedColumn === '') {
            throw new InvalidArgumentException('Highlight conditions must reference a column.');
        }

        $normalizedOperator = $this->normalizeConditionOperatorString($operator, [
            'empty'       => 'Highlight operator cannot be empty.',
            'unsupported' => 'Unsupported condition operator: %s',
        ]);

        $normalizedValue = $this->normalizeConditionValueForOperator(
            $normalizedOperator,
            $value,
            [
                'in_not_in'  => 'IN/NOT IN conditions require a non-empty array of values.',
                'comparison' => 'Comparison operators require numeric values.',
                'contains'   => 'Contains operator requires a string value.',
            ]
        );

        if (in_array($normalizedOperator, ['empty', 'not_empty'], true)) {
            $normalizedValue = null;
        }

        return [
            'column'   => $normalizedColumn,
            'operator' => $normalizedOperator,
            'value'    => $normalizedValue,
        ];
    }

    /**
     * @return array{column: string, operator: string, value: mixed}
     */
    private function normalizeActionCondition(string $field, string $operand, mixed $value): array
    {
        $column = $this->normalizeColumnReference($field);
        if ($column === '') {
            throw new InvalidArgumentException('Duplicate condition requires a valid column name.');
        }

        $operator = $this->normalizeConditionOperatorString($operand, [
            'empty'       => 'Duplicate condition operator cannot be empty.',
            'unsupported' => 'Unsupported duplicate condition operator: %s',
        ]);

        $value = $this->normalizeConditionValueForOperator(
            $operator,
            $value,
            [
                'in_not_in'  => 'IN/NOT IN duplicate conditions require a non-empty list of values.',
                'comparison' => 'Comparison duplicate conditions require numeric values.',
                'contains'   => 'Contains duplicate conditions require a string value.',
            ]
        );

        return [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];
    }

    private function isActionEnabled(string $action): bool
    {
        $meta = $this->config['table_meta'] ?? [];

        return match ($action) {
            'add' => isset($meta['add']) ? (bool) $meta['add'] : true,
            'view' => isset($meta['view']) ? (bool) $meta['view'] : true,
            'edit' => isset($meta['edit']) ? (bool) $meta['edit'] : true,
            'delete' => (
                (isset($meta['delete']) ? (bool) $meta['delete'] : true)
                || $this->hasActionOverride('delete')
            ),
            'duplicate' => (
                (isset($meta['duplicate']) ? (bool) $meta['duplicate'] : false)
                || $this->hasActionOverride('duplicate')
            ),
            default => false,
        };
    }

    private function isBatchDeleteEnabled(): bool
    {
        $meta = $this->config['table_meta'] ?? [];

        $batchDeleteConfigured = isset($meta['batch_delete']) ? (bool) $meta['batch_delete'] : false;
        $hasBulkActions        = isset($meta['bulk_actions']) && is_array($meta['bulk_actions']) && $meta['bulk_actions'] !== [];

        if (!$batchDeleteConfigured && !$hasBulkActions) {
            return false;
        }

        if ($batchDeleteConfigured) {
            return isset($meta['delete']) ? (bool) $meta['delete'] : true;
        }

        return true;
    }

    private function getActionCondition(string $action): ?array
    {
        $key       = $action . '_condition';
        $condition = $this->config['table_meta'][$key] ?? null;

        return is_array($condition) ? $condition : null;
    }

    private function isActionAllowedForRow(string $action, array $row): bool
    {
        if (!$this->isActionEnabled($action)) {
            return false;
        }

        $condition = $this->getActionCondition($action);
        if ($condition === null) {
            return true;
        }

        $rowForEvaluation = $row;
        $column           = $condition['column'] ?? null;
        if (is_string($column) && $column !== '') {
            $rawValues = $row['__fastcrud_raw'] ?? null;
            if (is_array($rawValues) && array_key_exists($column, $rawValues)) {
                $rowForEvaluation[$column] = $rawValues[$column];
            }
        }

        return $this->evaluateCondition($condition, $rowForEvaluation);
    }

    private function evaluateCondition(array $condition, array $row): bool
    {
        $column   = $condition['column'];
        $operator = $condition['operator'];
        $value    = $condition['value'];

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
                return is_string($current) && str_contains($current, (string) $value);
            case 'not_contains':
                return !is_string($current) || !str_contains($current, (string) $value);
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
        $length       = max(1, $length);
        $stringLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($stringLength <= $length) {
            return $value;
        }

        $substr = function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);

        return $substr . $suffix;
    }

    private const PATTERN_TOKEN_REGEX = '/\{([A-Za-z0-9_]+)\}/';

    private function applyPattern(string $pattern, string $display, mixed $raw, string $column, array $row, ?string $formatted = null): string
    {
        return preg_replace_callback(
            self::PATTERN_TOKEN_REGEX,
            function (array $matches) use ($display, $raw, $column, $row, $formatted): string
            {
                $token = strtolower($matches[1]);

                return match ($token) {
                    'value' => $display,
                    'formatted' => $formatted ?? $display,
                    'raw' => $this->stringifyValue($raw),
                    'column' => $column,
                    'label' => $this->resolveColumnLabel($column),
                    default => $this->stringifyValue($row[$token] ?? ''),
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

        $lower      = strtolower($width);
        $styleUnits = ['px', 'rem', 'em', '%', 'vw', 'vh'];
        foreach ($styleUnits as $unit) {
            if (str_ends_with($lower, $unit)) {
                return ['class' => null, 'style' => 'width: ' . $width . ';'];
            }
        }

        if (str_contains($lower, 'calc(')) {
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

        $cells     = [];
        $rawValues = [];

        if (isset($sourceRow['__fastcrud_raw']) && is_array($sourceRow['__fastcrud_raw'])) {
            $rawValues = $sourceRow['__fastcrud_raw'];
        }

        foreach ($columns as $column) {
            $value          = $sourceRow[$column] ?? null;
            $rawOriginal    = $rawValues[$column] ?? ($sourceRow[$column] ?? null);
            $cells[$column] = $this->presentCell($column, $value, $sourceRow, $rawOriginal);
        }

        $rowClasses = [];
        foreach ($this->config['row_highlights'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $condition = $entry['condition'] ?? null;
            $class     = isset($entry['class']) ? (string) $entry['class'] : '';
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

        $linkButtons = $this->buildLinkButtonsMetaForRow($sourceRow);
        if ($linkButtons !== []) {
            $meta['link_buttons'] = $linkButtons;
        }

        $multiLinkButtons = $this->buildMultiLinkButtonsMetaForRow($sourceRow);
        if ($multiLinkButtons !== []) {
            $meta['multi_link_buttons'] = $multiLinkButtons;
        }

        $actionButtonOrder = $this->buildCustomActionButtonOrder($linkButtons, $multiLinkButtons);
        if ($actionButtonOrder !== []) {
            $meta['action_button_order'] = $actionButtonOrder;
        }

        $meta['view_allowed']      = $this->isActionAllowedForRow('view', $sourceRow);
        $meta['duplicate_allowed'] = $this->isActionAllowedForRow('duplicate', $sourceRow);
        $meta['edit_allowed']      = $this->isActionAllowedForRow('edit', $sourceRow);
        $meta['delete_allowed']    = $this->isActionAllowedForRow('delete', $sourceRow);

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
                $rows[$index]['__fastcrud']['primary_key']   = $rows[$index]['__fastcrud_primary_key'];
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
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyCustomColumns(array $rows): array
    {
        $definitions = $this->config['custom_columns'] ?? [];
        if ($rows === [] || $definitions === []) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            foreach ($definitions as $column => $callable) {
                if (!is_string($column) || $column === '' || !is_callable($callable)) {
                    continue;
                }

                $value = call_user_func($callable, $rows[$index]);

                $rows[$index][$column] = $value;

                if (!isset($rows[$index]['__fastcrud_raw']) || !is_array($rows[$index]['__fastcrud_raw'])) {
                    $rows[$index]['__fastcrud_raw'] = [];
                }

                $rows[$index]['__fastcrud_raw'][$column] = $value;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function applyFieldCallbacksToRow(array $row, string $mode = 'edit'): array
    {
        if ($row === []) {
            return $row;
        }

        $original = $row;

        $customDefinitions = $this->config['custom_fields'] ?? [];
        foreach ($customDefinitions as $field => $callable) {
            if (!is_string($field) || $field === '' || !is_callable($callable)) {
                continue;
            }

            $initialValue = $original[$field] ?? null;
            $result       = call_user_func($callable, $field, $initialValue, $original, $mode);
            $row          = $this->applyFieldCallbackResult($row, $field, $result, $initialValue);
        }

        $fieldCallbacks = $this->config['field_callbacks'] ?? [];
        foreach ($fieldCallbacks as $field => $callable) {
            if (!is_string($field) || $field === '' || !is_callable($callable)) {
                continue;
            }

            $currentValue = $row[$field] ?? ($original[$field] ?? null);
            $result       = call_user_func($callable, $field, $currentValue, $row, $mode);
            $row          = $this->applyFieldCallbackResult($row, $field, $result, $currentValue);
        }

        return $row;
    }

    private function applyFieldCallbackResult(array $row, string $field, mixed $result, mixed $fallbackValue): array
    {
        if (!is_string($result)) {
            $result = (string) ($result ?? '');
        }

        if (!isset($row['__fastcrud_field_html']) || !is_array($row['__fastcrud_field_html'])) {
            $row['__fastcrud_field_html'] = [];
        }

        $row['__fastcrud_field_html'][$field] = $result;

        if ($fallbackValue !== null) {
            $row[$field] = $fallbackValue;
        } else {
            unset($row[$field]);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private function buildLinkButtonsMetaForRow(array $row): array
    {
        $configs = $this->getNormalizedLinkButtonsConfig();
        if ($configs === []) {
            return [];
        }

        $result = [];

        foreach ($configs as $config) {
            $resolvedUrl = trim($this->applyPattern($config['url'], '', null, 'link_button', $row));
            if ($resolvedUrl === '') {
                continue;
            }

            $resolvedLabel = null;
            if (isset($config['label']) && is_string($config['label']) && $config['label'] !== '') {
                $labelResult = trim($this->applyPattern($config['label'], '', null, 'link_button', $row));
                if ($labelResult !== '') {
                    $resolvedLabel = $labelResult;
                }
            }

            $result[] = [
                'url'          => $resolvedUrl,
                'label'        => $resolvedLabel,
                'icon'         => $config['icon'],
                'button_class' => $config['button_class'],
                'options'      => $config['options'],
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $linkButtons
     * @param array<int, array<string, mixed>> $multiLinkButtons
     * @return array<int, array{type: string, index: int}>
     */
    private function buildCustomActionButtonOrder(array $linkButtons, array $multiLinkButtons): array
    {
        $linkCount  = count($linkButtons);
        $multiCount = count($multiLinkButtons);
        if ($linkCount === 0 && $multiCount === 0) {
            return [];
        }

        $sequence   = $this->getActionButtonSequence();
        $order      = [];
        $linkIndex  = 0;
        $multiIndex = 0;

        foreach ($sequence as $entry) {
            if ($entry === 'link') {
                if ($linkIndex < $linkCount) {
                    $order[] = ['type' => 'link', 'index' => $linkIndex];
                    $linkIndex++;
                }
                continue;
            }

            if ($entry === 'multi' && $multiIndex < $multiCount) {
                $order[] = ['type' => 'multi', 'index' => $multiIndex];
                $multiIndex++;
            }
        }

        while ($linkIndex < $linkCount) {
            $order[] = ['type' => 'link', 'index' => $linkIndex];
            $linkIndex++;
        }

        while ($multiIndex < $multiCount) {
            $order[] = ['type' => 'multi', 'index' => $multiIndex];
            $multiIndex++;
        }

        return $order;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private function buildMultiLinkButtonsMetaForRow(array $row): array
    {
        $configs = $this->getNormalizedMultiLinkButtonsConfig();
        if ($configs === []) {
            return [];
        }

        $result = [];

        foreach ($configs as $config) {
            $buttonConfig = $config['button'];

            $triggerIcon = null;
            if (isset($buttonConfig['icon']) && is_string($buttonConfig['icon']) && $buttonConfig['icon'] !== '') {
                $iconResult = trim($this->applyPattern($buttonConfig['icon'], '', null, 'multi_link_button', $row));
                if ($iconResult !== '') {
                    $normalizedIcon = $this->normalizeCssClassList($iconResult);
                    if ($normalizedIcon !== '') {
                        $triggerIcon = $normalizedIcon;
                    }
                }
            }

            $items         = [];
            $hasActionItem = false;
            foreach ($config['items'] as $item) {
                $itemType = 'link';
                if (isset($item['type'])) {
                    $typeCandidate = strtolower(trim((string) $item['type']));
                    if ($typeCandidate !== '') {
                        $itemType = $typeCandidate;
                    }
                }

                if ($itemType === 'divider') {
                    if ($items === []) {
                        continue;
                    }

                    $lastIndex = array_key_last($items);
                    $lastItem  = $lastIndex !== null ? $items[$lastIndex] : null;
                    $lastType  = is_array($lastItem) && isset($lastItem['type']) ? strtolower((string) $lastItem['type']) : null;
                    if ($lastType === 'divider') {
                        continue;
                    }

                    $resolvedTitle = null;
                    if (array_key_exists('title', $item) && $item['title'] !== null) {
                        $titleResult = trim($this->applyPattern((string) $item['title'], '', null, 'multi_link_button', $row));
                        if ($titleResult !== '') {
                            $resolvedTitle = $titleResult;
                        }
                    }

                    $items[] = [
                        'type'  => 'divider',
                        'title' => $resolvedTitle,
                    ];
                    continue;
                }

                if ($itemType === 'duplicate') {
                    $resolvedLabel = trim($this->applyPattern((string) $item['label'], '', null, 'multi_link_button', $row));
                    if ($resolvedLabel === '') {
                        continue;
                    }

                    $resolvedOptions = [];
                    if (isset($item['options']) && is_array($item['options'])) {
                        foreach ($item['options'] as $key => $value) {
                            if (!is_string($key)) {
                                continue;
                            }

                            $optionValue = trim($this->applyPattern($value, '', null, 'multi_link_button', $row));
                            if ($optionValue === '') {
                                continue;
                            }

                            $resolvedOptions[$key] = $optionValue;
                        }
                    }

                    $resolvedIcon = null;
                    if (isset($item['icon']) && is_string($item['icon']) && $item['icon'] !== '') {
                        $iconCandidate = trim($this->applyPattern($item['icon'], '', null, 'multi_link_button', $row));
                        if ($iconCandidate !== '') {
                            $normalizedIcon = $this->normalizeCssClassList($iconCandidate);
                            if ($normalizedIcon !== '') {
                                $resolvedIcon = $normalizedIcon;
                            }
                        }
                    }

                    $items[]       = [
                        'type'    => 'duplicate',
                        'label'   => $resolvedLabel,
                        'icon'    => $resolvedIcon,
                        'options' => $resolvedOptions,
                    ];
                    $hasActionItem = true;
                    continue;
                }

                if ($itemType === 'delete') {
                    $resolvedLabel = trim($this->applyPattern((string) $item['label'], '', null, 'multi_link_button', $row));
                    if ($resolvedLabel === '') {
                        continue;
                    }

                    $resolvedOptions = [];
                    if (isset($item['options']) && is_array($item['options'])) {
                        foreach ($item['options'] as $key => $value) {
                            if (!is_string($key)) {
                                continue;
                            }

                            $optionValue = trim($this->applyPattern($value, '', null, 'multi_link_button', $row));
                            if ($optionValue === '') {
                                continue;
                            }

                            $resolvedOptions[$key] = $optionValue;
                        }
                    }

                    $resolvedIcon = null;
                    if (isset($item['icon']) && is_string($item['icon']) && $item['icon'] !== '') {
                        $iconCandidate = trim($this->applyPattern($item['icon'], '', null, 'multi_link_button', $row));
                        if ($iconCandidate !== '') {
                            $normalizedIcon = $this->normalizeCssClassList($iconCandidate);
                            if ($normalizedIcon !== '') {
                                $resolvedIcon = $normalizedIcon;
                            }
                        }
                    }

                    $items[]       = [
                        'type'    => 'delete',
                        'label'   => $resolvedLabel,
                        'icon'    => $resolvedIcon,
                        'options' => $resolvedOptions,
                    ];
                    $hasActionItem = true;
                    continue;
                }

                if ($itemType === 'input') {
                    if (!isset($item['url'], $item['label'])) {
                        continue;
                    }

                    $resolvedUrl = trim($this->applyPattern((string) $item['url'], '', null, 'multi_link_button', $row));
                    if ($resolvedUrl === '') {
                        continue;
                    }

                    $resolvedLabel = trim($this->applyPattern((string) $item['label'], '', null, 'multi_link_button', $row));
                    if ($resolvedLabel === '') {
                        continue;
                    }

                    $resolvedOptions = [];
                    if (isset($item['options']) && is_array($item['options'])) {
                        foreach ($item['options'] as $key => $value) {
                            if (!is_string($key)) {
                                continue;
                            }

                            $optionValue = trim($this->applyPattern($value, '', null, 'multi_link_button', $row));
                            if ($optionValue === '') {
                                continue;
                            }

                            $resolvedOptions[$key] = $optionValue;
                        }
                    }

                    $resolvedIcon = null;
                    if (isset($item['icon']) && is_string($item['icon']) && $item['icon'] !== '') {
                        $iconCandidate = trim($this->applyPattern($item['icon'], '', null, 'multi_link_button', $row));
                        if ($iconCandidate !== '') {
                            $normalizedIcon = $this->normalizeCssClassList($iconCandidate);
                            if ($normalizedIcon !== '') {
                                $resolvedIcon = $normalizedIcon;
                            }
                        }
                    }

                    $inputNameSource   = isset($item['input_name']) ? (string) $item['input_name'] : 'exampleinput';
                    $resolvedInputName = trim($this->applyPattern($inputNameSource, '', null, 'multi_link_button', $row));
                    if ($resolvedInputName === '') {
                        $resolvedInputName = 'exampleinput';
                    }

                    $resolvedPrompt = null;
                    if (array_key_exists('prompt', $item) && $item['prompt'] !== null) {
                        $promptCandidate = trim($this->applyPattern((string) $item['prompt'], '', null, 'multi_link_button', $row));
                        if ($promptCandidate !== '') {
                            $resolvedPrompt = $promptCandidate;
                        }
                    }

                    $items[]       = [
                        'type'       => 'input',
                        'url'        => $resolvedUrl,
                        'label'      => $resolvedLabel,
                        'icon'       => $resolvedIcon,
                        'options'    => $resolvedOptions,
                        'input_name' => $resolvedInputName,
                        'prompt'     => $resolvedPrompt,
                    ];
                    $hasActionItem = true;
                    continue;
                }

                if (!isset($item['url'], $item['label'])) {
                    continue;
                }

                $resolvedUrl = trim($this->applyPattern((string) $item['url'], '', null, 'multi_link_button', $row));
                if ($resolvedUrl === '') {
                    continue;
                }

                $resolvedLabel = trim($this->applyPattern((string) $item['label'], '', null, 'multi_link_button', $row));
                if ($resolvedLabel === '') {
                    continue;
                }

                $resolvedOptions = [];
                if (isset($item['options']) && is_array($item['options'])) {
                    foreach ($item['options'] as $key => $value) {
                        if (!is_string($key)) {
                            continue;
                        }

                        $optionValue = trim($this->applyPattern($value, '', null, 'multi_link_button', $row));
                        if ($optionValue === '') {
                            continue;
                        }

                        $resolvedOptions[$key] = $optionValue;
                    }
                }

                $resolvedIcon = null;
                if (isset($item['icon']) && is_string($item['icon']) && $item['icon'] !== '') {
                    $iconCandidate = trim($this->applyPattern($item['icon'], '', null, 'multi_link_button', $row));
                    if ($iconCandidate !== '') {
                        $normalizedIcon = $this->normalizeCssClassList($iconCandidate);
                        if ($normalizedIcon !== '') {
                            $resolvedIcon = $normalizedIcon;
                        }
                    }
                }

                $items[]       = [
                    'type'    => 'link',
                    'url'     => $resolvedUrl,
                    'label'   => $resolvedLabel,
                    'icon'    => $resolvedIcon,
                    'options' => $resolvedOptions,
                ];
                $hasActionItem = true;
            }

            if ($items !== []) {
                $lastIndex = array_key_last($items);
                if ($lastIndex !== null) {
                    $lastItem = $items[$lastIndex];
                    $lastType = isset($lastItem['type']) ? strtolower((string) $lastItem['type']) : null;
                    if ($lastType === 'divider') {
                        array_pop($items);
                    }
                }
            }

            if (!$hasActionItem || $items === []) {
                continue;
            }

            $resolvedLabel = null;
            if (isset($buttonConfig['label']) && is_string($buttonConfig['label']) && $buttonConfig['label'] !== '') {
                $labelResult = trim($this->applyPattern($buttonConfig['label'], '', null, 'multi_link_button', $row));
                if ($labelResult !== '') {
                    $resolvedLabel = $labelResult;
                }
            }

            $resolvedOptions = [];
            if (isset($buttonConfig['options']) && is_array($buttonConfig['options'])) {
                foreach ($buttonConfig['options'] as $key => $value) {
                    $optionValue = trim($this->applyPattern($value, '', null, 'multi_link_button', $row));
                    if ($optionValue === '' || !is_string($key)) {
                        continue;
                    }

                    $resolvedOptions[$key] = $optionValue;
                }
            }

            $result[] = [
                'button' => [
                    'label'           => $resolvedLabel,
                    'icon'            => $triggerIcon,
                    'options'         => $resolvedOptions,
                    'button_class'    => $buttonConfig['button_class'],
                    'menu_class'      => $buttonConfig['menu_class'],
                    'container_class' => $buttonConfig['container_class'],
                    'enable_filter'   => !empty($buttonConfig['enable_filter']),
                    'highlight_row_on_open' => !empty($buttonConfig['highlight_row_on_open']),
                ],
                'items'  => $items,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentCell(string $column, mixed $value, array $row, mixed $rawOriginal): array
    {
        $display          = $this->stringifyValue($value);
        $displayOriginal  = $display;
        $formattedDisplay = $display;
        $html             = null;

        $formatterResult = $this->applyColumnFormatter($column, $value, $row);
        if ($formatterResult !== null) {
            $display          = $formatterResult['display'];
            $displayOriginal  = $display;
            $formattedDisplay = $display;
            $html             = $formatterResult['html'];
        }

        $cut = $this->config['column_cuts'][$column]
            ?? $this->config['default_column_truncate']
            ?? null;
        if ($html === null && is_array($cut) && isset($cut['length'])) {
            $suffix           = isset($cut['suffix']) ? (string) $cut['suffix'] : '…';
            $formattedDisplay = $this->truncateString($formattedDisplay, (int) $cut['length'], $suffix);
        }

        $patternEntry = $this->config['column_patterns'][$column] ?? null;
        if ($patternEntry !== null) {
            $patternTemplate = trim((string) $patternEntry);
            if ($patternTemplate !== '') {
                $patternOutput = $this->applyPattern($patternTemplate, $displayOriginal, $value, $column, $row, $formattedDisplay);
                $html          = $patternOutput;
            }
        }

        $display = $formattedDisplay;

        $tooltip     = null;
        $attributes  = [];
        $cellClasses = [];

        if (isset($this->config['column_callbacks'][$column])) {
            $callbackEntry = $this->config['column_callbacks'][$column];
            $callable      = null;

            if (is_string($callbackEntry) && $callbackEntry !== '') {
                $callable = $callbackEntry;
            } elseif (is_array($callbackEntry) && isset($callbackEntry['callable'])) {
                $callable = (string) $callbackEntry['callable'];
            }

            if ($callable !== null && is_callable($callable)) {
                $formattedValue = $html !== null ? $html : $display;
                $result         = call_user_func($callable, $value, $row, $column, $formattedValue);

                if ($result !== null) {
                    $stringResult = $this->stringifyValue($result);
                    $html         = $stringResult;
                    $display      = $stringResult;
                }
            }
        }

        if ($html === null && isset($this->config['custom_columns'][$column])) {
            $stringValue = $this->stringifyValue($value);
            if ($stringValue !== '') {
                $html    = $stringValue;
                $display = $stringValue;
            }
        }

        if ($html === null && isset($this->config['form']['behaviours']['change_type'][$column])) {
            $change       = $this->config['form']['behaviours']['change_type'][$column];
            $type         = is_array($change) && isset($change['type']) ? strtolower((string) $change['type']) : '';
            $changeParams = is_array($change) && isset($change['params']) && is_array($change['params']) ? $change['params'] : null;
            if ($type === 'file') {
                $raw = $rawOriginal;
                if ($raw === null || $raw === '') {
                    $raw = $value;
                }
                $fileName = $this->stringifyValue($raw);
                $fileName = trim($fileName);
                if ($fileName !== '') {
                    $resolved = self::resolveStoredFileName($fileName, $changeParams);
                    $target   = $resolved !== '' ? $resolved : $fileName;
                    $href     = $this->buildPublicUploadUrl($target);
                    $linkText = $display;
                    $html     = '<a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer">' . $this->escapeHtml($linkText) . '</a>';
                }
            } elseif ($type === 'files') {
                $raw = $rawOriginal;
                if ($raw === null || $raw === '') {
                    $raw = $value;
                }
                $names = $this->parseImageNameList($raw);
                if ($changeParams !== null) {
                    $names = array_values(array_filter(
                        array_map(
                            static fn(string $name): string => self::resolveStoredFileName($name, $changeParams),
                            $names
                        ), static fn(string $name): bool => $name !== ''));
                }
                if ($names !== []) {
                    $first = $names[0];
                    $href  = $this->buildPublicUploadUrl($first);
                    $extra = count($names) > 1 ? ' (+' . (count($names) - 1) . ')' : '';
                    $text  = $this->extractFileName($first) . $extra;
                    $html  = '<a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer">' . $this->escapeHtml($text) . '</a>';
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
                        $resolved = self::resolveStoredFileName($fileName, $changeParams);
                        $target   = $resolved !== '' ? $resolved : $fileName;
                        $src      = $this->buildPublicUploadUrl($target);
                        $style    = $height > 0 ? (' style="height: ' . $height . 'px; width: auto;"') : '';
                        $html     = '<img src="' . $this->escapeHtml($src) . '" alt="" class="img-thumbnail"' . $style . ' />';
                    }
                } else {
                    $raw = $rawOriginal;
                    if ($raw === null || $raw === '') {
                        $raw = $value;
                    }
                    $names = $this->parseImageNameList($raw);
                    if ($changeParams !== null) {
                        $names = array_values(array_filter(
                            array_map(
                                static fn(string $name): string => self::resolveStoredFileName($name, $changeParams),
                                $names
                            ), static fn(string $name): bool => $name !== ''));
                    }
                    if ($names !== []) {
                        $first = $names[0];
                        $src   = $this->buildPublicUploadUrl($first);
                        $style = $height > 0 ? (' style="height: ' . $height . 'px; width: auto;"') : '';
                        $html  = '<img src="' . $this->escapeHtml($src) . '" alt="" class="img-thumbnail"' . $style . ' />';
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
                    $text   = $this->escapeHtml($this->stringifyValue($value));
                    $html   = $swatch . ' ' . $text;
                }
            }
        }

        // Default rendering: show boolean fields as a Bootstrap switch in grid
        if ($html === null && CrudConfig::$bools_in_grid) {
            $isBoolean = false;

            // Only allow inline toggle for base table columns
            $lower      = strtolower($column);
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
                    $schema       = $this->getTableSchema($this->table);
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
                $class     = isset($entry['class']) ? (string) $entry['class'] : '';
                if (!is_array($condition) || $class === '') {
                    continue;
                }

                if ($this->evaluateCondition($condition, $row)) {
                    $cellClasses[] = $class;
                }
            }
        }

        if (isset($this->config['column_tooltips'][$column])) {
            $tooltipEntry = $this->config['column_tooltips'][$column];
            if (is_string($tooltipEntry)) {
                $tooltip = trim($this->applyPattern($tooltipEntry, $display, $value, $column, $row, $html ?? $display));
                if ($tooltip === '') {
                    $tooltip = null;
                }
            } elseif (is_array($tooltipEntry)) {
                $type = isset($tooltipEntry['type']) ? strtolower((string) $tooltipEntry['type']) : '';
                if ($type === 'static') {
                    $tooltipValue = isset($tooltipEntry['value']) && is_scalar($tooltipEntry['value'])
                        ? (string) $tooltipEntry['value']
                        : '';
                    $tooltip = trim($this->applyPattern($tooltipValue, $display, $value, $column, $row, $html ?? $display));
                    if ($tooltip === '') {
                        $tooltip = null;
                    }
                } elseif ($type === 'callback') {
                    $callable = isset($tooltipEntry['callable']) ? (string) $tooltipEntry['callable'] : '';
                    if ($callable !== '' && is_callable($callable)) {
                        $formattedValue = $html !== null ? $html : $display;
                        $tooltipResult  = call_user_func($callable, $value, $row, $column, $formattedValue);
                        if ($tooltipResult !== null) {
                            $tooltipText = trim($this->stringifyValue($tooltipResult));
                            $tooltip     = $tooltipText === '' ? null : $tooltipText;
                        }
                    }
                }
            }
        }

        $width = $this->config['column_widths'][$column] ?? null;

        return [
            'display'    => $display,
            'html'       => $html,
            'class'      => trim(implode(' ', array_filter($cellClasses, static fn(string $class): bool      => $class !== ''))),
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
        $truthy = ['true', 't', 'yes', 'y', 'on', 'enabled', 'enable', 'active', 'checked'];
        $falsy  = ['false', 'f', 'no', 'n', 'off', 'disabled', 'disable', 'inactive', 'unchecked', 'null', 'none'];
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
        $base = CrudConfig::getUploadServePath();

        if ($name !== '' && (preg_match('/^https?:\/\//i', $name) === 1 || str_starts_with($name, '/'))) {
            return $name;
        }

        if ($base === '') {
            $base = 'public/uploads';
        }

        // If base is not a full URL and does not start with '/', prefix with '/'
        if (preg_match('/^https?:\/\//i', $base) !== 1 && !str_starts_with($base, '/')) {
            $base = '/' . $base;
        }

        // Normalize join
        $base    = rtrim($base, '/');
        $segment = ltrim($name, '/');
        return $base . '/' . $segment;
    }

    private function extractFileName(string $value): string
    {
        $str = trim($value);
        if ($str === '') {
            return '';
        }

        $path = parse_url(str_replace('\\', '/', $str), PHP_URL_PATH);
        return is_string($path) ? basename($path) : '';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function parseImageNameList(mixed $value): array
    {
        $result = [];

        $append = static function (array &$list, string $candidate): void
        {
            $normalized = self::normalizeStoredImageName($candidate);
            if ($normalized !== '' && !in_array($normalized, $list, true)) {
                $list[] = $normalized;
            }
        };

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item === null) {
                    continue;
                }
                $append($result, (string) $item);
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
                        if ($item === null) {
                            continue;
                        }
                        $append($result, (string) $item);
                    }
                    return $result;
                }
            } catch (\Throwable) {
                // fall through to CSV parsing
            }
        }

        foreach (explode(',', $text) as $item) {
            $append($result, (string) $item);
        }

        return $result;
    }

    private static function normalizeStoredImageName(string $value): string
    {
        $str = trim($value);
        if ($str === '') {
            return '';
        }

        $str = strtok(str_replace('\\', '/', $str), '?#');
        if ($str === false) {
            return '';
        }

        $str = preg_replace('#/+#', '/', $str) ?? $str;

        while (strncmp($str, './', 2) === 0) {
            $str = substr($str, 2) ?: '';
        }

        if ($str === '.' || $str === '') {
            return '';
        }

        return $str;
    }

    private static function normalizeUploadSubPathOption(?string $path): string
    {
        if ($path === null) {
            return '';
        }

        $candidate = trim($path);
        if ($candidate === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            $parsed    = parse_url($candidate, PHP_URL_PATH) ?: '';
            $candidate = $parsed !== '' ? $parsed : '';
        }

        $candidate = strtr($candidate, ['\\' => '/']);
        $candidate = preg_replace('#/+#', '/', $candidate) ?? $candidate;
        $candidate = trim($candidate, '/');
        if ($candidate === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $candidate), static fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return '';
        }

        if (strcasecmp($segments[0], 'public') === 0) {
            array_shift($segments);
        }

        $base         = CrudConfig::getUploadPath();
        $base         = strtr(trim($base), ['\\' => '/']);
        $base         = preg_replace('#/+#', '/', $base) ?? $base;
        $baseSegments = array_values(array_filter(explode('/', trim($base, '/')), static fn(string $segment): bool => $segment !== ''));

        if ($segments !== [] && $baseSegments !== []) {
            $lastBase = $baseSegments[count($baseSegments) - 1];
            if ($lastBase !== '' && strcasecmp($segments[0], $lastBase) === 0) {
                array_shift($segments);
            }
        }

        return implode('/', $segments);
    }

    /**
     * @param array<string, mixed>|null $changeParams
     */
    private static function resolveStoredFileName(string $name, ?array $changeParams): string
    {
        $normalized = self::normalizeStoredImageName($name);
        if ($normalized === '') {
            return '';
        }

        $path = null;
        if (isset($changeParams['path'])) {
            $pathCandidate = is_scalar($changeParams['path']) ? (string) $changeParams['path'] : null;
            if ($pathCandidate !== null && $pathCandidate !== '') {
                $path = self::normalizeUploadSubPathOption($pathCandidate);
            }
        }

        if ($path !== '' && $path !== null && !str_contains($normalized, '/') && !str_contains($normalized, '\\')) {
            $normalized = $path . '/' . $normalized;
        }

        return $normalized;
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

    public function compact_pagination(bool $enabled = true): self
    {
        $this->config['compact_pagination'] = (bool) $enabled;

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
     * @param string|array<int, string> $columns
     * @param bool|string|array<int, string> $condition Boolean or callable receiving (array $row, array $context, Crud $crud)
     */
    public function column_visible_if(string|array $columns, bool|string|array $condition): self
    {
        $list = $this->normalizeList($columns);
        if ($list === []) {
            throw new InvalidArgumentException('column_visible_if requires at least one column.');
        }

        $rule    = $this->normalizePermissionRule($condition);
        $applied = false;

        foreach ($list as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            $this->config['column_visibility_rules'][$normalized] = $rule;
            $applied                                              = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_visible_if requires at least one valid column name.');
        }

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

        if ($label === null) {
            unset($this->config['column_labels'][$column]);
            return $this;
        }

        $resolvedLabel = trim((string) $label);
        if ($resolvedLabel === '') {
            $this->config['column_labels'][$column] = '';
            return $this;
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

        if ($label === null) {
            unset($this->config['field_labels'][$field]);
            return $this;
        }

        $resolvedLabel = trim((string) $label);

        if ($resolvedLabel === '') {
            $this->config['field_labels'][$field] = '';
            return $this;
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
    public function column_callback(string|array $columns, string|array $callback): self
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
            $applied                                       = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_callback requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * Register multiple column callbacks in a single call.
     *
     * Pass an associative array where each key is a comma-separated list of columns
     * (or a single column) and each value is the callable definition accepted by
     * {@see column_callback()}.
     *
     * @param array<int|string, mixed> $definitions
     */
    public function column_callbacks(array $definitions): self
    {
        if ($definitions === []) {
            throw new InvalidArgumentException('column_callbacks requires at least one definition.');
        }

        $applied = $this->applyBatchCallbacks($definitions, 'column');

        if (!$applied) {
            throw new InvalidArgumentException('column_callbacks requires at least one valid definition.');
        }

        return $this;
    }

    /**
     * Attach Bootstrap tooltip text to grid cells for one or more columns.
     *
     * Pass static text (patterns like `{value}` and `{column}` are supported) or a
     * callable receiving ($value, array $row, string $column, string $display).
     *
     * @param string|array<int, string> $columns
     * @param string|array<int|string, mixed>|null $tooltip
     */
    public function column_tooltip(string|array $columns, string|array|null $tooltip): self
    {
        $list = $this->normalizeList($columns);
        if ($list === []) {
            throw new InvalidArgumentException('column_tooltip requires at least one column.');
        }

        $definition = $this->normalizeColumnTooltipDefinition($tooltip);
        $applied    = false;

        foreach ($list as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            if ($definition['type'] === 'none') {
                unset($this->config['column_tooltips'][$normalized]);
            } else {
                $this->config['column_tooltips'][$normalized] = $definition;
            }
            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_tooltip requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * Register multiple column tooltips in one call.
     *
     * @param array<int|string, mixed> $definitions
     */
    public function column_tooltips(array $definitions): self
    {
        if ($definitions === []) {
            throw new InvalidArgumentException('column_tooltips requires at least one definition.');
        }

        $applied = false;
        foreach ($definitions as $key => $definition) {
            $targets = null;
            $tooltip = null;

            if (is_string($key)) {
                $targets = $key;
                $tooltip = $definition;
            } elseif (is_array($definition)) {
                if (array_key_exists('columns', $definition) && array_key_exists('tooltip', $definition)) {
                    $targets = $definition['columns'];
                    $tooltip = $definition['tooltip'];
                } elseif (array_key_exists('columns', $definition) && array_key_exists('callback', $definition)) {
                    $targets = $definition['columns'];
                    $tooltip = ['type' => 'callback', 'callable' => $definition['callback']];
                } elseif (array_key_exists(0, $definition) && array_key_exists(1, $definition)) {
                    $targets = $definition[0];
                    $tooltip = $definition[1];
                }
            }

            if ($targets === null) {
                throw new InvalidArgumentException('Each column_tooltips entry must provide columns and tooltip.');
            }

            if (!is_string($targets) && !is_array($targets)) {
                throw new InvalidArgumentException('Column tooltip targets must be a string or array of column names.');
            }

            if (!is_string($tooltip) && !is_array($tooltip) && $tooltip !== null) {
                throw new InvalidArgumentException('Column tooltip must be a string, callable array, tooltip definition array, or null.');
            }

            $this->column_tooltip($targets, $tooltip);
            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_tooltips requires at least one valid definition.');
        }

        return $this;
    }

    /**
     * Register a computed column that is not part of the underlying table.
     *
     * The callback receives the current row array and should return the value to display.
     * Returned strings are injected as raw HTML in the grid, so escape the output yourself
     * if it comes from an untrusted source.
     */
    public function custom_column(string $column, string|array $callback): self
    {
        $normalizedColumn = $this->normalizeColumnReference($column);
        if ($normalizedColumn === '') {
            throw new InvalidArgumentException('Custom column name cannot be empty.');
        }

        $serialized = $this->normalizeCallable($callback);

        if (!is_callable($serialized)) {
            throw new InvalidArgumentException('Provided callback is not callable: ' . $serialized);
        }

        $this->config['custom_columns'][$normalizedColumn] = $serialized;
        $this->disable_sort($normalizedColumn);

        return $this;
    }

    /**
     * Register multiple custom columns using associative "column list" => callable definitions.
     *
     * Keys accept a single column or a comma-separated list; values are forwarded to
     * {@see custom_column()} for validation.
     *
     * @param array<string, string|array<int, string>> $definitions
     */
    public function custom_columns(array $definitions): self
    {
        if ($definitions === []) {
            throw new InvalidArgumentException('custom_columns requires at least one definition.');
        }

        $applied = false;

        foreach ($definitions as $columns => $callback) {
            if (!is_string($columns)) {
                throw new InvalidArgumentException('custom_columns keys must be strings of column names.');
            }

            $targets = $this->normalizeList($columns);
            if ($targets === []) {
                continue;
            }

            foreach ($targets as $column) {
                $this->custom_column($column, $callback);
                $applied = true;
            }
        }

        if (!$applied) {
            throw new InvalidArgumentException('custom_columns requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * Apply a callback to transform form field values before they are sent to the client.
     *
     * The callback receives the field name, the current value, the full row array, and the form
     * mode (`edit`, `create`, or `view`). Whatever value it returns (including null) replaces the
     * existing field value. Return an array with an `html` key or a plain string (text or markup)
     * to provide custom form controls rendered as raw HTML. FastCRUD also runs these callbacks
     * while building the per-mode form templates (before a record exists); those placeholder rows
     * are marked with `__fastcrud_template => true` and contain null column values. When returning
     * custom markup, include your own inputs with `data-fastcrud-field="{field}"` so the Ajax
     * submit logic can capture the value.
     */
    public function field_callback(string|array $fields, string|array $callback): self
    {
        $list = $this->normalizeList($fields);
        if ($list === []) {
            throw new InvalidArgumentException('field_callback requires at least one field.');
        }

        $serialized = $this->normalizeCallable($callback);

        if (!is_callable($serialized)) {
            throw new InvalidArgumentException('Provided callback is not callable: ' . $serialized);
        }

        $applied = false;

        foreach ($list as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized === '') {
                continue;
            }

            $this->config['field_callbacks'][$normalized] = $serialized;
            $applied                                      = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('field_callback requires at least one valid field name.');
        }

        return $this;
    }

    /**
     * Bulk assign field callbacks using the same associative shape as `column_callbacks()`.
     *
     * @param array<int|string, mixed> $definitions
     */
    public function fields_callbacks(array $definitions): self
    {
        if ($definitions === []) {
            throw new InvalidArgumentException('fields_callbacks requires at least one definition.');
        }

        $applied = $this->applyBatchCallbacks($definitions, 'field');

        if (!$applied) {
            throw new InvalidArgumentException('fields_callbacks requires at least one valid definition.');
        }

        return $this;
    }

    /**
     * Alias of {@see fields_callbacks()} for readability.
     *
     * @param array<int|string, mixed> $definitions
     */
    public function field_callbacks(array $definitions): self
    {
        return $this->fields_callbacks($definitions);
    }

    /**
     * Register a form field that is not stored in the database.
     *
     * The callback receives the field name, the initial value (or null), the row array, and the
     * current form mode. FastCRUD also invokes the callback to build the per-mode form templates
     * before a record exists; those placeholder rows expose `__fastcrud_template => true` and the
     * column values will be null. Return a string of HTML or an array with `html`/`value` keys to
     * inject custom form controls. Escape the markup yourself if it contains untrusted data.
     */
    public function custom_field(string $field, string|array $callback): self
    {
        $normalizedField = $this->normalizeColumnReference($field);
        if ($normalizedField === '') {
            throw new InvalidArgumentException('Custom field name cannot be empty.');
        }

        $serialized = $this->normalizeCallable($callback);

        if (!is_callable($serialized)) {
            throw new InvalidArgumentException('Provided callback is not callable: ' . $serialized);
        }

        $this->config['custom_fields'][$normalizedField] = $serialized;
        $this->addFormColumns([$normalizedField]);

        return $this;
    }

    /**
     * @param array<int|string, mixed> $definitions
     */
    private function applyBatchCallbacks(array $definitions, string $type): bool
    {
        $applied  = false;
        $listKeys = $type === 'column' ? ['columns'] : ['fields', 'columns'];

        foreach ($definitions as $key => $definition) {
            [$targets, $callback] = $this->normalizeBatchCallbackDefinition($key, $definition, $listKeys, $type);

            if ($type === 'column') {
                $this->column_callback($targets, $callback);
            } else {
                $this->field_callback($targets, $callback);
            }

            $applied = true;
        }

        return $applied;
    }

    /**
     * @param array<int, string> $listKeys
     * @return array{0: string|array<int, string>, 1: string|array<int, string>}
     */
    private function normalizeBatchCallbackDefinition(int|string $key, mixed $definition, array $listKeys, string $type): array
    {
        $targets  = null;
        $callback = null;

        if (is_string($key)) {
            $targets  = $key;
            $callback = $definition;
        } elseif (is_array($definition)) {
            foreach ($listKeys as $listKey) {
                if (array_key_exists($listKey, $definition) && array_key_exists('callback', $definition)) {
                    $targets  = $definition[$listKey];
                    $callback = $definition['callback'];
                    break;
                }
            }

            if ($targets === null && array_key_exists(0, $definition) && array_key_exists(1, $definition)) {
                $targets  = $definition[0];
                $callback = $definition[1];
            }
        }

        if ($targets === null || $callback === null) {
            $label = $type === 'column' ? 'columns' : 'fields';
            throw new InvalidArgumentException(sprintf('Each %s_callbacks entry must provide %s and callback.', $type, $label));
        }

        return [$targets, $callback];
    }

    /**
     * Register a lifecycle callback for CRUD mutations.
     */
    private function registerLifecycleCallback(string $event, string|array $callback): self
    {
        if (!in_array($event, self::LIFECYCLE_EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported lifecycle event: ' . $event);
        }

        $serialized = $this->normalizeCallable($callback);

        if (!is_callable($serialized)) {
            throw new InvalidArgumentException('Provided callback is not callable: ' . $serialized);
        }

        $this->config['lifecycle_callbacks'][$event][] = $serialized;

        return $this;
    }

    public function before_insert(string|array $callback): self
    {
        return $this->registerLifecycleCallback('before_insert', $callback);
    }

    public function after_insert(string|array $callback): self
    {
        return $this->registerLifecycleCallback('after_insert', $callback);
    }

    public function before_create(string|array $callback): self
    {
        return $this->before_insert($callback);
    }

    public function after_create(string|array $callback): self
    {
        return $this->after_insert($callback);
    }

    public function before_update(string|array $callback): self
    {
        return $this->registerLifecycleCallback('before_update', $callback);
    }

    public function after_update(string|array $callback): self
    {
        return $this->registerLifecycleCallback('after_update', $callback);
    }

    public function before_delete(string|array $callback): self
    {
        return $this->registerLifecycleCallback('before_delete', $callback);
    }

    public function after_delete(string|array $callback): self
    {
        return $this->registerLifecycleCallback('after_delete', $callback);
    }

    public function before_fetch(string|array $callback): self
    {
        return $this->registerLifecycleCallback('before_fetch', $callback);
    }

    public function after_fetch(string|array $callback): self
    {
        return $this->registerLifecycleCallback('after_fetch', $callback);
    }

    public function before_read(string|array $callback): self
    {
        return $this->registerLifecycleCallback('before_read', $callback);
    }

    public function after_read(string|array $callback): self
    {
        return $this->registerLifecycleCallback('after_read', $callback);
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
            $applied                                     = true;
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
            $applied                                    = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_width requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * @param string|array<int, string> $columns
     */
    public function column_truncate(string|array $columns, int $length, string $suffix = '…'): self
    {
        $columnList = $this->normalizeList($columns);
        if ($columnList === []) {
            throw new InvalidArgumentException('column_truncate requires at least one column.');
        }

        if ($length < 1) {
            throw new InvalidArgumentException('Column truncate length must be at least 1.');
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
            $applied                                  = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_truncate requires at least one valid column name.');
        }

        return $this;
    }

    /**
     * Override the default truncation applied to every column unless column_truncate() is used.
     *
     * Pass an integer length to use the default '…' suffix, provide an array like
     * ['length' => 80, 'suffix' => '...'], or null to disable per-instance defaults.
     *
     * @param array{length:int,suffix?:string}|int|null $value
     */
    public function default_column_truncate(array|int|null $value): self
    {
        $this->config['default_column_truncate'] = $this->normalizeDefaultColumnTruncateValue(
            $value,
            'default_column_truncate()'
        );

        return $this;
    }

    /**
     * @deprecated 1.0.x Use column_truncate() instead.
     * @param string|array<int, string> $columns
     */
    public function column_cut(string|array $columns, int $length, string $suffix = '…'): self
    {
        return $this->column_truncate($columns, $length, $suffix);
    }

    /**
     * Format numeric columns as currency/money values.
     *
     * @param string|array<int, string> $columns
     */
    public function asMoney(string|array $columns, string $currency = '', array $options = []): self
    {
        $options['currency'] = $currency;

        return $this->columnFormatter($columns, 'money', $options);
    }

    public function as_money(string|array $columns, string $currency = '', array $options = []): self
    {
        return $this->asMoney($columns, $currency, $options);
    }

    /**
     * Format date columns.
     *
     * @param string|array<int, string> $columns
     */
    public function asDate(string|array $columns, string $format = 'Y-m-d', array $options = []): self
    {
        $options['format'] = $format;

        return $this->columnFormatter($columns, 'date', $options);
    }

    public function as_date(string|array $columns, string $format = 'Y-m-d', array $options = []): self
    {
        return $this->asDate($columns, $format, $options);
    }

    /**
     * Format datetime columns.
     *
     * @param string|array<int, string> $columns
     */
    public function asDateTime(string|array $columns, string $format = 'Y-m-d H:i', array $options = []): self
    {
        $options['format'] = $format;

        return $this->columnFormatter($columns, 'datetime', $options);
    }

    public function as_datetime(string|array $columns, string $format = 'Y-m-d H:i', array $options = []): self
    {
        return $this->asDateTime($columns, $format, $options);
    }

    /**
     * Render column values as Bootstrap badges.
     *
     * `$map` may be `['active' => 'success']` or
     * `['active' => ['label' => 'Active', 'class' => 'bg-success']]`.
     *
     * @param string|array<int, string> $columns
     * @param array<string, mixed> $map
     */
    public function asBadge(string|array $columns, array $map = [], array $options = []): self
    {
        $options['map'] = $map;

        return $this->columnFormatter($columns, 'badge', $options);
    }

    public function as_badge(string|array $columns, array $map = [], array $options = []): self
    {
        return $this->asBadge($columns, $map, $options);
    }

    /**
     * Render boolean-like values with labels/badges instead of raw database values.
     *
     * @param string|array<int, string> $columns
     */
    public function asBoolean(string|array $columns, array $options = []): self
    {
        return $this->columnFormatter($columns, 'boolean', $options);
    }

    public function as_boolean(string|array $columns, array $options = []): self
    {
        return $this->asBoolean($columns, $options);
    }

    /**
     * @param string|array<int, string> $columns
     * @param array<string, mixed> $options
     */
    public function columnFormatter(string|array $columns, string $type, array $options = []): self
    {
        $columnList = $this->normalizeList($columns);
        if ($columnList === []) {
            throw new InvalidArgumentException('columnFormatter requires at least one column.');
        }

        $type = strtolower(trim($type));
        if (!in_array($type, self::SUPPORTED_COLUMN_FORMATTERS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported column formatter "%s".', $type));
        }

        $applied = false;
        foreach ($columnList as $column) {
            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            $this->config['column_formatters'][$normalized] = $this->normalizeColumnFormatterConfig($type, $options);
            $applied                                       = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('columnFormatter requires at least one valid column name.');
        }

        return $this;
    }

    public function column_formatter(string|array $columns, string $type, array $options = []): self
    {
        return $this->columnFormatter($columns, $type, $options);
    }


    /**
     * @param string|array<int, string> $columns
     * @param mixed $value
     */
    public function highlight(string|array $columns, string $operator, mixed $value = null, string $class = 'text-warning'): self
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

            $normalizedCondition = $this->normalizeCondition($normalizedColumn, $operator, $value);

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
     * @param string|array<int, string> $columns
     * @param mixed $value
     */
    public function highlight_row(string|array $columns, string $operator, mixed $value = null, string $class = 'table-warning'): self
    {
        $columnList = $this->normalizeList($columns);
        if ($columnList === []) {
            throw new InvalidArgumentException('highlight_row requires at least one column.');
        }

        $class = $this->normalizeCssClassList($class);

        $applied = false;

        foreach ($columnList as $column) {
            $normalizedColumn = $this->normalizeColumnReference($column);
            if ($normalizedColumn === '') {
                continue;
            }

            $normalizedCondition = $this->normalizeCondition($normalizedColumn, $operator, $value);

            $this->config['row_highlights'][] = [
                'condition' => $normalizedCondition,
                'class'     => $class,
            ];

            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('highlight_row requires at least one valid column name.');
        }

        return $this;
    }

    public function table_title(string $title): self
    {
        $this->config['table_meta']['title'] = trim($title);

        return $this;
    }

    public function hide_table_title(bool $hidden = true): self
    {
        $this->config['table_meta']['hide_title'] = (bool) $hidden;

        return $this;
    }

    public function table_tooltip(string $tooltip): self
    {
        $tooltip                               = trim($tooltip);
        $this->config['table_meta']['tooltip'] = $tooltip === '' ? null : $tooltip;

        return $this;
    }

    public function table_icon(string $iconClass): self
    {
        $iconClass                          = $this->normalizeCssClassList($iconClass);
        $this->config['table_meta']['icon'] = $iconClass === '' ? null : $iconClass;

        return $this;
    }

    public function enable_add(bool $enabled = true): self
    {
        $this->config['table_meta']['add'] = (bool) $enabled;

        return $this;
    }

    public function enable_view(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self
    {
        $this->config['table_meta']['view'] = (bool) $enabled;

        if ($enabled && func_num_args() >= 4) {
            if ($field === false || $operand === false || $value === false) {
                throw new InvalidArgumentException('View condition requires field, operator, and value.');
            }

            $this->config['table_meta']['view_condition'] = $this->normalizeActionCondition((string) $field, (string) $operand, $value);
        } else {
            $this->config['table_meta']['view_condition'] = null;
        }

        return $this;
    }

    public function enable_edit(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self
    {
        $this->config['table_meta']['edit'] = (bool) $enabled;

        if ($enabled && func_num_args() >= 4) {
            if ($field === false || $operand === false || $value === false) {
                throw new InvalidArgumentException('Edit condition requires field, operator, and value.');
            }

            $this->config['table_meta']['edit_condition'] = $this->normalizeActionCondition((string) $field, (string) $operand, $value);
        } else {
            $this->config['table_meta']['edit_condition'] = null;
        }

        return $this;
    }

    public function enable_delete(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self
    {
        $this->config['table_meta']['delete'] = (bool) $enabled;

        if ($enabled && func_num_args() >= 4) {
            if ($field === false || $operand === false || $value === false) {
                throw new InvalidArgumentException('Delete condition requires field, operator, and value.');
            }

            $this->config['table_meta']['delete_condition'] = $this->normalizeActionCondition((string) $field, (string) $operand, $value);
        } else {
            $this->config['table_meta']['delete_condition'] = null;
        }

        return $this;
    }

    public function enable_duplicate(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self
    {
        $this->config['table_meta']['duplicate'] = (bool) $enabled;

        if ($enabled && func_num_args() >= 4) {
            if ($field === false || $operand === false || $value === false) {
                throw new InvalidArgumentException('Duplicate condition requires field, operator, and value.');
            }

            $this->config['table_meta']['duplicate_condition'] = $this->normalizeActionCondition((string) $field, (string) $operand, $value);
        } else {
            $this->config['table_meta']['duplicate_condition'] = null;
        }

        return $this;
    }

    public function enable_batch_delete(bool $enabled = true): self
    {
        $enabled = (bool) $enabled;

        $this->config['table_meta']['batch_delete']        = $enabled;
        $this->config['table_meta']['batch_delete_button'] = $enabled;

        return $this;
    }

    public function add_bulk_action(string $name, string $label, array $options = []): self
    {
        $action = $this->normalizeBulkActionDefinition($name, $label, $options);

        if (!isset($this->config['table_meta']['bulk_actions']) || !is_array($this->config['table_meta']['bulk_actions'])) {
            $this->config['table_meta']['bulk_actions'] = [];
        }

        $this->config['table_meta']['bulk_actions'][] = $action;

        return $this;
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     */
    public function set_bulk_actions(array $actions): self
    {
        $normalized = [];

        foreach ($actions as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name  = isset($entry['name']) ? (string) $entry['name'] : '';
            $label = isset($entry['label']) ? (string) $entry['label'] : $name;

            $options = $entry;
            unset($options['name'], $options['label']);

            $normalized[] = $this->normalizeBulkActionDefinition($name, $label, $options);
        }

        $this->config['table_meta']['bulk_actions'] = $normalized;

        return $this;
    }

    /**
     * Enable soft delete mode by updating one or more columns instead of hard deleting rows.
     *
     * Supported option keys:
     * - mode: 'timestamp', 'literal', or 'expression' (defaults to 'timestamp')
     * - value: scalar value or expression string (required for literal/expression modes)
     * - additional: array<string, mixed> of extra column assignments (scalars or option arrays)
     * - assignments: array mapping columns to assignment definitions (overrides other options)
     */
    public function enable_soft_delete(string $column, array $options = []): self
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidArgumentException('Soft delete column name is required.');
        }

        if (isset($options['assignments']) && is_array($options['assignments'])) {
            return $this->set_soft_delete_assignments($options['assignments']);
        }

        $mode = isset($options['mode']) ? strtolower((string) $options['mode']) : 'timestamp';
        if (!in_array($mode, self::SUPPORTED_SOFT_DELETE_MODES, true)) {
            $message = sprintf(
                'Invalid soft delete mode "%s". Allowed modes: %s.',
                $mode,
                implode(', ', self::SUPPORTED_SOFT_DELETE_MODES)
            );
            throw new InvalidArgumentException($message);
        }

        $assignments = [
            $column => [
                'mode'  => $mode,
                'value' => $options['value'] ?? null,
            ],
        ];

        if (isset($options['additional']) && is_array($options['additional'])) {
            foreach ($options['additional'] as $extraColumn => $definition) {
                $assignments[$extraColumn] = $definition;
            }
        }

        return $this->set_soft_delete_assignments($assignments);
    }

    /**
     * Replace soft delete assignments wholesale. Keys may be column names or indexed arrays containing
     * a 'column' key along with optional 'mode' and 'value' keys.
     *
     * @param array<int|string, mixed> $assignments
     */
    public function set_soft_delete_assignments(array $assignments): self
    {
        $normalized = $this->normalizeSoftDeleteAssignmentsForConfig($assignments);

        if ($normalized === []) {
            throw new InvalidArgumentException('Soft delete configuration requires at least one assignment.');
        }

        $this->config['soft_delete'] = ['assignments' => $normalized];

        return $this;
    }

    public function disable_soft_delete(): self
    {
        $this->config['soft_delete'] = null;

        return $this;
    }

    public function enableRowOrdering(string $column = 'sort_order'): self
    {
        $column = $this->normalizeColumnReference($column);
        if ($column === '') {
            throw new InvalidArgumentException('Row ordering column cannot be empty.');
        }

        $columnLookup = $this->getTableColumnLookupFor($this->table);
        if (!isset($columnLookup[$column])) {
            throw new InvalidArgumentException(sprintf('Unknown row ordering column "%s".', $column));
        }

        $this->config['row_ordering'] = [
            'enabled' => true,
            'column'  => $column,
        ];

        $this->applyRowOrderingSort();

        return $this;
    }

    public function enable_row_ordering(string $column = 'sort_order'): self
    {
        return $this->enableRowOrdering($column);
    }

    public function disableRowOrdering(): self
    {
        $this->config['row_ordering'] = [
            'enabled' => false,
            'column'  => null,
        ];

        return $this;
    }

    public function disable_row_ordering(): self
    {
        return $this->disableRowOrdering();
    }

    private function isRowOrderingEnabled(): bool
    {
        $config = $this->config['row_ordering'] ?? [];

        return is_array($config)
            && (bool) ($config['enabled'] ?? false)
            && isset($config['column'])
            && is_string($config['column'])
            && $config['column'] !== '';
    }

    private function getRowOrderingColumn(): ?string
    {
        $config = $this->config['row_ordering'] ?? [];
        if (!is_array($config) || !isset($config['column']) || !is_string($config['column'])) {
            return null;
        }

        $column = $this->normalizeColumnReference($config['column']);

        return $column === '' ? null : $column;
    }

    private function applyRowOrderingSort(): void
    {
        if (!$this->isRowOrderingEnabled()) {
            return;
        }

        $column = $this->getRowOrderingColumn();
        if ($column === null) {
            return;
        }

        $orders = [];
        foreach ($this->config['order_by'] as $order) {
            if (!is_array($order) || !isset($order['field'])) {
                continue;
            }

            if ($this->normalizeColumnReference((string) $order['field']) === $column) {
                continue;
            }

            $orders[] = $order;
        }

        array_unshift($orders, [
            'field'     => $column,
            'direction' => 'ASC',
        ]);

        $this->config['order_by'] = $orders;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{enabled: bool, column: string|null}
     */
    private function normalizeRowOrderingConfig(array $config): array
    {
        $enabled = (bool) ($config['enabled'] ?? false);
        $column  = isset($config['column']) && is_string($config['column'])
            ? $this->normalizeColumnReference($config['column'])
            : null;

        if ($enabled && ($column === null || $column === '')) {
            throw new InvalidArgumentException('row_ordering configuration requires a column when enabled.');
        }

        return [
            'enabled' => $enabled,
            'column'  => $column === '' ? null : $column,
        ];
    }

    /**
     * Enable persistent audit logging for create, update, delete, and duplicate operations.
     *
     * Supported options:
     * - user_id: scalar identifier stored with every audit row
     * - user_id_callback: callable string or [ClassName, method] returning the current user id
     *
     * @param array{user_id?: mixed, user_id_callback?: string|array<int, string>} $options
     */
    public function enableAuditLog(array $options = []): self
    {
        $this->config['audit_log'] = $this->normalizeAuditLogConfig(array_replace(
            ['enabled' => true],
            $options
        ));

        Database::ensureAuditLogTable($this->connection);

        return $this;
    }

    public function enable_audit_log(array $options = []): self
    {
        return $this->enableAuditLog($options);
    }

    public function disableAuditLog(): self
    {
        $this->config['audit_log'] = $this->normalizeAuditLogConfig(['enabled' => false]);

        return $this;
    }

    public function disable_audit_log(): self
    {
        return $this->disableAuditLog();
    }

    /**
     * @param array<string, mixed> $config
     * @return array{enabled: bool, user_id: mixed, user_id_callback: string|null}
     */
    private function normalizeAuditLogConfig(array $config): array
    {
        $enabled = isset($config['enabled']) ? (bool) $config['enabled'] : true;

        $userId = $config['user_id'] ?? null;
        if ($userId !== null && !is_scalar($userId)) {
            throw new InvalidArgumentException('audit_log user_id must be a scalar value or null.');
        }

        $callback = null;
        if (isset($config['user_id_callback'])) {
            $callbackConfig = $config['user_id_callback'];
            if (!is_string($callbackConfig) && !is_array($callbackConfig)) {
                throw new InvalidArgumentException('audit_log user_id_callback must be a callable string or [ClassName, method] pair.');
            }

            $callback = $this->normalizeCallable($callbackConfig);
            if (!is_callable($callback)) {
                throw new InvalidArgumentException('audit_log user_id_callback is not callable: ' . $callback);
            }
        }

        return [
            'enabled'          => $enabled,
            'user_id'          => $userId,
            'user_id_callback' => $callback,
        ];
    }

    private function isAuditLogEnabled(): bool
    {
        $config = $this->config['audit_log'] ?? [];

        return $this->table !== 'fastcrud_audit_logs' && is_array($config) && (bool) ($config['enabled'] ?? false);
    }

    private function resolveAuditUserId(): ?string
    {
        $config = $this->config['audit_log'] ?? [];
        if (!is_array($config)) {
            return null;
        }

        $callback = $config['user_id_callback'] ?? null;
        if (is_string($callback) && $callback !== '' && is_callable($callback)) {
            $value = call_user_func($callback);
            return $value === null ? null : $this->stringifyValue($value);
        }

        $userId = $config['user_id'] ?? null;

        return $userId === null ? null : $this->stringifyValue($userId);
    }

    private function resolveAuditIpAddress(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && trim($ip) !== '' ? trim($ip) : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeAuditLog(string $action, mixed $recordId, ?string $fieldName, mixed $oldValue, mixed $newValue, array $metadata = []): void
    {
        if (!$this->isAuditLogEnabled()) {
            return;
        }

        try {
            // AJAX writes use the schema prepared by init() or enableAuditLog().
            if (!CrudAjax::isAjaxRequest()) {
                Database::ensureAuditLogTable($this->connection);
            }

            $statement = $this->connection->prepare(
                'INSERT INTO fastcrud_audit_logs
                    (table_name, record_id, action, field_name, old_value, new_value, user_id, ip_address, metadata)
                 VALUES
                    (:table_name, :record_id, :action, :field_name, :old_value, :new_value, :user_id, :ip_address, :metadata)'
            );

            if ($statement === false) {
                throw new RuntimeException('Failed to prepare audit log statement.');
            }

            $statement->execute([
                ':table_name' => $this->table,
                ':record_id'  => $recordId === null ? null : $this->stringifyValue($recordId),
                ':action'     => $action,
                ':field_name' => $fieldName,
                ':old_value'  => $this->encodeAuditValue($oldValue),
                ':new_value'  => $this->encodeAuditValue($newValue),
                ':user_id'    => $this->resolveAuditUserId(),
                ':ip_address' => $this->resolveAuditIpAddress(),
                ':metadata'   => $metadata === [] ? null : $this->encodeAuditJson($metadata),
            ]);
        } catch (Throwable $exception) {
            if (CrudConfig::$debug) {
                throw new RuntimeException('Failed to write audit log.', 0, $exception);
            }
        }
    }

    private function encodeAuditValue(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value), is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            default => $this->encodeAuditJson($value),
        };
    }

    private function encodeAuditJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return $this->stringifyValue($value);
        }
    }

    private function auditValuesEqual(mixed $oldValue, mixed $newValue): bool
    {
        if ($oldValue === null || $newValue === null) {
            return $oldValue === $newValue;
        }

        return $this->stringifyValue($oldValue) === $this->stringifyValue($newValue);
    }

    /**
     * @param array<int|string, mixed> $assignments
     * @return array<int, array{column: string, mode: string, value: mixed}>
     */
    private function normalizeSoftDeleteAssignmentsForConfig(array $assignments): array
    {
        $normalized = [];

        foreach ($assignments as $key => $definition) {
            if (is_int($key) && is_array($definition) && isset($definition['column'])) {
                $column       = (string) $definition['column'];
                $normalized[] = $this->normalizeSoftDeleteAssignment($column, $definition, 'literal');
                continue;
            }

            $column = null;
            if (is_string($key) && $key !== '') {
                $column = $key;
            } elseif (is_array($definition) && isset($definition['column'])) {
                $candidate = trim((string) $definition['column']);
                if ($candidate !== '') {
                    $column = $candidate;
                }
            }

            if ($column === null) {
                throw new InvalidArgumentException('Soft delete assignments must specify a column name.');
            }

            $normalized[] = $this->normalizeSoftDeleteAssignment($column, $definition, 'literal');
        }

        return $normalized;
    }

    private function normalizeSoftDeleteAssignment(string $column, mixed $definition, string $defaultMode): array
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidArgumentException('Soft delete column name cannot be empty.');
        }

        $mode  = $defaultMode;
        $value = null;

        if (is_array($definition)) {
            if (isset($definition['column'])) {
                $candidate = trim((string) $definition['column']);
                if ($candidate !== '') {
                    $column = $candidate;
                }
            }

            if (isset($definition['mode'])) {
                $mode = strtolower((string) $definition['mode']);
            }

            if (array_key_exists('value', $definition)) {
                $value = $definition['value'];
            }
        } elseif ($definition !== null) {
            $mode  = 'literal';
            $value = $definition;
        } else {
            if ($defaultMode !== 'timestamp') {
                $mode = 'literal';
            }
        }

        if (!in_array($mode, self::SUPPORTED_SOFT_DELETE_MODES, true)) {
            $message = sprintf(
                'Invalid soft delete mode "%s" for column "%s". Allowed modes: %s.',
                $mode,
                $column,
                implode(', ', self::SUPPORTED_SOFT_DELETE_MODES)
            );
            throw new InvalidArgumentException($message);
        }

        if ($mode === 'expression') {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Soft delete expression for column "%s" must be a non-empty string.', $column));
            }
            $value = trim((string) $value);
        }

        return [
            'column' => $column,
            'mode'   => $mode,
            'value'  => $mode === 'timestamp' ? null : ($value ?? null),
        ];
    }

    private function normalizeBulkActionDefinition(string $name, string $label, array $options): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Bulk action name is required.');
        }

        $label = trim($label);
        if ($label === '') {
            $label = ucfirst($name);
        }

        if (isset($options['type'])) {
            $providedType = strtolower((string) $options['type']);
            if ($providedType !== '' && $providedType !== 'update') {
                throw new InvalidArgumentException('Bulk actions no longer support the "delete" type. Use enable_batch_delete().');
            }
        }

        if (array_key_exists('mode', $options)) {
            throw new InvalidArgumentException('Bulk actions no longer accept a mode option.');
        }

        if (array_key_exists('operation', $options)) {
            throw new InvalidArgumentException('Bulk actions no longer accept a custom operation.');
        }

        $confirm = isset($options['confirm']) ? trim((string) $options['confirm']) : null;
        if ($confirm === '') {
            $confirm = null;
        }

        $fieldsOption = $options['fields'] ?? [];
        if (!is_array($fieldsOption)) {
            throw new InvalidArgumentException('Bulk update action requires a fields array.');
        }

        $fields = [];
        foreach ($fieldsOption as $column => $value) {
            if (!is_string($column)) {
                continue;
            }

            $normalizedColumn = trim($column);
            if ($normalizedColumn === '') {
                continue;
            }

            $fields[$normalizedColumn] = $value;
        }

        if ($fields === []) {
            throw new InvalidArgumentException('Bulk update action requires at least one column assignment.');
        }

        $result = [
            'name'   => $name,
            'label'  => $label,
            'fields' => $fields,
        ];

        if ($confirm !== null) {
            $result['confirm'] = $confirm;
        }

        if (isset($options['payload']) && is_array($options['payload']) && $options['payload'] !== []) {
            $result['payload'] = $options['payload'];
        }

        return $result;
    }

    private function isSoftDeleteEnabled(): bool
    {
        $config = $this->config['soft_delete'] ?? null;
        if (!is_array($config)) {
            return false;
        }

        $assignments = $config['assignments'] ?? [];
        return is_array($assignments) && $assignments !== [];
    }

    /**
     * @return array<int, array{column: string, mode: string, value: mixed}>
     */
    private function getSoftDeleteAssignments(): array
    {
        if (!$this->isSoftDeleteEnabled()) {
            return [];
        }

        /** @var array<int, array{column: string, mode: string, value: mixed}> $assignments */
        $assignments = $this->config['soft_delete']['assignments'];
        return $assignments;
    }

    /**
     * @param array<int, array{column: string, mode: string, value: mixed}> $assignments
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $resolvedValues
     */
    private function buildSoftDeleteUpdateClause(array $assignments, array &$parameters, string $parameterPrefix, array &$resolvedValues): string
    {
        if ($assignments === []) {
            throw new RuntimeException('Soft delete configuration produced no assignments.');
        }

        $clauses = [];
        $index   = 0;

        foreach ($assignments as $assignment) {
            $column    = $assignment['column'];
            $mode      = $assignment['mode'];
            $columnSql = $this->quoteIdentifierPart($column);

            if ($mode === 'expression') {
                $expression              = (string) $assignment['value'];
                $clauses[]               = sprintf('%s = %s', $columnSql, $expression);
                $resolvedValues[$column] = null;
                continue;
            }

            $value = $mode === 'timestamp'
                ? date('Y-m-d H:i:s')
                : ($assignment['value'] ?? null);

            $placeholder              = sprintf(':%s_%d', $parameterPrefix, $index++);
            $clauses[]                = sprintf('%s = %s', $columnSql, $placeholder);
            $parameters[$placeholder] = $value;
            $resolvedValues[$column]  = $value;
        }

        return implode(', ', $clauses);
    }

    /**
     * @param array<string, mixed>|null $row
     * @param array<int, array{column: string, mode: string, value: mixed}> $assignments
     * @param array<string, mixed> $resolvedValues
     */
    private function softDeleteAssignmentsSatisfied(?array $row, array $assignments, array $resolvedValues): bool
    {
        if ($row === null) {
            return false;
        }

        foreach ($assignments as $assignment) {
            if ($assignment['mode'] === 'expression') {
                continue;
            }

            $column = $assignment['column'];
            if (!array_key_exists($column, $row)) {
                return false;
            }

            $expected = $resolvedValues[$column] ?? null;
            $actual   = $row[$column];

            if ($expected === null) {
                if ($actual !== null) {
                    return false;
                }
                continue;
            }

            if ($actual == $expected) { // phpcs:ignore
                continue;
            }

            return false;
        }

        return true;
    }

    public function enable_delete_confirm(bool $enabled = true): self
    {
        $this->config['table_meta']['delete_confirm'] = (bool) $enabled;

        return $this;
    }

    public function enable_export_csv(bool $enabled = true): self
    {
        $this->config['table_meta']['export_csv'] = (bool) $enabled;

        return $this;
    }

    public function enable_numbers(bool $enabled = true): self
    {
        $this->config['numbers_enabled'] = (bool) $enabled;

        return $this;
    }

    public function enable_select2(bool $enabled = true): self
    {
        $this->config['select2'] = (bool) $enabled;

        return $this;
    }

    public function enable_filters(bool $enabled = true): self
    {
        $this->config['filters_enabled'] = (bool) $enabled;

        return $this;
    }

    /**
     * @param string|array<string, mixed> $urlOrConfig
     * @param array<string, bool|float|int|string> $options
     */
    public function add_link_button(string|array $urlOrConfig, ?string $iconClass = null, ?string $label = null, ?string $buttonClass = null, array $options = []): self
    {
        if (is_array($urlOrConfig)) {
            $payload = $urlOrConfig;

            if ($iconClass !== null && !array_key_exists('icon', $payload)) {
                $payload['icon'] = $iconClass;
            }

            if ($label !== null && !array_key_exists('label', $payload)) {
                $payload['label'] = $label;
            }

            if ($buttonClass !== null && !array_key_exists('button_class', $payload)) {
                $payload['button_class'] = $buttonClass;
            }

            if ($options !== []) {
                if (!isset($payload['options']) || !is_array($payload['options'])) {
                    $payload['options'] = $options;
                } else {
                    $payload['options'] = array_merge($payload['options'], $options);
                }
            }
        } else {
            if ($iconClass === null) {
                throw new InvalidArgumentException('Link button requires both a URL and icon class when using the legacy signature.');
            }

            $payload = [
                'url'          => $urlOrConfig,
                'icon'         => $iconClass,
                'label'        => $label,
                'button_class' => $buttonClass,
                'options'      => $options,
            ];
        }

        $normalized = $this->normalizeLinkButtonConfigPayload($payload);

        if ($normalized === null) {
            throw new InvalidArgumentException('Link button requires a non-empty URL and icon class.');
        }

        if (!isset($this->config['link_buttons']) || !is_array($this->config['link_buttons'])) {
            $this->config['link_buttons'] = [];
        }

        $this->config['link_buttons'][] = $normalized;
        $this->appendActionButtonSequence('link');

        return $this;
    }

    /**
     * @param string|array<string, mixed> $urlOrConfig
     * @param array<string, bool|float|int|string> $options
     */
    public function add_toolbar_action(string|array $urlOrConfig, ?string $iconClass = null, ?string $label = null, ?string $buttonClass = null, array $options = []): self
    {
        if (is_array($urlOrConfig)) {
            $payload = $urlOrConfig;

            if ($iconClass !== null && !array_key_exists('icon', $payload)) {
                $payload['icon'] = $iconClass;
            }

            if ($label !== null && !array_key_exists('label', $payload)) {
                $payload['label'] = $label;
            }

            if ($buttonClass !== null && !array_key_exists('button_class', $payload)) {
                $payload['button_class'] = $buttonClass;
            }

            if ($options !== []) {
                if (!isset($payload['options']) || !is_array($payload['options'])) {
                    $payload['options'] = $options;
                } else {
                    $payload['options'] = array_merge($payload['options'], $options);
                }
            }
        } else {
            if ($iconClass === null) {
                throw new InvalidArgumentException('Toolbar action requires both a URL and icon class when using the legacy signature.');
            }

            $payload = [
                'url'          => $urlOrConfig,
                'icon'         => $iconClass,
                'label'        => $label,
                'button_class' => $buttonClass,
                'options'      => $options,
            ];
        }

        $normalized = $this->normalizeLinkButtonConfigPayload($payload);
        if ($normalized === null) {
            throw new InvalidArgumentException('Toolbar action requires a non-empty URL and icon class.');
        }

        if (!isset($this->config['table_meta']['toolbar_actions']) || !is_array($this->config['table_meta']['toolbar_actions'])) {
            $this->config['table_meta']['toolbar_actions'] = [];
        }

        $this->config['table_meta']['toolbar_actions'][] = $normalized;

        return $this;
    }

    public function add_toolbar_html(string $html): self
    {
        $normalized = $this->normalizeToolbarHtmlConfigPayload(['html' => $html]);
        if ($normalized === null) {
            throw new InvalidArgumentException('Toolbar HTML requires a non-empty HTML string.');
        }

        if (!isset($this->config['table_meta']['toolbar_html']) || !is_array($this->config['table_meta']['toolbar_html'])) {
            $this->config['table_meta']['toolbar_html'] = [];
        }

        $this->config['table_meta']['toolbar_html'][] = $normalized;

        return $this;
    }

    public function add_toolbar_custom_html(string $html): self
    {
        return $this->add_toolbar_html($html);
    }

    /**
     * @param array<string, mixed> $mainButton
     * @param array<int, array<string, mixed>> $items
     */
    public function add_multi_link_button(array $mainButton = [], array $items = []): self
    {
        $normalized = $this->normalizeMultiLinkButtonConfigPayload([
            'button' => $mainButton,
            'items'  => $items,
        ]);

        if ($normalized === null) {
            throw new InvalidArgumentException('Multi link button requires at least one item with both label and URL.');
        }

        if (!isset($this->config['multi_link_buttons']) || !is_array($this->config['multi_link_buttons'])) {
            $this->config['multi_link_buttons'] = [];
        }

        $this->config['multi_link_buttons'][] = $normalized;
        $this->appendActionButtonSequence('multi');

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

        $applied    = false;
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
    public function fields(
        string|array $fields,
        bool $reverse = false,
        string|false $tab = false,
        string|array|false $mode = false,
        string|false $section = false,
    ): self {
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
            $tabName   = $candidate === '' ? null : $candidate;
        }

        $sectionName = null;
        if ($section !== false) {
            $sectionCandidate = $this->normalizeSectionIdentifier((string) $section);
            $sectionName      = $sectionCandidate === '' ? null : $sectionCandidate;
        }

        $modes = $this->normalizeFormModes($mode);
        $this->storeLayoutEntry($normalizedFields, $reverse, $tabName, $modes, $sectionName);

        return $this;
    }

    /**
     * Define a named section for form rendering.
     *
     * @param array<string, mixed> $definition
     * @param string|array<int, string>|false $mode
     */
    public function form_section(string $identifier, array $definition, string|array|false $mode = false): self
    {
        $sectionId = $this->normalizeSectionIdentifier($identifier);
        if ($sectionId === '') {
            throw new InvalidArgumentException('Section identifier cannot be empty.');
        }

        if (!isset($definition['fields'])) {
            throw new InvalidArgumentException('Section definition must include a "fields" entry.');
        }

        $fields = $this->normalizeList($definition['fields']);
        if ($fields === []) {
            throw new InvalidArgumentException(sprintf('Section "%s" requires at least one field.', $sectionId));
        }

        $normalizedFields = [];
        foreach ($fields as $field) {
            $normalized = $this->normalizeColumnReference($field);
            if ($normalized !== '') {
                $normalizedFields[] = $normalized;
            }
        }

        if ($normalizedFields === []) {
            throw new InvalidArgumentException(sprintf('Section "%s" requires at least one valid field.', $sectionId));
        }

        $title = null;
        if (isset($definition['title']) && is_string($definition['title'])) {
            $trimmedTitle = trim($definition['title']);
            $title        = $trimmedTitle === '' ? null : $trimmedTitle;
        }

        $description = null;
        if (isset($definition['description']) && is_string($definition['description'])) {
            $trimmedDescription = trim($definition['description']);
            $description        = $trimmedDescription === '' ? null : $trimmedDescription;
        }

        $icon = null;
        if (isset($definition['icon']) && is_string($definition['icon'])) {
            $iconCandidate = $this->normalizeCssClassList($definition['icon']);
            $icon          = $iconCandidate === '' ? null : $iconCandidate;
        }

        $sectionClass = null;
        if (isset($definition['class']) && is_string($definition['class'])) {
            $classCandidate = $this->normalizeCssClassList($definition['class']);
            $sectionClass   = $classCandidate === '' ? null : $classCandidate;
        }

        $titleClass = null;
        if (isset($definition['title_class']) && is_string($definition['title_class'])) {
            $titleClassCandidate = $this->normalizeCssClassList($definition['title_class']);
            $titleClass          = $titleClassCandidate === '' ? null : $titleClassCandidate;
        }

        $collapsible = !empty($definition['collapsible']);
        $collapsed   = false;
        if (isset($definition['collapsed'])) {
            $collapsed = (bool) $definition['collapsed'];
        } elseif (isset($definition['start_collapsed'])) {
            $collapsed = (bool) $definition['start_collapsed'];
        }

        $modes = $this->normalizeFormModes($definition['mode'] ?? $mode);

        $this->ensureFormSectionBuckets();

        $sectionEntry = [
            'id'          => $sectionId,
            'title'       => $title,
            'description' => $description,
            'fields'      => array_values(array_unique($normalizedFields)),
            'collapsible' => $collapsible,
            'collapsed'   => $collapsible ? $collapsed : false,
            'icon'        => $icon,
            'class'       => $sectionClass,
            'title_class' => $titleClass,
        ];

        foreach ($modes as $targetMode) {
            $bucket = $targetMode === 'all' ? 'all' : $targetMode;
            if (!isset($this->config['form']['sections'][$bucket]) || !is_array($this->config['form']['sections'][$bucket])) {
                $this->config['form']['sections'][$bucket] = [];
            }
            $this->config['form']['sections'][$bucket][$sectionId] = $sectionEntry;
        }

        $this->storeLayoutEntry($normalizedFields, false, null, $modes, $sectionId);
        $this->addFormColumns($normalizedFields);

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
            $bucket                                        = $targetMode === 'all' ? 'all' : $targetMode;
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
     * Retrieve the stored change_type definition for a field.
     *
     * @return array<string, mixed>|null
     */
    public function getChangeTypeDefinition(string $field): ?array
    {
        $field = trim($field);
        if ($field === '') {
            return null;
        }

        $normalized  = $this->normalizeColumnReference($field);
        $definitions = $this->config['form']['behaviours']['change_type'] ?? [];

        $candidate = $definitions[$normalized] ?? $definitions[$field] ?? null;

        return is_array($candidate) ? $candidate : null;
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function pass_var(string|array $fields, mixed $value, string|array $mode = 'all'): self
    {
        return $this->applyFormBehaviour($fields, 'pass_var', $value, $mode, 'pass_var requires at least one field.');
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function pass_default(string|array $fields, mixed $value, string|array $mode = 'all'): self
    {
        return $this->applyFormBehaviour($fields, 'pass_default', $value, $mode, 'pass_default requires at least one field.');
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function readonly(string|array $fields, string|array $mode = 'all'): self
    {
        return $this->applyFormBehaviour($fields, 'readonly', true, $mode, 'readonly requires at least one field.');
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function disabled(string|array $fields, string|array $mode = 'all'): self
    {
        return $this->applyFormBehaviour($fields, 'disabled', true, $mode, 'disabled requires at least one field.');
    }

    /**
     * @param string|array<int, string> $fields
     * @param bool|string|array<int, string> $condition Boolean or callable receiving (array $row, array $context, Crud $crud)
     * @param string|array<int, string> $mode
     */
    public function field_visible_if(string|array $fields, bool|string|array $condition, string|array $mode = 'all'): self
    {
        return $this->applyFormPermissionRule($fields, 'visible_if', $condition, $mode, 'field_visible_if requires at least one field.');
    }

    /**
     * @param string|array<int, string> $fields
     * @param bool|string|array<int, string> $condition Boolean or callable receiving (array $row, array $context, Crud $crud)
     * @param string|array<int, string> $mode
     */
    public function field_editable_if(string|array $fields, bool|string|array $condition, string|array $mode = 'all'): self
    {
        return $this->applyFormPermissionRule($fields, 'editable_if', $condition, $mode, 'field_editable_if requires at least one field.');
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

        return $this->applyFormBehaviour($fields, 'validation_required', $minLength, $mode, 'validation_required requires at least one field.');
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

        return $this->applyFormBehaviour($fields, 'validation_pattern', $pattern, $mode, 'validation_pattern requires at least one field.');
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function max_char(string|array $fields, int $length, string|array $mode = 'all'): self
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Maximum character length must be at least 1.');
        }

        return $this->applyFormBehaviour($fields, 'max_length', $length, $mode, 'max_char requires at least one field.');
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function max_chars(string|array $fields, int $length, string|array $mode = 'all'): self
    {
        return $this->max_char($fields, $length, $mode);
    }

    /**
     * @param string|array<int, string> $fields
     * @param string|array<int, string> $mode
     */
    public function unique(string|array $fields, string|array $mode = 'all'): self
    {
        return $this->applyFormBehaviour($fields, 'unique', true, $mode, 'unique requires at least one field.');
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

    public function hide_search(bool $hidden = true): self
    {
        $this->config['hide_search'] = (bool) $hidden;

        return $this;
    }

    public function no_quotes(string|array $fields): self
    {
        $list = $this->normalizeList($fields);

        $this->config['no_quotes'] = array_values(array_unique(array_merge($this->config['no_quotes'], $list)));

        return $this;
    }

    public function where(string $condition): self
    {
        $this->addWhereCondition($condition, 'AND');

        return $this;
    }

    public function or_where(string $condition): self
    {
        $this->addWhereCondition($condition, 'OR');

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
                $aliasList[]  = $trimmedAlias === '' ? null : $trimmedAlias;
            }
        } elseif (is_string($alias)) {
            $trimmed   = trim($alias);
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
        bool $multi = false,
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
     * Define a nested table instance that can be expanded per row.
     *
     * @param callable|null $configurator Optional callback to configure the nested Crud instance.
     */
    public function nested_table(
        string $instanceName,
        string $parentColumn,
        string $innerTable,
        string $innerTableField,
        ?callable $configurator = null,
    ): self {
        $name = trim($instanceName);
        if ($name === '') {
            throw new InvalidArgumentException('Nested table instance name cannot be empty.');
        }

        if (isset($this->nestedTables[$name])) {
            throw new InvalidArgumentException(sprintf('Nested table "%s" is already defined.', $name));
        }

        $normalizedParentColumn = $this->normalizeColumnReference($parentColumn);
        if ($normalizedParentColumn === '') {
            throw new InvalidArgumentException('Nested table parent column must reference a valid column.');
        }

        $foreignColumn = trim($innerTableField);
        if ($foreignColumn === '') {
            throw new InvalidArgumentException('Nested table foreign column cannot be empty.');
        }

        $child = new self($innerTable, $this->connection);

        if ($configurator !== null) {
            $configurator($child);
        }

        $this->nestedTables[$name] = [
            'name'              => $name,
            'parent_column'     => $normalizedParentColumn,
            'parent_column_raw' => trim($parentColumn),
            'foreign_column'    => $foreignColumn,
            'crud'              => $child,
        ];

        return $child;
    }

    private function addWhereCondition(string $condition, string $glue): void
    {
        $normalizedGlue = strtoupper($glue) === 'OR' ? 'OR' : 'AND';
        $trimmed        = trim($condition);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Condition expression cannot be empty.');
        }

        $this->config['where'][] = [
            'glue'     => $normalizedGlue,
            'raw'      => $trimmed,
            'column'   => null,
            'operator' => null,
            'value'    => null,
        ];
    }

    private function isAssociativeArray(array $array): bool
    {
        return function_exists('array_is_list') ? !array_is_list($array) : ($array !== [] && array_keys($array) !== range(0, count($array) - 1));
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
                $index        = 0;
                foreach ($value as $item) {
                    $placeholder = sprintf(':w_%d_%d', $counter, $index++);
                    if (in_array($column, $this->config['no_quotes'], true)) {
                        $placeholders[] = (string) $item;
                    } else {
                        $parameters[$placeholder] = $item;
                        $placeholders[]           = $placeholder;
                    }
                }

                $list   = implode(', ', $placeholders);
                $clause = sprintf('%s %s (%s)', $column, $operator, $list);
            } else {
                if (in_array($column, $this->config['no_quotes'], true)) {
                    $clause = sprintf('%s %s %s', $column, $operator, (string) $value);
                } else {
                    $placeholder              = sprintf(':w_%d', $counter);
                    $parameters[$placeholder] = $value;
                    $clause                   = sprintf('%s %s %s', $column, $operator, $placeholder);
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

        $queryFilters = $this->config['query_builder']['filters'] ?? [];
        if (is_array($queryFilters) && $queryFilters !== []) {
            $logic = isset($this->config['query_builder']['logic'])
                && strtoupper((string) $this->config['query_builder']['logic']) === 'OR'
                ? 'OR'
                : 'AND';

            $qbClauses            = [];
            $qbPlaceholderCounter = 0;

            foreach ($queryFilters as $filter) {
                if (!is_array($filter)) {
                    continue;
                }

                $clause = $this->buildQueryBuilderFilterClause($filter, $parameters, $qbPlaceholderCounter);
                if ($clause !== '') {
                    $qbClauses[] = $clause;
                }
            }

            if ($qbClauses !== []) {
                $combined = count($qbClauses) > 1
                    ? '(' . implode(' ' . $logic . ' ', $qbClauses) . ')'
                    : $qbClauses[0];

                $clauses[] = [
                    'glue'   => 'AND',
                    'clause' => $combined,
                ];
            }
        }

        if ($searchTerm !== null && $searchTerm !== '') {
            $configuredColumns = $this->config['search_columns'];
            $map               = $this->getWhereColumnsMapForAllSearch(); // display => expr
            $targetExpr        = null;

            if ($searchColumn !== null && $searchColumn !== '') {
                if (isset($map[$searchColumn])) {
                    $targetExpr = $map[$searchColumn];
                } elseif ($configuredColumns !== [] && in_array($searchColumn, $configuredColumns, true)) {
                    $targetExpr = $this->normalizeWhereField($searchColumn);
                }
            }

            if ($targetExpr !== null && $targetExpr !== '') {
                $placeholder              = ':search_term';
                $parameters[$placeholder] = '%' . $searchTerm . '%';
                $isJsonColumn             = isset($this->searchableColumnMeta[$searchColumn])
                    ? (bool) ($this->searchableColumnMeta[$searchColumn]['is_json'] ?? false)
                    : false;

                if ($isJsonColumn && $this->supportsJsonSearch()) {
                    $searchClause = $this->buildJsonSearchCondition($targetExpr, $placeholder, false);
                } else {
                    $searchClause = sprintf('%s LIKE %s', $targetExpr, $placeholder);
                }
            } else {
                // When "All" is selected (or no specific/allowed column picked),
                // start with visible grid columns and always merge configured search columns
                // so hidden-but-searchable fields are still queried.
                $exprList  = [];
                $seenExprs = [];

                foreach ($map as $displayColumn => $expr) {
                    if ($expr === '') {
                        continue;
                    }
                    $exprList[]       = ['expr' => $expr, 'key' => $displayColumn];
                    $seenExprs[$expr] = true;
                }

                foreach ($configuredColumns as $c) {
                    $expr = $this->normalizeWhereField($c);
                    if ($expr === '' || isset($seenExprs[$expr])) {
                        continue;
                    }
                    $exprList[]       = ['expr' => $expr, 'key' => null];
                    $seenExprs[$expr] = true;
                }

                $parts = [];
                $value = '%' . $searchTerm . '%';
                foreach ($exprList as $idx => $entry) {
                    $expr = $entry['expr'];
                    if ($expr === '') {
                        continue;
                    }
                    $ph              = ':search_term_' . $idx;
                    $parameters[$ph] = $value;
                    $key             = $entry['key'];
                    $isJsonColumn    = $key !== null
                        ? (bool) ($this->searchableColumnMeta[$key]['is_json'] ?? false)
                        : false;

                    if ($isJsonColumn && $this->supportsJsonSearch()) {
                        $parts[] = $this->buildJsonSearchCondition($expr, $ph, false);
                    } else {
                        $parts[] = sprintf('%s LIKE %s', $expr, $ph);
                    }
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
            $sql .= $prefix . $entry['clause'];
        }

        return $sql;
    }

    private function getConnectionDriver(): string
    {
        if (is_string($this->cachedDriver) && $this->cachedDriver !== '') {
            return $this->cachedDriver;
        }

        try {
            $driver = strtolower((string) $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (PDOException) {
            $driver = 'mysql';
        }

        if ($driver === '') {
            $driver = 'mysql';
        }

        $this->cachedDriver = $driver;

        return $this->cachedDriver;
    }

    private function supportsJsonSearch(): bool
    {
        return $this->getConnectionDriver() === 'mysql';
    }

    private function isJsonColumnType(?string $type): bool
    {
        if ($type === null || $type === '') {
            return false;
        }

        $token = strtolower((string) $type);
        $paren = strpos($token, '(');
        if ($paren !== false) {
            $token = substr($token, 0, $paren);
        }

        return in_array(trim($token), ['json', 'jsonb'], true);
    }

    private function buildJsonSearchCondition(string $columnExpr, string $placeholder, bool $negate = false): string
    {
        $keyword = $negate ? 'IS NULL' : 'IS NOT NULL';

        return sprintf('JSON_SEARCH(%s, \'one\', %s) %s', $columnExpr, $placeholder, $keyword);
    }

    private function getSqlIdentifierQuotes(): array
    {
        $driver = $this->getConnectionDriver();

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
        if (
            (str_starts_with($trimmed, '`') && str_ends_with($trimmed, '`')) ||
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
        ) {
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

        $parts  = explode('.', $expr);
        $quoted = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $quoted[] = $this->quoteIdentifierPart($p);
        }
        return implode('.', $quoted);
    }

    private function quotePrimaryKeyColumnName(string $column): string
    {
        return $this->quoteQualifiedIdentifier($column);
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
        $this->searchableColumnMeta = [];

        if ($this->config['custom_query'] !== null) {
            return [];
        }

        $supportsJsonSearch = $this->supportsJsonSearch();

        // Build alias => table map for later schema lookups
        $aliasToTable = [];
        foreach ($this->config['joins'] as $index => $join) {
            $alias                = isset($join['alias']) && is_string($join['alias']) && $join['alias'] !== ''
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
                $available[]           = $name;
                $subselectNames[$name] = true;
            }
        }

        // Resolve visible display list
        $visible = $this->calculateVisibleColumns($available);
        if ($visible === []) {
            return [];
        }

        // Load schemas to filter out non-LIKE-able types
        $mainSchema  = $this->getTableSchema($this->table);
        $joinSchemas = [];
        foreach ($aliasToTable as $alias => $table) {
            $joinSchemas[$alias] = $this->getTableSchema($table);
        }

        $isSearchableType = static function (?string $type) use ($supportsJsonSearch): bool
        {
            if ($type === null || $type === '') {
                return true;
            }
            $t     = strtolower($type);
            $paren = strpos($t, '(');
            if ($paren !== false) {
                $t = substr($t, 0, $paren);
            }
            $blocked = [
                'json',
                'blob',
                'tinyblob',
                'mediumblob',
                'longblob',
                'binary',
                'varbinary',
                'bit',
                'geometry',
                'point',
                'linestring',
                'polygon',
                'multipoint',
                'multilinestring',
                'multipolygon',
                'geometrycollection',
            ];
            if ($supportsJsonSearch) {
                $blocked = array_values(array_diff($blocked, ['json', 'jsonb']));
            }
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

            if (str_contains($displayCol, '__')) {
                [$alias, $name] = array_map('trim', explode('__', $displayCol, 2));
                $typeMeta       = $joinSchemas[$alias][$name]['type'] ?? null;
                $typeString     = is_string($typeMeta) ? $typeMeta : null;
                $isJsonType     = $this->isJsonColumnType($typeString);
                if ($isJsonType && !$supportsJsonSearch) {
                    continue;
                }
                if (!$isJsonType && !$isSearchableType($typeString)) {
                    continue;
                }
                $expr                                    = $this->quoteQualifiedIdentifier($alias . '.' . $name);
                $map[$displayCol]                        = $expr;
                $this->searchableColumnMeta[$displayCol] = [
                    'expr'    => $expr,
                    'is_json' => $isJsonType,
                ];
            } else {
                $typeMeta   = $mainSchema[$displayCol]['type'] ?? null;
                $typeString = is_string($typeMeta) ? $typeMeta : null;
                $isJsonType = $this->isJsonColumnType($typeString);
                if ($isJsonType && !$supportsJsonSearch) {
                    continue;
                }
                if (!$isJsonType && !$isSearchableType($typeString)) {
                    continue;
                }
                $expr                                    = $this->quoteQualifiedIdentifier('main.' . $displayCol);
                $map[$displayCol]                        = $expr;
                $this->searchableColumnMeta[$displayCol] = [
                    'expr'    => $expr,
                    'is_json' => $isJsonType,
                ];
            }
        }

        return $map;
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function buildSelectQuery(?int $limit = null, ?int $offset = null, ?string $searchTerm = null, ?string $searchColumn = null): array
    {
        $this->applyRowOrderingSort();

        $selectParts = ['main.*'];

        foreach ($this->config['subselects'] as $subselect) {
            $column        = $subselect['column'];
            $sql           = $subselect['sql'];
            $selectParts[] = sprintf('(%s) AS %s', $sql, $column);
        }

        foreach ($this->config['joins'] as $index => $join) {
            $alias   = $join['alias'] ?? ('j' . $index);
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

        $parameters  = [];
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
                $field      = (string) $order['field'];
                $normalized = $this->normalizeColumnReference($field);
                if ($normalized !== '' && isset($disabled[$normalized])) {
                    // Skip disabled columns from ORDER BY
                    continue;
                }

                // Build a safe SQL expression for ORDER BY
                // Support alias__column notation and quote identifiers when applicable.
                $expr         = $this->denormalizeColumnReference($normalized);
                $isExpression = (str_contains($expr, ' ') || str_contains($expr, '(') || str_contains($expr, ')'));
                if (!$isExpression) {
                    if (!str_contains($expr, '.')) {
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
            $alias   = $join['alias'] ?? ('j' . $index);
            $left    = str_contains($join['field'], '.') ? $join['field'] : 'main.' . $join['field'];
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
        $pkExpr      = $this->quoteQualifiedIdentifier('main.' . $this->getPrimaryKeyColumn());
        $countExpr   = $useDistinct ? ('COUNT(DISTINCT ' . $pkExpr . ')') : 'COUNT(*)';
        $sql         = sprintf('SELECT %s FROM %s', $countExpr, $this->buildFromClause());

        $joins = $this->buildJoinClauses();
        if ($joins !== '') {
            $sql .= ' ' . $joins;
        }

        $parameters  = [];
        $whereClause = $this->buildWhereClause($parameters, $searchTerm, $searchColumn);
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        return [
            'sql'    => $sql,
            'params' => $parameters,
        ];
    }

    private function relationLookupKey(array $relation): string
    {
        return serialize([$relation['table'], $relation['related_field'], (array) $relation['related_name'],
            $relation['where'] ?? [], $relation['order_by'] ?? null]);
    }

    /** Load only labels used by this page, with bounded parameter counts. */
    private function loadRelationLabels(array $relation, array $values): ?array
    {
        $nameFields = (array) $relation['related_name'];
        $select = [$relation['related_field'] . ' AS relation_key'];
        foreach ($nameFields as $index => $field) {
            $select[] = $field . ' AS relation_value_' . $index;
        }
        $map = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $parameters = [];
            $placeholders = [];
            foreach ($chunk as $index => $value) {
                $placeholder = ':rel_' . $index;
                $placeholders[] = $placeholder;
                $parameters[$placeholder] = $value;
            }
            $query = sprintf('SELECT %s FROM %s WHERE %s IN (%s)', implode(', ', $select),
                $relation['table'], $relation['related_field'], implode(', ', $placeholders));
            foreach (($relation['where'] ?? []) as $field => $value) {
                $placeholder = ':rel_where_' . count($parameters);
                $query .= ' AND ' . $field . ' = ' . $placeholder;
                $parameters[$placeholder] = $value;
            }
            if (!empty($relation['order_by'])) {
                $query .= ' ORDER BY ' . $relation['order_by'];
            }
            try {
                $statement = $this->connection->prepare($query);
                if ($statement === false || !$statement->execute($parameters)) {
                    return null;
                }
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['relation_key'] === null) {
                        continue;
                    }
                    $parts = [];
                    foreach ($nameFields as $index => $_field) {
                        $parts[] = $row['relation_value_' . $index] ?? '';
                    }
                    $map[(string) $row['relation_key']] = trim(implode(' ', array_filter($parts, static fn($part) => $part !== null)));
                }
            } catch (PDOException) {
                return null;
            }
        }
        return $map;
    }

    private function applyRelations(array $rows): array
    {
        if ($rows === [] || $this->config['relations'] === []) {
            return $rows;
        }

        $relationGroups = [];
        foreach ($this->config['relations'] as $relation) {
            $key = $this->relationLookupKey($relation);
            foreach ($rows as $row) {
                $value = $row[$relation['field']] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $values = !empty($relation['multi']) && is_string($value) ? $this->splitValues($value) : [$value];
                foreach ($values as $item) {
                    $relationGroups[$key][(string) $item] = (string) $item;
                }
            }
        }
        $labelMaps = [];

        foreach ($this->config['relations'] as $index => $relation) {
            $field        = $relation['field'];
            $relatedTable = $relation['table'];
            $relatedField = $relation['related_field'];
            $nameFields   = (array) $relation['related_name'];

            if ($field === '' || $relatedTable === '' || $relatedField === '') {
                continue;
            }

            $key = $this->relationLookupKey($relation);
            $values = array_values($relationGroups[$key] ?? []);
            if ($values === []) {
                continue;
            }
            if (!array_key_exists($key, $labelMaps)) {
                $labelMaps[$key] = $this->loadRelationLabels($relation, $values);
            }
            $map = $labelMaps[$key];
            if ($map === null) {
                continue;
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
                    $key                     = (string) $currentValue;
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
        $baseColumns  = $this->getBaseTableColumns();
        foreach ($rows as $row) {
            $filteredRow = [
                '__fastcrud_primary_key'   => $row['__fastcrud_primary_key'] ?? null,
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
            return $this->applyColumnVisibilityRules($available);
        }

        $availableLookup = array_flip($available);

        if ($this->config['columns_reverse']) {
            $result = [];
            foreach ($available as $column) {
                if (!in_array($column, $configured, true)) {
                    $result[] = $column;
                }
            }

            return $this->applyColumnVisibilityRules($result !== [] ? $result : $available);
        }

        $result = [];
        $added  = [];

        foreach ($configured as $column) {
            if ($column === '*') {
                foreach ($available as $candidate) {
                    if (!isset($added[$candidate])) {
                        $result[]          = $candidate;
                        $added[$candidate] = true;
                    }
                }
                continue;
            }

            if (isset($availableLookup[$column]) && !isset($added[$column])) {
                $result[]       = $column;
                $added[$column] = true;
            }
        }

        return $this->applyColumnVisibilityRules($result !== [] ? $result : $available);
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function applyColumnVisibilityRules(array $columns): array
    {
        $rules = $this->config['column_visibility_rules'] ?? [];
        if (!is_array($rules) || $rules === []) {
            return $columns;
        }

        $visible = [];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                continue;
            }

            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            if (isset($rules[$normalized]) && !$this->evaluatePermissionRule($rules[$normalized], 'column', $normalized)) {
                continue;
            }

            $visible[] = $column;
        }

        return $visible;
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function ensureCustomColumnNames(array $columns): array
    {
        $customColumns = array_keys($this->config['custom_columns'] ?? []);
        foreach ($customColumns as $column) {
            if (!is_string($column)) {
                continue;
            }

            if ($column === '' || in_array($column, $columns, true)) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
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
        return Database::rememberSchemaMetadata(
            $this->connection,
            'crud:columns:' . $table,
            fn(): array => $this->loadTableColumnsFor($table)
        );
    }

    private function loadTableColumnsFor(string $table): array
    {
        $sql = sprintf('SELECT * FROM %s LIMIT 0', $table);

        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException) {
            return [];
        }

        if ($statement === false) {
            return [];
        }

        $columns = [];
        $count   = $statement->columnCount();

        for ($index = 0; $index < $count; $index++) {
            $meta = $statement->getColumnMeta($index) ?: [];
            $name = $meta['name'] ?? null;
            if (is_string($name)) {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * @return array<string, bool>
     */
    private function getTableColumnLookupFor(string $table): array
    {
        return array_fill_keys($this->getTableColumnsFor($table), true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTableSchema(string $table): array
    {
        return Database::rememberSchemaMetadata(
            $this->connection,
            'crud:schema:' . $table,
            fn(): array => $this->loadTableSchema($table)
        );
    }

    private function loadTableSchema(string $table): array
    {
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

        return $schema;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadMysqlTableSchema(string $table): array
    {
        $schema       = [];
        $escapedTable = str_replace('`', '``', $table);
        $sql          = sprintf('SHOW FULL COLUMNS FROM `%s`', $escapedTable);

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

            $rawType    = isset($row['Type']) ? (string) $row['Type'] : null;
            $type       = $rawType !== null ? strtolower($rawType) : null;
            $enumValues = $rawType !== null ? $this->parseEnumDefinition($rawType) : [];

            $schema[$field] = [
                'type'     => $type,
                'raw_type' => $row['Type'] ?? null,
                'meta'     => $row,
            ];

            if ($enumValues !== []) {
                $schema[$field]['enum_values'] = $enumValues;
            }
        }

        if ($schema !== []) {
            $jsonAliases = $this->detectMysqlJsonAliasColumns($table);
            if ($jsonAliases !== []) {
                $normalizedLookup = [];
                foreach (array_keys($schema) as $columnName) {
                    if (!is_string($columnName) || $columnName === '') {
                        continue;
                    }
                    $normalizedLookup[strtolower($columnName)] = $columnName;
                }

                foreach ($jsonAliases as $aliasColumn => $_flag) {
                    $normalized = strtolower($aliasColumn);
                    if (!isset($normalizedLookup[$normalized])) {
                        continue;
                    }

                    $actualColumn                      = $normalizedLookup[$normalized];
                    $schema[$actualColumn]['type']     = 'json';
                    $schema[$actualColumn]['raw_type'] = 'json';
                    if (!isset($schema[$actualColumn]['meta']) || !is_array($schema[$actualColumn]['meta'])) {
                        $schema[$actualColumn]['meta'] = [];
                    }
                    $schema[$actualColumn]['meta']['mariadb_json_alias'] = true;
                }
            }
        }

        return $schema;
    }

    /**
     * @return array<string, bool>
     */
    private function detectMysqlJsonAliasColumns(string $table): array
    {
        $table = trim($table);
        if ($table === '') {
            return [];
        }

        $escapedTable = str_replace('`', '``', $table);
        $sql          = sprintf('SHOW CREATE TABLE `%s`', $escapedTable);

        try {
            $statement = $this->connection->query($sql);
        } catch (PDOException) {
            return [];
        }

        if ($statement === false) {
            return [];
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }

        $ddl = '';
        foreach (['Create Table', 'Create View', 'Create'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                $ddl = $row[$key];
                break;
            }
        }

        if ($ddl === '') {
            return [];
        }

        $matches = [];
        preg_match_all('/json_valid\(\s*`([^`]+)`\s*\)/i', $ddl, $matches);

        if (!isset($matches[1]) || !is_array($matches[1])) {
            return [];
        }

        $aliases = [];
        foreach ($matches[1] as $columnName) {
            if (!is_string($columnName) || $columnName === '') {
                continue;
            }

            $aliases[strtolower($columnName)] = true;
        }

        return $aliases;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadPgsqlTableSchema(string $table): array
    {
        $schema = [];

        $sql = <<<'SQL'
SELECT column_name, data_type, udt_name, udt_schema, is_nullable, column_default
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

            $dataType  = isset($row['data_type']) ? strtolower((string) $row['data_type']) : null;
            $udtName   = isset($row['udt_name']) ? strtolower((string) $row['udt_name']) : null;
            $udtSchema = isset($row['udt_schema']) ? (string) $row['udt_schema'] : null;

            $enumValues = [];
            if ($dataType === 'user-defined' && $udtName !== null && $udtName !== '') {
                $enumValues = $this->fetchPgsqlEnumOptions($udtName, $udtSchema);
            }

            $schema[$field] = [
                'type'      => $dataType ?: $udtName,
                'data_type' => $dataType,
                'udt_name'  => $udtName,
                'meta'      => $row,
            ];

            if ($enumValues !== []) {
                $schema[$field]['enum_values'] = $enumValues;
            }
        }

        return $schema;
    }

    /**
     * @return array<string, string>
     */
    private function fetchPgsqlEnumOptions(string $typeName, ?string $schema): array
    {
        $typeName = trim($typeName);
        if ($typeName === '') {
            return [];
        }

        $cacheKey = $schema === null || $schema === ''
            ? sprintf('pgsql:%s', $typeName)
            : sprintf('pgsql:%s.%s', $schema, $typeName);

        return Database::rememberSchemaMetadata(
            $this->connection,
            'crud:enum:' . $cacheKey,
            fn(): array => $this->loadPgsqlEnumOptions($typeName, $schema)
        );
    }

    private function loadPgsqlEnumOptions(string $typeName, ?string $schema): array
    {
        $sql = <<<'SQL'
SELECT e.enumlabel
FROM pg_type t
JOIN pg_enum e ON t.oid = e.enumtypid
JOIN pg_namespace n ON n.oid = t.typnamespace
WHERE t.typname = :type
SQL;

        $params = [':type' => $typeName];

        if ($schema !== null && $schema !== '') {
            $sql .= ' AND n.nspname = :schema';
            $params[':schema'] = $schema;
        }

        $sql .= ' ORDER BY e.enumsortorder';

        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            return [];
        }

        try {
            $statement->execute($params);
        } catch (PDOException) {
            return [];
        }

        $options = [];
        while (($value = $statement->fetchColumn()) !== false) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $options[$trimmed] = $trimmed;
        }

        return $options;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadSqliteTableSchema(string $table): array
    {
        $schema = [];
        $sql    = sprintf("PRAGMA table_info('%s')", $table);

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
                'type'     => $type,
                'raw_type' => $row['type'] ?? null,
                'meta'     => $row,
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
        $sql    = sprintf('SELECT * FROM %s LIMIT 0', $table);

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
            $meta  = $statement->getColumnMeta($index) ?: [];
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
        $defaults  = [];
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
            $params  = [];
            if ($options !== []) {
                $params['values'] = $options;
            }

            $defaults[$originalField] = [
                'type'    => !empty($relation['multi']) ? 'multiselect' : 'select',
                'default' => '',
                'params'  => $params,
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
        $field        = isset($relation['field']) ? (string) $relation['field'] : '';
        $table        = isset($relation['table']) ? (string) $relation['table'] : '';
        $relatedField = isset($relation['related_field']) ? (string) $relation['related_field'] : '';
        $nameFields   = isset($relation['related_name']) ? (array) $relation['related_name'] : [];

        if ($field === '' || $table === '' || $relatedField === '') {
            return [];
        }

        $cacheKeyParts = [$table, $relatedField, $nameFields, $relation['where'] ?? [], $relation['order_by'] ?? null];
        $cacheKey      = md5(json_encode($cacheKeyParts) ?: serialize($cacheKeyParts));
        if (isset($this->relationOptionsCache[$cacheKey])) {
            return $this->relationOptionsCache[$cacheKey];
        }

        $selectColumns = [$relatedField . ' AS relation_key'];
        foreach ($nameFields as $index => $nameField) {
            if (!is_string($nameField) || trim($nameField) === '') {
                continue;
            }
            $alias           = sprintf('relation_value_%d', $index);
            $selectColumns[] = sprintf('%s AS %s', $nameField, $alias);
        }

        $sql        = sprintf('SELECT %s FROM %s', implode(', ', $selectColumns), $table);
        $parameters = [];
        $conditions = [];

        if (!empty($relation['where']) && is_array($relation['where'])) {
            foreach ($relation['where'] as $whereField => $whereValue) {
                if (!is_string($whereField) || trim($whereField) === '') {
                    continue;
                }
                $placeholder              = sprintf(':relopt_%s_%d', preg_replace('/[^a-z0-9_]+/i', '_', $field), count($parameters));
                $parameters[$placeholder] = $whereValue;
                $conditions[]             = sprintf('%s = %s', $whereField, $placeholder);
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
                $alias   = sprintf('relation_value_%d', $index);
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
     * @return array<string, string>
     */
    private function extractEnumValues(array $columnMeta): array
    {
        $enumValues = [];

        if (isset($columnMeta['enum_values']) && is_array($columnMeta['enum_values'])) {
            foreach ($columnMeta['enum_values'] as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $enumValues[(string) $key] = $value;
                    continue;
                }

                if (is_string($value)) {
                    $enumValues[$value] = $value;
                    continue;
                }

                if (is_string($key)) {
                    $enumValues[(string) $key] = (string) $key;
                }
            }
        }

        if ($enumValues !== []) {
            return $enumValues;
        }

        foreach (['raw_type', 'type'] as $key) {
            if (!isset($columnMeta[$key]) || !is_string($columnMeta[$key])) {
                continue;
            }

            $parsed = $this->parseEnumDefinition((string) $columnMeta[$key]);
            if ($parsed !== []) {
                return $parsed;
            }
        }

        $meta = $columnMeta['meta'] ?? null;
        if (is_array($meta)) {
            foreach (['Type', 'type', 'native_type'] as $metaKey) {
                if (!isset($meta[$metaKey]) || !is_string($meta[$metaKey])) {
                    continue;
                }

                $parsed = $this->parseEnumDefinition((string) $meta[$metaKey]);
                if ($parsed !== []) {
                    return $parsed;
                }
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function parseEnumDefinition(string $typeDefinition): array
    {
        $trimmed = trim($typeDefinition);
        if ($trimmed === '') {
            return [];
        }

        if (stripos($trimmed, 'enum') !== 0) {
            return [];
        }

        $open  = strpos($trimmed, '(');
        $close = strrpos($trimmed, ')');
        if ($open === false || $close === false || $close <= $open) {
            return [];
        }

        $body = substr($trimmed, $open + 1, $close - $open - 1);
        if ($body === false || $body === '') {
            return [];
        }

        $values = $this->parseEnumValueList($body);
        if ($values === []) {
            return [];
        }

        return $this->normalizeEnumValueMap($values);
    }

    /**
     * @param array<int, string> $values
     * @return array<string, string>
     */
    private function normalizeEnumValueMap(array $values): array
    {
        $options = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $key = (string) $value;
            if (!array_key_exists($key, $options)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function parseEnumValueList(string $body): array
    {
        $values     = [];
        $length     = strlen($body);
        $buffer     = '';
        $inValue    = false;
        $escapeNext = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $body[$index];

            if ($escapeNext) {
                $buffer .= $char;
                $escapeNext = false;
                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($char === "'") {
                if ($inValue) {
                    if ($index + 1 < $length && $body[$index + 1] === "'") {
                        $buffer .= "'";
                        $index++;
                        continue;
                    }

                    $values[] = $buffer;
                    $buffer   = '';
                    $inValue  = false;
                } else {
                    $inValue = true;
                }

                continue;
            }

            if ($inValue) {
                $buffer .= $char;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $columnMeta
     * @return array<string, mixed>|null
     */
    private function mapDatabaseTypeToChangeType(array $columnMeta): ?array
    {
        $enumValues = $this->extractEnumValues($columnMeta);
        if ($enumValues !== []) {
            $default = array_key_first($enumValues);
            if ($default !== null) {
                $default = (string) $default;
            } else {
                $default = '';
            }

            return [
                'type'    => 'select',
                'default' => $default,
                'params'  => ['values' => $enumValues],
            ];
        }

        $typeInfo       = $this->detectSqlTypeInfo($columnMeta);
        $rawType        = $typeInfo['raw'];
        $normalizedType = $typeInfo['normalized'];

        $params     = [];
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
            'type'    => $changeType,
            'default' => '',
            'params'  => $params,
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

        $rawType        = $candidates[0] ?? '';
        $normalizedType = '';

        foreach ($candidates as $candidate) {
            $normalizedCandidate = $this->normalizeSqlType($candidate);
            if ($normalizedCandidate !== '') {
                $normalizedType = $normalizedCandidate;
                break;
            }
        }

        return [
            'raw'        => $rawType,
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
        $tokens        = preg_split('/\s+/', $normalizedType) ?: [];
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

        if (str_contains($column, '.') && !str_contains($column, '__')) {
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

    private function hasNestedTables(): bool
    {
        return $this->nestedTables !== [];
    }

    /**
     * @return array<int, string>
     */
    private function getBaseTableColumns(): array
    {
        return $this->getTableColumnsFor($this->table);
    }

    /**
     * Render the CRUD interface.
     *
     * @param string|null $mode Optional render mode (`edit`, `create`, or `view`)
     * @param mixed       $primaryKeyValue Optional primary key for targeted record modes
     */
    public function render(?string $mode = null, mixed $primaryKeyValue = null): string
    {
        $normalizedMode = $this->normalizeRenderMode($mode);
        $formOnly       = $normalizedMode !== null;

        $rawId   = $this->id;
        $id      = $this->escapeHtml($rawId);
        $table   = $this->escapeHtml($this->table);
        $perPage = $this->perPage;

        // Get column names for headers
        $columns = $this->getColumnNames();

        if ($columns === []) {
            return '<div class="alert alert-warning">' . $this->escapeHtml($this->uiText('no_columns')) . '</div>';
        }

        $batchDeleteEnabled  = $this->isBatchDeleteEnabled();
        $headerHtml          = $this->buildHeader($columns);
        $clientConfigPayload = $this->buildClientConfigPayload($columns);
        $script              = $this->generateAjaxScript();
        $formDisplayMode     = $formOnly
            ? self::DEFAULT_FORM_DISPLAY_MODE
            : $this->normalizeFormDisplayMode((string) ($this->config['form_display_mode'] ?? self::DEFAULT_FORM_DISPLAY_MODE));
        $inlineFormDisplay   = $formOnly || $formDisplayMode === 'inline';
        $styles              = $this->buildActionColumnStyles($rawId, $formOnly, $formDisplayMode);
        $numbersEnabled      = !empty($this->config['numbers_enabled']);
        $rowOrderingEnabled  = $this->isRowOrderingEnabled();
        $colspan             = $this->escapeHtml((string) (count($columns) + 1 + ($batchDeleteEnabled ? 1 : 0) + ($this->hasNestedTables() ? 1 : 0) + ($numbersEnabled ? 1 : 0) + ($rowOrderingEnabled ? 1 : 0)));
        $editPanel           = $this->buildEditOffcanvas($rawId, $inlineFormDisplay, $formDisplayMode);
        $viewPanel           = $this->buildViewOffcanvas($rawId, $inlineFormDisplay, $formDisplayMode);
        $queryBuilderModal   = $this->buildQueryBuilderModal($rawId, $formOnly);

        $configKey  = $this->storeClientConfigPayloadInSession($clientConfigPayload);
        $configJson = '{}';
        try {
            $configJson = json_encode(
                $configKey !== null ? $this->buildClientBootstrapPayload() : $clientConfigPayload,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $configJson = '{}';
        }

        $viewStorageKey = $this->buildViewStorageKey($clientConfigPayload);
        $loadingText    = $this->escapeHtml($this->uiText('loading'));
        $loadingDataText = $this->escapeHtml($this->uiText('loading_data'));
        $paginationText = $this->escapeHtml($this->uiText('pagination'));

        $containerAttributes = [
            'id'                                   => $rawId . '-container',
            'data-fastcrud-config'                 => $configJson,
            'data-fastcrud-initial-primary-column' => $this->getPrimaryKeyColumn(),
            'data-fastcrud-view-storage-key'       => $viewStorageKey,
            'data-fastcrud-form-display-mode'      => $formDisplayMode,
        ];

        if ($configKey !== null) {
            $containerAttributes['data-fastcrud-config-key'] = $configKey;
        }

        if ($formOnly) {
            $containerAttributes['class']                      = 'fastcrud-form-only';
            $containerAttributes['data-fastcrud-form-only']    = '1';
            $containerAttributes['data-fastcrud-initial-mode'] = $normalizedMode;

            if ($primaryKeyValue !== null && $normalizedMode !== 'create') {
                $containerAttributes['data-fastcrud-initial-primary'] = is_scalar($primaryKeyValue)
                    ? (string) $primaryKeyValue
                    : json_encode($primaryKeyValue);
            }
        } elseif ($formDisplayMode === 'side') {
            $containerAttributes['class'] = 'fastcrud-has-side-form';
        } elseif ($formDisplayMode === 'inline') {
            $containerAttributes['class'] = 'fastcrud-has-inline-form';
        }

        $attributePairs = [];
        foreach ($containerAttributes as $name => $value) {
            if ($value === null) {
                continue;
            }

            $attributePairs[] = sprintf('%s="%s"', $name, $this->escapeHtml((string) $value));
        }
        $attributesHtml = implode(' ', $attributePairs);

        $metaHtml = <<<HTML
    <div id="{$id}-meta" class="d-flex flex-wrap align-items-center gap-2 mb-2"></div>
HTML;

        $tableViewportHtml = <<<HTML
    <div class="table-responsive fastcrud-table-container">
        <table id="$id" class="table align-middle" data-table="$table" data-per-page="$perPage">
            <thead>
                <tr>
$headerHtml
                </tr>
            </thead>
            <tbody>
                <tr class="fastcrud-loading-row">
                    <td colspan="{$colspan}" class="text-center fastcrud-loading-placeholder">
                        <div class="d-inline-flex align-items-center gap-2">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">{$loadingText}</span>
                            </div>
                            <span class="fastcrud-loading-text">{$loadingDataText}</span>
                        </div>
                    </td>
                </tr>
            </tbody>
            <tfoot id="{$id}-summary" class="fastcrud-summary"></tfoot>
        </table>
    </div>
HTML;

        $paginationHtml = <<<HTML
    <nav aria-label="{$paginationText}" class="d-flex flex-wrap align-items-center gap-2 justify-content-between mt-1">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <ul id="{$id}-pagination" class="pagination justify-content-start mb-0 flex-wrap"></ul>
            <div id="{$id}-toolbar" class="d-flex flex-wrap align-items-center gap-2"></div>
        </div>
        <div id="{$id}-range" class="text-muted small ms-auto"></div>
    </nav>
HTML;

        $tableHtml = <<<HTML
$metaHtml
$tableViewportHtml
$paginationHtml
HTML;

        if (!$formOnly && $formDisplayMode === 'side') {
            return <<<HTML
<div {$attributesHtml}>
{$metaHtml}
    <div class="fastcrud-side-layout">
        <div class="fastcrud-side-table">
{$tableViewportHtml}
        </div>
        <div class="fastcrud-side-panel-slot">
$editPanel
$viewPanel
        </div>
    </div>
{$paginationHtml}
</div>
$queryBuilderModal
$styles
$script
HTML;
        }

        if (!$formOnly && $formDisplayMode === 'inline') {
            return <<<HTML
<div {$attributesHtml}>
{$metaHtml}
    <div class="fastcrud-inline-panel-slot">
$editPanel
$viewPanel
    </div>
{$tableViewportHtml}
{$paginationHtml}
</div>
$queryBuilderModal
$styles
$script
HTML;
        }

        return <<<HTML
<div {$attributesHtml}>
$tableHtml
</div>
$queryBuilderModal
$styles
$editPanel
$viewPanel
$script
HTML;
    }

    private function normalizeRenderMode(?string $mode): ?string
    {
        if ($mode === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $mode));
        if ($normalized === '' || in_array($normalized, ['list', 'grid', 'table'], true)) {
            return null;
        }

        switch ($normalized) {
            case 'create':
            case 'add':
            case 'insert':
                return 'create';
            case 'edit':
            case 'update':
                return 'edit';
            case 'view':
            case 'read':
                return 'view';
            default:
                return null;
        }
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
        ?string $searchColumn = null,
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
        $rows = $this->applyCustomColumns($rows);

        $columns          = $this->extractColumnNames($statement, $rows);
        $columns          = $this->ensureCustomColumnNames($columns);
        [$rows, $columns] = $this->applyColumnVisibility($rows, $columns);

        $rows = $this->decorateRows($rows, $columns);

        return [$rows, $columns];
    }

    private function normalizePrimaryKeyLookupKey(mixed $value): string
    {
        if ($value === null) {
            return '__FASTCRUD_NULL__';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return 'hash:' . md5(serialize($value));
    }

    /**
     * @param array<int, mixed> $primaryKeyValues
     * @return array<string, array<string, mixed>>
     */
    private function fetchRowsByPrimaryKeys(string $primaryKeyColumn, array $primaryKeyValues): array
    {
        if ($primaryKeyValues === []) {
            return [];
        }

        $placeholders = [];
        $parameters   = [];
        foreach (array_values($primaryKeyValues) as $index => $value) {
            $placeholder              = ':pk_list_' . $index;
            $placeholders[]           = $placeholder;
            $parameters[$placeholder] = $value;
        }

        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s IN (%s)',
            $this->table,
            $primaryKeySql,
            implode(', ', $placeholders)
        );

        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare batch record lookup.');
        }

        try {
            $statement->execute($parameters);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to fetch records for deletion.', 0, $exception);
        }

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }

        $primaryKey = $this->getPrimaryKeyColumn();
        $results    = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['__fastcrud_primary_key']   = $primaryKey;
            $row['__fastcrud_primary_value'] = $row[$primaryKey] ?? null;

            /** @var array<string, mixed> $normalizedRow */
            $normalizedRow                                              = $this->applyFieldCallbacksToRow($row, 'edit');
            $lookupValue                                                = $normalizedRow[$primaryKeyColumn] ?? ($row[$primaryKeyColumn] ?? null);
            $results[$this->normalizePrimaryKeyLookupKey($lookupValue)] = $normalizedRow;
        }

        return $results;
    }



    /**
     * @param array<int, string> $columns
     */
    private function buildHeader(array $columns): string
    {
        $cells = [];

        if ($this->isRowOrderingEnabled()) {
            $cells[] = '            <th scope="col" class="text-center fastcrud-row-order fastcrud-row-order-header" aria-label="Reorder rows"></th>';
        }

        if ($this->hasNestedTables()) {
            $cells[] = '            <th scope="col" class="text-center fastcrud-nested fastcrud-nested-header" aria-label="Toggle nested rows"></th>';
        }

        if ($this->isBatchDeleteEnabled()) {
            $cells[] = '            <th scope="col" class="text-center fastcrud-select fastcrud-select-header"><input type="checkbox" class="form-check-input fastcrud-select-all" aria-label="Select all rows"></th>';
        }

        if (!empty($this->config['numbers_enabled'])) {
            $cells[] = '            <th scope="col" class="text-center fastcrud-number fastcrud-number-header">#</th>';
        }

        foreach ($columns as $column) {
            $label   = $this->resolveColumnLabel($column);
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

            return array_values(
                array_filter(
                    $columns,
                    static fn(string $column): bool => strpos($column, '__fastcrud') !== 0
                )
            );
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

    /**
     * @return array<string, string>
     */
    private function getUiTextDefaults(): array
    {
        $defaults = [
            'add'                  => 'Add',
            'add_record'           => 'Add Record',
            'add_new_record'       => 'Add new record',
            'edit'                 => 'Edit',
            'edit_record'          => 'Edit Record',
            'edit_record_with_id'   => 'Edit Record {id}',
            'view'                 => 'View',
            'view_record'          => 'View Record',
            'delete'               => 'Delete',
            'delete_record'        => 'Delete record',
            'duplicate'            => 'Duplicate',
            'duplicate_record'     => 'Duplicate record',
            'search'               => 'Search',
            'search_placeholder'   => 'Search...',
            'clear'                => 'Clear',
            'filters'              => 'Filters',
            'saved_views'          => 'Saved views',
            'default_view'         => 'Default view',
            'delete_selected_view' => 'Delete selected view',
            'delete_selected'      => 'Delete Selected',
            'delete_selected_title' => 'Delete selected records',
            'bulk_actions'         => 'Bulk actions',
            'apply'                => 'Apply',
            'save_changes'          => 'Save Changes',
            'create_record_new'     => 'Create Record & New',
            'changes_saved'         => 'Changes saved successfully.',
            'cancel'                => 'Cancel',
            'close'                 => 'Close',
            'export_csv'           => 'Export CSV',
            'export_csv_title'     => 'Export as CSV',
            'all_columns'          => 'All Columns',
            'all'                  => 'All',
            'loading'              => 'Loading...',
            'loading_data'         => 'Loading data...',
            'loading_nested'       => 'Loading {title}...',
            'fetching_nested_records' => 'Fetching nested records...',
            'no_records'           => 'No records found.',
            'no_record_selected'   => 'No record selected.',
            'no_columns'           => 'No columns available for this table.',
            'pagination'           => 'Table pagination',
            'previous'             => 'Previous',
            'next'                 => 'Next',
            'showing_range'        => 'Showing {start}-{end} of {total}',
            'open_link'            => 'Open link',
            'query_builder'        => 'Query Builder',
            'match_conditions'     => 'Match Conditions',
            'all_conditions'       => 'All conditions (AND)',
            'any_condition'        => 'Any condition (OR)',
            'conditions'           => 'Conditions',
            'filter_help'          => 'Add one or more conditions to filter results',
            'sort_order'           => 'Sort Order',
            'sort_help'            => 'Define the priority of ordering',
            'add_condition'        => 'Add Condition',
            'add_sort'             => 'Add Sort',
            'save_view'            => 'Save as View',
        ];

        $globalTexts = CrudStyle::$texts ?? [];
        if (is_array($globalTexts)) {
            foreach ($globalTexts as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }

                $normalizedKey = $this->normalizeUiTextKey($key);
                if ($normalizedKey !== '') {
                    $defaults[$normalizedKey] = (string) $value;
                }
            }
        }

        $instanceTexts = $this->config['ui_text'] ?? [];
        if (is_array($instanceTexts)) {
            foreach ($instanceTexts as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }

                $normalizedKey = $this->normalizeUiTextKey($key);
                if ($normalizedKey !== '') {
                    $defaults[$normalizedKey] = (string) $value;
                }
            }
        }

        return $defaults;
    }

    private function uiText(string $key, ?string $fallback = null): string
    {
        $texts         = $this->getUiTextDefaults();
        $normalizedKey = $this->normalizeUiTextKey($key);

        if ($normalizedKey !== '' && array_key_exists($normalizedKey, $texts)) {
            return $texts[$normalizedKey];
        }

        return $fallback ?? $key;
    }

    /**
     * Merge CrudStyle overrides with library defaults.
     *
     * @return array<string, string>
     */
    private function getStyleDefaults(): array
    {
        $defaults = [
            'link_button_class'             => 'btn btn-sm btn-outline-secondary',
            'panel_cancel_button_class'     => 'btn btn-outline-secondary',
            'panel_save_button_class'       => 'btn btn-primary',
            'search_button_class'           => 'btn btn-outline-primary',
            'search_clear_button_class'     => 'btn btn-outline-secondary',
            'filters_button_class'          => 'btn btn-sm btn-outline-secondary',
            'batch_delete_button_class'     => 'btn btn-sm btn-danger',
            'bulk_apply_button_class'       => 'btn btn-sm btn-outline-primary',
            'export_csv_button_class'       => 'btn btn-sm btn-outline-secondary',
            'add_button_class'              => 'btn btn-sm btn-success',
            'duplicate_action_button_class' => 'btn btn-sm btn-info',
            'view_action_button_class'      => 'btn btn-sm btn-secondary',
            'edit_action_button_class'      => 'btn btn-sm btn-primary',
            'delete_action_button_class'    => 'btn btn-sm btn-danger',
            'nested_toggle_button_classes'  => 'btn btn-link p-0',
            'edit_view_row_highlight_class' => 'table-active',
            'bools_in_grid_color'           => 'primary',
            'x_icon_class'                  => 'fas fa-xmark',
        ];

        $globalActionClass    = '';
        $globalActionClassRaw = CrudStyle::$action_button_global_class ?? '';
        if (is_string($globalActionClassRaw)) {
            $globalActionClass = trim($globalActionClassRaw);
        }

        $toolbarGlobalClass    = '';
        $toolbarGlobalClassRaw = CrudStyle::$toolbar_action_button_global_class ?? '';
        if (is_string($toolbarGlobalClassRaw)) {
            $toolbarGlobalClass = trim($toolbarGlobalClassRaw);
        }

        $overrides = [
            'link_button_class'             => CrudStyle::$link_button_class ?? '',
            'panel_cancel_button_class'     => CrudStyle::$panel_cancel_button_class ?? '',
            'panel_save_button_class'       => CrudStyle::$panel_save_button_class ?? '',
            'search_button_class'           => CrudStyle::$search_button_class ?? '',
            'search_clear_button_class'     => CrudStyle::$search_clear_button_class ?? '',
            'filters_button_class'          => CrudStyle::$filters_button_class ?? '',
            'batch_delete_button_class'     => CrudStyle::$batch_delete_button_class ?? '',
            'bulk_apply_button_class'       => CrudStyle::$bulk_apply_button_class ?? '',
            'export_csv_button_class'       => CrudStyle::$export_csv_button_class ?? '',
            'add_button_class'              => CrudStyle::$add_button_class ?? '',
            'duplicate_action_button_class' => CrudStyle::$duplicate_action_button_class ?? '',
            'view_action_button_class'      => CrudStyle::$view_action_button_class ?? '',
            'edit_action_button_class'      => CrudStyle::$edit_action_button_class ?? '',
            'delete_action_button_class'    => CrudStyle::$delete_action_button_class ?? '',
            'nested_toggle_button_classes'  => CrudStyle::$nested_toggle_button_classes ?? '',
            'edit_view_row_highlight_class' => CrudStyle::$edit_view_row_highlight_class ?? '',
            'bools_in_grid_color'           => CrudStyle::$bools_in_grid_color ?? '',
            'x_icon_class'                  => CrudStyle::$x_icon_class ?? '',
        ];

        $appliedOverrides = [];

        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $defaults[$key]         = $trimmed;
            $appliedOverrides[$key] = true;
        }

        $instanceOverrides = $this->config['style_overrides'] ?? [];
        if (is_array($instanceOverrides)) {
            foreach ($instanceOverrides as $key => $value) {
                if (!is_string($key) || !is_string($value) || !array_key_exists($key, $defaults)) {
                    continue;
                }

                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                $defaults[$key]         = $trimmed;
                $appliedOverrides[$key] = true;
            }
        }

        if ($globalActionClass !== '') {
            $rowActionKeys = [
                'view_action_button_class',
                'edit_action_button_class',
                'delete_action_button_class',
                'duplicate_action_button_class',
            ];

            foreach ($rowActionKeys as $actionKey) {
                if (!array_key_exists($actionKey, $defaults)) {
                    continue;
                }

                if (!empty($appliedOverrides[$actionKey])) {
                    continue;
                }

                $defaults[$actionKey] = $globalActionClass;
            }
        }

        if ($toolbarGlobalClass !== '') {
            $toolbarActionKeys = [
                'add_button_class',
                'link_button_class',
                'batch_delete_button_class',
                'bulk_apply_button_class',
                'export_csv_button_class',
                'search_button_class',
                'search_clear_button_class',
                'filters_button_class',
            ];

            foreach ($toolbarActionKeys as $actionKey) {
                if (!array_key_exists($actionKey, $defaults)) {
                    continue;
                }

                if (!empty($appliedOverrides[$actionKey])) {
                    continue;
                }

                $defaults[$actionKey] = $toolbarGlobalClass;
            }
        }

        $defaults['action_button_global_class']         = $globalActionClass;
        $defaults['toolbar_action_button_global_class'] = $toolbarGlobalClass;

        return $defaults;
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

    private function buildEditOffcanvas(string $id, bool $inline = false, string $displayMode = self::DEFAULT_FORM_DISPLAY_MODE): string
    {
        $escapedId = $this->escapeHtml($id);
        $labelId   = $escapedId . '-edit-label';
        $formId    = $escapedId . '-edit-form';
        $panelId   = $escapedId . '-edit-panel';
        $errorId   = $escapedId . '-edit-error';
        $successId = $escapedId . '-edit-success';
        $fieldsId  = $escapedId . '-edit-fields';

        $widthStyle = '';
        if ($this->config['form_width'] !== null) {
            $width      = $this->escapeHtml($this->config['form_width']);
            $widthStyle = " style=\"width: {$width};\"";
        }
        $modalDialogStyle = '';
        if ($this->config['form_width'] !== null) {
            $width            = $this->escapeHtml($this->config['form_width']);
            $modalDialogStyle = " style=\"--bs-modal-width: {$width}; width: {$width}; max-width: calc(100% - 1rem);\"";
        }

        $styles      = $this->getStyleDefaults();
        $cancelClass = $this->escapeHtml($styles['panel_cancel_button_class']);
        $saveClass   = $this->escapeHtml($styles['panel_save_button_class']);
        $editRecordText = $this->escapeHtml($this->uiText('edit_record'));
        $saveChangesText = $this->escapeHtml($this->uiText('save_changes'));
        $createRecordNewText = $this->escapeHtml($this->uiText('create_record_new'));
        $changesSavedText = $this->escapeHtml($this->uiText('changes_saved'));
        $cancelText = $this->escapeHtml($this->uiText('cancel'));
        $closeText = $this->escapeHtml($this->uiText('close'));

        if ($inline) {
            $panelClasses = 'fastcrud-inline-panel card shadow-sm border-0';
            $inlineCloseButton = $displayMode === 'inline'
                ? <<<HTML
            <button type="button" class="btn-close" data-fastcrud-inline-close="1" aria-label="{$closeText}"></button>
HTML
                : '';
            $inlineCancelButton = $displayMode === 'inline'
                ? <<<HTML
                <button type="button" class="{$cancelClass}" data-fastcrud-inline-close="1">{$cancelText}</button>
HTML
                : '';
            return <<<HTML
<div class="{$panelClasses}" id="{$panelId}" data-fastcrud-inline="1">
    <div class="card-header border-bottom d-flex align-items-center justify-content-between">
        <h5 class="mb-0" id="{$labelId}">{$editRecordText}</h5>
        <div class="d-flex flex-wrap align-items-center gap-2 justify-content-end">
            <button type="submit" form="{$formId}" class="{$saveClass} fastcrud-submit-close" data-fastcrud-submit-action="close">{$saveChangesText}</button>
            <button type="submit" form="{$formId}" class="{$saveClass} fastcrud-submit-new d-none" data-fastcrud-submit-action="new">{$createRecordNewText}</button>
{$inlineCloseButton}
        </div>
    </div>
    <div class="card-body">
        <form id="{$formId}" novalidate class="d-flex flex-column gap-3">
            <div class="alert alert-danger d-none" id="{$errorId}" role="alert"></div>
            <div class="alert alert-success d-none" id="{$successId}" role="alert">{$changesSavedText}</div>
            <div id="{$fieldsId}" class="fastcrud-inline-fields"></div>
            <div class="d-flex justify-content-end gap-2 pt-2">
{$inlineCancelButton}
                <button type="submit" class="{$saveClass} fastcrud-submit-close" data-fastcrud-submit-action="close">{$saveChangesText}</button>
                <button type="submit" class="{$saveClass} fastcrud-submit-new d-none" data-fastcrud-submit-action="new">{$createRecordNewText}</button>
            </div>
        </form>
    </div>
</div>
HTML;
        }

        if ($displayMode === 'modal') {
            return <<<HTML
<div class="modal fade fastcrud-edit-modal" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg"{$modalDialogStyle}>
        <form id="{$formId}" novalidate class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="{$labelId}">{$editRecordText}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{$closeText}"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="{$errorId}" role="alert"></div>
                <div class="alert alert-success d-none" id="{$successId}" role="alert">{$changesSavedText}</div>
                <div id="{$fieldsId}"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="{$cancelClass}" data-bs-dismiss="modal">{$cancelText}</button>
                <button type="submit" class="{$saveClass}">{$saveChangesText}</button>
            </div>
        </form>
    </div>
</div>
HTML;
        }

        if ($displayMode === 'side') {
            return <<<HTML
<div class="fastcrud-side-panel card shadow-sm" id="{$panelId}" data-fastcrud-side-panel="1" aria-labelledby="{$labelId}">
    <div class="card-header border-bottom d-flex align-items-center justify-content-between gap-3">
        <h5 class="mb-0" id="{$labelId}">{$editRecordText}</h5>
        <button type="button" class="btn-close" data-fastcrud-side-close="1" aria-label="{$closeText}"></button>
    </div>
    <div class="card-body">
        <form id="{$formId}" novalidate class="d-flex flex-column h-100">
            <div class="alert alert-danger d-none" id="{$errorId}" role="alert"></div>
            <div class="alert alert-success d-none" id="{$successId}" role="alert">{$changesSavedText}</div>
            <div id="{$fieldsId}" class="fastcrud-side-fields flex-grow-1 overflow-auto"></div>
            <div class="d-flex justify-content-end gap-2 mt-auto pt-3 border-top sticky-bottom">
                <button type="button" class="{$cancelClass}" data-fastcrud-side-close="1">{$cancelText}</button>
                <button type="submit" class="{$saveClass}">{$saveChangesText}</button>
            </div>
        </form>
    </div>
</div>
HTML;
        }

        $panelClasses = 'offcanvas offcanvas-start';
        $inlineAttr   = '';

        return <<<HTML
<div class="{$panelClasses}" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}"{$widthStyle}{$inlineAttr}>
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="{$labelId}">{$editRecordText}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{$closeText}"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <form id="{$formId}" novalidate class="d-flex flex-column h-100">
            <div class="alert alert-danger d-none" id="{$errorId}" role="alert"></div>
            <div class="alert alert-success d-none" id="{$successId}" role="alert">{$changesSavedText}</div>
            <div id="{$fieldsId}" class="flex-grow-1 overflow-auto"></div>
            <div class="d-flex justify-content-end gap-2 mt-auto pt-3 border-top sticky-bottom">
                <button type="button" class="{$cancelClass}" data-bs-dismiss="offcanvas">{$cancelText}</button>
                <button type="submit" class="{$saveClass}">{$saveChangesText}</button>
            </div>
        </form>
    </div>
</div>
HTML;
    }

    private function buildViewOffcanvas(string $id, bool $inline = false, string $displayMode = self::DEFAULT_FORM_DISPLAY_MODE): string
    {
        $escapedId = $this->escapeHtml($id);
        $labelId   = $escapedId . '-view-label';
        $panelId   = $escapedId . '-view-panel';
        $contentId = $escapedId . '-view-content';
        $emptyId   = $escapedId . '-view-empty';

        $widthStyle = '';
        if ($this->config['form_width'] !== null) {
            $width      = $this->escapeHtml($this->config['form_width']);
            $widthStyle = " style=\"width: {$width};\"";
        }
        $viewRecordText       = $this->escapeHtml($this->uiText('view_record'));
        $noRecordSelectedText = $this->escapeHtml($this->uiText('no_record_selected'));
        $closeText            = $this->escapeHtml($this->uiText('close'));

        if ($inline) {
            $panelClasses = 'fastcrud-inline-panel card shadow-sm border-0';
            $inlineCloseButton = $displayMode === 'inline'
                ? <<<HTML
        <button type="button" class="btn-close" data-fastcrud-inline-close="1" aria-label="{$closeText}"></button>
HTML
                : '';
            return <<<HTML
<div class="{$panelClasses}" id="{$panelId}" data-fastcrud-inline="1">
    <div class="card-header border-bottom d-flex align-items-center justify-content-between gap-3">
        <h5 class="mb-0" id="{$labelId}">{$viewRecordText}</h5>
{$inlineCloseButton}
    </div>
    <div class="card-body">
        <div class="alert alert-info d-none" id="{$emptyId}" role="alert">{$noRecordSelectedText}</div>
        <div id="{$contentId}" class="list-group list-group-flush"></div>
    </div>
</div>
HTML;
        }

        if ($displayMode === 'side') {
            return <<<HTML
<div class="fastcrud-side-panel card shadow-sm" id="{$panelId}" data-fastcrud-side-panel="1" aria-labelledby="{$labelId}">
    <div class="card-header border-bottom d-flex align-items-center justify-content-between gap-3">
        <h5 class="mb-0" id="{$labelId}">{$viewRecordText}</h5>
        <button type="button" class="btn-close" data-fastcrud-side-close="1" aria-label="{$closeText}"></button>
    </div>
    <div class="card-body">
        <div class="alert alert-info d-none" id="{$emptyId}" role="alert">{$noRecordSelectedText}</div>
        <div id="{$contentId}" class="list-group list-group-flush fastcrud-side-fields flex-grow-1 overflow-auto"></div>
    </div>
</div>
HTML;
        }

        $panelClasses = 'offcanvas offcanvas-start';
        $inlineAttr   = '';

        return <<<HTML
<div class="{$panelClasses}" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}"{$widthStyle}{$inlineAttr}>
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="{$labelId}">{$viewRecordText}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{$closeText}"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <div class="alert alert-info d-none" id="{$emptyId}" role="alert">{$noRecordSelectedText}</div>
        <div id="{$contentId}" class="list-group list-group-flush flex-grow-1 overflow-auto"></div>
    </div>
</div>
HTML;
    }

    private function buildQueryBuilderModal(string $id, bool $formOnly = false): string
    {
        if ($formOnly) {
            return '';
        }

        $escapedId = $this->escapeHtml($id);
        $modalId   = $escapedId . '-query-builder';
        $logicId   = $escapedId . '-qb-logic';
        $filtersId = $escapedId . '-qb-filters';
        $sortsId   = $escapedId . '-qb-sorts';
        $applyId   = $escapedId . '-qb-apply';
        $clearId   = $escapedId . '-qb-clear';
        $saveId    = $escapedId . '-qb-save';
        $queryBuilderText = $this->escapeHtml($this->uiText('query_builder'));
        $matchConditionsText = $this->escapeHtml($this->uiText('match_conditions'));
        $allConditionsText = $this->escapeHtml($this->uiText('all_conditions'));
        $anyConditionText = $this->escapeHtml($this->uiText('any_condition'));
        $conditionsText = $this->escapeHtml($this->uiText('conditions'));
        $filterHelpText = $this->escapeHtml($this->uiText('filter_help'));
        $sortOrderText = $this->escapeHtml($this->uiText('sort_order'));
        $sortHelpText = $this->escapeHtml($this->uiText('sort_help', 'Define the priority of ordering'));
        $clearText = $this->escapeHtml($this->uiText('clear'));
        $saveViewText = $this->escapeHtml($this->uiText('save_view'));
        $applyText = $this->escapeHtml($this->uiText('apply'));
        $closeText = $this->escapeHtml($this->uiText('close'));

        return <<<HTML
<div class="modal fade fastcrud-query-builder" id="{$modalId}" tabindex="-1" aria-labelledby="{$modalId}-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="{$modalId}-label">{$queryBuilderText}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{$closeText}"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="{$logicId}" class="form-label">{$matchConditionsText}</label>
                    <select id="{$logicId}" class="form-select form-select-sm" style="max-width: 14rem;">
                        <option value="AND">{$allConditionsText}</option>
                        <option value="OR">{$anyConditionText}</option>
                    </select>
                </div>
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">{$conditionsText}</h6>
                        <small class="text-muted">{$filterHelpText}</small>
                    </div>
                    <div id="{$filtersId}"></div>
                </div>
                <div class="mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">{$sortOrderText}</h6>
                        <small class="text-muted">{$sortHelpText}</small>
                    </div>
                    <div id="{$sortsId}"></div>
                </div>
            </div>
            <div class="modal-footer d-flex align-items-center">
                <div class="me-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="{$clearId}">{$clearText}</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="{$saveId}">{$saveViewText}</button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" id="{$applyId}">{$applyText}</button>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
    }

    private function buildActionColumnStyles(string $id, bool $formOnly = false, string $formDisplayMode = self::DEFAULT_FORM_DISPLAY_MODE): string
    {
        $containerId = $this->escapeHtml($id . '-container');
        $fieldsId    = $this->escapeHtml($id . '-edit-fields');
        $editPanelId = $this->escapeHtml($id . '-edit-panel');
        $viewPanelId = $this->escapeHtml($id . '-view-panel');
        $metaId      = $this->escapeHtml($id . '-meta');
        $summaryId   = $this->escapeHtml($id . '-summary');
        $styles      = $this->getStyleDefaults();
        $switchColor = $this->resolveAccentColor($styles['bools_in_grid_color'] ?? 'primary');

        $additionalCss = '';
        if ($formOnly || $formDisplayMode === 'inline') {
            $inlineWidth = $this->config['form_width'] !== null
                ? $this->escapeHtml((string) $this->config['form_width'])
                : '100%';
            $additionalCss = <<<CSS
#{$containerId}.fastcrud-form-only .table-responsive,
#{$containerId}.fastcrud-form-only nav,
#{$containerId}.fastcrud-form-only #{$summaryId} {
    display: none !important;
}
#{$containerId}.fastcrud-form-only #{$metaId} {
    display: none !important;
}
#{$containerId}.fastcrud-has-inline-form .fastcrud-inline-panel-slot {
    margin-bottom: 1rem;
}
#{$containerId}.fastcrud-has-inline-form.fastcrud-inline-open #{$metaId},
#{$containerId}.fastcrud-has-inline-form.fastcrud-inline-open .fastcrud-table-container,
#{$containerId}.fastcrud-has-inline-form.fastcrud-inline-open > nav,
#{$containerId}.fastcrud-has-inline-form.fastcrud-inline-open #{$summaryId} {
    display: none !important;
}
#{$editPanelId}.fastcrud-inline-panel,
#{$viewPanelId}.fastcrud-inline-panel {
    position: static;
    visibility: hidden;
    transform: none !important;
    width: {$inlineWidth};
    max-width: 100%;
    border-radius: 0.5rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    box-shadow: none;
    display: none;
    margin: 0 auto 1.5rem;
    background-color: var(--bs-body-bg, #ffffff);
}
#{$editPanelId}.fastcrud-inline-panel.fastcrud-inline-visible,
#{$editPanelId}.fastcrud-inline-panel.show,
#{$viewPanelId}.fastcrud-inline-panel.fastcrud-inline-visible,
#{$viewPanelId}.fastcrud-inline-panel.show {
    display: block;
    visibility: visible;
}
#{$editPanelId}.fastcrud-inline-panel .card-header,
#{$viewPanelId}.fastcrud-inline-panel .card-header {
    background-color: transparent;
}
#{$editPanelId}.fastcrud-inline-panel .card-body,
#{$viewPanelId}.fastcrud-inline-panel .card-body {
    padding: 1.5rem;
}
#{$editPanelId}.fastcrud-inline-panel .fastcrud-inline-fields {
    min-height: 10rem;
}
CSS;
        } elseif ($formDisplayMode === 'side') {
            $sideWidth = $this->config['form_width'] !== null
                ? $this->escapeHtml((string) $this->config['form_width'])
                : '420px';
            $additionalCss = <<<CSS
#{$containerId}.fastcrud-has-side-form .fastcrud-side-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
    align-items: stretch;
}
#{$containerId}.fastcrud-has-side-form .fastcrud-side-table {
    min-width: 0;
}
#{$containerId}.fastcrud-has-side-form .fastcrud-side-panel-slot {
    display: none;
    min-width: 0;
    min-height: 0;
    align-items: stretch;
}
#{$containerId}.fastcrud-has-side-form.fastcrud-side-open .fastcrud-side-layout {
    grid-template-columns: minmax(0, 1fr) minmax(0, {$sideWidth});
}
#{$containerId}.fastcrud-has-side-form.fastcrud-side-open .fastcrud-side-panel-slot {
    display: flex;
}
#{$editPanelId}.fastcrud-side-panel,
#{$viewPanelId}.fastcrud-side-panel {
    display: none;
    position: sticky;
    top: 1rem;
    width: 100%;
    max-height: none;
    min-height: 0;
    overflow: hidden;
    border: 1px solid var(--bs-border-color, #dee2e6);
    background-color: var(--bs-body-bg, #ffffff);
}
#{$editPanelId}.fastcrud-side-panel.fastcrud-inline-visible,
#{$editPanelId}.fastcrud-side-panel.show,
#{$viewPanelId}.fastcrud-side-panel.fastcrud-inline-visible,
#{$viewPanelId}.fastcrud-side-panel.show {
    display: flex;
    flex-direction: column;
}
#{$editPanelId}.fastcrud-side-panel .card-body,
#{$viewPanelId}.fastcrud-side-panel .card-body {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}
#{$editPanelId}.fastcrud-side-panel form {
    flex: 1 1 auto;
    min-height: 0;
}
#{$editPanelId}.fastcrud-side-panel .fastcrud-side-fields,
#{$viewPanelId}.fastcrud-side-panel .fastcrud-side-fields {
    min-height: 0;
    overflow: auto;
}
@media (max-width: 991.98px) {
    #{$containerId}.fastcrud-has-side-form.fastcrud-side-open .fastcrud-side-layout {
        grid-template-columns: minmax(0, 1fr);
    }
    #{$editPanelId}.fastcrud-side-panel,
    #{$viewPanelId}.fastcrud-side-panel {
        position: static;
        height: auto !important;
    }
}
CSS;
        }

        return <<<HTML
<style>
#{$containerId} .table-responsive {
    position: relative;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

#{$containerId} table {
    position: relative;
    border-collapse: collapse;
    border-spacing: 0;
    width: 100%;
    min-width: 100%;
    table-layout: auto;
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

#{$containerId} table thead th.fastcrud-nested,
#{$containerId} table tbody td.fastcrud-nested-cell,
#{$containerId} table tfoot td.fastcrud-nested-cell {
    width: 2.75rem;
    min-width: 2.75rem;
    text-align: center;
}

#{$containerId} table thead th.fastcrud-number,
#{$containerId} table tbody td.fastcrud-number-cell,
#{$containerId} table tfoot td.fastcrud-number-cell {
    width: 2.75rem;
    min-width: 2.75rem;
    text-align: center;
}

#{$containerId} table thead th.fastcrud-row-order,
#{$containerId} table tbody td.fastcrud-row-order-cell {
    width: 2.75rem;
    min-width: 2.75rem;
    text-align: center;
    vertical-align: middle;
}

#{$containerId} .fastcrud-row-order-handle {
    cursor: grab;
    touch-action: none;
}

#{$containerId} .fastcrud-row-order-handle:active {
    cursor: grabbing;
}

#{$containerId} table tbody tr.fastcrud-row-order-chosen {
    background: var(--bs-tertiary-bg, rgba(13, 110, 253, .08));
}

#{$containerId} table tbody tr.fastcrud-row-order-dragging,
#{$containerId} table tbody tr.fastcrud-row-order-ghost {
    opacity: .55;
}

#{$containerId} table tbody tr.fastcrud-row-order-ghost {
    outline: 1px dashed var(--bs-primary, #0d6efd);
}

#{$containerId} table tbody td.fastcrud-nested-cell {
    vertical-align: middle;
}

#{$containerId} .fastcrud-nested-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    border: 1px solid var(--bs-border-color, #dee2e6);
    background-color: var(--bs-body-bg, #ffffff);
    color: inherit;
    text-decoration: none;
}

#{$containerId} .fastcrud-nested-toggle:hover {
    background-color: var(--bs-gray-100, rgba(0,0,0,0.05));
}

#{$containerId} .fastcrud-nested-row td {
    background-color: var(--bs-tertiary-bg, rgba(0,0,0,0.02));
}

#{$containerId} .fastcrud-nested-wrapper {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

#{$containerId} .fastcrud-view-controls .fastcrud-view-select {
    min-width: 12rem;
    width: auto;
}

#{$containerId} .fastcrud-view-controls .fastcrud-saved-view-group {
    flex: 0 0 auto;
    width: auto;
}

#{$containerId} .fastcrud-view-controls .fastcrud-saved-view-group .btn {
    flex: 0 0 auto;
    white-space: nowrap;
}

#{$containerId} .fastcrud-view-controls .fastcrud-open-query-builder {
    display: inline-flex;
    align-items: center;
}

#{$containerId} .fastcrud-search-btn,
#{$containerId} .fastcrud-search-clear-btn,
#{$containerId} .fastcrud-toolbar-action,
#{$containerId} .fastcrud-open-query-builder,
#{$containerId} .fastcrud-batch-delete-btn,
#{$containerId} .fastcrud-bulk-apply-btn,
#{$containerId} .fastcrud-export-csv-btn,
#{$containerId} .fastcrud-add-btn {
    flex: 0 0 auto;
    white-space: nowrap;
}

.fastcrud-query-builder .modal-body h6 {
    font-size: 0.95rem;
}

.fastcrud-query-builder .modal-body small {
    font-size: 0.75rem;
}

.fastcrud-query-builder .fastcrud-qb-filter-row .form-select-sm,
.fastcrud-query-builder .fastcrud-qb-filter-row .form-control-sm,
.fastcrud-query-builder .fastcrud-qb-sort-row .form-select-sm {
    font-size: 0.875rem;
}

#{$containerId} table thead th.fastcrud-actions,
#{$containerId} table tbody td.fastcrud-actions-cell,
#{$containerId} table tfoot td.fastcrud-actions-cell {
    position: sticky;
    right: 0;
    width: fit-content;
}

#{$containerId} table thead th.fastcrud-actions {
    z-index: 1056;
    text-align: right;
    white-space: nowrap;
}

#{$containerId} table tbody td.fastcrud-actions-cell,
#{$containerId} table tfoot td.fastcrud-actions-cell {
    z-index: 1055;
    box-shadow: -6px 0 6px -6px rgba(0, 0, 0, 0.2);
    white-space: nowrap;
}

#{$containerId} table tbody td.fastcrud-actions-cell.fastcrud-actions-open,
#{$containerId} table tfoot td.fastcrud-actions-cell.fastcrud-actions-open {
    z-index: 1062;
}

#{$containerId} table tbody td.fastcrud-actions-cell .btn,
#{$containerId} table tbody td.fastcrud-actions-cell .fastcrud-action-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1.25;
   
    flex: 0 0 auto;
}

#{$containerId} table tbody td.fastcrud-actions-cell .fastcrud-action-button {
    min-height: calc(1.5rem + 0.5rem);
}

#{$containerId} table tbody td.fastcrud-actions-cell .fastcrud-actions-stack,
#{$containerId} table tfoot td.fastcrud-actions-cell .fastcrud-actions-stack {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.3rem;
    flex-wrap: nowrap;
    width: 100%;
}

#{$containerId} .fastcrud-icon {
    font-size: {$this->escapeHtml(CrudStyle::$action_icon_size)};
    line-height: 1;
}

#{$containerId} .fastcrud-link-icon {
    font-size: 1.25rem;
    line-height: 1;
}

#{$containerId} .fastcrud-link-btn-text {
    line-height: 1.25rem;
}

#{$containerId} .fastcrud-multi-link-icon {
    font-size: 1.1rem;
    line-height: 1;
}

#{$containerId} .fastcrud-multi-link-text {
    line-height: 1.25rem;
}

#{$containerId} .fastcrud-multi-link-item-icon {
    width: 1.1rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.35rem;
}

#{$containerId} .fastcrud-multi-link-item-text {
    line-height: 1.25rem;
}

#{$containerId} .fastcrud-multi-link-btn {
    position: relative;
}

#{$containerId} .fastcrud-multi-link-btn .dropdown-menu {
    z-index: 1070;
}

#{$containerId} .fastcrud-multi-link-menu {
    max-height: 20rem;
    max-height: min(60vh, 20rem);
    overflow-y: auto;
    overscroll-behavior: contain;
}

#{$containerId} .fastcrud-multi-link-menu.fastcrud-multi-link-filterable {
    min-width: 14rem;
}

#{$containerId} .fastcrud-multi-link-filter-wrapper {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: 0.35rem 0.5rem 0.4rem;
    background: var(--bs-dropdown-bg, #fff);
}

#{$containerId} .fastcrud-multi-link-filter-input {
    width: 100%;
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

{$additionalCss}
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
        if (
            str_starts_with($lower, 'var(')
            || str_starts_with($lower, 'rgb(')
            || str_starts_with($lower, 'rgba(')
            || str_starts_with($lower, 'hsl(')
            || str_starts_with($lower, 'hsla(')
            || str_starts_with($lower, '#')
        ) {
            return $c;
        }

        // Map Bootstrap theme keys to CSS vars
        $keys = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
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
        ?string $searchColumn = null,
    ): array {
        $defaultPerPage = $this->config['limit_default'] ?? $this->perPage;
        $perPage        = $perPage ?? $defaultPerPage;
        $page           = max(1, $page);

        $beforePayload = [
            'page'          => $page,
            'per_page'      => $perPage,
            'search_term'   => $searchTerm,
            'search_column' => $searchColumn,
        ];

        $beforeContext = [
            'operation' => 'fetch',
            'stage'     => 'before',
            'table'     => $this->table,
            'id'        => $this->id,
        ];

        $beforeFetch = $this->dispatchLifecycleEvent('before_fetch', $beforePayload, $beforeContext, true);

        if ($beforeFetch['cancelled']) {
            return [
                'rows'       => [],
                'columns'    => [],
                'pagination' => [
                    'current_page' => $page,
                    'total_pages'  => 0,
                    'total_rows'   => 0,
                    'per_page'     => $perPage ?? ($defaultPerPage ?? 0),
                ],
                'meta'       => [],
            ];
        }

        $modifiedPayload = $beforeFetch['payload'];
        if (is_array($modifiedPayload)) {
            if (array_key_exists('page', $modifiedPayload)) {
                $candidatePage = $modifiedPayload['page'];
                if (is_numeric($candidatePage)) {
                    $page = max(1, (int) $candidatePage);
                }
            }

            if (array_key_exists('per_page', $modifiedPayload)) {
                $candidatePerPage = $modifiedPayload['per_page'];
                if ($candidatePerPage === null || $candidatePerPage === 'all') {
                    $perPage = null;
                } elseif (is_numeric($candidatePerPage)) {
                    $perPage = (int) $candidatePerPage;
                }
            }

            if (array_key_exists('search_term', $modifiedPayload)) {
                $searchTermCandidate = $modifiedPayload['search_term'];
                $searchTerm          = $searchTermCandidate === null ? null : (string) $searchTermCandidate;
            }

            if (array_key_exists('search_column', $modifiedPayload)) {
                $searchColumnCandidate = $modifiedPayload['search_column'];
                if ($searchColumnCandidate === null) {
                    $searchColumn = null;
                } elseif (is_string($searchColumnCandidate)) {
                    $searchColumnCandidate = trim($searchColumnCandidate);
                    $searchColumn          = $searchColumnCandidate === '' ? null : $searchColumnCandidate;
                }
            }
        }

        $beforeContext['resolved'] = [
            'page'          => $page,
            'per_page'      => $perPage,
            'search_term'   => $searchTerm,
            'search_column' => $searchColumn,
        ];

        $limitValue = ($perPage !== null && $perPage > 0) ? $perPage : null;
        $prefetched = null;
        $totalRows = null;
        // Joins have distinct-record count semantics, so retain their count query.
        if ($this->config['joins'] === [] && $this->config['custom_columns'] === []
            && $this->config['column_callbacks'] === [] && ($page === 1 || $limitValue === null)) {
            $prefetched = $this->fetchData($limitValue, $limitValue === null ? null : 0, $searchTerm, $searchColumn);
            if ($limitValue === null || count($prefetched[0]) < $limitValue) {
                $totalRows = count($prefetched[0]);
            }
        }
        if ($totalRows === null) {
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
        }

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

        [$rows, $columns] = $prefetched ?? $this->fetchData($limitValue, $offset, $searchTerm, $searchColumn);

        $effectivePerPage = $limitValue ?? ($totalRows > 0 ? $totalRows : max(count($rows), 1));

        $result = [
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

        $afterContext = [
            'operation' => 'fetch',
            'stage'     => 'after',
            'table'     => $this->table,
            'id'        => $this->id,
            'resolved'  => $beforeContext['resolved'] ?? [],
        ];

        $afterFetch = $this->dispatchLifecycleEvent('after_fetch', $result, $afterContext, true);
        if (!$afterFetch['cancelled'] && is_array($afterFetch['payload'])) {
            $result = array_merge($result, $afterFetch['payload']);
        }

        return $result;
    }

    private function buildMetaWithSummaries(array $columns, ?string $searchTerm, ?string $searchColumn): array
    {
        $meta              = $this->buildMeta($columns);
        $meta['summaries'] = $this->buildSummaries($searchTerm, $searchColumn);

        return $meta;
    }

    private function buildColumnLookup(array $columns): array
    {
        $lookup = [];

        $register = function ($candidate) use (&$lookup): void
        {
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

        foreach (array_keys($this->config['custom_columns'] ?? []) as $customColumn) {
            $register($customColumn);
        }

        foreach (array_keys($this->config['custom_fields'] ?? []) as $customField) {
            $register($customField);
        }

        foreach (array_keys($this->config['field_callbacks'] ?? []) as $callbackField) {
            $register($callbackField);
        }

        return $lookup;
    }

    /**
     * Build template rows for each form mode so the client can render custom field markup
     * (field callbacks + custom fields) even before a record exists.
     *
     * @param array<int, string> $allColumns
     * @return array<string, array<string, mixed>>
     */
    private function buildFormTemplates(array $allColumns): array
    {
        $hasFieldCallbacks = $this->config['field_callbacks'] ?? [];
        $hasCustomFields   = $this->config['custom_fields'] ?? [];
        $behaviours        = $this->config['form']['behaviours'] ?? [];
        $hasPermissionRules = false;
        if (is_array($behaviours)) {
            foreach (['visible_if', 'editable_if'] as $permissionKey) {
                if (!isset($behaviours[$permissionKey]) || !is_array($behaviours[$permissionKey])) {
                    continue;
                }

                if ($behaviours[$permissionKey] !== []) {
                    $hasPermissionRules = true;
                    break;
                }
            }
        }

        if ($hasFieldCallbacks === [] && $hasCustomFields === [] && !$hasPermissionRules) {
            return [];
        }

        $primaryKeyColumn = $this->getPrimaryKeyColumn();

        $baseRow = [
            '__fastcrud_primary_key'   => $primaryKeyColumn,
            '__fastcrud_primary_value' => null,
        ];

        foreach ($allColumns as $column) {
            if (!is_string($column)) {
                continue;
            }

            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '' || array_key_exists($normalized, $baseRow)) {
                continue;
            }

            $baseRow[$normalized] = null;
        }

        $templates = [];

        // Templates seed the client-side forms for every mode. Tag placeholder rows
        // so callback authors can detect these pre-record invocations (all column
        // values are null at this stage) and bail when needed.
        $templateRows = ['create', 'edit', 'view'];

        foreach ($templateRows as $mode) {
            $row                        = $baseRow;
            $row['__fastcrud_template'] = true;
            $row                        = $this->applyFieldCallbacksToRow($row, $mode);
            $permissions                = $this->buildFieldPermissionState($allColumns, $mode, $row);
            if ($permissions !== []) {
                $row['__fastcrud_field_permissions'] = $permissions;
            }
            $templates[$mode] = $row;
        }

        return $templates;
    }

    private function buildMeta(array $columns): array
    {
        $columnLookup = $this->buildColumnLookup($columns);

        $filterColumns = static function (array $source) use ($columnLookup): array
        {
            $filtered = [];
            foreach ($source as $column => $value) {
                if (isset($columnLookup[$column])) {
                    $filtered[$column] = $value;
                }
            }
            return $filtered;
        };

        $tableMeta                        = $this->config['table_meta'];
        $batchDeleteConfigured            = isset($tableMeta['batch_delete']) ? (bool) $tableMeta['batch_delete'] : false;
        $tableMeta['batch_delete_button'] = $batchDeleteConfigured;
        $tableTitle                       = isset($tableMeta['title']) && is_string($tableMeta['title']) && $tableMeta['title'] !== ''
            ? $tableMeta['title']
            : $this->makeTitle($this->table);

        $inline = array_values(array_keys(array_filter($this->config['inline_edit'] ?? [], static fn($v) => (bool) $v)));

        $sortDisabled = array_values(
            array_filter(
                $this->config['sort_disabled'],
                static function ($col) use ($columnLookup): bool
                {
                    return is_string($col) && isset($columnLookup[$col]);
                }
            )
        );

        foreach (array_keys($this->config['custom_columns']) as $customColumn) {
            if (!is_string($customColumn)) {
                continue;
            }

            $normalized = $this->normalizeColumnReference($customColumn);
            if ($normalized === '' || isset($columnLookup[$normalized]) === false) {
                continue;
            }

            if (!in_array($normalized, $sortDisabled, true)) {
                $sortDisabled[] = $normalized;
            }
        }

        $formMeta = $this->buildFormMeta($columns);
        if (isset($formMeta['all_columns']) && is_array($formMeta['all_columns'])) {
            $templates = $this->buildFormTemplates($formMeta['all_columns']);
            if ($templates !== []) {
                $formMeta['templates'] = $templates;
            }
        }

        return [
            'table'                  => [
                'key'                 => $this->table,
                'title'               => $tableTitle,
                'tooltip'             => $tableMeta['tooltip'] ?? null,
                'icon'                => $tableMeta['icon'] ?? null,
                'hide_title'          => isset($tableMeta['hide_title'])
                    ? (bool) $tableMeta['hide_title']
                    : CrudConfig::$hide_table_title,
                'add'                 => isset($tableMeta['add']) ? (bool) $tableMeta['add'] : true,
                'view'                => isset($tableMeta['view']) ? (bool) $tableMeta['view'] : true,
                'view_condition'      => isset($tableMeta['view_condition']) && is_array($tableMeta['view_condition'])
                    ? $tableMeta['view_condition']
                    : null,
                'edit'                => isset($tableMeta['edit']) ? (bool) $tableMeta['edit'] : true,
                'edit_condition'      => isset($tableMeta['edit_condition']) && is_array($tableMeta['edit_condition'])
                    ? $tableMeta['edit_condition']
                    : null,
                'delete'              => isset($tableMeta['delete']) ? (bool) $tableMeta['delete'] : true,
                'delete_condition'    => isset($tableMeta['delete_condition']) && is_array($tableMeta['delete_condition'])
                    ? $tableMeta['delete_condition']
                    : null,
                'duplicate'           => isset($tableMeta['duplicate']) ? (bool) $tableMeta['duplicate'] : false,
                'duplicate_condition' => isset($tableMeta['duplicate_condition']) && is_array($tableMeta['duplicate_condition'])
                    ? $tableMeta['duplicate_condition']
                    : null,
                'batch_delete'        => $this->isBatchDeleteEnabled(),
                'batch_delete_button' => $batchDeleteConfigured,
                'bulk_actions'        => isset($tableMeta['bulk_actions']) && is_array($tableMeta['bulk_actions'])
                    ? array_values($tableMeta['bulk_actions'])
                    : [],
                'toolbar_actions'     => $this->getNormalizedToolbarActionsConfig(),
                'toolbar_html'        => $this->getNormalizedToolbarHtmlConfig(),
                'delete_confirm'      => isset($tableMeta['delete_confirm']) ? (bool) $tableMeta['delete_confirm'] : true,
                'export_csv'          => isset($tableMeta['export_csv']) ? (bool) $tableMeta['export_csv'] : false,
            ],
            'link_buttons'           => $this->getNormalizedLinkButtonsConfig(),
            'multi_link_buttons'     => $this->getNormalizedMultiLinkButtonsConfig(),
            'action_button_sequence' => $this->getActionButtonSequence(),
            'primary_key'            => $this->getPrimaryKeyColumn(),
            'columns'                => $columns,
            'labels'                 => $filterColumns($this->config['column_labels']),
            'column_classes'         => $filterColumns($this->config['column_classes']),
            'column_widths'          => $filterColumns($this->config['column_widths']),
            'limit_options'          => $this->config['limit_options'],
            'default_limit'          => $this->config['limit_default'] ?? $this->perPage,
            'compact_pagination'     => (bool) ($this->config['compact_pagination'] ?? false),
            'search'                 => [
                'columns'   => $this->config['search_columns'],
                'default'   => $this->config['search_default'],
                'available' => array_keys($this->getWhereColumnsMapForAllSearch()),
                'hidden'    => (bool) ($this->config['hide_search'] ?? false),
            ],
            'order_by'               => array_map(
                static fn(array $order): array               => [
                    'field'     => $order['field'],
                    'direction' => $order['direction'],
                ],
                $this->config['order_by']
            ),
            'sort_disabled'          => $sortDisabled,
            'form'                   => $formMeta,
            'form_display_mode'      => $this->normalizeFormDisplayMode((string) ($this->config['form_display_mode'] ?? self::DEFAULT_FORM_DISPLAY_MODE)),
            'inline_edit'            => $inline,
            'numbers_enabled'        => (bool) ($this->config['numbers_enabled'] ?? false),
            'nested_tables'          => $this->buildNestedTablesClientConfigPayload(),
            'soft_delete'            => $this->config['soft_delete'],
            'row_ordering'           => $this->config['row_ordering'],
            'query_builder'          => $this->buildQueryBuilderClientPayload(),
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

                    $section = null;
                    if (isset($entry['section']) && is_string($entry['section'])) {
                        $sectionCandidate = $this->normalizeSectionIdentifier($entry['section']);
                        if ($sectionCandidate !== '') {
                            $section = $sectionCandidate;
                        }
                    }

                    $normalizedEntries[] = [
                        'fields'  => $fields,
                        'reverse' => !empty($entry['reverse']),
                        'tab'     => isset($entry['tab']) && is_string($entry['tab']) && $entry['tab'] !== ''
                            ? $entry['tab']
                            : null,
                        'section' => $section,
                    ];
                }

                if ($normalizedEntries !== []) {
                    $layouts[$mode] = $normalizedEntries;
                }
            }
        }

        $sections = [];
        if (isset($this->config['form']['sections']) && is_array($this->config['form']['sections'])) {
            foreach ($this->config['form']['sections'] as $mode => $entries) {
                if (!is_array($entries)) {
                    continue;
                }

                $normalizedSections = [];
                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $sectionId = null;
                    if (isset($entry['id']) && is_string($entry['id'])) {
                        $candidate = $this->normalizeSectionIdentifier($entry['id']);
                        if ($candidate !== '') {
                            $sectionId = $candidate;
                        }
                    }

                    if ($sectionId === null && isset($entry['section']) && is_string($entry['section'])) {
                        $candidate = $this->normalizeSectionIdentifier($entry['section']);
                        if ($candidate !== '') {
                            $sectionId = $candidate;
                        }
                    }

                    if ($sectionId === null) {
                        continue;
                    }

                    $fields = [];
                    if (isset($entry['fields']) && is_array($entry['fields'])) {
                        foreach ($entry['fields'] as $field) {
                            if (is_string($field) && isset($columnLookup[$field])) {
                                $fields[] = $field;
                            }
                        }
                    }

                    if ($fields === []) {
                        continue;
                    }

                    $title = null;
                    if (isset($entry['title']) && is_string($entry['title'])) {
                        $trimmedTitle = trim($entry['title']);
                        $title        = $trimmedTitle === '' ? null : $trimmedTitle;
                    }

                    $description = null;
                    if (isset($entry['description']) && is_string($entry['description'])) {
                        $trimmedDescription = trim($entry['description']);
                        $description        = $trimmedDescription === '' ? null : $trimmedDescription;
                    }

                    $collapsible = !empty($entry['collapsible']);
                    $collapsed   = $collapsible && !empty($entry['collapsed']);

                    $icon = null;
                    if (isset($entry['icon']) && is_string($entry['icon'])) {
                        $iconCandidate = $this->normalizeCssClassList($entry['icon']);
                        $icon          = $iconCandidate === '' ? null : $iconCandidate;
                    }

                    $cssClass = null;
                    if (isset($entry['class']) && is_string($entry['class'])) {
                        $classCandidate = $this->normalizeCssClassList($entry['class']);
                        $cssClass       = $classCandidate === '' ? null : $classCandidate;
                    }

                    $titleClass = null;
                    if (isset($entry['title_class']) && is_string($entry['title_class'])) {
                        $titleClassCandidate = $this->normalizeCssClassList($entry['title_class']);
                        $titleClass          = $titleClassCandidate === '' ? null : $titleClassCandidate;
                    }

                    $normalizedSections[] = [
                        'id'          => $sectionId,
                        'title'       => $title,
                        'description' => $description,
                        'fields'      => array_values(array_unique($fields)),
                        'collapsible' => $collapsible,
                        'collapsed'   => $collapsed,
                        'icon'        => $icon,
                        'class'       => $cssClass,
                        'title_class' => $titleClass,
                    ];
                }

                if ($normalizedSections !== []) {
                    $sections[$mode] = $normalizedSections;
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
            'change_type'         => [],
            'pass_var'            => [],
            'pass_default'        => [],
            'readonly'            => [],
            'disabled'            => [],
            'visible_if'          => [],
            'editable_if'         => [],
            'validation_required' => [],
            'validation_pattern'  => [],
            'max_length'          => [],
            'unique'              => [],
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

            $modeAwareKeys = ['pass_var', 'pass_default', 'readonly', 'disabled', 'visible_if', 'editable_if', 'validation_required', 'validation_pattern', 'max_length', 'unique'];
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

                if ($label === null) {
                    $fieldLabels[$field] = '';
                    continue;
                }

                if (!is_string($label)) {
                    continue;
                }

                if ($label === '') {
                    $fieldLabels[$field] = '';
                    continue;
                }

                $trimmed = trim($label);
                if ($trimmed === '') {
                    $fieldLabels[$field] = '';
                    continue;
                }

                $fieldLabels[$field] = $trimmed;
            }
        }

        $allColumns = $this->getBaseTableColumns();
        if (isset($this->config['form']['all_columns']) && is_array($this->config['form']['all_columns'])) {
            foreach ($this->config['form']['all_columns'] as $column) {
                $normalized = $this->normalizeColumnReference((string) $column);
                if ($normalized !== '' && !in_array($normalized, $allColumns, true)) {
                    $allColumns[] = $normalized;
                }
            }
        }

        foreach (array_keys($this->config['custom_fields'] ?? []) as $customField) {
            if (is_string($customField) && $customField !== '' && !in_array($customField, $allColumns, true)) {
                $allColumns[] = $customField;
            }
        }

        foreach (array_keys($this->config['custom_columns'] ?? []) as $customColumn) {
            if (is_string($customColumn) && $customColumn !== '' && !in_array($customColumn, $allColumns, true)) {
                $allColumns[] = $customColumn;
            }
        }

        $autoRequired = $this->detectDatabaseRequiredColumns($columnLookup);
        if ($autoRequired !== []) {
            foreach ($autoRequired as $field => $minLength) {
                if (!is_string($field) || $field === '') {
                    continue;
                }

                $value = max(1, (int) $minLength);

                if (!isset($behaviours['validation_required'][$field]) || !is_array($behaviours['validation_required'][$field])) {
                    $behaviours['validation_required'][$field] = ['all' => $value];
                    continue;
                }

                $current = $behaviours['validation_required'][$field];
                if (!isset($current['all'])) {
                    $existing = null;
                    if (isset($current['create']) && is_numeric($current['create'])) {
                        $existing = (int) $current['create'];
                    } elseif (isset($current['edit']) && is_numeric($current['edit'])) {
                        $existing = (int) $current['edit'];
                    }

                    $current['all'] = $existing !== null && $existing > 0 ? $existing : $value;
                }

                $behaviours['validation_required'][$field] = $current;
            }
        }

        return [
            'layouts'      => $layouts,
            'sections'     => $sections,
            'default_tabs' => $defaultTabs,
            'behaviours'   => $behaviours,
            'labels'       => $fieldLabels,
            'all_columns'  => array_values($allColumns),
            'show_primary_key_field' => $this->shouldDisplayPrimaryKeyField(),
        ];
    }

    /**
     * @param array<string, bool> $columnLookup
     * @return array<string, int>
     */
    private function detectDatabaseRequiredColumns(array $columnLookup): array
    {
        if ($columnLookup === []) {
            return [];
        }

        $schema = $this->getTableSchema($this->table);
        if ($schema === []) {
            return [];
        }

        $primaryKey         = $this->normalizeColumnReference($this->getPrimaryKeyColumn());
        $primaryKeyRaw      = $this->denormalizeColumnReference($primaryKey);
        $primaryKeyNameOnly = $primaryKey;
        if (strpos($primaryKey, '__') !== false) {
            $parts              = explode('__', $primaryKey);
            $primaryKeyNameOnly = (string) array_pop($parts);
        }

        $required = [];

        foreach ($schema as $column => $meta) {
            if (!is_string($column) || $column === '') {
                continue;
            }

            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '' || !isset($columnLookup[$normalized])) {
                continue;
            }

            $isPrimaryKey = (
                $normalized === $primaryKey
                || $normalized === $primaryKeyNameOnly
                || $column === $primaryKeyRaw
                || $column === $primaryKeyNameOnly
            );

            if ($isPrimaryKey) {
                if ($this->shouldDisplayPrimaryKeyField() && $this->schemaColumnIsRequired($meta)) {
                    $required[$normalized] = 1;
                }
                continue;
            }

            if ($this->schemaColumnIsRequired($meta)) {
                $required[$normalized] = 1;
            }
        }

        return $required;
    }

    /**
     * @param array<string, mixed> $columnMeta
     */
    private function schemaColumnIsRequired(array $columnMeta): bool
    {
        $meta = $columnMeta['meta'] ?? [];
        $meta = is_array($meta) ? $meta : [];

        if (isset($meta['Null'])) {
            $flag = strtoupper((string) $meta['Null']);
            if ($flag !== 'NO') {
                return false;
            }

            $extra = isset($meta['Extra']) ? strtolower((string) $meta['Extra']) : '';
            if ($extra !== '' && str_contains($extra, 'auto_increment')) {
                return false;
            }

            if (isset($meta['Generated']) && is_string($meta['Generated'])) {
                $generated = strtolower($meta['Generated']);
                if ($generated === 'stored' || $generated === 'always') {
                    return false;
                }
            }

            if (array_key_exists('Default', $meta) && $meta['Default'] !== null) {
                return false;
            }

            return true;
        }

        if (isset($meta['is_nullable'])) {
            $nullable = strtoupper((string) $meta['is_nullable']);
            if ($nullable !== 'NO') {
                return false;
            }

            if (array_key_exists('column_default', $meta) && $meta['column_default'] !== null) {
                return false;
            }

            return true;
        }

        if (isset($meta['notnull'])) {
            if ((int) $meta['notnull'] !== 1) {
                return false;
            }

            if (!empty($meta['pk'])) {
                return false;
            }

            if (array_key_exists('dflt_value', $meta) && $meta['dflt_value'] !== null) {
                return false;
            }

            return true;
        }

        if (isset($meta['flags']) && is_array($meta['flags'])) {
            $flags = array_map(
                static fn($flag) => is_string($flag) ? strtolower($flag) : $flag,
                $meta['flags']
            );

            if (!in_array('not_null', $flags, true)) {
                return false;
            }

            if (in_array('auto_increment', $flags, true) || in_array('primary_key', $flags, true)) {
                return false;
            }

            if (array_key_exists('default', $columnMeta) && $columnMeta['default'] !== null) {
                return false;
            }

            if (array_key_exists('default_value', $columnMeta) && $columnMeta['default_value'] !== null) {
                return false;
            }

            return true;
        }

        return false;
    }

    private function shouldDisplayPrimaryKeyField(): bool
    {
        return !$this->isPrimaryKeyNumeric();
    }

    private function isPrimaryKeyNumeric(): bool
    {
        $primaryKey = $this->getPrimaryKeyColumn();
        $schema     = $this->getTableSchema($this->table);
        if (!isset($schema[$primaryKey]) || !is_array($schema[$primaryKey])) {
            return true;
        }

        $typeInfo = $this->detectSqlTypeInfo($schema[$primaryKey]);

        if ($typeInfo['normalized'] !== '') {
            return $this->isNumericType($typeInfo['normalized']);
        }

        if ($typeInfo['raw'] !== '') {
            return $this->isNumericType($this->normalizeSqlType($typeInfo['raw']));
        }

        return true;
    }

    private function buildSummaries(?string $searchTerm, ?string $searchColumn): array
    {
        if ($this->config['column_summaries'] === []) {
            return [];
        }

        $parameters  = [];
        $whereClause = $this->buildWhereClause($parameters, $searchTerm, $searchColumn);
        $fromClause  = $this->buildFromClause();
        $joins       = $this->buildJoinClauses();

        $baseSql = sprintf('FROM %s', $fromClause);
        if ($joins !== '') {
            $baseSql .= ' ' . $joins;
        }
        if ($whereClause !== '') {
            $baseSql .= ' WHERE ' . $whereClause;
        }

        $summaries = [];
        $expressions = [];

        foreach ($this->config['column_summaries'] as $entry) {
            if (!is_array($entry) || !isset($entry['column'], $entry['type'])) {
                continue;
            }

            $column = (string) $entry['column'];
            $type   = strtolower((string) $entry['type']);

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

            $expressions[] = sprintf('%s(%s)', strtoupper($type), $columnExpression);
            $summaries[] = [
                'column' => $column,
                'type'   => $type,
                'label'  => $label,
                'precision' => $precision,
            ];
        }

        if ($expressions === []) {
            return [];
        }
        $values = null;
        try {
            $statement = $this->connection->prepare('SELECT ' . implode(', ', $expressions) . ' ' . $baseSql);
            if ($statement !== false && $statement->execute($parameters)) {
                $values = $statement->fetch(PDO::FETCH_NUM);
            }
        } catch (PDOException) {
            // Preserve valid summaries even when another configured expression is invalid.
        }
        $result = [];
        foreach ($summaries as $index => $summary) {
            if (is_array($values)) {
                $value = $values[$index] ?? null;
            } else {
                try {
                    $statement = $this->connection->prepare('SELECT ' . $expressions[$index] . ' ' . $baseSql);
                    if ($statement === false || !$statement->execute($parameters)) {
                        continue;
                    }
                    $value = $statement->fetchColumn();
                    $value = $value === false ? null : $value;
                } catch (PDOException) {
                    continue;
                }
            }
            if ($summary['precision'] !== null && $value !== null && is_numeric($value)) {
                $value = number_format((float) $value, $summary['precision'], '.', '');
            }
            unset($summary['precision']);
            $summary['value'] = $value;
            $result[] = $summary;
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNestedTablesClientConfigPayload(): array
    {
        if ($this->nestedTables === []) {
            return [];
        }

        $payload = [];

        foreach ($this->nestedTables as $entry) {
            if (!is_array($entry) || !isset($entry['crud']) || !$entry['crud'] instanceof self) {
                continue;
            }

            /** @var self $child */
            $child          = $entry['crud'];
            $childConfig    = $child->buildClientConfigPayload();
            $childConfigKey = $child->storeClientConfigPayloadInSession($childConfig);
            $item           = [
                'name'              => $entry['name'],
                'parent_column'     => $entry['parent_column'],
                'parent_column_raw' => $entry['parent_column_raw'],
                'foreign_column'    => $entry['foreign_column'],
                'table'             => $child->getTable(),
                'label'             => $child->getConfiguredTableTitle(),
            ];

            if ($childConfigKey !== null) {
                $item['config_key'] = $childConfigKey;
            } else {
                $item['config'] = $childConfig;
            }

            $payload[] = $item;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClientBootstrapPayload(): array
    {
        return [
            'primary_key'     => $this->getPrimaryKeyColumn(),
            'select2'         => (bool) ($this->config['select2'] ?? false),
            'filters_enabled' => (bool) ($this->config['filters_enabled'] ?? true),
            'numbers_enabled' => (bool) ($this->config['numbers_enabled'] ?? false),
            'compact_pagination' => (bool) ($this->config['compact_pagination'] ?? false),
            'hide_search'     => (bool) ($this->config['hide_search'] ?? false),
            'form_display_mode' => $this->normalizeFormDisplayMode((string) ($this->config['form_display_mode'] ?? self::DEFAULT_FORM_DISPLAY_MODE)),
            'rich_editor'     => [
                'upload_path' => CrudConfig::getUploadServePath(),
            ],
            'upload_limits'   => $this->buildUploadLimitsClientPayload(),
            'ui_text'         => $this->config['ui_text'],
            'style_overrides' => $this->config['style_overrides'],
            'debug'           => (bool) CrudConfig::$debug,
        ];
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    private function buildUploadLimitsClientPayload(): array
    {
        return [
            'image' => $this->getUploadLimitDetails(true),
            'file'  => $this->getUploadLimitDetails(false),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function getUploadLimitDetails(bool $isImage): array
    {
        $appLimit = $isImage ? CrudConfig::getUploadMaxImageSize() : CrudConfig::getUploadMaxFileSize();
        $limits = [
            'app' => $appLimit,
        ];

        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $raw = ini_get($key);
            if (is_string($raw)) {
                $parsed = CrudConfig::parseUploadSize($raw);
                if ($parsed !== null && $parsed > 0) {
                    $limits['php_' . $key] = $parsed;
                }
            }
        }

        $source = 'app';
        $effective = $appLimit;
        foreach ($limits as $key => $limit) {
            if ($limit < $effective) {
                $effective = $limit;
                $source = $key;
            }
        }

        return [
            'app'                   => $appLimit,
            'php_upload_max_filesize' => $limits['php_upload_max_filesize'] ?? null,
            'php_post_max_size'     => $limits['php_post_max_size'] ?? null,
            'effective'             => $effective,
            'source'                => $source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClientConfigPayload(?array $columns = null): array
    {
        $this->applyRowOrderingSort();
        $this->ensureFormLayoutBuckets();
        $this->ensureFormBehaviourBuckets();
        $this->ensureDefaultTabBuckets();

        $columns    = $columns ?? $this->getColumnNames();
        $allColumns       = $this->getBaseTableColumns();
        $allColumnsLookup = array_fill_keys($allColumns, true);
        $formConfig       = $this->config['form'];

        foreach (array_keys($this->config['custom_columns']) as $customColumn) {
            if (is_string($customColumn) && $customColumn !== '' && !isset($allColumnsLookup[$customColumn])) {
                $allColumns[]                    = $customColumn;
                $allColumnsLookup[$customColumn] = true;
            }
        }

        foreach (array_keys($this->config['custom_fields']) as $customField) {
            if (is_string($customField) && $customField !== '' && !isset($allColumnsLookup[$customField])) {
                $allColumns[]                   = $customField;
                $allColumnsLookup[$customField] = true;
            }
        }

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
                        if ($normalized === '' || isset($allColumnsLookup[$normalized])) {
                            continue;
                        }

                        $allColumns[]                  = $normalized;
                        $allColumnsLookup[$normalized] = true;
                    }
                }
            }
        }

        $formConfig['all_columns']           = $allColumns;
        $this->config['form']['all_columns'] = $allColumns;

        $inline = array_values(array_keys(array_filter($this->config['inline_edit'] ?? [], static fn($v) => (bool) $v)));

        $sortDisabled = array_values(
            array_filter(
                $this->config['sort_disabled'],
                static fn($col): bool => is_string($col) && $col !== ''
            )
        );

        foreach (array_keys($this->config['custom_columns']) as $customColumn) {
            if (!is_string($customColumn) || $customColumn === '') {
                continue;
            }

            $normalized = $this->normalizeColumnReference($customColumn);
            if ($normalized === '') {
                continue;
            }

            if (!in_array($normalized, $sortDisabled, true)) {
                $sortDisabled[] = $normalized;
            }
        }

        foreach (array_keys($this->config['custom_fields']) as $customField) {
            if (!is_string($customField) || $customField === '') {
                continue;
            }

            $normalized = $this->normalizeColumnReference($customField);
            if ($normalized === '') {
                continue;
            }

            if (!in_array($normalized, $sortDisabled, true)) {
                $sortDisabled[] = $normalized;
            }
        }

        $batchDeleteConfigured                             = isset($this->config['table_meta']['batch_delete'])
            ? (bool) $this->config['table_meta']['batch_delete']
            : false;
        $this->config['table_meta']['batch_delete_button'] = $batchDeleteConfigured;
        $this->getNormalizedToolbarActionsConfig();
        $this->getNormalizedToolbarHtmlConfig();

        $formMeta = $this->buildFormMeta($columns);
        if (!isset($formMeta['all_columns']) || !is_array($formMeta['all_columns'])) {
            $formMeta['all_columns'] = $allColumns;
        }

        $templates = $this->buildFormTemplates($formMeta['all_columns']);
        if ($templates !== []) {
            $formMeta['templates'] = $templates;
        }

        return [
            'per_page'                => $this->perPage,
            'where'                   => $this->config['where'],
            'order_by'                => $this->config['order_by'],
            'no_quotes'               => $this->config['no_quotes'],
            'limit_options'           => $this->config['limit_options'],
            'limit_default'           => $this->config['limit_default'],
            'compact_pagination'      => (bool) ($this->config['compact_pagination'] ?? false),
            'search_columns'          => $this->config['search_columns'],
            'search_default'          => $this->config['search_default'],
            'hide_search'             => (bool) ($this->config['hide_search'] ?? false),
            'joins'                   => $this->config['joins'],
            'relations'               => $this->config['relations'],
            'custom_query'            => $this->config['custom_query'],
            'subselects'              => $this->config['subselects'],
            'visible_columns'         => $this->config['visible_columns'],
            'column_visibility_rules' => $this->config['column_visibility_rules'],
            'audit_log'               => $this->config['audit_log'],
            'columns_reverse'         => $this->config['columns_reverse'],
            'column_labels'           => $this->config['column_labels'],
            'column_patterns'         => $this->config['column_patterns'],
            'column_formatters'       => $this->config['column_formatters'],
            'column_callbacks'        => $this->config['column_callbacks'],
            'column_tooltips'         => $this->config['column_tooltips'],
            'custom_columns'          => $this->config['custom_columns'],
            'field_callbacks'         => $this->config['field_callbacks'],
            'lifecycle_callbacks'     => $this->config['lifecycle_callbacks'],
            'custom_fields'           => $this->config['custom_fields'],
            'sort_disabled'           => $sortDisabled,
            'column_classes'          => $this->config['column_classes'],
            'column_widths'           => $this->config['column_widths'],
            'column_cuts'             => $this->config['column_cuts'],
            'default_column_truncate' => $this->config['default_column_truncate'],
            'column_highlights'       => $this->config['column_highlights'],
            'row_highlights'          => $this->config['row_highlights'],
            'link_buttons'            => $this->config['link_buttons'],
            'multi_link_buttons'      => $this->config['multi_link_buttons'],
            'action_button_sequence'  => $this->getActionButtonSequence(),
            'table_meta'              => $this->config['table_meta'],
            'column_summaries'        => $this->config['column_summaries'],
            'ui_text'                 => $this->config['ui_text'],
            'style_overrides'         => $this->config['style_overrides'],
            'field_labels'            => $this->config['field_labels'],
            'form_display_mode'       => $this->normalizeFormDisplayMode((string) ($this->config['form_display_mode'] ?? self::DEFAULT_FORM_DISPLAY_MODE)),
            'primary_key'             => $this->primaryKeyColumn,
            'soft_delete'             => $this->config['soft_delete'],
            'row_ordering'            => $this->config['row_ordering'],
            'form'                    => $formMeta,
            'inline_edit'             => $inline,
            'nested_tables'           => $this->buildNestedTablesClientConfigPayload(),
            'rich_editor'             => [
                'upload_path' => CrudConfig::getUploadServePath(),
            ],
            'upload_limits'           => $this->buildUploadLimitsClientPayload(),
            'select2'                 => (bool) ($this->config['select2'] ?? false),
            'debug'                   => (bool) CrudConfig::$debug,
            'filters_enabled'         => (bool) ($this->config['filters_enabled'] ?? true),
            'numbers_enabled'         => (bool) ($this->config['numbers_enabled'] ?? false),
            'query_builder'           => $this->buildQueryBuilderClientPayload(),
        ];
    }

    /**
     * Build a stable namespace for storing client-side saved views.
     *
     * @param array<string, mixed> $clientConfig
     */
    private function buildViewStorageKey(array $clientConfig): string
    {
        $tableKey = $this->table;
        if (isset($clientConfig['table']['key']) && is_string($clientConfig['table']['key']) && $clientConfig['table']['key'] !== '') {
            $tableKey = $clientConfig['table']['key'];
        }

        $hashSource = [
            'table'           => $tableKey,
            'columns'         => $clientConfig['columns'] ?? [],
            'visible_columns' => $clientConfig['visible_columns'] ?? null,
            'joins'           => $clientConfig['joins'] ?? [],
            'relations'       => $clientConfig['relations'] ?? [],
        ];

        $hash = substr(sha1($tableKey), 0, 12);
        try {
            $hash = substr(sha1(json_encode($hashSource, JSON_THROW_ON_ERROR)), 0, 12);
        } catch (JsonException) {
            // Ignore and keep fallback hash.
        }

        return $tableKey . ':' . $hash;
    }

    private function buildQueryBuilderClientPayload(): array
    {
        $state = $this->config['query_builder'] ?? [];
        $logic = isset($state['logic']) && strtoupper((string) $state['logic']) === 'OR' ? 'OR' : 'AND';

        $filters = [];
        if (isset($state['filters']) && is_array($state['filters'])) {
            foreach ($state['filters'] as $filter) {
                if (!is_array($filter) || !isset($filter['field'], $filter['operator'])) {
                    continue;
                }

                $filters[] = [
                    'field'    => (string) $filter['field'],
                    'operator' => (string) $filter['operator'],
                    'value'    => $filter['value'] ?? null,
                    'type'     => $filter['type'] ?? null,
                ];
            }
        }

        $sorts = [];
        if (isset($state['sorts']) && is_array($state['sorts']) && $state['sorts'] !== []) {
            foreach ($state['sorts'] as $sort) {
                if (!is_array($sort) || !isset($sort['field'])) {
                    continue;
                }

                $field = $this->normalizeColumnReference((string) $sort['field']);
                if ($field === '') {
                    continue;
                }

                $direction = isset($sort['direction']) && strtoupper((string) $sort['direction']) === 'DESC'
                    ? 'DESC'
                    : 'ASC';

                $sorts[] = [
                    'field'     => $field,
                    'direction' => $direction,
                ];
            }
        } else {
            foreach ($this->config['order_by'] as $sort) {
                if (!is_array($sort) || !isset($sort['field'])) {
                    continue;
                }

                $field = $this->normalizeColumnReference((string) $sort['field']);
                if ($field === '') {
                    continue;
                }

                $direction = isset($sort['direction']) && strtoupper((string) $sort['direction']) === 'DESC'
                    ? 'DESC'
                    : 'ASC';

                $sorts[] = [
                    'field'     => $field,
                    'direction' => $direction,
                ];
            }
        }

        $fields = [];
        foreach ($this->getQueryBuilderFieldMap() as $field) {
            $entry = $field;
            unset($entry['sql']);
            $fields[] = $entry;
        }

        $activeView = null;
        if (isset($state['active_view']) && is_string($state['active_view'])) {
            $trimmed = trim($state['active_view']);
            if ($trimmed !== '') {
                $activeView = $trimmed;
            }
        }

        return [
            'logic'       => $logic,
            'filters'     => $filters,
            'sorts'       => $sorts,
            'fields'      => $fields,
            'operators'   => $this->getQueryBuilderOperators(),
            'active_view' => $activeView,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getQueryBuilderOperators(): array
    {
        $operators = [];

        foreach (self::QUERY_BUILDER_OPERATOR_CONFIG as $operator => $config) {
            $label = isset($config['label']) && is_string($config['label'])
                ? $config['label']
                : ucwords(str_replace('_', ' ', $operator));

            $operators[] = [
                'value'          => $operator,
                'label'          => $label,
                'requires_value' => (bool) ($config['requires_value'] ?? true),
                'multi'          => (bool) ($config['multi'] ?? false),
            ];
        }

        return $operators;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getQueryBuilderFieldMap(): array
    {
        if ($this->queryBuilderFieldCache !== null) {
            return $this->queryBuilderFieldCache;
        }

        $fields = [];

        $mainSchema = $this->getTableSchema($this->table);
        foreach ($this->getBaseTableColumns() as $column) {
            if (!is_string($column) || $column === '') {
                continue;
            }

            $normalized = $this->normalizeColumnReference($column);
            if ($normalized === '') {
                continue;
            }

            $columnMeta = $mainSchema[$column] ?? [];
            if (!$this->isQueryBuilderFilterable($columnMeta)) {
                continue;
            }

            $fields[$normalized] = $this->makeQueryBuilderFieldEntry(
                $normalized,
                'main.' . $column,
                $columnMeta
            );
        }

        foreach ($this->config['joins'] as $index => $join) {
            if (!is_array($join) || !isset($join['table'])) {
                continue;
            }

            $joinTable = trim((string) $join['table']);
            if ($joinTable === '') {
                continue;
            }

            $alias = isset($join['alias']) && is_string($join['alias']) && trim($join['alias']) !== ''
                ? trim((string) $join['alias'])
                : ('j' . $index);

            $joinColumns = $this->getTableColumnsFor($joinTable);
            $joinSchema  = $this->getTableSchema($joinTable);

            foreach ($joinColumns as $column) {
                if (!is_string($column) || $column === '') {
                    continue;
                }

                $normalized = $this->normalizeColumnReference($alias . '__' . $column);
                if ($normalized === '') {
                    continue;
                }

                $columnMeta = $joinSchema[$column] ?? [];
                if (!$this->isQueryBuilderFilterable($columnMeta)) {
                    continue;
                }

                $fields[$normalized] = $this->makeQueryBuilderFieldEntry(
                    $normalized,
                    $alias . '.' . $column,
                    $columnMeta
                );
            }
        }

        foreach ($this->config['relations'] as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            $field = isset($relation['field']) ? $this->normalizeColumnReference((string) $relation['field']) : '';
            if ($field === '' || !isset($fields[$field])) {
                continue;
            }

            $options = $this->fetchRelationOptions($relation);
            if ($options === []) {
                continue;
            }

            $fields[$field]['options'] = $options;
        }

        $this->queryBuilderFieldCache = $fields;

        return $fields;
    }

    /**
     * @param array<string, mixed> $columnMeta
     * @return array<string, mixed>
     */
    private function makeQueryBuilderFieldEntry(string $fieldKey, string $qualifiedName, array $columnMeta): array
    {
        $typeInfo       = $this->detectSqlTypeInfo($columnMeta);
        $type           = $this->determineQueryBuilderFieldType($columnMeta);
        $options        = $this->extractEnumValues($columnMeta);
        $label          = $this->resolveColumnLabel($fieldKey);
        $normalizedType = $typeInfo['normalized'] !== '' ? $typeInfo['normalized'] : $typeInfo['raw'];
        $isJsonField    = $this->isJsonColumnType($normalizedType);

        return [
            'id'       => $fieldKey,
            'field'    => $fieldKey,
            'label'    => $label,
            'type'     => $type,
            'nullable' => $this->queryBuilderColumnIsNullable($columnMeta),
            'options'  => $options,
            'sql'      => $this->quoteQualifiedIdentifier($qualifiedName),
            'is_json'  => $isJsonField,
        ];
    }

    /**
     * @param array<string, mixed> $columnMeta
     */
    private function determineQueryBuilderFieldType(array $columnMeta): string
    {
        $typeInfo   = $this->detectSqlTypeInfo($columnMeta);
        $normalized = $typeInfo['normalized'];

        if ($normalized === '') {
            if (isset($columnMeta['type']) && is_string($columnMeta['type'])) {
                $normalized = strtolower(trim((string) $columnMeta['type']));
            }
        }

        if ($normalized === '') {
            return 'string';
        }

        if ($this->isNumericType($normalized)) {
            return 'number';
        }

        if (str_contains($normalized, 'bool')) {
            return 'boolean';
        }

        if (str_contains($normalized, 'enum')) {
            return 'enum';
        }

        if (str_contains($normalized, 'timestamp') || str_contains($normalized, 'datetime')) {
            return 'datetime';
        }

        if (str_contains($normalized, 'date')) {
            if (!str_contains($normalized, 'time')) {
                return 'date';
            }

            return 'datetime';
        }

        if (str_contains($normalized, 'time')) {
            return 'time';
        }

        return 'string';
    }

    /**
     * @param array<string, mixed> $columnMeta
     */
    private function isQueryBuilderFilterable(array $columnMeta): bool
    {
        $typeInfo   = $this->detectSqlTypeInfo($columnMeta);
        $normalized = $typeInfo['normalized'];

        if ($normalized === '') {
            if (isset($columnMeta['type']) && is_string($columnMeta['type'])) {
                $normalized = strtolower(trim((string) $columnMeta['type']));
            }
        }

        if ($normalized === '') {
            return true;
        }

        $token = strtolower($normalized);
        $token = preg_replace('/\(.*\)/', '', $token) ?? $token;
        $token = trim($token);

        $blocked = [
            'json',
            'jsonb',
            'blob',
            'tinyblob',
            'mediumblob',
            'longblob',
            'binary',
            'varbinary',
            'bit',
            'geometry',
            'point',
            'linestring',
            'polygon',
            'multipoint',
            'multilinestring',
            'multipolygon',
            'geometrycollection',
            'bytea',
        ];

        if ($this->supportsJsonSearch()) {
            $blocked = array_values(array_diff($blocked, ['json', 'jsonb']));
        }

        return !in_array($token, $blocked, true);
    }

    /**
     * @param array<string, mixed> $columnMeta
     */
    private function queryBuilderColumnIsNullable(array $columnMeta): bool
    {
        $meta = $columnMeta['meta'] ?? null;
        if (is_array($meta)) {
            if (array_key_exists('Null', $meta)) {
                return strtoupper((string) $meta['Null']) !== 'NO';
            }

            if (array_key_exists('IS_NULLABLE', $meta)) {
                return strtoupper((string) $meta['IS_NULLABLE']) !== 'NO';
            }

            if (array_key_exists('is_nullable', $meta)) {
                $value = $meta['is_nullable'];
                if (is_string($value)) {
                    return strtoupper($value) !== 'NO';
                }

                if (is_bool($value)) {
                    return $value;
                }

                if (is_int($value)) {
                    return $value !== 0;
                }
            }
        }

        if (isset($columnMeta['is_nullable'])) {
            $value = $columnMeta['is_nullable'];
            if (is_string($value)) {
                return strtoupper($value) !== 'NO';
            }

            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value)) {
                return $value !== 0;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $orderBy
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeOrderByEntries(array $orderBy): array
    {
        $sanitized = [];

        foreach ($orderBy as $entry) {
            if (!is_array($entry) || !isset($entry['field'])) {
                continue;
            }

            $field = $this->normalizeColumnReference((string) $entry['field']);
            if ($field === '') {
                continue;
            }

            $direction = isset($entry['direction']) && strtoupper((string) $entry['direction']) === 'DESC'
                ? 'DESC'
                : 'ASC';

            $sanitized[] = [
                'field'     => $field,
                'direction' => $direction,
            ];
        }

        return $sanitized;
    }

    private function normalizeQueryBuilderValue(mixed $value, string $fieldType): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        switch ($fieldType) {
            case 'number':
                if ($value === '' || (!is_numeric($value) && !is_bool($value))) {
                    return null;
                }

                return $value + 0;

            case 'boolean':
                if (is_bool($value)) {
                    return $value ? 1 : 0;
                }

                if (is_numeric($value)) {
                    return ((int) $value) ? 1 : 0;
                }

                if (is_string($value)) {
                    $lower = strtolower($value);
                    if (in_array($lower, ['1', 'true', 'yes', 'y', 'on'], true)) {
                        return 1;
                    }

                    if (in_array($lower, ['0', 'false', 'no', 'n', 'off'], true)) {
                        return 0;
                    }
                }

                return null;

            case 'date':
            case 'datetime':
            case 'time':
            case 'enum':
            default:
                if ($value === '') {
                    return null;
                }

                if (is_scalar($value)) {
                    return (string) $value;
                }

                return null;
        }
    }

    private function sanitizeQueryBuilderFilter(mixed $filter): ?array
    {
        if (!is_array($filter)) {
            return null;
        }

        if (!isset($filter['field']) || !is_string($filter['field'])) {
            return null;
        }

        $fieldKey = $this->normalizeColumnReference((string) $filter['field']);
        if ($fieldKey === '') {
            return null;
        }

        $fieldMap = $this->getQueryBuilderFieldMap();
        if (!isset($fieldMap[$fieldKey])) {
            return null;
        }

        $operator = isset($filter['operator']) ? strtolower((string) $filter['operator']) : '';
        if (!in_array($operator, self::SUPPORTED_CONDITION_OPERATORS, true)) {
            return null;
        }

        $operatorConfig = self::QUERY_BUILDER_OPERATOR_CONFIG[$operator] ?? null;
        if ($operatorConfig === null) {
            return null;
        }

        $fieldMeta = $fieldMap[$fieldKey];
        $fieldType = isset($fieldMeta['type']) && is_string($fieldMeta['type']) ? $fieldMeta['type'] : 'string';

        $requiresValue = (bool) ($operatorConfig['requires_value'] ?? true);
        $isMulti       = (bool) ($operatorConfig['multi'] ?? false);

        if ($requiresValue) {
            $rawValue = $filter['value'] ?? null;

            if ($isMulti) {
                if (!is_array($rawValue)) {
                    $rawValue = $rawValue === null ? [] : [$rawValue];
                }

                $normalized = [];
                foreach ($rawValue as $item) {
                    $candidate = $this->normalizeQueryBuilderValue($item, $fieldType);
                    if ($candidate === null) {
                        continue;
                    }
                    $normalized[] = $candidate;
                }

                if ($normalized === []) {
                    return null;
                }

                $value = $normalized;
            } else {
                $value = $this->normalizeQueryBuilderValue($rawValue, $fieldType);
                if ($value === null) {
                    return null;
                }
            }
        } else {
            $value = null;
        }

        return [
            'field'    => $fieldKey,
            'operator' => $operator,
            'value'    => $value,
            'type'     => $fieldType,
            'sql'      => $fieldMeta['sql'],
            'nullable' => $fieldMeta['nullable'] ?? true,
            'is_json'  => (bool) ($fieldMeta['is_json'] ?? false),
        ];
    }

    private function applyQueryBuilderPayload(mixed $payload): void
    {
        $state = [
            'filters'     => [],
            'logic'       => 'AND',
            'sorts'       => $this->config['order_by'],
            'active_view' => null,
        ];

        if (!is_array($payload)) {
            $this->config['query_builder'] = $state;

            return;
        }

        if (isset($payload['logic']) && strtoupper((string) $payload['logic']) === 'OR') {
            $state['logic'] = 'OR';
        }

        if (isset($payload['active_view']) && is_string($payload['active_view'])) {
            $trimmed = trim($payload['active_view']);
            if ($trimmed !== '') {
                $state['active_view'] = $trimmed;
            }
        }

        if (isset($payload['filters']) && is_array($payload['filters'])) {
            $filters = [];
            foreach ($payload['filters'] as $filter) {
                $sanitized = $this->sanitizeQueryBuilderFilter($filter);
                if ($sanitized !== null) {
                    $filters[] = $sanitized;
                }
            }

            if ($filters !== []) {
                $state['filters'] = $filters;
            }
        }

        if (isset($payload['sorts']) && is_array($payload['sorts'])) {
            $sorts = $this->sanitizeOrderByEntries($payload['sorts']);
            if ($sorts !== []) {
                $state['sorts']           = $sorts;
                $this->config['order_by'] = $sorts;
            }
        } else {
            $state['sorts'] = $this->config['order_by'];
        }

        $this->config['query_builder'] = $state;
    }

    private function buildQueryBuilderFilterClause(array $filter, array &$parameters, int &$placeholderCounter): string
    {
        $column = isset($filter['sql']) && is_string($filter['sql']) ? $filter['sql'] : '';
        if ($column === '') {
            return '';
        }

        $operator = isset($filter['operator']) ? strtolower((string) $filter['operator']) : '';
        if ($operator === '') {
            return '';
        }

        $type        = isset($filter['type']) && is_string($filter['type']) ? $filter['type'] : 'string';
        $isJsonField = isset($filter['is_json']) ? (bool) $filter['is_json'] : false;

        $basePlaceholder = ':qb_' . $placeholderCounter;
        $placeholderCounter++;

        switch ($operator) {
            case 'equals':
            case 'not_equals':
            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                $value = $filter['value'] ?? null;
                if ($value === null) {
                    return '';
                }

                if (is_string($value) && $value === '') {
                    return '';
                }

                $map = [
                    'equals'     => '=',
                    'not_equals' => '<>',
                    'gt'         => '>',
                    'gte'        => '>=',
                    'lt'         => '<',
                    'lte'        => '<=',
                ];

                $parameters[$basePlaceholder] = $value;

                return sprintf('%s %s %s', $column, $map[$operator], $basePlaceholder);

            case 'contains':
            case 'not_contains':
                $value = $filter['value'] ?? null;
                if ($value === null) {
                    return '';
                }

                $stringValue = (string) $value;
                if ($stringValue === '') {
                    return '';
                }

                $parameters[$basePlaceholder] = '%' . $stringValue . '%';

                if ($isJsonField && $this->supportsJsonSearch()) {
                    $negate = $operator === 'not_contains';

                    return $this->buildJsonSearchCondition($column, $basePlaceholder, $negate);
                }

                $keyword = $operator === 'contains' ? 'LIKE' : 'NOT LIKE';

                return sprintf('%s %s %s', $column, $keyword, $basePlaceholder);

            case 'in':
            case 'not_in':
                $values = is_array($filter['value']) ? $filter['value'] : [];
                if ($values === []) {
                    return '';
                }

                $placeholders = [];
                foreach ($values as $index => $value) {
                    if ($value === null) {
                        continue;
                    }

                    if (is_string($value) && $value === '') {
                        continue;
                    }

                    $placeholder              = $basePlaceholder . '_' . $index;
                    $parameters[$placeholder] = $value;
                    $placeholders[]           = $placeholder;
                }

                if ($placeholders === []) {
                    return '';
                }

                $keyword = $operator === 'in' ? 'IN' : 'NOT IN';

                return sprintf('%s %s (%s)', $column, $keyword, implode(', ', $placeholders));

            case 'empty':
                if ($type === 'number' || $type === 'boolean') {
                    return sprintf('%s IS NULL', $column);
                }

                return sprintf("(%s IS NULL OR %s = '')", $column, $column);

            case 'not_empty':
                if ($type === 'number' || $type === 'boolean') {
                    return sprintf('%s IS NOT NULL', $column);
                }

                return sprintf("(%s IS NOT NULL AND %s <> '')", $column, $column);
        }

        return '';
    }


    /**
     * @param array<string, mixed> $payload
     */
    private function applyClientConfig(array $payload): void
    {
        $this->queryBuilderFieldCache = null;

        if (isset($payload['primary_key']) && is_string($payload['primary_key']) && trim($payload['primary_key']) !== '') {
            $this->primary_key($payload['primary_key']);
        }

        if (isset($payload['per_page'])) {
            $perPageCandidate = (int) $payload['per_page'];
            if ($perPageCandidate > 0) {
                $this->perPage                 = $perPageCandidate;
                $this->config['limit_default'] = $perPageCandidate;
            } elseif ($perPageCandidate === 0) {
                $this->perPage                 = 0;
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
            'column_visibility_rules',
            'column_labels',
            'column_patterns',
            'column_formatters',
            'column_callbacks',
            'column_tooltips',
            'column_classes',
            'column_widths',
            'column_cuts',
            'column_highlights',
            'row_highlights',
            'column_summaries',
            'ui_text',
            'style_overrides',
        ];
        foreach ($arrayKeys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $this->config[$key] = $payload[$key];
            }
        }

        if (isset($payload['inline_edit'])) {
            $fields = $this->normalizeList($payload['inline_edit']);
            $map    = [];
            foreach ($fields as $field) {
                $normalized = $this->normalizeColumnReference($field);
                if ($normalized !== '') {
                    $map[$normalized] = true;
                }
            }
            $this->config['inline_edit'] = $map;
        }

        if (isset($payload['limit_options']) && is_array($payload['limit_options'])) {
            $this->config['limit_options'] = array_values($payload['limit_options']);
        }

        if (isset($payload['limit_default']) && is_numeric($payload['limit_default'])) {
            $this->config['limit_default'] = (int) $payload['limit_default'];
        }

        if (array_key_exists('compact_pagination', $payload)) {
            $this->config['compact_pagination'] = (bool) $payload['compact_pagination'];
        }

        if (isset($payload['search_columns'])) {
            $this->config['search_columns'] = $this->normalizeList($payload['search_columns']);
        }

        if (array_key_exists('search_default', $payload)) {
            $default                        = $payload['search_default'];
            $this->config['search_default'] = is_string($default) && $default !== '' ? $default : null;
        }

        if (array_key_exists('hide_search', $payload)) {
            $this->config['hide_search'] = (bool) $payload['hide_search'];
        }

        if (isset($payload['form_display_mode']) && is_string($payload['form_display_mode'])) {
            $this->config['form_display_mode'] = $this->normalizeFormDisplayMode($payload['form_display_mode']);
        }

        if (isset($payload['custom_query']) && is_string($payload['custom_query']) && trim($payload['custom_query']) !== '') {
            $this->config['custom_query'] = $payload['custom_query'];
        }

        if (isset($payload['subselects']) && is_array($payload['subselects'])) {
            $this->config['subselects'] = $payload['subselects'];
        }

        if (isset($payload['visible_columns'])) {
            $columns    = $this->normalizeList($payload['visible_columns']);
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

        if (isset($payload['select2'])) {
            $this->config['select2'] = (bool) $payload['select2'];
        }

        if (array_key_exists('numbers_enabled', $payload)) {
            $this->config['numbers_enabled'] = (bool) $payload['numbers_enabled'];
        }

        if (isset($payload['table_meta']) && is_array($payload['table_meta'])) {
            $meta  = $payload['table_meta'];
            $title = null;
            if (isset($meta['title']) && is_string($meta['title'])) {
                $title = $meta['title'];
            } elseif (isset($meta['name']) && is_string($meta['name'])) {
                $title = $meta['name'];
            }

            $this->config['table_meta'] = [
                'title'               => $title,
                'tooltip'             => isset($meta['tooltip']) && is_string($meta['tooltip']) ? $meta['tooltip'] : null,
                'icon'                => isset($meta['icon']) && is_string($meta['icon']) ? $meta['icon'] : null,
                'hide_title'          => isset($meta['hide_title'])
                    ? (bool) $meta['hide_title']
                    : CrudConfig::$hide_table_title,
                'add'                 => isset($meta['add']) ? (bool) $meta['add'] : true,
                'view'                => isset($meta['view']) ? (bool) $meta['view'] : true,
                'view_condition'      => isset($meta['view_condition']) && is_array($meta['view_condition'])
                    ? $meta['view_condition']
                    : null,
                'edit'                => isset($meta['edit']) ? (bool) $meta['edit'] : true,
                'edit_condition'      => isset($meta['edit_condition']) && is_array($meta['edit_condition'])
                    ? $meta['edit_condition']
                    : null,
                'delete'              => isset($meta['delete']) ? (bool) $meta['delete'] : true,
                'delete_condition'    => isset($meta['delete_condition']) && is_array($meta['delete_condition'])
                    ? $meta['delete_condition']
                    : null,
                'duplicate'           => isset($meta['duplicate']) ? (bool) $meta['duplicate'] : false,
                'duplicate_condition' => isset($meta['duplicate_condition']) && is_array($meta['duplicate_condition'])
                    ? $meta['duplicate_condition']
                    : null,
                'batch_delete'        => isset($meta['batch_delete']) ? (bool) $meta['batch_delete'] : false,
                'batch_delete_button' => isset($meta['batch_delete']) ? (bool) $meta['batch_delete'] : false,
                'delete_confirm'      => isset($meta['delete_confirm']) ? (bool) $meta['delete_confirm'] : true,
                'export_csv'          => isset($meta['export_csv']) ? (bool) $meta['export_csv'] : false,
                'bulk_actions'        => [],
                'toolbar_actions'     => [],
                'toolbar_html'        => [],
            ];

            if (isset($meta['bulk_actions']) && is_array($meta['bulk_actions'])) {
                $bulkActions = [];
                foreach ($meta['bulk_actions'] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $name  = isset($entry['name']) ? (string) $entry['name'] : '';
                    $label = isset($entry['label']) ? (string) $entry['label'] : $name;

                    $options = $entry;
                    unset($options['name'], $options['label']);

                    $bulkActions[] = $this->normalizeBulkActionDefinition($name, $label, $options);
                }

                $this->config['table_meta']['bulk_actions'] = $bulkActions;
            }

            if (isset($meta['toolbar_actions']) && is_array($meta['toolbar_actions'])) {
                $this->config['table_meta']['toolbar_actions'] = $this->normalizeLinkButtonConfigList($meta['toolbar_actions']);
            }

            if (array_key_exists('toolbar_html', $meta)) {
                $this->config['table_meta']['toolbar_html'] = $this->normalizeToolbarHtmlList($meta['toolbar_html']);
            }
        }

        if (array_key_exists('toolbar_actions', $payload)) {
            $this->config['table_meta']['toolbar_actions'] = $this->normalizeLinkButtonConfigList($payload['toolbar_actions']);
        } elseif (array_key_exists('toolbar_action', $payload)) {
            $toolbarAction = $payload['toolbar_action'];
            if (is_array($toolbarAction)) {
                $normalizedToolbarAction                       = $this->normalizeLinkButtonConfigPayload($toolbarAction);
                $this->config['table_meta']['toolbar_actions'] = $normalizedToolbarAction !== null ? [$normalizedToolbarAction] : [];
            } elseif ($toolbarAction === null) {
                $this->config['table_meta']['toolbar_actions'] = [];
            }
        }

        if (array_key_exists('toolbar_html', $payload)) {
            $this->config['table_meta']['toolbar_html'] = $this->normalizeToolbarHtmlList($payload['toolbar_html']);
        } elseif (array_key_exists('toolbar_custom_html', $payload)) {
            $toolbarHtml = $payload['toolbar_custom_html'];
            if (is_string($toolbarHtml)) {
                $normalizedToolbarHtml                     = $this->normalizeToolbarHtmlConfigPayload(['html' => $toolbarHtml]);
                $this->config['table_meta']['toolbar_html'] = $normalizedToolbarHtml !== null ? [$normalizedToolbarHtml] : [];
            } elseif ($toolbarHtml === null) {
                $this->config['table_meta']['toolbar_html'] = [];
            }
        }

        if (array_key_exists('link_buttons', $payload)) {
            $this->config['link_buttons'] = $this->normalizeLinkButtonConfigList($payload['link_buttons']);
        } elseif (array_key_exists('link_button', $payload)) {
            $linkConfig = $payload['link_button'];
            if (is_array($linkConfig)) {
                $normalizedLink               = $this->normalizeLinkButtonConfigPayload($linkConfig);
                $this->config['link_buttons'] = $normalizedLink !== null ? [$normalizedLink] : [];
            } elseif ($linkConfig === null) {
                $this->config['link_buttons'] = [];
            }
        }

        if (array_key_exists('multi_link_buttons', $payload)) {
            $this->config['multi_link_buttons'] = $this->normalizeMultiLinkButtonConfigList($payload['multi_link_buttons']);
        } elseif (array_key_exists('multi_link_button', $payload)) {
            $multiLinkConfig = $payload['multi_link_button'];
            if (is_array($multiLinkConfig)) {
                $normalizedMulti                    = $this->normalizeMultiLinkButtonConfigPayload($multiLinkConfig);
                $this->config['multi_link_buttons'] = $normalizedMulti !== null ? [$normalizedMulti] : [];
            } elseif ($multiLinkConfig === null) {
                $this->config['multi_link_buttons'] = [];
            }
        }

        if (array_key_exists('action_button_sequence', $payload)) {
            $this->config['action_button_sequence'] = $this->normalizeActionButtonSequence($payload['action_button_sequence']);
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

        if (isset($payload['column_tooltips']) && is_array($payload['column_tooltips'])) {
            $tooltips = [];
            foreach ($payload['column_tooltips'] as $column => $entry) {
                if (!is_string($column)) {
                    continue;
                }

                $normalizedColumn = $this->normalizeColumnReference($column);
                if ($normalizedColumn === '') {
                    continue;
                }

                if (!is_string($entry) && !is_array($entry) && $entry !== null) {
                    continue;
                }

                try {
                    $definition = $this->normalizeColumnTooltipDefinition($entry);
                } catch (InvalidArgumentException) {
                    continue;
                }

                if ($definition['type'] !== 'none') {
                    $tooltips[$normalizedColumn] = $definition;
                }
            }

            $this->config['column_tooltips'] = $tooltips;
        }

        if (isset($payload['field_callbacks']) && is_array($payload['field_callbacks'])) {
            $normalized = [];
            foreach ($payload['field_callbacks'] as $field => $entry) {
                if (!is_string($field)) {
                    continue;
                }

                $normalizedField = $this->normalizeColumnReference($field);
                if ($normalizedField === '') {
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

                $normalized[$normalizedField] = $callable;
            }

            if ($normalized !== []) {
                $this->config['field_callbacks'] = $normalized;
            }
        }

        if (array_key_exists('soft_delete', $payload)) {
            $softDeleteConfig = $payload['soft_delete'];
            if ($softDeleteConfig === null || $softDeleteConfig === false) {
                $this->config['soft_delete'] = null;
            } elseif (is_array($softDeleteConfig)) {
                $assignmentsPayload = $softDeleteConfig['assignments'] ?? $softDeleteConfig;
                if (!is_array($assignmentsPayload)) {
                    throw new InvalidArgumentException('soft_delete configuration must provide an assignments array.');
                }

                $normalizedAssignments = $this->normalizeSoftDeleteAssignmentsForConfig($assignmentsPayload);
                if ($normalizedAssignments === []) {
                    throw new InvalidArgumentException('soft_delete configuration requires at least one assignment.');
                }

                $this->config['soft_delete'] = ['assignments' => $normalizedAssignments];
            } else {
                throw new InvalidArgumentException('soft_delete configuration must be an array or null.');
            }
        }

        if (array_key_exists('row_ordering', $payload)) {
            $rowOrderingConfig = $payload['row_ordering'];
            if ($rowOrderingConfig === null || $rowOrderingConfig === false) {
                $this->disableRowOrdering();
            } elseif (is_array($rowOrderingConfig)) {
                $this->config['row_ordering'] = $this->normalizeRowOrderingConfig($rowOrderingConfig);
                $this->applyRowOrderingSort();
            } else {
                throw new InvalidArgumentException('row_ordering configuration must be an array or null.');
            }
        }

        if (array_key_exists('audit_log', $payload)) {
            $auditConfig = $payload['audit_log'];
            if ($auditConfig === null || $auditConfig === false) {
                $this->disableAuditLog();
            } elseif (is_array($auditConfig)) {
                $this->config['audit_log'] = $this->normalizeAuditLogConfig($auditConfig);
            } else {
                throw new InvalidArgumentException('audit_log configuration must be an array or null.');
            }
        }

        if (isset($payload['lifecycle_callbacks']) && is_array($payload['lifecycle_callbacks'])) {
            $normalized = [];
            foreach (self::LIFECYCLE_EVENTS as $event) {
                $entries = $payload['lifecycle_callbacks'][$event] ?? null;
                if (!is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $callable = null;
                    if (is_string($entry)) {
                        $callable = $entry;
                    } elseif (is_array($entry) && isset($entry['callable'])) {
                        $callable = (string) $entry['callable'];
                    }

                    if ($callable === null || $callable === '' || !is_callable($callable)) {
                        continue;
                    }

                    $normalized[$event][] = $callable;
                }
            }

            foreach (self::LIFECYCLE_EVENTS as $event) {
                $this->config['lifecycle_callbacks'][$event] = $normalized[$event] ?? [];
            }
        }

        if (isset($payload['custom_columns']) && is_array($payload['custom_columns'])) {
            $custom = [];
            foreach ($payload['custom_columns'] as $column => $entry) {
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

                $custom[$normalizedColumn] = $callable;
            }

            if ($custom !== []) {
                $this->config['custom_columns'] = $custom;
            }
        }

        if (isset($payload['custom_fields']) && is_array($payload['custom_fields'])) {
            $custom = [];
            foreach ($payload['custom_fields'] as $field => $entry) {
                if (!is_string($field)) {
                    continue;
                }

                $normalizedField = $this->normalizeColumnReference($field);
                if ($normalizedField === '') {
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

                $custom[$normalizedField] = $callable;
            }

            if ($custom !== []) {
                $this->config['custom_fields'] = $custom;
                $this->addFormColumns(array_keys($custom));
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
                if (!is_string($field)) {
                    continue;
                }

                $normalizedField = $this->normalizeColumnReference($field);
                if ($normalizedField === '') {
                    continue;
                }

                if ($label === null) {
                    continue;
                }

                if (!is_string($label)) {
                    continue;
                }

                $trimmed = trim($label);
                if ($trimmed === '') {
                    $fieldLabels[$normalizedField] = '';
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

        if (isset($payload['column_formatters']) && is_array($payload['column_formatters'])) {
            $formatters = [];
            foreach ($payload['column_formatters'] as $column => $definition) {
                if (!is_string($column) || !is_array($definition)) {
                    continue;
                }

                $normalizedColumn = $this->normalizeColumnReference($column);
                if ($normalizedColumn === '') {
                    continue;
                }

                $type = isset($definition['type']) ? strtolower(trim((string) $definition['type'])) : '';
                if (!in_array($type, self::SUPPORTED_COLUMN_FORMATTERS, true)) {
                    continue;
                }

                $options = isset($definition['options']) && is_array($definition['options'])
                    ? $definition['options']
                    : [];
                $formatters[$normalizedColumn] = $this->normalizeColumnFormatterConfig($type, $options);
            }

            $this->config['column_formatters'] = $formatters;
        }

        if (isset($payload['ui_text']) && is_array($payload['ui_text'])) {
            $texts = [];
            foreach ($payload['ui_text'] as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }

                $normalizedKey = $this->normalizeUiTextKey($key);
                if ($normalizedKey !== '') {
                    $texts[$normalizedKey] = (string) $value;
                }
            }

            $this->config['ui_text'] = $texts;
        }

        if (isset($payload['style_overrides']) && is_array($payload['style_overrides'])) {
            $styles = [];
            foreach ($payload['style_overrides'] as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }

                $normalizedStyle = $this->normalizeCssClassList((string) $value);
                if ($normalizedStyle !== '') {
                    $styles[trim($key)] = $normalizedStyle;
                }
            }

            $this->config['style_overrides'] = $styles;
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

        if (array_key_exists('default_column_truncate', $payload)) {
            $this->config['default_column_truncate'] = $this->normalizeDefaultColumnTruncateValue(
                is_array($payload['default_column_truncate']) || is_int($payload['default_column_truncate'])
                ? $payload['default_column_truncate']
                : null,
                'default_column_truncate payload'
            );
        }


        if (isset($payload['column_highlights']) && is_array($payload['column_highlights'])) {
            $highlights = [];
            foreach ($payload['column_highlights'] as $column => $entries) {
                if (!is_string($column) || !is_array($entries)) {
                    continue;
                }
                $normalizedColumn  = $this->normalizeColumnReference($column);
                $normalizedEntries = [];
                foreach ($entries as $entry) {
                    if (!is_array($entry) || !isset($entry['condition'], $entry['class'])) {
                        continue;
                    }
                    $condition = $entry['condition'];
                    $class     = (string) $entry['class'];
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
                $class     = (string) $entry['class'];
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
                $type   = strtolower((string) $entry['type']);
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

        if (array_key_exists('nested_tables', $payload)) {
            $this->nestedTables = [];
            if (isset($payload['nested_tables']) && is_array($payload['nested_tables'])) {
                foreach ($payload['nested_tables'] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $name = isset($entry['name']) ? trim((string) $entry['name']) : '';
                    if ($name === '') {
                        continue;
                    }

                    $parentRaw        = isset($entry['parent_column']) ? trim((string) $entry['parent_column']) : '';
                    $normalizedParent = $this->normalizeColumnReference($parentRaw);
                    if ($normalizedParent === '') {
                        continue;
                    }

                    $tableName = isset($entry['table']) ? trim((string) $entry['table']) : '';
                    if ($tableName === '') {
                        continue;
                    }

                    $foreignColumn = isset($entry['foreign_column']) ? trim((string) $entry['foreign_column']) : '';
                    if ($foreignColumn === '') {
                        continue;
                    }

                    $child = new self($tableName, $this->connection);

                    $childConfig = null;
                    if (isset($entry['config'])) {
                        if (is_array($entry['config'])) {
                            $childConfig = $entry['config'];
                        } elseif (is_string($entry['config']) && $entry['config'] !== '') {
                            try {
                                $decoded = json_decode($entry['config'], true, 512, JSON_THROW_ON_ERROR);
                                if (is_array($decoded)) {
                                    $childConfig = $decoded;
                                }
                            } catch (JsonException) {
                                $childConfig = null;
                            }
                        }
                    }

                    if (is_array($childConfig)) {
                        $child->applyClientConfig($childConfig);
                    }

                    $this->nestedTables[$name] = [
                        'name'              => $name,
                        'parent_column'     => $normalizedParent,
                        'parent_column_raw' => isset($entry['parent_column_raw']) && is_string($entry['parent_column_raw'])
                            ? trim($entry['parent_column_raw'])
                            : $parentRaw,
                        'foreign_column'    => $foreignColumn,
                        'crud'              => $child,
                    ];
                }
            }

        }

        $orderByConfig = $this->config['order_by'];
        if (!is_array($orderByConfig)) {
            $orderByConfig = [];
        }
        $this->config['order_by'] = $this->sanitizeOrderByEntries($orderByConfig);

        $this->applyQueryBuilderPayload($payload['query_builder'] ?? null);
        $this->applyRowOrderingSort();

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
        $query = $this->buildSelectQuery(0, 0);

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
        $columns = $this->ensureCustomColumnNames($columns);

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
            $rows[$index]['__fastcrud_primary_key']   = $primaryKey;
            $rows[$index]['__fastcrud_primary_value'] = $row[$primaryKey] ?? null;
        }

        return $rows;
    }

    /**
     * Create a record and return the freshly inserted row.
     *
     * @param array<string, mixed> $fields Column => value map to insert
     * @return array<string, mixed>|null
     */
    public function createRecord(array $fields): ?array
    {
        if (!$this->isActionEnabled('add')) {
            throw new RuntimeException('Add action is not enabled for this table.');
        }

        $columns = $this->getTableColumnsFor($this->table);
        if ($columns === []) {
            throw new RuntimeException('Unable to determine table columns for insert.');
        }
        $columnLookup = $this->getTableColumnLookupFor($this->table);

        $primaryKeyColumn = $this->getPrimaryKeyColumn();
        $primaryKeySql    = $this->quotePrimaryKeyColumnName($primaryKeyColumn);

        $readonly = $this->gatherBehaviourForMode('readonly', 'create');
        $disabled = $this->gatherBehaviourForMode('disabled', 'create');

        $filtered = [];
        $submittedContext = $fields;
        foreach ($fields as $column => $value) {
            if (!is_string($column)) {
                continue;
            }

            if (!isset($columnLookup[$column])) {
                continue;
            }

            $normalizedColumn = $this->normalizeColumnReference($column);
            if ($normalizedColumn === '') {
                continue;
            }

            if (isset($readonly[$normalizedColumn]) || isset($disabled[$normalizedColumn])) {
                continue;
            }

            if (!$this->isFieldVisible($normalizedColumn, 'create', $submittedContext) || !$this->isFieldEditable($normalizedColumn, 'create', $submittedContext)) {
                continue;
            }

            $filtered[$column] = $value;
        }

        $context = array_merge($fields, $filtered);

        $passDefaults = $this->gatherBehaviourForMode('pass_default', 'create');
        foreach ($passDefaults as $column => $value) {
            if (!isset($columnLookup[$column])) {
                continue;
            }

            $needsDefault = !array_key_exists($column, $filtered)
                || $filtered[$column] === null
                || $filtered[$column] === '';

            if ($needsDefault) {
                $filtered[$column] = $this->renderTemplateValue($value, $context);
                $context[$column]  = $filtered[$column];
            }
        }

        $passVars = $this->gatherBehaviourForMode('pass_var', 'create');
        foreach ($passVars as $column => $value) {
            if (!isset($columnLookup[$column])) {
                continue;
            }

            $filtered[$column] = $this->renderTemplateValue($value, $context);
            $context[$column]  = $filtered[$column];
        }

        $filtered = $this->applyPasswordFieldTransformations($filtered, $fields);

        $context = array_merge($context, $filtered);

        if (!$this->isActionAllowedForRow('add', $context)) {
            throw new RuntimeException('Add action is not permitted for this data.');
        }

        $beforeContext = [
            'operation'     => 'insert',
            'stage'         => 'before',
            'table'         => $this->table,
            'mode'          => 'create',
            'primary_key'   => $primaryKeyColumn,
            'fields'        => $fields,
            'current_state' => $context,
        ];

        $beforeInsert = $this->dispatchLifecycleEvent('before_insert', $filtered, $beforeContext, true);
        if ($beforeInsert['cancelled']) {
            return null;
        }

        /** @var array<string, mixed> $filtered */
        $filtered = $beforeInsert['payload'];
        $context  = array_merge($context, $filtered);

        $errors = [];

        $required = $this->gatherBehaviourForMode('validation_required', 'create');
        foreach ($required as $column => $minLength) {
            if (!isset($columnLookup[$column])) {
                continue;
            }

            $value  = $filtered[$column] ?? null;
            $length = $this->measureValidationLength($value, true);

            if ($length < (int) $minLength) {
                $errors[$column] = 'This field is required.';
            }
        }

        $this->collectMaxLengthErrors($filtered, $columnLookup, 'create', $errors);

        $patterns = $this->gatherBehaviourForMode('validation_pattern', 'create');
        foreach ($patterns as $column => $pattern) {
            if (!isset($columnLookup[$column])) {
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

        $uniqueRules = $this->gatherBehaviourForMode('unique', 'create');
        foreach ($uniqueRules as $column => $flag) {
            if (!$flag || !isset($columnLookup[$column])) {
                continue;
            }

            if (!array_key_exists($column, $filtered)) {
                continue;
            }

            $value = $filtered[$column];
            if ($value === null || $value === '') {
                continue;
            }

            $sql = sprintf('SELECT COUNT(*) FROM %s WHERE %s = :value', $this->table, $column);

            $statement = $this->connection->prepare($sql);
            if ($statement === false) {
                continue;
            }

            try {
                $statement->execute([':value' => $value]);
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

        $primaryValue = null;
        if (array_key_exists($primaryKeyColumn, $filtered)) {
            $pkValue = $filtered[$primaryKeyColumn];
            if ($pkValue === null || $pkValue === '') {
                unset($filtered[$primaryKeyColumn]);
            } else {
                $primaryValue = $pkValue;
            }
        }

        if ($filtered === []) {
            throw new RuntimeException('No data provided for insert.');
        }

        $columnsList  = array_keys($filtered);
        $placeholders = [];
        $parameters   = [];
        foreach ($filtered as $column => $value) {
            $placeholder              = ':col_' . $column;
            $placeholders[]           = $placeholder;
            $parameters[$placeholder] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columnsList),
            implode(', ', $placeholders)
        );

        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare insert statement.');
        }

        try {
            $statement->execute($parameters);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to insert record.', 0, $exception);
        }

        $row = null;

        if ($primaryValue !== null) {
            $row = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryValue);
        }

        if ($row === null) {
            try {
                $newPk = $this->connection->lastInsertId();
                if (is_string($newPk) && $newPk !== '' && $newPk !== '0') {
                    $primaryValue = $newPk;
                    $row          = $this->findRowByPrimaryKey($primaryKeyColumn, $newPk);
                }
            } catch (PDOException) {
                // ignore and fall back below
            }
        }

        if ($row === null) {
            try {
                $sql          = sprintf('SELECT * FROM %s ORDER BY %s DESC LIMIT 1', $this->table, $primaryKeySql);
                $fallbackStmt = $this->connection->query($sql);
                if ($fallbackStmt !== false) {
                    $candidate = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($candidate)) {
                        $row = $candidate;
                        if ($primaryValue === null && array_key_exists($primaryKeyColumn, $candidate)) {
                            $primaryValue = $candidate[$primaryKeyColumn];
                        }
                    }
                }
            } catch (PDOException) {
                // ignore
            }
        }

        $afterContext = [
            'operation'     => 'insert',
            'stage'         => 'after',
            'table'         => $this->table,
            'mode'          => 'create',
            'primary_key'   => $primaryKeyColumn,
            'primary_value' => $primaryValue,
            'fields'        => $filtered,
        ];

        $this->writeAuditLog('create', $primaryValue ?? ($row[$primaryKeyColumn] ?? null), null, null, $row ?? $filtered, [
            'primary_key' => $primaryKeyColumn,
            'mode'        => 'create',
        ]);

        if ($row !== null) {
            $afterContext['row'] = $row;
            $after               = $this->dispatchLifecycleEvent('after_insert', $row, $afterContext, true);
            /** @var array<string, mixed> $resultRow */
            $resultRow = $after['payload'];
            return $resultRow;
        }

        $this->dispatchLifecycleEvent('after_insert', $filtered, $afterContext, true);

        return null;
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
        $columnLookup = $this->getTableColumnLookupFor($this->table);
        if (!isset($columnLookup[$primaryKeyColumn])) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);

        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['create', 'edit', 'view'], true)) {
            $mode = 'edit';
        }

        $currentRow = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
        if ($currentRow === null) {
            throw new InvalidArgumentException('Record not found for update.');
        }

        if (!$this->isActionAllowedForRow('edit', $currentRow)) {
            throw new RuntimeException('Edit action is not permitted for this record.');
        }

        $readonly = $this->gatherBehaviourForMode('readonly', $mode);
        $disabled = $this->gatherBehaviourForMode('disabled', $mode);

        $filtered       = [];
        $payloadColumns = [];
        foreach ($fields as $column => $value) {
            if (!is_string($column)) {
                continue;
            }

            $payloadColumns[$column] = true;

            if ($column === $primaryKeyColumn) {
                continue;
            }

            if (!isset($columnLookup[$column])) {
                continue;
            }

            $normalizedColumn = $this->normalizeColumnReference($column);
            if ($normalizedColumn === '') {
                continue;
            }

            if (isset($readonly[$normalizedColumn]) || isset($disabled[$normalizedColumn])) {
                continue;
            }

            $permissionContext = array_merge($currentRow, $fields);
            if (!$this->isFieldVisible($normalizedColumn, $mode, $permissionContext) || !$this->isFieldEditable($normalizedColumn, $mode, $permissionContext)) {
                continue;
            }

            $filtered[$column] = $value;
        }

        $context = array_merge($currentRow, $fields, $filtered);

        $passVars = $this->gatherBehaviourForMode('pass_var', $mode);
        foreach ($passVars as $column => $value) {
            if (!isset($columnLookup[$column])) {
                continue;
            }

            $filtered[$column] = $this->renderTemplateValue($value, $context);
            $context[$column]  = $filtered[$column];
        }

        $filtered = $this->applyPasswordFieldTransformations($filtered, $fields);

        $context = array_merge($currentRow, $fields, $filtered);

        $beforeContext = [
            'operation'     => 'update',
            'stage'         => 'before',
            'table'         => $this->table,
            'primary_key'   => $primaryKeyColumn,
            'primary_value' => $primaryKeyValue,
            'mode'          => $mode,
            'current_row'   => $currentRow,
            'fields'        => $fields,
        ];

        $beforeUpdate = $this->dispatchLifecycleEvent('before_update', $filtered, $beforeContext, true);
        if ($beforeUpdate['cancelled']) {
            return $currentRow;
        }

        /** @var array<string, mixed> $filtered */
        $filtered = $beforeUpdate['payload'];

        if ($filtered === []) {
            return $currentRow;
        }

        $context = array_merge($currentRow, $filtered);

        $errors = [];

        $required = $this->gatherBehaviourForMode('validation_required', $mode);
        foreach ($required as $column => $minLength) {
            if (!isset($columnLookup[$column])) {
                continue;
            }

            if (!array_key_exists($column, $filtered) && !isset($payloadColumns[$column])) {
                continue;
            }

            $value  = $filtered[$column] ?? ($context[$column] ?? null);
            $length = $this->measureValidationLength($value, true);

            if ($length < (int) $minLength) {
                $errors[$column] = 'This field is required.';
            }
        }

        $this->collectMaxLengthErrors($filtered, $columnLookup, $mode, $errors);

        $patterns = $this->gatherBehaviourForMode('validation_pattern', $mode);
        foreach ($patterns as $column => $pattern) {
            if (!isset($columnLookup[$column])) {
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
            if (!$flag || !isset($columnLookup[$column])) {
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
                $primaryKeySql
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
            $primaryKeySql
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

        $row = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);

        // Skip view-permission enforcement when the view action itself is disabled.
        if ($row !== null && $this->isActionEnabled('view') && !$this->isActionAllowedForRow('view', $row)) {
            throw new RuntimeException('View action is not permitted for this record.');
        }

        $afterContext = [
            'operation'     => 'update',
            'stage'         => 'after',
            'table'         => $this->table,
            'primary_key'   => $primaryKeyColumn,
            'primary_value' => $primaryKeyValue,
            'mode'          => $mode,
            'changes'       => $filtered,
            'previous_row'  => $currentRow,
        ];

        $newRowForAudit = $row ?? array_merge($currentRow, $filtered);
        foreach ($filtered as $column => $newValue) {
            $oldValue = $currentRow[$column] ?? null;
            $persistedValue = is_array($newRowForAudit) && array_key_exists($column, $newRowForAudit)
                ? $newRowForAudit[$column]
                : $newValue;

            if ($this->auditValuesEqual($oldValue, $persistedValue)) {
                continue;
            }

            $this->writeAuditLog('update', $primaryKeyValue, $column, $oldValue, $persistedValue, [
                'primary_key' => $primaryKeyColumn,
                'mode'        => $mode,
            ]);
        }

        if ($row !== null) {
            $afterContext['row'] = $row;
            $after               = $this->dispatchLifecycleEvent('after_update', $row, $afterContext, true);
            /** @var array<string, mixed> $updatedRow */
            $updatedRow = $after['payload'];

            return $updatedRow;
        }

        $this->dispatchLifecycleEvent('after_update', $filtered, $afterContext, true);

        return $row;
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
        $columnLookup = $this->getTableColumnLookupFor($this->table);
        if (!isset($columnLookup[$primaryKeyColumn])) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);

        $currentRow = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
        if ($currentRow === null) {
            return false;
        }

        if (!$this->isActionAllowedForRow('delete', $currentRow)) {
            throw new RuntimeException('Delete action is not permitted for this record.');
        }

        $softDeleteAssignments = $this->getSoftDeleteAssignments();
        $useSoftDelete         = $softDeleteAssignments !== [];

        $beforeContext = [
            'operation'     => 'delete',
            'stage'         => 'before',
            'table'         => $this->table,
            'primary_key'   => $primaryKeyColumn,
            'primary_value' => $primaryKeyValue,
            'mode'          => $useSoftDelete ? 'soft' : 'hard',
        ];

        $beforeDelete = $this->dispatchLifecycleEvent('before_delete', $currentRow, $beforeContext, true);
        if ($beforeDelete['cancelled']) {
            return false;
        }

        /** @var array<string, mixed> $rowForDeletion */
        $rowForDeletion = $beforeDelete['payload'];

        $parameters     = [':pk' => $primaryKeyValue];
        $resolvedValues = [];

        if ($useSoftDelete) {
            $updateClause = $this->buildSoftDeleteUpdateClause($softDeleteAssignments, $parameters, 'sd_single', $resolvedValues);
            $sql          = sprintf('UPDATE %s SET %s WHERE %s = :pk', $this->table, $updateClause, $primaryKeySql);
        } else {
            $sql = sprintf('DELETE FROM %s WHERE %s = :pk', $this->table, $primaryKeySql);
        }

        $statement = $this->connection->prepare($sql);

        if ($statement === false) {
            $message = $useSoftDelete
                ? 'Failed to prepare soft delete statement.'
                : 'Failed to prepare delete statement.';
            throw new RuntimeException($message);
        }

        try {
            $statement->execute($parameters);
        } catch (PDOException $exception) {
            $message = $useSoftDelete ? 'Failed to soft delete record.' : 'Failed to delete record.';
            throw new RuntimeException($message, 0, $exception);
        }

        $deleted = $statement->rowCount() > 0;
        $postRow = null;

        if ($useSoftDelete) {
            $postRow = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
            if (!$deleted && $this->softDeleteAssignmentsSatisfied($postRow, $softDeleteAssignments, $resolvedValues)) {
                $deleted = true;
            }
        }

        $afterContext = [
            'operation'     => 'delete',
            'stage'         => 'after',
            'table'         => $this->table,
            'primary_key'   => $primaryKeyColumn,
            'primary_value' => $primaryKeyValue,
            'deleted'       => $deleted,
            'mode'          => $useSoftDelete ? 'soft' : 'hard',
        ];

        $afterContext['row'] = $rowForDeletion;

        if ($deleted) {
            $this->writeAuditLog('delete', $primaryKeyValue, null, $rowForDeletion, $postRow, [
                'primary_key' => $primaryKeyColumn,
                'mode'        => $useSoftDelete ? 'soft' : 'hard',
            ]);
        }

        $this->dispatchLifecycleEvent('after_delete', $rowForDeletion, $afterContext, true);

        return $deleted;
    }

    /**
     * Delete multiple records by their primary key values.
     *
     * @param string $primaryKeyColumn
     * @param array<int, mixed> $primaryKeyValues
     * @return array{deleted: int, failures: array<int, array{value: mixed, error: string}>}
     */
    public function deleteRecords(string $primaryKeyColumn, array $primaryKeyValues): array
    {
        $primaryKeyColumn = trim($primaryKeyColumn);
        if ($primaryKeyColumn === '') {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        $columnLookup = $this->getTableColumnLookupFor($this->table);
        if (!isset($columnLookup[$primaryKeyColumn])) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);

        $normalizedValues = [];
        foreach ($primaryKeyValues as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            $normalizedValues[] = $value;
        }

        if ($normalizedValues === []) {
            return ['deleted' => 0, 'failures' => []];
        }

        $softDeleteAssignments = $this->getSoftDeleteAssignments();
        $useSoftDelete         = $softDeleteAssignments !== [];

        $initialRows = $this->fetchRowsByPrimaryKeys($primaryKeyColumn, $normalizedValues);

        $targets  = [];
        $failures = [];
        $seenKeys = [];

        foreach ($normalizedValues as $value) {
            $lookupKey = $this->normalizePrimaryKeyLookupKey($value);

            if (isset($seenKeys[$lookupKey])) {
                $failures[] = [
                    'value' => $value,
                    'error' => 'Record not found or already deleted.',
                ];
                continue;
            }

            $currentRow = $initialRows[$lookupKey] ?? null;
            if ($currentRow === null) {
                $failures[] = [
                    'value' => $value,
                    'error' => 'Record not found or already deleted.',
                ];
                continue;
            }

            if (!$this->isActionAllowedForRow('delete', $currentRow)) {
                $failures[] = [
                    'value' => $value,
                    'error' => 'Delete action is not permitted for this record.',
                ];
                continue;
            }

            $beforeContext = [
                'operation'     => 'delete',
                'stage'         => 'before',
                'table'         => $this->table,
                'primary_key'   => $primaryKeyColumn,
                'primary_value' => $value,
                'mode'          => $useSoftDelete ? 'soft' : 'hard',
            ];

            $beforeDelete = $this->dispatchLifecycleEvent('before_delete', $currentRow, $beforeContext, true);
            if ($beforeDelete['cancelled']) {
                $failures[] = [
                    'value' => $value,
                    'error' => 'Record not found or already deleted.',
                ];
                continue;
            }

            /** @var array<string, mixed> $rowForDeletion */
            $rowForDeletion = $beforeDelete['payload'];

            $targets[] = [
                'lookup_key' => $lookupKey,
                'value'      => $value,
                'row'        => $rowForDeletion,
            ];

            $seenKeys[$lookupKey] = true;
        }

        if ($targets === []) {
            return ['deleted' => 0, 'failures' => $failures];
        }

        $placeholders = [];
        $parameters   = [];
        foreach ($targets as $index => $target) {
            $placeholder              = ':pk_' . $index;
            $placeholders[$index]     = $placeholder;
            $parameters[$placeholder] = $target['value'];
        }

        $resolvedValues    = [];
        $statement         = null;
        $manageTransaction = !$this->connection->inTransaction();

        if ($manageTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            if ($useSoftDelete) {
                $updateClause = $this->buildSoftDeleteUpdateClause($softDeleteAssignments, $parameters, 'sd_batch', $resolvedValues);
                $sql          = sprintf(
                    'UPDATE %s SET %s WHERE %s IN (%s)',
                    $this->table,
                    $updateClause,
                    $primaryKeySql,
                    implode(', ', $placeholders)
                );
            } else {
                $sql = sprintf(
                    'DELETE FROM %s WHERE %s IN (%s)',
                    $this->table,
                    $primaryKeySql,
                    implode(', ', $placeholders)
                );
            }

            $statement = $this->connection->prepare($sql);
            if ($statement === false) {
                $message = $useSoftDelete
                    ? 'Failed to prepare soft delete statement.'
                    : 'Failed to prepare delete statement.';
                throw new RuntimeException($message);
            }

            $statement->execute($parameters);

            if ($manageTransaction) {
                $this->connection->commit();
            }
        } catch (PDOException $exception) {
            if ($manageTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            $message = $useSoftDelete ? 'Failed to soft delete records.' : 'Failed to delete records.';
            throw new RuntimeException($message, 0, $exception);
        } catch (RuntimeException $exception) {
            if ($manageTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        $expected = count($targets);
        $affected = $statement !== null ? $statement->rowCount() : 0;

        $perRowStatus = array_fill(0, $expected, !$useSoftDelete ? true : ($affected === $expected));

        $targetValues = array_map(static fn(array $entry) => $entry['value'], $targets);

        if ($useSoftDelete && $affected !== $expected) {
            $refetched = $this->fetchRowsByPrimaryKeys($primaryKeyColumn, $targetValues);
            foreach ($targets as $index => $target) {
                $lookupKey            = $this->normalizePrimaryKeyLookupKey($target['value']);
                $row                  = $refetched[$lookupKey] ?? null;
                $perRowStatus[$index] = $this->softDeleteAssignmentsSatisfied($row, $softDeleteAssignments, $resolvedValues);

                if (!$perRowStatus[$index]) {
                    $failures[] = [
                        'value' => $target['value'],
                        'error' => 'Record not found or already deleted.',
                    ];
                }
            }
        }

        if (!$useSoftDelete && $affected !== $expected) {
            $remaining = $this->fetchRowsByPrimaryKeys($primaryKeyColumn, $targetValues);
            foreach ($targets as $index => $target) {
                $lookupKey            = $this->normalizePrimaryKeyLookupKey($target['value']);
                $stillExists          = isset($remaining[$lookupKey]);
                $perRowStatus[$index] = !$stillExists;

                if ($stillExists) {
                    $failures[] = [
                        'value' => $target['value'],
                        'error' => 'Record not found or already deleted.',
                    ];
                }
            }
        }

        $deletedCount = 0;
        $postDeleteRows = $useSoftDelete
            ? $this->fetchRowsByPrimaryKeys($primaryKeyColumn, $targetValues)
            : [];

        foreach ($targets as $index => $target) {
            $deleted = $perRowStatus[$index] ?? false;
            if ($deleted) {
                $deletedCount++;
            }

            $afterContext = [
                'operation'     => 'delete',
                'stage'         => 'after',
                'table'         => $this->table,
                'primary_key'   => $primaryKeyColumn,
                'primary_value' => $target['value'],
                'deleted'       => $deleted,
                'mode'          => $useSoftDelete ? 'soft' : 'hard',
            ];

            $afterContext['row'] = $target['row'];

            if ($deleted) {
                $lookupKey = $this->normalizePrimaryKeyLookupKey($target['value']);
                $this->writeAuditLog('delete', $target['value'], null, $target['row'], $postDeleteRows[$lookupKey] ?? null, [
                    'primary_key' => $primaryKeyColumn,
                    'mode'        => $useSoftDelete ? 'soft' : 'hard',
                    'batch'       => true,
                ]);
            }

            $this->dispatchLifecycleEvent('after_delete', $target['row'], $afterContext, true);
        }

        return ['deleted' => $deletedCount, 'failures' => $failures];
    }

    /**
     * Bulk update multiple records using the same field assignments.
     *
     * @param array<int, mixed> $primaryKeyValues
     * @param array<string, mixed> $fields
     * @return array{updated: int, failures: array<int, array{value: mixed, error: string}>}
     */
    public function updateRecords(string $primaryKeyColumn, array $primaryKeyValues, array $fields, string $mode = 'edit'): array
    {
        $primaryKeyColumn = trim($primaryKeyColumn);
        if ($primaryKeyColumn === '') {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['create', 'edit', 'view'], true)) {
            $mode = 'edit';
        }

        $columnLookup = $this->getTableColumnLookupFor($this->table);
        if (!isset($columnLookup[$primaryKeyColumn])) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        $filteredFields = [];
        foreach ($fields as $column => $value) {
            if (!is_string($column)) {
                continue;
            }

            $normalizedColumn = trim($column);
            if ($normalizedColumn === '' || $normalizedColumn === $primaryKeyColumn) {
                continue;
            }

            if (!isset($columnLookup[$normalizedColumn])) {
                continue;
            }

            $filteredFields[$normalizedColumn] = $value;
        }

        if ($filteredFields === []) {
            throw new InvalidArgumentException('At least one column value is required for bulk update.');
        }

        $normalizedValues = [];
        foreach ($primaryKeyValues as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $candidate = trim($value);
                if ($candidate === '') {
                    continue;
                }
                $normalizedValues[] = $candidate;
                continue;
            }

            $normalizedValues[] = $value;
        }

        if ($normalizedValues === []) {
            return ['updated' => 0, 'failures' => []];
        }

        $updatedCount = 0;
        $failures     = [];

        foreach ($normalizedValues as $value) {
            try {
                $result = $this->updateRecord($primaryKeyColumn, $value, $filteredFields, $mode);
                if ($result === null) {
                    $failures[] = [
                        'value' => $value,
                        'error' => 'Record not found or could not be updated.',
                    ];
                } else {
                    $updatedCount++;
                }
            } catch (Throwable $exception) {
                $failures[] = [
                    'value' => $value,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return ['updated' => $updatedCount, 'failures' => $failures];
    }

    /**
     * Reorder the currently submitted row slice by updating the configured ordering column.
     *
     * @param array<int, mixed> $primaryKeyValues
     * @return array{updated: int}
     */
    public function reorderRecords(string $primaryKeyColumn, array $primaryKeyValues, int $startPosition = 1): array
    {
        if (!$this->isRowOrderingEnabled()) {
            throw new RuntimeException('Row ordering is not enabled for this table.');
        }

        $orderColumn = $this->getRowOrderingColumn();
        if ($orderColumn === null) {
            throw new RuntimeException('Row ordering column is not configured.');
        }

        $primaryKeyColumn = trim($primaryKeyColumn);
        if ($primaryKeyColumn === '') {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        $columnLookup = $this->getTableColumnLookupFor($this->table);
        if (!isset($columnLookup[$primaryKeyColumn])) {
            throw new InvalidArgumentException(sprintf('Unknown primary key column "%s".', $primaryKeyColumn));
        }
        if (!isset($columnLookup[$orderColumn])) {
            throw new InvalidArgumentException(sprintf('Unknown row ordering column "%s".', $orderColumn));
        }

        $values = [];
        $seen   = [];
        foreach ($primaryKeyValues as $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            $lookupKey = $this->normalizePrimaryKeyLookupKey($value);
            if (isset($seen[$lookupKey])) {
                continue;
            }

            $seen[$lookupKey] = true;
            $values[]         = $value;
        }

        if ($values === []) {
            return ['updated' => 0];
        }

        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);
        $orderColumnSql = $this->quoteIdentifierPart($orderColumn);
        $startPosition = max(1, $startPosition);
        $manageTransaction = !$this->connection->inTransaction();
        $updated = 0;

        if ($manageTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $statement = $this->connection->prepare(sprintf(
                'UPDATE %s SET %s = :position WHERE %s = :pk',
                $this->table,
                $orderColumnSql,
                $primaryKeySql
            ));

            if ($statement === false) {
                throw new RuntimeException('Failed to prepare row ordering statement.');
            }

            foreach ($values as $index => $value) {
                $statement->execute([
                    ':position' => $startPosition + $index,
                    ':pk'       => $value,
                ]);
                $updated += $statement->rowCount();
            }

            if ($manageTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $exception) {
            if ($manageTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw new RuntimeException('Failed to reorder rows.', 0, $exception);
        }

        return ['updated' => $updated];
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
        $columnLookup = $this->getTableColumnLookupFor($this->table);
        if (!isset($columnLookup[$primaryKeyColumn])) {
            throw new InvalidArgumentException(sprintf('Unknown primary key column "%s".', $primaryKeyColumn));
        }

        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);

        if (!$this->isActionEnabled('duplicate')) {
            throw new RuntimeException('Duplicate action is not enabled for this table.');
        }

        // 2) Load source row
        $source = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
        if ($source === null) {
            throw new InvalidArgumentException('Record not found for duplication.');
        }

        if (!$this->isActionAllowedForRow('duplicate', $source)) {
            throw new RuntimeException('Duplicate action is not permitted for this record.');
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

        $beforeContext = [
            'operation'     => 'insert',
            'stage'         => 'before',
            'table'         => $this->table,
            'mode'          => 'duplicate',
            'primary_key'   => $primaryKeyColumn,
            'primary_value' => $primaryKeyValue,
            'source_row'    => $source,
        ];

        $beforeDuplicate = $this->dispatchLifecycleEvent('before_insert', $fields, $beforeContext, true);
        if ($beforeDuplicate['cancelled']) {
            return null;
        }

        /** @var array<string, mixed> $fields */
        $fields = $beforeDuplicate['payload'];

        if ($fields === []) {
            throw new RuntimeException('Nothing to duplicate.');
        }

        // 4) Insert new row
        $placeholders = [];
        $parameters   = [];
        foreach ($fields as $column => $value) {
            $ph              = ':col_' . $column;
            $placeholders[]  = $ph;
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
                    $parameters   = [];
                    foreach ($fields as $column => $value) {
                        $ph              = ':col_' . $column;
                        $placeholders[]  = $ph;
                        $parameters[$ph] = $value;
                    }
                    $sql       = sprintf(
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

        $primaryValue = null;
        $row          = null;

        try {
            $newPk = $this->connection->lastInsertId();
            if (is_string($newPk) && $newPk !== '' && $newPk !== '0') {
                $primaryValue = $newPk;
                $row          = $this->findRowByPrimaryKey($primaryKeyColumn, $newPk);
            }
        } catch (PDOException) {
            // ignore, fallback below
        }

        if ($row === null) {
            try {
                $sql          = sprintf('SELECT * FROM %s ORDER BY %s DESC LIMIT 1', $this->table, $primaryKeySql);
                $fallbackStmt = $this->connection->query($sql);
                if ($fallbackStmt !== false) {
                    $candidate = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($candidate)) {
                        $row = $candidate;
                        if ($primaryValue === null && array_key_exists($primaryKeyColumn, $candidate)) {
                            $primaryValue = $candidate[$primaryKeyColumn];
                        }
                    }
                }
            } catch (PDOException) {
                // ignore
            }
        }

        $afterContext = [
            'operation'     => 'insert',
            'stage'         => 'after',
            'table'         => $this->table,
            'mode'          => 'duplicate',
            'primary_key'   => $primaryKeyColumn,
            'primary_value' => $primaryValue,
            'fields'        => $fields,
            'source_row'    => $source,
        ];

        $this->writeAuditLog('duplicate', $primaryValue ?? ($row[$primaryKeyColumn] ?? null), null, $source, $row ?? $fields, [
            'primary_key'       => $primaryKeyColumn,
            'source_record_id'  => $primaryKeyValue,
        ]);

        if ($row !== null) {
            $afterContext['row'] = $row;
            $after               = $this->dispatchLifecycleEvent('after_insert', $row, $afterContext, true);
            /** @var array<string, mixed> $duplicated */
            $duplicated = $after['payload'];
            return $duplicated;
        }

        $this->dispatchLifecycleEvent('after_insert', $fields, $afterContext, true);

        return null;
    }

    private function isDuplicateKeyException(PDOException $exception): bool
    {
        // MySQL: SQLSTATE 23000, error code 1062; generic message contains 'Duplicate entry'
        $code    = $exception->getCode();
        $message = strtolower((string) $exception->getMessage());
        $info0   = is_array($exception->errorInfo ?? null) ? ($exception->errorInfo[0] ?? null) : null;
        $info1   = is_array($exception->errorInfo ?? null) ? ($exception->errorInfo[1] ?? null) : null;
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
            $base      = $this->stripCopySuffix($value);
            $candidate = $base . ' (copy)';
            $attempt   = 2;
            while ($this->valueExistsForColumn($column, $candidate) && $attempt < 100) {
                $candidate = $base . ' (copy ' . $attempt . ')';
                $attempt++;
            }
            if (!$this->valueExistsForColumn($column, $candidate)) {
                $updated[$column] = $candidate;
                $changed          = true;
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
        $sql  = sprintf('SELECT COUNT(*) FROM %s WHERE %s = :v', $this->table, $column);
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
        $sql     = sprintf('SHOW INDEX FROM `%s`', $table);
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
            $keyName   = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            $seq       = isset($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : 0;
            $col       = isset($row['Column_name']) ? (string) $row['Column_name'] : '';
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
    private function findRowByPrimaryKey(string $primaryKeyColumn, mixed $primaryKeyValue, string $mode = 'edit'): ?array
    {
        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);
        $sql           = sprintf('SELECT * FROM %s WHERE %s = :pk LIMIT 1', $this->table, $primaryKeySql);
        $statement     = $this->connection->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('Failed to prepare record lookup.');
        }

        try {
            $statement->execute([':pk' => $primaryKeyValue]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to fetch updated record.', 0, $exception);
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $primaryKey                      = $this->getPrimaryKeyColumn();
        $row['__fastcrud_primary_key']   = $primaryKey;
        $row['__fastcrud_primary_value'] = $row[$primaryKey] ?? null;

        $resolvedMode = $this->normalizeRenderMode($mode) ?? 'edit';

        $row                           = $this->applyFieldCallbacksToRow($row, $resolvedMode);
        $row['__fastcrud_render_mode'] = $resolvedMode;

        return $row;
    }

    /**
     * Public accessor to fetch a single row by its primary key.
     *
     * @return array<string, mixed>|null
     */
    public function getRecord(string $primaryKeyColumn, mixed $primaryKeyValue, string $mode = 'edit'): ?array
    {
        $primaryKeyColumn = trim($primaryKeyColumn);
        if ($primaryKeyColumn === '') {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        $resolvedMode = $this->normalizeRenderMode($mode) ?? 'edit';

        // Validate against base table columns (not just visible columns)
        $columnLookup = $this->getTableColumnLookupFor($this->table);
        if (!isset($columnLookup[$primaryKeyColumn])) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        $beforePayload = [
            'primary_key_column' => $primaryKeyColumn,
            'primary_key_value'  => $primaryKeyValue,
            'mode'               => $resolvedMode,
        ];

        $beforeContext = [
            'operation' => 'read',
            'stage'     => 'before',
            'table'     => $this->table,
            'id'        => $this->id,
            'mode'      => $resolvedMode,
        ];

        $beforeRead = $this->dispatchLifecycleEvent('before_read', $beforePayload, $beforeContext, true);
        if ($beforeRead['cancelled']) {
            return null;
        }

        $resolvedBeforePayload = $beforePayload;
        if (is_array($beforeRead['payload'])) {
            $resolvedBeforePayload = array_merge($resolvedBeforePayload, $beforeRead['payload']);
        }

        if (isset($resolvedBeforePayload['primary_key_column'])) {
            $candidateColumn = trim((string) $resolvedBeforePayload['primary_key_column']);
            if ($candidateColumn !== '') {
                $primaryKeyColumn = $candidateColumn;
            }
        }

        if (!isset($columnLookup[$primaryKeyColumn])) {
            $message = sprintf('Unknown primary key column "%s".', $primaryKeyColumn);
            throw new InvalidArgumentException($message);
        }

        if (array_key_exists('primary_key_value', $resolvedBeforePayload)) {
            $primaryKeyValue = $resolvedBeforePayload['primary_key_value'];
        }

        if (isset($resolvedBeforePayload['mode'])) {
            $candidateMode = $this->normalizeRenderMode((string) $resolvedBeforePayload['mode']);
            if ($candidateMode !== null) {
                $resolvedMode = $candidateMode;
            }
        }

        $row = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue, $resolvedMode);

        $afterPayload = [
            'row'                => $row,
            'primary_key_column' => $primaryKeyColumn,
            'primary_key_value'  => $primaryKeyValue,
            'mode'               => $resolvedMode,
        ];

        $afterContext = [
            'operation' => 'read',
            'stage'     => 'after',
            'table'     => $this->table,
            'id'        => $this->id,
            'found'     => $row !== null,
            'mode'      => $resolvedMode,
        ];

        $afterRead = $this->dispatchLifecycleEvent('after_read', $afterPayload, $afterContext, true);
        if (!$afterRead['cancelled'] && is_array($afterRead['payload'])) {
            $resolvedAfterPayload = array_merge($afterPayload, $afterRead['payload']);
            if (array_key_exists('row', $resolvedAfterPayload)) {
                $row = $resolvedAfterPayload['row'];
            }
        }

        $row = is_array($row) ? $row : null;
        if ($row !== null) {
            $row['__fastcrud_render_mode'] = $resolvedMode;
            $permissionColumns = $this->config['form']['all_columns'] ?? [];
            if (!is_array($permissionColumns) || $permissionColumns === []) {
                $permissionColumns = $this->getBaseTableColumns();
            }
            $permissions = $this->buildFieldPermissionState($permissionColumns, $resolvedMode, $row);
            if ($permissions !== []) {
                $row['__fastcrud_field_permissions'] = $permissions;
            }
        }

        return $row;
    }

    /**
     * Generate jQuery AJAX script for loading table data with pagination.
     */
    private function generateAjaxScript(): string
    {
        $styles           = $this->getStyleDefaults();
        $editViewRowClass = trim($styles['edit_view_row_highlight_class'] ?? '');
        if ($editViewRowClass === '') {
            $editViewRowClass = 'table-warning';
        }
        $styles['edit_view_row_highlight_class'] = $editViewRowClass;

        $icons = [];
        foreach (['view', 'edit', 'delete', 'duplicate', 'expand', 'collapse'] as $action) {
            $property = $action . '_action_icon';
            $icons[$action] = '<i class="' . $this->escapeHtml(CrudStyle::${$property})
                . ' fastcrud-icon" aria-hidden="true"></i>';
        }

        return CrudAssets::renderInitializer([
            'id' => $this->id,
            'styles' => $styles,
            'texts' => $this->getUiTextDefaults(),
            'icons' => $icons,
        ]);
    }
}
