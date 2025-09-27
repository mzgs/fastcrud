<?php
declare(strict_types=1);

namespace FastCrud;

use Exception;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class CrudAjax
{
    /**
     * Handle incoming AJAX requests for CRUD operations.
     */
    public static function handle(): void
    {
        try {
            $request = self::getRequestData();
            $action = $request['action'] ?? 'fetch';
            
            switch ($action) {
                case 'fetch':
                    self::handleFetchTable($request);
                    break;
                case 'read':
                    self::handleRead($request);
                    break;
                case 'create':
                    self::handleCreate($request);
                    break;
                case 'update':
                    self::handleUpdate($request);
                    break;
                case 'delete':
                    self::handleDelete($request);
                    break;
                case 'batch_delete':
                    self::handleBatchDelete($request);
                    break;
                case 'duplicate':
                    self::handleDuplicate($request);
                    break;
                case 'upload_image':
                    self::handleUploadImage($request);
                    break;
                case 'upload_filepond':
                    // Reuse the same secure image upload flow used by TinyMCE
                    self::handleUploadImage($request);
                    break;
                default:
                    throw new InvalidArgumentException('Invalid action: ' . $action);
            }
        } catch (Exception $e) {
            self::respond([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        exit;
    }
    
    /**
     * Handle fetching table data.
     */
    private static function handleFetchTable(array $request): void
    {
        if (!isset($request['table'])) {
            throw new InvalidArgumentException('Table parameter is required');
        }
        
        $table = $request['table'];
        $page = isset($request['page']) ? (int) $request['page'] : 1;

        $perPage = null;
        if (isset($request['per_page'])) {
            $perPageRaw = $request['per_page'];
            if (is_string($perPageRaw) && strtolower($perPageRaw) === 'all') {
                $perPage = 0;
            } elseif (is_numeric($perPageRaw)) {
                $perPage = (int) $perPageRaw;
            }
        }

        $searchTerm = isset($request['search_term']) ? (string) $request['search_term'] : null;
        // Normalize empty string to null for "All" selection
        $searchColumnRaw = $request['search_column'] ?? null;
        $searchColumn = is_string($searchColumnRaw) ? trim($searchColumnRaw) : null;
        if ($searchColumn === '') {
            $searchColumn = null;
        }

        $crud = Crud::fromAjax(
            $table,
            isset($request['id']) && is_string($request['id']) ? $request['id'] : null,
            $request['config'] ?? null
        );
        $data = $crud->getTableData($page, $perPage, $searchTerm, $searchColumn);

        self::respond([
            'success' => true,
            'data' => $data['rows'],
            'columns' => $data['columns'],
            'pagination' => $data['pagination'],
            'meta' => $data['meta'] ?? [],
            'id' => $request['id'] ?? null,
        ]);
    }

    /**
     * Handle fetching a single record by primary key.
     *
     * @param array<string, mixed> $request
     */
    private static function handleRead(array $request): void
    {
        if (!isset($request['table'])) {
            throw new InvalidArgumentException('Table parameter is required');
        }

        if (!isset($request['primary_key_column']) || !is_string($request['primary_key_column'])) {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        if (!array_key_exists('primary_key_value', $request)) {
            throw new InvalidArgumentException('Primary key value is required.');
        }

        $crud = Crud::fromAjax(
            (string) $request['table'],
            isset($request['id']) && is_string($request['id']) ? $request['id'] : null,
            $request['config'] ?? null
        );

        $row = $crud->getRecord((string) $request['primary_key_column'], $request['primary_key_value']);

        self::respond([
            'success' => $row !== null,
            'row' => $row,
            'columns' => $row !== null ? array_keys($row) : [],
            'id' => $request['id'] ?? null,
        ], $row !== null ? 200 : 404);
    }

    /**
     * Handle record creation via AJAX.
     *
     * @param array<string, mixed> $request
     */
    private static function handleCreate(array $request): void
    {
        if (!isset($request['table'])) {
            throw new InvalidArgumentException('Table parameter is required');
        }

        $fieldsPayload = $request['fields'] ?? [];
        $fields = [];

        if (is_string($fieldsPayload) && $fieldsPayload !== '') {
            $decoded = json_decode($fieldsPayload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new InvalidArgumentException('Invalid fields payload.');
            }
            $fields = $decoded;
        } elseif (is_array($fieldsPayload)) {
            $fields = $fieldsPayload;
        }

        if (!is_array($fields)) {
            throw new InvalidArgumentException('Invalid fields payload.');
        }

        $crud = Crud::fromAjax(
            (string) $request['table'],
            isset($request['id']) && is_string($request['id']) ? $request['id'] : null,
            $request['config'] ?? null
        );

        try {
            $newRow = $crud->createRecord($fields);
        } catch (ValidationException $exception) {
            self::respond([
                'success' => false,
                'error' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
                'id' => $request['id'] ?? null,
            ], 422);
        }

        self::respond([
            'success' => true,
            'row' => $newRow,
            'columns' => $newRow !== null ? array_keys($newRow) : [],
            'id' => $request['id'] ?? null,
        ]);
    }

    /**
     * Handle record updates via AJAX.
     *
     * @param array<string, mixed> $request
     */
    private static function handleUpdate(array $request): void
    {
        if (!isset($request['table'])) {
            throw new InvalidArgumentException('Table parameter is required');
        }

        if (!isset($request['primary_key_column']) || !is_string($request['primary_key_column'])) {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        if (!array_key_exists('primary_key_value', $request)) {
            throw new InvalidArgumentException('Primary key value is required.');
        }

        $fieldsPayload = $request['fields'] ?? [];
        $fields = [];

        if (is_string($fieldsPayload) && $fieldsPayload !== '') {
            $decoded = json_decode($fieldsPayload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new InvalidArgumentException('Invalid fields payload.');
            }
            $fields = $decoded;
        } elseif (is_array($fieldsPayload)) {
            $fields = $fieldsPayload;
        }

        if (!is_array($fields)) {
            throw new InvalidArgumentException('Invalid fields payload.');
        }

        $crud = Crud::fromAjax(
            (string) $request['table'],
            isset($request['id']) && is_string($request['id']) ? $request['id'] : null,
            $request['config'] ?? null
        );
        try {
            $updatedRow = $crud->updateRecord(
                (string) $request['primary_key_column'],
                $request['primary_key_value'],
                $fields,
                'edit'
            );
        } catch (ValidationException $exception) {
            self::respond([
                'success' => false,
                'error' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
                'id' => $request['id'] ?? null,
            ], 422);
        }

        self::respond([
            'success' => true,
            'row' => $updatedRow,
            'columns' => $updatedRow !== null ? array_keys($updatedRow) : [],
            'id' => $request['id'] ?? null,
        ]);
    }

    /**
     * Handle record deletions via AJAX.
     *
     * @param array<string, mixed> $request
     */
    private static function handleDelete(array $request): void
    {
        if (!isset($request['table'])) {
            throw new InvalidArgumentException('Table parameter is required');
        }

        if (!isset($request['primary_key_column']) || !is_string($request['primary_key_column'])) {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        if (!array_key_exists('primary_key_value', $request)) {
            throw new InvalidArgumentException('Primary key value is required.');
        }

        $crud     = Crud::fromAjax(
            (string) $request['table'],
            isset($request['id']) && is_string($request['id']) ? $request['id'] : null,
            $request['config'] ?? null
        );
        $deleted  = $crud->deleteRecord((string) $request['primary_key_column'], $request['primary_key_value']);
        $response = [
            'success' => $deleted,
            'deleted' => $deleted,
            'id'      => $request['id'] ?? null,
        ];

        if (!$deleted) {
            $response['error'] = 'Record not found or already deleted.';
        }

        self::respond($response, $deleted ? 200 : 404);
    }

    /**
     * Handle batch record deletions via AJAX.
     *
     * @param array<string, mixed> $request
     */
    private static function handleBatchDelete(array $request): void
    {
        if (!isset($request['table'])) {
            throw new InvalidArgumentException('Table parameter is required');
        }

        if (!isset($request['primary_key_column']) || !is_string($request['primary_key_column'])) {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        if (!array_key_exists('primary_key_values', $request)) {
            throw new InvalidArgumentException('Primary key values are required.');
        }

        $rawValues = $request['primary_key_values'];
        if (is_string($rawValues)) {
            $rawValues = [$rawValues];
        }

        if (!is_array($rawValues)) {
            throw new InvalidArgumentException('Primary key values must be provided as an array.');
        }

        $values = array_values($rawValues);
        if ($values === []) {
            throw new InvalidArgumentException('At least one primary key value is required.');
        }

        $crud = Crud::fromAjax(
            (string) $request['table'],
            isset($request['id']) && is_string($request['id']) ? $request['id'] : null,
            $request['config'] ?? null
        );

        $result = $crud->deleteRecords((string) $request['primary_key_column'], $values);
        $deletedCount = $result['deleted'];
        $failures = $result['failures'];

        $success = $deletedCount > 0;

        $response = [
            'success' => $success,
            'deleted' => $deletedCount,
            'failures' => $failures,
            'id' => $request['id'] ?? null,
        ];

        if (!$success) {
            $response['error'] = 'No records were deleted.';
        } elseif ($failures !== []) {
            $response['warning'] = 'Some records could not be deleted.';
        }

        self::respond($response, $success ? 200 : 404);
    }

    /**
     * Handle record duplication via AJAX.
     *
     * @param array<string, mixed> $request
     */
    private static function handleDuplicate(array $request): void
    {
        if (!isset($request['table'])) {
            throw new InvalidArgumentException('Table parameter is required');
        }

        if (!isset($request['primary_key_column']) || !is_string($request['primary_key_column'])) {
            throw new InvalidArgumentException('Primary key column is required.');
        }

        if (!array_key_exists('primary_key_value', $request)) {
            throw new InvalidArgumentException('Primary key value is required.');
        }

        $crud = Crud::fromAjax(
            (string) $request['table'],
            isset($request['id']) && is_string($request['id']) ? $request['id'] : null,
            $request['config'] ?? null
        );

        try {
            $newRow = $crud->duplicateRecord(
                (string) $request['primary_key_column'],
                $request['primary_key_value']
            );

            if ($newRow === null) {
                self::respond([
                    'success' => false,
                    'error' => 'Failed to duplicate record.',
                    'id' => $request['id'] ?? null,
                ], 200);
                return;
            }

            self::respond([
                'success' => true,
                'row' => $newRow,
                'columns' => array_keys($newRow),
                'id' => $request['id'] ?? null,
            ], 201);
        } catch (\Throwable $e) {
            self::respond([
                'success' => false,
                'error' => $e->getMessage(),
                'id' => $request['id'] ?? null,
            ], 200);
        }
    }

    /**
     * Handle FilePond/TinyMCE uploads (images and generic files).
     */
    private static function handleUploadImage(array $request): void
    {
        if (!isset($_FILES['file'])) {
            throw new InvalidArgumentException('No file provided.');
        }

        $file = $_FILES['file'];
        if (!is_array($file) || !array_key_exists('error', $file)) {
            throw new InvalidArgumentException('Invalid upload payload.');
        }

        $error = is_array($file['error']) ? ($file['error'][0] ?? UPLOAD_ERR_NO_FILE) : (int) $file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(self::describeUploadError($error));
        }

        $tmpName = is_array($file['tmp_name']) ? ($file['tmp_name'][0] ?? '') : ($file['tmp_name'] ?? '');
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Invalid upload stream.');
        }

        $size = is_array($file['size']) ? (int) ($file['size'][0] ?? 0) : (int) ($file['size'] ?? 0);
        $kind = isset($request['kind']) && is_string($request['kind']) ? strtolower($request['kind']) : 'image';
        $isImage = $kind !== 'file';
        $maxSize = $isImage ? (8 * 1024 * 1024) : (20 * 1024 * 1024);
        if ($size > $maxSize) {
            throw new InvalidArgumentException(($isImage ? 'Image' : 'File') . ' exceeds the maximum allowed size of ' . ($isImage ? '8MB' : '20MB') . '.');
        }

        $originalNameRaw = is_array($file['name']) ? (string) ($file['name'][0] ?? 'upload') : (string) ($file['name'] ?? 'upload');
        $originalName = $originalNameRaw === '' ? 'upload' : $originalNameRaw;
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($isImage) {
            $allowedExtensions = ['jpg', 'jpeg', 'jpe', 'jfi', 'jfif', 'png', 'gif', 'bmp', 'webp', 'svg'];
            if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
                throw new InvalidArgumentException('Unsupported image extension. Allowed: ' . implode(', ', $allowedExtensions) . '.');
            }

            $mimeType = self::detectMimeType($tmpName);
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp', 'image/svg+xml'];
            if ($mimeType !== null && !in_array($mimeType, $allowedMimeTypes, true)) {
                throw new InvalidArgumentException('Unsupported image type: ' . $mimeType);
            }
        } else {
            // Generic file: block dangerous executable/script extensions by default
            $blockedExtensions = ['php', 'phtml', 'phar', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh', 'bash', 'zsh', 'py', 'rb', 'exe', 'dll', 'so', 'js', 'mjs'];
            if ($extension === '' || in_array($extension, $blockedExtensions, true)) {
                throw new InvalidArgumentException('This file type is not allowed for upload.');
            }
        }

        $table = isset($request['table']) && is_string($request['table']) ? trim($request['table']) : null;
        $column = isset($request['column']) && is_string($request['column']) ? trim($request['column']) : null;
        $changeTypeParams = [];

        if ($table !== null && $table !== '') {
            try {
                $crud = Crud::fromAjax(
                    $table,
                    isset($request['id']) && is_string($request['id']) ? (string) $request['id'] : null,
                    $request['config'] ?? null
                );

                if ($column !== null && $column !== '') {
                    $definition = $crud->getChangeTypeDefinition($column);
                    if (is_array($definition)) {
                        $params = $definition['params'] ?? [];
                        if (is_array($params)) {
                            $changeTypeParams = $params;
                        }
                    }
                }
            } catch (\Throwable) {
                $changeTypeParams = [];
            }
        }

        $pathOverride = null;
        if (isset($changeTypeParams['path'])) {
            $pathOverride = is_scalar($changeTypeParams['path']) ? (string) $changeTypeParams['path'] : null;
        }

        $destination = self::resolveUploadDestination($pathOverride);
        $uploadDirectory = $destination['directory'];
        $relativePrefix = $destination['relative'];
        $publicBase = $destination['public_base'];

        if (!is_dir($uploadDirectory)) {
            if (!mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
                throw new RuntimeException('Failed to create upload directory.');
            }
        }

        $filename = self::generateUploadFilename($originalName, $extension);
        $targetPath = rtrim($uploadDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Failed to store uploaded file.');
        }

        @chmod($targetPath, 0664);

        if ($isImage) {
            self::processImageTransforms($targetPath, $filename, $extension, $changeTypeParams);
        }

        $storedName = $filename;
        if ($relativePrefix !== '') {
            $storedName = $relativePrefix . '/' . $storedName;
        }

        $location = self::buildPublicUploadLocation($publicBase, $storedName);

        self::respond([
            'success' => true,
            'location' => $location,
            'name' => $storedName,
            'size' => $size,
        ], 201);
    }
    
    /**
     * Check if current request is an AJAX request for CrudAjax.
     */
    public static function isAjaxRequest(): bool
    {
        $flag = $_GET['fastcrud_ajax'] ?? $_POST['fastcrud_ajax'] ?? null;

        return $flag === '1';
    }
    
    /**
     * Auto-handle AJAX requests if detected.
     * Call this early in your application bootstrap.
     */
    public static function autoHandle(): void
    {
        if (self::isAjaxRequest()) {
            self::handle();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function getRequestData(): array
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST') {
            return $_POST;
        }

        return $_GET;
    }

    private static function detectMimeType(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path) ?: null;
        finfo_close($finfo);

        return $mime === false ? null : $mime;
    }

    /**
     * @return array{directory: string, public_base: string, relative: string}
     */
    private static function resolveUploadDestination(?string $pathOption): array
    {
        $basePublic = CrudConfig::getUploadPath();
        $relative = '';

        if ($pathOption !== null) {
            $candidate = trim($pathOption);
            if ($candidate !== '') {
                if (self::isUrl($candidate)) {
                    $parsedPath = parse_url($candidate, PHP_URL_PATH) ?: '';
                    $candidate = $parsedPath !== '' ? $parsedPath : $candidate;
                }

                try {
                    $relative = self::normalizeUploadSubPath($candidate);
                } catch (InvalidArgumentException) {
                    $relative = '';
                }
            }
        }

        $baseDirectory = self::resolveUploadDirectoryFromBase($basePublic);
        $targetDirectory = $baseDirectory;

        if ($relative !== '') {
            $targetDirectory .= DIRECTORY_SEPARATOR . strtr($relative, ['/' => DIRECTORY_SEPARATOR]);
        }

        return [
            'directory' => $targetDirectory,
            'public_base' => $basePublic,
            'relative' => $relative,
        ];
    }

    private static function resolveUploadDirectoryFromBase(string $configuredPath): string
    {
        $path = $configuredPath;

        if (self::isUrl($path)) {
            $parsedPath = parse_url($path, PHP_URL_PATH) ?: '';
            $path = $parsedPath !== '' ? $parsedPath : 'public/uploads';
        }

        if (self::isAbsolutePath($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        $root = dirname(__DIR__);
        $relative = trim($path, '\/');
        if ($relative === '') {
            $relative = 'public/uploads';
        }

        $normalizedRelative = strtr($relative, ['/' => DIRECTORY_SEPARATOR, chr(92) => DIRECTORY_SEPARATOR]);

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedRelative;
    }

    private static function normalizeUploadSubPath(string $path): string
    {
        $normalized = strtr($path, [chr(92) => '/', '\\' => '/', '../' => '../']);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $normalized = trim($normalized, '/');
        if ($normalized === '') {
            return '';
        }

        if (str_contains($normalized, '..')) {
            throw new InvalidArgumentException('Relative upload path cannot contain parent traversal segments.');
        }

        $segments = array_values(array_filter(
            explode('/', $normalized),
            static fn(string $segment): bool => $segment !== '' && $segment !== '.'
        ));
        if ($segments === []) {
            return '';
        }

        if ($segments[0] === 'public') {
            array_shift($segments);
        }

        $baseSegments = array_values(array_filter(
            explode('/', trim(strtr(CrudConfig::getUploadPath(), [chr(92) => '/']), '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        if ($segments !== [] && $baseSegments !== []) {
            $lastBase = $baseSegments[count($baseSegments) - 1];
            if ($segments[0] === $lastBase) {
                array_shift($segments);
            }
        }

        return implode('/', $segments);
    }

    private static function sanitizePathSegment(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? '';
        $sanitized = trim($sanitized, '-_');
        if ($sanitized === '') {
            return '';
        }

        if (str_contains($sanitized, '..')) {
            return '';
        }

        return $sanitized;
    }

    private static function processImageTransforms(string $absolutePath, string $filename, string $extension, array $options): void
    {
        $normalizedExtension = strtolower($extension);

        if (!self::supportsImageManipulation($normalizedExtension)) {
            return;
        }

        if (!extension_loaded('gd')) {
            return;
        }

        $width = isset($options['width']) ? (int) $options['width'] : 0;
        $height = isset($options['height']) ? (int) $options['height'] : 0;
        $crop = !empty($options['crop']);

        if ($width > 0 || $height > 0) {
            self::transformImage($absolutePath, $absolutePath, $normalizedExtension, $width, $height, $crop);
        }

        if (isset($options['thumbs']) && is_array($options['thumbs'])) {
            self::generateThumbnails($absolutePath, $filename, $normalizedExtension, $options['thumbs']);
        }
    }

    /**
     * @param array<int, mixed> $thumbConfigs
     */
    private static function generateThumbnails(string $absolutePath, string $filename, string $extension, array $thumbConfigs): void
    {
        $baseDirectory = dirname($absolutePath);

        foreach ($thumbConfigs as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $thumbWidth = isset($entry['width']) ? (int) $entry['width'] : 0;
            $thumbHeight = isset($entry['height']) ? (int) $entry['height'] : 0;

            if ($thumbWidth <= 0 && $thumbHeight <= 0) {
                continue;
            }

            $thumbCrop = !empty($entry['crop']);
            $marker = isset($entry['marker']) ? trim((string) $entry['marker']) : '';
            if ($marker !== '') {
                $marker = preg_replace('/[^A-Za-z0-9._-]+/', '-', $marker) ?? '';
            }
            $folder = isset($entry['folder']) ? self::sanitizePathSegment((string) $entry['folder']) : '';

            $thumbDirectory = $baseDirectory . DIRECTORY_SEPARATOR . 'thumbs';
            if ($folder !== '') {
                $thumbDirectory .= DIRECTORY_SEPARATOR . $folder;
            }

            if (!is_dir($thumbDirectory) && !mkdir($thumbDirectory, 0775, true) && !is_dir($thumbDirectory)) {
                continue;
            }

            $thumbFilename = self::buildThumbnailFilename($filename, $marker);
            $thumbPath = $thumbDirectory . DIRECTORY_SEPARATOR . $thumbFilename;

            self::transformImage($absolutePath, $thumbPath, $extension, $thumbWidth, $thumbHeight, $thumbCrop, true);
        }
    }

    private static function buildThumbnailFilename(string $filename, string $marker): string
    {
        if ($marker === '') {
            return $filename;
        }

        $dot = strrpos($filename, '.');
        if ($dot === false) {
            return $filename . $marker;
        }

        $name = substr($filename, 0, $dot) ?: $filename;
        $extension = substr($filename, $dot);

        return $name . $marker . $extension;
    }

    private static function supportsImageManipulation(string $extension): bool
    {
        return in_array($extension, ['jpg', 'jpeg', 'jpe', 'jfi', 'jfif', 'png', 'gif', 'bmp', 'webp'], true);
    }

    private static function transformImage(
        string $sourcePath,
        string $destinationPath,
        string $extension,
        int $targetWidth,
        int $targetHeight,
        bool $crop,
        bool $allowReusingSource = false
    ): void {
        if ($targetWidth <= 0 && $targetHeight <= 0) {
            return;
        }

        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            return;
        }

        [$originalWidth, $originalHeight] = $imageInfo;
        if ($originalWidth <= 0 || $originalHeight <= 0) {
            return;
        }

        $source = self::createImageResource($sourcePath, $extension);
        if ($source === false) {
            return;
        }

        $preserveAspect = !(!$crop && $targetWidth > 0 && $targetHeight > 0);
        $dimensions = $crop && $targetWidth > 0 && $targetHeight > 0
            ? self::calculateCropDimensions($originalWidth, $originalHeight, $targetWidth, $targetHeight)
            : self::calculateFitDimensions($originalWidth, $originalHeight, $targetWidth, $targetHeight, $preserveAspect);

        if ($dimensions === null) {
            imagedestroy($source);
            return;
        }

        if (!$crop && !$allowReusingSource && $dimensions['dest_width'] === $originalWidth && $dimensions['dest_height'] === $originalHeight) {
            imagedestroy($source);
            return;
        }

        $destination = imagecreatetruecolor($dimensions['dest_width'], $dimensions['dest_height']);
        if ($destination === false) {
            imagedestroy($source);
            return;
        }

        self::prepareDestinationCanvas($destination, $extension);

        $resampled = imagecopyresampled(
            $destination,
            $source,
            0,
            0,
            $dimensions['src_x'],
            $dimensions['src_y'],
            $dimensions['dest_width'],
            $dimensions['dest_height'],
            $dimensions['src_width'],
            $dimensions['src_height']
        );

        if ($resampled) {
            self::saveImageResource($destination, $destinationPath, $extension);
            @chmod($destinationPath, 0664);
        }

        imagedestroy($destination);
        imagedestroy($source);
    }

    /**
     * @return array{dest_width:int,dest_height:int,src_x:int,src_y:int,src_width:int,src_height:int}|null
     */
    private static function calculateFitDimensions(int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight, bool $preserveAspect): ?array
    {
        if (!$preserveAspect && $targetWidth > 0 && $targetHeight > 0) {
            $destWidth = max(1, $targetWidth);
            $destHeight = max(1, $targetHeight);

            return [
                'dest_width' => $destWidth,
                'dest_height' => $destHeight,
                'src_x' => 0,
                'src_y' => 0,
                'src_width' => $originalWidth,
                'src_height' => $originalHeight,
            ];
        }

        $scale = 1.0;

        if ($targetWidth > 0 && $targetHeight > 0) {
            $scale = min($targetWidth / $originalWidth, $targetHeight / $originalHeight);
        } elseif ($targetWidth > 0) {
            $scale = $targetWidth / $originalWidth;
        } elseif ($targetHeight > 0) {
            $scale = $targetHeight / $originalHeight;
        }

        if ($scale <= 0) {
            return null;
        }

        $destWidth = max(1, (int) round($originalWidth * $scale));
        $destHeight = max(1, (int) round($originalHeight * $scale));

        return [
            'dest_width' => $destWidth,
            'dest_height' => $destHeight,
            'src_x' => 0,
            'src_y' => 0,
            'src_width' => $originalWidth,
            'src_height' => $originalHeight,
        ];
    }

    /**
     * @return array{dest_width:int,dest_height:int,src_x:int,src_y:int,src_width:int,src_height:int}
     */
    private static function calculateCropDimensions(int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight): array
    {
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $originalWidth / $originalHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $originalHeight;
            $cropWidth = (int) round($targetRatio * $cropHeight);
            $srcX = (int) floor(($originalWidth - $cropWidth) / 2);
            $srcY = 0;
        } else {
            $cropWidth = $originalWidth;
            $cropHeight = (int) round($cropWidth / $targetRatio);
            $srcX = 0;
            $srcY = (int) floor(($originalHeight - $cropHeight) / 2);
        }

        return [
            'dest_width' => max(1, $targetWidth),
            'dest_height' => max(1, $targetHeight),
            'src_x' => max(0, $srcX),
            'src_y' => max(0, $srcY),
            'src_width' => max(1, $cropWidth),
            'src_height' => max(1, $cropHeight),
        ];
    }

    /**
     * @return resource|false
     */
    private static function createImageResource(string $path, string $extension)
    {
        return match ($extension) {
            'jpg', 'jpeg', 'jpe', 'jfi', 'jfif' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
            'bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($path) : false,
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private static function prepareDestinationCanvas($canvas, string $extension): void
    {
        $extension = strtolower($extension);

        if (in_array($extension, ['png', 'gif', 'webp'], true)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, imagesx($canvas), imagesy($canvas), $transparent);
        }
    }

    private static function saveImageResource($image, string $path, string $extension): void
    {
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
            case 'jpe':
            case 'jfi':
            case 'jfif':
                if (function_exists('imagejpeg')) {
                    imagejpeg($image, $path, 90);
                }
                break;
            case 'png':
                if (function_exists('imagepng')) {
                    imagepng($image, $path, 6);
                }
                break;
            case 'gif':
                if (function_exists('imagegif')) {
                    imagegif($image, $path);
                }
                break;
            case 'bmp':
                if (function_exists('imagebmp')) {
                    imagebmp($image, $path);
                }
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    imagewebp($image, $path, 90);
                }
                break;
        }
    }

    private static function resolveTinymceUploadDirectory(): string
    {
        return self::resolveUploadDestination(null)['directory'];
    }

    private static function buildPublicUploadLocation(string $basePath, string $filename): string
    {
        if (self::isUrl($basePath)) {
            return rtrim($basePath, '/') . '/' . $filename;
        }

        $normalized = strtr(trim($basePath), [chr(92) => '/']);
        if ($normalized === '') {
            $normalized = '/public/uploads';
        }

        if ($normalized !== '/' && !self::startsWith($normalized, '/')) {
            $normalized = '/' . $normalized;
        }

        return rtrim($normalized, '/') . '/' . $filename;
    }

    private static function generateUploadFilename(string $originalName, string $extension): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?? 'image';
        $base = trim($base, '-_');
        if ($base === '') {
            $base = 'image';
        }

        // Use a 6-digit random number for brevity
        try {
            $randomNumber = random_int(0, 999999);
        } catch (Exception) {
            // Fallback in the rare case random_int is unavailable
            $randomNumber = (int) substr(str_replace('.', '', uniqid('', true)), -6);
            if ($randomNumber < 0) {
                $randomNumber = 0;
            }
        }

        return sprintf('%s-%06d.%s', $base, $randomNumber, $extension);
    }

    private static function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded image is too large.',
            UPLOAD_ERR_PARTIAL => 'Uploaded image was only partially received.',
            UPLOAD_ERR_NO_FILE => 'No image file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded image to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the image upload.',
            default => 'Image upload failed with error code ' . $code . '.',
        };
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (self::startsWith($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\\\//]/', $path);
    }

    private static function isUrl(string $path): bool
    {
        return (bool) preg_match('/^https?:\/\//i', $path);
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    /**
     * Emit a JSON response and terminate execution.
     *
     * @param array<string, mixed> $payload
     */
    private static function respond(array $payload, int $status = 200): void
    {
        if (ob_get_level() > 0) {
            @ob_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        http_response_code($status);

        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $fallback = json_encode([
                'success' => false,
                'error' => 'Failed to encode JSON response.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"success":false,"error":"Failed to encode JSON response."}';
            echo $fallback;
            exit;
        }

        echo $json;
        exit;
    }
}
