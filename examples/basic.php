<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CodexCrud\Crud;
use CodexCrud\CrudConfig;

 

CrudConfig::setDbConfig([
    'database' => 'codexcrud',
    'username' => 'root',
    'password' => '1ss',
]);

 
    $crud = new Crud('users');
    $tableHtml = $crud->render();
 

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CodexCrud Demo</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        crossorigin="anonymous"
    >
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col">
                <div class="text-center mb-4">
                    <h1 class="display-5">CodexCrud Demo</h1>
                    <p class="lead">Dynamically rendered records for the configured table.</p>
                </div>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        Users Table Preview
                    </div>
                    <div class="card-body">
                        <?= $tableHtml ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"
    ></script>
</body>
</html>
