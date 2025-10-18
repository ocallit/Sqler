<?php
/**
 * Error Log API - Simple, direct backend using Ocallit\Sqler
 * Handles server-side filtering, sorting, and pagination for Tabulator.js
 */

use Ocallit\Sqler\QueryBuilder;
use Ocallit\Sqler\SqlUtils;

// Include config for authentication, security, and database connection
require_once '../config/config.php';


// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Initialize QueryBuilder for safe query construction
global $gSqlExecutor;
$qb = new QueryBuilder();

try {
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';
    
    // Route to appropriate handler
    switch ($action) {
        case 'getData':
            echo json_encode(handleGetData($input));
            break;
            
        case 'updateStatus':
            echo json_encode(handleUpdateStatus($input));
            break;
            
        case 'updateComment':
            echo json_encode(handleUpdateComment($input));
            break;
            
        default:
            throw new Exception("Invalid action: $action");
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    error_log("Error Log API Error: " . $e->getMessage());
}

/**
 * Handle getData request - server-side filtering, sorting, pagination
 */
function handleGetData(array $params): array {
    global $gSqlExecutor;
    
    $pageSize = min($params['size'] ?? 25, 100);
    $page = max(1, $params['page'] ?? 1);
    $offset = ($page - 1) * $pageSize;
    
    // Build base query
    $baseQuery = "SELECT * FROM error_log";
    $countQuery = "SELECT COUNT(*) as total FROM error_log";
    $queryParams = [];
    $whereConditions = [];
    
    // Handle filters from Tabulator
    if (!empty($params['filters'])) {
        $filters = json_decode($params['filters'], true);
        if (is_array($filters)) {
            foreach ($filters as $filter) {
                addWhereCondition($whereConditions, $queryParams, $filter);
            }
        }
    }
    
    // Apply WHERE clause if filters exist
    if (!empty($whereConditions)) {
        $whereClause = ' WHERE ' . implode(' AND ', $whereConditions);
        $baseQuery .= $whereClause;
        $countQuery .= $whereClause;
    }
    
    // Handle sorting from Tabulator
    $sortField = 'last_seen';
    $sortDir = 'DESC';
    
    if (!empty($params['sorters'])) {
        $sorters = json_decode($params['sorters'], true);
        if (is_array($sorters) && count($sorters) > 0) {
            $sortField = validateSortField($sorters[0]['field']);
            $sortDir = strtoupper($sorters[0]['dir']) === 'ASC' ? 'ASC' : 'DESC';
        }
    }
    
    // Complete the query with sorting and pagination
    $dataQuery = "$baseQuery ORDER BY `$sortField` $sortDir LIMIT $pageSize OFFSET $offset";
    
    // Execute queries using SqlExecutor
    $data = $gSqlExecutor->array($dataQuery, $queryParams);
    $totalCount = $gSqlExecutor->firstValue($countQuery, $queryParams, 0);
    $totalPages = ceil($totalCount / $pageSize);
    
    return [
        'data' => $data,
        'last_page' => $totalPages,
        'total_count' => (int)$totalCount,
        'current_page' => $page,
        'page_size' => $pageSize
    ];
}

/**
 * Add WHERE condition based on Tabulator filter
 */
function addWhereCondition(array &$conditions, array &$params, array $filter): void {
    $field = validateField($filter['field']);
    $type = $filter['type'] ?? '=';
    $value = $filter['value'] ?? '';
    
    // Skip empty filters except for null checks
    if (empty($value) && !in_array($type, ['nu', 'nn'])) {
        return;
    }
    
    $paramKey = 'param_' . count($params);
    
    switch ($type) {
        case '=':
        case 'eq':
            $conditions[] = "`$field` = :$paramKey";
            $params[$paramKey] = $value;
            break;
            
        case '!=':
        case 'ne':
            $conditions[] = "`$field` != :$paramKey";
            $params[$paramKey] = $value;
            break;
            
        case '<':
        case 'lt':
            $conditions[] = "`$field` < :$paramKey";
            $params[$paramKey] = $value;
            break;
            
        case '>':
        case 'gt':
            $conditions[] = "`$field` > :$paramKey";
            $params[$paramKey] = $value;
            break;
            
        case '<=':
        case 'le':
            $conditions[] = "`$field` <= :$paramKey";
            $params[$paramKey] = $value;
            break;
            
        case '>=':
        case 'ge':
            $conditions[] = "`$field` >= :$paramKey";
            $params[$paramKey] = $value;
            break;
            
        case 'like':
        case 'cn':
            $conditions[] = "`$field` LIKE :$paramKey";
            $params[$paramKey] = "%$value%";
            break;
            
        case 'starts':
        case 'bw':
            $conditions[] = "`$field` LIKE :$paramKey";
            $params[$paramKey] = "$value%";
            break;
            
        case 'ends':
        case 'ew':
            $conditions[] = "`$field` LIKE :$paramKey";
            $params[$paramKey] = "%$value";
            break;
            
        case 'nu':
            $conditions[] = "(`$field` IS NULL OR `$field` = '')";
            break;
            
        case 'nn':
            $conditions[] = "(`$field` IS NOT NULL AND `$field` != '')";
            break;
    }
}

/**
 * Handle status update
 */
function handleUpdateStatus(array $params): array {
    global $gSqlExecutor, $qb;
    
    $errorHash = $params['error_hash'] ?? '';
    $status = $params['status'] ?? '';
    
    // Validate inputs
    if (empty($errorHash) || !preg_match('/^[a-f0-9]{16}$/', $errorHash)) {
        throw new Exception('Invalid error hash');
    }
    
    if (!in_array($status, ['Bug', 'Fixed', "Won't Fix", 'Info'])) {
        throw new Exception('Invalid status value');
    }
    
    // Use QueryBuilder for safe update
    $updateData = [
        'status' => $status,
        'commented_at' => 'NOW()'  // QueryBuilder recognizes this as MySQL function
    ];
    
    $updateQuery = $qb->update('error_log', $updateData, ['error_hash' => $errorHash]);
    $gSqlExecutor->query($updateQuery['query'], $updateQuery['parameters']);
    
    if ($gSqlExecutor->affected_rows() === 0) {
        throw new Exception('Error record not found or no changes made');
    }
    
    return [
        'success' => true,
        'message' => 'Status updated successfully'
    ];
}

/**
 * Handle comment update
 */
function handleUpdateComment(array $params): array {
    global $gSqlExecutor, $qb;
    
    $errorHash = $params['error_hash'] ?? '';
    $comment = $params['comment'] ?? '';
    
    // Validate error hash
    if (empty($errorHash) || !preg_match('/^[a-f0-9]{16}$/', $errorHash)) {
        throw new Exception('Invalid error hash');
    }
    
    // Use QueryBuilder for safe update
    $updateData = [
        'comment' => $comment,
        'commented_at' => 'NOW()'  // QueryBuilder recognizes this as MySQL function
    ];
    
    $updateQuery = $qb->update('error_log', $updateData, ['error_hash' => $errorHash]);
    $gSqlExecutor->query($updateQuery['query'], $updateQuery['parameters']);
    
    if ($gSqlExecutor->affected_rows() === 0) {
        throw new Exception('Error record not found or no changes made');
    }
    
    return [
        'success' => true,
        'message' => 'Comment updated successfully'
    ];
}

/**
 * Validate and sanitize field names
 */
function validateField(string $field): string {
    $allowedFields = [
        'error_hash', 'status', 'error_type', 'error_code', 'seen_count',
        'error_message', 'file', 'function_name', 'line_number', 
        'first_seen', 'last_seen', 'user_nick', 'comment'
    ];
    
    if (!in_array($field, $allowedFields)) {
        throw new Exception("Invalid field name: $field");
    }
    
    return $field;
}

/**
 * Validate sort fields (more restrictive than filter fields)
 */
function validateSortField(string $field): string {
    $allowedSortFields = [
        'error_hash', 'status', 'error_type', 'error_code', 'seen_count',
        'first_seen', 'last_seen', 'line_number', 'user_nick'
    ];
    
    if (!in_array($field, $allowedSortFields)) {
        return 'last_seen'; // Safe default
    }
    
    return $field;
}
?>