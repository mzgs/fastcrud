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
    // shows images in list view
    public static bool $images_in_grid = true;
    public static int $images_in_grid_height = 55;

    // show boolean fields as switches in grid cells
    public static bool $bools_in_grid = true;

    // enable select2 widgets globally unless overridden per Crud instance
    public static bool $enable_select2 = false;

    // show query builder filters controls by default
    public static bool $enable_filters = false;

    // hide table title block globally
    public static bool $hide_table_title = false;

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
}
