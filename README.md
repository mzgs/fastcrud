<div align="center">

# 🚀 FastCRUD

**A fast and simple CRUD operations library for PHP with built-in pagination and AJAX support**

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue?style=for-the-badge&logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)
[![Packagist](https://img.shields.io/badge/Packagist-fastcrud-orange?style=for-the-badge&logo=packagist)](https://packagist.org/packages/mzgs/fastcrud)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.x-purple?style=for-the-badge&logo=bootstrap)](https://getbootstrap.com)

---

*✨ Zero-config setup • 🔄 AJAX-powered • 📊 Built-in pagination • 🎨 Bootstrap 5 ready*

</div>

## 📚 Table of Contents

- [🎆 Features](#features)
- [📦 Installation](#installation)
- [🚀 Quick Start](#quick-start)
- [🔧 Configuration](#configuration)
- [🗃️ Database Editor](#database-editor)
- [📜 API Reference & Customization](#api-reference--customization)
- [📝 License](#license)

## 🎆 Features

✨ **Zero-config CRUD** with automatic pagination, search, and column sorting  
🔄 **AJAX-powered forms**, inline editing, bulk updates, and real-time validation feedback  
🔗 **Nested tables, relations**, and subselect support for modelling complex data  
🪝 **Lifecycle callbacks**, custom columns, and field modifiers for fine-grained control  
📊 **Built-in CSV/Excel export**, soft-delete helpers, and configurable action buttons  
🔍 **Visual query builder** to compose filters & sorts with reusable saved views  
🎨 **Global styling hooks** and upload helpers so you can align the UI with your project

## 📦 Installation

```bash
# Install via Composer
composer require mzgs/fastcrud
```

### 📋 Requirements

✅ **PHP** 8.0 or higher  
✅ **PDO** extension  
✅ **Database** - MySQL, PostgreSQL, SQLite, etc.  
✅ **Bootstrap** 5 for styling  
✅ **jQuery** for AJAX functionality

## 🚀 Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FastCrud\Crud;

// 🔌 Initialize database connection
// Supports: driver, host, port, database, username, password, options
Crud::init([
    'driver' => 'mysql',        // mysql, pgsql, sqlite
    'host' => '127.0.0.1',
    'database' => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password',
]);

// ✨ Create and render a CRUD table - that's it!
echo new Crud('users')->render();
```

> 🎉 **That's it!** FastCRUD automatically generates a complete CRUD interface with pagination, search, and AJAX functionality.

### 🖼️ What You Get Out of the Box

*Example of a fully functional CRUD table generated with just 2 lines of code*

📊 **Data Table** with sorting, searching, and pagination  
✏️ **Inline Editing** for quick updates  
📝 **Forms** for create/edit operations  
📋 **Export** to CSV/Excel  
🗑️ **Bulk Actions** for mass operations

### 🌍 Complete HTML Example

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FastCRUD Demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://mzgs.github.io/fa7/css/all.css" rel="stylesheet"  >
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

</head>
<body>
    <div class="container py-5">
        <div class="card">
            <div class="card-header"><h1>FastCRUD Demo</h1></div>
            <div class="card-body">
                <?php
                require __DIR__ . '/vendor/autoload.php';
                use FastCrud\Crud;
                
                Crud::init(['database' => 'your_db', 'username' => 'user', 'password' => 'pass']);
                echo new Crud('users')->render();
                ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

---

## 🔧 Configuration

### 📋 Rendering Multiple Tables

```php
// Each Crud instance is independent - reuse the same connection
$users = new Crud('users');
$orders = (new Crud('orders'))->setPerPage(10)->order_by('created_at', 'desc');

echo $users->render();
echo $orders->render();
```




## 🗃️ Database Editor

FastCRUD includes a visual Database Editor that provides a web-based interface for managing your database schema. Create, modify, and organize tables and columns directly from your browser without writing SQL.

### 🎯 Features

- **Visual Schema Management** - Create, rename, and delete tables through an intuitive interface
- **Column Management** - Add, rename, and modify column types with live feedback
- **Drag & Drop Reordering** - Reorder columns visually (MySQL only)
- **Multi-Database Support** - Works with MySQL, PostgreSQL, and SQLite
- **Database Export** - Download complete database dumps as SQL files
- **Real-time Updates** - AJAX-powered interface with instant feedback
- **Type-safe Operations** - Built-in validation prevents invalid schema changes

### 🚀 Quick Start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use FastCrud\DatabaseEditor;

// Initialize with an existing PDO instance
$pdo = new PDO('mysql:host=127.0.0.1;dbname=your_database', 'your_username', 'your_password');
DatabaseEditor::init($pdo);

// Render the database editor interface
echo DatabaseEditor::render();
?>
```

> Tip: If you have already called `Crud::init([...])` (or otherwise configured `CrudConfig`) earlier in your bootstrap, you can simply call `DatabaseEditor::init()` — it will reuse the existing DB config/connection.
```php
use FastCrud\Crud;
use FastCrud\DatabaseEditor;

Crud::init(['database' => 'app', 'username' => 'user', 'password' => 'secret']);
DatabaseEditor::init(); // reuses previously set config
echo DatabaseEditor::render();
```

 

### 🛠️ API Reference

#### Database Editor Class

##### Initialization
- **`DatabaseEditor::init(?PDO $pdo = null): void`** – Initialize the database editor with an optional PDO instance
  ```php
  $pdo = new PDO('mysql:host=localhost;dbname=my_app', 'db_user', 'db_password');
  DatabaseEditor::init($pdo);
  ```
  - If `Crud::init([...])` (or `CrudConfig::setDbConfig([...])`) has already been called, `DatabaseEditor::init()` can be invoked without arguments to reuse the existing configuration and connection.
  - Supplying a `PDO` instance lets you share an existing connection without reconfiguring `CrudConfig`.

##### Rendering
- **`DatabaseEditor::render(bool $showHeader = true): string`** – Generate and return the complete database editor HTML interface. Pass `false` to omit the hero header block.
  ```php
  $editorHtml = DatabaseEditor::render();
  echo $editorHtml;
  ```

### ⚡ Supported Operations

#### Table Management
- **Create Tables** - Add new tables with auto-generated primary key
- **Rename Tables** - Click table names to rename them inline
- **Delete Tables** - Remove tables with confirmation prompts
- **Table Overview** - View table structure and column count at a glance

#### Column Management
- **Add Columns** - Create new columns with customizable data types
- **Rename Columns** - Click column names for inline editing
- **Change Types** - Modify column data types using dropdown selectors
- **Reorder Columns** - Drag and drop to reorder (MySQL only)

#### Data Types by Database

**MySQL Types:**
`BIGINT`, `BINARY(255)`, `BIT`, `BOOLEAN`, `CHAR(36)`, `DATE`, `DATETIME`, `DECIMAL(10,2)`, `DOUBLE`, `FLOAT`, `INT`, `JSON`, `LONGTEXT`, `MEDIUMTEXT`, `SMALLINT`, `TEXT`, `TIME`, `TIMESTAMP`, `TINYINT`, `TINYINT(1)`, `VARCHAR(255)`

**PostgreSQL Types:**
`BIGINT`, `BIGSERIAL`, `BOOLEAN`, `DATE`, `DECIMAL(10,2)`, `DOUBLE PRECISION`, `INTEGER`, `JSON`, `JSONB`, `NUMERIC(10,2)`, `SERIAL`, `SMALLINT`, `TEXT`, `TIMESTAMP`, `UUID`, `VARCHAR(255)`

**SQLite Types:**
`INTEGER`, `REAL`, `TEXT`, `BLOB`, `NUMERIC`

### 💾 Database Export

- **One-click Export** - Complete SQL dumps with data
- **Timestamped Files** - Auto-generated filenames
- **Multi-database Support** - Works with MySQL, PostgreSQL, SQLite

### 🔒 Security Features

- **SQL Injection Protection** - Input validation and identifier quoting
- **Type Safety** - Column type validation
- **Error Handling** - Graceful error recovery

### 💡 Usage Tips

- **Backup First** - Always backup before structural changes
- **Test on Dev** - Use development databases before production
- **Column Reordering** - MySQL-only feature
- **Keyboard Navigation** - Tab/Enter for quick navigation

---

## 📜 API Reference & Customization

All customization options are available through the main `FastCrud\Crud` class methods:

### 🛠️ FastCrud\Crud - Main CRUD Class

---

#### 🚀 Setup & Bootstrap

- **`Crud::init(PDO|array|null $dbConfig = null): void`** – Configure the connection defaults (keys like `driver`, `host`, `port`, `database`, `username`, `password`, `options`) or inject an existing PDO instance, and auto-handle AJAX requests.
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
- **`setPerPage(int $perPage): self`** – Set the default rows per page (use any positive integer).
  ```php
  $crud->setPerPage(25);
  ```
- **`limit(int $limit): self`** – Alias of `setPerPage()` for fluent configuration.
  ```php
  $crud->limit(10);
  ```
- **`limit_list(string|array $limits): self`** – Define the pagination dropdown values; mix integers with the special `'all'` keyword.
  ```php
  $crud->limit_list([5, 10, 25, 'all']);
  ```
- **`setPanelWidth(string $width): self`** – Adjust the edit panel width with any CSS length (`'640px'`, `'30%'`, `'40rem'`).
  ```php
  $crud->setPanelWidth('640px');
  $crud->setPanelWidth('30%');
  ```

---

### 📋 Table Display

- **`inline_edit(string|array $fields): self`** – Enable inline edits for selected columns (pass an array or a comma-separated string).
  ```php
  $crud->inline_edit(['status', 'priority']);
  ```
- **`columns(string|array $columns, bool $reverse = false): self`** – Control which columns appear and in what order (pass an array or a comma-separated string).
  ```php
  $crud->columns(['id', 'name', 'email']);
  ```
- **`set_column_labels(array|string $labels, ?string $label = null): self`** – Change table heading labels (accepts associative arrays or single column/label pairs).
  ```php
  $crud->set_column_labels(['created_at' => 'Created']);
  ```
- **`set_field_labels(array|string $labels, ?string $label = null): self`** – Rename form fields without renaming columns; the same shapes as `set_column_labels()` apply.
  ```php
  $crud->set_field_labels('phone', 'Contact Number');
  ```

---

### 📊 Column Presentation

- **`column_pattern(string|array $columns, string $pattern): self`** – Render column values with template tokens like `{value}`, `{formatted}`, `{raw}`, `{column}`, `{label}`, and any column name from the row.
  ```php
  $crud->column_pattern('email', '<a href="mailto:{raw}">{value}</a>');
  $crud->column_pattern('name', '<strong>{first_name} {last_name}</strong> ({id})');
  ```
  Use `{formatted}` to reference the truncated display produced by helpers like `column_cut()`, while `{value}` preserves the original string.
- **`column_callback(string|array $columns, string|array $callback): self`** – Pass values through a formatter callback (use a named function `'function_name'`, `'Class::method'`, or `[ClassName::class, 'method']`).
  ```php
  // Using a named function (function must accept 4 params: $value, $row, $column, $display)
  // $value: current cell value, $row: full row data, $column: column name, $display: formatted value
  function format_total_with_tax($value, $row, $column, $display) {
      $tax_rate = $row['tax_rate'] ?? 0;
      $total_with_tax = $value * (1 + $tax_rate / 100);
      return '$' . number_format($total_with_tax, 2);
  }
  $crud->column_callback('total', 'format_total_with_tax');
  ```
- **`custom_column(string $column, string|array $callback): self`** – Add computed virtual columns to the grid; callback forms mirror `column_callback()`.
  ```php
  // Using a named function (function must accept 1 param: $row)
  // $row: full row data array
  function compute_full_name($row) {
      return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
  }
  $crud->custom_column('full_name', 'compute_full_name');
  ```
- **`column_class(string|array $columns, string|array $classes): self`** – Append custom CSS classes to specific cells (pass space-separated strings or arrays).
  ```php
  $crud->column_class('status', 'text-uppercase text-success');
  ```
- **`column_width(string|array $columns, string $width): self`** – Set fixed widths for columns using any CSS length (`'160px'`, `'20%'`, `'12rem'`).
  ```php
  $crud->column_width('name', '220px');
  ```
- **`column_cut(string|array $columns, int $length, string $suffix = '…'): self`** – Truncate long text for clean tables; customise the suffix (e.g. `'...'`).
  ```php
  $crud->column_cut('description', 80);
  ```
- **`highlight(string|array $columns, string $operator, mixed $value = null, string $class = 'text-warning'): self`** – Highlight cells that match conditions using operators such as `equals`, `not_equals`, `contains`, `not_contains`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `empty`, `not_empty` (symbol aliases like `=`, `!=`, `>=`, `<` are also accepted).
  ```php
  $crud->highlight('status', '=', 'pending', 'text-danger');
  $crud->highlight(['status', 'priority'], 'empty', null, 'text-muted');
  $crud->highlight('notes', 'not_contains', 'internal', 'text-danger');
  ```
- **`highlight_row(string|array $columns, string $operator, mixed $value = null, string $class = 'table-warning'): self`** – Highlight entire rows based on the same operator options used by `highlight()`.
  ```php
  $crud->highlight_row('balance', 'lt', 0, 'table-danger');
  ```
- **`column_summary(string|array $columns, string $type = 'sum', ?string $label = null, ?int $precision = null): self`** – Display aggregated totals in the footer with summary types `sum`, `avg`, `min`, `max`, or `count`.
  ```php
  $crud->column_summary('total', 'sum', 'Grand Total', 2);
  ```

---

### 📋 Field & Form Customization

- **`custom_field(string $field, string|array $callback): self`** – Inject additional, non-database fields into the form; callbacks accept the same shapes as other behaviour hooks.
  ```php
  // Using a named function (function must accept 4 params: $field, $value, $row, $mode)
  // $field: field name, $value: current value, $row: full row data, $mode: 'create'|'edit'|'view'
  function add_confirmation_checkbox($field, $value, $row, $mode) {
      return <<<HTML
          <label class="form-check-label">
              <input 
                  type="checkbox" 
                  class="form-check-input"
                  name="{$field}"
                  data-fastcrud-field="{$field}" 
                  value="1"
              > 
              I confirm this action
          </label>
      HTML;
  }
  $crud->custom_field('confirmation', 'add_confirmation_checkbox');
  ```
- **`field_callback(string|array $fields, string|array $callback): self`** – Create custom HTML input fields by returning raw HTML markup instead of the default input. **Required:** Include `data-fastcrud-field="{field}"` attribute for AJAX form submission.
  ```php
  // Using a named function (function must accept 4 params: $field, $value, $row, $mode)
  // $field: field name, $value: current value, $row: full row data, $mode: 'create'|'edit'|'view'
  function create_color_picker($field, $value, $row, $mode) {
      $value = htmlspecialchars($value ?? '#000000');
      return <<<HTML
          <input
              type="color"
              class="form-control form-control-color"
              name="{$field}"
              data-fastcrud-field="{$field}"
              value="{$value}"
              title="Choose your color"
          >
      HTML;
  }
  $crud->field_callback('color', 'create_color_picker');
  ```
- **`fields(string|array $fields, bool $reverse = false, string|false $tab = false, string|array|false $mode = false): self`** – Arrange form fields into sections and tabs; target specific modes using `'create'`, `'edit'`, `'view'`, or `'all'` (or pass `false` to apply everywhere).
  ```php
  $crud->fields(['name', 'email', 'phone'], false, 'Details');
  ```
- **`default_tab(string $tabName, string|array|false $mode = false): self`** – Choose the default tab for each form mode (`'create'`, `'edit'`, `'view'`, or `'all'`).
  ```php
  $crud->default_tab('Details', ['create', 'edit']);
  ```
- **`change_type(string|array $fields, string $type, mixed $default = '', array $params = []): self`** – Swap the input widget or field type. Supported types include text-based (`'text'`, `'textarea'`, `'rich_editor'`, `'json'`, `'password'`), selection (`'select'`, `'multiselect'`, `'radio'`, `'multicheckbox'`), boolean (`'bool'`, `'checkbox'`, `'switch'`), date/time (`'date'`, `'datetime'`, `'time'`), numeric (`'number'`, `'int'`, `'float'`), file upload (`'file'`, `'files'`, `'image'`, `'images'`), and special (`'email'`, `'color'`, `'hidden'`).
  ```php
  // Text input (default)
  $crud->change_type('username', 'text');
  
  // Textarea for longer content
  $crud->change_type('description', 'textarea', '', ['rows' => 5]);
  
  // Select dropdown with options
  $crud->change_type('status', 'select', 'active', ['options' => ['active' => 'Active', 'inactive' => 'Inactive']]);
  
  // Rich text editor (WYSIWYG with TinyMCE)
  $crud->change_type('content', 'rich_editor', '', ['height' => 400]);
  
  // Image upload (basic)
  $crud->change_type('avatar', 'image', '', ['path' => 'avatars']);
  
  // Image upload with all options
  $crud->change_type('product_image', 'image', '', [
      'path' => 'products',
      'width' => 1200,
      'height' => 800,
      'crop' => false,
      'previewHeight' => 200,
      'aspectRatio' => '3:2',
      'thumbs' => [
          [
              'width' => 300,
              'height' => 200,
              'crop' => true,
              'marker' => '_medium',
              'folder' => 'medium'
          ],
          [
              'width' => 150,
              'height' => 150,
              'crop' => true,
              'marker' => '_thumb',
              'folder' => 'thumbs'
          ]
      ],
      'placeholder' => 'Upload product image',
      'class' => 'product-image-uploader'
  ]);
  
  // Switch/toggle button
  $crud->change_type('is_featured', 'switch', false);
  
  // Number input with constraints
  $crud->change_type('price', 'number', 0, ['step' => 'any', 'min' => 0, 'max' => 1000]);
  
  // Date picker
  $crud->change_type('birth_date', 'date');
  
  // DateTime picker
  $crud->change_type('created_at', 'datetime');
  
  // Color picker
  $crud->change_type('theme_color', 'color', '#000000');
  
  // JSON editor with syntax highlighting
  $crud->change_type('metadata', 'json', '{}');

  // Password input; hashes with password_hash (defaults to PASSWORD_BCRYPT)
  $crud->change_type('password', 'password', '', [
      'placeholder' => 'Set a password',
      'algorithm' => PASSWORD_BCRYPT, // optional, accepts PASSWORD_* constants or aliases
      'cost' => 12,                    // optional password_hash option shortcut
  ]);
  ```
- Password inputs render blank when editing existing rows; entering a new value hashes it automatically before persistence, leaving the field empty keeps the stored hash untouched.
- **`getChangeTypeDefinition(string $field): ?array`** – Inspect previously configured type overrides.
  ```php
  $definition = $crud->getChangeTypeDefinition('avatar');
  ```
- **`pass_var(string|array $fields, mixed $value, string|array $mode = 'all'): self`** – Inject runtime values each time the form renders; target the usual modes (`'create'`, `'edit'`, `'view'`, `'all'`).
  ```php
  $crud->pass_var('updated_by', Auth::id());
  ```
- **`pass_default(string|array $fields, mixed $value, string|array $mode = 'all'): self`** – Supply fallback values when inputs are empty using the same mode flags (`'create'`, `'edit'`, `'view'`, `'all'`).
  ```php
  $crud->pass_default('status', 'pending', 'create');
  ```
- **`readonly(string|array $fields, string|array $mode = 'all'): self`** – Mark fields as read-only per mode (`'create'`, `'edit'`, `'view'`, `'all'`).
  ```php
  $crud->readonly(['email'], ['edit', 'view']);
  ```
- **`disabled(string|array $fields, string|array $mode = 'all'): self`** – Disable inputs entirely for certain modes (`'create'`, `'edit'`, `'view'`, `'all'`).
  ```php
  $crud->disabled('type', 'create');
  ```

---

### ✅ Validation Helpers

- **`validation_required(string|array $fields, int $minLength = 1, string|array $mode = 'all'): self`** – Enforce required fields and minimum length (modes `'create'`, `'edit'`, `'view'`, `'all'`).
  ```php
  $crud->validation_required('name', 1);
  ```
- **`validation_pattern(string|array $fields, string $pattern, string|array $mode = 'all'): self`** – Apply regex-based validation rules using any valid PCRE pattern string (e.g. `'/^\+?[0-9]{7,15}$/'`).
  ```php
  $crud->validation_pattern('phone', '/^\+?[0-9]{7,15}$/');
  ```
- **`unique(string|array $fields, string|array $mode = 'all'): self`** – Ensure values remain unique when saving; scope to modes `'create'`, `'edit'`, `'view'`, or `'all'`.
  ```php
  $crud->unique('email', ['create', 'edit']);
  ```

---

### 🪝 Lifecycle Hooks

Lifecycle hook methods accept only serializable callbacks: named functions (`'function_name'`), static method strings (`'Class::method'`), or class/method arrays (`[ClassName::class, 'method']`). Closures are not supported because the configuration is serialized for AJAX.

- **`before_insert(string|array $callback): self`** – Run logic right before an insert occurs.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: array of field values to be inserted
  // $context: ['operation'=>'insert', 'stage'=>'before', 'table'=>'...', 'mode'=>'create', 'primary_key'=>'...', 'fields'=>[...], 'current_state'=>[...]]
  // $crud: Crud instance
  function add_timestamps($payload, $context, $crud) {
      $payload['created_at'] = date('Y-m-d H:i:s');
      $payload['created_by'] = $_SESSION['user_id'] ?? null;
      return $payload; // Return modified payload or false to cancel
  }
  $crud->before_insert('add_timestamps');
  ```
- **`after_insert(string|array $callback): self`** – React immediately after a record is inserted.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: the inserted record data array
  // $context: ['operation'=>'insert', 'stage'=>'after', 'table'=>'...', 'mode'=>'create', 'primary_key'=>'...', 'primary_value'=>123, 'fields'=>[...], 'row'=>[...]]
  // $crud: Crud instance
  function log_new_user($payload, $context, $crud) {
      $primaryValue = $context['primary_value'] ?? 'unknown';
      error_log("New user created: ID {$primaryValue}, Email: " . ($payload['email'] ?? 'N/A'));
      return $payload; // Return payload (modifications allowed) or null
  }
  $crud->after_insert('log_new_user');
  ```
- **`before_create(string|array $callback): self`** – Intercept create form submissions before validation.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: array of field values to be inserted
  // $context: ['operation'=>'insert', 'stage'=>'before', 'table'=>'...', 'mode'=>'create', 'primary_key'=>'...', 'fields'=>[...], 'current_state'=>[...]]
  // $crud: Crud instance
  function prepare_form_data($payload, $context, $crud) {
      $payload['created_by'] = $_SESSION['user_id'] ?? null;
      $payload['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? null;
      return $payload; // Return modified payload or false to cancel
  }
  $crud->before_create('prepare_form_data');
  ```
- **`after_create(string|array $callback): self`** – React once the create form has finished.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: the inserted record data array
  // $context: ['operation'=>'insert', 'stage'=>'after', 'table'=>'...', 'mode'=>'create', 'primary_key'=>'...', 'primary_value'=>123, 'fields'=>[...], 'row'=>[...]]
  // $crud: Crud instance
  function send_welcome_email($payload, $context, $crud) {
      if (!empty($payload['email'])) {
          mail($payload['email'], 'Welcome!', 'Thank you for registering.');
      }
      return $payload; // Return payload (modifications allowed) or null
  }
  $crud->after_create('send_welcome_email');
  ```
- **`before_update(string|array $callback): self`** – Run logic prior to updating a record.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: array of field values to be updated
  // $context: ['operation'=>'update', 'stage'=>'before', 'table'=>'...', 'primary_key'=>'...', 'primary_value'=>123, 'mode'=>'edit', 'current_row'=>[...], 'fields'=>[...]]
  // $crud: Crud instance
  function update_timestamp($payload, $context, $crud) {
      $payload['updated_at'] = date('Y-m-d H:i:s');
      $payload['updated_by'] = $_SESSION['user_id'] ?? null;
      return $payload; // Return modified payload or false to cancel
  }
  $crud->before_update('update_timestamp');
  ```
- **`after_update(string|array $callback): self`** – React to successful updates.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: the updated record data array
  // $context: ['operation'=>'update', 'stage'=>'after', 'table'=>'...', 'primary_key'=>'...', 'primary_value'=>123, 'mode'=>'edit', 'changes'=>[...], 'previous_row'=>[...], 'row'=>[...]]
  // $crud: Crud instance
  function notify_status_change($payload, $context, $crud) {
      $changes = $context['changes'] ?? [];
      $primaryValue = $context['primary_value'] ?? 'unknown';
      if (isset($changes['status'])) {
          error_log("Status changed for record {$primaryValue}: " . ($payload['status'] ?? 'N/A'));
      }
      return $payload; // Return payload (modifications allowed) or null
  }
  $crud->after_update('notify_status_change');
  ```
- **`before_delete(string|array $callback): self`** – Perform checks before deletions execute.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: current record data to be deleted
  // $context: ['operation'=>'delete', 'stage'=>'before', 'table'=>'...', 'primary_key'=>'...', 'primary_value'=>123, 'mode'=>'hard'|'soft']
  // $crud: Crud instance
  function check_delete_permission($payload, $context, $crud) {
      if (($payload['created_by'] ?? null) !== ($_SESSION['user_id'] ?? null)) {
          return false; // Return false to cancel deletion
      }
      return $payload; // Return payload to continue
  }
  $crud->before_delete('check_delete_permission');
  ```
- **`after_delete(string|array $callback): self`** – Handle clean-up after deletions.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: the deleted record data
  // $context: ['operation'=>'delete', 'stage'=>'after', 'table'=>'...', 'primary_key'=>'...', 'primary_value'=>123, 'deleted'=>true, 'mode'=>'hard'|'soft', 'row'=>[...]]
  // $crud: Crud instance
  function cleanup_files($payload, $context, $crud) {
      if ($context['deleted'] && !empty($payload['avatar_path'])) {
          @unlink($payload['avatar_path']); // Clean up associated files
      }
      return null; // Return value ignored for after_delete
  }
  $crud->after_delete('cleanup_files');
  ```
- **`before_fetch(string|array $callback): self`** – Adjust pagination payloads before data loads.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: ['page'=>1, 'per_page'=>20, 'search_term'=>'...', 'search_column'=>'...']
  // $context: ['operation'=>'fetch', 'stage'=>'before', 'table'=>'...', 'id'=>'...', 'resolved'=>[...]]
  // $crud: Crud instance
  function apply_user_filter($payload, $context, $crud) {
      // Add user-specific filtering to the fetch operation
      $payload['user_filter'] = $_SESSION['user_id'] ?? 0;
      return $payload; // Return modified payload
  }
  $crud->before_fetch('apply_user_filter');
  ```
- **`after_fetch(string|array $callback): self`** – Transform row collections after loading.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: ['rows'=>[...], 'columns'=>[...], 'pagination'=>[...], 'meta'=>[...]]
  // $context: ['operation'=>'fetch', 'stage'=>'after', 'table'=>'...', 'id'=>'...', 'resolved'=>[...]]
  // $crud: Crud instance
  function add_computed_fields($payload, $context, $crud) {
      if (isset($payload['rows'])) {
          foreach ($payload['rows'] as &$row) {
              $row['full_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
          }
      }
      return $payload; // Return modified payload
  }
  $crud->after_fetch('add_computed_fields');
  ```
- **`before_read(string|array $callback): self`** – Inspect requests before a single record load.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: ['primary_key_column'=>'id', 'primary_key_value'=>123]
  // $context: ['operation'=>'read', 'stage'=>'before', 'table'=>'...', 'id'=>'...']
  // $crud: Crud instance
  function log_record_access($payload, $context, $crud) {
      $primaryValue = $payload['primary_key_value'] ?? 'unknown';
      error_log("User " . ($_SESSION['user_id'] ?? 'anonymous') . " accessing record {$primaryValue}");
      return $payload; // Return payload or false to cancel read
  }
  $crud->before_read('log_record_access');
  ```
- **`after_read(string|array $callback): self`** – React after a single record is retrieved.
  ```php
  // Using a named function (function must accept 3 params: $payload, $context, $crud)
  // $payload: ['row'=>[...], 'primary_key_column'=>'id', 'primary_key_value'=>123]
  // $context: ['operation'=>'read', 'stage'=>'after', 'table'=>'...', 'id'=>'...', 'found'=>true]
  // $crud: Crud instance
  function add_permissions($payload, $context, $crud) {
      if (isset($payload['row']) && $context['found']) {
          $payload['row']['can_edit'] = ($payload['row']['created_by'] ?? null) === ($_SESSION['user_id'] ?? null);
      }
      return $payload; // Return modified payload
  }
  $crud->after_read('add_permissions');
  ```

---

### ⚙️ Actions & Toolbar

- **`table_title(string $title): self`** – Set the headline shown above the table.
  ```php
  $crud->table_title('Customer Accounts');
  ```
- **`hide_table_title(bool $hidden = true): self`** – Hide or show the table title row.
  ```php
  $crud->hide_table_title();
  ```
- **`table_tooltip(string $tooltip): self`** – Provide a tooltip for the table header.
  ```php
  $crud->table_tooltip('Live customer data');
  ```
- **`table_icon(string $iconClass): self`** – Add an icon before the table title.
  ```php
  $crud->table_icon('fas fa-users');
  ```
- **`enable_add(bool $enabled = true): self`** – Toggle the add-record button.
  ```php
  $crud->enable_add(false);
  ```
- **`enable_view(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self`** – Control per-row view permissions using the condition operators `equals`, `not_equals`, `contains`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `empty`, `not_empty`.
  ```php
  $crud->enable_view(true, 'status', 'equals', 'active');
  ```
- **`enable_edit(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self`** – Decide which rows are editable with the same operator list as `enable_view()`.
  ```php
  $crud->enable_edit(true, 'locked', 'equals', 0);
  ```
- **`enable_delete(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self`** – Restrict delete access using the standard condition operators (`equals`, `not_equals`, etc.).
  ```php
  $crud->enable_delete(true, 'status', 'not_equals', 'archived');
  ```
- **`enable_duplicate(bool $enabled = true, string|false $field = false, string|false $operand = false, mixed $value = false): self`** – Enable duplication for qualifying rows using the same operator set as above.
  ```php
  $crud->enable_duplicate(true, 'type', 'equals', 'template');
  ```
- **`enable_batch_delete(bool $enabled = true): self`** – Show or hide batch deletion controls.
  ```php
  $crud->enable_batch_delete();
  ```
- **`add_bulk_action(string $name, string $label, array $options = []): self`** – Register a custom bulk update. Supply a `'fields' => ['column' => value]` map and optionally a `'confirm'` message before applying.
  ```php
  $crud->add_bulk_action('flag', 'Flag Selected', [
      'fields'  => ['flagged' => 1],
      'confirm' => 'Flag all chosen records?',
  ]);
  ```
- **`set_bulk_actions(array $actions): self`** – Replace the entire bulk action list. Each entry mirrors `add_bulk_action()` with `name`, `label`, and a `'fields'` array.
  ```php
  $crud->set_bulk_actions([
      ['name' => 'close', 'label' => 'Close', 'fields' => ['open' => 0]],
  ]);
  ```
- **`enable_soft_delete(string $column, array $options = []): self`** – Configure soft-delete behaviour (`'mode'` accepts `'timestamp'`, `'literal'`, or `'expression'`; provide `'value'` for non-timestamp modes and optional `'additional'` assignments).
  ```php
  $crud->enable_soft_delete('deleted_at', ['mode' => 'timestamp']);
  $crud->enable_soft_delete('is_deleted', ['mode' => 'literal', 'value' => 1]);
  ```
- **`set_soft_delete_assignments(array $assignments): self`** – Provide advanced soft-delete assignments; each entry should specify a column and `'mode'` (`'timestamp'`, `'literal'`, `'expression'`) plus an optional `'value'`.
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
- **`add_link_button(string $url, string $iconClass, ?string $label = null, ?string $buttonClass = null, array $options = []): self`** – Append a custom toolbar button; call it multiple times to stack more buttons. The `$options` array lets you set arbitrary HTML attributes like `['target' => '_blank']`.
  ```php
  $crud->add_link_button('/reports', 'fas fa-file-alt', 'Reports', 'btn btn-sm btn-outline-info', ['target' => '_blank']);
  $crud->add_link_button('/reports/export', 'fas fa-download', 'Export', 'btn btn-sm btn-secondary');
  ```
- **`add_multi_link_button(array $mainButton = [], array $items = []): self`** – Append a dropdown button that expands into multiple links. Supply at least one actionable entry in `$items`, each with `'url'` and `'label'` plus optional `'icon'` and `'options'` for per-link attributes (placeholders like `{id}` are resolved per-row). To insert a divider between links, either pass an empty array or `['type' => 'divider']` as an item. You can also push the built-in duplicate action into the dropdown via `['type' => 'duplicate', 'label' => 'Clone']`, which inherits the standard duplicate permissions/styling and uses the existing duplicate handler. `$mainButton` configures the trigger with keys such as `'icon'`, `'label'`, `'button_class'`, `'menu_class'`, `'container_class'`, and `'options'`; omit any key to fall back to sensible defaults. Invoke this repeatedly to add more dropdown clusters.
  ```php
  $crud->add_multi_link_button([
      'icon' => 'fas fa-ellipsis-h',
      'label' => 'More Actions',
      'options' => ['data-bs-auto-close' => 'outside'],
      'container_class' => 'btn-group dropstart'
  ], [
      ['url' => '/customers/{id}', 'label' => 'Profile', 'icon' => 'fas fa-user'],
      ['url' => '/customers/{id}/orders', 'label' => 'Orders', 'icon' => 'fas fa-receipt', 'options' => ['target' => '_blank']],
      ['type' => 'divider'],
      ['type' => 'duplicate', 'label' => 'Clone']
  ]);
  ```
- **`enable_select2(bool $enabled = true): self`** – Enable or disable Select2 widgets for dropdown fields on this CRUD instance, overriding the global `CrudConfig::$enable_select2` setting.
  ```php
  $crud->enable_select2(true); // Use Select2 for this table
  $crud->enable_select2(false); // Disable Select2 for this table
  ```

---

### 🔍 Sorting, Filtering & Relationships

- **`order_by(string|array $fields, string $direction = 'asc'): self`** – Define default ordering for query results; direction must be `'asc'` or `'desc'` (case-insensitive).
  ```php
  $crud->order_by(['status' => 'asc', 'created_at' => 'desc']);
  ```
- **`enable_filters(bool $enabled = true): self`** – Show or hide the Query Builder filters UI in the toolbar.
  ```php
  $crud->enable_filters(); // enable filters UI
  ```
- **`disable_sort(string|array $columns): self`** – Prevent UI sorting on columns.
  ```php
  $crud->disable_sort(['notes']);
  ```
- **`search_columns(string|array $columns, string|false $default = false): self`** – Control which columns participate in quick search; set `$default` to a column name or `false` to leave the selector blank.
  ```php
  $crud->search_columns(['name', 'email'], 'name');
  ```
- **`no_quotes(string|array $fields): self`** – Treat specified expressions as raw SQL by passing column names or raw expressions (array or comma-separated string).
  ```php
  $crud->no_quotes('JSON_EXTRACT(meta, "$.flag")');
  ```
- **`where(string|array $fields, mixed $whereValue = false, string $glue = 'AND'): self`** – Add `AND`-joined conditions via associative arrays (`['status' => 'active']`), field lists with a shared value, or raw SQL strings when `$whereValue === false`.
  ```php
  $crud->where('status = ?', 'active');
  ```
- **`or_where(string|array $fields, mixed $whereValue = false): self`** – Add `OR`-joined conditions using the same shapes as `where()`.
  ```php
  $crud->or_where(['role' => 'admin']);
  ```
- **`join(string|array $fields, string $joinTable, string $joinField, string|array|false $alias = false, bool $notInsert = false): self`** – Join related tables for display; `$alias` may be `false`, a single alias, or an array of aliases matching each field.
  ```php
  $crud->join('role_id', 'roles', 'id', 'r');
  ```
- **`relation(string|array $fields, string $relatedTable, string $relatedField, string|array $relName, array $relWhere = [], string|false $orderBy = false, bool $multi = false): self`** – Populate select fields from related tables; `$relName` can be a column string or an array of columns to concatenate, `$orderBy` accepts a SQL fragment or `false`, and `$multi = true` produces multi-select options.
  ```php
  $crud->relation('country_id', 'countries', 'id', 'name', ['active' => 1]);
  ```

---

### 📊 Query Extensions

- **`query(string $query): self`** – Replace the default select statement with your own SQL (must select the base table columns required by FastCRUD).
  ```php
  $crud->query('SELECT * FROM view_users');
  ```
- **`subselect(string $columnName, string $sql): self`** – Add a derived column via subquery.
  ```php
  $crud->subselect('orders_count', 'SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id');
  ```

---

### 🔗 Nested Data

- **`nested_table(string $instanceName, string $parentColumn, string $innerTable, string $innerTableField, ?callable $configurator = null): self`** – Attach expandable child tables to each row; the method returns the child `Crud` instance so you can continue configuring it.
  ```php
  $crud->nested_table('orders', 'id', 'orders', 'user_id', function (Crud $child) {
      $child->columns(['id', 'total'])->limit(5);
  });
  ```

---

### 🎨 Rendering & Data Access

- **`render(?string $mode = null, mixed $primaryKeyValue = null): string`** – Output the full FastCRUD widget; `$mode` can be `null`, `'create'`, `'edit'`, or `'view'` and `$primaryKeyValue` targets a specific row for non-create modes.
  ```php
  echo $crud->render();
  ```
- **`getId(): string`** – Retrieve the generated component ID for DOM targeting.
  ```php
  $componentId = $crud->getId();
  ```
- **`getTableData(int $page = 1, ?int $perPage = null, ?string $searchTerm = null, ?string $searchColumn = null): array`** – Fetch paginated data for AJAX responses; pass `$perPage = null` (or `'all'` via AJAX) to load everything, and `$searchColumn = null` to search all configured columns.
  ```php
  $payload = $crud->getTableData(1, 10, 'sam', 'name');
  ```

---

### 📄 Record Operations

- **`createRecord(array $fields): ?array`** – Insert a new record with behaviour support; pass a column => value array and receive the inserted row or `null` if cancelled.
  ```php
  $user = $crud->createRecord(['name' => 'Sam', 'email' => 'sam@example.com']);
  ```
- **`updateRecord(string $primaryKeyColumn, mixed $primaryKeyValue, array $fields, string $mode = 'edit'): ?array`** – Update a record and return the latest data; `$mode` is usually `'edit'` but also accepts `'create'` or `'view'` to align with form behaviours.
  ```php
  $updated = $crud->updateRecord('id', 5, ['status' => 'active']);
  ```
- **`deleteRecord(string $primaryKeyColumn, mixed $primaryKeyValue): bool`** – Delete or soft-delete a single record; returns `false` if the action is disabled or rejected by callbacks.
  ```php
  $crud->deleteRecord('id', 9);
  ```
- **`deleteRecords(string $primaryKeyColumn, array $primaryKeyValues): array`** – Delete multiple records at once; the values array should contain scalar primary key values.
  ```php
  $result = $crud->deleteRecords('id', [1, 2, 3]);
  ```
- **`updateRecords(string $primaryKeyColumn, array $primaryKeyValues, array $fields, string $mode = 'edit'): array`** – Apply bulk updates to several records; `$fields` is a column => value map and `$mode` follows the `'create'|'edit'|'view'|'all'` pattern.
  ```php
  $crud->updateRecords('id', [2, 3], ['status' => 'archived']);
  ```
- **`duplicateRecord(string $primaryKeyColumn, mixed $primaryKeyValue): ?array`** – Clone an existing record including allowed columns, or `null` when duplication is disabled or blocked.
  ```php
  $copy = $crud->duplicateRecord('id', 7);
  ```
- **`getRecord(string $primaryKeyColumn, mixed $primaryKeyValue): ?array`** – Retrieve a single record with presentation rules applied.
  ```php
  $record = $crud->getRecord('id', 7);
  ```

---

### 🔄 FastCrud\CrudAjax - AJAX Request Handler

- **`CrudAjax::handle(): void`** – Process the current FastCRUD AJAX request (`fastcrud_ajax=1`) and emit JSON/CSV/Excel responses as needed.
  ```php
  if (CrudAjax::isAjaxRequest()) {
      CrudAjax::handle();
  }
  ```
- **`CrudAjax::isAjaxRequest(): bool`** – Detect whether a request targets FastCRUD by checking for `fastcrud_ajax=1` in `$_GET` or `$_POST`.
  ```php
  if (CrudAjax::isAjaxRequest()) {
      // delegate to FastCRUD handlers
  }
  ```
- **`CrudAjax::autoHandle(): void`** – Automatically handle the request when wired into `Crud::init()`.
  ```php
  CrudAjax::autoHandle();
  ```

---

### ⚙️ FastCrud\CrudConfig - Configuration Manager

#### Database Configuration

- **`CrudConfig::setDbConfig(array $configuration): void`** – Store PDO connection settings (`driver` may be `'mysql'`, `'pgsql'`, or `'sqlite'`, with optional `'host'`, `'port'`, `'database'`, `'username'`, `'password'`, and PDO `'options'`).
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

#### Global Settings

- **`CrudConfig::getUploadPath(): string`** – Resolve the base directory for uploads (defaults to `'public/uploads'` when unset).
  ```php
  $path = CrudConfig::getUploadPath();
  ```
- **`CrudConfig::$upload_path`** – Set the default upload directory for file operations (default: `'public/uploads'`).
  ```php
  CrudConfig::$upload_path = 'assets/uploads';
  ```
- **`CrudConfig::$images_in_grid`** – Enable/disable image thumbnails in grid view (default: `true`).
  ```php
  CrudConfig::$images_in_grid = false; // Hide images in grid
  ```
- **`CrudConfig::$images_in_grid_height`** – Set thumbnail height in pixels for grid images (default: `55`).
  ```php
  CrudConfig::$images_in_grid_height = 80; // Larger thumbnails
  ```
- **`CrudConfig::$bools_in_grid`** – Display boolean fields as toggle switches in grid cells (default: `true`).
  ```php
  CrudConfig::$bools_in_grid = false; // Show as text instead
  ```
- **`CrudConfig::$enable_select2`** – Enable Select2 widgets globally for all dropdowns (default: `false`).
  ```php
  CrudConfig::$enable_select2 = true; // Use Select2 by default
  ```
- **`CrudConfig::$enable_filters`** – Show query builder filter controls in toolbar by default (default: `false`).
  ```php
  CrudConfig::$enable_filters = true; // Enable filters globally
  ```
- **`CrudConfig::$hide_table_title`** – Hide the table header block (title, icon, tooltip) by default (default: `false`).
  ```php
  CrudConfig::$hide_table_title = true; // Remove table titles globally
  ```

---

### 🎨 FastCrud\CrudStyle - Global Styling Configuration

Customize default CSS classes for buttons, rows, and components throughout FastCRUD by modifying these public static properties. All properties use Bootstrap 5 classes by default but can be overridden with any CSS framework.

#### 🔘 Toolbar & Action Buttons

- **`CrudStyle::$add_button_class`** – Add new record button (default: `'btn btn-sm btn-success'`)
- **`CrudStyle::$toolbar_action_button_global_class`** – Apply the same classes to all toolbar action buttons (default: `''`, values override individual toolbar buttons unless explicitly customised)
- **`CrudStyle::$link_button_class`** – Custom link buttons added via `add_link_button()` (default: `'btn btn-sm btn-outline-secondary'`)
- **`CrudStyle::$search_button_class`** – Search form submit button (default: `'btn btn-outline-primary'`)
- **`CrudStyle::$search_clear_button_class`** – Search form clear button (default: `'btn btn-outline-secondary'`)
- **`CrudStyle::$filters_button_class`** – Query builder filters toggle button (default: `'btn btn-sm btn-outline-secondary'`)
- **`CrudStyle::$batch_delete_button_class`** – Bulk delete button (default: `'btn btn-sm btn-danger'`)
- **`CrudStyle::$bulk_apply_button_class`** – Bulk actions apply button (default: `'btn btn-sm btn-outline-primary'`)
- **`CrudStyle::$export_csv_button_class`** – CSV export button (default: `'btn btn-sm btn-outline-secondary'`)
- **`CrudStyle::$export_excel_button_class`** – Excel export button (default: `'btn btn-sm btn-outline-secondary'`)

#### 🎯 Row Action Buttons

- **`CrudStyle::$action_button_global_class`** – Apply the same classes to all row action buttons (default: `''`, values override individual buttons unless explicitly customised)
- **`CrudStyle::$view_action_button_class`** – View/read record button (default: `'btn btn-sm btn-secondary'`)
- **`CrudStyle::$edit_action_button_class`** – Edit record button (default: `'btn btn-sm btn-primary'`)
- **`CrudStyle::$delete_action_button_class`** – Delete record button (default: `'btn btn-sm btn-danger'`)
- **`CrudStyle::$duplicate_action_button_class`** – Duplicate record button (default: `'btn btn-sm btn-info'`)

> Tip: Use `CrudStyle::$action_button_global_class` for row action buttons and `CrudStyle::$toolbar_action_button_global_class` for top toolbar actions while still allowing per-button overrides. Setting a toolbar button property to any non-empty string (even the documented default) keeps the global toolbar class from applying to that button.

#### 🗂️ Panel & Form Buttons

- **`CrudStyle::$panel_save_button_class`** – Save button in edit/create panels (default: `'btn btn-primary'`)
- **`CrudStyle::$panel_cancel_button_class`** – Cancel button in edit/create panels (default: `'btn btn-outline-secondary'`)

#### 🌲 Nested Tables & Grid

- **`CrudStyle::$nested_toggle_button_classes`** – Expand/collapse buttons for nested tables (default: `'btn btn-link p-0'`)
- **`CrudStyle::$edit_view_row_highlight_class`** – Table row highlight while editing/viewing (default: `'table-active'`)
- **`CrudStyle::$bools_in_grid_color`** – Color variant for boolean switches in grid cells (default: `'primary'`)

#### 🎯 Action Button Icons

- **`CrudStyle::$view_action_icon`** – Icon class for view/read action buttons (default: `'fas fa-eye'`)
- **`CrudStyle::$edit_action_icon`** – Icon class for edit action buttons (default: `'fas fa-edit'`)
- **`CrudStyle::$delete_action_icon`** – Icon class for delete action buttons (default: `'fas fa-trash'`)
- **`CrudStyle::$duplicate_action_icon`** – Icon class for duplicate action buttons (default: `'far fa-copy'`)
- **`CrudStyle::$expand_action_icon`** – Icon class for expanding nested records (default: `'fas fa-chevron-down'`)
- **`CrudStyle::$collapse_action_icon`** – Icon class for collapsing nested records (default: `'fas fa-chevron-up'`)
- **`CrudStyle::$x_icon_class`** – Icon class for dismiss/remove buttons (default: `'fas fa-xmark'`)
- **`CrudStyle::$action_icon_size`** – Font size for action button icons (default: `'1.05rem'`)

#### 💡 Usage Examples

```php
use FastCrud\CrudStyle;

// 🎨 Customize for dark theme
CrudStyle::$add_button_class = 'btn btn-sm btn-outline-success';
CrudStyle::$edit_action_button_class = 'btn btn-sm btn-outline-warning';
CrudStyle::$delete_action_button_class = 'btn btn-sm btn-outline-danger';
CrudStyle::$edit_view_row_highlight_class = 'table-dark';

// 🌈 Customize for colorful theme
CrudStyle::$search_button_class = 'btn btn-info';
CrudStyle::$export_csv_button_class = 'btn btn-success';
CrudStyle::$export_excel_button_class = 'btn btn-warning';
CrudStyle::$bools_in_grid_color = 'success';

// 📱 Customize for mobile/compact theme
CrudStyle::$add_button_class = 'btn btn-xs btn-success';
CrudStyle::$view_action_button_class = 'btn btn-xs btn-outline-secondary';
CrudStyle::$edit_action_button_class = 'btn btn-xs btn-outline-primary';
CrudStyle::$delete_action_button_class = 'btn btn-xs btn-outline-danger';

// 🎯 Customize panel buttons
CrudStyle::$panel_save_button_class = 'btn btn-lg btn-success';
CrudStyle::$panel_cancel_button_class = 'btn btn-lg btn-secondary';

// 🌲 Customize nested table styling
CrudStyle::$nested_toggle_button_classes = 'btn btn-outline-primary btn-sm';

// 🎯 Customize action icons
CrudStyle::$view_action_icon = 'fas fa-search';
CrudStyle::$edit_action_icon = 'fas fa-pencil';
CrudStyle::$delete_action_icon = 'fas fa-times';
CrudStyle::$duplicate_action_icon = 'fas fa-clone';
CrudStyle::$expand_action_icon = 'fas fa-plus';
CrudStyle::$collapse_action_icon = 'fas fa-minus';
CrudStyle::$action_icon_size = '1.2rem';

// 🌐 Using different icon libraries (Bootstrap Icons)
CrudStyle::$view_action_icon = 'bi bi-eye';
CrudStyle::$edit_action_icon = 'bi bi-pencil';
CrudStyle::$delete_action_icon = 'bi bi-trash';
CrudStyle::$duplicate_action_icon = 'bi bi-files';

// 💼 Using custom CSS framework (Tailwind CSS example)
CrudStyle::$add_button_class = 'bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded';
CrudStyle::$edit_action_button_class = 'bg-blue-500 hover:bg-blue-700 text-white font-bold py-1 px-2 rounded text-sm';
```

---

### 💾 FastCrud\DB - Database Connection

- **`DB::connection(): PDO`** – Access the shared PDO instance used by FastCRUD; connection settings come from `CrudConfig::setDbConfig()`.
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

---

### ⚠️ FastCrud\ValidationException - Validation Errors

- **`__construct(string $message, array $errors = [], int $code = 0, ?Throwable $previous = null)`** – Create a validation exception with field errors supplied as `['field' => 'message']`.
  ```php
  throw new ValidationException('Invalid data', ['email' => 'Taken']);
  ```
- **`getErrors(): array`** – Retrieve the error messages that were supplied.
  ```php
  $errors = $exception->getErrors();
  ```

---

## 📝 License

This project is licensed under the **MIT License** - see the [LICENSE](LICENSE) file for details.

---

<div align="center">

**Made with ❤️ by the FastCRUD Team**

🐛 Found a bug? [Report it here](https://github.com/mzgs/fastcrud/issues)  
⭐ Like this project? Give it a star!  
💬 Questions? [Start a discussion](https://github.com/mzgs/fastcrud/discussions)

</div>
