<?php
declare(strict_types=1);

namespace CodexCrud;

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
            $action = $_GET['action'] ?? 'fetch';
            
            switch ($action) {
                case 'fetch':
                    self::handleFetchTable();
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
    private static function handleFetchTable(): void
    {
        if (!isset($_GET['table'])) {
            throw new InvalidArgumentException('Table parameter is required');
        }
        
        $table = $_GET['table'];
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : null;
        
        $crud = new Crud($table);
        $data = $crud->getTableData($page, $perPage);
        
        echo json_encode([
            'success' => true,
            'data' => $data['rows'],
            'columns' => $data['columns'],
            'pagination' => $data['pagination'],
            'id' => $_GET['id'] ?? null
        ]);
    }
    
    /**
     * Check if current request is an AJAX request for CrudAjax.
     */
    public static function isAjaxRequest(): bool
    {
        return isset($_GET['codexcrud_ajax']) && $_GET['codexcrud_ajax'] === '1';
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
}