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
    public static int|string|null $upload_max_image_size = 16 * 1024 * 1024;
    public static int|string|null $upload_max_file_size = 100 * 1024 * 1024;
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

    public static function getUploadMaxImageSize(): int
    {
        return self::normalizeUploadSize(self::$upload_max_image_size, 8 * 1024 * 1024);
    }

    public static function getUploadMaxFileSize(): int
    {
        return self::normalizeUploadSize(self::$upload_max_file_size, 20 * 1024 * 1024);
    }

    public static function parseUploadSize(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value)) {
            return $value > 0 ? (int) round($value) : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            $number = (float) $trimmed;
            return $number > 0 ? (int) round($number) : null;
        }

        if (!preg_match('/^(\\d+(?:\\.\\d+)?)\\s*(b|kb|k|mb|m|gb|g)$/i', $trimmed, $matches)) {
            return null;
        }

        $number = (float) $matches[1];
        if ($number <= 0) {
            return null;
        }

        $unit = strtolower($matches[2]);
        $multiplier = match ($unit) {
            'kb', 'k' => 1024,
            'mb', 'm' => 1024 * 1024,
            'gb', 'g' => 1024 * 1024 * 1024,
            default => 1,
        };

        $bytes = (int) round($number * $multiplier);

        return $bytes > 0 ? $bytes : null;
    }

    private static function normalizeUploadSize(int|string|null $value, int $fallback): int
    {
        $parsed = self::parseUploadSize($value);

        return $parsed !== null ? $parsed : $fallback;
    }
}
