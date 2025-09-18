<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FastCrud\Crud;

Crud::init([
    'database' => 'fastcrud',
    'username' => 'root',
    'password' => '1',
]);

  

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FastCRUD Demo</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        crossorigin="anonymous"
    >
    <link
        rel="icon"
        type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='4' fill='%230d6efd'/%3E%3Ctext x='8' y='11' fill='%23ffffff' font-family='Arial' font-size='8' text-anchor='middle'%3EFc%3C/text%3E%3C/svg%3E"
    >
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>

</head>
<body data-bs-theme="dark" >
    <div class="container py-5">
        <div class="row justify-content-center">

         <div class="col">
                <div class="text-center mb-4">
                    <h1 class="display-5">FastCRUD Demo</h1>
                    <p class="lead">Dynamically rendered records for the configured table.</p>
                </div>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        Posts Table Preview
                    </div>
                    <div class="card-body">
                        <?= new Crud('posts')->render(); ?>
                    </div>
                </div>

            </div>

            
            <div class="col">
                <div class="text-center mb-4">
                    <h1 class="display-5">FastCRUD Demo</h1>
                    <p class="lead">Dynamically rendered records for the configured table.</p>
                </div>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        Users Table Preview
                    </div>
                    <div class="card-body">
                        <?= new Crud('users')->render(); ?>
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
