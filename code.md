# FastCRUD Code Documentation

> **AI Agent Context**: This document serves as the primary reference for understanding and extending the FastCRUD library. It contains complete implementation details, patterns, and conventions that should be followed when adding new features or modifications.

## Quick Reference for AI Agents

### Key Principles
1. **Bootstrap-First**: All UI components must use Bootstrap 5 classes
2. **jQuery Required**: All JavaScript functionality uses jQuery (not vanilla JS)
3. **AJAX by Default**: Tables load data asynchronously via AJAX
4. **No Direct SQL**: Use PDO prepared statements through the existing classes
5. **Maintain BC**: Preserve backward compatibility with existing implementations

### File Modification Guidelines
- **src/Crud.php**: Core table rendering and data operations
- **src/CrudAjax.php**: AJAX endpoint handling 
- **src/CrudConfig.php**: Global configuration storage
- **src/DB.php**: Database connection management
- **Never modify**: The public API of existing methods

### Testing Requirements
- Test with MySQL, PostgreSQL, and SQLite
- Verify Bootstrap styling renders correctly
- Ensure jQuery AJAX calls work
- Check pagination with various data sizes
- Validate XSS protection on all outputs

## Library Structure

FastCRUD is organized into a clean, modular architecture with clear separation of concerns:

```
src/
├── Crud.php          # Main CRUD class - handles table rendering and data operations
├── CrudAjax.php      # AJAX request handler - processes async data fetching
├── CrudConfig.php    # Configuration manager - stores database settings
└── DB.php            # Database connection manager - handles PDO connections
```

### Core Components

#### 1. **Crud.php** - Main CRUD Operations
- **Purpose**: Core class that generates HTML tables with automatic AJAX loading
- **Key Methods**:
  - `__construct(string $table, ?PDO $connection)`: Initialize with table name
  - `render()`: Generate complete HTML table with Bootstrap styling
  - `setPerPage(int $perPage)`: Configure pagination size
  - `getTableData(int $page, ?int $perPage)`: Fetch paginated data as array
- **Features**:
  - Automatic jQuery/AJAX script generation
  - Bootstrap-styled table rendering
  - Built-in pagination controls
  - Column name auto-detection

#### 2. **CrudAjax.php** - AJAX Request Handler
- **Purpose**: Handles all AJAX requests for dynamic data loading
- **Key Methods**:
  - `autoHandle()`: Auto-detect and process AJAX requests
  - `handle()`: Manual AJAX request processing
  - `isAjaxRequest()`: Check if current request is AJAX
- **Request Format**: `?fastcrud_ajax=1&action=fetch&table=users&page=1`

#### 3. **CrudConfig.php** - Configuration Storage
- **Purpose**: Global database configuration management
- **Key Methods**:
  - `setDbConfig(array $config)`: Store database settings
  - `getDbConfig()`: Retrieve current configuration
- **Supported Drivers**: MySQL, PostgreSQL, SQLite

#### 4. **DB.php** - Database Connection
- **Purpose**: Singleton PDO connection management
- **Key Methods**:
  - `connection()`: Get shared PDO instance
  - `setConnection(PDO $connection)`: Inject custom PDO
  - `disconnect()`: Close current connection
- **Features**:
  - Automatic DSN building
  - Connection caching
  - Multiple driver support

## Basic Usage

### 1. Simple Implementation

```php
<?php
require 'vendor/autoload.php';

use FastCrud\Crud;

// Initialize with database configuration
Crud::init([
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'host' => 'localhost',  // optional, defaults to 127.0.0.1
    'port' => 3306,         // optional, defaults to 3306
]);

// In your HTML body
echo new Crud('users')->render();
```

### 2. Custom Pagination

```php
$crud = new Crud('products');
$crud->setPerPage(25);  // Show 25 items per page
echo $crud->render();
```

### 3. Multiple Tables on Same Page

```php
<!DOCTYPE html>
<html>
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-md-6">
                <h2>Users</h2>
                <?= new Crud('users')->render() ?>
            </div>
            <div class="col-md-6">
                <h2>Orders</h2>
                <?= (new Crud('orders'))->setPerPage(10)->render() ?>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

### 4. Custom PDO Connection

```php
// Use your own PDO instance
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$crud = new Crud('customers', $pdo);
echo $crud->render();
```

## AJAX Implementation Details

### How AJAX Works in FastCRUD

1. **Automatic Initialization**: When `Crud::init()` is called, it registers an AJAX handler
2. **Table Rendering**: The `render()` method generates:
   - HTML table structure with Bootstrap classes
   - jQuery script that automatically loads data via AJAX
   - Pagination controls

### AJAX Request Flow

```javascript
// Generated jQuery code structure
$(document).ready(function() {
    function loadTableData(page) {
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: {
                fastcrud_ajax: '1',
                action: 'fetch',
                table: 'users',
                page: page,
                per_page: 10
            },
            dataType: 'json',
            success: function(response) {
                // Populate table and pagination
            }
        });
    }
    
    loadTableData(1);  // Initial load
});
```

### Custom AJAX Integration

#### Live Search with AJAX

```html
<!DOCTYPE html>
<html>
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div class="container mt-5">
        <div class="row mb-3">
            <div class="col-md-6">
                <input type="text" id="search" class="form-control" placeholder="Search...">
            </div>
            <div class="col-md-3">
                <select id="per-page" class="form-select">
                    <option value="5">5 per page</option>
                    <option value="10" selected>10 per page</option>
                    <option value="25">25 per page</option>
                    <option value="50">50 per page</option>
                </select>
            </div>
            <div class="col-md-3">
                <button id="refresh" class="btn btn-primary">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
        </div>
        
        <div id="table-container">
            <!-- Table will be loaded here -->
        </div>
        
        <div id="pagination-container">
            <!-- Pagination will be loaded here -->
        </div>
    </div>

    <script>
    $(document).ready(function() {
        var currentPage = 1;
        var searchTerm = '';
        var perPage = 10;
        
        function loadData() {
            $.ajax({
                url: 'search-endpoint.php',
                type: 'GET',
                data: {
                    search: searchTerm,
                    page: currentPage,
                    per_page: perPage
                },
                beforeSend: function() {
                    $('#table-container').html('<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
                },
                success: function(response) {
                    renderTable(response);
                    renderPagination(response.pagination);
                },
                error: function() {
                    $('#table-container').html('<div class="alert alert-danger">Error loading data</div>');
                }
            });
        }
        
        function renderTable(data) {
            if (!data.rows || data.rows.length === 0) {
                $('#table-container').html('<div class="alert alert-info">No records found</div>');
                return;
            }
            
            var html = '<div class="table-responsive">';
            html += '<table class="table table-hover table-striped">';
            html += '<thead class="table-dark"><tr>';
            
            // Render headers
            data.columns.forEach(function(column) {
                html += '<th>' + column.replace(/_/g, ' ').toUpperCase() + '</th>';
            });
            html += '</tr></thead><tbody>';
            
            // Render rows
            data.rows.forEach(function(row) {
                html += '<tr>';
                data.columns.forEach(function(column) {
                    var value = row[column] || '-';
                    html += '<td>' + escapeHtml(String(value)) + '</td>';
                });
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
            $('#table-container').html(html);
        }
        
        function renderPagination(pagination) {
            if (pagination.total_pages <= 1) {
                $('#pagination-container').html('');
                return;
            }
            
            var html = '<nav aria-label="Table pagination">';
            html += '<ul class="pagination justify-content-center">';
            
            // Previous button
            html += '<li class="page-item ' + (pagination.current_page == 1 ? 'disabled' : '') + '">';
            html += '<a class="page-link" href="#" data-page="' + (pagination.current_page - 1) + '">&laquo; Previous</a>';
            html += '</li>';
            
            // Page numbers with ellipsis
            var startPage = Math.max(1, pagination.current_page - 2);
            var endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
            
            if (startPage > 1) {
                html += '<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>';
                if (startPage > 2) {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }
            
            for (var i = startPage; i <= endPage; i++) {
                html += '<li class="page-item ' + (i == pagination.current_page ? 'active' : '') + '">';
                html += '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a>';
                html += '</li>';
            }
            
            if (endPage < pagination.total_pages) {
                if (endPage < pagination.total_pages - 1) {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                html += '<li class="page-item"><a class="page-link" href="#" data-page="' + pagination.total_pages + '">' + pagination.total_pages + '</a></li>';
            }
            
            // Next button
            html += '<li class="page-item ' + (pagination.current_page == pagination.total_pages ? 'disabled' : '') + '">';
            html += '<a class="page-link" href="#" data-page="' + (pagination.current_page + 1) + '">Next &raquo;</a>';
            html += '</li>';
            
            html += '</ul></nav>';
            
            // Add info text
            html += '<p class="text-center text-muted">Page ' + pagination.current_page + ' of ' + pagination.total_pages;
            html += ' (Total: ' + pagination.total_rows + ' records)</p>';
            
            $('#pagination-container').html(html);
        }
        
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // Event handlers
        $('#search').on('input', function() {
            searchTerm = $(this).val();
            currentPage = 1;
            clearTimeout(window.searchTimeout);
            window.searchTimeout = setTimeout(loadData, 300); // Debounce
        });
        
        $('#per-page').on('change', function() {
            perPage = parseInt($(this).val());
            currentPage = 1;
            loadData();
        });
        
        $('#refresh').on('click', function() {
            loadData();
        });
        
        $(document).on('click', '.pagination a', function(e) {
            e.preventDefault();
            var page = $(this).data('page');
            if (page && page > 0) {
                currentPage = page;
                loadData();
            }
        });
        
        // Initial load
        loadData();
    });
    </script>
</body>
</html>
```

## Bootstrap Classes Used

FastCRUD leverages Bootstrap 5 classes for consistent styling:

### Table Classes
- `table` - Basic Bootstrap table styling
- `table-hover` - Highlight rows on hover
- `table-striped` - Alternating row colors (optional)
- `table-responsive` - Horizontal scroll on mobile
- `align-middle` - Vertically center cell content

### Pagination Classes
- `pagination` - Bootstrap pagination component
- `page-item` - Individual pagination item
- `page-link` - Clickable pagination link
- `active` - Current page indicator
- `disabled` - Disabled pagination button
- `justify-content-start` - Align pagination to left

### Utility Classes
- `text-center` - Center text alignment
- `text-muted` - Muted text color
- `spinner-border` - Loading spinner
- `spinner-border-sm` - Small loading spinner
- `visually-hidden` - Screen reader only text
- `alert` - Alert component
- `alert-warning`, `alert-danger`, `alert-info` - Alert variants

### Form Classes (for custom implementations)
- `form-control` - Input field styling
- `form-select` - Select dropdown styling
- `form-select-sm` - Small select dropdown
- `btn` - Button base class
- `btn-primary`, `btn-secondary` - Button variants

## jQuery Requirements

FastCRUD requires jQuery for AJAX functionality. The generated scripts use:

### jQuery Methods Used
- `$(document).ready()` - DOM ready handler
- `$.ajax()` - AJAX requests
- `.fadeIn()` / `.fadeOut()` - Smooth transitions
- `.on()` - Event delegation
- `.data()` - Data attributes access
- `.html()` - DOM manipulation
- `.append()` - Element insertion
- `.empty()` - Clear content
- `.find()` - Element selection

### Minimum jQuery Version
- **Recommended**: jQuery 3.7.1
- **Minimum**: jQuery 3.0.0

## Advanced Configuration

### Database Drivers

```php
// MySQL (default)
Crud::init([
    'driver' => 'mysql',
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'mydb',
    'username' => 'root',
    'password' => 'secret',
    'charset' => 'utf8mb4'
]);

// PostgreSQL
Crud::init([
    'driver' => 'pgsql',
    'host' => 'localhost',
    'port' => 5432,
    'database' => 'mydb',
    'username' => 'postgres',
    'password' => 'secret'
]);

// SQLite
Crud::init([
    'driver' => 'sqlite',
    'database' => '/path/to/database.db'
]);

// SQLite in-memory
Crud::init([
    'driver' => 'sqlite',
    'database' => ':memory:'
]);
```

### PDO Options

```php
Crud::init([
    'database' => 'mydb',
    'username' => 'root',
    'password' => 'secret',
    'options' => [
        PDO::ATTR_PERSISTENT => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
        PDO::ATTR_TIMEOUT => 5,
    ]
]);
```

## Security Considerations

1. **SQL Injection Protection**: Table names are validated to contain only alphanumeric characters and underscores
2. **XSS Protection**: All output is HTML-escaped using `htmlspecialchars()`
3. **CSRF**: Implement your own CSRF tokens for write operations (not included)
4. **Authentication**: Add your own authentication layer before rendering sensitive data

## Performance Tips

1. **Indexing**: Ensure database tables have appropriate indexes
2. **Pagination**: Use reasonable page sizes (5-50 records)
3. **Caching**: Consider implementing query caching for read-heavy applications
4. **Connection Pooling**: Use persistent PDO connections for high-traffic sites

## Troubleshooting

### Common Issues

1. **AJAX not loading data**
   - Ensure jQuery is loaded before the table render
   - Check browser console for JavaScript errors
   - Verify `Crud::init()` is called before any AJAX requests

2. **Empty tables**
   - Check database connection settings
   - Verify table name exists and is spelled correctly
   - Ensure database user has SELECT permissions

3. **Pagination not working**
   - Check that Bootstrap CSS is loaded
   - Verify jQuery event handlers are attached
   - Look for JavaScript errors in console

4. **Styling issues**
   - Ensure Bootstrap 5 CSS is loaded
   - Check for CSS conflicts with custom styles
   - Verify Bootstrap JavaScript bundle is included for interactive components

## Implementation Patterns for New Features

### Adding New CRUD Operations (AI Agent Guide)

#### Pattern 1: Adding Edit Functionality
```php
// In CrudAjax.php, add new action handler
case 'update':
    self::handleUpdateRecord();
    break;

// Implementation method
private static function handleUpdateRecord(): void {
    $table = $_POST['table'];
    $id = $_POST['id'];
    $data = $_POST['data'];
    
    $crud = new Crud($table);
    $crud->updateRecord($id, $data);  // New method to implement
}
```

```javascript
// jQuery AJAX for edit - always use jQuery, not fetch
$('.edit-btn').on('click', function() {
    var row = $(this).closest('tr');
    var data = {
        fastcrud_ajax: '1',
        action: 'update',
        table: tableName,
        id: row.data('id'),
        data: collectRowData(row)
    };
    
    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: data,
        success: function(response) {
            // Use Bootstrap classes for feedback
            showAlert('Record updated', 'success');
            loadTableData(currentPage);
        }
    });
});
```

#### Pattern 2: Adding Delete Functionality
```php
// In Crud.php, add method following existing patterns
public function deleteRecord(int $id): bool {
    $sql = sprintf('DELETE FROM %s WHERE id = :id', $this->table);
    $stmt = $this->connection->prepare($sql);
    return $stmt->execute(['id' => $id]);
}
```

#### Pattern 3: Adding Search/Filter
```javascript
// Always use jQuery for DOM manipulation
function addSearchFilter() {
    var searchHtml = '<div class="input-group mb-3">' +
        '<input type="text" class="form-control" id="search-' + tableId + '" placeholder="Search...">' +
        '<button class="btn btn-outline-secondary" type="button">Search</button>' +
        '</div>';
    
    $('#' + tableId + '-container').prepend(searchHtml);
    
    // Debounced search with jQuery
    var searchTimeout;
    $('#search-' + tableId).on('input', function() {
        clearTimeout(searchTimeout);
        var searchTerm = $(this).val();
        searchTimeout = setTimeout(function() {
            loadTableData(1, searchTerm);
        }, 300);
    });
}
```

### Adding Modal Forms (Bootstrap Pattern)

```javascript
// Modal HTML generation - use Bootstrap 5 classes
function generateEditModal() {
    return '<div class="modal fade" id="editModal" tabindex="-1">' +
        '<div class="modal-dialog">' +
        '<div class="modal-content">' +
        '<div class="modal-header">' +
        '<h5 class="modal-title">Edit Record</h5>' +
        '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
        '</div>' +
        '<div class="modal-body">' +
        '<form id="editForm">' +
        // Form fields here
        '</form>' +
        '</div>' +
        '<div class="modal-footer">' +
        '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
        '<button type="button" class="btn btn-primary" id="saveBtn">Save</button>' +
        '</div></div></div></div>';
}

// Show modal with Bootstrap's JavaScript
var modal = new bootstrap.Modal(document.getElementById('editModal'));
modal.show();
```

### Database Schema Detection Pattern

```php
// Pattern for getting column metadata
public function getTableSchema(): array {
    $sql = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = :table";
    
    $stmt = $this->connection->prepare($sql);
    $stmt->execute(['table' => $this->table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

## Code Standards for AI Implementation

### PHP Standards
```php
// Always use strict types
declare(strict_types=1);

// Follow existing namespace pattern
namespace FastCrud;

// Type hints and return types required
public function methodName(string $param): array {
    // Implementation
}

// Use existing error handling pattern
try {
    // Database operation
} catch (PDOException $exception) {
    throw new RuntimeException('Message', 0, $exception);
}
```

### JavaScript/jQuery Standards
```javascript
// Always wrap in jQuery ready
$(document).ready(function() {
    // Code here
});

// Use jQuery for AJAX, not fetch API
$.ajax({
    url: 'endpoint',
    type: 'POST',
    dataType: 'json',
    success: function(response) {},
    error: function(xhr, status, error) {}
});

// Event delegation for dynamic content
$(document).on('click', '.dynamic-element', function() {
    // Handler
});

// Always escape HTML to prevent XSS
function escapeHtml(text) {
    var map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}
```

### HTML Generation Standards
```php
// Use heredoc for multi-line HTML
return <<<HTML
<div class="container">
    <div class="row">
        <div class="col-12">
            {$this->escapeHtml($content)}
        </div>
    </div>
</div>
HTML;

// Always escape output
$safe = $this->escapeHtml($userInput);
```

## Feature Implementation Checklist

When implementing new features, ensure:

- [ ] Uses Bootstrap 5 classes exclusively for styling
- [ ] jQuery is used for all JavaScript (no vanilla JS)
- [ ] AJAX requests follow the existing pattern
- [ ] PDO prepared statements for all queries
- [ ] HTML output is escaped with `htmlspecialchars()`
- [ ] Backward compatibility maintained
- [ ] Error handling follows existing patterns
- [ ] Code follows PSR-12 standards
- [ ] Feature works with MySQL, PostgreSQL, and SQLite
- [ ] Pagination still functions correctly
- [ ] No breaking changes to public API
- [ ] Loading states use Bootstrap spinners
- [ ] Form inputs use Bootstrap form classes
- [ ] Alerts/messages use Bootstrap alert component

## Common Extension Points

### 1. Adding Custom Actions to Tables
- Extend `CrudAjax::handle()` with new cases
- Add corresponding methods in `Crud` class
- Generate appropriate jQuery handlers in `generateAjaxScript()`

### 2. Custom Column Rendering
- Override `formatValue()` method for special data types
- Add data type detection in `getColumnNames()`
- Implement custom formatters for dates, currency, etc.

### 3. Row Actions (Edit/Delete buttons)
- Modify `buildBody()` to add action column
- Add Bootstrap button groups with appropriate classes
- Implement jQuery click handlers with event delegation

### 4. Export Functionality
- Add export action in `CrudAjax`
- Generate CSV/JSON/Excel using existing data methods
- Use Bootstrap dropdown for format selection

### 5. Bulk Operations
- Add checkboxes using Bootstrap form-check classes
- Implement select all with jQuery
- Handle bulk actions via AJAX POST

## Dependencies and Version Requirements

### Required Dependencies
- PHP >= 7.4 (8.0+ recommended)
- PDO PHP extension
- Bootstrap 5.3.3 CSS (CDN or local)
- jQuery 3.7.1 (minimum 3.0.0)
- Bootstrap 5.3.3 JavaScript bundle (for modals, dropdowns)

### Optional Enhancements
- Bootstrap Icons for UI elements
- jQuery UI for advanced interactions
- DataTables for enhanced table features (requires adaptation)

## Notes for AI Agents

1. **Always preserve existing functionality** - FastCRUD is used in production
2. **Follow the established patterns** - Consistency is crucial
3. **Use Bootstrap classes** - Don't add custom CSS
4. **jQuery is mandatory** - Don't use vanilla JavaScript
5. **Test with provided example** - Use examples/basic.php as reference
6. **Security first** - Always escape output, use prepared statements
7. **Performance matters** - Consider pagination for large datasets
8. **Document changes** - Update this file with new patterns