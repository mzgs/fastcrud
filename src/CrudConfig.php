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

    public static string $tinymce_upload_path = 'public/uploads';

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

    public static function getTinymceUploadPath(): string
    {
        $path = trim(self::$tinymce_upload_path);
        return $path === '' ? 'public/uploads' : $path;
    }
}
