<?php
declare(strict_types=1);

namespace FastCrud;

class CrudConfig
{
    private static array $dbConfig = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
    ];

    public static string $upload_path = 'public/uploads';
    public static ?string $upload_serve_path = null;
    // shows images in list view
    public static bool $images_in_grid = true;
    public static int $images_in_grid_height = 55;

    // show boolean fields as switches in grid cells
    public static bool $bools_in_grid = true;

    // enable select2 widgets globally unless overridden per Crud instance
    public static bool $enable_select2 = false;

    // show query builder filters controls by default
    public static bool $enable_filters = false;

    // surface detailed error information for debugging (avoid enabling in production)
    public static bool $debug = false;

    // prepend a numbered column to grid listings unless overridden per Crud instance
    public static bool $enable_numbers = false;

    // hide table title block globally
    public static bool $hide_table_title = false;

    /**
     * When set, truncate every column by default unless overridden via Crud::column_truncate().
     * Defaults to 300 characters. Accepts either an integer length (suffix defaults to '…') or an
     * array with `['length' => int, 'suffix' => string]`.
     *
     * @var array{length:int,suffix?:string}|int|null
     */
    public static array|int|null $default_column_truncate = 300;

    // For default CSS classes and colours refer to CrudStyle::$* properties.

    /**
     * Store database configuration values for later use.
     * Missing keys default to MySQL on localhost port 3306.
     *
     * Example usage:
     *     use FastCrud\CrudConfig;
     *
     *     CrudConfig::setDbConfig([
     *         'database' => 'app',
     *         'username' => 'user',
     *         'password' => 'secret',
     *     ]);
     */
    public static function setDbConfig(array $configuration): void
    {
        $defaults = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
        ];

        self::$dbConfig = array_replace($defaults, $configuration);
    }

    /**
     * Retrieve the currently stored database configuration.
     */
    public static function getDbConfig(): array
    {
        return self::$dbConfig;
    }

    public static function getUploadPath(): string
    {
        $path = trim(self::$upload_path);
        return $path === '' ? 'public/uploads' : $path;
    }

    public static function getUploadServePath(): string
    {
        $path = self::$upload_serve_path;
        if ($path === null || trim($path) === '') {
            return self::getUploadPath();
        }

        return trim($path);
    }
}
