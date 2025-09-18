<?php
declare(strict_types=1);

namespace FastCrud;

use Exception;
use InvalidArgumentException;

class CrudAjax
{
    /**
     * Handle incoming AJAX requests for CRUD operations.
     */
    public static function handle(): void
    {
        header('Content-Type: application/json');
        
        try {
            $request = self::getRequestData();
            $action = $request['action'] ?? 'fetch';
            
            switch ($action) {
                case 'fetch':
                    self::handleFetchTable($request);
                    break;
                case 'update':
                    self::handleUpdate($request);
                    break;
                case 'delete':
                    self::handleDelete($request);
                    break;
                default:
                    throw new InvalidArgumentException('Invalid action: ' . $action);
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
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
        $perPage = isset($request['per_page']) ? (int) $request['per_page'] : null;
        
        $crud = new Crud($table);
        $data = $crud->getTableData($page, $perPage);
        
        echo json_encode([
            'success' => true,
            'data' => $data['rows'],
            'columns' => $data['columns'],
            'pagination' => $data['pagination'],
            'id' => $request['id'] ?? null
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

        $crud = new Crud((string) $request['table']);
        $updatedRow = $crud->updateRecord(
            (string) $request['primary_key_column'],
            $request['primary_key_value'],
            $fields
        );

        echo json_encode([
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

        $crud     = new Crud((string) $request['table']);
        $deleted  = $crud->deleteRecord((string) $request['primary_key_column'], $request['primary_key_value']);
        $response = [
            'success' => $deleted,
            'deleted' => $deleted,
            'id'      => $request['id'] ?? null,
        ];

        if (!$deleted) {
            $response['error'] = 'Record not found or already deleted.';
        }

        echo json_encode($response);
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
}
