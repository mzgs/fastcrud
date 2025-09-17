# codexcrud

## Usage Example

```php
use CodexCrud\Crud;
use CodexCrud\CrudConfig;

require __DIR__ . '/vendor/autoload.php';

$config = new CrudConfig();
$config->setDbConfig([
    'driver' => 'mysql',
    'host' => 'localhost',
]);

$crud = new Crud();
// Additional CRUD setup will go here once implemented.
```
