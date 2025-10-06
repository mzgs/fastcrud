<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FastCrud\Crud;
use FastCrud\CrudConfig;
use FastCrud\CrudStyle;
use FastCrud\DatabseEditor;

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
    return '<strong class="text-primary">' .  $formatted . $row['id'] . ' ...</strong>';
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

DatabseEditor::init();
 

  

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


    <style>
       /* ==========================================
   Bootstrap Dark Theme — Compact Radius
   OLED-friendly, high-contrast, modern style
   ========================================== */
:root,
[data-bs-theme="dark"] {
  /* --- Core Palette --- */
  --bg: #121212;
  --bg-2: #1E1E1E;
  --surface: #242424;
  --surface-elev: #2C2C2C;

  --border: #3A3A3A;
  --border-strong: #4A4A4A;

  --text: #E0E0E0;
  --text-2: #A0A0A0;
  --text-disabled: #666666;

  --accent: #2979FF;
  --accent-2: #00B8D4;
  --success: #00C853;
  --warning: #FFB300;
  --error:   #D32F2F;
  --info:    #42A5F5;

  /* --- Bootstrap Token Mapping --- */
  --bs-body-bg: var(--bg);
  --bs-body-color: var(--text);
  --bs-secondary-color: var(--text-2);
  --bs-tertiary-color: var(--text-disabled);
  --bs-border-color: var(--border);

  --bs-link-color: var(--accent);
  --bs-link-hover-color: color-mix(in srgb, var(--accent) 85%, #ffffff 15%);

  --bs-primary: var(--accent);
  --bs-info: var(--info);
  --bs-success: var(--success);
  --bs-warning: var(--warning);
  --bs-danger: var(--error);

  --bs-card-bg: var(--surface);
  --bs-card-border-color: var(--border);
  --bs-card-color: var(--text);

  --bs-navbar-bg: color-mix(in srgb, var(--bg-2) 92%, transparent);
  --bs-navbar-color: var(--text);
  --bs-navbar-brand-color: var(--text);

  --bs-dropdown-bg: var(--surface);
  --bs-dropdown-link-color: var(--text);
  --bs-dropdown-link-hover-bg: rgba(255,255,255,.05);

  --bs-table-bg: var(--surface);
  --bs-table-border-color: var(--border-strong);
  --bs-table-color: var(--text);

  --bs-input-bg: var(--surface-elev);
  --bs-input-border-color: var(--border-strong);
  --bs-input-color: var(--text);
  --bs-input-placeholder-color: var(--text-disabled);

  --shadow: rgba(0,0,0,.4);
  --radius: 6px; /* reduced radius */
}

/* --- Background Gradient --- */
body {
  background:
    radial-gradient(1200px 600px at 80% -10%, rgba(255,255,255,0.05), transparent 60%),
    radial-gradient(800px 400px at -10% 20%, rgba(255,255,255,0.04), transparent 60%),
    linear-gradient(180deg, var(--bg-2), var(--bg));
  color: var(--text);
}

/* --- Navbar --- */
.navbar {
  backdrop-filter: saturate(1.2) blur(8px);
  border-bottom: 1px solid var(--border-strong);
}

/* --- Cards --- */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: 0 8px 16px -8px var(--shadow);
  color: var(--text);
}

/* --- Surface Sections --- */
.surface {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: 0 10px 20px -10px var(--shadow);
}

/* --- Chips --- */
.chip {
  display: inline-block;
  padding: .35rem .6rem;
  border-radius: 20px;
  background: var(--bg-2);
  border: 1px solid var(--border);
  color: var(--bs-secondary-color);
  font-size: .85rem;
}

/* --- Buttons --- */
.btn {
  border-radius: 6px;
  font-weight: 600;
  transition: all .15s ease-in-out;
}

.btn-primary {
  background: linear-gradient(180deg, color-mix(in srgb, var(--accent) 85%, #fff 0%), var(--accent));
  border-color: color-mix(in srgb, var(--accent) 65%, #000 35%);
  color: #fff;
}
.btn-primary:hover,
.btn-primary:focus {
  background: color-mix(in srgb, var(--accent) 90%, #ffffff 10%);
  color: #fff;
}

.btn-info {
  background: linear-gradient(180deg, color-mix(in srgb, var(--accent-2) 85%, #fff 0%), var(--accent-2));
  border-color: color-mix(in srgb, var(--accent-2) 65%, #000 35%);
  color: #001d21;
}
.btn-info:hover,
.btn-info:focus {
  background: color-mix(in srgb, var(--accent-2) 90%, #ffffff 10%);
  color: #001d21;
}

.btn-outline-light {
  color: var(--text);
  border-color: var(--border-strong);
  background: transparent;
}
.btn-outline-light:hover,
.btn-outline-light:focus {
  background: rgba(255,255,255,0.08);
  color: #fff;
  border-color: var(--border-strong);
}

.btn-outline-light:disabled,
.btn-primary:disabled,
.btn-info:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* --- Inputs & Forms (Compact, Better Visibility) --- */
.form-control,
.form-select,
input[type="checkbox"],
textarea {
  background: var(--surface-elev);
  border: 1px solid color-mix(in srgb, var(--border-strong) 80%, #ffffff 10%) !important;
  color: var(--text);
  border-radius: 5px;
  transition:
    border-color 0.25s ease,
    box-shadow 0.25s ease,
    background 0.25s ease;
}

.form-control::placeholder,
textarea::placeholder {
  color: var(--text-disabled);
}

.form-control:hover,
.form-select:hover,
textarea:hover {
  border-color: color-mix(in srgb, var(--accent) 35%, var(--border-strong) 65%);
}

.form-control:focus,
.form-select:focus,
textarea:focus {
  border-color: color-mix(in srgb, var(--accent) 80%, #ffffff 15%);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent);
  background: color-mix(in srgb, var(--surface-elev) 85%, var(--accent) 15%);
  outline: none;
}

.is-valid.form-control,
.is-valid.form-select {
  border-color: color-mix(in srgb, var(--success) 80%, #fff 15%);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--success) 30%, transparent);
}

.is-invalid.form-control,
.is-invalid.form-select {
  border-color: color-mix(in srgb, var(--error) 80%, #fff 15%);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--error) 30%, transparent);
}

/* --- Alerts --- */
.alert {
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--surface-elev);
  color: var(--text);
}

/* --- Table Styling --- */
.table {
  background: var(--surface)  ;
  border-collapse: separate;
  border-spacing: 0;
  border: 1px solid var(--border-strong);
  border-radius: 6px;
  overflow: hidden;
  color: var(--text);
}

.table thead th {
  background: linear-gradient(
    180deg,
    color-mix(in srgb, var(--bg-2) 85%, #ffffff 5%),
    color-mix(in srgb, var(--bg-2) 70%, #000000 30%)
  );
  color: var(--text-2);
  font-weight: 600;
  border-bottom: 1px solid var(--border-strong);
  padding: 10px 12px;
}

.table tbody td {
  
  border-bottom: 1px solid var(--border);
  color: var(--text);
}

.table thead th:first-child { border-top-left-radius: 6px; }
.table thead th:last-child  { border-top-right-radius: 6px; }
.table tbody tr:last-child td:first-child { border-bottom-left-radius: 6px; }
.table tbody tr:last-child td:last-child  { border-bottom-right-radius: 6px; }

.table-hover tbody tr:hover td {
  background: rgba(255, 255, 255, 0.06);
  transition: background 0.2s ease;
}
 

.table thead tr {
  box-shadow: inset 0 -1px 0 0 color-mix(in srgb, var(--accent) 30%, transparent);
}

.table-dark {
  background: none !important;
  --bs-table-bg: transparent;
  --bs-table-border-color: var(--border-strong);
}

/* --- Footer --- */
footer {
  border-top: 1px solid var(--border);
  color: var(--text-2);
}

/* --- Utilities --- */
.section { margin-top: 1.5rem; }
.rounded-large { border-radius: var(--radius); }
.text-secondary { color: var(--text-2) !important; }


    </style>

</head>
<body data-bs-theme="dark" >
    <div class="container py-5">
        <div class="row justify-content-center">

       

         <div class="col">
                <div class="text-center mb-4">
                    <div class="d-flex justify-content-center align-items-center gap-2">
                        <h1 class="display-5 mb-0">FastCRUD Demo</h1>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-theme-toggle aria-label="Switch to light theme">
                            <i class="bi bi-moon-stars-fill" aria-hidden="true"></i>
                        </button>
                    </div>
                    <p class="lead mt-2">Dynamically rendered records for the configured table.</p>
                </div>

              

                <?= DatabseEditor::render(true); ?>

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
                    ->order_by('id', 'desc')
                    ->relation('user_id', 'users', 'id', 'username')
                    ->enable_batch_delete(true)
                    ->enable_soft_delete('deleted_at') 
                    ->add_bulk_action('publish', 'Publish Selected', [
                        'fields' => ['is_featured' => 1],
                        'confirm' => 'Flag all chosen records?',
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
                    ->column_callback('content', 'content_callback')
                    // Add a custom, computed column that isn't stored in the database
                    ->custom_column('status_label', 'render_status_badge')
                    // ->field_callback('color', 'create_color_picker')
                    ->column_class('user_id', 'text-muted')
                    // ->column_width('title', '30%')
                    ->column_cut('content', 30)
                    ->setPanelWidth('30%')
                    ->link_button('example.com?id={id}', 'bi bi-person', '', 'btn btn-success', ['target' => '_blank', 'class' => 'me-2'] )
                    // ->change_type('title', 'textarea','',['rows' => 12])
                  
                    // ->highlight('id', 'equals', 32, 'bg-info')
                    // ->highlight_row('id', 'equals', 23, 'table-info')
                    ->table_name('Posts Overview')
                    // ->highlight_row('title', 'contains', 'we', 'table-info')
                    // ->table_tooltip('FastCRUD live preview of posts')
                    ->table_icon('bi bi-newspaper');
                    // ->column_summary('id', 'count', 'Total');

                 
                ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
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
                ->table_icon('bi bi-people')
                    ->nested_table('posts', 'id', 'posts', 'user_id', static function (Crud $nested): void {
                        $nested
                            ->table_name('Posts')
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
                    icon.classList.toggle('bi-sun-fill', theme === 'light');
                    icon.classList.toggle('bi-moon-stars-fill', theme === 'dark');
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
