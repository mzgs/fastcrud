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



## Requirements

- PHP 7.4 or higher
- PDO extension
- A supported database (MySQL, PostgreSQL, SQLite, etc.)
- Bootstrap 5 for styling
- jQuery for AJAX functionality

## License

MIT
