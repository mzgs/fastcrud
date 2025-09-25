# FastCRUD

A fast and simple CRUD operations library for PHP with built-in pagination and AJAX support.

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

## Feature Highlights

- Simple CRUD operations with Bootstrap 5 UI
- Built-in pagination and AJAX refreshes
- Query helpers: flexible limits, ordering, and `where` filters
- Relation helpers for lookup columns and joins
- Built-in search toolbar powered by `search_columns`
- Inline editing, deletion, and metadata-backed JavaScript hooks
- Column presentation controls (labels, patterns, callbacks, classes, widths, highlights)
- Table metadata, summary rows, custom column buttons, and duplicate toggles
- Form engine with tabbed layouts, hidden field orchestration, and behaviour flags
- Validation helpers, unique checks, and templated defaults directly in edit workflows

The sections below show how to enable each data-layer feature introduced in the latest release.

### Form Input Types

`change_type()` lets you swap the default editor for one or more columns. Common options include `select`, `multiselect`, `checkbox`, `switch`, `file`, `image`, and `rich_editor`. FastCRUD also supports `radio` and `multicheckbox` groups:

```php
$crud
    ->change_type('status', 'radio', null, [
        'values' => [
            'draft'     => 'Draft',
            'published' => 'Published',
            'archived'  => 'Archived',
        ],
        'inline' => true, // optional bootstrap inline layout
    ])
    ->change_type('categories', 'multicheckbox', '', [
        'values' => [
            'tech'    => 'Technology',
            'design'  => 'Design',
            'culture' => 'Culture',
        ],
    ]);
```

Both `multiselect` and `multicheckbox` inputs serialize the checked values into a comma-separated string when the form is submitted (e.g. `"tech,design"`). Use FastCRUD callbacks such as `before_save` or `after_fetch` if you need to convert that representation to another format (JSON, pivot tables, etc.).

### Pagination Controls with `limit_list`

```php
$posts = new Crud('posts');
$posts->limit_list('5,10,25,all'); // adds dropdown + "All" option
echo $posts->render();
```

### Sorting with `order_by`

```php
$users = new Crud('users');
// Single field + direction
$users->order_by('created_at', 'desc');

// Multiple fields with per-field directions
$users->order_by(['status' => 'asc', 'name' => 'asc']);

echo $users->render();
```

### Filtering with `where` / `or_where`

```php
$tickets = new Crud('tickets');
$tickets
    ->where('status =', 'open')
    ->or_where(['priority =' => 'high']);
echo $tickets->render();
```

### Raw Expressions with `no_quotes`

```php
$logs = new Crud('activity_logs');
$logs
    ->no_quotes('created_at')
    ->where('created_at >=', 'NOW() - INTERVAL 7 DAY');
echo $logs->render();
```

### Choosing Visible Columns with `columns`

```php
$posts = new Crud('posts');
$posts
    ->columns('id,title,status,created_at')
    ->order_by('created_at', 'desc');
echo $posts->render();
```

Pass `true` as the second argument (`columns('content,slug', true)`) to hide specific columns instead of listing every one you want to keep.

Joined columns can be referenced with dot notation, which FastCRUD converts to its internal alias format. For example, `columns('authors.username,authors.email')` keeps only the `username` and `email` fields from a join declared as `->join('user_id', 'users', 'id', 'authors')`.

### Joining Related Tables

`join()` augments the auto-generated SQL so the primary table can pull data from a related table without writing custom queries. The method signature is:

```php
join(string $localField, string $joinTable, string $joinField, string|false $alias = false, bool $notInsert = false): self
```

- **`$localField`** – Column on the main table (e.g. `posts`.`user_id`).
- **`$joinTable`** – Table to join (e.g. `users`).
- **`$joinField`** – Column on the joined table used for the match (e.g. `users`.`id`).
- **`$alias`** *(optional)* – Custom alias for the joined table; defaults to `j0`, `j1`, etc. (Pass `'authors'` if you prefer referring to `authors.email`).
- **`$notInsert`** *(optional)* – Reserved flag for future write-control; leave `false` for reads.

Under the hood this call turns the default query into:

```sql
SELECT main.*
FROM posts AS main
LEFT JOIN users AS authors ON main.user_id = authors.id
```

Every column from the joined table is exposed automatically using the pattern `<alias>__<column>`. For example, `authors.email` becomes `authors__email` in the rendered table, so you can reference it in `columns()` or other helpers.

What you can do with a join:

1. **Filter using joined columns**
   ```php
   $posts
       ->join('user_id', 'users', 'id', 'authors')
       ->where('authors.status =', 'active');
   ```

2. **Surface related labels** – pair `join()` with `relation()` or a `subselect()` so the UI shows friendly names:
   ```php
   $posts
       ->join('user_id', 'users', 'id', 'authors')
       ->relation('user_id', 'users', 'id', 'username');
   ```

3. **Expose joined data via custom columns** – for example, add author email with a subselect built on the alias:
   ```php
   $posts
       ->join('user_id', 'users', 'id', 'authors')
       ->subselect('author_email', 'SELECT authors.email');
   ```

By default FastCRUD still selects `main.*`, so the base columns stay unchanged. Use the alias (`authors.some_column`) inside other helpers whenever you need to render or filter on the joined data.

```php
$posts = new Crud('posts');
$posts
    ->join('user_id', 'users', 'id', 'authors')
    ->relation('user_id', 'users', 'id', 'username');
echo $posts->render();
```

### Displaying Relation Labels with `relation`

```php
$posts = new Crud('posts');
$posts->relation('author_id', 'users', 'id', 'username');
echo $posts->render();
```

### Custom SQL via `query`

```php
$topUsers = new Crud('users');
$topUsers->query('SELECT * FROM users WHERE score > 100');
echo $topUsers->render();
```

### Adding Derived Columns with `subselect`

```php
$invoices = new Crud('invoices');
$invoices->subselect('total_paid', 'SELECT SUM(amount) FROM payments WHERE invoice_id = invoices.id');
echo $invoices->render();
```

### Search Toolbar with `search_columns`

```php
$articles = new Crud('articles');
$articles->search_columns('title,content', 'title');
echo $articles->render();
```

## Column & Table Presentation

### Column Labels & Patterns

```php
$posts = new Crud('posts');
$posts
    ->set_column_labels([
        'user_id' => 'Author',
        'title'   => 'Headline',
    ])
    ->column_pattern(['created_at', 'updated_at'], '{value} UTC')
    ->column_pattern('status', '<span class="badge bg-{raw}">{value}</span>');

// Lightweight example:
$posts->column_pattern('slug', '<strong>{value} - {title}</strong>');
```

Available placeholders:

```text
{value}     – formatted value (includes effects of callbacks, cuts, etc.)
{raw}       – raw database value for the column
{column}    – column name (after normalization)
{label}     – resolved label/title for the column
{other}     – raw value from another column in the same row (e.g. {title})
```

Pattern output is injected as-is, so HTML fragments (badges, icons, etc.) can be returned directly.

### Server-Side Callbacks

```php
function render_status($value, array $row, string $column, string $formatted): string
{
    $label = strtoupper($formatted);
    $tone = $label === 'ACTIVE' ? 'success' : 'secondary';

    return '<span class="badge bg-' . $tone . ' text-uppercase">' . $label . '</span>';
}

$users = new Crud('users');
$users->column_callback('status', 'render_status');
```

Callbacks must be serialisable (string callables or `Class::method`) because FastCRUD rebuilds them during each AJAX request. They receive the raw value, the full row, the column name, and the current formatted string. Whatever the callback returns is injected into the cell without escaping, so ensure you only emit trusted HTML.

### Classes, Widths & Truncation

```php
$posts
    ->column_class('user_id', 'text-muted')
    ->column_width('title', '40%')
    ->column_cut('content', 120, '…');
```

Provide Bootstrap utility classes or explicit dimensions to `column_width()`. The helper synchronises header and body widths, and `column_cut()` safely shortens long text before it reaches the browser.

### Highlights

```php
$posts
    ->highlight('status', ['operator' => 'equals', 'value' => 'draft'], 'text-warning')
    ->highlight_row(['column' => 'priority', 'operator' => 'gt', 'value' => 3], 'table-warning');
```

Highlight conditions support `equals`, `not_equals`, `contains`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `empty`, and `not_empty`. Cell highlights append Bootstrap text/background classes, while row highlights add table-level classes (e.g. `table-success`).

### Table Metadata & Summary Rows

```php
$posts
    ->table_name('Posts Overview')
    ->table_icon('bi bi-newspaper')
    ->table_tooltip('FastCRUD live preview of posts')
    ->column_summary('total', 'sum', 'Grand Total', 2);
```

Metadata renders above the toolbar (icon + title + tooltip) while summaries appear in a Bootstrap-styled footer row. Summary queries respect the current filters/search term and support the aggregation types `sum`, `avg`, `min`, `max`, and `count`.

### Duplicate Button

Show a Duplicate button in the actions column and handle the event on the client.

```php
$users = new Crud('users');
$users->enable_duplicate(true); // enable duplicate button in each row
echo $users->render();
```

To show the Duplicate button only when a row matches a condition (for example, `status = "template"`), pass the field, operator, and value:

```php
$users->enable_duplicate(true, 'status', '=', 'template');
```

Control the other built-in actions (Add is boolean; Edit/Delete/Duplicate accept optional conditions):

```php
$users
    ->enable_add(true) // disable Add globally by passing false
    ->enable_view(true, 'status', '!=', 'archived') // hide View when the row is archived
    ->enable_edit(true, 'status', '!=', 'archived') // hide Edit when the row is archived
    ->enable_delete(true, 'status', '!=', 'archived') // hide Delete when the row is archived
    ->enable_duplicate(true, 'status', '=', 'template');
```

`enable_add` only accepts a boolean to toggle the Add button. View/Edit/Delete/Duplicate evaluate each row's raw database values, so the buttons stay hidden when the rule fails.

The button emits a delegated jQuery event you can hook into:

```javascript
$(document).on('fastcrud:duplicate', function (event, payload) {
  // payload = { tableId: string, row: object }
  const tableId = payload.tableId;
  const sourceRow = payload.row; // full row JSON for the clicked record

  // Implement your duplication flow here (e.g., open a custom form
  // pre-filled with sourceRow, or POST to your own endpoint to insert).
});
```

FastCRUD also performs a server-side duplicate by default when you click the button. The event still fires after a successful duplication with `payload.newRow` containing the created record.

### Form Layout & Tabs (`fields` / `default_tab`)

Edit forms can be curated without touching templates. Use `fields()` to choose the inputs to display and optionally group them into tabs, or pass `true` as the second argument to hide fields instead.

```php
$crud = new Crud('users');

$crud
    ->fields('first_name,last_name,email', false, 'Profile')
    ->fields('status,role', false, 'Access')
    ->fields('created_at,updated_at', true) // hide audit fields
    ->default_tab('Profile');
```

The third argument of `fields()` names the tab that will host the provided columns. Tabs are rendered automatically in the edit offcanvas and synced with `default_tab()` so the intended panel opens first per operation mode (`create`, `edit`, `view`, or `all`).

### Field Behaviours & Validation

Pair layout with behaviour helpers to pre-fill values, mark inputs as read-only, and enforce validation in both the browser and PHP layer.

```php
$crud
    ->change_type('status', 'select', 'active', [
        'values' => [
            'active'    => 'Active',
            'suspended' => 'Suspended',
            'archived'  => 'Archived',
        ],
    ])
    ->pass_default('status', 'pending', 'create')
    ->pass_var('updated_by', '{__session_user_id}', 'edit')
    ->readonly('email', ['view', 'edit'])
    ->disabled('role', 'view')
    ->validation_required(['first_name', 'last_name'], 1)
    ->validation_pattern('email', '/^[^@\s]+@[^@\s]+\.[^@\s]+$/i')
    ->unique('email');
```

 - **Type controls:** `change_type()` swaps the rendered input (e.g. select, textarea, checkbox) and accepts defaults plus extra parameters like option lists. FastCrud now inspects the table schema and picks sensible HTML controls automatically—MySQL `TEXT` columns render as `<textarea>`, `DATE` becomes `<input type="date">`, date times become `datetime-local`, `TINYINT(1)`/boolean flags turn into checkboxes, and numeric types map to `<input type="number">`. Calling `change_type()` still overrides the detected defaults when you need something custom. Accepted `type` values include `textarea`, `json` (textarea with JSON validation/pretty-print), `select`/`dropdown`, `multiselect`, `hidden`, `date`, `datetime`/`datetime-local`, `time`, `email`, `number` (`int`/`integer`/`float`/`decimal` aliases), `password`, TinyMCE-backed `rich_editor`, generic file upload `file` (single-file), multi-file upload `files` (comma-separated list), image upload `image` (single-file FilePond with preview), multi-image upload `images` (FilePond with multi-select storing comma-separated filenames), and boolean helpers (`bool`/`checkbox`/`switch`). Use the `$params` argument to tweak each control—for example `['rows' => 6]` for textareas/JSON editors, `['values' => ['draft' => 'Draft']]` for selects, number constraints like `['step' => '0.01', 'min' => 0]`, or WYSIWYG tuning such as `['height' => 400, 'editor' => ['toolbar' => 'undo redo | bold italic']]`. For `file`/`files`, you can restrict types with `['accept' => 'application/pdf,.docx']`. For `image` and `images`, FilePond handles client-side preview and uploads to the path configured by `CrudConfig::getUploadPath()`, automatically restoring previews for existing filenames. Fields wired with `relation()` automatically inherit `select`/`multiselect` widgets plus option lists pulled from the related table, so most lookup dropdowns work out of the box.
- **Custom form widgets:** `field_callback()` lets you replace the generated control with your own markup. The callback receives the field name, current value, hydrated row, and the form mode—return a string (HTML or plain text). When you output your own `<input>`/`<textarea>` you **must** include `data-fastcrud-field="{field}`` so the AJAX submit logic can collect the value:

    ```php
    $crud->field_callback('color', 'my_color_input');

    function my_color_input(string $field, mixed $value, array $row, string $formType): string
    {
        $safeField = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
        $current = is_string($value) && $value !== '' ? $value : '#ff0000';
        $safeValue = htmlspecialchars((string) $current, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <input
                type="color"
                class="form-control"
                data-fastcrud-field="{$safeField}"
                value="{$safeValue}"
            >
        HTML;
    }
    ```

    The callback signature is `function(string $field, mixed $value, array $row, string $formType)` where
    `$formType` is `edit`, `view`, or `create`.

- **Virtual fields:** `custom_field()` registers additional form fields that do not exist in the database. It shares the same callback signature as `field_callback()` (`string $field, mixed $value, array $row, string $formType`) where `$formType` is `edit`, `view`, or `create`. Return a string (HTML or plain text) and reference the field in `->fields()`/`->change_type()` just like a real column.
- **Templated values:** `pass_default()` and `pass_var()` inject placeholders such as `{column}` or custom tokens whenever an input is empty or omitted entirely.
- **Mode-aware locks:** `readonly()` and `disabled()` honour per-mode rules so audit fields stay untouched during edits.
- **Validation helpers:** `validation_required()`, `validation_pattern()`, and `unique()` run checks in JavaScript and repeat them on the server before executing updates.

These settings travel through the AJAX config so the form builder, inline validation, and server logic share the same rules, dramatically reducing custom scripting.

```php
// Swap to a multiline textarea with custom rows
$crud->change_type('bio', 'textarea', '', ['rows' => 6]);

// Render a date picker and pre-fill today's date when empty
$crud->change_type('start_date', 'date', date('Y-m-d'));

// Number input with min/max and decimal step
$crud->change_type('budget', 'number', '0', ['min' => 0, 'max' => 100000, 'step' => '0.01']);

// Boolean flag as checkbox; default to checked when creating
$crud->change_type('is_active', 'checkbox', true);

// TinyMCE-powered rich text editor with custom height
$crud->change_type('content', 'rich_editor', '', ['height' => 450]);

// JSON editor with validation and pretty-print; 8 rows tall
$crud->change_type('settings', 'json', '', ['rows' => 8]);

// Point TinyMCE uploads to a custom public directory (defaults to public/uploads)
\FastCrud\CrudConfig::$upload_path = 'public/content';

// Dropdown fed from an associative array
$crud->change_type('priority', 'select', 'normal', [
    'values' => [
        'low'    => 'Low',
        'normal' => 'Normal',
        'high'   => 'High',
    ],
]);
```

### JavaScript Helpers (`window.FastCrudTables`)

Each rendered table registers itself for quick access:

```javascript
const tableId = 'fastcrud-abc123';
window.FastCrudTables[tableId].search('error logs');
window.FastCrudTables[tableId].setPerPage('all');
```

## Requirements

- PHP 7.4 or higher
- PDO extension

## License

MIT
