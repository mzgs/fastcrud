<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use FastCrud\Crud;

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n<1201)
    INSERT INTO people SELECT n, 'Person ' || n FROM seq");
$pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, owner INTEGER, reviewers TEXT)');
$pdo->exec("INSERT INTO items VALUES (1, 'First', 1201, '1,1201'), (2, 'Second', 1, '2')");
if (isset($_GET['buffered'])) {
    ob_start();
    echo 'Discard buffered application output';
}
Crud::init($pdo);
$crud = (new Crud('items'))->relation('owner', 'people', 'id', 'name')
    ->relation('reviewers', 'people', 'id', 'name', [], false, true)
    ->inline_edit(['owner'])->enable_filters()->enable_export_csv()->enable_batch_delete();
$crud->nested_table('children', 'id', 'items', 'owner', function (Crud $child): void {
    $child->columns(['id', 'name', 'owner'])->relation('owner', 'people', 'id', 'name');
});
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Optimization regression fixture</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="p-3">
<?= $crud->render(isset($_GET['create']) ? 'create' : null) ?>
<?= (new Crud('items'))->columns(['id', 'name'])->render() ?>
</body>
</html>
