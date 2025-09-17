<?php

require __DIR__ . '/../vendor/autoload.php';

use CodexCrud\Greeter;

$greeter = new Greeter();

 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CodexCrud Greeter Demo</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-tl2tVqaVH6R0jnJ10jzH5aXo0ESKGLYgZGgZ9I3x+uigPuQIMHaqBa2anw8yz4Jt"
        crossorigin="anonymous"
    >
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-4">
                    <h1 class="display-5">CodexCrud Greeter</h1>
                    <p class="lead">Bootstrap 5 example demonstrating the library output.</p>
                </div>
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        Greeting Samples
                    </div>
                    <div class="card-body">
                         <?php
                             echo "<p>" . $greeter->greet("Alice") . "</p>";
                           
                            ?>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-p3mIhQU+rPJRggQX0nqdNTUpORF1VI1HjMJtoZVqB6gcEAaMJKRN45Iz4Q2E8wZ+"
        crossorigin="anonymous"
    ></script>
</body>
</html>
