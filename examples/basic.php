<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FastCrud\Crud;
use FastCrud\CrudConfig;
use FastCrud\CrudStyle;
use FastCrud\DatabaseEditor;

function fc_before_create_defaults(array $fields, array $context, Crud $crud): array
{
    $fields['created_at'] = $fields['created_at'] ?? date('Y-m-d H:i:s');
    $fields['status'] = $fields['status'] ?? 'draft';
    $fields['slug'] =  'new-post-' . time();

    return $fields;
}

function fc_before_edit(array $fields, array $context, Crud $crud): array
{
    

    return $fields;
}

function content_callback(?string $value, array $row, string $column, string $formatted): string
{
    return "test: " .$formatted;
}

function fc_render_user_role(?string $value, array $row, string $column, string $formatted): string
{
    $label = strtoupper($formatted !== '' ? $formatted : (string) $value);
    $variant = $label === 'ADMIN' ? 'danger' : 'secondary';

    return '<span class="badge bg-' . $variant . ' text-uppercase">' . $label . '</span>';
}

function render_status_badge(array $row): string
{
    $isFeatured = !empty($row['is_featured']);
    $label = $isFeatured ? 'Featured' : 'Standard';
    $variant = $isFeatured ? 'success' : 'secondary';

    return '<span class="badge bg-' . $variant . '">' . $label . '</span>';
}


function create_color_picker($field, $value, $row, $mode)
{
 
    $value = htmlspecialchars($value ?? '#000000');
    return <<<HTML
          <input
              type="color"
              class="form-control form-control-color"
              name="{$field}"
              data-fastcrud-field="{$field}"
              value="{$value}"
              title="Choose your color"
          >
      HTML;
}

function render_status_note_field(string $field, mixed $value, array $row, string $formType): string
{
    return  '<hr><span class="text-primary">Featured posts are highlighted and surface in key sections.</span>' ;
}


Crud::init([
    'database' => 'fastcrud',
    'username' => 'root',
    'password' => '1',
]);



CrudStyle::$bools_in_grid_color = 'success';

CrudStyle::$action_button_global_class = "btn btn-secondary";
CrudStyle::$toolbar_action_button_global_class = "btn btn-sm btn-outline-info";
CrudStyle::$batch_delete_button_class = "btn btn-sm btn-danger";
CrudStyle::$delete_action_button_class = "btn btn-danger";
CrudStyle::$add_button_class = "btn btn-sm btn-success";
// CrudStyle::$edit_action_button_class = "btn btn-success";


DatabaseEditor::init();
 

  

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FastCRUD Demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">


    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='4' fill='%230d6efd'/%3E%3Ctext x='8' y='11' fill='%23ffffff' font-family='Arial' font-size='8' text-anchor='middle'%3EFc%3C/text%3E%3C/svg%3E">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <link href="https://mzgs.github.io/fa7/css/all.css" rel="stylesheet"  >
  
    <link href="style.css" rel="stylesheet" crossorigin="anonymous" >

</head>
<body data-bs-theme="dark" >
    <div class="container py-5">
        <div class="row justify-content-center">

       

         <div class="col">
                <div class="text-center mb-4">
                    <div class="d-flex justify-content-center align-items-center gap-2">
                        <h1 class="display-5 mb-0">FastCRUD Demo</h1>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-theme-toggle aria-label="Switch to light theme">
                            <i class="fas fa-moon" aria-hidden="true"></i>
                        </button>
                    </div>
                    <p class="lead mt-2">Dynamically rendered records for the configured table.</p>
                </div>

                 

                <?= DatabaseEditor::render(true); ?>

                <br>
                <br>

                <?php
                $postsCrud = new Crud('posts');
                $postsCrud
                    ->before_create('fc_before_create_defaults')
                    ->limit_list('5,10,25,all')
                    ->where('deleted_at IS NULL')    
                    
                    // ->enable_add(true)
                    // ->enable_view(true, 'user_id', '=', '1')
                    // ->enable_edit(true, 'user_id', '=', '1')
                    // ->enable_delete(true, 'user_id', '=', '1')
                    ->enable_duplicate(true)  
                    ->enable_filters()
                    ->order_by('id', 'desc')
                    ->relation('user_id', 'users', 'id', 'username')
                    ->enable_batch_delete(true)
                    ->enable_soft_delete('deleted_at') 
                    ->add_bulk_action('publish', 'Publish Selected', [
                        'fields' => ['is_featured' => 1],
                        'confirm' => 'Flag all chosen records?',
                    ])

                    ->add_multi_link_button([
                        'icon' => 'fas fa-globe',
                        'label' => 'Actions',   
                        'button_class' => 'btn btn-sm btn-success'
                    ], [
                        ['url' => '/customers/{id}', 'label' => 'Profile', 'icon' => 'fas fa-user'],
                        ['url' => '/customers/{id}', 'label' => 'Profile', 'icon' => 'fas fa-user'],
                        [],
                        ['url' => '/customers/{id}/orders', 'label' => 'Orders', 'icon' => 'fas fa-receipt', 'options' => ['target' => '_blank']]
                    ])
                   
                    ->enable_export_csv()
                    ->enable_export_excel()
                  
                    
                    // ->join('user_id', 'users', 'id','user')
                    // ->columns('id,user_id,user.username,user.bio,title,content,created_at')
                    ->columns('user_id,title,is_featured,,cats,file,status_label,content,image,color')
                    ->fields('user_id,status,title,is_featured,json_field,image,gallery_images,file,color,content,created_at', false, 'Post Details' )
                    ->fields('slug,status_note,cats,radio_field', false, 'Post Summary' )
                    // ->fields('slug,content',false,'Content' )
                    ->change_type('file', 'files')
                    
                   
                    // ->enable_delete_confirm(false)
                    ->change_type('image', 'image')
                   
                        ->change_type('cats', 'multicheckbox', '', [
        'values' => [
            'tech'    => 'Technology',
            'design'  => 'Design',
            'culture' => 'Culture',
        ],
    ])
                    ->change_type('radio_field', 'radio', null, [
                        'values' => [
                            'draft'     => 'Draft',
                            'published' => 'Published',
                            'archived'  => 'Archived',
                        ],
                        'inline' => true, // optional bootstrap inline layout
                    ])
                    ->change_type('gallery_images', 'images')
                    ->change_type('color', 'color', '#ff0000')
                    ->change_type('content', 'rich_editor', '', ['height' => 450])
                    ->change_type('created_at', 'hidden', date('Y-m-d H:i:s'))
                  ->column_pattern("title","{title} - {slug}")
                //   ->pass_default('file','default.txt')
                
                    ->search_columns('title,content', 'title')
                    ->validation_required('slug')
                    ->change_type('json_field', 'json', '', ['rows' => 8])
                    ->inline_edit('title,color,user_id')
                    ->custom_field('status_note', 'render_status_note_field')
                    ->change_type('status_note', 'textarea', '', ['rows' => 2])
                    ->readonly('status_note')
                    
                    ->set_column_labels([
                        'user_id'    => 'Author',
                        'title'      => 'Title',
                        'content'    => 'Content',
                        'status_label' => 'Status',
                        'is_featured' => 'Ft',
                     
                    ])
                    ->set_field_labels([
                        'user_id'    => 'Select User',
                        'title'      => 'Post Title',
                        'status_note' => 'Status Notes',
                         
                       
                    ])
                    ->enable_select2()
                    ->column_pattern('slug', '<strong>{value} - {id} | {status}</strong>')
                   ->column_pattern('content', 'pattern content| {value}')
                    ->column_callback('content', 'content_callback')
                    // Add a custom, computed column that isn't stored in the database
                    ->custom_column('status_label', 'render_status_badge')
                    // ->field_callback('color', 'create_color_picker')
                    ->column_class('user_id', 'text-muted')
                    // ->column_width('title', '30%')
                    ->column_cut('content', 30)
                    ->setPanelWidth('30%')
                    
                    ->add_link_button('example.com?id={id}', 'fas fa-user', '', 'btn btn-info text-white', ['target' => '_blank', 'class' => 'me-2'] )
                  
                    // ->change_type('title', 'textarea','',['rows' => 12])
                  
                    // ->highlight('id', 'equals', 32, 'bg-info')
                    // ->highlight_row('id', 'equals', 23, 'table-info')
                    ->table_title('Posts Overview')
                    // ->highlight_row('title', 'contains', 'we', 'table-info')
                    // ->table_tooltip('FastCRUD live preview of posts')
                    ->table_icon('fas fa-newspaper');
                    // ->column_summary('id', 'count', 'Total');

                 
                ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <h2 class="h5 mb-1">Posts Table Preview</h2>
                                <p class="card-text mb-0">Inline editing (title &amp; color), custom callbacks, FilePond uploads, and column patterns are showcased here.</p>
                                <p class="card-text text-muted small mb-0">Use the chevron in the first column to expand nested comment tables for each post.</p>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        
                        <?= $postsCrud->render(); ?>
                    </div>
                </div>

            </div>

            
            <div class="card">
               

                <?php

                $usersCrud = new Crud('users');
                $usersCrud
                ->columns('username,email')
                ->table_icon('fas fa-users')
                    ->nested_table('posts', 'id', 'posts', 'user_id', static function (Crud $nested): void {
                        $nested
                            ->table_title('Posts')
                            ->columns('title,content,created_at')
                            ->setPerPage(5)
                            ->change_type('content', 'rich_editor', '', ['rows' => 4])
                            ->change_type('color', 'color', '#ff0000')
                            ->change_type('image', 'image')
                             
                            
                           
                    ;})
                    ->limit_list('5,10,25,all');

                echo $usersCrud->render();
                ?>

            </div>


          


        </div>
    </div>
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"
    ></script>
    <script>
        (function () {
            const storageKey = 'fastcrud-theme';
            const body = document.body;
            const toggleButton = document.querySelector('[data-theme-toggle]');
            if (!toggleButton) {
                return;
            }

            const icon = toggleButton.querySelector('i');
            const applyTheme = (theme) => {
                body.setAttribute('data-bs-theme', theme);
                if (icon) {
                    icon.classList.toggle('fa-sun', theme === 'light');
                    icon.classList.toggle('fa-moon', theme === 'dark');
                }
                const targetLabel = theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';
                toggleButton.setAttribute('aria-label', targetLabel);
                toggleButton.setAttribute('title', targetLabel);
                toggleButton.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            };

            const storedTheme = localStorage.getItem(storageKey);
            const initialTheme = storedTheme === 'light' ? 'light' : 'dark';
            applyTheme(initialTheme);

            toggleButton.addEventListener('click', () => {
                const currentTheme = body.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
                const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
                applyTheme(nextTheme);
                localStorage.setItem(storageKey, nextTheme);
            });
        }());
    </script>
</body>
 
  
</html>
