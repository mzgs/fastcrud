<?php
declare(strict_types=1);

namespace FastCrud;

/**
 * Defines customizable CSS classes and colors used by FastCrud components.
 *
 * Update these public static properties to change the default styling globally
 * without editing the core library files. Values fallback to sensible defaults
 * when left empty.
 */
class CrudStyle
{
    /** @var string Default classes applied to the optional link button. */
    public static string $link_button_class = 'btn btn-sm btn-outline-secondary';

    /** @var string Default cancel button classes in the edit offcanvas panel. */
    public static string $panel_cancel_button_class = 'btn btn-outline-secondary';

    /** @var string Default submit button classes in the edit offcanvas panel. */
    public static string $panel_save_button_class = 'btn btn-primary';

    /** @var string Default button classes for the top toolbar search button. */
    public static string $search_button_class = 'btn btn-outline-primary';

    /** @var string Default button classes for the top toolbar clear button. */
    public static string $search_clear_button_class = 'btn btn-outline-secondary';

    /** @var string Default button classes for the batch delete toolbar button. */
    public static string $batch_delete_button_class = 'btn btn-sm btn-danger';

    /** @var string Default button classes for the bulk apply toolbar button. */
    public static string $bulk_apply_button_class = 'btn btn-sm btn-outline-primary';

    /** @var string Default button classes for the export CSV toolbar button. */
    public static string $export_csv_button_class = 'btn btn-sm btn-outline-secondary';

    /** @var string Default button classes for the export Excel toolbar button. */
    public static string $export_excel_button_class = 'btn btn-sm btn-outline-secondary';

    /** @var string Default button classes for the add record toolbar button. */
    public static string $add_button_class = 'btn btn-sm btn-success';

    /** @var string Classes applied to all action buttons when set globally. */
    public static string $action_button_global_class = '';

    /** @var string Default classes for the duplicate action button in table rows. */
    public static string $duplicate_action_button_class = 'btn btn-sm btn-info';

    /** @var string Default classes for the view action button in table rows. */
    public static string $view_action_button_class = 'btn btn-sm btn-secondary';

    /** @var string Default classes for the edit action button in table rows. */
    public static string $edit_action_button_class = 'btn btn-sm btn-primary';

    /** @var string Default classes for the delete action button in table rows. */
    public static string $delete_action_button_class = 'btn btn-sm btn-danger';

    /** @var string Extra classes appended to the nested toggle buttons. */
    public static string $nested_toggle_button_classes = 'btn btn-link p-0';

    /** @var string Table row highlight class applied while editing or viewing. */
    public static string $edit_view_row_highlight_class = 'table-active';

    /** @var string Accent color for boolean switches rendered in the grid. */
    public static string $bools_in_grid_color = 'primary';

    /** @var string Icon class for view action buttons. */
    public static string $view_action_icon = 'fas fa-eye';

    /** @var string Icon class for edit action buttons. */
    public static string $edit_action_icon = 'fas fa-edit';

    /** @var string Icon class for delete action buttons. */
    public static string $delete_action_icon = 'fas fa-trash';

    /** @var string Icon class for duplicate action buttons. */
    public static string $duplicate_action_icon = 'far fa-copy';

    /** @var string Icon class for expand nested records. */
    public static string $expand_action_icon = 'fas fa-chevron-down';

    /** @var string Icon class for collapse nested records. */
    public static string $collapse_action_icon = 'fas fa-chevron-up';

    /** @var string Font size for action button icons. */
    public static string $action_icon_size = '1.05rem';
}
