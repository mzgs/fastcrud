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
    /**
     * @var array<string, array<string, string>>
     */
    private array $enumOptionsCache = [];
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $queryBuilderFieldCache = null;
    /**
     * @var array<string, array{name: string, parent_column: string, parent_column_raw: string, foreign_column: string, crud: self}>
     */
    private array $nestedTables = [];
    private string $primaryKeyColumn = 'id';
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
        'equals' => ['label' => 'Equals', 'requires_value' => true, 'multi' => false],
        'not_equals' => ['label' => 'Does not equal', 'requires_value' => true, 'multi' => false],
        'contains' => ['label' => 'Contains', 'requires_value' => true, 'multi' => false],
        'not_contains' => ['label' => 'Does not contain', 'requires_value' => true, 'multi' => false],
        'gt' => ['label' => 'Greater than', 'requires_value' => true, 'multi' => false],
        'gte' => ['label' => 'Greater than or equal', 'requires_value' => true, 'multi' => false],
        'lt' => ['label' => 'Less than', 'requires_value' => true, 'multi' => false],
        'lte' => ['label' => 'Less than or equal', 'requires_value' => true, 'multi' => false],
        'in' => ['label' => 'Is one of', 'requires_value' => true, 'multi' => true],
        'not_in' => ['label' => 'Is not one of', 'requires_value' => true, 'multi' => true],
        'empty' => ['label' => 'Is empty', 'requires_value' => false, 'multi' => false],
        'not_empty' => ['label' => 'Is not empty', 'requires_value' => false, 'multi' => false],
    ];

    private const SUPPORTED_SUMMARY_TYPES = ['sum', 'avg', 'min', 'max', 'count'];
    private const SUPPORTED_FORM_MODES = ['all', 'create', 'edit', 'view'];
    private const DEFAULT_FORM_MODE = 'all';
    private const SUPPORTED_SOFT_DELETE_MODES = ['timestamp', 'literal', 'expression'];
    private const DEFAULT_MULTI_LINK_BUTTON_CLASS = 'btn btn-sm btn-outline-secondary dropdown-toggle';
    private const DEFAULT_MULTI_LINK_MENU_CLASS = 'dropdown-menu dropdown-menu-end';
    private const DEFAULT_MULTI_LINK_CONTAINER_CLASS = 'btn-group';
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
        'custom_columns' => [],
        'field_callbacks' => [],
        'custom_fields' => [],
        'soft_delete' => null,
        'lifecycle_callbacks' => [
            'before_insert' => [],
            'after_insert' => [],
            'before_update' => [],
            'after_update' => [],
            'before_delete' => [],
            'after_delete' => [],
            'before_fetch' => [],
            'after_fetch' => [],
            'before_read' => [],
            'after_read' => [],
        ],
        'column_classes' => [],
        'column_widths' => [],
        'column_cuts' => [],
        'column_highlights' => [],
        'row_highlights' => [],
        'link_buttons' => [],
        'multi_link_buttons' => [],
        'inline_edit' => [],
        'table_meta' => [
            'title'   => null,
            'tooltip' => null,
            'icon'    => null,
            'hide_title' => false,
            'add' => true,
            'view' => true,
            'view_condition' => null,
            'edit' => true,
            'edit_condition' => null,
            'delete' => true,
            'delete_condition' => null,
            'duplicate' => false,
            'duplicate_condition' => null,
            'batch_delete' => false,
            'batch_delete_button' => false,
            'bulk_actions' => [],
            'delete_confirm' => true,
            'export_csv' => false,
            'export_excel' => false,
        ],
        'column_summaries' => [],
        'field_labels' => [],
        'panel_width' => null,
        'select2' => false,
        'filters_enabled' => true,
        'numbers_enabled' => false,
        'primary_key' => 'id',
        'query_builder' => [
            'filters' => [],
            'logic' => 'AND',
            'sorts' => [],
            'active_view' => null,
        ],
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
            'sections' => [],
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
        if ($dbConfig instanceof PDO) {
            Database::setConnection($dbConfig);
        } elseif ($dbConfig !== null) {
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
        $this->connection = $connection ?? Database::connection();
        $this->id         = $this->generateId();

        $this->config['select2'] = CrudConfig::$enable_select2;
        $this->config['filters_enabled'] = CrudConfig::$enable_filters;
        $this->config['numbers_enabled'] = CrudConfig::$enable_numbers;
        $this->config['table_meta']['hide_title'] = CrudConfig::$hide_table_title;
    }

    public function getTable(): string
    {
        return $this->table;
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
     * Enable inline edit for the given fields (excluding boolean switches which are always inline).
     *
     * @param string|array<int, string> $fields
     */
    public function inline_edit(string|array $fields): self
    {
        $list = $this->normalizeList($fields);
        $map = [];
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
     * @param array<string, mixed> $payload
     */
    private function normalizeLinkButtonConfigPayload(array $payload): ?array
    {
        $url = isset($payload['url']) ? trim((string) $payload['url']) : '';
        if ($url === '') {
            return null;
        }

        $iconRaw = isset($payload['icon']) ? (string) $payload['icon'] : '';
        $iconClass = $this->normalizeCssClassList($iconRaw);
        if ($iconClass === '') {
            return null;
        }

        $buttonClass = null;
        if (array_key_exists('button_class', $payload) && $payload['button_class'] !== null) {
            $buttonClassRaw = (string) $payload['button_class'];
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

        $options = [];
        if (isset($payload['options']) && is_array($payload['options'])) {
            foreach ($payload['options'] as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $normalizedKey = trim($key);
                if ($normalizedKey === '') {
                    continue;
                }

                if (is_scalar($value)) {
                    $options[$normalizedKey] = (string) $value;
                }
            }
        }

        $styles = $this->getStyleDefaults();
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
     * @param array<string, mixed> $payload
     */
    private function normalizeMultiLinkButtonConfigPayload(array $payload): ?array
    {
        $itemsRaw = $payload['items'] ?? null;
        if (!is_array($itemsRaw) || $itemsRaw === []) {
            return null;
        }

        $items = [];
        $hasActionItem = false;
        $hasDuplicateItem = false;
        $hasDeleteItem = false;
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
                    'type' => 'divider',
                ];
                continue;
            }

            if ($entryType === 'duplicate') {
                $label = 'Duplicate';
                if (array_key_exists('label', $entry) && $entry['label'] !== null) {
                    $labelCandidate = trim((string) $entry['label']);
                    if ($labelCandidate !== '') {
                        $label = $labelCandidate;
                    }
                }

                $icon = null;
                $defaultIcon = $this->normalizeCssClassList((string) (CrudStyle::$duplicate_action_icon ?? ''));
                if ($defaultIcon !== '') {
                    $icon = $defaultIcon;
                }
                if (array_key_exists('icon', $entry) && $entry['icon'] !== null) {
                    $iconRaw = (string) $entry['icon'];
                    $iconClass = $this->normalizeCssClassList($iconRaw);
                    if ($iconClass !== '') {
                        $icon = $iconClass;
                    }
                }

                $itemOptions = [];
                if (isset($entry['options']) && is_array($entry['options'])) {
                    foreach ($entry['options'] as $key => $value) {
                        if (!is_string($key)) {
                            continue;
                        }

                        $normalizedKey = trim($key);
                        if ($normalizedKey === '') {
                            continue;
                        }

                        if (is_scalar($value)) {
                            $itemOptions[$normalizedKey] = (string) $value;
                        }
                    }
                }

                $items[] = [
                    'type'    => 'duplicate',
                    'label'   => $label,
                    'icon'    => $icon,
                    'options' => $itemOptions,
                ];
                $hasActionItem = true;
                $hasDuplicateItem = true;
                continue;
            }

            if ($entryType === 'delete') {
                $label = 'Delete';
                if (array_key_exists('label', $entry) && $entry['label'] !== null) {
                    $labelCandidate = trim((string) $entry['label']);
                    if ($labelCandidate !== '') {
                        $label = $labelCandidate;
                    }
                }

                $icon = null;
                $defaultIcon = $this->normalizeCssClassList((string) (CrudStyle::$delete_action_icon ?? ''));
                if ($defaultIcon !== '') {
                    $icon = $defaultIcon;
                }
                if (array_key_exists('icon', $entry) && $entry['icon'] !== null) {
                    $iconRaw = (string) $entry['icon'];
                    $iconClass = $this->normalizeCssClassList($iconRaw);
                    if ($iconClass !== '') {
                        $icon = $iconClass;
                    }
                }

                $itemOptions = [];
                if (isset($entry['options']) && is_array($entry['options'])) {
                    foreach ($entry['options'] as $key => $value) {
                        if (!is_string($key)) {
                            continue;
                        }

                        $normalizedKey = trim($key);
                        if ($normalizedKey === '') {
                            continue;
                        }

                        if (is_scalar($value)) {
                            $itemOptions[$normalizedKey] = (string) $value;
                        }
                    }
                }

                $items[] = [
                    'type'    => 'delete',
                    'label'   => $label,
                    'icon'    => $icon,
                    'options' => $itemOptions,
                ];
                $hasActionItem = true;
                $hasDeleteItem = true;
                continue;
            }

            $url = isset($entry['url']) ? trim((string) $entry['url']) : '';
            if ($url === '') {
                continue;
            }

            $labelRaw = isset($entry['label']) ? (string) $entry['label'] : '';
            $label = trim($labelRaw);
            if ($label === '') {
                continue;
            }

            $icon = null;
            if (array_key_exists('icon', $entry) && $entry['icon'] !== null) {
                $iconRaw = (string) $entry['icon'];
                $iconClass = $this->normalizeCssClassList($iconRaw);
                if ($iconClass !== '') {
                    $icon = $iconClass;
                }
            }

            $itemOptions = [];
            if (isset($entry['options']) && is_array($entry['options'])) {
                foreach ($entry['options'] as $key => $value) {
                    if (!is_string($key)) {
                        continue;
                    }

                    $normalizedKey = trim($key);
                    if ($normalizedKey === '') {
                        continue;
                    }

                    if (is_scalar($value)) {
                        $itemOptions[$normalizedKey] = (string) $value;
                    }
                }
            }

            $items[] = [
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
            $iconCandidate = (string) $buttonRaw['icon'];
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
            $buttonClassRaw = (string) $buttonRaw['button_class'];
            $normalizedButton = $this->normalizeCssClassList($buttonClassRaw);
            if ($normalizedButton !== '') {
                $buttonClass = $normalizedButton;
            }
        }

        $menuClass = null;
        if (array_key_exists('menu_class', $buttonRaw) && $buttonRaw['menu_class'] !== null) {
            $menuClassRaw = (string) $buttonRaw['menu_class'];
            $normalizedMenu = $this->normalizeCssClassList($menuClassRaw);
            if ($normalizedMenu !== '') {
                $menuClass = $normalizedMenu;
            }
        }

        $containerClass = null;
        if (array_key_exists('container_class', $buttonRaw) && $buttonRaw['container_class'] !== null) {
            $containerClassRaw = (string) $buttonRaw['container_class'];
            $normalizedContainer = $this->normalizeCssClassList($containerClassRaw);
            if ($normalizedContainer !== '') {
                $containerClass = $normalizedContainer;
            }
        }

        $buttonOptions = [];
        if (isset($buttonRaw['options']) && is_array($buttonRaw['options'])) {
            foreach ($buttonRaw['options'] as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $normalizedKey = trim($key);
                if ($normalizedKey === '') {
                    continue;
                }

                if (is_scalar($value)) {
                    $buttonOptions[$normalizedKey] = (string) $value;
                }
            }
        }

        return [
            'button' => [
                'icon'             => $buttonIcon,
                'label'            => $buttonLabel,
                'button_class'     => $buttonClass ?? self::DEFAULT_MULTI_LINK_BUTTON_CLASS,
                'menu_class'       => $menuClass ?? self::DEFAULT_MULTI_LINK_MENU_CLASS,
                'container_class'  => $containerClass ?? self::DEFAULT_MULTI_LINK_CONTAINER_CLASS,
                'options'          => $buttonOptions,
            ],
            'items' => $items,
        ];
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

        $normalizedList = [];
        foreach ($stored as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalized = $this->normalizeLinkButtonConfigPayload($entry);
            if ($normalized !== null) {
                $normalizedList[] = $normalized;
            }
        }

        $this->config['link_buttons'] = $normalizedList;

        return $normalizedList;
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

        $normalizedList = [];
        foreach ($stored as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalized = $this->normalizeMultiLinkButtonConfigPayload($entry);
            if ($normalized !== null) {
                $normalizedList[] = $normalized;
            }
        }

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

        $class = trim($class);
        $method = trim($method);

        if ($class === '' || $method === '') {
            throw new InvalidArgumentException('Callback array entries cannot be empty strings.');
        }

        return $class . '::' . $method;
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
            $algorithm = $this->normalizePasswordAlgorithm($algorithmCandidate);

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
                    $tab = null;
                    if (isset($entry['tab']) && is_string($entry['tab'])) {
                        $tabCandidate = trim($entry['tab']);
                        $tab = $tabCandidate === '' ? null : $tabCandidate;
                    }

                    $section = null;
                    if (isset($entry['section']) && is_string($entry['section'])) {
                        $sectionCandidate = $this->normalizeSectionIdentifier($entry['section']);
                        $section = $sectionCandidate === '' ? null : $sectionCandidate;
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
                        $title = $trimmedTitle === '' ? null : $trimmedTitle;
                    }

                    $description = null;
                    if (isset($entry['description']) && is_string($entry['description'])) {
                        $trimmedDescription = trim($entry['description']);
                        $description = $trimmedDescription === '' ? null : $trimmedDescription;
                    }

                    $icon = null;
                    if (isset($entry['icon']) && is_string($entry['icon'])) {
                        $iconCandidate = $this->normalizeCssClassList($entry['icon']);
                        $icon = $iconCandidate === '' ? null : $iconCandidate;
                    }

                    $collapsible = !empty($entry['collapsible']);
                    $collapsed = false;
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
     * @param array{empty?: string, unsupported?: string} $messages
     */
    private function normalizeConditionOperatorString(string $operator, array $messages): string
    {
        $original = $operator;
        $normalized = strtolower(trim($operator));

        if ($normalized === '') {
            if (isset($messages['empty'])) {
                throw new InvalidArgumentException($messages['empty']);
            }

            return 'equals';
        }

        $normalized = str_replace(' ', '_', $normalized);

        $synonyms = [
            '='   => 'equals',
            '=='  => 'equals',
            '===' => 'equals',
            'eq'  => 'equals',
            '!='  => 'not_equals',
            '!==' => 'not_equals',
            '<>'  => 'not_equals',
            'ne'  => 'not_equals',
            'not_equals' => 'not_equals',
            '>'   => 'gt',
            'gt'  => 'gt',
            '>='  => 'gte',
            'gte' => 'gte',
            '<'   => 'lt',
            'lt'  => 'lt',
            '<='  => 'lte',
            'lte' => 'lte',
            'notin' => 'not_in',
            'not_in' => 'not_in',
            'contains' => 'contains',
            '!contains' => 'not_contains',
            'notcontains' => 'not_contains',
            'not_contains' => 'not_contains',
            'does_not_contain' => 'not_contains',
            'doesnt_contain' => 'not_contains',
            'not_like' => 'not_contains',
            '!~' => 'not_contains',
            '!value' => 'empty',
            'empty' => 'empty',
            '!empty' => 'not_empty',
            'not_empty' => 'not_empty',
            'has_value' => 'not_empty',
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
            'in_not_in' => 'IN/NOT IN conditions require a non-empty array of values.',
            'comparison' => 'Comparison operators require numeric values.',
            'contains' => 'Contains operator requires a string value.',
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
            'empty' => 'Highlight operator cannot be empty.',
            'unsupported' => 'Unsupported condition operator: %s',
        ]);

        $normalizedValue = $this->normalizeConditionValueForOperator(
            $normalizedOperator,
            $value,
            [
                'in_not_in' => 'IN/NOT IN conditions require a non-empty array of values.',
                'comparison' => 'Comparison operators require numeric values.',
                'contains' => 'Contains operator requires a string value.',
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
            'empty' => 'Duplicate condition operator cannot be empty.',
            'unsupported' => 'Unsupported duplicate condition operator: %s',
        ]);

        $value = $this->normalizeConditionValueForOperator(
            $operator,
            $value,
            [
                'in_not_in' => 'IN/NOT IN duplicate conditions require a non-empty list of values.',
                'comparison' => 'Comparison duplicate conditions require numeric values.',
                'contains' => 'Contains duplicate conditions require a string value.',
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
            'add'       => isset($meta['add']) ? (bool) $meta['add'] : true,
            'view'      => isset($meta['view']) ? (bool) $meta['view'] : true,
            'edit'      => isset($meta['edit']) ? (bool) $meta['edit'] : true,
            'delete'    => (
                (isset($meta['delete']) ? (bool) $meta['delete'] : true)
                || $this->hasActionOverride('delete')
            ),
            'duplicate' => (
                (isset($meta['duplicate']) ? (bool) $meta['duplicate'] : false)
                || $this->hasActionOverride('duplicate')
            ),
            default     => false,
        };
    }

    private function isBatchDeleteEnabled(): bool
    {
        $meta = $this->config['table_meta'] ?? [];

        $batchDeleteConfigured = isset($meta['batch_delete']) ? (bool) $meta['batch_delete'] : false;
        $hasBulkActions = isset($meta['bulk_actions']) && is_array($meta['bulk_actions']) && $meta['bulk_actions'] !== [];

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
        $key = $action . '_condition';
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
        $column = $condition['column'] ?? null;
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
            case 'not_contains':
                return !is_string($current) || strpos($current, (string) $value) === false;
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

    private function applyPattern(string $pattern, string $display, mixed $raw, string $column, array $row, ?string $formatted = null): string
    {
        return preg_replace_callback(
            self::PATTERN_TOKEN_REGEX,
            function (array $matches) use ($display, $raw, $column, $row, $formatted): string {
                $token = strtolower($matches[1]);

                return match ($token) {
                    'value'  => $display,
                    'formatted' => $formatted ?? $display,
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

        $linkButtons = $this->buildLinkButtonsMetaForRow($sourceRow);
        if ($linkButtons !== []) {
            $meta['link_buttons'] = $linkButtons;
        }

        $multiLinkButtons = $this->buildMultiLinkButtonsMetaForRow($sourceRow);
        if ($multiLinkButtons !== []) {
            $meta['multi_link_buttons'] = $multiLinkButtons;
        }

        $meta['view_allowed'] = $this->isActionAllowedForRow('view', $sourceRow);
        $meta['duplicate_allowed'] = $this->isActionAllowedForRow('duplicate', $sourceRow);
        $meta['edit_allowed'] = $this->isActionAllowedForRow('edit', $sourceRow);
        $meta['delete_allowed'] = $this->isActionAllowedForRow('delete', $sourceRow);

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
            $result = call_user_func($callable, $field, $initialValue, $original, $mode);
            $row = $this->applyFieldCallbackResult($row, $field, $result, $initialValue);
        }

        $fieldCallbacks = $this->config['field_callbacks'] ?? [];
        foreach ($fieldCallbacks as $field => $callable) {
            if (!is_string($field) || $field === '' || !is_callable($callable)) {
                continue;
            }

            $currentValue = $row[$field] ?? ($original[$field] ?? null);
            $result = call_user_func($callable, $field, $currentValue, $row, $mode);
            $row = $this->applyFieldCallbackResult($row, $field, $result, $currentValue);
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

            $items = [];
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
                    if ($items !== []) {
                        $lastIndex = array_key_last($items);
                        $lastItem = $lastIndex !== null ? $items[$lastIndex] : null;
                        $lastType = is_array($lastItem) && isset($lastItem['type']) ? strtolower((string) $lastItem['type']) : null;
                        if ($lastType !== 'divider') {
                            $items[] = ['type' => 'divider'];
                        }
                    }
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

                    $items[] = [
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

                    $items[] = [
                        'type'    => 'delete',
                        'label'   => $resolvedLabel,
                        'icon'    => $resolvedIcon,
                        'options' => $resolvedOptions,
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

                $items[] = [
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
        $display = $this->stringifyValue($value);
        $displayOriginal = $display;
        $formattedDisplay = $display;

        $cut = $this->config['column_cuts'][$column] ?? null;
        if (is_array($cut) && isset($cut['length'])) {
            $suffix = isset($cut['suffix']) ? (string) $cut['suffix'] : '…';
            $formattedDisplay = $this->truncateString($formattedDisplay, (int) $cut['length'], $suffix);
        }

        $html = null;

        $patternEntry = $this->config['column_patterns'][$column] ?? null;
        if ($patternEntry !== null) {
            $patternTemplate = trim((string) $patternEntry);
            if ($patternTemplate !== '') {
                $patternOutput = $this->applyPattern($patternTemplate, $displayOriginal, $value, $column, $row, $formattedDisplay);
                $html = $patternOutput;
            }
        }

        $display = $formattedDisplay;

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
                $formattedValue = $html !== null ? $html : $display;
                $result = call_user_func($callable, $value, $row, $column, $formattedValue);

                if ($result !== null) {
                    $stringResult = $this->stringifyValue($result);
                    $html = $stringResult;
                    $display = $stringResult;
                }
            }
        }

        if ($html === null && isset($this->config['custom_columns'][$column])) {
            $stringValue = $this->stringifyValue($value);
            if ($stringValue !== '') {
                $html = $stringValue;
                $display = $stringValue;
            }
        }

        if ($html === null && isset($this->config['form']['behaviours']['change_type'][$column])) {
            $change = $this->config['form']['behaviours']['change_type'][$column];
            $type = is_array($change) && isset($change['type']) ? strtolower((string) $change['type']) : '';
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
                    $target = $resolved !== '' ? $resolved : $fileName;
                    $href = $this->buildPublicUploadUrl($target);
                    $linkText = $display;
                    $html = '<a href="' . $this->escapeHtml($href) . '" target="_blank" rel="noopener noreferrer">' . $this->escapeHtml($linkText) . '</a>';
                }
            } elseif ($type === 'files') {
                $raw = $rawOriginal;
                if ($raw === null || $raw === '') {
                    $raw = $value;
                }
                $names = $this->parseImageNameList($raw);
                if ($changeParams !== null) {
                    $names = array_values(array_filter(array_map(
                        static fn(string $name): string => self::resolveStoredFileName($name, $changeParams),
                        $names
                    ), static fn(string $name): bool => $name !== ''));
                }
                if ($names !== []) {
                    $first = $names[0];
                    $href = $this->buildPublicUploadUrl($first);
                    $extra = count($names) > 1 ? ' (+' . (count($names) - 1) . ')' : '';
                    $text = $this->extractFileName($first) . $extra;
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
                        $resolved = self::resolveStoredFileName($fileName, $changeParams);
                        $target = $resolved !== '' ? $resolved : $fileName;
                        $src = $this->buildPublicUploadUrl($target);
                        $style = $height > 0 ? (' style="height: ' . $height . 'px; width: auto;"') : '';
                        $html = '<img src="' . $this->escapeHtml($src) . '" alt="" class="img-thumbnail"' . $style . ' />';
                    }
                } else {
                    $raw = $rawOriginal;
                    if ($raw === null || $raw === '') {
                        $raw = $value;
                    }
                    $names = $this->parseImageNameList($raw);
                    if ($changeParams !== null) {
                        $names = array_values(array_filter(array_map(
                            static fn(string $name): string => self::resolveStoredFileName($name, $changeParams),
                            $names
                        ), static fn(string $name): bool => $name !== ''));
                    }
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

        $append = static function(array &$list, string $candidate): void {
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

        $hashPos = strpos($str, '#');
        if ($hashPos !== false) {
            $str = substr($str, 0, $hashPos);
        }

        $queryPos = strpos($str, '?');
        if ($queryPos !== false) {
            $str = substr($str, 0, $queryPos);
        }

        $str = str_replace('\\', '/', $str);
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
            $parsed = parse_url($candidate, PHP_URL_PATH) ?: '';
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

        $base = CrudConfig::getUploadPath();
        $base = strtr(trim($base), ['\\' => '/']);
        $base = preg_replace('#/+#', '/', $base) ?? $base;
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
            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('column_callback requires at least one valid column name.');
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
     * Apply a callback to transform form field values before they are sent to the client.
     *
     * The callback receives the field name, the current value, the full row array, and the form
     * mode (`edit`, `create`, or `view`). Whatever value it returns (including null) replaces the
     * existing field value. Return an array with an `html` key or a plain string (text or markup)
     * to provide custom form controls rendered as raw HTML. When returning custom markup, include
     * your own inputs with `data-fastcrud-field="{field}"` so the Ajax submit logic can capture
     * the value.
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
            $applied = true;
        }

        if (!$applied) {
            throw new InvalidArgumentException('field_callback requires at least one valid field name.');
        }

        return $this;
    }

    /**
     * Register a form field that is not stored in the database.
     *
     * The callback receives the field name, the initial value (or null), the row array, and the
     * current form mode. Return a string of HTML or an array with `html`/`value` keys to inject
     * custom form controls. Escape the markup yourself if it contains untrusted data.
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

        if (!isset($this->config['form']['all_columns']) || !is_array($this->config['form']['all_columns'])) {
            $this->config['form']['all_columns'] = [];
        }

        if (!in_array($normalizedField, $this->config['form']['all_columns'], true)) {
            $this->config['form']['all_columns'][] = $normalizedField;
        }

        return $this;
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

        $this->config['table_meta']['batch_delete'] = $enabled;
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

            $name = isset($entry['name']) ? (string) $entry['name'] : '';
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

    /**
     * @param array<int|string, mixed> $assignments
     * @return array<int, array{column: string, mode: string, value: mixed}>
     */
    private function normalizeSoftDeleteAssignmentsForConfig(array $assignments): array
    {
        $normalized = [];

        foreach ($assignments as $key => $definition) {
            if (is_int($key) && is_array($definition) && isset($definition['column'])) {
                $column = (string) $definition['column'];
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

        $mode = $defaultMode;
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
            $mode = 'literal';
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

    private function generateSoftDeleteTimestamp(): string
    {
        return date('Y-m-d H:i:s');
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
        $index = 0;

        foreach ($assignments as $assignment) {
            $column = $assignment['column'];
            $mode = $assignment['mode'];
            $columnSql = $this->quoteIdentifierPart($column);

            if ($mode === 'expression') {
                $expression = (string) $assignment['value'];
                $clauses[] = sprintf('%s = %s', $columnSql, $expression);
                $resolvedValues[$column] = null;
                continue;
            }

            $value = $mode === 'timestamp'
                ? $this->generateSoftDeleteTimestamp()
                : ($assignment['value'] ?? null);

            $placeholder = sprintf(':%s_%d', $parameterPrefix, $index++);
            $clauses[] = sprintf('%s = %s', $columnSql, $placeholder);
            $parameters[$placeholder] = $value;
            $resolvedValues[$column] = $value;
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
            $actual = $row[$column];

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

    public function enable_export_excel(bool $enabled = true): self
    {
        $this->config['table_meta']['export_excel'] = (bool) $enabled;

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

        return $this;
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
    public function fields(
        string|array $fields,
        bool $reverse = false,
        string|false $tab = false,
        string|array|false $mode = false,
        string|false $section = false
    ): self
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

        $sectionName = null;
        if ($section !== false) {
            $sectionCandidate = $this->normalizeSectionIdentifier((string) $section);
            $sectionName = $sectionCandidate === '' ? null : $sectionCandidate;
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
            $title = $trimmedTitle === '' ? null : $trimmedTitle;
        }

        $description = null;
        if (isset($definition['description']) && is_string($definition['description'])) {
            $trimmedDescription = trim($definition['description']);
            $description = $trimmedDescription === '' ? null : $trimmedDescription;
        }

        $icon = null;
        if (isset($definition['icon']) && is_string($definition['icon'])) {
            $iconCandidate = $this->normalizeCssClassList($definition['icon']);
            $icon = $iconCandidate === '' ? null : $iconCandidate;
        }

        $collapsible = !empty($definition['collapsible']);
        $collapsed = false;
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
        ];

        foreach ($modes as $targetMode) {
            $bucket = $targetMode === 'all' ? 'all' : $targetMode;
            if (!isset($this->config['form']['sections'][$bucket]) || !is_array($this->config['form']['sections'][$bucket])) {
                $this->config['form']['sections'][$bucket] = [];
            }
            $this->config['form']['sections'][$bucket][$sectionId] = $sectionEntry;
        }

        $this->storeLayoutEntry($normalizedFields, false, null, $modes, $sectionId);

        if (!isset($this->config['form']['all_columns']) || !is_array($this->config['form']['all_columns'])) {
            $this->config['form']['all_columns'] = [];
        }

        foreach ($normalizedFields as $field) {
            if (!in_array($field, $this->config['form']['all_columns'], true)) {
                $this->config['form']['all_columns'][] = $field;
            }
        }

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

        $normalized = $this->normalizeColumnReference($field);
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
     * Define a nested table instance that can be expanded per row.
     *
     * @param callable|null $configurator Optional callback to configure the nested Crud instance.
     */
    public function nested_table(
        string $instanceName,
        string $parentColumn,
        string $innerTable,
        string $innerTableField,
        ?callable $configurator = null
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
            'name'               => $name,
            'parent_column'      => $normalizedParentColumn,
            'parent_column_raw'  => trim($parentColumn),
            'foreign_column'     => $foreignColumn,
            'crud'               => $child,
        ];

        return $child;
    }

    private function addWhereCondition(string $condition, string $glue): void
    {
        $normalizedGlue = strtoupper($glue) === 'OR' ? 'OR' : 'AND';
        $trimmed = trim($condition);

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
        foreach (array_keys($array) as $key) {
            if (!is_int($key)) {
                return true;
            }
        }

        return false;
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

        $queryFilters = $this->config['query_builder']['filters'] ?? [];
        if (is_array($queryFilters) && $queryFilters !== []) {
            $logic = isset($this->config['query_builder']['logic'])
                && strtoupper((string) $this->config['query_builder']['logic']) === 'OR'
                ? 'OR'
                : 'AND';

            $qbClauses = [];
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
                // start with visible grid columns and always merge configured search columns
                // so hidden-but-searchable fields are still queried.
                $exprList = [];
                $seenExprs = [];

                foreach ($map as $expr) {
                    if ($expr === '') { continue; }
                    $exprList[] = $expr;
                    $seenExprs[$expr] = true;
                }

                foreach ($configuredColumns as $c) {
                    $expr = $this->normalizeWhereField($c);
                    if ($expr === '' || isset($seenExprs[$expr])) {
                        continue;
                    }
                    $exprList[] = $expr;
                    $seenExprs[$expr] = true;
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

            $rawType = isset($row['Type']) ? (string) $row['Type'] : null;
            $type = $rawType !== null ? strtolower($rawType) : null;
            $enumValues = $rawType !== null ? $this->parseEnumDefinition($rawType) : [];

            $schema[$field] = [
                'type' => $type,
                'raw_type' => $row['Type'] ?? null,
                'meta' => $row,
            ];

            if ($enumValues !== []) {
                $schema[$field]['enum_values'] = $enumValues;
            }
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

            $dataType = isset($row['data_type']) ? strtolower((string) $row['data_type']) : null;
            $udtName  = isset($row['udt_name']) ? strtolower((string) $row['udt_name']) : null;
            $udtSchema = isset($row['udt_schema']) ? (string) $row['udt_schema'] : null;

            $enumValues = [];
            if ($dataType === 'user-defined' && $udtName !== null && $udtName !== '') {
                $enumValues = $this->fetchPgsqlEnumOptions($udtName, $udtSchema);
            }

            $schema[$field] = [
                'type' => $dataType ?: $udtName,
                'data_type' => $dataType,
                'udt_name' => $udtName,
                'meta' => $row,
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

        if (isset($this->enumOptionsCache[$cacheKey])) {
            return $this->enumOptionsCache[$cacheKey];
        }

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
            $this->enumOptionsCache[$cacheKey] = [];
            return [];
        }

        try {
            $statement->execute($params);
        } catch (PDOException) {
            $this->enumOptionsCache[$cacheKey] = [];
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

        $this->enumOptionsCache[$cacheKey] = $options;

        return $options;
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

        $open = strpos($trimmed, '(');
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
        $values = [];
        $length = strlen($body);
        $buffer = '';
        $inValue = false;
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
                    $buffer = '';
                    $inValue = false;
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
                'type' => 'select',
                'default' => $default,
                'params' => ['values' => $enumValues],
            ];
        }

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
        $formOnly = $normalizedMode !== null;

        $rawId  = $this->id;
        $id     = $this->escapeHtml($rawId);
        $table  = $this->escapeHtml($this->table);
        $perPage = $this->perPage;

        // Get column names for headers
        $columns = $this->getColumnNames();

        if ($columns === []) {
            return '<div class="alert alert-warning">No columns available for this table.</div>';
        }

        $batchDeleteEnabled = $this->isBatchDeleteEnabled();
        $headerHtml = $this->buildHeader($columns);
        $clientConfigPayload = $this->buildClientConfigPayload();
        $script     = $this->generateAjaxScript();
        $styles     = $this->buildActionColumnStyles($rawId, $formOnly);
        $numbersEnabled = !empty($this->config['numbers_enabled']);
        $colspan    = $this->escapeHtml((string) (count($columns) + 1 + ($batchDeleteEnabled ? 1 : 0) + ($this->hasNestedTables() ? 1 : 0) + ($numbersEnabled ? 1 : 0)));
        $offcanvas  = $this->buildEditOffcanvas($rawId, $formOnly) . $this->buildViewOffcanvas($rawId, $formOnly);
        $queryBuilderModal = $this->buildQueryBuilderModal($rawId, $formOnly);

        $configJson = '{}';
        try {
            $configJson = json_encode($clientConfigPayload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $configJson = '{}';
        }

        $viewStorageKey = $this->buildViewStorageKey($clientConfigPayload);

        $containerAttributes = [
            'id'                              => $rawId . '-container',
            'data-fastcrud-config'            => $configJson,
            'data-fastcrud-initial-primary-column' => $this->getPrimaryKeyColumn(),
            'data-fastcrud-view-storage-key'  => $viewStorageKey,
        ];

        if ($formOnly) {
            $containerAttributes['class'] = 'fastcrud-form-only';
            $containerAttributes['data-fastcrud-form-only'] = '1';
            $containerAttributes['data-fastcrud-initial-mode'] = $normalizedMode;

            if ($primaryKeyValue !== null && $normalizedMode !== 'create') {
                $containerAttributes['data-fastcrud-initial-primary'] = is_scalar($primaryKeyValue)
                    ? (string) $primaryKeyValue
                    : json_encode($primaryKeyValue);
            }
        }

        $attributePairs = [];
        foreach ($containerAttributes as $name => $value) {
            if ($value === null) {
                continue;
            }

            $attributePairs[] = sprintf('%s="%s"', $name, $this->escapeHtml((string) $value));
        }
        $attributesHtml = implode(' ', $attributePairs);

        return <<<HTML
<div {$attributesHtml}>
    <div id="{$id}-meta" class="d-flex flex-wrap align-items-center gap-2 mb-2"></div>
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
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <span class="fastcrud-loading-text">Loading data...</span>
                        </div>
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
$queryBuilderModal
$styles
$offcanvas
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
        $rows = $this->applyCustomColumns($rows);

        $columns = $this->extractColumnNames($statement, $rows);
        $columns = $this->ensureCustomColumnNames($columns);
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
        $parameters = [];
        foreach (array_values($primaryKeyValues) as $index => $value) {
            $placeholder = ':pk_list_' . $index;
            $placeholders[] = $placeholder;
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
        $results = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['__fastcrud_primary_key'] = $primaryKey;
            $row['__fastcrud_primary_value'] = $row[$primaryKey] ?? null;

            /** @var array<string, mixed> $normalizedRow */
            $normalizedRow = $this->applyFieldCallbacksToRow($row, 'edit');
            $lookupValue = $normalizedRow[$primaryKeyColumn] ?? ($row[$primaryKeyColumn] ?? null);
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
            'export_excel_button_class'     => 'btn btn-sm btn-outline-secondary',
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

        $globalActionClass = '';
        $globalActionClassRaw = CrudStyle::$action_button_global_class ?? '';
        if (is_string($globalActionClassRaw)) {
            $globalActionClass = trim($globalActionClassRaw);
        }

        $toolbarGlobalClass = '';
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
            'export_excel_button_class'     => CrudStyle::$export_excel_button_class ?? '',
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

            $defaults[$key] = $trimmed;
            $appliedOverrides[$key] = true;
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
                'export_excel_button_class',
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

        $defaults['action_button_global_class'] = $globalActionClass;
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

    private function buildEditOffcanvas(string $id, bool $inline = false): string
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

        $styles = $this->getStyleDefaults();
        $cancelClass = $this->escapeHtml($styles['panel_cancel_button_class']);
        $saveClass   = $this->escapeHtml($styles['panel_save_button_class']);

        if ($inline) {
            $panelClasses = 'fastcrud-inline-panel card shadow-sm border-0';
            return <<<HTML
<div class="{$panelClasses}" id="{$panelId}" data-fastcrud-inline="1">
    <div class="card-header border-bottom d-flex align-items-center justify-content-between">
        <h5 class="mb-0" id="{$labelId}">Edit Record</h5>
        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <button type="submit" form="{$formId}" class="{$saveClass} fastcrud-submit-close" data-fastcrud-submit-action="close">Save Changes</button>
            <button type="submit" form="{$formId}" class="{$saveClass} fastcrud-submit-new d-none" data-fastcrud-submit-action="new">Create Record &amp; New</button>
        </div>
    </div>
    <div class="card-body">
        <form id="{$formId}" novalidate class="d-flex flex-column gap-3">
            <div class="alert alert-danger d-none" id="{$errorId}" role="alert"></div>
            <div class="alert alert-success d-none" id="{$successId}" role="alert">Changes saved successfully.</div>
            <div id="{$fieldsId}" class="fastcrud-inline-fields"></div>
            <div class="d-flex justify-content-end gap-2 pt-2">
                <button type="submit" class="{$saveClass} fastcrud-submit-close" data-fastcrud-submit-action="close">Save Changes</button>
                <button type="submit" class="{$saveClass} fastcrud-submit-new d-none" data-fastcrud-submit-action="new">Create Record &amp; New</button>
            </div>
        </form>
    </div>
</div>
HTML;
        }

        $panelClasses = 'offcanvas offcanvas-start';
        $inlineAttr = '';

        return <<<HTML
<div class="{$panelClasses}" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}"{$widthStyle}{$inlineAttr}>
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
                <button type="button" class="{$cancelClass}" data-bs-dismiss="offcanvas">Cancel</button>
                <button type="submit" class="{$saveClass}">Save Changes</button>
            </div>
        </form>
    </div>
</div>
HTML;
    }

    private function buildViewOffcanvas(string $id, bool $inline = false): string
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

        if ($inline) {
            $panelClasses = 'fastcrud-inline-panel card shadow-sm border-0';
            return <<<HTML
<div class="{$panelClasses}" id="{$panelId}" data-fastcrud-inline="1">
    <div class="card-header border-bottom">
        <h5 class="mb-0" id="{$labelId}">View Record</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info d-none" id="{$emptyId}" role="alert">No record selected.</div>
        <div id="{$contentId}" class="list-group list-group-flush"></div>
    </div>
</div>
HTML;
        }

        $panelClasses = 'offcanvas offcanvas-start';
        $inlineAttr = '';

        return <<<HTML
<div class="{$panelClasses}" tabindex="-1" id="{$panelId}" aria-labelledby="{$labelId}"{$widthStyle}{$inlineAttr}>
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

    private function buildQueryBuilderModal(string $id, bool $formOnly = false): string
    {
        if ($formOnly) {
            return '';
        }

        $escapedId = $this->escapeHtml($id);
        $modalId = $escapedId . '-query-builder';
        $logicId = $escapedId . '-qb-logic';
        $filtersId = $escapedId . '-qb-filters';
        $sortsId = $escapedId . '-qb-sorts';
        $applyId = $escapedId . '-qb-apply';
        $clearId = $escapedId . '-qb-clear';
        $saveId = $escapedId . '-qb-save';

        return <<<HTML
<div class="modal fade fastcrud-query-builder" id="{$modalId}" tabindex="-1" aria-labelledby="{$modalId}-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="{$modalId}-label">Query Builder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="{$logicId}" class="form-label">Match Conditions</label>
                    <select id="{$logicId}" class="form-select form-select-sm" style="max-width: 14rem;">
                        <option value="AND">All conditions (AND)</option>
                        <option value="OR">Any condition (OR)</option>
                    </select>
                </div>
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Filters</h6>
                        <small class="text-muted">Add one or more conditions to filter results</small>
                    </div>
                    <div id="{$filtersId}"></div>
                </div>
                <div class="mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Sort Order</h6>
                        <small class="text-muted">Define the priority of ordering</small>
                    </div>
                    <div id="{$sortsId}"></div>
                </div>
            </div>
            <div class="modal-footer d-flex align-items-center">
                <div class="me-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="{$clearId}">Clear</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="{$saveId}">Save as View</button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" id="{$applyId}">Apply</button>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
    }

    private function buildActionColumnStyles(string $id, bool $formOnly = false): string
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
        if ($formOnly) {
            $additionalCss = <<<CSS
#{$containerId}.fastcrud-form-only .table-responsive,
#{$containerId}.fastcrud-form-only nav,
#{$containerId}.fastcrud-form-only #{$summaryId} {
    display: none !important;
}
#{$containerId}.fastcrud-form-only #{$metaId} {
    display: none !important;
}
#{$editPanelId}.fastcrud-inline-panel,
#{$viewPanelId}.fastcrud-inline-panel {
    position: static;
    visibility: hidden;
    transform: none !important;
    width: 100%;
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
}

#{$containerId} .fastcrud-view-controls .fastcrud-open-query-builder {
    display: inline-flex;
    align-items: center;
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
                $searchTerm = $searchTermCandidate === null ? null : (string) $searchTermCandidate;
            }

            if (array_key_exists('search_column', $modifiedPayload)) {
                $searchColumnCandidate = $modifiedPayload['search_column'];
                if ($searchColumnCandidate === null) {
                    $searchColumn = null;
                } elseif (is_string($searchColumnCandidate)) {
                    $searchColumnCandidate = trim($searchColumnCandidate);
                    $searchColumn = $searchColumnCandidate === '' ? null : $searchColumnCandidate;
                }
            }
        }

        $beforeContext['resolved'] = [
            'page'          => $page,
            'per_page'      => $perPage,
            'search_term'   => $searchTerm,
            'search_column' => $searchColumn,
        ];

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
        $hasCustomFields = $this->config['custom_fields'] ?? [];

        if ($hasFieldCallbacks === [] && $hasCustomFields === []) {
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

        foreach (['create', 'edit', 'view'] as $mode) {
            $row = $baseRow;
            $templates[$mode] = $this->applyFieldCallbacksToRow($row, $mode);
        }

        return $templates;
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
        $batchDeleteConfigured = isset($tableMeta['batch_delete']) ? (bool) $tableMeta['batch_delete'] : false;
        $tableMeta['batch_delete_button'] = $batchDeleteConfigured;
        $tableTitle = isset($tableMeta['title']) && is_string($tableMeta['title']) && $tableMeta['title'] !== ''
            ? $tableMeta['title']
            : $this->makeTitle($this->table);

        $inline = array_values(array_keys(array_filter($this->config['inline_edit'] ?? [], static fn($v) => (bool) $v)));

        $sortDisabled = array_values(array_filter(
            $this->config['sort_disabled'],
            static function ($col) use ($columnLookup): bool {
                return is_string($col) && isset($columnLookup[$col]);
            }
        ));

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
            'table' => [
                'key'       => $this->table,
                'title'     => $tableTitle,
                'tooltip'   => $tableMeta['tooltip'] ?? null,
                'icon'      => $tableMeta['icon'] ?? null,
                'hide_title' => isset($tableMeta['hide_title'])
                    ? (bool) $tableMeta['hide_title']
                    : CrudConfig::$hide_table_title,
                'add'       => isset($tableMeta['add']) ? (bool) $tableMeta['add'] : true,
                'view'      => isset($tableMeta['view']) ? (bool) $tableMeta['view'] : true,
                'view_condition' => isset($tableMeta['view_condition']) && is_array($tableMeta['view_condition'])
                    ? $tableMeta['view_condition']
                    : null,
                'edit'      => isset($tableMeta['edit']) ? (bool) $tableMeta['edit'] : true,
                'edit_condition' => isset($tableMeta['edit_condition']) && is_array($tableMeta['edit_condition'])
                    ? $tableMeta['edit_condition']
                    : null,
                'delete'    => isset($tableMeta['delete']) ? (bool) $tableMeta['delete'] : true,
                'delete_condition' => isset($tableMeta['delete_condition']) && is_array($tableMeta['delete_condition'])
                    ? $tableMeta['delete_condition']
                    : null,
                'duplicate' => isset($tableMeta['duplicate']) ? (bool) $tableMeta['duplicate'] : false,
                'duplicate_condition' => isset($tableMeta['duplicate_condition']) && is_array($tableMeta['duplicate_condition'])
                    ? $tableMeta['duplicate_condition']
                    : null,
                'batch_delete' => $this->isBatchDeleteEnabled(),
                'batch_delete_button' => $batchDeleteConfigured,
                'bulk_actions' => isset($tableMeta['bulk_actions']) && is_array($tableMeta['bulk_actions'])
                    ? array_values($tableMeta['bulk_actions'])
                    : [],
                'delete_confirm' => isset($tableMeta['delete_confirm']) ? (bool) $tableMeta['delete_confirm'] : true,
                'export_csv' => isset($tableMeta['export_csv']) ? (bool) $tableMeta['export_csv'] : false,
                'export_excel' => isset($tableMeta['export_excel']) ? (bool) $tableMeta['export_excel'] : false,
            ],
            'link_buttons'    => $this->getNormalizedLinkButtonsConfig(),
            'multi_link_buttons' => $this->getNormalizedMultiLinkButtonsConfig(),
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
            'sort_disabled'  => $sortDisabled,
            'form' => $formMeta,
            'inline_edit' => $inline,
            'numbers_enabled' => (bool) ($this->config['numbers_enabled'] ?? false),
            'nested_tables' => $this->buildNestedTablesClientConfigPayload(),
            'soft_delete'   => $this->config['soft_delete'],
            'query_builder' => $this->buildQueryBuilderClientPayload(),
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
                        $title = $trimmedTitle === '' ? null : $trimmedTitle;
                    }

                    $description = null;
                    if (isset($entry['description']) && is_string($entry['description'])) {
                        $trimmedDescription = trim($entry['description']);
                        $description = $trimmedDescription === '' ? null : $trimmedDescription;
                    }

                    $collapsible = !empty($entry['collapsible']);
                    $collapsed = $collapsible && !empty($entry['collapsed']);

                    $icon = null;
                    if (isset($entry['icon']) && is_string($entry['icon'])) {
                        $iconCandidate = $this->normalizeCssClassList($entry['icon']);
                        $icon = $iconCandidate === '' ? null : $iconCandidate;
                    }

                    $normalizedSections[] = [
                        'id'          => $sectionId,
                        'title'       => $title,
                        'description' => $description,
                        'fields'      => array_values(array_unique($fields)),
                        'collapsible' => $collapsible,
                        'collapsed'   => $collapsed,
                        'icon'        => $icon,
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

        $primaryKey = $this->normalizeColumnReference($this->getPrimaryKeyColumn());
        $primaryKeyRaw = $this->denormalizeColumnReference($primaryKey);
        $primaryKeyNameOnly = $primaryKey;
        if (strpos($primaryKey, '__') !== false) {
            $parts = explode('__', $primaryKey);
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

            if (
                $normalized === $primaryKey
                || $normalized === $primaryKeyNameOnly
                || $column === $primaryKeyRaw
                || $column === $primaryKeyNameOnly
            ) {
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
            $child = $entry['crud'];
            $payload[] = [
                'name'              => $entry['name'],
                'parent_column'     => $entry['parent_column'],
                'parent_column_raw' => $entry['parent_column_raw'],
                'foreign_column'    => $entry['foreign_column'],
                'table'             => $child->getTable(),
                'label'             => $child->getConfiguredTableTitle(),
                'config'            => $child->buildClientConfigPayload(),
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClientConfigPayload(): array
    {
        $this->ensureFormLayoutBuckets();
        $this->ensureFormBehaviourBuckets();
        $this->ensureDefaultTabBuckets();

        $columns = $this->getColumnNames();
        $allColumns = $this->getBaseTableColumns();
        $formConfig = $this->config['form'];

        foreach (array_keys($this->config['custom_columns']) as $customColumn) {
            if (is_string($customColumn) && $customColumn !== '' && !in_array($customColumn, $allColumns, true)) {
                $allColumns[] = $customColumn;
            }
        }

        foreach (array_keys($this->config['custom_fields']) as $customField) {
            if (is_string($customField) && $customField !== '' && !in_array($customField, $allColumns, true)) {
                $allColumns[] = $customField;
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

        $inline = array_values(array_keys(array_filter($this->config['inline_edit'] ?? [], static fn($v) => (bool) $v)));

        $sortDisabled = array_values(array_filter(
            $this->config['sort_disabled'],
            static fn($col): bool => is_string($col) && $col !== ''
        ));

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

        $batchDeleteConfigured = isset($this->config['table_meta']['batch_delete'])
            ? (bool) $this->config['table_meta']['batch_delete']
            : false;
        $this->config['table_meta']['batch_delete_button'] = $batchDeleteConfigured;

        $formMeta = $this->buildFormMeta($columns);
        if (!isset($formMeta['all_columns']) || !is_array($formMeta['all_columns'])) {
            $formMeta['all_columns'] = $allColumns;
        }

        $templates = $this->buildFormTemplates($formMeta['all_columns']);
        if ($templates !== []) {
            $formMeta['templates'] = $templates;
        }

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
            'custom_columns'   => $this->config['custom_columns'],
            'field_callbacks'  => $this->config['field_callbacks'],
            'lifecycle_callbacks' => $this->config['lifecycle_callbacks'],
            'custom_fields'    => $this->config['custom_fields'],
            'sort_disabled'    => $sortDisabled,
            'column_classes'  => $this->config['column_classes'],
            'column_widths'   => $this->config['column_widths'],
            'column_cuts'     => $this->config['column_cuts'],
            'column_highlights' => $this->config['column_highlights'],
            'row_highlights'    => $this->config['row_highlights'],
            'link_buttons'       => $this->config['link_buttons'],
            'multi_link_buttons' => $this->config['multi_link_buttons'],
            'table_meta'        => $this->config['table_meta'],
            'column_summaries'  => $this->config['column_summaries'],
            'field_labels'      => $this->config['field_labels'],
            'primary_key'       => $this->primaryKeyColumn,
            'soft_delete'       => $this->config['soft_delete'],
            'form'              => $formMeta,
            'inline_edit'       => $inline,
            'nested_tables'     => $this->buildNestedTablesClientConfigPayload(),
            'rich_editor'       => [
                'upload_path' => CrudConfig::getUploadPath(),
            ],
            'select2'           => (bool) ($this->config['select2'] ?? false),
            'debug'           => (bool) CrudConfig::$debug,
            'filters_enabled'   => (bool) ($this->config['filters_enabled'] ?? true),
            'numbers_enabled'   => (bool) ($this->config['numbers_enabled'] ?? false),
            'query_builder'     => $this->buildQueryBuilderClientPayload(),
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
                'value' => $operator,
                'label' => $label,
                'requires_value' => (bool) ($config['requires_value'] ?? true),
                'multi' => (bool) ($config['multi'] ?? false),
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
        $type = $this->determineQueryBuilderFieldType($columnMeta);
        $options = $this->extractEnumValues($columnMeta);
        $label = $this->resolveColumnLabel($fieldKey);

        return [
            'id'       => $fieldKey,
            'field'    => $fieldKey,
            'label'    => $label,
            'type'     => $type,
            'nullable' => $this->queryBuilderColumnIsNullable($columnMeta),
            'options'  => $options,
            'sql'      => $this->quoteQualifiedIdentifier($qualifiedName),
        ];
    }

    /**
     * @param array<string, mixed> $columnMeta
     */
    private function determineQueryBuilderFieldType(array $columnMeta): string
    {
        $typeInfo = $this->detectSqlTypeInfo($columnMeta);
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
        $typeInfo = $this->detectSqlTypeInfo($columnMeta);
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
            'json', 'jsonb', 'blob', 'tinyblob', 'mediumblob', 'longblob',
            'binary', 'varbinary', 'bit', 'geometry', 'point', 'linestring',
            'polygon', 'multipoint', 'multilinestring', 'multipolygon',
            'geometrycollection', 'bytea'
        ];

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
        $isMulti = (bool) ($operatorConfig['multi'] ?? false);

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
        ];
    }

    private function applyQueryBuilderPayload(mixed $payload): void
    {
        $state = [
            'filters' => [],
            'logic' => 'AND',
            'sorts' => $this->config['order_by'],
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
                $state['sorts'] = $sorts;
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

        $type = isset($filter['type']) && is_string($filter['type']) ? $filter['type'] : 'string';

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
                    'equals' => '=',
                    'not_equals' => '<>',
                    'gt' => '>',
                    'gte' => '>=',
                    'lt' => '<',
                    'lte' => '<=',
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

                    $placeholder = $basePlaceholder . '_' . $index;
                    $parameters[$placeholder] = $value;
                    $placeholders[] = $placeholder;
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

        if (isset($payload['select2'])) {
            $this->config['select2'] = (bool) $payload['select2'];
        }

        if (array_key_exists('numbers_enabled', $payload)) {
            $this->config['numbers_enabled'] = (bool) $payload['numbers_enabled'];
        }

        if (isset($payload['table_meta']) && is_array($payload['table_meta'])) {
            $meta = $payload['table_meta'];
            $title = null;
            if (isset($meta['title']) && is_string($meta['title'])) {
                $title = $meta['title'];
            } elseif (isset($meta['name']) && is_string($meta['name'])) {
                $title = $meta['name'];
            }

            $this->config['table_meta'] = [
                'title'   => $title,
                'tooltip' => isset($meta['tooltip']) && is_string($meta['tooltip']) ? $meta['tooltip'] : null,
                'icon'    => isset($meta['icon']) && is_string($meta['icon']) ? $meta['icon'] : null,
                'hide_title' => isset($meta['hide_title'])
                    ? (bool) $meta['hide_title']
                    : CrudConfig::$hide_table_title,
                'add'     => isset($meta['add']) ? (bool) $meta['add'] : true,
                'view'    => isset($meta['view']) ? (bool) $meta['view'] : true,
                'view_condition' => isset($meta['view_condition']) && is_array($meta['view_condition'])
                    ? $meta['view_condition']
                    : null,
                'edit'    => isset($meta['edit']) ? (bool) $meta['edit'] : true,
                'edit_condition' => isset($meta['edit_condition']) && is_array($meta['edit_condition'])
                    ? $meta['edit_condition']
                    : null,
                'delete'  => isset($meta['delete']) ? (bool) $meta['delete'] : true,
                'delete_condition' => isset($meta['delete_condition']) && is_array($meta['delete_condition'])
                    ? $meta['delete_condition']
                    : null,
                'duplicate' => isset($meta['duplicate']) ? (bool) $meta['duplicate'] : false,
                'duplicate_condition' => isset($meta['duplicate_condition']) && is_array($meta['duplicate_condition'])
                    ? $meta['duplicate_condition']
                    : null,
                'batch_delete' => isset($meta['batch_delete']) ? (bool) $meta['batch_delete'] : false,
                'batch_delete_button' => isset($meta['batch_delete']) ? (bool) $meta['batch_delete'] : false,
                'delete_confirm' => isset($meta['delete_confirm']) ? (bool) $meta['delete_confirm'] : true,
                'export_csv' => isset($meta['export_csv']) ? (bool) $meta['export_csv'] : false,
                'export_excel' => isset($meta['export_excel']) ? (bool) $meta['export_excel'] : false,
                'bulk_actions' => [],
            ];

            if (isset($meta['bulk_actions']) && is_array($meta['bulk_actions'])) {
                $bulkActions = [];
                foreach ($meta['bulk_actions'] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $name = isset($entry['name']) ? (string) $entry['name'] : '';
                    $label = isset($entry['label']) ? (string) $entry['label'] : $name;

                    $options = $entry;
                    unset($options['name'], $options['label']);

                    $bulkActions[] = $this->normalizeBulkActionDefinition($name, $label, $options);
                }

                $this->config['table_meta']['bulk_actions'] = $bulkActions;
            }
        }

        if (array_key_exists('link_buttons', $payload)) {
            $linkButtonsPayload = $payload['link_buttons'];
            $normalizedButtons = [];
            if (is_array($linkButtonsPayload)) {
                foreach ($linkButtonsPayload as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $normalized = $this->normalizeLinkButtonConfigPayload($entry);
                    if ($normalized !== null) {
                        $normalizedButtons[] = $normalized;
                    }
                }
            }
            $this->config['link_buttons'] = $normalizedButtons;
        } elseif (array_key_exists('link_button', $payload)) {
            $linkConfig = $payload['link_button'];
            if (is_array($linkConfig)) {
                $normalizedLink = $this->normalizeLinkButtonConfigPayload($linkConfig);
                $this->config['link_buttons'] = $normalizedLink !== null ? [$normalizedLink] : [];
            } elseif ($linkConfig === null) {
                $this->config['link_buttons'] = [];
            }
        }

        if (array_key_exists('multi_link_buttons', $payload)) {
            $multiPayload = $payload['multi_link_buttons'];
            $normalizedMultiButtons = [];
            if (is_array($multiPayload)) {
                foreach ($multiPayload as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $normalized = $this->normalizeMultiLinkButtonConfigPayload($entry);
                    if ($normalized !== null) {
                        $normalizedMultiButtons[] = $normalized;
                    }
                }
            }
            $this->config['multi_link_buttons'] = $normalizedMultiButtons;
        } elseif (array_key_exists('multi_link_button', $payload)) {
            $multiLinkConfig = $payload['multi_link_button'];
            if (is_array($multiLinkConfig)) {
                $normalizedMulti = $this->normalizeMultiLinkButtonConfigPayload($multiLinkConfig);
                $this->config['multi_link_buttons'] = $normalizedMulti !== null ? [$normalizedMulti] : [];
            } elseif ($multiLinkConfig === null) {
                $this->config['multi_link_buttons'] = [];
            }
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

                if (!isset($this->config['form']['all_columns']) || !is_array($this->config['form']['all_columns'])) {
                    $this->config['form']['all_columns'] = [];
                }

                foreach (array_keys($custom) as $fieldName) {
                    if (!in_array($fieldName, $this->config['form']['all_columns'], true)) {
                        $this->config['form']['all_columns'][] = $fieldName;
                    }
                }
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

                $parentRaw = isset($entry['parent_column']) ? trim((string) $entry['parent_column']) : '';
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
                    'name'               => $name,
                    'parent_column'      => $normalizedParent,
                    'parent_column_raw'  => isset($entry['parent_column_raw']) && is_string($entry['parent_column_raw'])
                        ? trim($entry['parent_column_raw'])
                        : $parentRaw,
                    'foreign_column'     => $foreignColumn,
                    'crud'               => $child,
                ];
            }
        }

        $orderByConfig = $this->config['order_by'];
        if (!is_array($orderByConfig)) {
            $orderByConfig = [];
        }
        $this->config['order_by'] = $this->sanitizeOrderByEntries($orderByConfig);

        $this->applyQueryBuilderPayload($payload['query_builder'] ?? null);

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
            $rows[$index]['__fastcrud_primary_key'] = $primaryKey;
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

        $primaryKeyColumn = $this->getPrimaryKeyColumn();
        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);

        $readonly = $this->gatherBehaviourForMode('readonly', 'create');
        $disabled = $this->gatherBehaviourForMode('disabled', 'create');

        $filtered = [];
        foreach ($fields as $column => $value) {
            if (!is_string($column)) {
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

        $context = array_merge($fields, $filtered);

        $passDefaults = $this->gatherBehaviourForMode('pass_default', 'create');
        foreach ($passDefaults as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $needsDefault = !array_key_exists($column, $filtered)
                || $filtered[$column] === null
                || $filtered[$column] === '';

            if ($needsDefault) {
                $filtered[$column] = $this->renderTemplateValue($value, $context);
                $context[$column] = $filtered[$column];
            }
        }

        $passVars = $this->gatherBehaviourForMode('pass_var', 'create');
        foreach ($passVars as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $filtered[$column] = $this->renderTemplateValue($value, $context);
            $context[$column] = $filtered[$column];
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
        $context = array_merge($context, $filtered);

        $errors = [];

        $required = $this->gatherBehaviourForMode('validation_required', 'create');
        foreach ($required as $column => $minLength) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $value = $filtered[$column] ?? null;
            $length = 0;
            if ($value !== null) {
                if (is_string($value)) {
                    $normalized = trim($value);
                    $length = function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized);
                } elseif (is_numeric($value)) {
                    $stringValue = (string) $value;
                    $length = function_exists('mb_strlen') ? mb_strlen($stringValue) : strlen($stringValue);
                } elseif (is_bool($value)) {
                    $length = 1;
                }
            }

            if ($length < (int) $minLength) {
                $errors[$column] = 'This field is required.';
            }
        }

        $patterns = $this->gatherBehaviourForMode('validation_pattern', 'create');
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

        $uniqueRules = $this->gatherBehaviourForMode('unique', 'create');
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

        $columnsList = array_keys($filtered);
        $placeholders = [];
        $parameters = [];
        foreach ($filtered as $column => $value) {
            $placeholder = ':col_' . $column;
            $placeholders[] = $placeholder;
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
                    $row = $this->findRowByPrimaryKey($primaryKeyColumn, $newPk);
                }
            } catch (PDOException) {
                // ignore and fall back below
            }
        }

        if ($row === null) {
            try {
                $sql = sprintf('SELECT * FROM %s ORDER BY %s DESC LIMIT 1', $this->table, $primaryKeySql);
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

        if ($row !== null) {
            $afterContext['row'] = $row;
            $after = $this->dispatchLifecycleEvent('after_insert', $row, $afterContext, true);
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
        $columns = $this->getTableColumnsFor($this->table);
        if (!in_array($primaryKeyColumn, $columns, true)) {
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

        $filtered = [];
        $payloadColumns = [];
        foreach ($fields as $column => $value) {
            if (!is_string($column)) {
                continue;
            }

            $payloadColumns[$column] = true;

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
                $context[$column] = $filtered[$column];
            }
        }

        $passVars = $this->gatherBehaviourForMode('pass_var', $mode);
        foreach ($passVars as $column => $value) {
            if (!in_array($column, $columns, true)) {
                continue;
            }

            $filtered[$column] = $this->renderTemplateValue($value, $context);
            $context[$column] = $filtered[$column];
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
            if (!in_array($column, $columns, true)) {
                continue;
            }

            if (!array_key_exists($column, $filtered) && !isset($payloadColumns[$column])) {
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
                } elseif (is_bool($value)) {
                    $length = 1;
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

        if ($row !== null && !$this->isActionAllowedForRow('view', $row)) {
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

        if ($row !== null) {
            $afterContext['row'] = $row;
            $after = $this->dispatchLifecycleEvent('after_update', $row, $afterContext, true);
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
        $columns = $this->getTableColumnsFor($this->table);
        if (!in_array($primaryKeyColumn, $columns, true)) {
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
        $useSoftDelete = $softDeleteAssignments !== [];

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

        $parameters = [':pk' => $primaryKeyValue];
        $resolvedValues = [];

        if ($useSoftDelete) {
            $updateClause = $this->buildSoftDeleteUpdateClause($softDeleteAssignments, $parameters, 'sd_single', $resolvedValues);
            $sql = sprintf('UPDATE %s SET %s WHERE %s = :pk', $this->table, $updateClause, $primaryKeySql);
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

        if ($useSoftDelete && !$deleted) {
            $postRow = $this->findRowByPrimaryKey($primaryKeyColumn, $primaryKeyValue);
            if ($this->softDeleteAssignmentsSatisfied($postRow, $softDeleteAssignments, $resolvedValues)) {
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

        $columns = $this->getTableColumnsFor($this->table);
        if (!in_array($primaryKeyColumn, $columns, true)) {
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
        $useSoftDelete = $softDeleteAssignments !== [];

        $initialRows = $this->fetchRowsByPrimaryKeys($primaryKeyColumn, $normalizedValues);

        $targets = [];
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
        $parameters = [];
        foreach ($targets as $index => $target) {
            $placeholder = ':pk_' . $index;
            $placeholders[$index] = $placeholder;
            $parameters[$placeholder] = $target['value'];
        }

        $resolvedValues = [];
        $statement = null;
        $manageTransaction = !$this->connection->inTransaction();

        if ($manageTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            if ($useSoftDelete) {
                $updateClause = $this->buildSoftDeleteUpdateClause($softDeleteAssignments, $parameters, 'sd_batch', $resolvedValues);
                $sql = sprintf(
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
                $lookupKey = $this->normalizePrimaryKeyLookupKey($target['value']);
                $row = $refetched[$lookupKey] ?? null;
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
                $lookupKey = $this->normalizePrimaryKeyLookupKey($target['value']);
                $stillExists = isset($remaining[$lookupKey]);
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

        $columns = $this->getTableColumnsFor($this->table);
        if (!in_array($primaryKeyColumn, $columns, true)) {
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

            if (!in_array($normalizedColumn, $columns, true)) {
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
        $failures = [];

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

        $primaryValue = null;
        $row = null;

        try {
            $newPk = $this->connection->lastInsertId();
            if (is_string($newPk) && $newPk !== '' && $newPk !== '0') {
                $primaryValue = $newPk;
                $row = $this->findRowByPrimaryKey($primaryKeyColumn, $newPk);
            }
        } catch (PDOException) {
            // ignore, fallback below
        }

        if ($row === null) {
            try {
                $sql = sprintf('SELECT * FROM %s ORDER BY %s DESC LIMIT 1', $this->table, $primaryKeySql);
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

        if ($row !== null) {
            $afterContext['row'] = $row;
            $after = $this->dispatchLifecycleEvent('after_insert', $row, $afterContext, true);
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
    private function findRowByPrimaryKey(string $primaryKeyColumn, mixed $primaryKeyValue, string $mode = 'edit'): ?array
    {
        $primaryKeySql = $this->quotePrimaryKeyColumnName($primaryKeyColumn);
        $sql       = sprintf('SELECT * FROM %s WHERE %s = :pk LIMIT 1', $this->table, $primaryKeySql);
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

        if (!is_array($row)) {
            return null;
        }

        $primaryKey = $this->getPrimaryKeyColumn();
        $row['__fastcrud_primary_key'] = $primaryKey;
        $row['__fastcrud_primary_value'] = $row[$primaryKey] ?? null;

        $resolvedMode = $this->normalizeRenderMode($mode) ?? 'edit';

        $row = $this->applyFieldCallbacksToRow($row, $resolvedMode);
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
        $columns = $this->getTableColumnsFor($this->table);
        if (!in_array($primaryKeyColumn, $columns, true)) {
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

        if (!in_array($primaryKeyColumn, $columns, true)) {
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
        }

        return $row;
    }

    /**
     * Generate jQuery AJAX script for loading table data with pagination.
     */
    private function generateAjaxScript(): string
    {
        $id = $this->escapeHtml($this->id);

        $styles = $this->getStyleDefaults();
        $editViewRowClass = trim($styles['edit_view_row_highlight_class'] ?? '');
        if ($editViewRowClass === '') {
            $editViewRowClass = 'table-warning';
        }
        $styles['edit_view_row_highlight_class'] = $editViewRowClass;

        $styleJson = json_encode(
            $styles,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
        if (!is_string($styleJson)) {
            $styleJson = '{}';
        }

        $select2ThemeCss = <<<'CSS'
:root, [data-bs-theme=light]{
  --fastcrud-select2-bg:var(--bs-body-bg,#fff);
  --fastcrud-select2-border:var(--bs-border-color,#ced4da);
  --fastcrud-select2-text:var(--bs-body-color,#212529);
  --fastcrud-select2-placeholder:var(--bs-secondary-color,rgba(108,117,125,0.75));
  --fastcrud-select2-dropdown-bg:var(--bs-tertiary-bg,var(--bs-body-bg,#fff));
  --fastcrud-select2-dropdown-text:var(--bs-body-color,#212529);
  --fastcrud-select2-highlight-bg:var(--bs-primary,#0d6efd);
  --fastcrud-select2-highlight-text:var(--bs-primary-contrast,#fff);
  --fastcrud-select2-selected-bg:rgba(var(--bs-primary-rgb,13,110,253),0.12);
  --fastcrud-select2-selected-text:var(--fastcrud-select2-highlight-bg);
  --fastcrud-select2-chip-bg:var(--bs-tertiary-bg,#f8f9fa);
  --fastcrud-select2-chip-text:var(--bs-body-color,#212529);
  --fastcrud-select2-chip-border:var(--fastcrud-select2-border);
  --fastcrud-select2-disabled-bg:var(--bs-secondary-bg,#e9ecef);
}
[data-bs-theme=dark]{
  --fastcrud-select2-bg:var(--bs-body-bg,#212529);
  --fastcrud-select2-border:var(--bs-border-color,#495057);
  --fastcrud-select2-text:var(--bs-body-color,#f8f9fa);
  --fastcrud-select2-placeholder:var(--bs-secondary-color,#adb5bd);
  --fastcrud-select2-dropdown-bg:var(--bs-tertiary-bg,var(--bs-body-bg,#2b3035));
  --fastcrud-select2-dropdown-text:var(--bs-body-color,#f8f9fa);
  --fastcrud-select2-highlight-bg:var(--bs-primary,#4dabf7);
  --fastcrud-select2-highlight-text:var(--bs-primary-contrast,#fff);
  --fastcrud-select2-selected-bg:rgba(var(--bs-primary-rgb,13,110,253),0.35);
  --fastcrud-select2-selected-text:var(--fastcrud-select2-highlight-text);
  --fastcrud-select2-chip-bg:rgba(255,255,255,0.08);
  --fastcrud-select2-chip-text:var(--bs-body-color,#f8f9fa);
  --fastcrud-select2-chip-border:rgba(255,255,255,0.15);
  --fastcrud-select2-disabled-bg:rgba(255,255,255,0.06);
}
.select2-container--default .select2-selection--single,
.select2-container--default .select2-selection--multiple{
  background-color:var(--fastcrud-select2-bg);
  border:1px solid var(--fastcrud-select2-border);
  color:var(--fastcrud-select2-text);
  border-radius:var(--bs-border-radius,0.375rem);
  transition:border-color .15s ease-in-out, box-shadow .15s ease-in-out;
}
.select2-container .select2-selection--single{
  min-height:calc(2.5rem + 2px);
  display:flex;
  align-items:center;
  padding:0.375rem 2rem 0.375rem 0.75rem;
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
  color:var(--fastcrud-select2-text);
  line-height:1.5;
  padding:0;
}
.select2-container--default .select2-selection--single .select2-selection__placeholder{
  color:var(--fastcrud-select2-placeholder);
}
.select2-container--default .select2-selection--single .select2-selection__arrow{
  height:100%;
  right:0.75rem;
}
.select2-container--default .select2-selection--single .select2-selection__arrow b{
  border-color:var(--fastcrud-select2-placeholder) transparent transparent transparent;
}
.select2-container--default .select2-selection--multiple{
  min-height:calc(2.5rem + 2px);
  padding:0.25rem 0.5rem;
}
.select2-container--default .select2-selection--multiple .select2-selection__rendered{
  display:flex;
  flex-wrap:wrap;
  gap:0.35rem;
  margin:0;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice{
  background-color:var(--fastcrud-select2-chip-bg);
  border:1px solid var(--fastcrud-select2-chip-border);
  color:var(--fastcrud-select2-chip-text);
  border-radius:var(--bs-border-radius-sm,0.25rem);
  padding:0.1rem 0.5rem;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{
  color:var(--fastcrud-select2-chip-text);
  margin-right:0.35rem;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover{
  color:var(--fastcrud-select2-highlight-bg);
}
.select2-container--default.select2-container--disabled .select2-selection--single,
.select2-container--default.select2-container--disabled .select2-selection--multiple{
  background-color:var(--fastcrud-select2-disabled-bg);
  opacity:0.75;
}
.select2-container--default .select2-dropdown{
  background-color:var(--fastcrud-select2-dropdown-bg);
  border:1px solid var(--fastcrud-select2-border);
  color:var(--fastcrud-select2-dropdown-text);
  border-radius:var(--bs-border-radius,0.375rem);
  box-shadow:0 0.5rem 1rem rgba(15,23,42,0.15);
}
.select2-container--default .select2-results__option{
  color:var(--fastcrud-select2-dropdown-text);
}
.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable{
  background-color:var(--fastcrud-select2-highlight-bg);
  color:var(--fastcrud-select2-highlight-text);
}
.select2-container--default .select2-results__option[aria-selected=true]:not(.select2-results__option--highlighted),
.select2-container--default .select2-results__option--selected:not(.select2-results__option--highlighted){
  background-color:var(--fastcrud-select2-selected-bg);
  color:var(--fastcrud-select2-selected-text);
}
.select2-search--dropdown .select2-search__field{
  background-color:var(--fastcrud-select2-bg);
  color:var(--fastcrud-select2-text);
  border:1px solid var(--fastcrud-select2-border);
  border-radius:var(--bs-border-radius,0.375rem);
}
.select2-search--dropdown .select2-search__field::placeholder{
  color:var(--fastcrud-select2-placeholder);
  opacity:0.75;
}
.select2-container--default.select2-container--focus .select2-selection--single,
.select2-container--default.select2-container--focus .select2-selection--multiple,
select:focus + .select2-container--default .select2-selection--single,
select:focus + .select2-container--default .select2-selection--multiple{
  border-color:var(--bs-primary,#0d6efd);
  box-shadow:0 0 0 0.25rem rgba(var(--bs-primary-rgb,13,110,253),0.25);
}
select.is-invalid + .select2-container--default .select2-selection--single,
select.is-invalid + .select2-container--default .select2-selection--multiple,
select.was-validated:invalid + .select2-container--default .select2-selection--single,
select.was-validated:invalid + .select2-container--default .select2-selection--multiple{
  border-color:var(--bs-form-invalid-border-color,#dc3545);
  box-shadow:0 0 0 0.25rem rgba(var(--bs-danger-rgb,220,53,69),0.25);
}
select.is-valid + .select2-container--default .select2-selection--single,
select.is-valid + .select2-container--default .select2-selection--multiple,
select.was-validated:valid + .select2-container--default .select2-selection--single,
select.was-validated:valid + .select2-container--default .select2-selection--multiple{
  border-color:var(--bs-form-valid-border-color,#198754);
  box-shadow:0 0 0 0.25rem rgba(var(--bs-success-rgb,25,135,84),0.25);
}
select.form-select-sm + .select2-container .select2-selection--single{
  min-height:calc(2.25rem + 2px);
  padding:0.25rem 1.75rem 0.25rem 0.5rem;
  font-size:0.875rem;
}
select.form-select-sm + .select2-container .select2-selection--multiple{
  min-height:calc(2.25rem + 2px);
  padding:0.2rem 0.4rem;
  font-size:0.875rem;
}
select.form-select-lg + .select2-container .select2-selection--single{
  min-height:calc(3rem + 2px);
  padding:0.5rem 2.5rem 0.5rem 1rem;
  font-size:1.25rem;
}
select.form-select-lg + .select2-container .select2-selection--multiple{
  min-height:calc(3rem + 2px);
  padding:0.4rem 0.75rem;
  font-size:1.25rem;
}
.select2-dropdown .select2-results__options::-webkit-scrollbar{width:0.5rem;}
.select2-dropdown .select2-results__options::-webkit-scrollbar-thumb{background-color:rgba(var(--bs-primary-rgb,13,110,253),0.35);border-radius:1rem;}
.select2-dropdown .select2-results__options::-webkit-scrollbar-track{background-color:rgba(0,0,0,0.05);}
[data-bs-theme=dark] .select2-dropdown .select2-results__options::-webkit-scrollbar-thumb{background-color:rgba(255,255,255,0.25);}
[data-bs-theme=dark] .select2-dropdown .select2-results__options::-webkit-scrollbar-track{background-color:rgba(255,255,255,0.08);}
CSS;
        $select2ThemeCss = trim($select2ThemeCss);
        $select2ThemeCssJson = json_encode(
            $select2ThemeCss,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
        if (!is_string($select2ThemeCssJson)) {
            $select2ThemeCssJson = '""';
        }

        return <<<SCRIPT
<script>
(function() {
    function FastCrudInit($) {
        $(document).ready(function() {
        function deepClone(value) {
            if (value === null || typeof value === 'undefined') {
                return value;
            }

            try {
                return JSON.parse(JSON.stringify(value));
            } catch (error) {
                if (Array.isArray(value)) {
                    return $.extend(true, [], value);
                }
                if (value && typeof value === 'object') {
                    return $.extend(true, {}, value);
                }
            }

            return value;
        }

        function extractAjaxErrorMessage(jqXHR, fallback) {
            if (jqXHR && jqXHR.responseJSON && typeof jqXHR.responseJSON.error === 'string') {
                var jsonError = jqXHR.responseJSON.error.trim();
                if (jsonError) {
                    return jsonError;
                }
            }
            if (jqXHR && typeof jqXHR.responseText === 'string') {
                var raw = jqXHR.responseText.trim();
                if (raw) {
                    try {
                        var parsed = JSON.parse(raw);
                        if (parsed && typeof parsed.error === 'string') {
                            var parsedError = parsed.error.trim();
                            if (parsedError) {
                                return parsedError;
                            }
                        }
                    } catch (ignored) {
                    }
                    var textContent = $('<div>').html(raw).text().trim();
                    if (textContent) {
                        return textContent;
                    }
                    return raw;
                }
            }
            if (typeof fallback === 'string' && fallback.trim() !== '') {
                return fallback;
            }
            return 'Failed to load data';
        }

        var tableId = '$id';
        var styleDefaults = {$styleJson};
        var editViewHighlightClass = getStyleClass('edit_view_row_highlight_class', 'table-warning');
        var dismissIconClass = getStyleClass('x_icon_class', 'fas fa-xmark');
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
        var debugEnabled = !!(clientConfig && clientConfig.debug);

        function toggleActionsCellZIndex(element, isOpen) {
            var cellEl = $(element).closest('td.fastcrud-actions-cell');
            if (!cellEl.length) {
                cellEl = $(element).closest('.fastcrud-actions-cell');
            }
            if (!cellEl.length) {
                return;
            }
            cellEl.toggleClass('fastcrud-actions-open', !!isOpen);
        }

        table.on('shown.bs.dropdown', '.fastcrud-multi-link-trigger, .fastcrud-multi-link-btn', function() {
            toggleActionsCellZIndex(this, true);
        });

        table.on('hidden.bs.dropdown', '.fastcrud-multi-link-trigger, .fastcrud-multi-link-btn', function() {
            toggleActionsCellZIndex(this, false);
        });
        var select2Enabled = !!(clientConfig && clientConfig.select2);
        var filtersEnabled = true;
        if (clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'filters_enabled')) {
            filtersEnabled = !!clientConfig.filters_enabled;
        }
        var numbersEnabled = !!(clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'numbers_enabled')
            ? clientConfig.numbers_enabled
            : false);
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
        var nestedTablesConfig = Array.isArray(clientConfig.nested_tables) ? clientConfig.nested_tables : [];
        var nestedRowStates = {};
        var orderBy = [];
        var queryBuilderConfig = clientConfig.query_builder && typeof clientConfig.query_builder === 'object'
            ? deepClone(clientConfig.query_builder)
            : {};
        var queryBuilderFields = Array.isArray(queryBuilderConfig.fields) ? deepClone(queryBuilderConfig.fields) : [];
        var queryBuilderOperators = Array.isArray(queryBuilderConfig.operators) ? deepClone(queryBuilderConfig.operators) : [];
        var queryBuilderFieldMap = {};
        queryBuilderFields.forEach(function(field) {
            if (!field || typeof field !== 'object') {
                return;
            }
            var id = field.id || field.field;
            if (!id) {
                return;
            }
            var key = String(id);
            var normalizedField = $.extend(true, {}, field);
            normalizedField.id = key;
            normalizedField.field = key;
            if (typeof normalizedField.options === 'object' && !Array.isArray(normalizedField.options)) {
                var optionsArray = [];
                Object.keys(normalizedField.options).forEach(function(optionKey) {
                    optionsArray.push({
                        value: optionKey,
                        label: normalizedField.options[optionKey]
                    });
                });
                normalizedField.options = optionsArray;
            }
            queryBuilderFieldMap[key] = normalizedField;
        });
        var queryBuilderOperatorMap = {};
        queryBuilderOperators.forEach(function(operator) {
            if (!operator || typeof operator !== 'object' || !operator.value) {
                return;
            }
            queryBuilderOperatorMap[String(operator.value)] = operator;
        });
        var queryBuilderState = {
            filters: [],
            logic: queryBuilderConfig.logic === 'OR' ? 'OR' : 'AND',
            sorts: Array.isArray(queryBuilderConfig.sorts) ? deepClone(queryBuilderConfig.sorts) : [],
            activeView: queryBuilderConfig.active_view || null,
            activeViewDirty: false
        };
        if (Array.isArray(queryBuilderConfig.filters)) {
            queryBuilderState.filters = queryBuilderConfig.filters.map(function(filter) {
                var field = filter && typeof filter.field === 'string' ? filter.field : '';
                var operator = filter && typeof filter.operator === 'string' ? filter.operator : 'equals';
                var rawValue = '';
                if (filter && Array.isArray(filter.value)) {
                    rawValue = filter.value.join(', ');
                } else if (filter && filter.value !== null && typeof filter.value !== 'undefined') {
                    rawValue = String(filter.value);
                }
                return {
                    field: field,
                    operator: operator,
                    value: rawValue
                };
            });
        }
        var queryBuilderModal = null;
        var queryBuilderFiltersContainer = null;
        var queryBuilderSortsContainer = null;
        var queryBuilderLogicSelect = null;
        var filtersButton = null;
        var filtersButtonBadge = null;
        var viewSelect = null;
        var deleteViewButton = null;
        var viewStorageNamespace = 'fastcrud:views:';
        var storageKeyAttr = container.attr('data-fastcrud-view-storage-key');
        var viewStorageKey = viewStorageNamespace + (storageKeyAttr && storageKeyAttr.length ? storageKeyAttr : tableId);
        var savedViews = [];

        function getFieldInfo(fieldId) {
            if (!fieldId && fieldId !== 0) {
                return null;
            }
            return queryBuilderFieldMap[String(fieldId)] || null;
        }

        function getOperatorInfo(operator) {
            if (!operator) {
                return null;
            }
            return queryBuilderOperatorMap[String(operator)] || null;
        }

        function getOperatorsForField(fieldId) {
            var info = getFieldInfo(fieldId);
            var type = info && info.type ? String(info.type) : 'string';
            var allowed;
            switch (type) {
                case 'number':
                case 'date':
                case 'datetime':
                case 'time':
                    allowed = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty'];
                    break;
                case 'boolean':
                    allowed = ['equals', 'not_equals', 'in', 'not_in', 'empty', 'not_empty'];
                    break;
                default:
                    allowed = ['contains', 'not_contains', 'equals', 'not_equals', 'in', 'not_in', 'empty', 'not_empty'];
                    break;
            }

            var filtered = [];
            allowed.forEach(function(op) {
                if (getOperatorInfo(op)) {
                    if (filtered.indexOf(op) === -1) {
                        filtered.push(op);
                    }
                }
            });

            if (filtered.length === 0) {
                filtered.push('equals');
            }

            return filtered;
        }

        function convertValueForType(rawValue, type) {
            if (rawValue === null || typeof rawValue === 'undefined') {
                return null;
            }

            if (typeof rawValue !== 'string') {
                rawValue = String(rawValue);
            }

            var trimmed = rawValue.trim();

            if (trimmed === '') {
                return '';
            }

            switch (type) {
                case 'number':
                    if (trimmed === '' || isNaN(Number(trimmed))) {
                        return null;
                    }
                    return Number(trimmed);
                case 'boolean':
                    var lower = trimmed.toLowerCase();
                    if (['1', 'true', 'yes', 'y', 'on'].indexOf(lower) !== -1) {
                        return 1;
                    }
                    if (['0', 'false', 'no', 'n', 'off'].indexOf(lower) !== -1) {
                        return 0;
                    }
                    return null;
                default:
                    return trimmed;
            }
        }

        function convertValuesForType(rawList, type) {
            if (!Array.isArray(rawList)) {
                return [];
            }

            var results = [];
            rawList.forEach(function(item) {
                var converted = convertValueForType(item, type);
                if (converted === null) {
                    return;
                }
                if (typeof converted === 'string' && converted === '') {
                    return;
                }
                results.push(converted);
            });

            return results;
        }

        function buildFilterPayloadForState(filter) {
            if (!filter || typeof filter !== 'object') {
                return null;
            }

            var field = filter.field ? String(filter.field) : '';
            if (!field) {
                return null;
            }

            var operator = filter.operator ? String(filter.operator) : 'equals';
            if (!getOperatorInfo(operator)) {
                operator = 'equals';
            }

            var fieldInfo = getFieldInfo(field) || {};
            var type = fieldInfo.type || 'string';
            var requiresValue = !(operator === 'empty' || operator === 'not_empty');
            var multi = operator === 'in' || operator === 'not_in';

            if (!requiresValue) {
                return {
                    field: field,
                    operator: operator
                };
            }

            var rawValue = filter.value;
            if (typeof rawValue !== 'string') {
                rawValue = rawValue === null || typeof rawValue === 'undefined' ? '' : String(rawValue);
            }

            if (multi) {
                var parts = rawValue.split(',').map(function(piece) {
                    return piece.trim();
                }).filter(function(piece) {
                    return piece.length > 0;
                });

                var convertedList = convertValuesForType(parts, type);
                if (!convertedList.length) {
                    return null;
                }

                return {
                    field: field,
                    operator: operator,
                    value: convertedList
                };
            }

            var converted = convertValueForType(rawValue, type);
            if (converted === null) {
                return null;
            }

            if (typeof converted === 'string' && converted === '') {
                return null;
            }

            return {
                field: field,
                operator: operator,
                value: converted
            };
        }

        function createDefaultFilter() {
            var firstField = queryBuilderFields.length ? String(queryBuilderFields[0].id || queryBuilderFields[0].field) : '';
            return {
                field: firstField,
                operator: 'equals',
                value: ''
            };
        }

        function createDefaultSort() {
            var firstField = queryBuilderFields.length ? String(queryBuilderFields[0].id || queryBuilderFields[0].field) : '';
            return {
                field: firstField,
                direction: 'ASC'
            };
        }

        function loadSavedViews() {
            if (!window.localStorage) {
                return [];
            }

            try {
                var raw = window.localStorage.getItem(viewStorageKey);
                if (!raw) {
                    return [];
                }
                var parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        }

        function persistSavedViews(views) {
            if (!window.localStorage) {
                return;
            }

            try {
                window.localStorage.setItem(viewStorageKey, JSON.stringify(views));
            } catch (error) {
                if (window.console && console.warn) {
                    console.warn('FastCrud: failed to persist saved views', error);
                }
            }
        }

        function updateViewDeleteState() {
            if (!deleteViewButton) {
                return;
            }

            if (!viewSelect || !viewSelect.val()) {
                deleteViewButton.prop('disabled', true);
            } else {
                deleteViewButton.prop('disabled', false);
            }
        }

        function renderSavedViewsSelect() {
            if (!viewSelect || !viewSelect.length) {
                return;
            }

            var current = viewSelect.val() || '';
            viewSelect.empty();
            viewSelect.append('<option value="">Default view</option>');
            savedViews.forEach(function(view) {
                if (!view || typeof view.name !== 'string') {
                    return;
                }
                var option = $('<option></option>').attr('value', view.name).text(view.name);
                viewSelect.append(option);
            });

            if (current && savedViews.some(function(view) { return view && view.name === current; })) {
                viewSelect.val(current);
            } else if (queryBuilderState.activeView) {
                viewSelect.val(queryBuilderState.activeView);
            } else {
                viewSelect.val('');
            }

            updateViewDeleteState();
        }

        function updateQueryBuilderBadge() {
            if (!filtersButtonBadge) {
                return;
            }

            var count = queryBuilderState.filters.filter(function(filter) {
                return filter && filter.field;
            }).length;

            if (count > 0) {
                filtersButtonBadge.text(count).removeClass('d-none');
            } else {
                filtersButtonBadge.text('').addClass('d-none');
            }
        }

        function syncSortStateFromOrderBy() {
            queryBuilderState.sorts = deepClone(orderBy || []);
        }

        function syncQueryBuilderToConfig() {
            if (formOnlyMode) {
                return;
            }

            var filtersPayload = [];
            queryBuilderState.filters.forEach(function(filter) {
                var payload = buildFilterPayloadForState(filter);
                if (payload) {
                    filtersPayload.push(payload);
                }
            });

            if (!clientConfig.query_builder || typeof clientConfig.query_builder !== 'object') {
                clientConfig.query_builder = {};
            }

            clientConfig.query_builder.filters = filtersPayload;
            clientConfig.query_builder.logic = queryBuilderState.logic === 'OR' ? 'OR' : 'AND';
            clientConfig.query_builder.sorts = deepClone(orderBy || []);
            if (queryBuilderState.activeViewDirty) {
                clientConfig.query_builder.active_view = null;
            } else {
                clientConfig.query_builder.active_view = queryBuilderState.activeView || null;
            }
        }

        function hydrateQueryBuilderFromMeta(metaQB) {
            if (!metaQB || typeof metaQB !== 'object') {
                return;
            }

            if (Array.isArray(metaQB.fields)) {
                queryBuilderFields = deepClone(metaQB.fields);
                queryBuilderFieldMap = {};
                queryBuilderFields.forEach(function(field) {
                    if (!field || typeof field !== 'object') {
                        return;
                    }
                    var id = field.id || field.field;
                    if (!id) {
                        return;
                    }
                    var key = String(id);
                    var normalizedField = $.extend(true, {}, field);
                    normalizedField.id = key;
                    normalizedField.field = key;
                    if (typeof normalizedField.options === 'object' && !Array.isArray(normalizedField.options)) {
                        var optionsArray = [];
                        Object.keys(normalizedField.options).forEach(function(optionKey) {
                            optionsArray.push({
                                value: optionKey,
                                label: normalizedField.options[optionKey]
                            });
                        });
                        normalizedField.options = optionsArray;
                    }
                    queryBuilderFieldMap[key] = normalizedField;
                });
            }

            if (Array.isArray(metaQB.operators)) {
                queryBuilderOperators = deepClone(metaQB.operators);
                queryBuilderOperatorMap = {};
                queryBuilderOperators.forEach(function(operator) {
                    if (!operator || typeof operator !== 'object' || !operator.value) {
                        return;
                    }
                    queryBuilderOperatorMap[String(operator.value)] = operator;
                });
            }

            if (Array.isArray(metaQB.filters)) {
                queryBuilderState.filters = metaQB.filters.map(function(filter) {
                    var field = filter && typeof filter.field === 'string' ? filter.field : '';
                    var operator = filter && typeof filter.operator === 'string' ? filter.operator : 'equals';
                    var rawValue = '';
                    if (filter && Array.isArray(filter.value)) {
                        rawValue = filter.value.join(', ');
                    } else if (filter && filter.value !== null && typeof filter.value !== 'undefined') {
                        rawValue = String(filter.value);
                    }
                    return {
                        field: field,
                        operator: operator,
                        value: rawValue
                    };
                });
            }

            queryBuilderState.logic = metaQB.logic === 'OR' ? 'OR' : 'AND';

            if (Array.isArray(metaQB.sorts)) {
                queryBuilderState.sorts = deepClone(metaQB.sorts);
                orderBy = deepClone(metaQB.sorts);
                clientConfig.order_by = deepClone(metaQB.sorts);
            }

            if (typeof metaQB.active_view === 'string' && metaQB.active_view.trim() !== '') {
                queryBuilderState.activeView = metaQB.active_view.trim();
            } else {
                queryBuilderState.activeView = null;
            }
            queryBuilderState.activeViewDirty = false;

            renderSavedViewsSelect();
            updateQueryBuilderBadge();
        }

        function ensureQueryBuilderModal() {
            if (queryBuilderModal && queryBuilderModal.length) {
                return;
            }

            queryBuilderModal = $('#' + tableId + '-query-builder');
            if (queryBuilderModal && queryBuilderModal.length && !queryBuilderModal.data('fastcrudPortal')) {
                queryBuilderModal.appendTo(document.body);
                queryBuilderModal.data('fastcrudPortal', 1);
            }
            queryBuilderFiltersContainer = $('#' + tableId + '-qb-filters');
            queryBuilderSortsContainer = $('#' + tableId + '-qb-sorts');
            queryBuilderLogicSelect = $('#' + tableId + '-qb-logic');

            if (queryBuilderModal && queryBuilderModal.length && !queryBuilderModal.data('fastcrudBound')) {
                var applyBtn = $('#' + tableId + '-qb-apply');
                var clearBtn = $('#' + tableId + '-qb-clear');
                var saveBtn = $('#' + tableId + '-qb-save');

                if (applyBtn.length) {
                    applyBtn.on('click', function(event) {
                        event.preventDefault();
                        applyQueryBuilderSelections();
                    });
                }

                if (clearBtn.length) {
                    clearBtn.on('click', function(event) {
                        event.preventDefault();
                        clearQueryBuilderState();
                    });
                }

                if (saveBtn.length) {
                    saveBtn.on('click', function(event) {
                        event.preventDefault();
                        saveCurrentView();
                    });
                }

                if (queryBuilderLogicSelect && queryBuilderLogicSelect.length) {
                    queryBuilderLogicSelect.on('change', function() {
                        var val = $(this).val() === 'OR' ? 'OR' : 'AND';
                        if (queryBuilderState.logic !== val) {
                            queryBuilderState.logic = val;
                            markFiltersDirty();
                        }
                    });
                }

                queryBuilderModal.data('fastcrudBound', 1);
            }
        }

        function renderQueryBuilderFilters() {
            ensureQueryBuilderModal();
            if (!queryBuilderFiltersContainer || !queryBuilderFiltersContainer.length) {
            return;
        }

        if (select2Enabled) {
            destroySelect2(queryBuilderFiltersContainer);
        }

        queryBuilderFiltersContainer.empty();

            if (!queryBuilderFields.length) {
                queryBuilderFiltersContainer.append('<div class="text-muted small">No filterable fields available.</div>');
                return;
            }

            queryBuilderState.filters.forEach(function(filter, index) {
                var currentFilter = filter || { field: '', operator: 'equals', value: '' };
                var row = $('<div class="row g-2 align-items-center mb-2 fastcrud-qb-filter-row"></div>');

                var fieldCol = $('<div class="col-4"></div>');
                var fieldSelect = $('<select class="form-select form-select-sm" data-fastcrud-type="select"></select>');
                queryBuilderFields.forEach(function(field) {
                    if (!field || !field.id) {
                        return;
                    }
                    var option = $('<option></option>').attr('value', field.id).text(field.label || field.id);
                    fieldSelect.append(option);
                });
                fieldSelect.val(currentFilter.field);
                fieldSelect.on('change', function() {
                    var newField = $(this).val() ? String($(this).val()) : '';
                    queryBuilderState.filters[index] = {
                        field: newField,
                        operator: 'equals',
                        value: ''
                    };
                    markFiltersDirty();
                    renderQueryBuilderFilters();
                    updateQueryBuilderBadge();
                });
                fieldCol.append(fieldSelect);
                row.append(fieldCol);

                var operatorCol = $('<div class="col-3"></div>');
                var operatorSelect = $('<select class="form-select form-select-sm"></select>');
                var operatorChoices = getOperatorsForField(currentFilter.field);
                if (operatorChoices.indexOf(currentFilter.operator) === -1) {
                    currentFilter.operator = operatorChoices[0] || 'equals';
                    queryBuilderState.filters[index].operator = currentFilter.operator;
                }
                operatorChoices.forEach(function(op) {
                    var info = getOperatorInfo(op);
                    var label = info && info.label ? info.label : op;
                    operatorSelect.append($('<option></option>').attr('value', op).text(label));
                });
                operatorSelect.val(currentFilter.operator);
                operatorSelect.on('change', function() {
                    var newOperator = $(this).val() ? String($(this).val()) : 'equals';
                    queryBuilderState.filters[index].operator = newOperator;
                    if (newOperator === 'empty' || newOperator === 'not_empty') {
                        queryBuilderState.filters[index].value = '';
                    }
                    markFiltersDirty();
                    renderQueryBuilderFilters();
                    updateQueryBuilderBadge();
                });
                operatorCol.append(operatorSelect);
                row.append(operatorCol);

                var valueCol = $('<div class="col-4"></div>');
                var operatorInfo = getOperatorInfo(currentFilter.operator) || { requires_value: true, multi: false };
                var fieldInfo = getFieldInfo(currentFilter.field) || {};
                var fieldOptions = Array.isArray(fieldInfo.options) ? fieldInfo.options : [];
                var needsValue = operatorInfo.requires_value !== false;
                var optionOperators = ['equals', 'not_equals', 'in', 'not_in'];
                var supportsOptionSelect = needsValue && fieldOptions.length && optionOperators.indexOf(currentFilter.operator) !== -1;
                var valueInput;

                if (!needsValue) {
                    valueInput = $('<input type="text" class="form-control form-control-sm" disabled />');
                } else if (supportsOptionSelect) {
                    valueInput = $('<select class="form-select form-select-sm"></select>');
                    if (operatorInfo.multi) {
                        valueInput.attr('multiple', 'multiple');
                        valueInput.attr('data-fastcrud-type', 'multiselect');
                    } else {
                        valueInput.append('<option value="">Select…</option>');
                        valueInput.attr('data-fastcrud-type', 'select');
                        valueInput.attr('data-placeholder', 'Select…');
                    }

                    fieldOptions.forEach(function(option) {
                        if (!option || typeof option !== 'object') {
                            return;
                        }
                        var opt = $('<option></option>').attr('value', option.value).text(option.label || option.value);
                        valueInput.append(opt);
                    });

                    if (operatorInfo.multi) {
                        var selectedValues = [];
                        if (currentFilter.value && typeof currentFilter.value === 'string') {
                            selectedValues = currentFilter.value.split(',').map(function(piece) {
                                return piece.trim();
                            }).filter(function(piece) {
                                return piece.length > 0;
                            });
                        }
                        valueInput.val(selectedValues);
                    } else {
                        valueInput.val(currentFilter.value || '');
                    }

                    valueInput.on('change', function() {
                        if (operatorInfo.multi) {
                            var selected = $(this).val();
                            if (Array.isArray(selected)) {
                                var joined = selected.map(function(piece) {
                                    return String(piece).trim();
                                }).filter(function(piece) {
                                    return piece.length > 0;
                                }).join(', ');
                                queryBuilderState.filters[index].value = joined;
                            } else {
                                queryBuilderState.filters[index].value = '';
                            }
                        } else {
                            var selectedValue = $(this).val();
                            queryBuilderState.filters[index].value = selectedValue ? String(selectedValue) : '';
                        }
                        markFiltersDirty();
                    });
                } else if (fieldInfo.type === 'boolean') {
                    valueInput = $('<select class="form-select form-select-sm" data-fastcrud-type="select"></select>');
                    valueInput.append('<option value="">Select…</option>');
                    valueInput.append('<option value="1">True</option>');
                    valueInput.append('<option value="0">False</option>');
                    valueInput.val(currentFilter.value || '');
                    valueInput.on('change', function() {
                        queryBuilderState.filters[index].value = $(this).val();
                        markFiltersDirty();
                    });
                } else {
                    valueInput = $('<input type="text" class="form-control form-control-sm" />');
                    if (operatorInfo.multi) {
                        valueInput.attr('placeholder', 'Value 1, Value 2');
                    }
                    valueInput.val(currentFilter.value || '');
                    valueInput.on('input change', function() {
                        queryBuilderState.filters[index].value = $(this).val();
                        markFiltersDirty();
                    });
                }

                if (!needsValue) {
                    valueInput.val('');
                }

                if (currentFilter._invalid) {
                    valueInput.addClass('is-invalid');
                    delete queryBuilderState.filters[index]._invalid;
                }

                valueCol.append(valueInput);
                row.append(valueCol);

                var actionsCol = $('<div class="col-1 text-end"></div>');
                var removeBtn = $('<button type="button" class="btn btn-sm btn-outline-danger" aria-label="Remove filter"></button>');
                removeBtn.append($('<i aria-hidden="true"></i>').addClass(dismissIconClass));
                removeBtn.on('click', function() {
                    queryBuilderState.filters.splice(index, 1);
                    markFiltersDirty();
                    renderQueryBuilderFilters();
                    updateQueryBuilderBadge();
                });
                actionsCol.append(removeBtn);
                row.append(actionsCol);

                queryBuilderFiltersContainer.append(row);
            });

            var addBtnWrapper = $('<div class="mt-2"></div>');
            var addBtn = $('<button type="button" class="btn btn-sm btn-outline-primary">Add Filter</button>');
            addBtn.on('click', function() {
                queryBuilderState.filters.push(createDefaultFilter());
                markFiltersDirty();
                renderQueryBuilderFilters();
                updateQueryBuilderBadge();
            });
            addBtnWrapper.append(addBtn);
            queryBuilderFiltersContainer.append(addBtnWrapper);

            if (select2Enabled) {
                initializeSelect2(queryBuilderFiltersContainer);
            }
        }

        function renderQueryBuilderSorts() {
            ensureQueryBuilderModal();
            if (!queryBuilderSortsContainer || !queryBuilderSortsContainer.length) {
                return;
            }

            if (select2Enabled) {
                destroySelect2(queryBuilderSortsContainer);
            }

            queryBuilderSortsContainer.empty();

            queryBuilderState.sorts.forEach(function(sort, index) {
                var currentSort = sort || { field: '', direction: 'ASC' };
                var row = $('<div class="row g-2 align-items-center mb-2 fastcrud-qb-sort-row"></div>');

                var fieldCol = $('<div class="col-6"></div>');
                var fieldSelect = $('<select class="form-select form-select-sm" data-fastcrud-type="select"></select>');
                queryBuilderFields.forEach(function(field) {
                    if (!field || !field.id) {
                        return;
                    }
                    var option = $('<option></option>').attr('value', field.id).text(field.label || field.id);
                    fieldSelect.append(option);
                });
                fieldSelect.val(currentSort.field);
                fieldSelect.on('change', function() {
                    queryBuilderState.sorts[index].field = $(this).val() ? String($(this).val()) : '';
                    markFiltersDirty();
                });
                fieldCol.append(fieldSelect);
                row.append(fieldCol);

                var directionCol = $('<div class="col-4"></div>');
                var directionSelect = $('<select class="form-select form-select-sm"></select>');
                directionSelect.append('<option value="ASC">Ascending</option>');
                directionSelect.append('<option value="DESC">Descending</option>');
                directionSelect.val((currentSort.direction || 'ASC').toUpperCase() === 'DESC' ? 'DESC' : 'ASC');
                directionSelect.on('change', function() {
                    queryBuilderState.sorts[index].direction = $(this).val() ? String($(this).val()).toUpperCase() : 'ASC';
                    markFiltersDirty();
                });
                directionCol.append(directionSelect);
                row.append(directionCol);

                var actionsCol = $('<div class="col-2 text-end"></div>');
                var removeBtn = $('<button type="button" class="btn btn-sm btn-outline-danger" aria-label="Remove sort"></button>');
                removeBtn.append($('<i aria-hidden="true"></i>').addClass(dismissIconClass));
                removeBtn.on('click', function() {
                    queryBuilderState.sorts.splice(index, 1);
                    markFiltersDirty();
                    renderQueryBuilderSorts();
                });
                actionsCol.append(removeBtn);
                row.append(actionsCol);

                queryBuilderSortsContainer.append(row);
            });

            var addBtnWrapper = $('<div class="mt-2"></div>');
            var addBtn = $('<button type="button" class="btn btn-sm btn-outline-primary">Add Sort</button>');
            addBtn.on('click', function() {
                queryBuilderState.sorts.push(createDefaultSort());
                markFiltersDirty();
                renderQueryBuilderSorts();
            });
            addBtnWrapper.append(addBtn);
            queryBuilderSortsContainer.append(addBtnWrapper);

            if (select2Enabled) {
                initializeSelect2(queryBuilderSortsContainer);
            }
        }

        function refreshQueryBuilderModal() {
            ensureQueryBuilderModal();
            renderQueryBuilderFilters();
            renderQueryBuilderSorts();
            if (queryBuilderLogicSelect && queryBuilderLogicSelect.length) {
                queryBuilderLogicSelect.val(queryBuilderState.logic === 'OR' ? 'OR' : 'AND');
            }
        }

        function openQueryBuilderModal() {
            if (formOnlyMode || !filtersEnabled) {
                return;
            }

            ensureQueryBuilderModal();
            syncSortStateFromOrderBy();
            refreshQueryBuilderModal();

            if (queryBuilderModal && queryBuilderModal.length) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(queryBuilderModal.get(0)).show();
                } else {
                    queryBuilderModal.removeClass('d-none').show();
                }
            }
        }

        function closeQueryBuilderModal() {
            if (queryBuilderModal && queryBuilderModal.length) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var instance = bootstrap.Modal.getInstance(queryBuilderModal.get(0));
                    if (instance) {
                        instance.hide();
                    }
                } else {
                    queryBuilderModal.hide();
                }
            }
        }

        function clearQueryBuilderState() {
            queryBuilderState.filters = [];
            queryBuilderState.logic = 'AND';
            queryBuilderState.sorts = [];
            queryBuilderState.activeView = null;
            queryBuilderState.activeViewDirty = false;
            if (viewSelect) {
                viewSelect.val('');
                updateViewDeleteState();
            }
            refreshQueryBuilderModal();
            updateQueryBuilderBadge();
        }

        function applyQueryBuilderSelections() {
            var validFilters = [];
            var hasInvalid = false;

            queryBuilderState.filters.forEach(function(filter, index) {
                var payload = buildFilterPayloadForState(filter);
                if (!payload) {
                    queryBuilderState.filters[index]._invalid = true;
                    hasInvalid = true;
                } else {
                    validFilters.push(payload);
                }
            });

            if (hasInvalid) {
                refreshQueryBuilderModal();
                updateQueryBuilderBadge();
                alert('Please complete all filter values before applying.');
                return;
            }

            orderBy = deepClone(queryBuilderState.sorts || []);
            clientConfig.order_by = deepClone(orderBy);

            markFiltersDirty();
            syncQueryBuilderToConfig();
            updateQueryBuilderBadge();
            updateSortIndicators();
            closeQueryBuilderModal();
            loadTableData(1);
        }

        function saveCurrentView() {
            var defaultName = '';
            if (typeof queryBuilderState.activeView === 'string') {
                var activeView = queryBuilderState.activeView.trim();
                if (activeView.length) {
                    var exists = savedViews.some(function(view) {
                        return view && view.name === activeView;
                    });
                    if (exists) {
                        defaultName = activeView;
                    }
                }
            }

            var name = window.prompt('Enter a name for this view:', defaultName);
            if (!name) {
                return;
            }
            name = name.trim();
            if (!name.length) {
                return;
            }

            var existingIndex = savedViews.findIndex(function(view) {
                return view && view.name === name;
            });

            var viewDefinition = {
                name: name,
                filters: deepClone(queryBuilderState.filters || []),
                logic: queryBuilderState.logic === 'OR' ? 'OR' : 'AND',
                sorts: deepClone(queryBuilderState.sorts || []),
                searchTerm: currentSearchTerm || '',
                searchColumn: currentSearchColumn || null
            };

            if (existingIndex >= 0) {
                savedViews[existingIndex] = viewDefinition;
            } else {
                savedViews.push(viewDefinition);
            }

            persistSavedViews(savedViews);
            queryBuilderState.activeView = name;
            queryBuilderState.activeViewDirty = false;
            renderSavedViewsSelect();
            updateQueryBuilderBadge();
        }

        function deleteCurrentView() {
            if (!viewSelect) {
                return;
            }
            var name = viewSelect.val();
            if (!name) {
                return;
            }
            if (!window.confirm('Delete view "' + name + '"?')) {
                return;
            }
            savedViews = savedViews.filter(function(view) {
                return view && view.name !== name;
            });
            persistSavedViews(savedViews);
            queryBuilderState.activeView = null;
            queryBuilderState.activeViewDirty = false;
            viewSelect.val('');
            updateViewDeleteState();
            renderSavedViewsSelect();
        }

        function applySavedViewByName(name) {
            if (!name) {
                queryBuilderState.activeView = null;
                queryBuilderState.activeViewDirty = false;
                queryBuilderState.filters = [];
                queryBuilderState.logic = 'AND';
                queryBuilderState.sorts = [];
                currentSearchTerm = '';
                currentSearchColumn = null;
                if (searchInput) {
                    searchInput.val('');
                }
                if (searchSelect) {
                    searchSelect.val('');
                }
                orderBy = [];
                clientConfig.order_by = [];
                syncQueryBuilderToConfig();
                updateSortIndicators();
                updateQueryBuilderBadge();
                loadTableData(1);
                return;
            }

            var view = savedViews.find(function(entry) {
                return entry && entry.name === name;
            });

            if (!view) {
                return;
            }

            queryBuilderState.activeView = view.name;
            queryBuilderState.activeViewDirty = false;
            queryBuilderState.filters = deepClone(view.filters || []);
            queryBuilderState.logic = view.logic === 'OR' ? 'OR' : 'AND';
            queryBuilderState.sorts = deepClone(view.sorts || []);

            if (viewSelect) {
                viewSelect.val(view.name);
                updateViewDeleteState();
            }

            currentSearchTerm = view.searchTerm || '';
            currentSearchColumn = view.searchColumn || null;

            if (searchInput) {
                searchInput.val(currentSearchTerm);
            }

            if (searchSelect) {
                searchSelect.val(currentSearchColumn || '');
            }

            orderBy = deepClone(queryBuilderState.sorts || []);
            clientConfig.order_by = deepClone(orderBy);
            syncQueryBuilderToConfig();
            updateSortIndicators();
            updateQueryBuilderBadge();
            loadTableData(1);
        }

        function markFiltersDirty() {
            if (queryBuilderState.activeView) {
                queryBuilderState.activeViewDirty = true;
            } else {
                queryBuilderState.activeViewDirty = false;
            }

            if (viewSelect) {
                viewSelect.val(queryBuilderState.activeView || '');
                updateViewDeleteState();
            }
        }

        savedViews = loadSavedViews();
        var addEnabled = true;
        var viewEnabled = true;
        var editEnabled = true;
        var deleteEnabled = true;
        var duplicateEnabled = false;
        var deleteConfirm = true;
        var sortDisabled = {};
        var inlineEditFields = {};
        var batchDeleteEnabled = false;
        var batchDeleteButton = null;
        var selectAllCheckbox = null;
        var selectedRows = {};
        var bulkActions = [];
        var allowBatchDeleteButton = false;
        var formConfig = {
            layouts: {},
            default_tabs: {},
            behaviours: {},
            labels: {},
            all_columns: [],
            sections: {},
            templates: {}
        };
        var formTemplates = {};
        var currentFieldErrors = {};
        var lastSubmitAction = null;
        var tableHasRendered = false;
        var activeFetchRequest = null;
        var tableViewportCache = null;

        function ensureLoadingStyles() {
            var styleId = 'fastcrud-loading-style';
            if (document.getElementById(styleId)) {
                return;
            }
            if (!document.head) {
                return;
            }
            var css = [
                '.fastcrud-table-container{position:relative;}',
                '.fastcrud-table-container>table{transition:opacity .18s ease-in-out,filter .18s ease-in-out;}',
                '.fastcrud-table-container.fastcrud-loading-active>table{opacity:0.45;filter:blur(1px);}',
                '.fastcrud-loading-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(255,255,255,0.7);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .18s ease-in-out;z-index:5;}',
                '.fastcrud-loading-overlay.fastcrud-visible{opacity:1;pointer-events:auto;}',
                '.fastcrud-loading-message{display:inline-flex;align-items:center;gap:0.5rem;font-weight:500;color:var(--bs-body-color,#212529);}',
                '.fastcrud-loading-placeholder{vertical-align:middle;}',
                '.fastcrud-loading-placeholder .spinner-border{width:1rem;height:1rem;}',
                '[data-bs-theme=dark] .fastcrud-loading-overlay{background:rgba(15,23,42,0.55);}',
                '@media (prefers-reduced-motion: reduce){.fastcrud-table-container>table{transition:none;filter:none;}.fastcrud-table-container.fastcrud-loading-active>table{opacity:1;}.fastcrud-loading-overlay{transition:none;}}'
            ].join('');
            var styleEl = document.createElement('style');
            styleEl.id = styleId;
            styleEl.type = 'text/css';
            styleEl.appendChild(document.createTextNode(css));
            document.head.appendChild(styleEl);
        }

        function getTableViewport() {
            if (tableViewportCache && tableViewportCache.length) {
                return tableViewportCache;
            }
            var viewport = container.find('.fastcrud-table-container').first();
            if (!viewport.length) {
                viewport = container.find('.table-responsive').first();
            }
            tableViewportCache = viewport;
            return viewport;
        }

        function ensureLoadingOverlay(viewport) {
            var target = viewport && viewport.length ? viewport : getTableViewport();
            if (!target || !target.length) {
                return $();
            }
            var overlay = target.children('.fastcrud-loading-overlay');
            if (!overlay.length) {
                overlay = $('<div class="fastcrud-loading-overlay" aria-live="polite"></div>');
                var message = $('<div class="fastcrud-loading-message"></div>');
                var spinner = $('<span class="spinner-border spinner-border-sm" role="status"></span>');
                spinner.append('<span class="visually-hidden">Loading...</span>');
                var text = $('<span class="fastcrud-loading-text"></span>').text('Loading...');
                message.append(spinner).append(text);
                overlay.append(message);
                target.append(overlay);
            }
            return overlay;
        }

        function updateLoadingMessage(message) {
            var overlay = ensureLoadingOverlay();
            if (!overlay.length) {
                return;
            }
            var text = overlay.find('.fastcrud-loading-text');
            if (!text.length) {
                return;
            }
            text.text(message || 'Loading...');
        }

        function beginLoadingState() {
            var viewport = getTableViewport();
            if (!viewport.length) {
                return;
            }
            var overlay = ensureLoadingOverlay(viewport);
            viewport.addClass('fastcrud-loading-active');
            var raf = window.requestAnimationFrame || function(handler) {
                return window.setTimeout(handler, 16);
            };
            raf(function() {
                overlay.addClass('fastcrud-visible');
            });
        }

        function endLoadingState() {
            var viewport = getTableViewport();
            if (!viewport.length) {
                return;
            }
            var overlay = viewport.children('.fastcrud-loading-overlay');
            overlay.removeClass('fastcrud-visible');
            viewport.removeClass('fastcrud-loading-active');
        }

        ensureLoadingStyles();
        function getFormTemplate(mode) {
            if (!mode) {
                return null;
            }

            var templates = formTemplates && typeof formTemplates === 'object' ? formTemplates : {};
            if (!templates) {
                return null;
            }

            var key = String(mode).toLowerCase();
            if (!Object.prototype.hasOwnProperty.call(templates, key)) {
                return null;
            }

            var template = templates[key];
            if (!template || typeof template !== 'object') {
                return null;
            }

            return deepClone(template);
        }

        function ensureRowColumns(row) {
            var output = row && typeof row === 'object' ? row : {};
            var sourceColumns = baseColumns.length ? baseColumns : columnsCache;
            sourceColumns.forEach(function(column) {
                if (typeof column !== 'string') {
                    return;
                }
                if (!Object.prototype.hasOwnProperty.call(output, column)) {
                    output[column] = null;
                }
            });

            return output;
        }
        // Cache for on-demand row fetches (keyed by tableId + '::' + pkCol + '::' + pkVal)
        var rowCache = {};
        var formOnlyMode = container.attr('data-fastcrud-form-only') === '1';
        var initialMode = String(container.attr('data-fastcrud-initial-mode') || '').toLowerCase();
        if (['create', 'edit', 'view'].indexOf(initialMode) === -1) {
            initialMode = '';
        }
        var initialPrimaryKeyValue = container.attr('data-fastcrud-initial-primary');
        var initialPrimaryKeyColumn = container.attr('data-fastcrud-initial-primary-column') || '';
        if (!primaryKeyColumn && initialPrimaryKeyColumn) {
            primaryKeyColumn = initialPrimaryKeyColumn;
        }
        if (typeof initialPrimaryKeyValue === 'string' && initialPrimaryKeyValue.length) {
            var trimmedInitial = initialPrimaryKeyValue.trim();
            var firstChar = trimmedInitial.charAt(0);
            var lastChar = trimmedInitial.charAt(trimmedInitial.length - 1);
            if ((firstChar === '{' && lastChar === '}') || (firstChar === '[' && lastChar === ']')) {
                try {
                    initialPrimaryKeyValue = JSON.parse(trimmedInitial);
                } catch (error) {
                    // Leave as-is if parsing fails
                }
            }
        }
        if (!primaryKeyColumn && typeof clientConfig.primary_key === 'string' && clientConfig.primary_key.length) {
            primaryKeyColumn = clientConfig.primary_key;
        }
        if (formOnlyMode) {
            container.addClass('fastcrud-form-only');

            if (clientConfig && typeof clientConfig === 'object') {
                var seedTableMeta = {};
                if (clientConfig.table_meta && typeof clientConfig.table_meta === 'object') {
                    try {
                        seedTableMeta = JSON.parse(JSON.stringify(clientConfig.table_meta));
                    } catch (error) {
                        seedTableMeta = $.extend(true, {}, clientConfig.table_meta);
                    }
                }

                if (!seedTableMeta || typeof seedTableMeta !== 'object') {
                    seedTableMeta = {};
                }

                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'bulk_actions') || !Array.isArray(seedTableMeta.bulk_actions)) {
                    seedTableMeta.bulk_actions = [];
                }
                ['add','view','edit','delete'].forEach(function(flag){
                    if (!Object.prototype.hasOwnProperty.call(seedTableMeta, flag)) {
                        seedTableMeta[flag] = true;
                    }
                });
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'duplicate')) {
                    seedTableMeta.duplicate = false;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'delete_confirm')) {
                    seedTableMeta.delete_confirm = true;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'batch_delete')) {
                    seedTableMeta.batch_delete = false;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'batch_delete_button')) {
                    seedTableMeta.batch_delete_button = false;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'export_csv')) {
                    seedTableMeta.export_csv = false;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'export_excel')) {
                    seedTableMeta.export_excel = false;
                }

                var seedMeta = {
                    columns: Array.isArray(clientConfig.columns) ? clientConfig.columns.slice() : [],
                    primary_key: clientConfig.primary_key || null,
                    form: clientConfig.form || {},
                    inline_edit: Array.isArray(clientConfig.inline_edit) ? clientConfig.inline_edit.slice() : (clientConfig.inline_edit || []),
                    table: seedTableMeta,
                    sort_disabled: Array.isArray(clientConfig.sort_disabled) ? clientConfig.sort_disabled.slice() : [],
                    limit_options: Array.isArray(clientConfig.limit_options) ? clientConfig.limit_options.slice() : [],
                    default_limit: typeof clientConfig.limit_default !== 'undefined' ? clientConfig.limit_default : null,
                    search: {
                        columns: Array.isArray(clientConfig.search_columns) ? clientConfig.search_columns.slice() : [],
                        'default': clientConfig.search_default || null,
                        available: Array.isArray(clientConfig.search_columns) ? clientConfig.search_columns.slice() : []
                    },
                    nested_tables: Array.isArray(clientConfig.nested_tables) ? clientConfig.nested_tables.slice() : [],
                    link_buttons: Array.isArray(clientConfig.link_buttons) ? clientConfig.link_buttons.slice() : [],
                    multi_link_buttons: Array.isArray(clientConfig.multi_link_buttons) ? clientConfig.multi_link_buttons.slice() : [],
                    soft_delete: clientConfig.soft_delete || null,
                    labels: clientConfig.column_labels || {},
                    column_classes: clientConfig.column_classes || {},
                    column_widths: clientConfig.column_widths || {},
                    order_by: Array.isArray(clientConfig.order_by) ? clientConfig.order_by.slice() : [],
                    summaries: Array.isArray(clientConfig.column_summaries) ? clientConfig.column_summaries.slice() : []
                };

                if (seedMeta.form && typeof seedMeta.form === 'object') {
                    try {
                        seedMeta.form = JSON.parse(JSON.stringify(seedMeta.form));
                    } catch (error) {
                        seedMeta.form = $.extend(true, {}, seedMeta.form);
                    }
                } else {
                    seedMeta.form = {};
                }

                if ((!seedMeta.columns || !seedMeta.columns.length) && seedMeta.form && Array.isArray(seedMeta.form.all_columns)) {
                    seedMeta.columns = seedMeta.form.all_columns.slice();
                }

                if (!seedMeta.columns || !seedMeta.columns.length) {
                    seedMeta.columns = [];
                }

                applyMeta(seedMeta);
            }
        }

        function getStyleClass(key, fallback) {
            var value = '';
            if (styleDefaults && Object.prototype.hasOwnProperty.call(styleDefaults, key)) {
                value = String(styleDefaults[key] || '');
            }
            value = value.trim();
            return value || fallback;
        }

        function clearRowHighlight() {
            try {
                table.find('tbody tr.fastcrud-editing').each(function() {
                    var trEl = $(this);
                    var had = trEl.data('fastcrudHadClass');
                    if (had !== 1 && had !== '1') { trEl.removeClass(editViewHighlightClass); }
                    trEl.removeClass('fastcrud-editing').removeData('fastcrudHadClass');
                });
            } catch (e) {}
        }

        function resolvePrimaryKeyColumn() {
            if (primaryKeyColumn && String(primaryKeyColumn).length) {
                return primaryKeyColumn;
            }

            if (initialPrimaryKeyColumn && String(initialPrimaryKeyColumn).length) {
                primaryKeyColumn = initialPrimaryKeyColumn;
                return primaryKeyColumn;
            }

            if (clientConfig && typeof clientConfig.primary_key === 'string' && clientConfig.primary_key.length) {
                primaryKeyColumn = clientConfig.primary_key;
                return primaryKeyColumn;
            }

            return null;
        }

        function highlightRow(tr) {
            if (!tr || !tr.length) {
                clearRowHighlight();
                return;
            }

            try {
                clearRowHighlight();
                var parts = String(editViewHighlightClass || '').split(/\s+/).filter(function(s){ return s.length > 0; });
                var hasAll = true;
                for (var i = 0; i < parts.length; i++) {
                    if (!tr.hasClass(parts[i])) { hasAll = false; break; }
                }
                var alreadyHas = hasAll ? 1 : 0;
                tr.data('fastcrudHadClass', alreadyHas);
                tr.addClass('fastcrud-editing');
                if (!alreadyHas) { tr.addClass(editViewHighlightClass); }
            } catch (e) {}
        }

        var toolbar = $('#' + tableId + '-toolbar');
        var rangeDisplay = $('#' + tableId + '-range');
        var metaContainer = $('#' + tableId + '-meta');
        var searchGroup = null;
        var searchInput = null;
        var searchSelect = null;
        var searchButton = null;
        var clearButton = null;

        selectAllCheckbox = table.find('thead .fastcrud-select-all');
        if (selectAllCheckbox.length) {
            selectAllCheckbox.on('change', function() {
                if (!batchDeleteEnabled || !deleteEnabled) {
                    $(this).prop('checked', false).prop('indeterminate', false);
                    return;
                }

                var shouldSelect = $(this).is(':checked');
                toggleSelectAll(shouldSelect);
            });
        }

        var editFormId = tableId + '-edit-form';
        var editForm = $('#' + editFormId);
        var editFieldsContainer = $('#' + tableId + '-edit-fields');
        var editError = $('#' + tableId + '-edit-error');
        var editSuccess = $('#' + tableId + '-edit-success');
        var editLabel = $('#' + tableId + '-edit-label');
        var editOffcanvasElement = $('#' + tableId + '-edit-panel');
        moveOffcanvasToBody(editOffcanvasElement);
        editForm.data('mode', 'edit');
        var editOffcanvasInstance = null;
        if (editOffcanvasElement.length) {
            // Clear highlight as soon as the panel starts closing (no wait for animation)
            editOffcanvasElement.on('hide.bs.offcanvas', function() {
                clearRowHighlight();
            });
            // Cleanup heavy widgets after the panel is fully hidden
            editOffcanvasElement.on('hidden.bs.offcanvas', function() {
                destroyRichEditors(editFieldsContainer);
                destroySelect2(editFieldsContainer);
                destroyFilePonds(editFieldsContainer);
            });
        }

        var viewOffcanvasElement = $('#' + tableId + '-view-panel');
        moveOffcanvasToBody(viewOffcanvasElement);
        var viewContentContainer = $('#' + tableId + '-view-content');
        var viewEmptyNotice = $('#' + tableId + '-view-empty');
        var viewHeading = $('#' + tableId + '-view-label');
        var viewOffcanvasInstance = null;
        if (viewOffcanvasElement.length) {
            viewOffcanvasElement.on('hide.bs.offcanvas', function() {
                clearRowHighlight();
            });
        }

        if (!formOnlyMode && container.length && !container.data('fastcrud-offcanvas-cleanup')) {
            container.data('fastcrud-offcanvas-cleanup', true);
            container.on('remove.fastcrudOffcanvasCleanup', function() {
                var panels = [editOffcanvasElement, viewOffcanvasElement];
                panels.forEach(function(panel) {
                    if (!panel || !panel.length) {
                        return;
                    }
                    var instance = panel.data('bs.offcanvas');
                    if (instance && typeof instance.dispose === 'function') {
                        try {
                            instance.dispose();
                        } catch (error) {}
                    }
                    panel.remove();
                });
            });
        }
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

        function collapseRepeatedSlashes(input) {
            var result = input;
            while (result.indexOf('//') !== -1) {
                result = result.replace('//', '/');
            }
            return result;
        }

        function normalizeStoredImageName(value) {
            if (value === null || typeof value === 'undefined') {
                return '';
            }
            var str = String(value).trim();
            if (!str.length) {
                return '';
            }
            var hashIndex = str.indexOf('#');
            if (hashIndex !== -1) {
                str = str.slice(0, hashIndex);
            }
            var queryIndex = str.indexOf('?');
            if (queryIndex !== -1) {
                str = str.slice(0, queryIndex);
            }
            str = str.split('\\\\').join('/');
            str = collapseRepeatedSlashes(str);
            while (str.indexOf('./') === 0) {
                str = str.slice(2);
            }
            if (str === '.' || !str.length) {
                return '';
            }
            return str;
        }

        function normalizeUploadSubPath(pathOption) {
            if (!pathOption) {
                return '';
            }
            var candidate = String(pathOption).trim();
            if (!candidate.length) {
                return '';
            }
            if (/^https?:\/\//i.test(candidate)) {
                try {
                    var parsed = new URL(candidate, window.location.origin);
                    candidate = parsed.pathname || '';
                } catch (e) {
                    candidate = '';
                }
            }
            candidate = candidate.split('\\\\').join('/');
            candidate = collapseRepeatedSlashes(candidate);
            while (candidate.charAt(0) === '/') {
                candidate = candidate.slice(1);
            }
            while (candidate.charAt(candidate.length - 1) === '/') {
                candidate = candidate.slice(0, -1);
            }
            if (!candidate.length) {
                return '';
            }
            var segments = candidate.split('/').filter(function(item) { return item.length > 0; });
            if (!segments.length) {
                return '';
            }
            if (segments[0].toLowerCase() === 'public') {
                segments.shift();
            }
            var basePath = getUploadPublicBase();
            while (basePath.charAt(0) === '/') {
                basePath = basePath.slice(1);
            }
            while (basePath.charAt(basePath.length - 1) === '/') {
                basePath = basePath.slice(0, -1);
            }
            var baseSegments = basePath.split('/').filter(function(item) {
                return item.length > 0;
            });
            if (segments.length && baseSegments.length) {
                var lastBase = baseSegments[baseSegments.length - 1];
                if (lastBase && segments[0].toLowerCase() === lastBase.toLowerCase()) {
                    segments.shift();
                }
            }
            return segments.join('/');
        }

        function parseImageNameList(value) {
            var result = [];
            var push = function(candidate) {
                var normalized = normalizeStoredImageName(candidate);
                if (normalized && result.indexOf(normalized) === -1) {
                    result.push(normalized);
                }
            };

            if (Array.isArray(value)) {
                value.forEach(push);
                return result;
            }

            var text = String(value || '').trim();
            if (!text.length) {
                return result;
            }

            text.split(',').forEach(push);

            return result;
        }

        function imageNamesToString(list) {
            if (!Array.isArray(list) || !list.length) {
                return '';
            }
            var normalized = [];
            list.forEach(function(item) {
                var name = normalizeStoredImageName(item);
                if (name && normalized.indexOf(name) === -1) {
                    normalized.push(name);
                }
            });
            return normalized.join(',');
        }

        function setImageNamesOnInput(input, list) {
            if (!input || !input.length) {
                return;
            }
            input.val(imageNamesToString(Array.isArray(list) ? list : parseImageNameList(list)));
        }

        function addImageNameToInput(input, candidate) {
            if (!input || !input.length) {
                return;
            }
            var name = normalizeStoredImageName(candidate);
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
            var name = normalizeStoredImageName(candidate);
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
            var normalized = normalizeStoredImageName(name);
            if (normalized) {
                map[key] = normalized;
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

            // Align FilePond panels/drop areas with the active Bootstrap theme (light/dark)
            try {
                var themeStyleId = 'fastcrud-filepond-theme-css';
                if (!document.getElementById(themeStyleId)) {
                    var themeStyle = document.createElement('style');
                    themeStyle.id = themeStyleId;
                    themeStyle.textContent = ':root{--fastcrud-filepond-panel-bg:var(--bs-tertiary-bg,var(--bs-secondary-bg,#f8f9fa));--fastcrud-filepond-surface:linear-gradient(135deg,rgba(255,255,255,0.95),rgba(246,248,253,0.9));--fastcrud-filepond-border-color:var(--bs-primary-border-subtle,var(--bs-primary,#0d6efd));--fastcrud-filepond-label-color:var(--bs-secondary-color,rgba(33,37,41,0.75));--fastcrud-filepond-text-color:var(--bs-body-color,#212529);--fastcrud-filepond-subtle-color:var(--bs-secondary-color,rgba(33,37,41,0.6));--fastcrud-filepond-legend-color:var(--fastcrud-filepond-text-color);--fastcrud-filepond-shadow:rgba(15,23,42,0.12);}' +
                        '[data-bs-theme=light]{--fastcrud-filepond-panel-bg:var(--bs-tertiary-bg,var(--bs-secondary-bg,#f8f9fa));--fastcrud-filepond-surface:linear-gradient(135deg,rgba(255,255,255,0.95),rgba(246,248,253,0.9));--fastcrud-filepond-shadow:rgba(15,23,42,0.12);--fastcrud-filepond-text-color:var(--bs-body-color,#212529);--fastcrud-filepond-subtle-color:var(--bs-secondary-color,rgba(73,80,87,0.75));--fastcrud-filepond-legend-color:var(--bs-body-color,#212529);}' +
                        '[data-bs-theme=dark]{--fastcrud-filepond-panel-bg:var(--bs-tertiary-bg,var(--bs-secondary-bg,#2b3035));--fastcrud-filepond-border-color:var(--bs-primary-border-subtle,var(--bs-primary,#6ea8fe));--fastcrud-filepond-label-color:var(--bs-secondary-color,#adb5bd);--fastcrud-filepond-text-color:var(--bs-body-color,#dee2e6);--fastcrud-filepond-subtle-color:rgba(222,226,230,0.7);--fastcrud-filepond-legend-color:var(--bs-body-color,#dee2e6);--fastcrud-filepond-surface:linear-gradient(135deg,rgba(47,52,58,0.9),rgba(30,34,39,0.92));--fastcrud-filepond-shadow:rgba(0,0,0,0.45);}' +
                        '.filepond--root{background:var(--fastcrud-filepond-surface)!important;border:2px dashed var(--fastcrud-filepond-border-color)!important;border-radius:0.85rem!important;padding:0.5rem 0.6rem!important;box-shadow:0 1.2rem 2.4rem -1.4rem var(--fastcrud-filepond-shadow);transition:background .2s ease,border-color .2s ease,box-shadow .2s ease;}' +
                        '.filepond--root:hover{box-shadow:0 1.4rem 2.8rem -1.2rem var(--fastcrud-filepond-shadow);}' +
                        '.filepond--panel-root,.filepond--panel-top,.filepond--panel-center,.filepond--panel-bottom{background-color:transparent!important;border:none!important;box-shadow:none!important;}' +
                        '.filepond--panel-root::before,.filepond--panel-root::after{background:transparent!important;}' +
                        '.filepond--drop-label{color:var(--fastcrud-filepond-label-color)!important;font-weight:500;}' +
                        '.filepond--drop-label span{color:inherit!important;}' +
                        '.filepond legend{color:var(--fastcrud-filepond-legend-color)!important;font-weight:600;}' +
                        '.filepond--file-info{color:var(--fastcrud-filepond-text-color)!important;}' +
                        '.filepond--file-info span{color:inherit!important;}' +
                        '.filepond--file-info-sub{color:var(--fastcrud-filepond-subtle-color)!important;}' +
                        '.filepond--item-panel{background-color:var(--fastcrud-filepond-panel-bg)!important;border:none!important;border-radius:0.65rem!important;box-shadow:0 0.6rem 1.4rem -1.2rem var(--fastcrud-filepond-shadow);}' +
                        '.filepond--file{border-radius:0.65rem!important;}';
                    document.head.appendChild(themeStyle);
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

        var select2State = window.FastCrudSelect2 || {};
        if (!select2State.scriptUrl) {
            select2State.scriptUrl = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';
        }
        if (!select2State.styleUrl) {
            select2State.styleUrl = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
        }
        if (!Array.isArray(select2State.queue)) {
            select2State.queue = [];
        }
        if (typeof select2State.loaded !== 'boolean') {
            select2State.loaded = (typeof $.fn !== 'undefined' && typeof $.fn.select2 === 'function');
        } else if (select2State.loaded && (typeof $.fn === 'undefined' || typeof $.fn.select2 !== 'function')) {
            select2State.loaded = false;
        }
        if (typeof select2State.loading !== 'boolean') {
            select2State.loading = false;
        }
        window.FastCrudSelect2 = select2State;

        function withSelect2Assets(callback) {
            if (!select2Enabled || typeof callback !== 'function') {
                return;
            }
            if (typeof $.fn !== 'undefined' && typeof $.fn.select2 === 'function') {
                callback();
                return;
            }
            select2State.queue.push(callback);
            if (select2State.loading) {
                return;
            }
            select2State.loading = true;
            appendStylesheetOnce(select2State.styleUrl, 'fastcrud-select2-css');
            try {
                var select2ThemeStyleId = 'fastcrud-select2-theme-css';
                if (!document.getElementById(select2ThemeStyleId)) {
                    var select2ThemeStyle = document.createElement('style');
                    select2ThemeStyle.id = select2ThemeStyleId;
                    var select2ThemeCss = {$select2ThemeCssJson};
                    select2ThemeStyle.textContent = select2ThemeCss;
                    document.head.appendChild(select2ThemeStyle);
                }
            } catch (e) {}
            var script = document.createElement('script');
            script.src = select2State.scriptUrl;
            script.async = true;
            script.onload = function() {
                select2State.loading = false;
                select2State.loaded = true;
                var queued = select2State.queue.slice();
                select2State.queue.length = 0;
                queued.forEach(function(fn) {
                    try { fn(); } catch (error) { if (window.console && console.error) { console.error(error); } }
                });
            };
            script.onerror = function() {
                select2State.loading = false;
                select2State.queue.length = 0;
                if (window.console && console.error) {
                    console.error('FastCrud: failed to load Select2 script');
                }
            };
            document.head.appendChild(script);
        }

        function resolveSelect2DropdownParent(select) {
            if (select && select.hasClass('fastcrud-inline-input')) {
                return $('body');
            }

            var parent = select.closest('.offcanvas.show, .modal.show');
            if (parent.length) {
                return parent;
            }
            parent = select.parent();
            if (parent.length) {
                return parent;
            }
            return $('body');
        }

        function initializeSelect2(container) {
            if (!select2Enabled) {
                return;
            }
            if (!container || !container.length) {
                return;
            }
            var selectors = 'select[data-fastcrud-type="select"], select[data-fastcrud-type="multiselect"], select.fastcrud-inline-input';
            var elements = container.is('select') ? container.filter(selectors) : container.find(selectors);
            if (!elements.length) {
                return;
            }
            withSelect2Assets(function() {
                if (typeof $.fn === 'undefined' || typeof $.fn.select2 !== 'function') {
                    return;
                }
                elements.each(function() {
                    var select = $(this);
                    if (select.data('select2')) {
                        return;
                    }
                    var isMultiple = select.prop('multiple');
                    var placeholder = select.attr('data-placeholder') || select.attr('placeholder') || null;
                    if (!placeholder && !isMultiple) {
                        var blankOption = select.find('option').filter(function() {
                            var value = $(this).attr('value');
                            if (typeof value === 'undefined' || value === null) {
                                return true;
                            }
                            return String(value).trim() === '';
                        }).first();
                        if (blankOption.length) {
                            placeholder = blankOption.text();
                        }
                    }
                    var options = {
                        width: '100%',
                        dropdownParent: resolveSelect2DropdownParent(select)
                    };
                    if (placeholder) {
                        options.placeholder = placeholder;
                        options.allowClear = !isMultiple;
                    } else if (!isMultiple && select.find('option[value=""]').length) {
                        options.allowClear = true;
                    }
                    try {
                        select.select2(options);
                    } catch (error) {
                        if (window.console && console.error) {
                            console.error('FastCrud: failed to initialize Select2', error);
                        }
                    }
                });
            });
        }

        function destroySelect2(container) {
            if (!container || !container.length) {
                return;
            }
            if (typeof $.fn === 'undefined' || typeof $.fn.select2 !== 'function') {
                return;
            }
            var selectors = 'select[data-fastcrud-type="select"], select[data-fastcrud-type="multiselect"], select.fastcrud-inline-input';
            var elements = container.is('select') ? container.filter(selectors) : container.find(selectors);
            elements.each(function() {
                var select = $(this);
                if (select.data('select2')) {
                    try { select.select2('destroy'); } catch (e) {}
                }
            });
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

        function createInlinePanelController(element, callbacks) {
            if (!element) {
                return null;
            }

            var elementRef = $(element);
            var hooks = callbacks && typeof callbacks === 'object' ? callbacks : {};
            return {
                show: function() {
                    elementRef.addClass('fastcrud-inline-visible show');
                },
                hide: function() {
                    elementRef.removeClass('fastcrud-inline-visible show');
                    if (hooks.onHide && typeof hooks.onHide === 'function') {
                        hooks.onHide();
                    }
                }
            };
        }

        function moveOffcanvasToBody(offcanvasElement) {
            if (formOnlyMode) {
                return;
            }

            if (!offcanvasElement || !offcanvasElement.length) {
                return;
            }

            var node = offcanvasElement.get(0);
            if (!node || node.parentNode === document.body || !document.body) {
                return;
            }

            var resolvedTheme = (function() {
                if (container && typeof container.attr === 'function') {
                    var directTheme = container.attr('data-bs-theme');
                    if (typeof directTheme === 'string' && directTheme.length) {
                        return directTheme;
                    }

                    var themedAncestor = container.closest('[data-bs-theme]');
                    if (themedAncestor.length) {
                        var ancestorTheme = themedAncestor.attr('data-bs-theme');
                        if (typeof ancestorTheme === 'string' && ancestorTheme.length) {
                            return ancestorTheme;
                        }
                    }
                }

                var bodyTheme = $('body').attr('data-bs-theme');
                if (typeof bodyTheme === 'string' && bodyTheme.length) {
                    return bodyTheme;
                }

                var htmlTheme = $('html').attr('data-bs-theme');
                if (typeof htmlTheme === 'string' && htmlTheme.length) {
                    return htmlTheme;
                }

                return null;
            }());

            if (resolvedTheme && !offcanvasElement.attr('data-bs-theme')) {
                offcanvasElement.attr('data-bs-theme', resolvedTheme);
            }

            offcanvasElement.attr('data-fastcrud-owner', tableId);
            document.body.appendChild(node);
        }

        function getEditOffcanvasInstance() {
            if (editOffcanvasInstance) {
                return editOffcanvasInstance;
            }

            var element = editOffcanvasElement.get(0);
            if (!element) {
                return null;
            }

            if (formOnlyMode) {
                editOffcanvasInstance = createInlinePanelController(element, {
                    onHide: function() {
                        clearRowHighlight();
                        destroyRichEditors(editFieldsContainer);
                        destroySelect2(editFieldsContainer);
                        destroyFilePonds(editFieldsContainer);
                    }
                });
                return editOffcanvasInstance;
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
                editOffcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(element);
                return editOffcanvasInstance;
            }

            return null;
        }

        function getViewOffcanvasInstance() {
            if (viewOffcanvasInstance) {
                return viewOffcanvasInstance;
            }

            var element = viewOffcanvasElement.get(0);
            if (!element) {
                return null;
            }

            if (formOnlyMode) {
                viewOffcanvasInstance = createInlinePanelController(element, {
                    onHide: function() {
                        clearRowHighlight();
                    }
                });
                return viewOffcanvasInstance;
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
                viewOffcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(element);
                return viewOffcanvasInstance;
            }

            return null;
        }

        function selectionKey(pkCol, pkVal) {
            if (!pkCol) {
                return '';
            }

            if (typeof pkVal === 'undefined' || pkVal === null) {
                return '';
            }

            return String(pkCol) + '::' + String(pkVal);
        }

        function setSelection(pkCol, pkVal, selected) {
            var key = selectionKey(pkCol, pkVal);
            if (!key) {
                return;
            }

            if (selected) {
                selectedRows[key] = { column: pkCol, value: pkVal };
            } else if (Object.prototype.hasOwnProperty.call(selectedRows, key)) {
                delete selectedRows[key];
            }
        }

        function isSelected(pkCol, pkVal) {
            return Object.prototype.hasOwnProperty.call(selectedRows, selectionKey(pkCol, pkVal));
        }

        function getSelectedCount() {
            return Object.keys(selectedRows).length;
        }

        function clearSelection() {
            selectedRows = {};
            table.find('tbody .fastcrud-select-row').each(function() {
                $(this).prop('checked', false);
            });
            if (selectAllCheckbox && selectAllCheckbox.length) {
                selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
            }
            updateBatchDeleteButtonState();
        }

        function updateBatchDeleteButtonState() {
            if (formOnlyMode) {
                batchDeleteButton = null;
                return;
            }

            if (batchDeleteButton && batchDeleteButton.length) {
                var selectedCount = getSelectedCount();
                var enabled = allowBatchDeleteButton && selectedCount > 0;
                batchDeleteButton.prop('disabled', !enabled);
                var shouldHideButton = !allowBatchDeleteButton || selectedCount === 0;
                batchDeleteButton.toggleClass('d-none', shouldHideButton);
            }

            if (selectAllCheckbox && selectAllCheckbox.length) {
                var allowSelection = batchDeleteEnabled;
                selectAllCheckbox.prop('disabled', !allowSelection);
                if (!allowSelection) {
                    selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
                }
            }

            updateBulkActionState();
        }

        function updateBulkActionState() {
            if (formOnlyMode) {
                return;
            }

            var wrapper = metaContainer.find('.fastcrud-bulk-actions');
            if (!wrapper.length) {
                return;
            }

            var select = wrapper.find('.fastcrud-bulk-action-select');
            var applyBtn = wrapper.find('.fastcrud-bulk-apply-btn');
            if (!applyBtn.length) {
                return;
            }

            var hasSelection = getSelectedCount() > 0;
            var selectedAction = select.length ? select.val() : '';
            applyBtn.prop('disabled', !(hasSelection && selectedAction));
        }

        function refreshSelectAllState() {
            if (!selectAllCheckbox || !selectAllCheckbox.length) {
                return;
            }

            if (!batchDeleteEnabled || !deleteEnabled) {
                selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
                return;
            }

            var enabledCheckboxes = table.find('tbody .fastcrud-select-row').filter(':not(:disabled)');
            if (!enabledCheckboxes.length) {
                selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
                return;
            }

            var checkedCount = enabledCheckboxes.filter(':checked').length;
            selectAllCheckbox.prop('checked', checkedCount === enabledCheckboxes.length);
            selectAllCheckbox.prop('indeterminate', checkedCount > 0 && checkedCount < enabledCheckboxes.length);
        }

        function toggleSelectAll(shouldSelect) {
            if (!batchDeleteEnabled) {
                return;
            }

            var checkboxes = table.find('tbody .fastcrud-select-row').filter(':not(:disabled)');
            checkboxes.each(function() {
                var checkbox = $(this);
                var pkCol = checkbox.attr('data-fastcrud-pk');
                var pkVal = checkbox.attr('data-fastcrud-pk-value');
                if (!pkCol || typeof pkVal === 'undefined') {
                    return;
                }

                checkbox.prop('checked', shouldSelect);
                setSelection(pkCol, pkVal, shouldSelect);
            });

            refreshSelectAllState();
            updateBatchDeleteButtonState();
        }

        function applyMeta(meta) {
            if (!meta || typeof meta !== 'object') {
                return;
            }

            metaConfig = meta;

            if (Object.prototype.hasOwnProperty.call(metaConfig, 'numbers_enabled')) {
                numbersEnabled = !!metaConfig.numbers_enabled;
            } else if (clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'numbers_enabled')) {
                numbersEnabled = !!clientConfig.numbers_enabled;
            }
            clientConfig.numbers_enabled = numbersEnabled;

            if (formOnlyMode) {
                if (Array.isArray(meta.columns) && meta.columns.length) {
                    columnsCache = meta.columns.slice();
                }

                if (typeof meta.primary_key === 'string' && meta.primary_key.length) {
                    primaryKeyColumn = meta.primary_key;
                }

                columnLabels = meta.labels && typeof meta.labels === 'object' ? meta.labels : {};
                columnClasses = meta.column_classes && typeof meta.column_classes === 'object' ? meta.column_classes : {};
                columnWidths = meta.column_widths && typeof meta.column_widths === 'object' ? meta.column_widths : {};

                if (meta.form && typeof meta.form === 'object') {
                    var templates = meta.form.templates && typeof meta.form.templates === 'object'
                        ? deepClone(meta.form.templates)
                        : {};
                    formConfig = {
                        layouts: meta.form.layouts && typeof meta.form.layouts === 'object' ? meta.form.layouts : {},
                        default_tabs: meta.form.default_tabs && typeof meta.form.default_tabs === 'object' ? meta.form.default_tabs : {},
                        behaviours: meta.form.behaviours && typeof meta.form.behaviours === 'object' ? meta.form.behaviours : {},
                        labels: meta.form.labels && typeof meta.form.labels === 'object' ? meta.form.labels : {},
                        all_columns: Array.isArray(meta.form.all_columns) ? meta.form.all_columns.slice() : [],
                        sections: meta.form.sections && typeof meta.form.sections === 'object' ? meta.form.sections : {},
                        templates: templates
                    };
                    formTemplates = templates;
                    clientConfig.form = $.extend(true, {}, meta.form);
                    if (Object.keys(templates).length) {
                        clientConfig.form.templates = deepClone(templates);
                    } else if (clientConfig.form && typeof clientConfig.form === 'object') {
                        delete clientConfig.form.templates;
                    }
                } else {
                    formTemplates = {};
                }

                inlineEditFields = {};
                var inlineFormOnly = Array.isArray(meta.inline_edit) ? meta.inline_edit : [];
                if (!inlineFormOnly.length && Array.isArray(clientConfig.inline_edit)) {
                    inlineFormOnly = clientConfig.inline_edit;
                }
                inlineFormOnly.forEach(function(field) {
                    if (field) {
                        inlineEditFields[String(field)] = true;
                    }
                });

                if (!Array.isArray(clientConfig.inline_edit) || !clientConfig.inline_edit.length) {
                    clientConfig.inline_edit = inlineFormOnly.slice();
                }

                if (Array.isArray(formConfig.all_columns) && formConfig.all_columns.length) {
                    baseColumns = formConfig.all_columns.slice();
                } else if (columnsCache.length) {
                    baseColumns = columnsCache.slice();
                }

                if (meta.table && typeof meta.table === 'object') {
                    clientConfig.table_meta = meta.table;
                }

                allowBatchDeleteButton = false;
                batchDeleteEnabled = false;
                metaInitialized = true;
                return;
            }

            if (Object.prototype.hasOwnProperty.call(meta, 'soft_delete')) {
                clientConfig.soft_delete = meta.soft_delete;
            }

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
                var liveTemplates = meta.form.templates && typeof meta.form.templates === 'object'
                    ? deepClone(meta.form.templates)
                    : {};
                formConfig = {
                    layouts: meta.form.layouts && typeof meta.form.layouts === 'object' ? meta.form.layouts : {},
                    default_tabs: meta.form.default_tabs && typeof meta.form.default_tabs === 'object' ? meta.form.default_tabs : {},
                    behaviours: meta.form.behaviours && typeof meta.form.behaviours === 'object' ? meta.form.behaviours : {},
                    labels: meta.form.labels && typeof meta.form.labels === 'object' ? meta.form.labels : {},
                    all_columns: Array.isArray(meta.form.all_columns) ? meta.form.all_columns : [],
                    sections: meta.form.sections && typeof meta.form.sections === 'object' ? meta.form.sections : {},
                    templates: liveTemplates
                };
                formTemplates = liveTemplates;
                clientConfig.form = $.extend(true, {}, meta.form);
                if (Object.keys(liveTemplates).length) {
                    clientConfig.form.templates = deepClone(liveTemplates);
                } else if (clientConfig.form && typeof clientConfig.form === 'object') {
                    delete clientConfig.form.templates;
                }
            } else {
                formTemplates = {};
                formConfig = {
                    layouts: {},
                    default_tabs: {},
                    behaviours: {},
                    labels: {},
                    all_columns: [],
                    sections: {},
                    templates: {}
                };
                delete clientConfig.form;
            }

            // Inline edit fields (fallback to client config if meta missing/empty)
            inlineEditFields = {};
            var inlineArr = Array.isArray(meta.inline_edit) ? meta.inline_edit : [];
            if (!inlineArr.length && Array.isArray(clientConfig.inline_edit)) {
                inlineArr = clientConfig.inline_edit;
            }
            if (!Array.isArray(clientConfig.inline_edit) || clientConfig.inline_edit.length === 0) {
                clientConfig.inline_edit = inlineArr.slice();
            }
            inlineArr.forEach(function(f){ if (f) { inlineEditFields[String(f)] = true; } });
            if (Array.isArray(formConfig.all_columns) && formConfig.all_columns.length) {
                baseColumns = formConfig.all_columns.slice();
            } else {
                baseColumns = columnsCache.slice();
            }

            var tableMeta = meta.table && typeof meta.table === 'object' ? meta.table : {};
            clientConfig.table_meta = tableMeta;
            bulkActions = Array.isArray(tableMeta.bulk_actions) ? tableMeta.bulk_actions : [];

            addEnabled = tableMeta.hasOwnProperty('add') ? !!tableMeta.add : true;
            viewEnabled = tableMeta.hasOwnProperty('view') ? !!tableMeta.view : true;
            editEnabled = tableMeta.hasOwnProperty('edit') ? !!tableMeta.edit : true;
            deleteEnabled = tableMeta.hasOwnProperty('delete') ? !!tableMeta.delete : true;
            duplicateEnabled = !!tableMeta.duplicate;
            if (tableMeta.hasOwnProperty('delete_confirm')) {
                deleteConfirm = !!tableMeta.delete_confirm;
            }
            var batchDeleteButtonEnabled = tableMeta.hasOwnProperty('batch_delete_button')
                ? !!tableMeta.batch_delete_button
                : !!tableMeta.batch_delete;

            allowBatchDeleteButton = batchDeleteButtonEnabled && deleteEnabled;
            var hasBulkActions = Array.isArray(bulkActions) && bulkActions.length > 0;
            batchDeleteEnabled = allowBatchDeleteButton || hasBulkActions;
            if (!batchDeleteEnabled) {
                clearSelection();
            }

            clientConfig.link_buttons = Array.isArray(meta.link_buttons)
                ? deepClone(meta.link_buttons)
                : [];
            clientConfig.multi_link_buttons = Array.isArray(meta.multi_link_buttons)
                ? deepClone(meta.multi_link_buttons)
                : [];

            updateMetaContainer(tableMeta);
            updateBatchDeleteButtonState();
            refreshSelectAllState();
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
            syncSortStateFromOrderBy();
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

            var nestedMeta = Array.isArray(meta.nested_tables)
                ? meta.nested_tables
                : (Array.isArray(clientConfig.nested_tables) ? clientConfig.nested_tables : []);
            nestedTablesConfig = Array.isArray(nestedMeta) ? deepClone(nestedMeta) : [];
            clientConfig.nested_tables = deepClone(nestedTablesConfig);

            if (meta.query_builder && typeof meta.query_builder === 'object') {
                hydrateQueryBuilderFromMeta(meta.query_builder);
            }

            renderSummaries(meta.summaries || []);
            refreshTooltips();

            metaInitialized = true;
        }

        function ensureSearchControls() {
            if (formOnlyMode) {
                return;
            }

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
                markFiltersDirty();
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

            var searchButtonClass = getStyleClass('search_button_class', 'btn btn-outline-primary');
            searchButton = $('<button type="button">Search</button>').addClass(searchButtonClass);
            searchButton.on('click', function() {
                triggerSearch();
            });

            var clearButtonClass = getStyleClass('search_clear_button_class', 'btn btn-outline-secondary');
            clearButton = $('<button type="button">Clear</button>').addClass(clearButtonClass);
            clearButton.on('click', function() {
                currentSearchTerm = '';
                if (searchInput) {
                    searchInput.val('');
                }
                markFiltersDirty();
                loadTableData(1);
            });

            searchGroup.append(searchButton).append(clearButton);

            toolbar.append(searchGroup);
        }

        function updateMetaContainer(tableMeta) {
            if (formOnlyMode) {
                if (metaContainer && metaContainer.length) {
                    metaContainer.empty().addClass('d-none');
                }
                batchDeleteButton = null;
                return;
            }

            tableMeta = tableMeta && typeof tableMeta === 'object' ? tableMeta : {};
            if (!metaContainer || typeof metaContainer.length === 'undefined' || !metaContainer.length) {
                return;
            }

            metaContainer.empty();

            var hideTitle = !!(tableMeta && tableMeta.hide_title);
            var hasMeta = !hideTitle && tableMeta && (tableMeta.title || tableMeta.icon || tableMeta.tooltip);
            if (hasMeta) {
                var wrapper = $('<div class="d-flex align-items-center gap-2"></div>');

                if (tableMeta.icon) {
                    wrapper.append($('<i></i>').addClass(tableMeta.icon));
                }

                if (tableMeta.title) {
                    var title = $('<h5 class="mb-0"></h5>').text(tableMeta.title);
                    if (tableMeta.tooltip) {
                        title.attr('title', tableMeta.tooltip).attr('data-bs-toggle', 'tooltip');
                    }
                    wrapper.append(title);
                } else if (tableMeta.tooltip) {
                    wrapper.append($('<span class="text-muted"></span>').text(tableMeta.tooltip));
                }

                metaContainer.append(wrapper);
            }

            var utilitiesWrapper = $('<div class="d-flex flex-wrap align-items-center gap-2 w-100"></div>');
            metaContainer.append(utilitiesWrapper);

            var viewControlsWrapper = null;
            if (filtersEnabled) {
                viewControlsWrapper = $('<div class="d-flex flex-wrap align-items-stretch gap-2 fastcrud-view-controls"></div>');
                utilitiesWrapper.append(viewControlsWrapper);

                var savedViewGroup = $('<div class="input-group input-group-sm fastcrud-saved-view-group"></div>');
                viewControlsWrapper.append(savedViewGroup);

                viewSelect = $('<select class="form-select fastcrud-view-select" aria-label="Saved views"></select>');
                viewSelect.on('change', function() {
                    applySavedViewByName($(this).val() ? String($(this).val()) : '');
                });
                savedViewGroup.append(viewSelect);

                deleteViewButton = $('<button type="button" class="btn btn-outline-danger" title="Delete selected view" aria-label="Delete selected view"></button>');
                deleteViewButton.append($('<i aria-hidden="true"></i>').addClass(dismissIconClass));
                deleteViewButton.append('<span class="visually-hidden">Delete</span>');
                deleteViewButton.on('click', function() {
                    deleteCurrentView();
                });
                savedViewGroup.append(deleteViewButton);

                filtersButton = $('<button type="button" class="fastcrud-open-query-builder align-self-stretch">Filters <span class="badge bg-primary ms-2 fastcrud-filter-count d-none"></span></button>');
                filtersButton.addClass(getStyleClass('filters_button_class', 'btn btn-sm btn-outline-secondary'));
                filtersButtonBadge = filtersButton.find('.fastcrud-filter-count');
                filtersButton.on('click', function() {
                    openQueryBuilderModal();
                });
                viewControlsWrapper.append(filtersButton);
            }

            var actionsWrapper = $('<div class="d-flex align-items-center gap-2 ms-auto"></div>');
            utilitiesWrapper.append(actionsWrapper);
            var hasActions = false;

            if (allowBatchDeleteButton) {
                var batchDeleteButtonClass = getStyleClass('batch_delete_button_class', 'btn btn-sm btn-danger');
                var batchDeleteEl = $('<button type="button" disabled></button>')
                    .addClass(batchDeleteButtonClass)
                    .addClass('fastcrud-batch-delete-btn d-none')
                    .attr('title', 'Delete selected records')
                    .attr('aria-label', 'Delete selected records')
                    .text('Delete Selected');
                actionsWrapper.append(batchDeleteEl);
                hasActions = true;
            }

            var localBulkActions = Array.isArray(bulkActions) ? bulkActions : [];
            if (localBulkActions.length) {
                var bulkWrapper = $('<div class="d-flex align-items-center gap-2 fastcrud-bulk-actions"></div>');
                var bulkSelect = $('<select class="form-select form-select-sm fastcrud-bulk-action-select"></select>');
                bulkSelect.append('<option value="">Bulk actions</option>');

                localBulkActions.forEach(function(action, index) {
                    if (!action || typeof action !== 'object') {
                        return;
                    }

                    var label = '';
                    if (typeof action.label === 'string' && action.label.trim() !== '') {
                        label = action.label.trim();
                    } else if (typeof action.name === 'string' && action.name.trim() !== '') {
                        label = action.name.trim();
                    } else {
                        label = 'Action ' + (index + 1);
                    }

                    bulkSelect.append(
                        $('<option></option>').attr('value', String(index)).text(label)
                    );
                });

                bulkWrapper.append(bulkSelect);
                var bulkApplyButtonClass = getStyleClass('bulk_apply_button_class', 'btn btn-sm btn-outline-primary');
                var bulkApplyBtn = $('<button type="button" disabled>Apply</button>')
                    .addClass(bulkApplyButtonClass)
                    .addClass('fastcrud-bulk-apply-btn');
                bulkWrapper.append(bulkApplyBtn);
                actionsWrapper.append(bulkWrapper);
                hasActions = true;
            }

            if (tableMeta.export_csv) {
                var exportCsvClass = getStyleClass('export_csv_button_class', 'btn btn-sm btn-outline-secondary');
                var exportCsvBtn = $('<button type="button"></button>')
                    .addClass(exportCsvClass)
                    .addClass('fastcrud-export-csv-btn')
                    .attr('title', 'Export as CSV')
                    .attr('aria-label', 'Export as CSV')
                    .text('Export CSV');
                actionsWrapper.append(exportCsvBtn);
                hasActions = true;
            }

            if (tableMeta.export_excel) {
                var exportExcelClass = getStyleClass('export_excel_button_class', 'btn btn-sm btn-outline-secondary');
                var exportExcelBtn = $('<button type="button"></button>')
                    .addClass(exportExcelClass)
                    .addClass('fastcrud-export-excel-btn')
                    .attr('title', 'Export for Excel')
                    .attr('aria-label', 'Export for Excel')
                    .text('Export Excel');
                actionsWrapper.append(exportExcelBtn);
                hasActions = true;
            }

            if (addEnabled) {
                var addButtonClass = getStyleClass('add_button_class', 'btn btn-sm btn-success');
                var addButton = $('<button type="button"></button>')
                    .addClass(addButtonClass)
                    .addClass('fastcrud-add-btn')
                    .attr('title', 'Add new record')
                    .attr('aria-label', 'Add new record')
                    .append('<i class="fas fa-plus"></i> ')
                    .append(document.createTextNode('Add'));

                actionsWrapper.append(addButton);
                hasActions = true;
            }

            renderSavedViewsSelect();
            updateViewDeleteState();
            updateQueryBuilderBadge();

            if (hasActions) {
                batchDeleteButton = actionsWrapper.find('.fastcrud-batch-delete-btn');
            } else {
                batchDeleteButton = null;
            }

            if (metaContainer.children().length) {
                metaContainer.removeClass('d-none');
            } else {
                metaContainer.addClass('d-none');
            }

            updateBulkActionState();
        }

        function applyHeaderMetadata() {
            var headerCells = table.find('thead th').not('.fastcrud-actions, .fastcrud-select-header, .fastcrud-nested, .fastcrud-number-header');
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
            markFiltersDirty();
            syncSortStateFromOrderBy();
        }

        function updateSortIndicators() {
            var headerCells = table.find('thead th').not('.fastcrud-actions, .fastcrud-select-header, .fastcrud-nested, .fastcrud-number-header');
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

                if (hasNestedTablesConfigured()) {
                    row.append('<td class="fastcrud-nested-cell">&nbsp;</td>');
                }

                if (batchDeleteEnabled) {
                    row.append('<td class="text-center fastcrud-select-cell">&nbsp;</td>');
                }

                if (numbersEnabled) {
                    row.append('<td class="fastcrud-number-cell">&nbsp;</td>');
                }

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

                row.append('<td class="text-end fastcrud-actions-cell"><div class="fastcrud-actions-stack">&nbsp;</div></td>');
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
            view: '<i class="{$this->escapeHtml(CrudStyle::$view_action_icon)} fastcrud-icon" aria-hidden="true"></i>',
            edit: '<i class="{$this->escapeHtml(CrudStyle::$edit_action_icon)} fastcrud-icon" aria-hidden="true"></i>',
            delete: '<i class="{$this->escapeHtml(CrudStyle::$delete_action_icon)} fastcrud-icon" aria-hidden="true"></i>',
            duplicate: '<i class="{$this->escapeHtml(CrudStyle::$duplicate_action_icon)} fastcrud-icon" aria-hidden="true"></i>',
            expand: '<i class="{$this->escapeHtml(CrudStyle::$expand_action_icon)} fastcrud-icon" aria-hidden="true"></i>',
            collapse: '<i class="{$this->escapeHtml(CrudStyle::$collapse_action_icon)} fastcrud-icon" aria-hidden="true"></i>'
        };

        // Note: previously had a jQuery-based builder for the action cell here.
        // The code now uses `buildActionCellHtml` to generate HTML strings directly.

        function triggerSearch() {
            if (!searchInput) {
                return;
            }

            currentSearchTerm = searchInput.val() || '';
            markFiltersDirty();
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
                if (typeof label === 'string') {
                    return label;
                }
                if (label === null) {
                    return '';
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

        function resolveSectionsForMode(mode, availableFields) {
            var sections = [];
            var indexLookup = {};
            var ordering = Array.isArray(availableFields) ? availableFields.slice() : [];
            var availableLookup = {};

            ordering.forEach(function(field) {
                availableLookup[field] = true;
            });

            function listify(source) {
                if (!source) {
                    return [];
                }
                if (Array.isArray(source)) {
                    return source.slice();
                }
                if (typeof source === 'object') {
                    return Object.keys(source).map(function(key) {
                        return source[key];
                    });
                }
                return [];
            }

            function normalizeId(rawId) {
                if (typeof rawId !== 'string') {
                    return '';
                }
                var trimmed = rawId.trim();
                if (!trimmed.length) {
                    return '';
                }
                return trimmed.replace(/[^A-Za-z0-9_-]+/g, '_').replace(/^[_-]+|[_-]+$/g, '').toLowerCase();
            }

            function collect(entries) {
                listify(entries).forEach(function(entry) {
                    if (!entry || typeof entry !== 'object') {
                        return;
                    }

                    var rawId = entry.id || entry.section;
                    var sectionId = normalizeId(rawId || '');
                    if (!sectionId.length) {
                        return;
                    }

                    var rawFields = Array.isArray(entry.fields) ? entry.fields.slice() : [];
                    if (!rawFields.length) {
                        return;
                    }

                    var filteredFields = [];
                    rawFields.forEach(function(field) {
                        if (typeof field !== 'string' || !field.length) {
                            return;
                        }
                        if (ordering.length && !availableLookup[field]) {
                            return;
                        }
                        if (filteredFields.indexOf(field) === -1) {
                            filteredFields.push(field);
                        }
                    });

                    if (!filteredFields.length) {
                        return;
                    }

                    var title = null;
                    if (typeof entry.title === 'string') {
                        var trimmedTitle = entry.title.trim();
                        title = trimmedTitle.length ? trimmedTitle : null;
                    }

                    var description = null;
                    if (typeof entry.description === 'string') {
                        var trimmedDescription = entry.description.trim();
                        description = trimmedDescription.length ? trimmedDescription : null;
                    }

                    var collapsible = !!entry.collapsible;
                    var collapsed = collapsible && !!entry.collapsed;

                    var icon = null;
                    if (typeof entry.icon === 'string') {
                        var trimmedIcon = entry.icon.trim();
                        icon = trimmedIcon.length ? trimmedIcon : null;
                    }

                    var normalized = {
                        id: sectionId,
                        title: title,
                        description: description,
                        fields: filteredFields,
                        collapsible: collapsible,
                        collapsed: collapsed,
                        icon: icon
                    };

                    if (Object.prototype.hasOwnProperty.call(indexLookup, sectionId)) {
                        sections[indexLookup[sectionId]] = normalized;
                    } else {
                        indexLookup[sectionId] = sections.length;
                        sections.push(normalized);
                    }
                });
            }

            if (formConfig.sections && typeof formConfig.sections === 'object') {
                collect(formConfig.sections.all);
                if (mode && Object.prototype.hasOwnProperty.call(formConfig.sections, mode)) {
                    collect(formConfig.sections[mode]);
                }
            }

            var fieldMap = {};
            sections.forEach(function(section) {
                section.fields.forEach(function(field) {
                    fieldMap[field] = section.id;
                });
            });

            return { list: sections, map: fieldMap };
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
            var fieldSectionHints = {};
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
                var sectionName = entry.section && String(entry.section).length ? String(entry.section) : null;
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
                    if (sectionName) {
                        fieldSectionHints[field] = sectionName;
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

            var sectionsInfo = resolveSectionsForMode(mode, ordering);
            var sectionMetaMap = {};
            sectionsInfo.list.forEach(function(section) {
                sectionMetaMap[section.id] = section;
            });

            Object.keys(fieldSectionHints).forEach(function(field) {
                if (!columnLookup[field]) {
                    return;
                }
                var sectionId = fieldSectionHints[field];
                if (!sectionId) {
                    return;
                }
                if (!Object.prototype.hasOwnProperty.call(sectionMetaMap, sectionId)) {
                    sectionMetaMap[sectionId] = {
                        id: sectionId,
                        title: makeLabel(sectionId),
                        description: null,
                        fields: [],
                        collapsible: false,
                        collapsed: false,
                        icon: null
                    };
                    sectionsInfo.list.push(sectionMetaMap[sectionId]);
                }
                if (sectionMetaMap[sectionId].fields.indexOf(field) === -1) {
                    sectionMetaMap[sectionId].fields.push(field);
                }
                sectionsInfo.map[field] = sectionId;
            });

            sectionsInfo.list = sectionsInfo.list.filter(function(section) {
                section.fields = ordering.filter(function(field) {
                    return sectionsInfo.map[field] === section.id;
                });
                return section.fields.length > 0;
            });

            var hasTabs = tabOrder.length > 0;
            var normalizedFields = ordering.map(function(field) {
                return {
                    name: field,
                    tab: fieldTabMap[field] || null,
                    section: sectionsInfo.map[field] || null
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
                defaultTab: defaultTab,
                sections: sectionsInfo.list
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
                var attachedControls = input.data && typeof input.data === 'function'
                    ? input.data('fastcrudControls')
                    : null;
                if (attachedControls && attachedControls.length) {
                    attachedControls.addClass('is-invalid');
                }
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

        function showLoadingRow(colspan, message) {
            var tbody = table.find('tbody');
            var row = $('<tr class="fastcrud-loading-row"></tr>');
            var cell = $('<td></td>')
                .attr('colspan', colspan)
                .addClass('text-center fastcrud-loading-placeholder');
            var wrapper = $('<div class="d-inline-flex align-items-center gap-2"></div>');
            var spinner = $('<span class="spinner-border spinner-border-sm" role="status"></span>');
            spinner.append('<span class="visually-hidden">Loading...</span>');
            wrapper.append(spinner);
            wrapper.append($('<span class="fastcrud-loading-text"></span>').text(message || 'Loading...'));
            cell.append(wrapper);
            row.append(cell);
            tbody.html(row);
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

        function deepClone(value) {
            try {
                return JSON.parse(JSON.stringify(value));
            } catch (e) {
                return value;
            }
        }

        function hasNestedTablesConfigured() {
            return Array.isArray(nestedTablesConfig) && nestedTablesConfig.length > 0;
        }

        function buildActionCellHtml(rowMeta) {
            var fragments = [];
            var duplicateActionClass = getStyleClass('duplicate_action_button_class', 'btn btn-sm btn-info');
            var viewActionClass = getStyleClass('view_action_button_class', 'btn btn-sm btn-secondary');
            var editActionClass = getStyleClass('edit_action_button_class', 'btn btn-sm btn-primary');
            var deleteActionClass = getStyleClass('delete_action_button_class', 'btn btn-sm btn-danger');

            if (rowMeta && Array.isArray(rowMeta.link_buttons)) {
                rowMeta.link_buttons.forEach(function(linkMeta) {
                    if (!linkMeta || typeof linkMeta !== 'object') {
                        return;
                    }

                    var href = String(linkMeta.url || '').trim();
                    if (!href.length) {
                        return;
                    }
                    var classSource = String(linkMeta.button_class || '').trim();
                    var classParts = classSource.length ? classSource.split(/\s+/) : [];
                    if (classParts.indexOf('btn') === -1) {
                        classParts.unshift('btn');
                    }
                    if (classParts.indexOf('btn-sm') === -1) {
                        classParts.push('btn-sm');
                    }
                    if (classParts.indexOf('fastcrud-action-button') === -1) {
                        classParts.push('fastcrud-action-button');
                    }
                    if (classParts.indexOf('fastcrud-link-btn') === -1) {
                        classParts.push('fastcrud-link-btn');
                    }
                    var labelRaw = typeof linkMeta.label === 'string' ? linkMeta.label : '';
                    var labelText = labelRaw.trim();
                    var options = linkMeta.options && typeof linkMeta.options === 'object'
                        ? Object.assign({}, linkMeta.options)
                        : {};
                    var attrString = '';
                    var hasTitleAttr = false;
                    var hasAriaAttr = false;
                    var hasRoleAttr = false;

                    var optionClassRaw = '';
                    if (Object.prototype.hasOwnProperty.call(options, 'class')) {
                        optionClassRaw = String(options['class'] || '').trim();
                        delete options['class'];
                    }

                    if (optionClassRaw) {
                        optionClassRaw.split(/\s+/).forEach(function(extra) {
                            if (!extra) { return; }
                            if (classParts.indexOf(extra) === -1) {
                                classParts.push(extra);
                            }
                        });
                    }

                    Object.keys(options).forEach(function(optionKey) {
                        if (!Object.prototype.hasOwnProperty.call(options, optionKey)) {
                            return;
                        }
                        var attrName = String(optionKey);
                        if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                            return;
                        }
                        var lowerName = attrName.toLowerCase();
                        if (lowerName === 'href' || lowerName === 'class') {
                            return;
                        }
                        if (lowerName === 'title') {
                            hasTitleAttr = true;
                        } else if (lowerName === 'aria-label') {
                            hasAriaAttr = true;
                        } else if (lowerName === 'role') {
                            hasRoleAttr = true;
                        }
                        var attrValue = options[optionKey];
                        if (attrValue === null || typeof attrValue === 'undefined') {
                            return;
                        }
                        attrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                    });

                    if (!hasAriaAttr) {
                        var ariaLabel = labelText ? labelText : 'Open link';
                        attrString += ' aria-label="' + escapeHtml(ariaLabel) + '"';
                    }
                    if (!hasTitleAttr && labelText) {
                        attrString += ' title="' + escapeHtml(labelText) + '"';
                    }
                    if (!hasRoleAttr) {
                        attrString += ' role="button"';
                    }

                    var iconClass = typeof linkMeta.icon === 'string' ? linkMeta.icon.trim() : '';
                    var iconHtml = iconClass ? '<i class="fastcrud-link-icon ' + escapeHtml(iconClass) + '"></i>' : '';
                    var contentHtml;
                    if (iconHtml && labelText) {
                        contentHtml = iconHtml + '<span class="fastcrud-link-btn-text ms-1">' + escapeHtml(labelText) + '</span>';
                    } else if (iconHtml) {
                        contentHtml = iconHtml;
                    } else if (labelText) {
                        contentHtml = escapeHtml(labelText);
                    } else {
                        contentHtml = '<span class="visually-hidden">Open link</span>';
                    }

                    var classAttr = classParts.join(' ');
                    fragments.push('<a href="' + escapeHtml(href) + '" class="' + escapeHtml(classAttr) + '"' + attrString + '>' + contentHtml + '</a>');
                });
            }

            if (rowMeta && Array.isArray(rowMeta.multi_link_buttons)) {
                rowMeta.multi_link_buttons.forEach(function(multiMeta) {
                    if (!multiMeta || typeof multiMeta !== 'object') {
                        return;
                    }

                    var buttonMeta = multiMeta.button && typeof multiMeta.button === 'object'
                        ? multiMeta.button
                        : {};
                    var multiItems = Array.isArray(multiMeta.items) ? multiMeta.items : [];
                    if (!multiItems.length) {
                        return;
                    }

                    var dropdownItems = [];
                    multiItems.forEach(function(item) {
                        if (!item || typeof item !== 'object') {
                            return;
                        }

                        var itemTypeRaw = typeof item.type === 'string' ? item.type : '';
                        var itemType = itemTypeRaw ? itemTypeRaw.trim().toLowerCase() : '';
                        if (!itemType) {
                            itemType = 'link';
                        }

                        if (itemType === 'divider') {
                            dropdownItems.push('<li><hr class="dropdown-divider fastcrud-multi-link-divider" role="separator"></li>');
                            return;
                        }

                        if (itemType === 'duplicate') {
                            var duplicateLabelRaw = typeof item.label === 'string' ? item.label : '';
                            var duplicateLabel = duplicateLabelRaw.trim();
                            if (!duplicateLabel) {
                                duplicateLabel = 'Duplicate';
                            }

                            var duplicateClassParts = ['dropdown-item', 'fastcrud-multi-link-item', 'fastcrud-duplicate-btn'];
                            var duplicateOptions = item.options && typeof item.options === 'object'
                                ? Object.assign({}, item.options)
                                : {};
                            var duplicateOptionClassRaw = '';
                            if (Object.prototype.hasOwnProperty.call(duplicateOptions, 'class')) {
                                duplicateOptionClassRaw = String(duplicateOptions['class'] || '').trim();
                                delete duplicateOptions['class'];
                            }
                            if (duplicateOptionClassRaw) {
                                duplicateOptionClassRaw.split(/\s+/).forEach(function(extra) {
                                    if (!extra) { return; }
                                    if (duplicateClassParts.indexOf(extra) === -1) {
                                        duplicateClassParts.push(extra);
                                    }
                                });
                            }

                            var duplicateAttrString = '';
                            var duplicateHasTitle = false;
                            var duplicateHasAria = false;
                            var duplicateHasRole = false;

                            Object.keys(duplicateOptions).forEach(function(optionKey) {
                                if (!Object.prototype.hasOwnProperty.call(duplicateOptions, optionKey)) {
                                    return;
                                }
                                var attrName = String(optionKey);
                                if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                                    return;
                                }
                                var lowerName = attrName.toLowerCase();
                                if (lowerName === 'href' || lowerName === 'class') {
                                    return;
                                }
                                if (lowerName === 'title') {
                                    duplicateHasTitle = true;
                                } else if (lowerName === 'aria-label') {
                                    duplicateHasAria = true;
                                } else if (lowerName === 'role') {
                                    duplicateHasRole = true;
                                }
                                var attrValue = duplicateOptions[optionKey];
                                if (attrValue === null || typeof attrValue === 'undefined') {
                                    return;
                                }
                                duplicateAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                            });

                            if (!duplicateHasAria) {
                                duplicateAttrString += ' aria-label="' + escapeHtml(duplicateLabel) + '"';
                            }
                            if (!duplicateHasTitle) {
                                duplicateAttrString += ' title="' + escapeHtml(duplicateLabel) + '"';
                            }
                            if (!duplicateHasRole) {
                                duplicateAttrString += ' role="menuitem"';
                            }

                            var uniqueDuplicateClassParts = [];
                            duplicateClassParts.forEach(function(part) {
                                if (!part) { return; }
                                if (uniqueDuplicateClassParts.indexOf(part) === -1) {
                                    uniqueDuplicateClassParts.push(part);
                                }
                            });

                            var duplicateClassAttr = uniqueDuplicateClassParts.join(' ');
                            var duplicateIconClass = item.icon && typeof item.icon === 'string' ? item.icon.trim() : '';
                            var duplicateContent;
                            if (duplicateIconClass) {
                                var duplicateIconHtml = '<i class="fastcrud-multi-link-item-icon ' + escapeHtml(duplicateIconClass) + '"></i>';
                                duplicateContent = duplicateIconHtml + '<span class="fastcrud-multi-link-item-text ms-2">' + escapeHtml(duplicateLabel) + '</span>';
                            } else {
                                duplicateContent = escapeHtml(duplicateLabel);
                            }

                            dropdownItems.push('<li><button type="button" class="' + escapeHtml(duplicateClassAttr) + '"' + duplicateAttrString + '>' + duplicateContent + '</button></li>');
                            return;
                        }

                        if (itemType === 'delete') {
                            var deleteLabelRaw = typeof item.label === 'string' ? item.label : '';
                            var deleteLabel = deleteLabelRaw.trim();
                            if (!deleteLabel) {
                                deleteLabel = 'Delete';
                            }

                            var deleteClassParts = ['dropdown-item', 'fastcrud-multi-link-item', 'fastcrud-delete-btn'];
                            var deleteOptions = item.options && typeof item.options === 'object'
                                ? Object.assign({}, item.options)
                                : {};
                            var deleteOptionClassRaw = '';
                            if (Object.prototype.hasOwnProperty.call(deleteOptions, 'class')) {
                                deleteOptionClassRaw = String(deleteOptions['class'] || '').trim();
                                delete deleteOptions['class'];
                            }
                            if (deleteOptionClassRaw) {
                                deleteOptionClassRaw.split(/\s+/).forEach(function(extra) {
                                    if (!extra) { return; }
                                    if (deleteClassParts.indexOf(extra) === -1) {
                                        deleteClassParts.push(extra);
                                    }
                                });
                            }

                            var deleteAttrString = '';
                            var deleteHasTitle = false;
                            var deleteHasAria = false;
                            var deleteHasRole = false;

                            Object.keys(deleteOptions).forEach(function(optionKey) {
                                if (!Object.prototype.hasOwnProperty.call(deleteOptions, optionKey)) {
                                    return;
                                }
                                var attrName = String(optionKey);
                                if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                                    return;
                                }
                                var lowerName = attrName.toLowerCase();
                                if (lowerName === 'href' || lowerName === 'class') {
                                    return;
                                }
                                if (lowerName === 'title') {
                                    deleteHasTitle = true;
                                } else if (lowerName === 'aria-label') {
                                    deleteHasAria = true;
                                } else if (lowerName === 'role') {
                                    deleteHasRole = true;
                                }
                                var attrValue = deleteOptions[optionKey];
                                if (attrValue === null || typeof attrValue === 'undefined') {
                                    return;
                                }
                                deleteAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                            });

                            if (!deleteHasAria) {
                                deleteAttrString += ' aria-label="' + escapeHtml(deleteLabel) + '"';
                            }
                            if (!deleteHasTitle) {
                                deleteAttrString += ' title="' + escapeHtml(deleteLabel) + '"';
                            }
                            if (!deleteHasRole) {
                                deleteAttrString += ' role="menuitem"';
                            }

                            var uniqueDeleteClassParts = [];
                            deleteClassParts.forEach(function(part) {
                                if (!part) { return; }
                                if (uniqueDeleteClassParts.indexOf(part) === -1) {
                                    uniqueDeleteClassParts.push(part);
                                }
                            });

                            var deleteClassAttr = uniqueDeleteClassParts.join(' ');
                            var deleteIconClass = item.icon && typeof item.icon === 'string' ? item.icon.trim() : '';
                            var deleteContent;
                            if (deleteIconClass) {
                                var deleteIconHtml = '<i class="fastcrud-multi-link-item-icon ' + escapeHtml(deleteIconClass) + '"></i>';
                                deleteContent = deleteIconHtml + '<span class="fastcrud-multi-link-item-text ms-2">' + escapeHtml(deleteLabel) + '</span>';
                            } else {
                                deleteContent = escapeHtml(deleteLabel);
                            }

                            dropdownItems.push('<li><button type="button" class="' + escapeHtml(deleteClassAttr) + '"' + deleteAttrString + '>' + deleteContent + '</button></li>');
                            return;
                        }

                        var itemHref = String(item.url || '').trim();
                        var itemLabelRaw = typeof item.label === 'string' ? item.label : '';
                        var itemLabel = itemLabelRaw.trim();
                        if (!itemHref || !itemLabel) {
                            return;
                        }

                        var itemClassParts = ['dropdown-item', 'fastcrud-multi-link-item'];
                        var itemOptions = item.options && typeof item.options === 'object'
                            ? Object.assign({}, item.options)
                            : {};
                        var itemOptionClassRaw = '';
                        if (Object.prototype.hasOwnProperty.call(itemOptions, 'class')) {
                            itemOptionClassRaw = String(itemOptions['class'] || '').trim();
                            delete itemOptions['class'];
                        }
                        if (itemOptionClassRaw) {
                            itemOptionClassRaw.split(/\s+/).forEach(function(extra) {
                                if (!extra) { return; }
                                if (itemClassParts.indexOf(extra) === -1) {
                                    itemClassParts.push(extra);
                                }
                            });
                        }

                        var itemAttrString = '';
                        var itemHasTitle = false;
                        var itemHasAria = false;
                        var itemHasRole = false;

                        Object.keys(itemOptions).forEach(function(optionKey) {
                            if (!Object.prototype.hasOwnProperty.call(itemOptions, optionKey)) {
                                return;
                            }
                            var attrName = String(optionKey);
                            if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                                return;
                            }
                            var lowerName = attrName.toLowerCase();
                            if (lowerName === 'href' || lowerName === 'class') {
                                return;
                            }
                            if (lowerName === 'title') {
                                itemHasTitle = true;
                            } else if (lowerName === 'aria-label') {
                                itemHasAria = true;
                            } else if (lowerName === 'role') {
                                itemHasRole = true;
                            }
                            var attrValue = itemOptions[optionKey];
                            if (attrValue === null || typeof attrValue === 'undefined') {
                                return;
                            }
                            itemAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                        });

                        if (!itemHasAria) {
                            itemAttrString += ' aria-label="' + escapeHtml(itemLabel) + '"';
                        }
                        if (!itemHasTitle) {
                            itemAttrString += ' title="' + escapeHtml(itemLabel) + '"';
                        }
                        if (!itemHasRole) {
                            itemAttrString += ' role="menuitem"';
                        }

                        var uniqueItemClassParts = [];
                        itemClassParts.forEach(function(part) {
                            if (!part) { return; }
                            if (uniqueItemClassParts.indexOf(part) === -1) {
                                uniqueItemClassParts.push(part);
                            }
                        });

                        var itemClassAttr = uniqueItemClassParts.join(' ');
                        var itemIconClass = item.icon && typeof item.icon === 'string' ? item.icon.trim() : '';
                        var itemContent;
                        if (itemIconClass) {
                            var itemIconHtml = '<i class="fastcrud-multi-link-item-icon ' + escapeHtml(itemIconClass) + '"></i>';
                            itemContent = itemIconHtml + '<span class="fastcrud-multi-link-item-text ms-2">' + escapeHtml(itemLabel) + '</span>';
                        } else {
                            itemContent = escapeHtml(itemLabel);
                        }

                        dropdownItems.push('<li><a href="' + escapeHtml(itemHref) + '" class="' + escapeHtml(itemClassAttr) + '"' + itemAttrString + '>' + itemContent + '</a></li>');
                    });

                    if (!dropdownItems.length) {
                        return;
                    }

                    var triggerClassSource = String(buttonMeta.button_class || '').trim();
                    var triggerClassParts = triggerClassSource ? triggerClassSource.split(/\s+/) : [];
                    if (triggerClassParts.indexOf('btn') === -1) { triggerClassParts.unshift('btn'); }
                    var hasSizeClass = triggerClassParts.some(function(part) {
                        return /^btn-(?:sm|md|lg|xl)$/i.test(part);
                    });
                    if (!hasSizeClass) {
                        triggerClassParts.push('btn-sm');
                    }
                    if (triggerClassParts.indexOf('dropdown-toggle') === -1) { triggerClassParts.push('dropdown-toggle'); }
                    if (triggerClassParts.indexOf('fastcrud-action-button') === -1) { triggerClassParts.push('fastcrud-action-button'); }
                    if (triggerClassParts.indexOf('fastcrud-multi-link-trigger') === -1) { triggerClassParts.push('fastcrud-multi-link-trigger'); }

                    var triggerOptions = buttonMeta.options && typeof buttonMeta.options === 'object'
                        ? Object.assign({}, buttonMeta.options)
                        : {};
                    var triggerOptionClassRaw = '';
                    if (Object.prototype.hasOwnProperty.call(triggerOptions, 'class')) {
                        triggerOptionClassRaw = String(triggerOptions['class'] || '').trim();
                        delete triggerOptions['class'];
                    }
                    if (triggerOptionClassRaw) {
                        triggerOptionClassRaw.split(/\s+/).forEach(function(extra) {
                            if (!extra) { return; }
                            if (triggerClassParts.indexOf(extra) === -1) {
                                triggerClassParts.push(extra);
                            }
                        });
                    }

                    var triggerAttrString = '';
                    var triggerHasTitle = false;
                    var triggerHasAria = false;
                    var triggerHasRole = false;
                    var triggerHasToggle = false;
                    var triggerHasExpanded = false;
                    var triggerHasHaspopup = false;

                    Object.keys(triggerOptions).forEach(function(optionKey) {
                        if (!Object.prototype.hasOwnProperty.call(triggerOptions, optionKey)) {
                            return;
                        }
                        var attrName = String(optionKey);
                        if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                            return;
                        }
                        var lowerName = attrName.toLowerCase();
                        if (lowerName === 'class' || lowerName === 'href') {
                            return;
                        }
                        if (lowerName === 'title') {
                            triggerHasTitle = true;
                        } else if (lowerName === 'aria-label') {
                            triggerHasAria = true;
                        } else if (lowerName === 'role') {
                            triggerHasRole = true;
                        } else if (lowerName === 'data-bs-toggle') {
                            triggerHasToggle = true;
                        } else if (lowerName === 'aria-expanded') {
                            triggerHasExpanded = true;
                        } else if (lowerName === 'aria-haspopup') {
                            triggerHasHaspopup = true;
                        }
                        var attrValue = triggerOptions[optionKey];
                        if (attrValue === null || typeof attrValue === 'undefined') {
                            return;
                        }
                        triggerAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                    });

                    var uniqueTriggerClassParts = [];
                    triggerClassParts.forEach(function(part) {
                        if (!part) {
                            return;
                        }
                        if (uniqueTriggerClassParts.indexOf(part) === -1) {
                            uniqueTriggerClassParts.push(part);
                        }
                    });
                    triggerClassParts = uniqueTriggerClassParts;

                    var triggerLabel = typeof buttonMeta.label === 'string' ? buttonMeta.label.trim() : '';

                    if (!triggerHasAria) {
                        var triggerAriaLabel = triggerLabel ? triggerLabel : 'Open menu';
                        triggerAttrString += ' aria-label="' + escapeHtml(triggerAriaLabel) + '"';
                    }
                    if (!triggerHasTitle && triggerLabel) {
                        triggerAttrString += ' title="' + escapeHtml(triggerLabel) + '"';
                    }
                    if (!triggerHasRole) {
                        triggerAttrString += ' role="button"';
                    }
                    if (!triggerHasToggle) {
                        triggerAttrString += ' data-bs-toggle="dropdown"';
                    }
                    if (!triggerHasExpanded) {
                        triggerAttrString += ' aria-expanded="false"';
                    }
                    if (!triggerHasHaspopup) {
                        triggerAttrString += ' aria-haspopup="true"';
                    }

                    var triggerClassAttr = triggerClassParts.join(' ');
                    var triggerIconClass = typeof buttonMeta.icon === 'string' ? buttonMeta.icon.trim() : '';
                    var triggerIconHtml = triggerIconClass
                        ? '<i class="fastcrud-multi-link-icon ' + escapeHtml(triggerIconClass) + '"></i>'
                        : '';
                    var triggerContent;
                    if (triggerIconHtml && triggerLabel) {
                        triggerContent = triggerIconHtml + '<span class="fastcrud-multi-link-text ms-1">' + escapeHtml(triggerLabel) + '</span>';
                    } else if (triggerIconHtml) {
                        triggerContent = triggerIconHtml;
                    } else if (triggerLabel) {
                        triggerContent = escapeHtml(triggerLabel);
                    } else {
                        triggerContent = '<span class="visually-hidden">Open menu</span>';
                    }

                    var menuClassSource = String(buttonMeta.menu_class || '').trim();
                    if (!menuClassSource) {
                        menuClassSource = 'dropdown-menu dropdown-menu-end';
                    }
                    var menuClassParts = menuClassSource ? menuClassSource.split(/\s+/) : [];
                    if (menuClassParts.indexOf('dropdown-menu') === -1) {
                        menuClassParts.unshift('dropdown-menu');
                    }
                    if (menuClassParts.indexOf('fastcrud-multi-link-menu') === -1) {
                        menuClassParts.push('fastcrud-multi-link-menu');
                    }
                    var uniqueMenuClassParts = [];
                    menuClassParts.forEach(function(part) {
                        if (!part) {
                            return;
                        }
                        if (uniqueMenuClassParts.indexOf(part) === -1) {
                            uniqueMenuClassParts.push(part);
                        }
                    });
                    var menuClassAttr = uniqueMenuClassParts.join(' ');

                    var containerClassSource = String(buttonMeta.container_class || '').trim();
                    if (!containerClassSource) {
                        containerClassSource = 'btn-group';
                    }
                    var containerClassParts = containerClassSource ? containerClassSource.split(/\s+/) : [];
                    if (containerClassParts.indexOf('fastcrud-action-button-group') === -1) {
                        containerClassParts.push('fastcrud-action-button-group');
                    }
                    if (containerClassParts.indexOf('fastcrud-multi-link-btn') === -1) {
                        containerClassParts.push('fastcrud-multi-link-btn');
                    }
                    var hasWrapperClass = containerClassParts.some(function(part) {
                        var lower = part.toLowerCase();
                        return lower === 'btn-group' || lower === 'dropdown' || lower === 'dropup' || lower === 'dropend' || lower === 'dropstart';
                    });
                    if (!hasWrapperClass) {
                        containerClassParts.unshift('btn-group');
                    }
                    var uniqueContainerClassParts = [];
                    containerClassParts.forEach(function(part) {
                        if (!part) {
                            return;
                        }
                        if (uniqueContainerClassParts.indexOf(part) === -1) {
                            uniqueContainerClassParts.push(part);
                        }
                    });
                    var containerClassAttr = uniqueContainerClassParts.join(' ');

                    var triggerHtml = '<button type="button" class="' + escapeHtml(triggerClassAttr) + '"' + triggerAttrString + '>' + triggerContent + '</button>';
                    var menuHtml = '<ul class="' + escapeHtml(menuClassAttr) + '" role="menu">' + dropdownItems.join('') + '</ul>';

                    fragments.push('<div class="' + escapeHtml(containerClassAttr) + '">' + triggerHtml + menuHtml + '</div>');
                });
            }

            if (duplicateEnabled) {
                var allowDuplicate = Object.prototype.hasOwnProperty.call(rowMeta, 'duplicate_allowed')
                    ? !!rowMeta.duplicate_allowed
                    : true;

                if (allowDuplicate) {
                    // Place duplicate button to the left of other action buttons
                    var duplicateClassAttr = (duplicateActionClass + ' fastcrud-action-button fastcrud-duplicate-btn').trim();
                    fragments.push('<button type="button" class="' + escapeHtml(duplicateClassAttr) + '" title="Duplicate" aria-label="Duplicate record">' + actionIcons.duplicate + '</button>');
                }
            }

            if (viewEnabled) {
                var allowView = Object.prototype.hasOwnProperty.call(rowMeta, 'view_allowed')
                    ? !!rowMeta.view_allowed
                    : true;

                if (allowView) {
                    var viewClassAttr = (viewActionClass + ' fastcrud-action-button fastcrud-view-btn').trim();
                    fragments.push('<button type="button" class="' + escapeHtml(viewClassAttr) + '" title="View" aria-label="View record">' + actionIcons.view + '</button>');
                }
            }

            if (editEnabled) {
                var allowEdit = Object.prototype.hasOwnProperty.call(rowMeta, 'edit_allowed')
                    ? !!rowMeta.edit_allowed
                    : true;

                if (allowEdit) {
                    var editClassAttr = (editActionClass + ' fastcrud-action-button fastcrud-edit-btn').trim();
                    fragments.push('<button type="button" class="' + escapeHtml(editClassAttr) + '" title="Edit" aria-label="Edit record">' + actionIcons.edit + '</button>');
                }
            }

            if (deleteEnabled) {
                var allowDelete = Object.prototype.hasOwnProperty.call(rowMeta, 'delete_allowed')
                    ? !!rowMeta.delete_allowed
                    : true;

                if (allowDelete) {
                    var deleteClassAttr = (deleteActionClass + ' fastcrud-action-button fastcrud-delete-btn').trim();
                    fragments.push('<button type="button" class="' + escapeHtml(deleteClassAttr) + '" title="Delete" aria-label="Delete record">' + actionIcons.delete + '</button>');
                }
            }

            var buttonsHtml = fragments.join('');
            return '<td class="text-end fastcrud-actions-cell"><div class="fastcrud-actions-stack">' + buttonsHtml + '</div></td>';
        }

        function populateTableRows(rows, pagination) {
            var tbody = table.find('tbody');
            var totalColumns = table.find('thead th').length || 1;

            rows = Array.isArray(rows) ? rows : [];

            if (!rows || rows.length === 0) {
                tbody.html('');
                showEmptyRow(totalColumns, 'No records found.');
                refreshSelectAllState();
                updateBatchDeleteButtonState();
                return;
            }

            var html = '';
            var expandQueue = [];
            var hasNested = hasNestedTablesConfigured();
            var paginationInfo = (pagination && typeof pagination === 'object') ? pagination : null;
            var numbersPerPage = perPage;
            if (paginationInfo && typeof paginationInfo.per_page === 'number') {
                numbersPerPage = paginationInfo.per_page;
            }
            if (!numbersPerPage || numbersPerPage < 1) {
                numbersPerPage = rows.length;
            }
            var numbersPage = currentPage;
            if (paginationInfo && typeof paginationInfo.current_page === 'number' && paginationInfo.current_page > 0) {
                numbersPage = paginationInfo.current_page;
            }
            if (!numbersPage || numbersPage < 1) {
                numbersPage = 1;
            }
            var numberOffset = numbersEnabled ? Math.max(0, (numbersPage - 1) * numbersPerPage) : 0;

            $.each(rows, function(rowIndex, row) {
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
                        if (typeof rawValue !== 'undefined') {
                            rowData[key] = rawValue;
                        }
                    });
                }

                var rowKey = null;
                var primaryValueString = (typeof primaryValue === 'undefined' || primaryValue === null)
                    ? ''
                    : String(primaryValue);
                if (rowPrimaryKeyColumn && primaryValueString !== '') {
                    try {
                        rowKey = rowCacheKey(rowPrimaryKeyColumn, primaryValueString);
                        rowCache[rowKey] = rowData;
                    } catch (e) {}
                }

                var cells = '';
                var rowDeleteAllowed = deleteEnabled;
                if (rowDeleteAllowed && Object.prototype.hasOwnProperty.call(rowMeta, 'delete_allowed')) {
                    rowDeleteAllowed = !!rowMeta.delete_allowed;
                }

                var rowEditAllowed = editEnabled;
                if (rowEditAllowed && Object.prototype.hasOwnProperty.call(rowMeta, 'edit_allowed')) {
                    rowEditAllowed = !!rowMeta.edit_allowed;
                }

                if (hasNested) {
                    var isExpanded = rowKey && nestedRowStates[rowKey];
                    if (isExpanded && rowKey) {
                        expandQueue.push({
                            key: rowKey,
                            pk: {
                                column: rowPrimaryKeyColumn,
                                value: primaryValueString
                            }
                        });
                    }

                    var ariaLabel = isExpanded ? 'Collapse nested content' : 'Expand nested content';
                    var nestedToggleBaseClass = getStyleClass('nested_toggle_button_classes', 'btn btn-link p-0');
                    var toggleClassValue = 'fastcrud-nested-toggle';
                    if (nestedToggleBaseClass) {
                        toggleClassValue += ' ' + nestedToggleBaseClass;
                    }
                    var toggleAttrs = [
                        'type="button"',
                        'class="' + escapeHtml(toggleClassValue) + '"',
                        'aria-expanded="' + (isExpanded ? 'true' : 'false') + '"',
                        'aria-label="' + escapeHtml(ariaLabel) + '"',
                        'data-fastcrud-expanded="' + (isExpanded ? 'true' : 'false') + '"'
                    ];
                    if (rowKey) {
                        toggleAttrs.push('data-fastcrud-row-key="' + escapeHtml(rowKey) + '"');
                    }
                    if (rowPrimaryKeyColumn) {
                        toggleAttrs.push('data-fastcrud-pk="' + escapeHtml(String(rowPrimaryKeyColumn)) + '"');
                    }
                    if (primaryValueString !== '') {
                        toggleAttrs.push('data-fastcrud-pk-value="' + escapeHtml(primaryValueString) + '"');
                    }
                    var iconHtml = isExpanded ? actionIcons.collapse : actionIcons.expand;
                    cells += '<td class="fastcrud-nested-cell"><button ' + toggleAttrs.join(' ') + '>' + iconHtml + '</button></td>';
                }

                if (batchDeleteEnabled) {
                    var checkboxAttrs = ['type="checkbox"', 'class="form-check-input fastcrud-select-row"'];
                    var selectable = rowPrimaryKeyColumn && primaryValueString !== '';

                    if (selectable) {
                        var selectionAllowed = false;
                        if (allowBatchDeleteButton) {
                            selectionAllowed = rowDeleteAllowed;
                        }

                        if (!selectionAllowed && Array.isArray(bulkActions) && bulkActions.length) {
                            selectionAllowed = rowEditAllowed || rowDeleteAllowed;
                        }

                        selectable = selectionAllowed;
                    }

                    if (selectable) {
                        var selectKey = selectionKey(rowPrimaryKeyColumn, primaryValueString);
                        checkboxAttrs.push('data-fastcrud-key="' + escapeHtml(selectKey) + '"');
                        checkboxAttrs.push('data-fastcrud-pk="' + escapeHtml(String(rowPrimaryKeyColumn)) + '"');
                        checkboxAttrs.push('data-fastcrud-pk-value="' + escapeHtml(primaryValueString) + '"');
                        if (isSelected(rowPrimaryKeyColumn, primaryValueString)) {
                            checkboxAttrs.push('checked');
                        }
                    } else {
                        checkboxAttrs.push('disabled');
                        setSelection(rowPrimaryKeyColumn, primaryValueString, false);
                    }

                    cells += '<td class="text-center fastcrud-select-cell"><input ' + checkboxAttrs.join(' ') + '></td>';
                }

                if (numbersEnabled) {
                    var numericIndex = typeof rowIndex === 'number' ? rowIndex : parseInt(rowIndex, 10);
                    if (isNaN(numericIndex)) {
                        numericIndex = 0;
                    }
                    var rowNumber = numberOffset + numericIndex + 1;
                    cells += '<td class="text-center fastcrud-number-cell">' + escapeHtml(String(rowNumber)) + '</td>';
                }

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
                    try {
                        var baseKey = String(column).indexOf('__') !== -1 ? String(column).split('__').pop() : String(column);
                        if (inlineEditFields[String(column)] || inlineEditFields[String(baseKey)]) {
                            classParts.push('fastcrud-inline-cell');
                        }
                    } catch (e) {}
                    var classAttr = classParts.length ? (' class="' + classParts.join(' ') + '"') : '';
                    var styleEnhance = '';
                    try {
                        var baseKey2 = String(column).indexOf('__') !== -1 ? String(column).split('__').pop() : String(column);
                        if (inlineEditFields[String(column)] || inlineEditFields[String(baseKey2)]) {
                            styleEnhance = 'cursor: text;';
                        }
                    } catch (e) {}
                    var styleAttr = (widthAttr.style || styleEnhance) ? (' style="' + (widthAttr.style ? widthAttr.style + (styleEnhance ? ' ' + styleEnhance : '') : styleEnhance) + '"') : '';

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

                    cells += '<td data-fastcrud-column="' + escapeHtml(String(column)) + '"' + classAttr + styleAttr + attrs + '>' + inner + '</td>';
                });

                cells += buildActionCellHtml(rowMeta);

                var trAttrList = [];
                if (rowMeta.row_class) {
                    trAttrList.push('class="' + escapeHtml(String(rowMeta.row_class)) + '"');
                }
                if (rowPrimaryKeyColumn) {
                    trAttrList.push('data-fastcrud-pk="' + escapeHtml(String(rowPrimaryKeyColumn)) + '"');
                }
                if (primaryValueString !== '') {
                    trAttrList.push('data-fastcrud-pk-value="' + escapeHtml(primaryValueString) + '"');
                }
                if (rowKey) {
                    trAttrList.push('data-fastcrud-row-key="' + escapeHtml(rowKey) + '"');
                }

                var trAttrString = trAttrList.length ? (' ' + trAttrList.join(' ')) : '';
                html += '<tr' + trAttrString + '>' + cells + '</tr>';
            });

            tbody.html(html);
            refreshSelectAllState();
            updateBatchDeleteButtonState();

            if (hasNested && expandQueue.length) {
                expandQueue.forEach(function(entry) {
                    if (!entry || !entry.key) {
                        return;
                    }

                    var targetRow = tbody.find('tr').filter(function() {
                        return $(this).attr('data-fastcrud-row-key') === entry.key;
                    }).first();

                    if (!targetRow.length) {
                        return;
                    }

                    var toggleButton = targetRow.find('.fastcrud-nested-toggle').first();
                    if (!toggleButton.length) {
                        return;
                    }

                    toggleNested(toggleButton, true, entry.pk);
                });
            }
        }

        function resolveNestedParentValue(config, rowData, pkInfo) {
            if (!config) {
                return null;
            }

            var candidates = [];
            if (config.parent_column && typeof config.parent_column === 'string') {
                candidates.push(String(config.parent_column));
            }
            if (config.parent_column_raw && typeof config.parent_column_raw === 'string') {
                candidates.push(String(config.parent_column_raw));
            }

            var value = null;
            for (var index = 0; index < candidates.length; index++) {
                var key = candidates[index];
                if (!key) {
                    continue;
                }

                if (Object.prototype.hasOwnProperty.call(rowData, key)) {
                    value = rowData[key];
                    break;
                }

                if (key.indexOf('.') !== -1) {
                    var tail = key.split('.').pop();
                    if (tail && Object.prototype.hasOwnProperty.call(rowData, tail)) {
                        value = rowData[tail];
                        break;
                    }
                }

                if (key.indexOf('__') !== -1) {
                    var denormalized = key.split('__').join('.');
                    if (Object.prototype.hasOwnProperty.call(rowData, denormalized)) {
                        value = rowData[denormalized];
                        break;
                    }
                }
            }

            if ((value === null || typeof value === 'undefined') && pkInfo && pkInfo.column) {
                if (candidates.indexOf(pkInfo.column) !== -1) {
                    value = pkInfo.value;
                }
            }

            return typeof value === 'undefined' ? null : value;
        }

        function requestNestedTable(target, config, rowData, pkInfo) {
            var container = target;
            var parentValue = resolveNestedParentValue(config, rowData, pkInfo);

            var payload = {
                fastcrud_ajax: '1',
                action: 'nested_fetch',
                table: config.table,
                parent_column: config.parent_column_raw || config.parent_column || '',
                foreign_column: config.foreign_column,
                config: JSON.stringify(config.config || {})
            };

            if (tableId) {
                payload.id = tableId + '--nested--' + (config.name || config.table || 'nested');
            }

            if (!payload.parent_column) {
                container.html('<div class="text-muted">Nested table is missing a parent column definition.</div>');
                return;
            }

            if (parentValue === null || typeof parentValue === 'undefined') {
                payload.parent_value = '__FASTCRUD_NULL__';
            } else {
                payload.parent_value = parentValue;
            }

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: payload,
                success: function(response) {
                    if (response && response.success && response.html) {
                        container.html(response.html);
                    } else {
                        var message = response && response.error ? response.error : 'No records found.';
                        container.html('<div class="text-muted">' + escapeHtml(String(message)) + '</div>');
                    }
                },
                error: function(_, __, error) {
                    container.html('<div class="alert alert-danger mb-0">' + escapeHtml(error || 'Failed to load nested records.') + '</div>');
                }
            });
        }

        function renderNestedSections(wrapper, configs, rowData, pkInfo, rowKey) {
            if (!Array.isArray(configs) || !configs.length) {
                wrapper.append('<div class="text-muted">No nested tables configured.</div>');
                return;
            }

            configs.forEach(function(config) {
                if (!config || typeof config !== 'object') {
                    return;
                }

                var title = (config.label && String(config.label).trim())
                    ? String(config.label).trim()
                    : makeLabel(config.table || 'Nested');

                var section = $('<div class="fastcrud-nested-section"></div>');
                var heading = $('<div class="d-flex justify-content-between align-items-center mb-2"></div>');
                heading.append($('<h6 class="mb-0"></h6>').text(title));
                section.append(heading);

                var body = $('<div class="fastcrud-nested-body border rounded p-3 bg-body"></div>');
                var placeholder = $('<div class="d-flex align-items-center gap-2 text-muted"></div>');
                placeholder.append('<div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div>');
                placeholder.append($('<span></span>').text('Loading ' + title + '...'));
                body.append(placeholder);
                section.append(body);
                wrapper.append(section);

                requestNestedTable(body, config, rowData, pkInfo);
            });
        }

        function collapseNestedRow(button, rowKey) {
            var buttonEl = button && button.jquery ? button : $(button);
            var parentRow = buttonEl.closest('tr');
            var nestedRow = parentRow.next('.fastcrud-nested-row');
            if (nestedRow.length) {
                nestedRow.remove();
            }

            buttonEl.attr('data-fastcrud-expanded', 'false').attr('aria-expanded', 'false').html(actionIcons.expand);
            if (rowKey && Object.prototype.hasOwnProperty.call(nestedRowStates, rowKey)) {
                delete nestedRowStates[rowKey];
            }
        }

        function expandNestedRow(button, rowKey, pkInfo) {
            var buttonEl = button && button.jquery ? button : $(button);
            var parentRow = buttonEl.closest('tr');
            if (!parentRow.length) {
                return;
            }

            var nestedRow = parentRow.next('.fastcrud-nested-row');
            var wrapper;
            if (!nestedRow.length) {
                var colspan = table.find('thead th').length || 1;
                nestedRow = $('<tr class="fastcrud-nested-row"></tr>');
                var nestedCell = $('<td class="fastcrud-nested-cell-container"></td>').attr('colspan', colspan);
                wrapper = $('<div class="fastcrud-nested-wrapper"></div>');
                nestedCell.append(wrapper);
                nestedRow.append(nestedCell);
                parentRow.after(nestedRow);
            } else {
                wrapper = nestedRow.find('.fastcrud-nested-wrapper').first();
                wrapper.empty();
            }

            if (rowKey) {
                nestedRowStates[rowKey] = true;
            }

            buttonEl.attr('data-fastcrud-expanded', 'true').attr('aria-expanded', 'true').html(actionIcons.collapse);

            var loadingNotice = $('<div class="d-flex align-items-center gap-2 text-muted"></div>');
            loadingNotice.append('<div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div>');
            loadingNotice.append($('<span></span>').text('Fetching nested records...'));
            wrapper.append(loadingNotice);

            fetchRowByPk(pkInfo.column, pkInfo.value, 'edit').then(function(rowData) {
                wrapper.empty();
                renderNestedSections(wrapper, nestedTablesConfig, rowData, pkInfo, rowKey);
            }).catch(function(error) {
                wrapper.empty().append(
                    $('<div class="alert alert-danger mb-0"></div>').text((error && error.message) ? error.message : 'Failed to load nested records.')
                );
            });
        }

        function toggleNested(button, forceOpen, pkOverride) {
            if (!hasNestedTablesConfigured()) {
                return;
            }

            var buttonEl = button && button.jquery ? button : $(button);
            if (!buttonEl.length) {
                return;
            }

            var expanded = buttonEl.attr('data-fastcrud-expanded') === 'true';
            var pkInfo = pkOverride || getPkInfoFromElement(buttonEl);
            if (!pkInfo) {
                return;
            }

            if (typeof pkInfo.value !== 'undefined' && pkInfo.value !== null) {
                pkInfo.value = String(pkInfo.value);
            }

            var rowKey = buttonEl.attr('data-fastcrud-row-key') || null;
            if (!rowKey && typeof pkInfo.value !== 'undefined' && pkInfo.value !== null) {
                try {
                    rowKey = rowCacheKey(pkInfo.column, pkInfo.value);
                    buttonEl.attr('data-fastcrud-row-key', rowKey);
                } catch (e) {
                    rowKey = null;
                }
            }

            if (forceOpen) {
                expandNestedRow(buttonEl, rowKey, pkInfo);
                return;
            }

            if (expanded) {
                collapseNestedRow(buttonEl, rowKey);
            } else {
                expandNestedRow(buttonEl, rowKey, pkInfo);
            }
        }

        function loadTableData(page) {
            currentPage = page || 1;
            rowCache = {};
            clearSelection();

            if (activeFetchRequest && typeof activeFetchRequest.abort === 'function') {
                activeFetchRequest.abort();
            }

            var tbody = table.find('tbody');
            var totalColumns = table.find('thead th').length || 1;
            var loadingMessage = 'Loading data...';

            if (!tableHasRendered) {
                showLoadingRow(totalColumns, loadingMessage);
            }

            syncQueryBuilderToConfig();

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

            var request = $.ajax({
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

                        populateTableRows(response.data || [], response.pagination || null);
                        refreshTooltips();
                        renderSummaries(metaConfig.summaries || []);

                        if (response.pagination) {
                            buildPagination(response.pagination);
                        }

                        tableHasRendered = true;
                    } else {
                        var errorMessage = response && response.error ? response.error : 'Failed to load data';
                        showError('Error: ' + errorMessage);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    if (textStatus === 'abort') {
                        return;
                    }
                    var fallbackMessage = typeof errorThrown === 'string' && errorThrown !== ''
                        ? errorThrown
                        : 'Failed to load data';
                    if (debugEnabled) {
                        showError(extractAjaxErrorMessage(jqXHR, fallbackMessage));
                    } else {
                        showError('Failed to load table data: ' + fallbackMessage);
                    }
                },
                complete: function(jqXHR) {
                    if (activeFetchRequest === jqXHR) {
                        activeFetchRequest = null;
                        endLoadingState();
                    }
                }
            });

            activeFetchRequest = request;
        }

        function showEditForm(row) {
            clearFormAlerts();

            var formMode = String(editForm.data('mode') || 'edit');
            var isCreateMode = formMode === 'create';

            if (viewOffcanvasInstance) {
                viewOffcanvasInstance.hide();
            }

            if (!row || typeof row !== 'object') {
                row = {};
            }

            var rowPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!rowPrimaryKeyColumn) {
                showFormError(isCreateMode ? 'Unable to determine primary key for creating records.' : 'Unable to determine primary key for editing.');
                return;
            }

            var primaryKeyValue = Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined'
                ? row.__fastcrud_primary_value
                : row[rowPrimaryKeyColumn];

            editForm.data('primaryKeyColumn', rowPrimaryKeyColumn);

            if (!primaryKeyColumn) {
                primaryKeyColumn = rowPrimaryKeyColumn;
            }

            if (isCreateMode) {
                editForm.data('primaryKeyValue', null);
            } else {
                if (primaryKeyValue === null || typeof primaryKeyValue === 'undefined' || String(primaryKeyValue).length === 0) {
                    showFormError('Missing primary key value for selected record.');
                    return;
                }
                editForm.data('primaryKeyValue', primaryKeyValue);
            }

            if (editLabel.length) {
                if (isCreateMode) {
                    editLabel.text('Add Record');
                } else {
                    editLabel.text('Edit Record ' + primaryKeyValue);
                }
            }

            var submitButtons = editOffcanvasElement.find('button[type="submit"]');
            var submitButtonClose = editOffcanvasElement.find('.fastcrud-submit-close');
            var submitButtonNew = editOffcanvasElement.find('.fastcrud-submit-new');

            if (isCreateMode) {
                if (submitButtonClose.length) {
                    submitButtonClose.text('Create Record & Close');
                }
                if (submitButtonNew.length) {
                    submitButtonNew.text('Create Record & New').removeClass('d-none');
                }
            } else {
                if (submitButtonClose.length) {
                    submitButtonClose.text('Save Changes');
                }
                if (submitButtonNew.length) {
                    submitButtonNew.addClass('d-none');
                }
            }

            destroyRichEditors(editFieldsContainer);
            destroySelect2(editFieldsContainer);

            var editSectionRegistry = editFieldsContainer.data('fastcrud-section-keys');
            if (Array.isArray(editSectionRegistry)) {
                editSectionRegistry.forEach(function(key) {
                    editFieldsContainer.removeData(key);
                });
            }
            editFieldsContainer.removeData('fastcrud-section-keys');

            editFieldsContainer.empty();
            editForm.find('input[type="hidden"][data-fastcrud-field]').remove();

            var templateContext = $.extend({}, row);
            var customFieldHtml = row.__fastcrud_field_html && typeof row.__fastcrud_field_html === 'object'
                ? deepClone(row.__fastcrud_field_html)
                : {};

            var templateForMode = formMode ? getFormTemplate(formMode) : null;
            if (templateForMode && typeof templateForMode === 'object' && templateForMode.__fastcrud_field_html
                && typeof templateForMode.__fastcrud_field_html === 'object') {
                var templateHtml = deepClone(templateForMode.__fastcrud_field_html);
                Object.keys(templateHtml).forEach(function(key) {
                    if (!Object.prototype.hasOwnProperty.call(customFieldHtml, key)) {
                        customFieldHtml[key] = templateHtml[key];
                    }
                });
                row.__fastcrud_field_html = deepClone(customFieldHtml);
            }

            var layout = buildFormLayout(formMode);
            var fields = layout.fields.slice();
            if (!fields.length) {
                var fallbackColumns = baseColumns.length ? baseColumns : columnsCache;
                fields = fallbackColumns
                    .filter(function(column) { return column !== rowPrimaryKeyColumn; })
                    .map(function(column) { return { name: column, tab: null, section: null }; });
            }

            var sectionMetaMap = {};
            if (layout && Array.isArray(layout.sections)) {
                layout.sections.forEach(function(section) {
                    if (!section || typeof section !== 'object' || !section.id) {
                        return;
                    }
                    sectionMetaMap[section.id] = section;
                });
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

            function getSectionMeta(sectionId) {
                if (!sectionId) {
                    return null;
                }
                if (Object.prototype.hasOwnProperty.call(sectionMetaMap, sectionId)) {
                    return sectionMetaMap[sectionId];
                }
                var fallback = {
                    id: sectionId,
                    title: makeLabel(sectionId),
                    description: null,
                    fields: [],
                    collapsible: false,
                    collapsed: false,
                    icon: null
                };
                sectionMetaMap[sectionId] = fallback;
                return fallback;
            }

            function ensureSectionContainer(parentContainer, sectionId) {
                if (!sectionId || !parentContainer || !parentContainer.length) {
                    return parentContainer;
                }

                var dataKey = 'fastcrud-section-' + sectionId;
                var cached = parentContainer.data(dataKey);
                if (cached && cached.body && cached.body.length) {
                    if (cached.wrapper && cached.wrapper.length
                        && parentContainer.length
                        && parentContainer[0]
                        && $.contains(parentContainer[0], cached.wrapper[0])) {
                        return cached.body;
                    }

                    parentContainer.removeData(dataKey);
                    var registry = parentContainer.data('fastcrud-section-keys');
                    if (Array.isArray(registry)) {
                        var index = registry.indexOf(dataKey);
                        if (index !== -1) {
                            registry.splice(index, 1);
                            parentContainer.data('fastcrud-section-keys', registry);
                        }
                    }
                }

                var meta = getSectionMeta(sectionId) || { id: sectionId };
                var title = typeof meta.title === 'string' && meta.title.length ? meta.title : makeLabel(sectionId);
                var description = typeof meta.description === 'string' && meta.description.length ? meta.description : null;
                var collapsible = !!meta.collapsible;
                var collapsed = collapsible && !!meta.collapsed;
                var iconClass = typeof meta.icon === 'string' && meta.icon.length ? meta.icon : null;

                var wrapper = $('<div class="fastcrud-form-section mb-4"></div>')
                    .attr('data-fastcrud-section', sectionId);

                var header = null;
                if (title) {
                    header = $('<div class="d-flex align-items-center justify-content-between mb-2 fastcrud-form-section-header"></div>');
                    var titleHeading = $('<h5 class="mb-0 d-flex align-items-center"></h5>');
                    if (iconClass) {
                        titleHeading.append($('<i class="fastcrud-form-section-icon me-2"></i>').addClass(iconClass));
                    }
                    titleHeading.append($('<span></span>').text(title));
                    header.append(titleHeading);
                    wrapper.append(header);
                }

                if (description) {
                    wrapper.append($('<p class="text-muted mb-3"></p>').text(description));
                }

                var body = $('<div class="fastcrud-form-section-body"></div>');
                wrapper.append(body);

                if (collapsible && header) {
                    var toggle = $('<button type="button" class="btn btn-sm btn-outline-secondary fastcrud-section-toggle" aria-expanded="true"></button>')
                        .html(collapsed ? actionIcons.expand : actionIcons.collapse)
                        .attr('aria-expanded', collapsed ? 'false' : 'true')
                        .attr('aria-label', collapsed ? 'Expand section' : 'Collapse section');
                    header.append(toggle);

                    if (collapsed) {
                        body.addClass('d-none');
                        wrapper.addClass('fastcrud-form-section-collapsed');
                        toggle.attr('aria-expanded', 'false');
                    }

                    toggle.on('click', function() {
                        var isCollapsed = body.hasClass('d-none');
                        if (isCollapsed) {
                            body.removeClass('d-none');
                            wrapper.removeClass('fastcrud-form-section-collapsed');
                            toggle.attr('aria-expanded', 'true')
                                .attr('aria-label', 'Collapse section')
                                .html(actionIcons.collapse);
                        } else {
                            body.addClass('d-none');
                            wrapper.addClass('fastcrud-form-section-collapsed');
                            toggle.attr('aria-expanded', 'false')
                                .attr('aria-label', 'Expand section')
                                .html(actionIcons.expand);
                        }
                    });
                }

                parentContainer.append(wrapper);
                parentContainer.data(dataKey, { wrapper: wrapper, body: body });

                var registry = parentContainer.data('fastcrud-section-keys');
                if (!Array.isArray(registry)) {
                    registry = [];
                }
                if (registry.indexOf(dataKey) === -1) {
                    registry.push(dataKey);
                    parentContainer.data('fastcrud-section-keys', registry);
                }

                return body;
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
                var behaviours = resolveBehavioursForField(column, formMode);
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
                var fieldLabel = resolveFieldLabel(column);

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

                var sectionId = field.section || null;
                var targetContainer = container;
                if (sectionId) {
                    targetContainer = ensureSectionContainer(container, sectionId);
                }

                if (typeof customFieldHtml[column] !== 'undefined') {
                    var customContainer = $('<div class="mb-3"></div>').attr('data-fastcrud-group', column);
                    var htmlContent = customFieldHtml[column];
                    var implicitLabel = false;

                    if (typeof htmlContent === 'string') {
                        implicitLabel = /<label\b/i.test(htmlContent);
                    } else if (htmlContent && (htmlContent.jquery || htmlContent.nodeType === 1)) {
                        var contentProbe = htmlContent.jquery ? htmlContent : $(htmlContent);
                        implicitLabel = contentProbe.is('label') || contentProbe.find('label').length > 0;
                    }

                    if (fieldLabel !== '' && !implicitLabel) {
                        customContainer.append($('<label class="form-label"></label>').text(fieldLabel));
                    } else if (fieldLabel === '') {
                        customContainer.addClass('fastcrud-field-no-label');
                    }

                    if (htmlContent && typeof htmlContent === 'object' && htmlContent.jquery) {
                        customContainer.append(htmlContent);
                    } else if (htmlContent && htmlContent.nodeType === 1 && typeof htmlContent.cloneNode === 'function') {
                        customContainer.append(htmlContent);
                    } else if (typeof htmlContent === 'string') {
                        if (htmlContent.indexOf('<') !== -1) {
                            customContainer.append(htmlContent);
                        } else {
                            customContainer.text(htmlContent);
                        }
                    } else {
                        customContainer.append(htmlContent);
                    }
                    targetContainer.append(customContainer);

                    // Sync value attributes inside custom markup with the resolved current value
                    var valueHolders = customContainer.find('[data-fastcrud-field="' + column + '"]');
                    if (valueHolders.length) {
                        valueHolders.each(function() {
                            var el = $(this);
                            var tagName = el.prop('tagName');
                            if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT') {
                                el.val(currentValue);
                            } else {
                                el.text(currentValue != null ? String(currentValue) : '');
                            }
                        });
                    }
                    return;
                }

                var group = $('<div class="mb-3"></div>').attr('data-fastcrud-group', column);
                var input;
                var compound = null; // optional wrapper for composite inputs (e.g., color)
                var colorPicker = null; // used when changeType === 'color'
                var dataType = changeType;
                var normalizedValue = currentValue;
                var hasExistingPassword = false;
                var applyNativeRequired = true;

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
                } else if (changeType === 'radio') {
                    dataType = 'radio';
                    var radioOptionMap = params.values || params.options || {};
                    var radioOptions = [];
                    if ($.isArray(radioOptionMap)) {
                        radioOptionMap.forEach(function(optionValue) {
                            radioOptions.push({ value: optionValue, label: optionValue });
                        });
                    } else if (typeof radioOptionMap === 'object') {
                        Object.keys(radioOptionMap).forEach(function(key) {
                            radioOptions.push({ value: key, label: radioOptionMap[key] });
                        });
                    }
                    var selectedRadioValue = '';
                    if ($.isArray(normalizedValue) && normalizedValue.length) {
                        selectedRadioValue = String(normalizedValue[0]);
                    } else if (normalizedValue !== null && typeof normalizedValue !== 'undefined') {
                        selectedRadioValue = String(normalizedValue);
                    }
                    var radioValueInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .val(selectedRadioValue);
                    input = radioValueInput;
                    var radioGroup = $('<div class="fastcrud-radio-group"></div>');
                    var radioControls = $();
                    var radioName = fieldId + '-choice';
                    radioOptions.forEach(function(option, index) {
                        var radioId = fieldId + '-radio-' + index;
                        var radioWrapper = $('<div class="form-check"></div>');
                        if (params.inline) {
                            radioWrapper.addClass('form-check-inline');
                        }
                        var radio = $('<input type="radio" class="form-check-input" />')
                            .attr('name', radioName)
                            .attr('id', radioId)
                            .attr('value', option.value);
                        if (selectedRadioValue !== '' && String(option.value) === selectedRadioValue) {
                            radio.prop('checked', true);
                        }
                        var radioLabel = $('<label class="form-check-label"></label>')
                            .attr('for', radioId)
                            .text(option.label);
                        radioWrapper.append(radio).append(radioLabel);
                        radioGroup.append(radioWrapper);
                        radioControls = radioControls.add(radio);
                    });
                    var syncRadioValue = function() {
                        var checked = radioControls.filter(':checked');
                        if (checked.length) {
                            radioValueInput.val(String(checked.first().val() || '')).trigger('change');
                        } else {
                            radioValueInput.val('').trigger('change');
                        }
                    };
                    radioControls.on('change', syncRadioValue);
                    syncRadioValue();
                    input.data('fastcrudExtraElements', radioGroup);
                    input.data('fastcrudControls', radioControls);
                } else if (changeType === 'multicheckbox' || changeType === 'multi_checkbox') {
                    dataType = 'multicheckbox';
                    var checkboxOptionMap = params.values || params.options || {};
                    var checkboxOptions = [];
                    if ($.isArray(checkboxOptionMap)) {
                        checkboxOptionMap.forEach(function(optionValue) {
                            checkboxOptions.push({ value: optionValue, label: optionValue });
                        });
                    } else if (typeof checkboxOptionMap === 'object') {
                        Object.keys(checkboxOptionMap).forEach(function(key) {
                            checkboxOptions.push({ value: key, label: checkboxOptionMap[key] });
                        });
                    }
                    var selectedCheckboxValues = [];
                    if ($.isArray(normalizedValue)) {
                        selectedCheckboxValues = normalizedValue.map(function(item) { return String(item); });
                    } else if (typeof normalizedValue === 'string') {
                        selectedCheckboxValues = normalizedValue.split(',').map(function(item) {
                            return String(item).trim();
                        }).filter(function(item) { return item.length > 0; });
                    } else if (normalizedValue !== null && typeof normalizedValue !== 'undefined') {
                        selectedCheckboxValues = [String(normalizedValue)];
                    }
                    var checkboxValueInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .val(selectedCheckboxValues.join(','));
                    input = checkboxValueInput;
                    var checkboxGroup = $('<div class="fastcrud-multicheckbox-group"></div>');
                    var checkboxControls = $();
                    checkboxOptions.forEach(function(option, index) {
                        var checkboxId = fieldId + '-checkbox-' + index;
                        var checkboxWrapper = $('<div class="form-check"></div>');
                        if (params.inline) {
                            checkboxWrapper.addClass('form-check-inline');
                        }
                        var checkbox = $('<input type="checkbox" class="form-check-input" />')
                            .attr('id', checkboxId)
                            .attr('value', option.value)
                            .attr('name', fieldId + '[]');
                        if (selectedCheckboxValues.indexOf(String(option.value)) !== -1) {
                            checkbox.prop('checked', true);
                        }
                        var checkboxLabel = $('<label class="form-check-label"></label>')
                            .attr('for', checkboxId)
                            .text(option.label);
                        checkboxWrapper.append(checkbox).append(checkboxLabel);
                        checkboxGroup.append(checkboxWrapper);
                        checkboxControls = checkboxControls.add(checkbox);
                    });
                    var syncCheckboxValues = function() {
                        var values = [];
                        checkboxControls.each(function() {
                            var el = $(this);
                            if (el.is(':checked')) {
                                values.push(String(el.val() || ''));
                            }
                        });
                        checkboxValueInput.val(values.join(',')).trigger('change');
                    };
                    checkboxControls.on('change', syncCheckboxValues);
                    syncCheckboxValues();
                    input.data('fastcrudExtraElements', checkboxGroup);
                    input.data('fastcrudControls', checkboxControls);
                } else if (changeType === 'image' || changeType === 'images') {
                    var isMultipleImages = changeType === 'images';
                    // Always use the declared column; no mapping via params.save_to or base column checks

                    var normalizedList = parseImageNameList(currentValue);
                    var uploadSubPath = normalizeUploadSubPath(params.path);
                    if (uploadSubPath) {
                        normalizedList = normalizedList.map(function(name) {
                            if (!name) {
                                return '';
                            }
                            if (name.indexOf('/') === -1 && name.indexOf('\\\\') === -1) {
                                return normalizeStoredImageName(uploadSubPath + '/' + name);
                            }
                            return normalizeStoredImageName(name);
                        }).filter(function(name) { return !!name; });
                    }
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
                    var uploadSubPathFiles = normalizeUploadSubPath(params.path);
                    if (uploadSubPathFiles) {
                        normalizedListFiles = normalizedListFiles.map(function(name) {
                            if (!name) {
                                return '';
                            }
                            if (name.indexOf('/') === -1 && name.indexOf('\\\\') === -1) {
                                return normalizeStoredImageName(uploadSubPathFiles + '/' + name);
                            }
                            return normalizeStoredImageName(name);
                        }).filter(function(name) { return !!name; });
                    }
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
                    // Let the browser open the picker when the swatch is clicked; keep manual edits possible
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
                    if (formMode !== 'create' && normalizedValue !== null && normalizedValue !== '') {
                        hasExistingPassword = true;
                        input.attr('data-fastcrud-password-existing', '1');
                    }
                } else if (changeType === 'bool' || changeType === 'checkbox' || changeType === 'switch') {
                    group.removeClass('mb-3').addClass('form-check mb-3');
                    input = $('<input type="checkbox" class="form-check-input" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', column)
                        .attr('data-fastcrud-type', 'checkbox');
                    var isChecked = normalizedValue === true || normalizedValue === 1 || normalizedValue === '1' || normalizedValue === 'true';
                    input.prop('checked', isChecked);
                    var checkboxLabel = null;
                    if (fieldLabel !== '') {
                        checkboxLabel = $('<label class="form-check-label"></label>')
                            .attr('for', fieldId)
                            .text(fieldLabel);
                    } else {
                        group.addClass('fastcrud-field-no-label');
                    }
                    group.append(input);
                    if (checkboxLabel) {
                        group.append(checkboxLabel);
                    }
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

                if (changeType !== 'bool' && changeType !== 'checkbox' && changeType !== 'switch') {
                    if (fieldLabel !== '') {
                        group.append($('<label class="form-label"></label>').attr('for', labelForId).text(fieldLabel));
                    } else {
                        group.addClass('fastcrud-field-no-label');
                    }
                    if (changeType === 'color' && compound) {
                        group.append(compound);
                    } else {
                        group.append(input);
                    }
                    if (changeType === 'image' || changeType === 'images' || changeType === 'file' || changeType === 'files') {
                        // Append the hidden value holder so it gets included on submit
                        group.append(hiddenInput);
                    }

                    var extraElements = input.data && typeof input.data === 'function'
                        ? input.data('fastcrudExtraElements')
                        : null;
                    if (extraElements) {
                        if ($.isArray(extraElements)) {
                            extraElements.forEach(function(element) {
                                if (!element) {
                                    return;
                                }
                                if (element.jquery) {
                                    group.append(element);
                                } else {
                                    group.append($(element));
                                }
                            });
                        } else if (extraElements.jquery) {
                            group.append(extraElements);
                        } else {
                            group.append($(extraElements));
                        }
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
                    if (changeType === 'password' && hasExistingPassword && formMode !== 'create') {
                        applyNativeRequired = false;
                    }
                    if (applyNativeRequired && !input.is(':checkbox')) {
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

                var attachedControls = input.data && typeof input.data === 'function'
                    ? input.data('fastcrudControls')
                    : null;
                if (attachedControls && attachedControls.length) {
                    if (behaviours.validation_required) {
                        attachedControls.attr('required', 'required');
                    }
                    if (behaviours.readonly || behaviours.disabled) {
                        attachedControls.prop('disabled', true);
                    }
                    if (params.class) {
                        attachedControls.addClass(params.class);
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

                targetContainer.append(group);

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
                                                        valueInput.val(normalizeStoredImageName(storedName));
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
                                        try {
                                            if (clientConfig) {
                                                formData.append('config', JSON.stringify(clientConfig));
                                            }
                                        } catch (e) {}
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
                                                else { valueInput.val(normalizeStoredImageName(storedName)); }
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
                                        try {
                                            if (clientConfig) {
                                                formData.append('config', JSON.stringify(clientConfig));
                                            }
                                        } catch (e) {}
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
                                    else { valueInput.val(normalizeStoredImageName(storedName)); }
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
            initializeSelect2(editFieldsContainer);

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

            var viewSectionRegistry = viewContentContainer.data('fastcrud-view-section-keys');
            if (Array.isArray(viewSectionRegistry)) {
                viewSectionRegistry.forEach(function(key) {
                    viewContentContainer.removeData(key);
                });
            }
            viewContentContainer.removeData('fastcrud-view-section-keys');

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

            var customFieldHtml = row.__fastcrud_field_html && typeof row.__fastcrud_field_html === 'object'
                ? row.__fastcrud_field_html
                : {};

            var viewLayout = buildFormLayout('view');
            var viewSections = Array.isArray(viewLayout.sections) ? viewLayout.sections.slice() : [];
            var viewSectionMetaMap = {};
            viewSections.forEach(function(section) {
                if (!section || typeof section !== 'object' || !section.id) {
                    return;
                }
                viewSectionMetaMap[section.id] = section;
            });
            var VIEW_UNSECTIONED_ID = '__fastcrud_unsectioned__';
            var viewHasSections = viewSections.length > 0;
            var viewFields = viewLayout.fields.length
                ? viewLayout.fields.slice()
                : columnsCache.map(function(column) {
                    return { name: column, tab: null, section: null };
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
            } else if (!viewHasSections) {
                viewContentContainer.addClass('list-group list-group-flush');
            } else {
                viewContentContainer.addClass('fastcrud-view-section-container');
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
                var paneContainer = viewHasSections
                    ? $('<div class="fastcrud-view-section-container"></div>')
                    : $('<div class="list-group list-group-flush"></div>');
                pane.append(paneContainer);
                viewTabsContent.append(pane);

                viewTabEntries[tabName] = { nav: navButton, pane: pane, container: paneContainer, list: paneContainer };
                return viewTabEntries[tabName];
            }

            function getViewSectionMeta(sectionId) {
                if (!sectionId) {
                    return null;
                }
                if (Object.prototype.hasOwnProperty.call(viewSectionMetaMap, sectionId)) {
                    return viewSectionMetaMap[sectionId];
                }

                var fallback = {
                    id: sectionId,
                    title: sectionId === VIEW_UNSECTIONED_ID ? null : makeLabel(sectionId),
                    description: null,
                    fields: [],
                    collapsible: false,
                    collapsed: false,
                    icon: null
                };
                viewSectionMetaMap[sectionId] = fallback;
                return fallback;
            }

            function ensureViewSectionContainer(parentContainer, sectionId) {
                if (!parentContainer || !parentContainer.length) {
                    return parentContainer;
                }

                var effectiveSection = sectionId;
                if (!effectiveSection && viewHasSections) {
                    effectiveSection = VIEW_UNSECTIONED_ID;
                }

                if (!effectiveSection) {
                    return parentContainer;
                }

                var dataKey = 'fastcrud-view-section-' + effectiveSection;
                var cached = parentContainer.data(dataKey);
                if (cached && cached.list && cached.list.length) {
                    if (cached.wrapper && cached.wrapper.length
                        && parentContainer.length
                        && parentContainer[0]
                        && $.contains(parentContainer[0], cached.wrapper[0])) {
                        return cached.list;
                    }

                    parentContainer.removeData(dataKey);
                    var viewRegistry = parentContainer.data('fastcrud-view-section-keys');
                    if (Array.isArray(viewRegistry)) {
                        var idx = viewRegistry.indexOf(dataKey);
                        if (idx !== -1) {
                            viewRegistry.splice(idx, 1);
                            parentContainer.data('fastcrud-view-section-keys', viewRegistry);
                        }
                    }
                }

                var meta = getViewSectionMeta(effectiveSection) || { id: effectiveSection };
                var title = typeof meta.title === 'string' && meta.title.length
                    ? meta.title
                    : (meta.title === null ? null : makeLabel(effectiveSection));
                var description = typeof meta.description === 'string' && meta.description.length ? meta.description : null;
                var collapsible = !!meta.collapsible;
                var collapsed = collapsible && !!meta.collapsed;
                var iconClass = typeof meta.icon === 'string' && meta.icon.length ? meta.icon : null;

                var wrapper = $('<div class="fastcrud-view-section mb-4"></div>')
                    .attr('data-fastcrud-section', effectiveSection);

                var header = null;
                if (title) {
                    header = $('<div class="d-flex align-items-center justify-content-between mb-2 fastcrud-view-section-header"></div>');
                    var heading = $('<h6 class="mb-0 text-uppercase text-muted d-flex align-items-center"></h6>');
                    if (iconClass) {
                        heading.append($('<i class="fastcrud-form-section-icon me-2"></i>').addClass(iconClass));
                    }
                    heading.append($('<span></span>').text(title));
                    header.append(heading);
                    wrapper.append(header);
                }

                if (description) {
                    wrapper.append($('<p class="text-muted small mb-3"></p>').text(description));
                }

                var list = $('<div class="list-group list-group-flush"></div>');
                wrapper.append(list);

                if (collapsible && header) {
                    var toggle = $('<button type="button" class="btn btn-sm btn-outline-secondary fastcrud-section-toggle" aria-expanded="true"></button>')
                        .html(collapsed ? actionIcons.expand : actionIcons.collapse)
                        .attr('aria-expanded', collapsed ? 'false' : 'true')
                        .attr('aria-label', collapsed ? 'Expand section' : 'Collapse section');
                    header.append(toggle);

                    if (collapsed) {
                        list.addClass('d-none');
                        wrapper.addClass('fastcrud-view-section-collapsed');
                        toggle.attr('aria-expanded', 'false');
                    }

                    toggle.on('click', function() {
                        var isCollapsed = list.hasClass('d-none');
                        if (isCollapsed) {
                            list.removeClass('d-none');
                            wrapper.removeClass('fastcrud-view-section-collapsed');
                            toggle.attr('aria-expanded', 'true')
                                .attr('aria-label', 'Collapse section')
                                .html(actionIcons.collapse);
                        } else {
                            list.addClass('d-none');
                            wrapper.addClass('fastcrud-view-section-collapsed');
                            toggle.attr('aria-expanded', 'false')
                                .attr('aria-label', 'Expand section')
                                .html(actionIcons.expand);
                        }
                    });
                }

                parentContainer.append(wrapper);
                parentContainer.data(dataKey, { wrapper: wrapper, list: list });

                var viewRegistry = parentContainer.data('fastcrud-view-section-keys');
                if (!Array.isArray(viewRegistry)) {
                    viewRegistry = [];
                }
                if (viewRegistry.indexOf(dataKey) === -1) {
                    viewRegistry.push(dataKey);
                    parentContainer.data('fastcrud-view-section-keys', viewRegistry);
                }

                return list;
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

                var sectionId = field.section || null;
                var targetContainer = ensureViewSectionContainer(container, sectionId);

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
                if (typeof customFieldHtml[column] !== 'undefined') {
                    var viewHtml = customFieldHtml[column];
                    if (viewHtml && typeof viewHtml === 'object' && viewHtml.jquery) {
                        valueElem.append(viewHtml);
                    } else if (typeof viewHtml === 'string') {
                        if (viewHtml.indexOf('<') !== -1) {
                            valueElem.append(viewHtml);
                        } else {
                            valueElem.text(viewHtml);
                        }
                    } else {
                        valueElem.append(viewHtml);
                    }
                    item.append(valueElem);
                    targetContainer.append(item);
                    viewHasContent = true;
                    return;
                }

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
                targetContainer.append(item);
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

            var formMode = String(editForm.data('mode') || 'edit');
            var isCreateMode = formMode === 'create';
            var primaryColumn = editForm.data('primaryKeyColumn');
            var primaryValue = editForm.data('primaryKeyValue');

            if (!primaryColumn) {
                showFormError('Primary key column missing.');
                return false;
            }

            if (!isCreateMode && (primaryValue === null || typeof primaryValue === 'undefined' || String(primaryValue).length === 0)) {
                showFormError('Primary key value missing.');
                return false;
            }

            clearFormAlerts();
            currentFieldErrors = {};

            if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                window.tinymce.triggerSave();
            }

            var submitButtons = editOffcanvasElement.find('button[type="submit"]');
            var originalTexts = [];
            var submitBusyText = isCreateMode ? 'Creating...' : 'Saving...';
            submitButtons.each(function(index, button) {
                var buttonEl = jQuery(button);
                originalTexts[index] = buttonEl.text();
                buttonEl.prop('disabled', true).text(submitBusyText);
            });

            var submitAction = lastSubmitAction || 'close';
            if (!isCreateMode && submitAction === 'new') {
                submitAction = 'close';
            }

            function restoreSubmitButtons() {
                submitButtons.each(function(index, button) {
                    var buttonEl = jQuery(button);
                    var action = buttonEl.data('fastcrudSubmitAction') || 'close';
                    var fallbackText;
                    if (action === 'new' && isCreateMode) {
                        fallbackText = 'Create Record & New';
                    } else if (isCreateMode) {
                        fallbackText = 'Create Record & Close';
                    } else {
                        fallbackText = 'Save Changes';
                    }

                    var originalText = typeof originalTexts[index] !== 'undefined'
                        ? originalTexts[index]
                        : fallbackText;
                    buttonEl.prop('disabled', false).text(originalText);
                });
            }

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
                    var attachedControls = input.data && typeof input.data === 'function'
                        ? input.data('fastcrudControls')
                        : null;

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
                    } else if (type === 'multicheckbox') {
                        rawValue = input.val();
                        if (rawValue === null || typeof rawValue === 'undefined' || rawValue === '') {
                            valueForField = null;
                            lengthForValidation = 0;
                        } else {
                            var checkboxValues = String(rawValue).split(',').map(function(item) {
                                return String(item).trim();
                            }).filter(function(item) { return item.length > 0; });
                            lengthForValidation = checkboxValues.length;
                            valueForField = checkboxValues.length ? checkboxValues.join(',') : null;
                        }
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
                    } else if (type === 'password') {
                        rawValue = input.val();
                        var hasExistingPassword = String(input.attr('data-fastcrud-password-existing') || '').trim() === '1';
                        if (rawValue === null || typeof rawValue === 'undefined') {
                            valueForField = null;
                            lengthForValidation = hasExistingPassword ? Number.MAX_SAFE_INTEGER : 0;
                        } else {
                            var passwordCandidate = String(rawValue).trim();
                            if (passwordCandidate === '') {
                                valueForField = null;
                                lengthForValidation = hasExistingPassword ? Number.MAX_SAFE_INTEGER : 0;
                            } else {
                                valueForField = passwordCandidate;
                                lengthForValidation = passwordCandidate.length;
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
                            if (attachedControls && attachedControls.length) {
                                attachedControls.addClass('is-invalid');
                            }
                        }
                    }

                    var patternRaw = input.attr('data-fastcrud-pattern');
                    if (patternRaw && valueForField !== null && valueForField !== '' && type !== 'multiselect') {
                        var regex = compileClientPattern(patternRaw);
                        if (regex && !regex.test(String(valueForField))) {
                            validationPassed = false;
                            fieldErrors[column] = 'Value does not match the expected format.';
                            input.addClass('is-invalid');
                            if (attachedControls && attachedControls.length) {
                                attachedControls.addClass('is-invalid');
                            }
                        }
                    }

                    fields[column] = valueForField;
                });

                if (!validationPassed) {
                    currentFieldErrors = fieldErrors;
                    applyFieldErrors(fieldErrors);
                    showFormError('Please fix the highlighted fields.');
                    restoreSubmitButtons();
                    return false;
                }

                var offcanvas = getEditOffcanvasInstance();
                var shouldHideOffcanvas = true;
                if (formOnlyMode) {
                    if (isCreateMode) {
                        shouldHideOffcanvas = submitAction !== 'new';
                    } else {
                        shouldHideOffcanvas = false;
                    }
                }
                if (offcanvas && shouldHideOffcanvas) {
                    offcanvas.hide();
                }

                var requestData = {
                    fastcrud_ajax: '1',
                    action: isCreateMode ? 'create' : 'update',
                    table: tableName,
                    id: tableId,
                    fields: JSON.stringify(fields),
                    config: JSON.stringify(clientConfig)
                };
                if (!isCreateMode) {
                    requestData.primary_key_column = primaryColumn;
                    requestData.primary_key_value = primaryValue;
                }

                $.ajax({
                    url: window.location.pathname,
                    type: 'POST',
                    dataType: 'json',
                    data: requestData,
                    success: function(response) {
                        if (response && response.success) {
                            if (isCreateMode) {
                                editSuccess.text('Record created successfully.');
                            } else {
                                editSuccess.text('Changes saved successfully.');
                            }
                            if (formOnlyMode && !isCreateMode) {
                                editSuccess.removeClass('d-none');
                            } else {
                                editSuccess.addClass('d-none');
                            }
                            currentFieldErrors = {};
                            if (!isCreateMode) {
                                try {
                                    var key = rowCacheKey(primaryColumn, String(primaryValue));
                                    if (response.row) {
                                        rowCache[key] = response.row;
                                    } else if (rowCache[key]) {
                                        delete rowCache[key];
                                    }
                                } catch (e) {}
                            }
                            loadTableData(currentPage);
                            if (isCreateMode && submitAction === 'new') {
                                var resolvedPrimaryForNew = resolvePrimaryKeyColumn();
                                if (!resolvedPrimaryForNew) {
                                    showFormError('Unable to prepare a new form instance.');
                                } else {
                                    var freshRow = getFormTemplate('create');
                                    if (!freshRow) {
                                        freshRow = {
                                            __fastcrud_primary_key: resolvedPrimaryForNew,
                                            __fastcrud_primary_value: null
                                        };
                                    } else {
                                        freshRow.__fastcrud_primary_key = resolvedPrimaryForNew || freshRow.__fastcrud_primary_key || null;
                                        freshRow.__fastcrud_primary_value = null;
                                    }
                                    showEditForm(ensureRowColumns(freshRow));
                                    editSuccess.text('Record created successfully.').removeClass('d-none');
                                }
                            }
                        } else {
                            var fallbackMessage = isCreateMode ? 'Failed to create record.' : 'Failed to update record.';
                            var message = response && response.error ? response.error : fallbackMessage;
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
                        var failureMessage = isCreateMode ? 'Failed to create record: ' + error : 'Failed to update record: ' + error;
                        showFormError(failureMessage);
                        if (offcanvas) {
                            offcanvas.show();
                        }
                    },
                    complete: function() {
                        restoreSubmitButtons();
                        lastSubmitAction = null;
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

        function rowCacheKey(pkCol, pkVal, mode) {
            return tableId + '::' + String(pkCol) + '::' + String(pkVal) + '::' + String(mode || 'edit');
        }

        function fetchRowByPk(pkCol, pkVal, mode) {
            var normalizedMode = typeof mode === 'string' ? mode.toLowerCase() : '';
            if (['create', 'edit', 'view'].indexOf(normalizedMode) === -1) {
                normalizedMode = 'edit';
            }

            var key = rowCacheKey(pkCol, pkVal, normalizedMode);
            if (rowCache[key]) {
                return Promise.resolve(deepClone(rowCache[key]));
            }
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: window.location.pathname,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        fastcrud_ajax: '1',
                        action: 'read',
                        table: tableName,
                        id: tableId,
                        primary_key_column: pkCol,
                        primary_key_value: pkVal,
                        render_mode: normalizedMode,
                        config: JSON.stringify(clientConfig)
                    },
                    success: function(response) {
                        if (response && response.success && response.row) {
                            var row = response.row;
                            if (row && typeof row === 'object') {
                                row.__fastcrud_render_mode = normalizedMode;
                            }
                            rowCache[key] = row;
                            resolve(deepClone(row));
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

        // Inline edit core
        function startInlineEdit(td) {
            var cell = $(td);
            var column = String(cell.attr('data-fastcrud-column') || '');
            var baseKey = column.indexOf('__') !== -1 ? column.split('__').pop() : column;
            if (!column || (!inlineEditFields[column] && !inlineEditFields[baseKey])) {
                return;
            }
            if (cell.closest('td').hasClass('fastcrud-actions-cell')) { return; }
            if (cell.find('input.fastcrud-bool-view').length) { return; }
            if (cell.data('fastcrudEditing')) { return; }

            var pk = getPkInfoFromElement(cell);
            if (!pk) {
                return;
            }

            cell.data('fastcrudEditing', true);
            var originalHtml = cell.html();
            cell.empty();
            var wrapper = $('<div class="fastcrud-inline-editor"></div>');
            var input = null;

            // Try to pick a better editor based on behaviours
            var fieldKey = inlineEditFields[column] ? column : baseKey;
            var behaviours = resolveBehavioursForField(fieldKey, 'edit');
            var changeMeta = behaviours && behaviours.change_type ? behaviours.change_type : {};
            var changeType = String((changeMeta && changeMeta.type) || 'text').toLowerCase();
            var params = (changeMeta && changeMeta.params && typeof changeMeta.params === 'object') ? changeMeta.params : {};

            if (changeType === 'number') {
                input = $('<input type="number" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'email') {
                input = $('<input type="email" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'date') {
                input = $('<input type="date" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'datetime' || changeType === 'datetime-local') {
                input = $('<input type="datetime-local" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'time') {
                input = $('<input type="time" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'color') {
                input = $('<input type="color" class="form-control form-control-color form-control-sm fastcrud-inline-input" />');
            } else if (
                changeType === 'select' ||
                changeType === 'multiselect' ||
                changeType === 'radio' ||
                changeType === 'multicheckbox' ||
                changeType === 'multi_checkbox'
            ) {
                var inlineIsMulti = (changeType === 'multiselect' || changeType === 'multicheckbox' || changeType === 'multi_checkbox');
                input = $('<select class="form-select form-select-sm fastcrud-inline-input" ' + (inlineIsMulti ? 'multiple' : '') + '></select>');
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
                if (params.placeholder && !inlineIsMulti) {
                    input.append($('<option value=""></option>').text(String(params.placeholder)));
                }
                optionsList.forEach(function(option) {
                    input.append($('<option></option>').attr('value', option.value).text(option.label));
                });
            } else {
                input = $('<input type="text" class="form-control form-control-sm fastcrud-inline-input" />');
            }

            wrapper.append(input);
            cell.append(wrapper);

            if (input.is('select')) {
                initializeSelect2(wrapper);
            }

            function restore() {
                if (input && input.is && input.is('select')) {
                    destroySelect2(wrapper);
                }
                cell.data('fastcrudEditing', false);
                cell.html(originalHtml);
            }

            function ensureColor(value) {
                var s = String(value || '').trim();
                if (!s) { return '#000000'; }
                var hex6 = /^#([0-9a-fA-F]{6})$/;
                if (hex6.test(s)) { return s; }
                // accept without #
                if (/^([0-9a-fA-F]{6})$/.test(s)) { return '#' + s; }
                return '#000000';
            }

            var committing = false;
            var skipInitialCommit = true;

            fetchRowByPk(pk.column, pk.value, 'edit').then(function(row){
                var startValue = row && Object.prototype.hasOwnProperty.call(row, fieldKey) ? (row[fieldKey] == null ? '' : String(row[fieldKey])) : '';
                if (changeType === 'color') {
                    input.val(ensureColor(startValue)).focus();
                } else if (input.is('select')) {
                    if (input.prop('multiple')) {
                        var parts = String(startValue).split(',').map(function(s){ return s.trim(); }).filter(function(s){ return s.length; });
                        input.val(parts);
                    } else {
                        input.val(startValue);
                    }
                    if (input.data('select2')) {
                        input.trigger('change');
                    }
                    input.focus();
                } else {
                    input.val(startValue).focus().select();
                }
                skipInitialCommit = false;
            }).catch(function(){
                // If fetch fails, allow editing current display text
                var current = cell.text();
                if (changeType === 'color') {
                    input.val(ensureColor(current)).focus();
                } else if (input.is('select')) {
                    input.val(current).focus();
                    if (input.data('select2')) {
                        input.trigger('change');
                    }
                } else {
                    input.val(current).focus().select();
                }
                skipInitialCommit = false;
            });

            function requestCommit(force) {
                if (!force && skipInitialCommit) {
                    return;
                }
                skipInitialCommit = false;
                commit();
            }

            function commit() {
                if (committing) return;
                committing = true;
                var newValue;
                if (input.is('select') && input.prop('multiple')) {
                    var arr = input.val() || [];
                    if (!Array.isArray(arr)) { arr = [arr]; }
                    newValue = arr.join(',');
                } else {
                    newValue = String(input.val() || '');
                }
                var payload = {};
                payload[fieldKey] = newValue;
                $.ajax({
                    url: window.location.pathname,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        fastcrud_ajax: '1',
                        action: 'update',
                        table: tableName,
                        id: tableId,
                        primary_key_column: pk.column,
                        primary_key_value: pk.value,
                        fields: JSON.stringify(payload),
                        config: JSON.stringify(clientConfig)
                    },
                    success: function(response) {
                        if (response && response.success) {
                            try {
                                var key = rowCacheKey(pk.column, String(pk.value));
                                if (response.row) { rowCache[key] = response.row; } else if (rowCache[key]) { delete rowCache[key]; }
                            } catch (e) {}
                            destroySelect2(wrapper);
                            loadTableData(currentPage);
                        } else {
                            var message = response && response.error ? response.error : 'Failed to update value.';
                            window.alert(message);
                            restore();
                        }
                    },
                    error: function(_, __, error) {
                        window.alert('Failed to update value: ' + error);
                        restore();
                    }
                });
            }

            input.on('keydown', function(e){
                if (e.key === 'Enter') { e.preventDefault(); requestCommit(true); }
                else if (e.key === 'Escape') { e.preventDefault(); restore(); }
            });
            if (changeType === 'color') {
                input.on('change', function(){ requestCommit(false); });
                input.on('blur', function(){ requestCommit(false); });
            } else if (input.is('select')) {
                input.on('change', function(){ requestCommit(false); });
                if (select2Enabled) {
                    input.on('select2:close', function(){ requestCommit(false); });
                    input.on('blur', function(){
                        var element = $(this);
                        if (!element.hasClass('select2-hidden-accessible')) {
                            requestCommit(false);
                        }
                    });
                } else {
                    input.on('blur', function(){ requestCommit(false); });
                }
            } else {
                input.on('blur', function(){ requestCommit(false); });
            }
        }

        table.on('click', 'tbody td[data-fastcrud-column]', function(event) {
            // Ignore clicks on interactive elements inside the cell
            var target = $(event.target);
            if (target.is('a,button,input,select,textarea') || target.closest('.btn, .dropdown, .fastcrud-actions-cell').length) {
                return;
            }
            startInlineEdit(this);
        });

        // Allow double-click to force inline edit even if content is nested (e.g., inside <strong>)
        table.on('dblclick', 'tbody td[data-fastcrud-column]', function(event) {
            startInlineEdit(this);
            event.preventDefault();
        });

        metaContainer.on('click', '.fastcrud-add-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            if (!primaryKeyColumn) {
                showError('Unable to determine primary key for creating records.');
                return false;
            }

            editForm.data('mode', 'create');
            clearRowHighlight();

            var templateRow = getFormTemplate('create');
            if (!templateRow) {
                templateRow = {
                    __fastcrud_primary_key: primaryKeyColumn,
                    __fastcrud_primary_value: null
                };
            } else {
                templateRow.__fastcrud_primary_key = primaryKeyColumn || templateRow.__fastcrud_primary_key || null;
                templateRow.__fastcrud_primary_value = null;
            }

            showEditForm(ensureRowColumns(templateRow));
            return false;
        });

        table.on('click', '.fastcrud-view-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for viewing.'); return false; }
            var tr = $(this).closest('tr');
            highlightRow(tr);
            fetchRowByPk(pk.column, pk.value, 'view')
                .then(function(row){
                    showViewPanel(row || {});
                    highlightRow(tr);
                })
                .catch(function(err){ showError('Failed to load record: ' + (err && err.message ? err.message : err)); });
            return false;
        });

        table.on('click', '.fastcrud-edit-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            editForm.data('mode', 'edit');
            var tr = $(this).closest('tr');
            highlightRow(tr);
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for editing.'); return false; }
            fetchRowByPk(pk.column, pk.value, 'edit')
                .then(function(row){
                    showEditForm(row || {});
                    highlightRow(tr);
                })
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

            if (deleteConfirm) {
                var confirmationMessage = 'Are you sure you want to delete record ' + primaryValue + '?';
                if (!window.confirm(confirmationMessage)) {
                    return;
                }
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

        function collectSelectionForBulk(showFeedback) {
            var keys = Object.keys(selectedRows);
            if (!keys.length) {
                if (showFeedback) {
                    showError('Select at least one row before applying a bulk action.');
                }
                return null;
            }

            var grouped = {};
            keys.forEach(function(key) {
                var entry = selectedRows[key];
                if (!entry || !entry.column) {
                    return;
                }

                var column = entry.column;
                if (!grouped[column]) {
                    grouped[column] = [];
                }

                grouped[column].push(entry.value);
            });

            var columns = Object.keys(grouped);
            if (!columns.length) {
                if (showFeedback) {
                    showError('No valid selections available for this bulk action.');
                }
                return null;
            }

            if (columns.length > 1) {
                if (showFeedback) {
                    showError('Bulk actions require all selections to share the same primary key column.');
                }
                return null;
            }

            var pkColumn = columns[0];
            var values = grouped[pkColumn];
            if (!values.length) {
                if (showFeedback) {
                    showError('No values available for the selected rows.');
                }
                return null;
            }

            return { column: pkColumn, values: values };
        }

        function requestBatchDelete() {
            if (!allowBatchDeleteButton) {
                showError('Bulk delete is not enabled for this table.');
                return;
            }

            var selection = collectSelectionForBulk(true);
            if (!selection) {
                return;
            }

            var pkColumn = selection.column;
            var values = selection.values;

            var confirmationMessage = values.length === 1
                ? 'Are you sure you want to delete the selected record?'
                : 'Are you sure you want to delete the ' + values.length + ' selected records?';

            if (deleteConfirm && !window.confirm(confirmationMessage)) {
                return;
            }

            sendBulkAjax({
                fastcrud_ajax: '1',
                action: 'batch_delete',
                table: tableName,
                id: tableId,
                primary_key_column: pkColumn,
                primary_key_values: values,
                config: JSON.stringify(clientConfig)
            }, 'Failed to delete selected records.');
        }

        function sendBulkAjax(payload, defaultMessage) {
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: payload,
                success: function(response) {
                    if (response && response.success) {
                        clearSelection();
                        loadTableData(currentPage);
                        metaContainer.find('.fastcrud-bulk-action-select').val('');
                        updateBulkActionState();
                    } else {
                        var message = response && response.error ? response.error : defaultMessage;
                        showError(message);
                    }
                },
                error: function(_, __, error) {
                    showError(defaultMessage + ' ' + error);
                }
            });
        }

        function requestBulkAction(actionKey) {
            if (!Array.isArray(bulkActions) || !bulkActions.length) {
                showError('No bulk actions are configured.');
                return;
            }

            var index = parseInt(actionKey, 10);
            if (isNaN(index) || index < 0 || index >= bulkActions.length) {
                showError('Invalid bulk action selected.');
                return;
            }

            var action = bulkActions[index] || {};
            var selection = collectSelectionForBulk(true);
            if (!selection) {
                return;
            }

            if (action.confirm && !window.confirm(String(action.confirm))) {
                return;
            }

            if (!action.fields || typeof action.fields !== 'object') {
                showError('Bulk update action is missing field assignments.');
                return;
            }

            var payload = {
                fastcrud_ajax: '1',
                action: 'bulk_update',
                table: tableName,
                id: tableId,
                primary_key_column: selection.column,
                primary_key_values: selection.values,
                fields: JSON.stringify(action.fields),
                config: JSON.stringify(clientConfig)
            };

            sendBulkAjax(payload, 'Failed to apply bulk update.');
        }

        function startExport(format) {
            var action = format === 'excel' ? 'export_excel' : 'export_csv';
            var params = new URLSearchParams();
            params.set('fastcrud_ajax', '1');
            params.set('action', action);
            params.set('table', tableName);
            params.set('id', tableId);
            params.set('config', JSON.stringify(clientConfig));

            if (primaryKeyColumn) {
                params.set('primary_key_column', primaryKeyColumn);
            }

            if (currentSearchTerm) {
                params.set('search_term', currentSearchTerm);
            }

            if (currentSearchColumn) {
                params.set('search_column', currentSearchColumn);
            }

            var selection = collectSelectionForBulk(false);
            if (selection && selection.values && selection.values.length) {
                selection.values.forEach(function(value) {
                    params.append('primary_key_values[]', value);
                });
            }

            var url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }

        table.on('change', '.fastcrud-select-row', function() {
            if (!batchDeleteEnabled) {
                $(this).prop('checked', false);
                return;
            }

            var checkbox = $(this);
            var pkCol = checkbox.attr('data-fastcrud-pk');
            var pkVal = checkbox.attr('data-fastcrud-pk-value');
            if (!pkCol || typeof pkVal === 'undefined') {
                checkbox.prop('checked', false);
                return;
            }

            var checked = checkbox.is(':checked');
            setSelection(pkCol, pkVal, checked);
            refreshSelectAllState();
            updateBatchDeleteButtonState();
        });

        table.on('click', '.fastcrud-nested-toggle', function(event) {
            event.preventDefault();
            event.stopPropagation();
            toggleNested($(this));
            return false;
        });

        metaContainer.on('click', '.fastcrud-batch-delete-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            requestBatchDelete();
            return false;
        });

        metaContainer.on('change', '.fastcrud-bulk-action-select', function() {
            updateBulkActionState();
        });

        metaContainer.on('click', '.fastcrud-bulk-apply-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var select = $(this).closest('.fastcrud-bulk-actions').find('.fastcrud-bulk-action-select');
            if (!select.length) {
                showError('Select a bulk action to apply.');
                return false;
            }

            var actionIndex = select.val();
            if (!actionIndex) {
                showError('Select a bulk action to apply.');
                return false;
            }

            syncQueryBuilderToConfig();
            requestBulkAction(actionIndex);
            return false;
        });

        metaContainer.on('click', '.fastcrud-export-csv-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            startExport('csv');
            return false;
        });

        metaContainer.on('click', '.fastcrud-export-excel-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            startExport('excel');
            return false;
        });

        table.on('click', '.fastcrud-delete-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for deletion.'); return false; }
            requestDelete({ __fastcrud_primary_key: pk.column, __fastcrud_primary_value: pk.value });
            return false;
        });

        // Removed handler for unused custom buttons.

        table.on('click', '.fastcrud-duplicate-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for duplication.'); return false; }
            requestDuplicate({ __fastcrud_primary_key: pk.column, __fastcrud_primary_value: pk.value });
            return false;
        });

        editOffcanvasElement.on('click', 'button[type="submit"]', function() {
            lastSubmitAction = jQuery(this).data('fastcrudSubmitAction') || 'close';
        });

        editForm.off('submit.fastcrud').on('submit.fastcrud', submitEditForm);

        function bootstrapInitialMode() {
            if (!initialMode || !formOnlyMode) {
                return false;
            }

            var resolvedPrimary = resolvePrimaryKeyColumn();

            if (initialMode === 'create') {
                editForm.data('mode', 'create');
                clearRowHighlight();

                if (!resolvedPrimary) {
                    showFormError('Unable to determine primary key for creating records.');
                    return true;
                }

                var templateRow = getFormTemplate('create');
                if (!templateRow) {
                    templateRow = {
                        __fastcrud_primary_key: resolvedPrimary,
                        __fastcrud_primary_value: null
                    };
                } else {
                    templateRow.__fastcrud_primary_key = resolvedPrimary || templateRow.__fastcrud_primary_key || null;
                    templateRow.__fastcrud_primary_value = null;
                }

                showEditForm(ensureRowColumns(templateRow));
                return true;
            }

            if (!resolvedPrimary) {
                showError('Primary key column missing for ' + initialMode + ' mode.');
                return true;
            }

            if (typeof initialPrimaryKeyValue === 'undefined' || initialPrimaryKeyValue === null ||
                (typeof initialPrimaryKeyValue === 'string' && initialPrimaryKeyValue === '')) {
                showError('Primary key value missing for ' + initialMode + ' mode.');
                return true;
            }

            if (initialMode === 'view') {
                fetchRowByPk(resolvedPrimary, initialPrimaryKeyValue, 'view')
                    .then(function(row) {
                        showViewPanel(row || {});
                    })
                    .catch(function(error) {
                        showError(error && error.message ? error.message : 'Failed to load record.');
                    });
                return true;
            }

            editForm.data('mode', 'edit');
            fetchRowByPk(resolvedPrimary, initialPrimaryKeyValue, 'edit')
                .then(function(row) {
                    showEditForm(row || {});
                })
                .catch(function(error) {
                    showError(error && error.message ? error.message : 'Failed to load record.');
                });

            return true;
        }

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

        if (formOnlyMode) {
            var handledInitialMode = bootstrapInitialMode();
            if (!handledInitialMode) {
                loadTableData(1);
            }
        } else {
            loadTableData(1);
        }
        });
    }
    (function __fastcrud_wait() {
        if (window.jQuery) {
            try { FastCrudInit(window.jQuery); } catch (e) { try { if (window.console && console.error) console.error('FastCrud init error', e); } catch (e2) {} }
        } else {
            setTimeout(__fastcrud_wait, 50);
        }
    })();
})();
</script>
SCRIPT;
    }
}
        if (isset($payload['inline_edit'])) {
            $fields = $this->normalizeList($payload['inline_edit']);
            $map = [];
            foreach ($fields as $field) {
                $normalized = $this->normalizeColumnReference($field);
                if ($normalized !== '') {
                    $map[$normalized] = true;
                }
            }
            $this->config['inline_edit'] = $map;
        }
