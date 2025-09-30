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

#### Setup & Bootstrap

- **`Crud::init(?array $dbConfig = null): void`** – Configure the connection defaults and auto-handle AJAX requests.
  ```php
  Crud::init([
      'database' => 'app',
      'username' => 'root',
      'password' => 'secret',
  ]);
  ```
- **`Crud::fromAjax(string $table, ?string $id, array|string|null $configPayload, ?PDO $connection = null): self`** – Rehydrate an instance from data posted by the FastCRUD frontend.
  ```php
  $crud = Crud::fromAjax('users', $_GET['fastcrud_id'] ?? null, $_POST['config'] ?? null);
  ```
- **`__construct(string $table, ?PDO $connection = null)`** – Build a CRUD controller for the given table.
  ```php
  $crud = new Crud('users', $customPdo);
  ```
- **`getTable(): string`** – Return the raw table identifier.
  ```php
  $tableName = $crud->getTable();
  ```
- **`primary_key(string $column): self`** – Override the primary key column that FastCRUD uses.
  ```php
  $crud->primary_key('user_id');
  ```
- **`setPerPage(int $perPage): self`** – Set the default rows per page.
  ```php
  $crud->setPerPage(25);
  ```
- **`limit(int $limit): self`** – Alias of `setPerPage()` for fluent configuration.
  ```php
  $crud->limit(10);
  ```
- **`limit_list(string|array $limits): self`** – Define the pagination dropdown values (supports `'all'`).
  ```php
  $crud->limit_list([5, 10, 25, 'all']);
  ```
- **`setPanelWidth(string $width): self`** – Adjust the width of the edit offcanvas panel.
  ```php
  $crud->setPanelWidth('640px');
  ```

#### Table Display

- **`inline_edit(string|array $fields): self`** – Enable inline edits for selected columns.
  ```php
  $crud->inline_edit(['status', 'priority']);
  ```
- **`columns(string|array $columns, bool $reverse = false): self`** – Control which columns appear and in what order.
  ```php
  $crud->columns(['id', 'name', 'email']);
  ```
- **`set_column_labels(array|string $labels, ?string $label = null): self`** – Change table heading labels.
  ```php
  $crud->set_column_labels(['created_at' => 'Created']);
  ```
- **`set_field_labels(array|string $labels, ?string $label = null): self`** – Rename form fields without renaming columns.
  ```php
  $crud->set_field_labels('phone', 'Contact Number');
  ```

#### Column Presentation

- **`column_pattern(string|array $columns, string $pattern): self`** – Render column values with template tokens like `{{display}}`.
  ```php
  $crud->column_pattern('email', '<a href="mailto:{{raw}}">{{display}}</a>');
  ```
- **`column_callback(string|array $columns, callable|string|array $callback): self`** – Pass values through a formatter callback.
  ```php
  $crud->column_callback('balance', [Formatter::class, 'money']);
  ```
- **`custom_column(string $column, callable|string|array $callback): self`** – Add computed virtual columns to the grid.
  ```php
  $crud->custom_column('full_name', [UserPresenter::class, 'fullName']);
  ```
- **`column_class(string|array $columns, string|array $classes): self`** – Append custom CSS classes to specific cells.
  ```php
  $crud->column_class('status', 'text-uppercase text-success');
  ```
- **`column_width(string|array $columns, string $width): self`** – Set fixed widths for columns.
  ```php
  $crud->column_width('name', '220px');
  ```
- **`column_cut(string|array $columns, int $length, string $suffix = '…'): self`** – Truncate long text for clean tables.
  ```php
  $crud->column_cut('description', 80);
  ```
- **`highlight(string|array $columns, array|string $condition, string $class = 'text-warning'): self`** – Highlight cells that match conditions.
  ```php
  $crud->highlight('status', ['equals' => 'pending'], 'text-danger');
  ```
- **`highlight_row(array|string $condition, string $class = 'table-warning'): self`** – Highlight entire rows based on conditions.
  ```php
  $crud->highlight_row(['field' => 'balance', 'operand' => 'lt', 'value' => 0], 'table-danger');
  ```
- **`column_summary(string|array $columns, string $type = 'sum', ?string $label = null, ?int $precision = null): self`** – Display aggregated totals in the footer.
  ```php
  $crud->column_summary('total', 'sum', 'Grand Total', 2);
  ```

#### Field & Form Customisation

- **`custom_field(string $field, callable|string|array $callback): self`** – Inject additional, non-database fields into the form.
  ```php
  $crud->custom_field('invite_toggle', [FormExtras::class, 'toggle']);
  ```
- **`field_callback(string|array $fields, callable|string|array $callback): self`** – Mutate input data before it is saved.
  ```php
  $crud->field_callback('slug', [Slugger::class, 'make']);
  ```
- **`fields(string|array $fields, bool $reverse = false, string|false $tab = false, string|array|false $mode = false): self`** – Arrange form fields into sections and tabs.
  ```php
  $crud->fields(['name', 'email', 'phone'], false, 'Details');
  ```
- **`default_tab(string $tabName, string|array|false $mode = false): self`** – Choose the default tab for each form mode.
  ```php
  $crud->default_tab('Details', ['create', 'edit']);
  ```
- **`change_type(string|array $fields, string $type, mixed $default = '', array $params = []): self`** – Swap the input widget or field type.
  ```php
  $crud->change_type('avatar', 'upload_image', '', ['path' => 'avatars']);
  ```
- **`getChangeTypeDefinition(string $field): ?array`** – Inspect previously configured type overrides.
  ```php
  $definition = $crud->getChangeTypeDefinition('avatar');
  ```
- **`pass_var(string|array $fields, mixed $value, string|array $mode = 'all'): self`** – Inject runtime values each time the form renders.
  ```php
  $crud->pass_var('updated_by', Auth::id());
  ```
- **`pass_default(string|array $fields, mixed $value, string|array $mode = 'all'): self`** – Supply fallback values when inputs are empty.
  ```php
  $crud->pass_default('status', 'pending', 'create');
  ```
- **`readonly(string|array $fields, string|array $mode = 'all'): self`** – Mark fields as read-only per mode.
  ```php
  $crud->readonly(['email'], ['edit', 'view']);
  ```
- **`disabled(string|array $fields, string|array $mode = 'all'): self`** – Disable inputs entirely for certain modes.
  ```php
  $crud->disabled('type', 'create');
  ```

#### Validation Helpers

- **`validation_required(string|array $fields, int $minLength = 1, string|array $mode = 'all'): self`** – Enforce required fields and minimum length.
  ```php
  $crud->validation_required('name', 1);
  ```
- **`validation_pattern(string|array $fields, string $pattern, string|array $mode = 'all'): self`** – Apply regex-based validation rules.
  ```php
  $crud->validation_pattern('phone', '/^\+?[0-9]{7,15}$/');
  ```
- **`unique(string|array $fields, string|array $mode = 'all'): self`** – Ensure values remain unique when saving.
  ```php
  $crud->unique('email', ['create', 'edit']);
  ```

#### Lifecycle Hooks

- **`before_insert(callable|string|array $callback): self`** – Run logic right before an insert occurs.
  ```php
  $crud->before_insert([Audit::class, 'stampBeforeInsert']);
  ```
- **`after_insert(callable|string|array $callback): self`** – React immediately after a record is inserted.
  ```php
  $crud->after_insert([Notifier::class, 'sendWelcome']);
  ```
- **`before_create(callable|string|array $callback): self`** – Intercept create form submissions before validation.
  ```php
  $crud->before_create(fn(array $payload) => $payload);
  ```
- **`after_create(callable|string|array $callback): self`** – React once the create form has finished.
  ```php
  $crud->after_create(fn(array $row) => $row);
  ```
- **`before_update(callable|string|array $callback): self`** – Run logic prior to updating a record.
  ```php
  $crud->before_update([Audit::class, 'stampBeforeUpdate']);
  ```
- **`after_update(callable|string|array $callback): self`** – React to successful updates.
  ```php
  $crud->after_update([Notifier::class, 'sendUpdate']);
  ```
- **`before_delete(callable|string|array $callback): self`** – Perform checks before deletions execute.
  ```php
  $crud->before_delete([Audit::class, 'logDeleteAttempt']);
  ```
- **`after_delete(callable|string|array $callback): self`** – Handle clean-up after deletions.
  ```php
  $crud->after_delete([Notifier::class, 'sendRemovalNotice']);
  ```
- **`before_fetch(callable|string|array $callback): self`** – Adjust pagination payloads before data loads.
  ```php
  $crud->before_fetch(fn(array $payload) => $payload);
  ```
- **`after_fetch(callable|string|array $callback): self`** – Transform row collections after loading.
  ```php
  $crud->after_fetch(fn(array $result) => $result);
  ```
- **`before_read(callable|string|array $callback): self`** – Inspect requests before a single record load.
  ```php
  $crud->before_read([Audit::class, 'logBeforeRead']);
  ```
- **`after_read(callable|string|array $callback): self`** – React after a single record is retrieved.
  ```php
  $crud->after_read([Audit::class, 'logAfterRead']);
  ```

#### Actions & Toolbar

- **`table_name(string $name): self`** – Set the headline shown above the table.
  ```php
  $crud->table_name('Customer Accounts');
  ```
- **`table_tooltip(string $tooltip): self`** – Provide a tooltip for the table header.
  ```php
  $crud->table_tooltip('Live customer data');
  ```
- **`table_icon(string $iconClass): self`** – Add an icon before the table title.
  ```php
  $crud->table_icon('bi-people');
  ```
- **`enable_add(bool $enabled = true): self`** – Toggle the add-record button.
  ```php
  $crud->enable_add(false);
  ```
- **`enable_view(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self`** – Control per-row view permissions.
  ```php
  $crud->enable_view(true, 'status', 'equals', 'active');
  ```
- **`enable_edit(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self`** – Decide which rows are editable.
  ```php
  $crud->enable_edit(true, 'locked', 'equals', 0);
  ```
- **`enable_delete(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self`** – Restrict delete access.
  ```php
  $crud->enable_delete(true, 'status', 'not_equals', 'archived');
  ```
- **`enable_duplicate(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self`** – Enable duplication for qualifying rows.
  ```php
  $crud->enable_duplicate(true, 'type', 'equals', 'template');
  ```
- **`enable_batch_delete(bool $enabled = true): self`** – Show or hide batch deletion controls.
  ```php
  $crud->enable_batch_delete();
  ```
- **`add_bulk_action(string $name, string $label, array $options = []): self`** – Register a custom bulk action.
  ```php
  $crud->add_bulk_action('flag', 'Flag Selected', [
      'type'   => 'update',
      'fields' => ['flagged' => 1],
  ]);
  ```
- **`set_bulk_actions(array $actions): self`** – Replace the entire bulk action list.
  ```php
  $crud->set_bulk_actions([
      ['name' => 'close', 'label' => 'Close', 'type' => 'update', 'fields' => ['open' => 0]],
  ]);
  ```
- **`enable_soft_delete(string $column, array $options = []): self`** – Configure soft-delete behaviour.
  ```php
  $crud->enable_soft_delete('deleted_at', ['mode' => 'timestamp']);
  ```
- **`set_soft_delete_assignments(array $assignments): self`** – Provide advanced soft-delete assignments.
  ```php
  $crud->set_soft_delete_assignments([
      ['column' => 'deleted_at', 'mode' => 'timestamp'],
      'deleted_by' => ['mode' => 'literal', 'value' => Auth::id()],
  ]);
  ```
- **`disable_soft_delete(): self`** – Remove soft-delete configuration.
  ```php
  $crud->disable_soft_delete();
  ```
- **`enable_delete_confirm(bool $enabled = true): self`** – Toggle confirmation prompts before deletion.
  ```php
  $crud->enable_delete_confirm(false);
  ```
- **`enable_export_csv(bool $enabled = true): self`** – Show or hide the CSV export button.
  ```php
  $crud->enable_export_csv();
  ```
- **`enable_export_excel(bool $enabled = true): self`** – Show or hide the Excel export button.
  ```php
  $crud->enable_export_excel();
  ```
- **`link_button(string $url, string $iconClass, ?string $label = null, ?string $buttonClass = null, array $options = []): self`** – Add a custom toolbar button.
  ```php
  $crud->link_button('/reports', 'bi-file-earmark', 'Reports', 'btn btn-sm btn-outline-info');
  ```

#### Sorting, Filtering & Relationships

- **`order_by(string|array $fields, string $direction = 'asc'): self`** – Define default ordering for query results.
  ```php
  $crud->order_by(['status' => 'asc', 'created_at' => 'desc']);
  ```
- **`disable_sort(string|array $columns): self`** – Prevent UI sorting on columns.
  ```php
  $crud->disable_sort(['notes']);
  ```
- **`search_columns(string|array $columns, string|false $default = false): self`** – Control which columns participate in quick search.
  ```php
  $crud->search_columns(['name', 'email'], 'name');
  ```
- **`no_quotes(string|array $fields): self`** – Treat specified expressions as raw SQL.
  ```php
  $crud->no_quotes('JSON_EXTRACT(meta, "$.flag")');
  ```
- **`where(string|array $fields, mixed $whereValue = false, string $glue = 'AND'): self`** – Add `AND`-joined conditions to the query.
  ```php
  $crud->where('status = ?', 'active');
  ```
- **`or_where(string|array $fields, mixed $whereValue = false): self`** – Add `OR`-joined conditions.
  ```php
  $crud->or_where(['role' => 'admin']);
  ```
- **`join(string|array $fields, string $joinTable, string $joinField, string|array|false $alias = false, bool $notInsert = false): self`** – Join related tables for display.
  ```php
  $crud->join('role_id', 'roles', 'id', 'r');
  ```
- **`relation(string|array $fields, string $relatedTable, string $relatedField, string|array $relName, array $relWhere = [], string|false $orderBy = false, bool $multi = false): self`** – Populate select fields from related tables.
  ```php
  $crud->relation('country_id', 'countries', 'id', 'name', ['active' => 1]);
  ```

#### Query Extensions

- **`query(string $query): self`** – Replace the default select statement.
  ```php
  $crud->query('SELECT * FROM view_users');
  ```
- **`subselect(string $columnName, string $sql): self`** – Add a derived column via subquery.
  ```php
  $crud->subselect('orders_count', 'SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id');
  ```

#### Nested Data

- **`nested_table(string $instanceName, string $parentColumn, string $innerTable, string $innerTableField, ?callable $configurator = null): self`** – Attach expandable child tables to each row.
  ```php
  $crud->nested_table('orders', 'id', 'orders', 'user_id', function (Crud $child) {
      $child->columns(['id', 'total'])->limit(5);
  });
  ```

#### Rendering & Data Access

- **`render(?string $mode = null, mixed $primaryKeyValue = null): string`** – Output the full FastCRUD widget.
  ```php
  echo $crud->render();
  ```
- **`getId(): string`** – Retrieve the generated component ID for DOM targeting.
  ```php
  $componentId = $crud->getId();
  ```
- **`getTableData(int $page = 1, ?int $perPage = null, ?string $searchTerm = null, ?string $searchColumn = null): array`** – Fetch paginated data for AJAX responses.
  ```php
  $payload = $crud->getTableData(1, 10, 'sam', 'name');
  ```

#### Record Operations

- **`createRecord(array $fields): ?array`** – Insert a new record with behaviour support.
  ```php
  $user = $crud->createRecord(['name' => 'Sam', 'email' => 'sam@example.com']);
  ```
- **`updateRecord(string $primaryKeyColumn, mixed $primaryKeyValue, array $fields, string $mode = 'edit'): ?array`** – Update a record and return the latest data.
  ```php
  $updated = $crud->updateRecord('id', 5, ['status' => 'active']);
  ```
- **`deleteRecord(string $primaryKeyColumn, mixed $primaryKeyValue): bool`** – Delete or soft-delete a single record.
  ```php
  $crud->deleteRecord('id', 9);
  ```
- **`deleteRecords(string $primaryKeyColumn, array $primaryKeyValues): array`** – Delete multiple records at once.
  ```php
  $result = $crud->deleteRecords('id', [1, 2, 3]);
  ```
- **`updateRecords(string $primaryKeyColumn, array $primaryKeyValues, array $fields, string $mode = 'edit'): array`** – Apply bulk updates to several records.
  ```php
  $crud->updateRecords('id', [2, 3], ['status' => 'archived']);
  ```
- **`duplicateRecord(string $primaryKeyColumn, mixed $primaryKeyValue): ?array`** – Clone an existing record including allowed columns.
  ```php
  $copy = $crud->duplicateRecord('id', 7);
  ```
- **`getRecord(string $primaryKeyColumn, mixed $primaryKeyValue): ?array`** – Retrieve a single record with presentation rules applied.
  ```php
  $record = $crud->getRecord('id', 7);
  ```

### FastCrud\CrudAjax

- **`CrudAjax::handle(): void`** – Process the current FastCRUD AJAX request.
  ```php
  if (CrudAjax::isAjaxRequest()) {
      CrudAjax::handle();
  }
  ```
- **`CrudAjax::isAjaxRequest(): bool`** – Detect whether a request targets FastCRUD.
  ```php
  if (CrudAjax::isAjaxRequest()) {
      // delegate to FastCRUD handlers
  }
  ```
- **`CrudAjax::autoHandle(): void`** – Automatically handle the request when wired into `Crud::init()`.
  ```php
  CrudAjax::autoHandle();
  ```

### FastCrud\CrudConfig

- **`CrudConfig::setDbConfig(array $configuration): void`** – Store PDO connection settings.
  ```php
  CrudConfig::setDbConfig([
      'driver' => 'pgsql',
      'host' => 'db',
      'database' => 'app',
      'username' => 'user',
      'password' => 'secret',
  ]);
  ```
- **`CrudConfig::getDbConfig(): array`** – Retrieve the stored configuration array.
  ```php
  $config = CrudConfig::getDbConfig();
  ```
- **`CrudConfig::getUploadPath(): string`** – Resolve the base directory for uploads.
  ```php
  $path = CrudConfig::getUploadPath();
  ```

### FastCrud\DB

- **`DB::connection(): PDO`** – Access the shared PDO instance used by FastCRUD.
  ```php
  $pdo = DB::connection();
  ```
- **`DB::setConnection(PDO $connection): void`** – Inject your own PDO instance (for example, in tests).
  ```php
  DB::setConnection($testPdo);
  ```
- **`DB::disconnect(): void`** – Clear the cached PDO connection.
  ```php
  DB::disconnect();
  ```

### FastCrud\ValidationException

- **`__construct(string $message, array $errors = [], int $code = 0, ?Throwable $previous = null)`** – Create a validation exception with field errors.
  ```php
  throw new ValidationException('Invalid data', ['email' => 'Taken']);
  ```
- **`getErrors(): array`** – Retrieve the error messages that were supplied.
  ```php
  $errors = $exception->getErrors();
  ```
