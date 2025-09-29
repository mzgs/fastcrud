# FastCRUD

A fast and simple CRUD operations library for PHP with built-in pagination and AJAX support.

## Features

- Zero-config CRUD with automatic pagination, search, and column sorting.
- AJAX-powered forms, inline editing, bulk updates, and real-time validation feedback.
- Nested tables, relations, and subselect support for modelling complex data.
- Lifecycle callbacks, custom columns, and field modifiers for fine-grained control.
- Built-in CSV/Excel export, soft-delete helpers, and configurable action buttons.
- Global styling hooks and upload helpers so you can align the UI with your project.

## Installation

```bash
composer require mzgs/fastcrud
```

## Usage Example

### Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FastCrud\Crud;

// Initialize database connection
Crud::init([
    'database' => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password',
]);

// Create and render a CRUD table
echo new Crud('users')->render();
```

### Full HTML Example

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FastCrud\Crud;

Crud::init([
    'database' => 'your_database',
    'username' => 'root',
    'password' => 'password',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FastCRUD Demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div class="container py-5">
        <div class="row">
            <div class="col">
                <h1>FastCRUD Demo</h1>
                <div class="card">
                    <div class="card-header">
                        Users Table
                    </div>
                    <div class="card-body">
                        <?= new Crud('users')->render(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

## Configuration

### Database connection

```php
use FastCrud\Crud;
use FastCrud\CrudConfig;

CrudConfig::setDbConfig([
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password',
]);

Crud::init(); // automatically handles FastCRUD AJAX requests
```

- Call `Crud::init()` during your bootstrap so AJAX endpoints (`?fastcrud_ajax=1`) are dispatched automatically.
- If you need full control of routing, call `FastCrud\CrudAjax::handle()` yourself when a request targets your FastCRUD endpoint.

### Rendering multiple tables

Each `Crud` instance is independent. Reuse the same bootstrap call and render additional tables as needed:

```php
$users = new Crud('users');
$orders = (new Crud('orders'))
    ->setPerPage(10)
    ->order_by('created_at', 'desc');

echo $users->render();
echo $orders->render();
```

## Customising the grid

- **Columns**: `columns(['id', 'name'])`, `set_column_labels(['created_at' => 'Created'])`, `column_pattern('email', '<a href="mailto:{{raw}}">{{display}}</a>')`.
- **Forms**: `fields([...])`, `change_type('avatar', 'upload_image')`, `validation_required(['name'])`, `default_tab('Details')`.
- **Actions**: Enable or restrict operations with `enable_add(false)`, per-row conditions, soft-delete helpers, and bulk actions via `add_bulk_action()`.
- **Highlighting**: Use `highlight('status', ['equals' => 'pending'], 'text-warning')` or `highlight_row([...])` for conditional styling.
- **Inline editing**: Call `inline_edit(['status', 'priority'])` to allow single-click updates for selected fields.

## Relations and nested tables

- Join related data automatically with `relation()` or `join()` helpers.
- Render nested tables inside a row by registering `nested_table()` definitions; FastCRUD loads child tables over AJAX when toggled.

## Hooks and callbacks

- Run logic before/after CRUD events with lifecycle callbacks (for example `before_insert()`, `after_update()`).
- Add derived data with `custom_column()` or mutate form values using `field_callback()`.
- Use `pass_default()`/`pass_var()` to inject server-side data into forms at runtime.

## Styling and assets

- Include Bootstrap 5 and jQuery on pages where you render a grid (CDN links shown in the full example above).
- Override global button classes or colours by updating the public statics in `FastCrud\CrudStyle`.
- Configure upload locations and grid behaviour via the statics in `FastCrud\CrudConfig` (for example `CrudConfig::$upload_path`).



## Requirements

- PHP 7.4 or higher
- PDO extension
- A supported database (MySQL, PostgreSQL, SQLite, etc.)
- Bootstrap 5 for styling
- jQuery for AJAX functionality

## License

MIT

## API Reference

### FastCrud\Crud

- `Crud::init(?array $dbConfig = null): void` – Set default DB config and auto-handle AJAX. Example:
  ```php
  Crud::init(['database' => 'app', 'username' => 'root', 'password' => 'secret']);
  ```
- `Crud::fromAjax(string $table, ?string $id, array|string|null $configPayload, ?PDO $connection = null): self` – Recreate an instance from client payloads. Example:
  ```php
  $crud = Crud::fromAjax('users', $_GET['id'] ?? null, $_POST['config'] ?? null);
  ```
- `__construct(string $table, ?PDO $connection = null)` – Build a CRUD wrapper for a table. Example: `$crud = new Crud('users', $pdo);`
- `getTable(): string` – Fetch the configured table name. Example: `$table = $crud->getTable();`
- `primary_key(string $column): self` – Override the primary key column. Example: `$crud->primary_key('user_id');`
- `setPerPage(int $perPage): self` – Set default page size. Example: `$crud->setPerPage(25);`
- `limit(int $limit): self` – Alias of `setPerPage`. Example: `$crud->limit(10);`
- `limit_list(string|array $limits): self` – Control per-page options (supports `all`). Example: `$crud->limit_list([5, 10, 'all']);`
- `setPanelWidth(string $width): self` – Control edit panel width. Example: `$crud->setPanelWidth('640px');`
- `inline_edit(string|array $fields): self` – Toggle inline editing for fields. Example: `$crud->inline_edit(['status', 'priority']);`
- `columns(string|array $columns, bool $reverse = false): self` – Restrict or reorder visible columns. Example: `$crud->columns(['id', 'email']);`
- `set_column_labels(array|string $labels, ?string $label = null): self` – Override column headers. Example: `$crud->set_column_labels(['created_at' => 'Created']);`
- `set_field_labels(array|string $labels, ?string $label = null): self` – Rename form fields. Example: `$crud->set_field_labels('phone', 'Contact Number');`
- `column_pattern(string|array $columns, string $pattern): self` – Render column using template tokens. Example:
  ```php
  $crud->column_pattern('email', '<a href="mailto:{{raw}}">{{display}}</a>');
  ```
- `column_callback(string|array $columns, callable|string|array $callback): self` – Apply formatter callback. Example: `$crud->column_callback('balance', [Formatter::class, 'money']);`
- `custom_column(string $column, callable|string|array $callback): self` – Add virtual column. Example: `$crud->custom_column('full_name', [UserPresenter::class, 'fullName']);`
- `field_callback(string|array $fields, callable|string|array $callback): self` – Mutate input before persistence. Example: `$crud->field_callback('slug', [Slugger::class, 'make']);`
- `custom_field(string $field, callable|string|array $callback): self` – Inject non-table field. Example: `$crud->custom_field('invite', [FormExtras::class, 'toggle']);`
- Lifecycle hooks `before_*` / `after_*` – Attach callbacks around CRUD events. Example:
  ```php
  $crud->before_update([Audit::class, 'stamp']);
  $crud->after_fetch(function(array $payload) {
      // mutate $payload['rows']
      return $payload;
  });
  ```
- `column_class(string|array $columns, string|array $classes): self` – Add CSS classes. Example: `$crud->column_class('status', 'text-uppercase');`
- `column_width(string|array $columns, string $width): self` – Force column width. Example: `$crud->column_width('name', '220px');`
- `column_cut(string|array $columns, int $length, string $suffix = '…'): self` – Truncate display. Example: `$crud->column_cut('description', 80);`
- `highlight(string|array $columns, array|string $condition, string $class = 'text-warning'): self` – Highlight values. Example: `$crud->highlight('status', ['equals' => 'pending'], 'text-danger');`
- `highlight_row(array|string $condition, string $class = 'table-warning'): self` – Highlight whole rows. Example: `$crud->highlight_row(['field' => 'balance', 'operand' => 'lt', 'value' => 0], 'table-danger');`
- `table_name(string $name): self` – Override table caption. Example: `$crud->table_name('Customers');`
- `table_tooltip(string $tooltip): self` – Add hover tooltip. Example: `$crud->table_tooltip('Live data');`
- `table_icon(string $iconClass): self` – Prefix heading with icon. Example: `$crud->table_icon('bi-people');`
- `enable_add(bool $enabled = true): self` – Toggle add button. Example: `$crud->enable_add(false);`
- `enable_view/enable_edit/enable_delete/enable_duplicate(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self` – Gate actions optionally per row. Example: `$crud->enable_delete(true, 'status', 'not_equals', 'archived');`
- `enable_batch_delete(bool $enabled = true): self` – Toggle batch delete. Example: `$crud->enable_batch_delete();`
- `add_bulk_action(string $name, string $label, array $options = []): self` – Register a single bulk action. Example:
  ```php
  $crud->add_bulk_action('flag', 'Flag Selected', ['type' => 'update', 'fields' => ['flagged' => 1]]);
  ```
- `set_bulk_actions(array $actions): self` – Replace bulk actions wholesale. Example: `$crud->set_bulk_actions([['name' => 'close', 'label' => 'Close', 'type' => 'update', 'fields' => ['open' => 0]]]);`
- `enable_soft_delete(string $column, array $options = []): self` – Configure soft delete. Example: `$crud->enable_soft_delete('deleted_at', ['mode' => 'timestamp']);`
- `set_soft_delete_assignments(array $assignments): self` – Custom assignments for soft delete. Example:
  ```php
  $crud->set_soft_delete_assignments([
      ['column' => 'deleted_at', 'mode' => 'timestamp'],
      'deleted_by' => ['mode' => 'literal', 'value' => Auth::id()],
  ]);
  ```
- `disable_soft_delete(): self` – Remove soft delete settings. Example: `$crud->disable_soft_delete();`
- `enable_delete_confirm(bool $enabled = true): self` – Toggle delete confirmation. Example: `$crud->enable_delete_confirm(false);`
- `enable_export_csv(bool $enabled = true): self` – Toggle CSV export. Example: `$crud->enable_export_csv();`
- `enable_export_excel(bool $enabled = true): self` – Toggle Excel export. Example: `$crud->enable_export_excel();`
- `link_button(string $url, string $iconClass, ?string $label = null, ?string $buttonClass = null, array $options = []): self` – Add toolbar link. Example: `$crud->link_button('/reports', 'bi-file-earmark', 'Reports');`
- `column_summary(string|array $columns, string $type = 'sum', ?string $label = null, ?int $precision = null): self` – Add footer summaries. Example: `$crud->column_summary('total', 'sum', 'Grand Total', 2);`
- `fields(string|array $fields, bool $reverse = false, string|false $tab = false, string|array|false $mode = false): self` – Control form layout and tabs. Example: `$crud->fields(['name', 'email'], false, 'Details');`
- `default_tab(string $tabName, string|array|false $mode = false): self` – Define default tab per mode. Example: `$crud->default_tab('Details', ['create', 'edit']);`
- `change_type(string|array $fields, string $type, mixed $default = '', array $params = []): self` – Override form field type. Example: `$crud->change_type('avatar', 'upload_image', '', ['path' => 'avatars']);`
- `getChangeTypeDefinition(string $field): ?array` – Inspect stored type override. Example: `$definition = $crud->getChangeTypeDefinition('avatar');`
- `pass_var(string|array $fields, mixed $value, string|array $mode = 'all'): self` – Inject runtime values. Example: `$crud->pass_var('updated_by', Auth::id());`
- `pass_default(string|array $fields, mixed $value, string|array $mode = 'all'): self` – Provide fallbacks when empty. Example: `$crud->pass_default('status', 'pending', 'create');`
- `readonly(string|array $fields, string|array $mode = 'all'): self` – Mark fields read-only. Example: `$crud->readonly(['email'], ['edit', 'view']);`
- `disabled(string|array $fields, string|array $mode = 'all'): self` – Disable fields. Example: `$crud->disabled('type', 'create');`
- `validation_required(string|array $fields, int $minLength = 1, string|array $mode = 'all'): self` – Require minimum length. Example: `$crud->validation_required('name', 1);`
- `validation_pattern(string|array $fields, string $pattern, string|array $mode = 'all'): self` – Apply regex validation. Example: `$crud->validation_pattern('phone', '/^\\+?[0-9]{7,15}$/');`
- `unique(string|array $fields, string|array $mode = 'all'): self` – Enforce uniqueness. Example: `$crud->unique('email', ['create', 'edit']);`
- `order_by(string|array $fields, string $direction = 'asc'): self` – Set default ordering. Example: `$crud->order_by(['status' => 'asc', 'created_at' => 'desc']);`
- `disable_sort(string|array $columns): self` – Disable column sorting. Example: `$crud->disable_sort(['notes']);`
- `search_columns(string|array $columns, string|false $default = false): self` – Limit searchable columns. Example: `$crud->search_columns(['name', 'email'], 'name');`
- `no_quotes(string|array $fields): self` – Skip quoting for raw expressions. Example: `$crud->no_quotes('JSON_EXTRACT(meta, "$.flag")');`
- `where(string|array $fields, mixed $whereValue = false, string $glue = 'AND'): self` – Add filter conditions. Example: `$crud->where('status = ?', 'active');`
- `or_where(string|array $fields, mixed $whereValue = false): self` – OR-scoped filters. Example: `$crud->or_where(['role' => 'admin']);`
- `join(string|array $fields, string $joinTable, string $joinField, string|array|false $alias = false, bool $notInsert = false): self` – Add SQL join definitions. Example: `$crud->join('role_id', 'roles', 'id', 'r');`
- `relation(string|array $fields, string $relatedTable, string $relatedField, string|array $relName, array $relWhere = [], string|false $orderBy = false, bool $multi = false): self` – Populate select options for foreign keys. Example: `$crud->relation('country_id', 'countries', 'id', 'name', ['active' => 1]);`
- `query(string $query): self` – Provide custom SELECT SQL. Example: `$crud->query('SELECT * FROM view_users');`
- `subselect(string $columnName, string $sql): self` – Add subquery column. Example: `$crud->subselect('orders_count', 'SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id');`
- `nested_table(string $instanceName, string $parentColumn, string $innerTable, string $innerTableField, ?callable $configurator = null): self` – Attach nested CRUDs. Example:
  ```php
  $crud->nested_table('orders', 'id', 'orders', 'user_id', function (Crud $child) {
      $child->columns(['id', 'total'])->limit(5);
  });
  ```
- `render(?string $mode = null, mixed $primaryKeyValue = null): string` – Render the widget HTML. Example: `echo $crud->render();`
- `getId(): string` – Retrieve the generated container ID. Example: `$id = $crud->getId();`
- `getTableData(int $page = 1, ?int $perPage = null, ?string $searchTerm = null, ?string $searchColumn = null): array` – Fetch data for AJAX responses. Example: `$data = $crud->getTableData(1, 10, 'sam', 'name');`
- `createRecord(array $fields): ?array` – Insert a row and return it. Example: `$user = $crud->createRecord(['name' => 'Sam']);`
- `updateRecord(string $primaryKeyColumn, mixed $primaryKeyValue, array $fields, string $mode = 'edit'): ?array` – Update a record. Example: `$crud->updateRecord('id', 5, ['status' => 'active']);`
- `deleteRecord(string $primaryKeyColumn, mixed $primaryKeyValue): bool` – Delete/soft delete a row. Example: `$crud->deleteRecord('id', 9);`
- `deleteRecords(string $primaryKeyColumn, array $primaryKeyValues): array` – Bulk delete. Example: `$crud->deleteRecords('id', [1, 2, 3]);`
- `updateRecords(string $primaryKeyColumn, array $primaryKeyValues, array $fields, string $mode = 'edit'): array` – Bulk update. Example: `$crud->updateRecords('id', [2, 3], ['status' => 'archived']);`
- `duplicateRecord(string $primaryKeyColumn, mixed $primaryKeyValue): ?array` – Clone a record. Example: `$copy = $crud->duplicateRecord('id', 7);`
- `getRecord(string $primaryKeyColumn, mixed $primaryKeyValue): ?array` – Fetch single record with formatting. Example: `$record = $crud->getRecord('id', 7);`

### FastCrud\CrudAjax

- `CrudAjax::handle(): void` – Process the current FastCRUD AJAX request. Example:
  ```php
  if (CrudAjax::isAjaxRequest()) {
      CrudAjax::handle();
  }
  ```
- `CrudAjax::isAjaxRequest(): bool` – Detect FastCRUD AJAX calls. Example: `if (CrudAjax::isAjaxRequest()) { /* ... */ }`
- `CrudAjax::autoHandle(): void` – Auto-handle when within `Crud::init()`. Example: `CrudAjax::autoHandle();`

### FastCrud\CrudConfig

- `CrudConfig::setDbConfig(array $configuration): void` – Store PDO connection settings. Example:
  ```php
  CrudConfig::setDbConfig([
      'driver' => 'pgsql',
      'host' => 'db',
      'database' => 'app',
      'username' => 'user',
      'password' => 'secret',
  ]);
  ```
- `CrudConfig::getDbConfig(): array` – Retrieve current settings. Example: `$config = CrudConfig::getDbConfig();`
- `CrudConfig::getUploadPath(): string` – Resolve upload base path. Example: `$path = CrudConfig::getUploadPath();`

### FastCrud\DB

- `DB::connection(): PDO` – Get or build shared PDO instance. Example: `$pdo = DB::connection();`
- `DB::setConnection(PDO $connection): void` – Inject your own PDO (testing). Example: `DB::setConnection($testPdo);`
- `DB::disconnect(): void` – Clear cached PDO. Example: `DB::disconnect();`

### FastCrud\ValidationException

- `__construct(string $message, array $errors = [], int $code = 0, ?Throwable $previous = null)` – Throw validation failure with field errors. Example:
  ```php
  throw new ValidationException('Invalid data', ['email' => 'Taken']);
  ```
- `getErrors(): array` – Retrieve validation error map. Example: `$errors = $exception->getErrors();`
