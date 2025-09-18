# FastCRUD

A fast and simple CRUD operations library for PHP with built-in pagination and AJAX support.

## Installation

```bash
composer require mzgs/fastcrud
```

## Usage Example

```php
use FastCrud\Crud;
use FastCrud\CrudConfig;

require __DIR__ . '/vendor/autoload.php';

$config = new CrudConfig();
$config->setDbConfig([
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password',
]);

$crud = new Crud($config);
$crud->setTable('users');
$crud->setColumns(['id', 'name', 'email', 'created_at']);
$crud->setPerPage(20);

// Render the CRUD table
echo $crud->render();
```

## Features

- Simple CRUD operations
- Built-in pagination
- AJAX support with CrudAjax class
- Customizable per-page records
- Clean and responsive table rendering

## Requirements

- PHP 7.4 or higher
- PDO extension

## License

MIT