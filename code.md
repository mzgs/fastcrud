# FastCRUD - AI Agent Brief

## Core Rules
1. **Bootstrap 5 only** - Use Bootstrap classes, no custom CSS
2. **jQuery required** - Never use vanilla JavaScript  
3. **AJAX pattern** - Follow existing AJAX request format with table ID
4. **Security** - Always escape HTML output, use PDO prepared statements
5. **Backward compatibility** - Don't break existing API
6. **PHP strict types** - Always use `declare(strict_types=1);`
7. **Namespace** - All classes in `FastCrud` namespace

## File Structure
- `src/Crud.php` - Main table rendering, add methods here
- `src/CrudAjax.php` - AJAX handlers, add new actions here
- `src/CrudConfig.php` - Config storage (rarely modified)
- `src/DB.php` - Database connection (rarely modified)

## AJAX Request Pattern
```javascript
$.ajax({
    url: window.location.pathname,
    data: {
        fastcrud_ajax: '1',
        action: 'fetch',  // or 'update', 'delete', etc.
        table: tableName,
        id: tableId,      // REQUIRED - unique table instance ID
        // other params
    },
    success: function(response) {
        // Handle response
    }
});
```

## Adding New Features
**FIRST: Analyze existing code patterns before implementing**
1. Read relevant source files to understand current implementation
2. Add action case in `CrudAjax::handle()`
3. Add method in `Crud` class  
4. Add jQuery handler in `generateAjaxScript()`
5. Use Bootstrap classes for UI elements
6. Test with examples/basic.php

## HTML Escaping Pattern
```php
// Always escape output
$safe = $this->escapeHtml($userInput);
$html = "<td>{$safe}</td>";
```

## jQuery Event Pattern
```javascript
// Use event delegation for dynamic content
$(document).on('click', '.my-button', function() {
    // Handler code
});
```

## Error Handling Pattern
```php
try {
    // Database operation
} catch (PDOException $exception) {
    throw new RuntimeException('Message', 0, $exception);
}
```

## Database Pattern  
```php
// Use existing connection or auto-connect
$crud = new Crud('table_name');  // Auto-connect via CrudConfig
// OR
$crud = new Crud('table_name', $customPDO);  // Custom PDO
```

## Table Requirements
- Alphanumeric + underscores only in table/column names
- Supports MySQL, PostgreSQL, SQLite
- Primary key recommended (usually 'id')

## Installation
```bash
composer require mzgs/fastcrud
```

```php
require 'vendor/autoload.php';
use FastCrud\Crud;

Crud::init(['database' => 'mydb', 'username' => 'user', 'password' => 'pass']);
```

## Required Dependencies
- jQuery 3.7.1+
- Bootstrap 5.3.3 CSS + JS bundle  
- PHP 7.4+ with PDO extension
- Composer autoloader