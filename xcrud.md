# XCRUD Complete Documentation

XCRUD is a powerful PHP CRUD (Create, Read, Update, Delete) system that allows you to build database-driven applications with minimal coding. It provides an intuitive interface for managing database tables with extensive customization options.

## Table of Contents

1. [Main Methods & Loading](#main-methods--loading)
2. [Data Selection](#data-selection)
3. [Table Overview & Display](#table-overview--display)
4. [Create & Edit Operations](#create--edit-operations)
5. [Field Types](#field-types)
6. [Data Manipulation & Callbacks](#data-manipulation--callbacks)
7. [Date & Time Formats](#date--time-formats)
8. [Configuration](#configuration)
9. [Database Operations](#database-operations)
10. [Post Data Handling](#post-data-handling)

---

## Main Methods & Loading

### Getting Started

```php
// Create an XCRUD instance
$xcrud = Xcrud::get_instance();

// Set the table to work with
$xcrud->table('users');

// Render the CRUD interface
echo $xcrud->render();
```

### Core Methods

#### `get_instance($name = false)`
Returns an XCRUD object. Can be called anywhere to create or retrieve instances.

```php
// Create a new instance
$xcrud = Xcrud::get_instance();

// Create a named instance
$xcrud = Xcrud::get_instance('MyInstance');

// Retrieve the same instance elsewhere
$xcrud2 = Xcrud::get_instance('MyInstance');
```

#### `table($table_name)`
Sets the table to work with. This method is mandatory.

```php
$xcrud->table('products');

// Work with a table from another database
$xcrud->table('other_db.products');
```

#### `connection($user, $pass, $table, $host = 'localhost', $encode = 'utf-8')`
Creates a custom database connection for the current instance.

```php
$xcrud->connection('username', 'password', 'remote_database', 'localhost', 'utf-8');
```

#### `language($lang)`
Sets the interface language.

```php
$xcrud->language('en'); // English
$xcrud->language('fr'); // French
```

#### `render($task = false, $primary = false)`
Displays the table data.

```php
// Render grid view
echo $xcrud->render();

// Render create form
echo $xcrud->render('create');

// Render edit form for record with ID 12
echo $xcrud->render('edit', 12);

// Render view details for record with ID 85
echo $xcrud->render('view', 85);
```

### Multiple Instances

```php
// Create multiple instances on one page
$users_xcrud = Xcrud::get_instance();
$users_xcrud->table('users');

$products_xcrud = Xcrud::get_instance();
$products_xcrud->table('products');

echo $users_xcrud->render();
echo $products_xcrud->render();
```

---

## Data Selection

### WHERE Conditions

#### `where($fields, $where_val = false, $glue = 'AND')`
Sets selection conditions.

```php
// Simple conditions
$xcrud->where('status =', 'active');
$xcrud->where('age >', 18);

// Multiple conditions (AND)
$xcrud->where('status =', 'active')->where('age >', 18);

// Using OR
$xcrud->where('status =', 'active');
$xcrud->where('role =', 'admin', 'OR');

// Using IN
$xcrud->where('category_id', array(1, 2, 3));

// Using NOT IN
$xcrud->where('status !', array('deleted', 'banned'));

// Using array syntax
$xcrud->where(array(
    'status =' => 'active',
    'age >' => 18
));

// Custom SQL
$xcrud->where("users.status = 'active' AND users.created > '2023-01-01'");
```

#### `or_where($fields, $where_val)`
Same as where() but uses OR operator.

```php
$xcrud->or_where('role =', 'admin');
$xcrud->or_where('role =', 'moderator');
```

#### `no_quotes($fields)`
Cancels automatic value escaping for MySQL functions.

```php
$xcrud->no_quotes('created');
$xcrud->where('created !=', 'null');
$xcrud->pass_var('created', 'NOW()');
```

### Relations

#### `relation($fields, $rel_tbl, $rel_field, $rel_name, $rel_where = [], $order_by = false, $multi = false)`
Creates a 1-n database relation.

```php
// Simple relation
$xcrud->relation('customer_id', 'customers', 'id', 'customer_name');

// Relation with concatenated fields
$xcrud->relation('user_id', 'users', 'id', array('first_name', 'last_name'));

// Relation with WHERE condition
$xcrud->relation('category_id', 'categories', 'id', 'name', 
    array('status' => 'active'));

// Multi-select relation
$xcrud->relation('tags', 'tags', 'id', 'tag_name', [], false, true);

// Tree structure dropdown
$xcrud->relation('parent_id', 'categories', 'id', 'name', [], false, false, ' ', 
    array('primary_key' => 'id', 'parent_key' => 'parent_id'));

// Dependent dropdowns
$xcrud->relation('country_id', 'countries', 'id', 'country_name');
$xcrud->relation('city_id', 'cities', 'id', 'city_name', [], false, false, ' ', 
    false, 'country_id', 'country_id');
```

#### `fk_relation($label, $field, $fk_table, $in_fk_field, $out_fk_field, $rel_tbl, $rel_field, $rel_name)`
Creates an n-n (many-to-many) database relation.

```php
// Many-to-many relation (e.g., users and roles)
$xcrud->fk_relation('User Roles', 'user_id', 'user_roles', 
    'user_id', 'role_id', 'roles', 'id', 'role_name');
```

#### `nested_table($instance_name, $field, $inner_tbl, $tbl_field)`
Creates drill-down nested tables.

```php
// Main table: orders
$xcrud->table('orders');

// Nested table: order details
$order_details = $xcrud->nested_table('order_details', 'order_id', 
    'order_details', 'order_id');

// Configure nested table
$order_details->columns('product_id,quantity,price');
$order_details->unset_add();
```

### Joins

#### `join($field, $join_tbl, $join_field, $alias = false, $not_insert = false)`
Performs table joins.

```php
// Simple join
$xcrud->table('users');
$xcrud->join('id', 'profiles', 'user_id');

// Multiple joins
$xcrud->table('orders');
$xcrud->join('customer_id', 'customers', 'id');
$xcrud->join('customers.country_id', 'countries', 'id');

// Join with alias
$xcrud->join('category_id', 'categories', 'id', 'cat');

// Join without insert/delete on joined table
$xcrud->join('product_id', 'products', 'id', false, true);
```

### Custom SQL

#### `query($query)`
Execute custom SQL queries (read-only).

```php
$xcrud->query('SELECT * FROM users WHERE age > 25 AND status = "active"');
echo $xcrud->render();
```

---

## Table Overview & Display

### Column Configuration

#### `columns($columns, $reverse = false)`
Set which columns to display.

```php
// Show specific columns
$xcrud->columns('name,email,status,created');

// Hide specific columns
$xcrud->columns('password,token', true);
```

#### `column_name($field, $text)`
Set custom column headers.

```php
$xcrud->column_name('fname', 'First Name');
$xcrud->column_name('lname', 'Last Name');
```

#### `label($fields, $label = '')`
Set field labels for forms and views.

```php
$xcrud->label('email', 'Email Address');
$xcrud->label(array(
    'fname' => 'First Name',
    'lname' => 'Last Name',
    'dob' => 'Date of Birth'
));
```

#### `column_pattern($column, $pattern)`
Custom column output patterns.

```php
$xcrud->column_pattern('username', 'User: {value}');
$xcrud->column_pattern('full_name', '{first_name} {last_name}');
$xcrud->column_pattern('email', '<a href="mailto:{value}">{value}</a>');
```

#### `column_callback($column, $callable, $path = '')`
Custom column rendering with callback.

```php
$xcrud->column_callback('status', 'format_status');

// In functions.php
function format_status($value, $fieldname, $primary_key, $row, $xcrud) {
    $color = $value == 'active' ? 'green' : 'red';
    return '<span style="color: '.$color.'">'.$value.'</span>';
}
```

### Display Options

#### `table_name($name, $tooltip = false, $icon = false)`
Set table title.

```php
$xcrud->table_name('User Management');
$xcrud->table_name('Products', 'Manage all products', 'icon-box');
```

#### `limit($limit)`
Set rows per page.

```php
$xcrud->limit(25);
```

#### `limit_list($limits)`
Set pagination options.

```php
$xcrud->limit_list('10,25,50,100,all');
```

#### `order_by($field, $direction = 'asc')`
Set default sorting.

```php
$xcrud->order_by('created', 'desc');
$xcrud->order_by(array('status' => 'asc', 'name' => 'asc'));
```

#### `search_columns($columns, $default = false)`
Define searchable columns.

```php
$xcrud->search_columns('name,email,phone', 'name');
```

### Visual Enhancements

#### `highlight($column, $operator, $value, $color, $class = '')`
Highlight cells based on conditions.

```php
$xcrud->highlight('status', '=', 'active', '#90EE90');
$xcrud->highlight('price', '>', 100, 'red');
$xcrud->highlight('quantity', '<', 10, '', 'low-stock');
```

#### `highlight_row($column, $operator, $value, $color, $class = '')`
Highlight entire rows.

```php
$xcrud->highlight_row('status', '=', 'urgent', '#FF6B6B');
```

#### `column_class($columns, $class)`
Add CSS classes to columns.

```php
$xcrud->column_class('price,total', 'text-right font-bold');
```

#### `column_width($columns, $width)`
Set column widths.

```php
$xcrud->column_width('description', '40%');
$xcrud->column_width('id,status', '80px');
```

#### `column_cut($length, $fields = false)`
Truncate column text.

```php
// Truncate all columns to 50 characters
$xcrud->column_cut(50);

// Truncate specific columns
$xcrud->column_cut(100, 'description,notes');
```

### Calculated Columns

#### `subselect($column_name, $sql, $before = false)`
Add calculated columns.

```php
// Add total column
$xcrud->subselect('total', 'SELECT SUM(quantity * price) FROM order_items WHERE order_id = {id}');

// Simple calculation
$xcrud->subselect('total_price', '{price} * {quantity}');

// Position before another column
$xcrud->subselect('item_count', 'SELECT COUNT(*) FROM items WHERE category_id = {id}', 'status');
```

#### `sum($fields, $class = '', $custom_text = '')`
Show sum totals.

```php
$xcrud->sum('price,tax,total');
$xcrud->sum('amount', 'text-bold', 'Grand Total: {value}');
```

<!-- Removed: Action Buttons (custom buttons/duplicate) — not supported in FastCRUD -->

### Visibility Controls

#### `unset_add()`, `unset_edit()`, `unset_remove()`, `unset_view()`
Control CRUD operations.

```php
// Remove add button
$xcrud->unset_add();

// Conditional edit
$xcrud->unset_edit(true, 'locked', '=', '1');

// Conditional delete
$xcrud->unset_remove(true, 'system_record', '=', '1');
```

#### `hide_button($button_names)`
Hide specific buttons.

```php
$xcrud->hide_button('save_return,save_new');
```

---

## Create & Edit Operations

### Field Management

#### `fields($fields, $reverse = false, $tab = false, $mode = false)`
Control which fields appear in forms.

```php
// Show specific fields
$xcrud->fields('name,email,phone,address');

// Hide specific fields
$xcrud->fields('id,created_at,updated_at', true);

// Group fields in tabs
$xcrud->fields('username,email,password', false, 'Account');
$xcrud->fields('first_name,last_name,phone', false, 'Personal');
$xcrud->fields('address,city,country', false, 'Location');

// Mode-specific fields
$xcrud->fields('password', false, false, 'create');
$xcrud->fields('last_login,login_count', false, false, 'view');
```

#### `default_tab($name)`
Set default tab name.

```php
$xcrud->default_tab('General Information');
```

### Field Attributes

#### `readonly($fields, $mode = 'all')`
Make fields read-only.

```php
$xcrud->readonly('created_at,updated_at');
$xcrud->readonly('username', 'edit');
```

#### `disabled($fields, $mode = 'all')`
Disable fields.

```php
$xcrud->disabled('system_field');
$xcrud->disabled('email', 'edit');
```

#### `no_editor($fields)`
Disable text editor for textareas.

```php
$xcrud->no_editor('simple_text,code_field');
```

### Field Types

#### `change_type($field, $type, $default = '', $params = [])`
Change field input type.

See [Field Types](#field-types) section for detailed examples.

### Data Passing

#### `pass_var($field, $value, $mode = 'all')`
Pass data directly to database.

```php
$xcrud->pass_var('user_id', $_SESSION['user_id']);
$xcrud->pass_var('created', date('Y-m-d H:i:s'), 'create');
$xcrud->pass_var('modified', date('Y-m-d H:i:s'), 'edit');

// Using field from current row
$xcrud->pass_var('slug', '{title}');
```

#### `pass_default($field, $value)`
Set default values.

```php
$xcrud->pass_default('status', 'active');
$xcrud->pass_default(array(
    'status' => 'pending',
    'priority' => 'normal',
    'assigned_to' => 'unassigned'
));
```

### Validation

#### `validation_required($fields, $chars = 1)`
Make fields required.

```php
$xcrud->validation_required('name,email');
$xcrud->validation_required('description', 10);
$xcrud->validation_required(array(
    'name' => 1,
    'description' => 10,
    'phone' => 7
));
```

#### `validation_pattern($fields, $pattern)`
Validate with patterns.

```php
$xcrud->validation_pattern('email', 'email');
$xcrud->validation_pattern('phone', 'numeric');
$xcrud->validation_pattern('username', '[a-zA-Z0-9_]{3,20}');
$xcrud->validation_pattern(array(
    'email' => 'email',
    'age' => 'integer',
    'price' => 'decimal'
));
```

Available patterns:
- `email` - Valid email address
- `alpha` - Letters only
- `alpha_numeric` - Letters and numbers
- `alpha_dash` - Letters, numbers, dashes, underscores
- `numeric` - Numbers only
- `integer` - Integer numbers
- `decimal` - Decimal numbers
- `natural` - Natural numbers

#### `unique($fields)`
Ensure unique values.

```php
$xcrud->unique('email,username');
```

### Conditional Logic

#### `condition($field, $operator, $value, $method, $params)`
Apply conditional rules.

```php
// Disable fields based on condition
$xcrud->condition('role', '=', 'guest', 'disabled', 'admin_fields');

// Require fields conditionally
$xcrud->condition('payment_method', '=', 'credit_card', 
    'validation_required', 'card_number,cvv');

// Hide editor conditionally
$xcrud->condition('format', '=', 'plain', 'no_editor', 'content');
```

### Email Alerts

#### `alert($email_field, $cc, $subject, $body, $link = '')`
Send email notifications.

```php
// Send on all changes
$xcrud->alert('user_email', 'admin@site.com', 
    'Account Updated', 
    'Hello {name}, your account has been updated.');

// Send on create only
$xcrud->alert_create('email', '', 
    'Welcome!', 
    'Welcome {name}! Your account has been created.');

// Send on edit only
$xcrud->alert_edit('email', 'manager@site.com', 
    'Profile Updated', 
    'User {username} has updated their profile.');
```

#### `mass_alert($table, $column, $where, $subject, $message)`
Send mass emails.

```php
$xcrud->mass_alert('users', 'email', 'newsletter = 1', 
    'Newsletter', 
    'Check out our latest updates!');
```

---

## Field Types

### Text Fields

```php
// Text input with max length
$xcrud->change_type('username', 'text', '', array('maxlength' => 20));

// Integer input
$xcrud->change_type('age', 'int', '0', array('maxlength' => 3));

// Float input
$xcrud->change_type('price', 'float', '0.00', array('maxlength' => 10));

// Hidden field
$xcrud->change_type('token', 'hidden');

// Textarea
$xcrud->change_type('description', 'textarea');

// Text editor (WYSIWYG)
$xcrud->change_type('content', 'texteditor');
```

### Date & Time Fields

```php
// Date picker
$xcrud->change_type('birth_date', 'date');

// DateTime picker
$xcrud->change_type('created_at', 'datetime');

// Time picker
$xcrud->change_type('appointment_time', 'time');

// Year selector
$xcrud->change_type('graduation_year', 'year');

// Timestamp
$xcrud->change_type('last_login', 'timestamp');

// Date range
$xcrud->change_type('start_date', 'date', '', 
    array('range_end' => 'end_date'));
$xcrud->change_type('end_date', 'date', '', 
    array('range_start' => 'start_date'));
```

### Selection Fields

```php
// Dropdown select
$xcrud->change_type('status', 'select', 'active', 
    array('values' => 'active,inactive,pending,deleted'));

// Multi-select
$xcrud->change_type('tags', 'multiselect', 'news,featured', 
    array('values' => 'news,featured,popular,trending,archive'));

// Select with optgroups
$xcrud->change_type('location', 'select', '', array('values' => array(
    'North America' => array(
        'US' => 'United States',
        'CA' => 'Canada',
        'MX' => 'Mexico'
    ),
    'Europe' => array(
        'UK' => 'United Kingdom',
        'FR' => 'France',
        'DE' => 'Germany'
    )
)));

// Radio buttons
$xcrud->change_type('gender', 'radio', 'other', 
    array('values' => array('male' => 'Male', 'female' => 'Female', 'other' => 'Other')));

// Checkboxes
$xcrud->change_type('newsletter', 'checkboxes', '', 
    array('values' => array(1 => 'Subscribe to newsletter')));

// Boolean checkbox
$xcrud->change_type('is_active', 'bool');
```

### File Upload

```php
// Basic file upload
$xcrud->change_type('document', 'file', '', 
    array('path' => 'uploads/documents'));

// File upload without renaming
$xcrud->change_type('report', 'file', '', 
    array(
        'path' => 'uploads/reports',
        'not_rename' => true
    ));

// Image upload with resizing
$xcrud->change_type('photo', 'image', '', array(
    'path' => 'uploads/photos',
    'width' => 800,
    'height' => 600
));

// Image with crop
$xcrud->change_type('avatar', 'image', '', array(
    'path' => 'uploads/avatars',
    'width' => 200,
    'height' => 200,
    'crop' => true
));

// Manual crop
$xcrud->change_type('banner', 'image', '', array(
    'manual_crop' => true
));

// Image with thumbnails
$xcrud->change_type('gallery_image', 'image', '', array(
    'path' => 'uploads/gallery',
    'thumbs' => array(
        array('width' => 150, 'marker' => '_thumb'),
        array('width' => 400, 'marker' => '_medium'),
        array('width' => 800, 'folder' => 'large')
    )
));

// Image with watermark
$xcrud->change_type('product_image', 'image', '', array(
    'path' => 'uploads/products',
    'watermark' => 'assets/watermark.png',
    'watermark_position' => array(95, 95)
));

// Store image as BLOB
$xcrud->change_type('photo_blob', 'image', '', array('blob' => true));
```

### Special Fields

```php
// Password field
$xcrud->change_type('password', 'password', 'sha256', 
    array('maxlength' => 50, 'placeholder' => 'Enter password'));

// Price field
$xcrud->change_type('amount', 'price', '0.00', array(
    'prefix' => '$',
    'suffix' => ' USD',
    'decimals' => 2,
    'separator' => ','
));

// Remote image
$xcrud->change_type('avatar_url', 'remote_image', '', 
    array('link' => 'https://cdn.example.com/avatars/'));

// Map location
$xcrud->change_type('location', 'point', '40.7128,-74.0060', array(
    'text' => 'Office Location',
    'width' => 600,
    'height' => 400,
    'zoom' => 15,
    'search' => true
));
```

---

## Data Manipulation & Callbacks

### CRUD Callbacks

#### Before/After Insert

```php
$xcrud->before_insert('process_before_insert');
$xcrud->after_insert('process_after_insert');

// In functions.php
function process_before_insert($postdata, $xcrud) {
    // Hash password
    if ($postdata->get('password')) {
        $postdata->set('password', password_hash($postdata->get('password'), PASSWORD_DEFAULT));
    }
    // Set creation timestamp
    $postdata->set('created_at', date('Y-m-d H:i:s'));
}

function process_after_insert($postdata, $primary_key, $xcrud) {
    // Create user profile
    $db = Xcrud_db::get_instance();
    $db->query("INSERT INTO profiles (user_id) VALUES ($primary_key)");
    
    // Send welcome email
    mail($postdata->get('email'), 'Welcome!', 'Your account has been created.');
}
```

#### Before/After Update

```php
$xcrud->before_update('process_before_update');
$xcrud->after_update('process_after_update');

// In functions.php
function process_before_update($postdata, $primary_key, $xcrud) {
    // Update modification timestamp
    $postdata->set('updated_at', date('Y-m-d H:i:s'));
    
    // Log the update
    $db = Xcrud_db::get_instance();
    $db->query("INSERT INTO audit_log (table_name, record_id, action, timestamp) 
                VALUES ('users', $primary_key, 'update', NOW())");
}
```

#### Before/After Remove

```php
$xcrud->before_remove('process_before_delete');
$xcrud->after_remove('process_after_delete');

// In functions.php
function process_before_delete($primary_key, $xcrud) {
    // Archive the record before deletion
    $db = Xcrud_db::get_instance();
    $db->query("INSERT INTO archive_users SELECT * FROM users WHERE id = $primary_key");
}

function process_after_delete($primary_key, $xcrud) {
    // Clean up related data
    $db = Xcrud_db::get_instance();
    $db->query("DELETE FROM user_sessions WHERE user_id = $primary_key");
    $db->query("DELETE FROM user_preferences WHERE user_id = $primary_key");
}
```

<!-- Removed: Custom Actions (replace_insert/update/remove) — not applicable here -->

### Field Callbacks

```php
// Column callback for list view
$xcrud->column_callback('status', 'format_status_column');

// Field callback for edit view
$xcrud->field_callback('tags', 'custom_tags_input');

// In functions.php
function format_status_column($value, $fieldname, $primary_key, $row, $xcrud) {
    $colors = array(
        'active' => 'green',
        'inactive' => 'gray',
        'pending' => 'orange'
    );
    $color = $colors[$value] ?? 'black';
    return '<span style="color: '.$color.'; font-weight: bold;">'.ucfirst($value).'</span>';
}

function custom_tags_input($value, $field, $primary_key, $list, $xcrud) {
    // Create custom tags input
    $tags = explode(',', $value);
    $html = '<div class="tags-input">';
    foreach($tags as $tag) {
        $html .= '<span class="tag">'.$tag.'</span>';
    }
    $html .= '<input type="text" name="'.$xcrud->fieldname_encode($field).'" 
              value="'.$value.'" class="xcrud-input" />';
    $html .= '</div>';
    return $html;
}
```

### Upload Callbacks

```php
// Before upload
$xcrud->before_upload('validate_upload');

// After upload
$xcrud->after_upload('process_upload');

// After resize (images only)
$xcrud->after_resize('process_resized_image');

// In functions.php
function validate_upload($field, $filename, $file_path, $config, $xcrud) {
    // Validation logic
}

function process_upload($field, $filename, $file_path, $config, $xcrud) {
    // Check file type
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if ($field == 'document' && !in_array($ext, ['pdf', 'doc', 'docx'])) {
        unlink($file_path);
        $xcrud->set_exception('document', 'Only PDF and Word documents allowed', 'error');
    }
}
```

### Interactive AJAX Callbacks

```php
// Create custom action
$xcrud->create_action('send_email', 'handle_send_email');

// Add button to trigger action
$xcrud->button('#', 'Send Email', 'icon-mail', 'xcrud-action', array(
    'data-task' => 'action',
    'data-action' => 'send_email',
    'data-primary' => '{id}',
    'data-email' => '{email}'
));

// In functions.php
function handle_send_email($xcrud) {
    $user_id = $xcrud->get('primary');
    $email = $xcrud->get('email');
    
    // Send email logic
    mail($email, 'Subject', 'Message body');
    
    // Return response
    $xcrud->set_exception('', 'Email sent successfully!', 'success');
}
```

### Exception Handling

```php
// In callback function
function validate_data($postdata, $xcrud) {
    $email = $postdata->get('email');
    
    // Check if email already exists
    $db = Xcrud_db::get_instance();
    $db->query("SELECT COUNT(*) as count FROM users WHERE email = ".$db->escape($email));
    $result = $db->row();
    
    if ($result['count'] > 0) {
        $xcrud->set_exception('email', 'This email is already registered', 'error');
    }
}
```

---

## Date & Time Formats

### PHP Date Formats

XCRUD uses PHP date() function formats:

```php
// Common formats
$xcrud->change_type('date_field', 'date');  // Default: Y-m-d
$xcrud->change_type('datetime_field', 'datetime');  // Default: Y-m-d H:i:s

// Custom formats in config
// xcrud_config.php
public static $php_date_format = 'd/m/Y';  // European format
public static $php_time_format = 'H:i';     // 24-hour format
```

Common PHP date format characters:
- `d` - Day of month (01-31)
- `j` - Day without leading zeros (1-31)
- `m` - Month (01-12)
- `n` - Month without leading zeros (1-12)
- `Y` - 4-digit year
- `y` - 2-digit year
- `H` - 24-hour format hour (00-23)
- `h` - 12-hour format hour (01-12)
- `i` - Minutes (00-59)
- `s` - Seconds (00-59)

### jQuery UI Date Formats

For datepickers in the interface:

```php
// In xcrud_config.php
public static $date_format = 'dd/mm/yy';  // European format
public static $time_format = 'HH:mm';     // 24-hour format
```

Common jQuery UI format codes:
- `d` - Day of month (no leading zero)
- `dd` - Day of month (two digit)
- `m` - Month (no leading zero)
- `mm` - Month (two digit)
- `yy` - 4-digit year
- `y` - 2-digit year

---

## Configuration

Key configuration options in `xcrud_config.php`:

### Database Configuration

```php
public static $dbname = 'your_database';
public static $dbuser = 'your_username';
public static $dbpass = 'your_password';
public static $dbhost = 'localhost';
public static $dbencoding = 'utf8';
```

### Theme and Language

```php
public static $theme = 'bootstrap';  // or 'default', 'minimal'
public static $language = 'en';      // Language file from languages/
public static $is_rtl = false;        // Right-to-left support
```

### Display Settings

```php
public static $limit = 25;           // Default rows per page
public static $limit_list = array(10, 25, 50, 100, 'all');
public static $column_cut = 50;      // Character limit for columns
public static $show_primary_ai_field = false;  // Show primary key in forms
public static $show_primary_ai_column = false; // Show primary key in grid
```

### Features

```php
public static $enable_printout = true;
public static $enable_search = true;
public static $enable_pagination = true;
public static $enable_csv_export = true;
public static $enable_sorting = true;
public static $remove_confirm = true;  // Confirm before delete
```

### Upload Settings

```php
public static $upload_folder_def = 'uploads';  // Default upload folder
public static $not_rename = false;             // Auto-rename uploaded files
```

### Email Configuration

```php
public static $mail_host = 'smtp.example.com';
public static $mail_port = 587;
public static $smtp_auth = true;
public static $username = 'your@email.com';
public static $password = 'your_password';
public static $smtp_secure = 'tls';  // or 'ssl'
```

### Security

```php
public static $auto_xss_filtering = true;
public static $demo_mode = false;  // Disable data modification
```

---

## Database Operations

### Using Xcrud_db Class

```php
// Get database instance
$db = Xcrud_db::get_instance();

// Execute query
$db->query("SELECT * FROM users WHERE status = 'active'");

// Get all results
$results = $db->result();
foreach($results as $row) {
    echo $row['username'];
}

// Get single row
$db->query("SELECT * FROM users WHERE id = 1");
$user = $db->row();
echo $user['email'];

// Insert data
$db->query("INSERT INTO logs (action, timestamp) VALUES ('login', NOW())");
$insert_id = $db->insert_id();

// Escape values
$safe_email = $db->escape($_POST['email']);
$db->query("SELECT * FROM users WHERE email = $safe_email");

// Escape for LIKE queries
$search_term = $db->escape_like($_POST['search']);
$db->query("SELECT * FROM products WHERE name LIKE $search_term");
```

---

## Post Data Handling

### Using Xcrud_postdata Class

Available in callback functions:

```php
function process_data($postdata, $xcrud) {
    // Get field value
    $email = $postdata->get('email');
    
    // Set field value
    $postdata->set('slug', create_slug($postdata->get('title')));
    
    // Remove field
    $postdata->del('temporary_field');
    
    // Get all data as array
    $all_data = $postdata->to_array();
    
    // Process all fields
    foreach($all_data as $field => $value) {
        // Process each field
        if (is_string($value)) {
            $postdata->set($field, trim($value));
        }
    }
}
```

---

## Advanced Features

### Multi-Instance Management

```php
// Controller
$users = Xcrud::get_instance('users_list');
$users->table('users');
$users->columns('id,username,email,status');

// Another file/view
$users = Xcrud::get_instance('users_list');  // Same instance
echo $users->render();
```

### Performance Optimization

```php
// Enable benchmarking
$xcrud->benchmark();

// Limit fields for better performance
$xcrud->columns('id,name,status');  // Only load needed columns

// Use pagination
$xcrud->limit(25);

// Disable features you don't need
$xcrud->unset_csv();
$xcrud->unset_print();
$xcrud->unset_search();
```

### Security Best Practices

```php
// Enable XSS filtering (in config)
public static $auto_xss_filtering = true;

// Validate and sanitize in callbacks
function before_insert($postdata, $xcrud) {
    // Validate email
    if (!filter_var($postdata->get('email'), FILTER_VALIDATE_EMAIL)) {
        $xcrud->set_exception('email', 'Invalid email address', 'error');
    }
    
    // Sanitize input
    $postdata->set('name', strip_tags($postdata->get('name')));
    
    // Hash sensitive data
    if ($postdata->get('password')) {
        $postdata->set('password', password_hash($postdata->get('password'), PASSWORD_DEFAULT));
    }
}

// Use prepared statements with Xcrud_db
$db = Xcrud_db::get_instance();
$email = $db->escape($_POST['email']);
$db->query("SELECT * FROM users WHERE email = $email");
```

### Custom Themes

Create custom theme in `xcrud/themes/your_theme/`:
- `xcrud.css` - Main styles
- `xcrud.js` - JavaScript functionality
- Templates for different views

```php
// Use custom theme
public static $theme = 'your_theme';
```

### Localization

Create language file in `xcrud/languages/your_lang.ini`:

```ini
add = "Add New"
edit = "Edit"
remove = "Delete"
view = "View"
save = "Save"
cancel = "Cancel"
```

```php
// Use custom language
$xcrud->language('your_lang');
```

---

## Complete Example

```php
<?php
require_once 'xcrud/xcrud.php';

// Create instance
$xcrud = Xcrud::get_instance();

// Configure basic settings
$xcrud->table('products');
$xcrud->table_name('Product Management', 'Manage all products in the system', 'icon-box');

// Column configuration
$xcrud->columns('id,name,category_id,price,stock,status,created_at');
$xcrud->column_name('category_id', 'Category');
$xcrud->column_name('created_at', 'Date Added');

// Relations
$xcrud->relation('category_id', 'categories', 'id', 'category_name');

// Field configuration
$xcrud->fields('name,category_id,description,price,stock,image,status');
$xcrud->change_type('description', 'textarea');
$xcrud->change_type('price', 'price', '', array('prefix' => '$'));
$xcrud->change_type('stock', 'int');
$xcrud->change_type('image', 'image', '', array(
    'path' => 'uploads/products',
    'thumbs' => array(
        array('width' => 150, 'marker' => '_thumb'),
        array('width' => 400, 'marker' => '_medium')
    )
));
$xcrud->change_type('status', 'select', 'active', 
    array('values' => 'active,inactive,discontinued'));

// Validation
$xcrud->validation_required('name,price,stock');
$xcrud->validation_pattern('price', 'decimal');
$xcrud->validation_pattern('stock', 'integer');

// Visual enhancements
$xcrud->highlight('stock', '<', 10, 'orange');
$xcrud->highlight('status', '=', 'discontinued', 'red');
$xcrud->column_class('price,stock', 'text-right');

// Callbacks
$xcrud->before_insert('process_product_insert');
$xcrud->after_update('update_product_cache');

// Custom button
$xcrud->button('#', 'Generate Barcode', 'icon-barcode', 'generate-barcode', 
    array('data-id' => '{id}'));

// Search configuration
$xcrud->search_columns('name,description', 'name');

// Pagination
$xcrud->limit(20);
$xcrud->limit_list('10,20,50,100,all');

// Render
echo $xcrud->render();

// Callback functions (in functions.php)
function process_product_insert($postdata, $xcrud) {
    $postdata->set('created_at', date('Y-m-d H:i:s'));
    $postdata->set('created_by', $_SESSION['user_id']);
    
    // Generate SKU
    $sku = 'PROD-' . time();
    $postdata->set('sku', $sku);
}

function update_product_cache($postdata, $primary_key, $xcrud) {
    // Clear product cache
    // cache_clear('product_' . $primary_key);
}
?>
```

---

## Tips and Best Practices

1. **Always validate user input** in callbacks
2. **Use relations** instead of manual joins when possible
3. **Implement proper error handling** with set_exception()
4. **Optimize queries** by selecting only needed columns
5. **Use field callbacks** for complex custom inputs
6. **Enable XSS filtering** for security
7. **Hash passwords** and sensitive data
8. **Use transactions** for complex database operations
9. **Cache frequently accessed data** in callbacks
10. **Create backups** before major data operations

---

## Troubleshooting

### Common Issues

1. **Primary key requirement**: XCRUD requires tables to have a primary key or unique field
2. **Upload permissions**: Ensure upload directories have write permissions
3. **Session issues**: Check session configuration if data isn't persisting
4. **Character encoding**: Ensure database and PHP use matching character sets
5. **Memory limits**: Increase PHP memory limit for large datasets

### Debug Mode

```php
// Enable benchmarking to see performance metrics
$xcrud->benchmark();

// Check generated SQL in callbacks
function debug_callback($postdata, $xcrud) {
    error_log(print_r($postdata->to_array(), true));
}
```

---

This documentation covers all major features of XCRUD. For specific use cases or advanced customization, refer to the callback system and extend functionality through custom PHP functions.
