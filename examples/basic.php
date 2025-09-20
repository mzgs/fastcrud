<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FastCrud\Crud;

function content_callback(?string $value, array $row, string $column, string $formatted): string
{
    return '<strong class="text-primary">' . $formatted . $row['id'] . ' ...</strong>';
}

function fc_render_user_role(?string $value, array $row, string $column, string $formatted): string
{
    $label = strtoupper($formatted !== '' ? $formatted : (string) $value);
    $variant = $label === 'ADMIN' ? 'danger' : 'secondary';

    return '<span class="badge bg-' . $variant . ' text-uppercase">' . $label . '</span>';
}

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
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
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
                <?php
                $postsCrud = new Crud('posts');
                $postsCrud
                    ->limit_list('5,10,25,all')
                    ->order_by('id', 'desc')
                    ->relation('user_id', 'users', 'id', 'username')
                    // ->join('user_id', 'users', 'id','user')
                    // ->columns('id,user_id,user.username,user.bio,title,content,created_at')
                    ->columns('id,user_id,title,slug,content,created_at')
                    ->search_columns('title,content', 'title')
                    ->set_column_labels([
                        'user_id'    => 'Author',
                        'title'      => 'Title',
                        'content'    => 'Content',
                        'created_at' => 'Published',
                    ])
                    ->column_pattern('slug', '<strong>{value} - {status}</strong>')
                    ->column_callback('content', 'content_callback')
                    ->column_class('user_id', 'text-muted')
                    ->column_width('title', '30%')
                    ->column_cut('content', 12)
                    ->setPanelWidth('30%')
                  
                    // ->highlight('id', ['operator' => 'equals', 'value' => 32], 'bg-info')
                    ->highlight_row(['column' => 'id', 'operator' => 'equals', 'value' => 23], 'table-info')
                    ->table_name('Posts Overview')
                    // ->table_tooltip('FastCRUD live preview of posts')
                    ->table_icon('bi bi-newspaper')
                    // ->column_summary('id', 'count', 'Total');
                ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        Posts Table Preview
                    </div>
                    <div class="card-body">
                        <?= $postsCrud->render(); ?>
                    </div>
                </div>

            </div>

            
            <div class="col">
                <div class="text-center mb-4">
                    <h1 class="display-5">FastCRUD Demo</h1>
                    <p class="lead">Dynamically rendered records for the configured table.</p>
                </div>
                <?php
                $usersCrud = new Crud('users');
                $usersCrud
                    ->limit_list('5,10,25')
                    
                    ->order_by('role', 'asc')
                    ->order_by('id', 'desc')
                    ->search_columns('name,email', 'name')
                    ->set_column_labels([
                        'name'  => 'Name',
                        'email' => 'Email Address',
                        'role'  => 'Role',
                    ])
                    ->column_callback('role', 'fc_render_user_role')
                    ->column_width('email', '30%')
                    ->highlight('role', ['operator' => 'equals', 'value' => 'admin'], 'fw-semibold text-danger')
                    ->highlight_row(['column' => 'role', 'operator' => 'equals', 'value' => 'admin'], 'table-warning')
                    ->table_name('User Directory')
                    ->table_tooltip('Core application users')
                    ->table_icon('bi bi-people')
                    ->column_summary('id', 'count', 'Total Users');
                ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        Users Table Preview
                    </div>
                    <div class="card-body">
                        <?= $usersCrud->render(); ?>
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
