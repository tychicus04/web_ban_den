<?php
/**
 * Database Helper Functions
 * Reusable database query utilities
 *
 * This file provides common database operations to reduce query duplication
 * and provide consistent error handling across the application.
 *
 * @author TK-MALL Development Team
 * @version 2.0.0
 */

/**
 * Execute a SELECT query and return all results
 *
 * @param PDO $db Database connection
 * @param string $sql SQL query
 * @param array $params Query parameters (default: [])
 * @return array|false Query results or false on error
 */
function dbSelect($db, $sql, $params = [])
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database SELECT error: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute a SELECT query and return first result
 *
 * @param PDO $db Database connection
 * @param string $sql SQL query
 * @param array $params Query parameters (default: [])
 * @return array|false First row or false on error/no results
 */
function dbSelectOne($db, $sql, $params = [])
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database SELECT ONE error: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute a COUNT query and return count
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param string $where WHERE clause (without WHERE keyword)
 * @param array $params Query parameters (default: [])
 * @return int Count result
 */
function dbCount($db, $table, $where = '', $params = [])
{
    try {
        $sql = "SELECT COUNT(*) as count FROM {$table}";
        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($result['count'] ?? 0);
    } catch (PDOException $e) {
        error_log("Database COUNT error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Execute an INSERT query
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param array $data Associative array of column => value
 * @return int|false Last insert ID or false on error
 */
function dbInsert($db, $table, $data)
{
    try {
        $columns = array_keys($data);
        $values = array_values($data);

        $columnList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $sql = "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})";

        $stmt = $db->prepare($sql);
        $stmt->execute($values);

        return $db->lastInsertId();
    } catch (PDOException $e) {
        error_log("Database INSERT error: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute an UPDATE query
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param array $data Associative array of column => value
 * @param string $where WHERE clause (without WHERE keyword)
 * @param array $whereParams WHERE clause parameters
 * @return bool True on success, false on error
 */
function dbUpdate($db, $table, $data, $where, $whereParams = [])
{
    try {
        $setClauses = [];
        $values = [];

        foreach ($data as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $values[] = $value;
        }

        $setClause = implode(', ', $setClauses);
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";

        // Merge data values and where parameters
        $allParams = array_merge($values, $whereParams);

        $stmt = $db->prepare($sql);
        return $stmt->execute($allParams);
    } catch (PDOException $e) {
        error_log("Database UPDATE error: " . $e->getMessage());
        return false;
    }
}

/**
 * Execute a DELETE query
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param string $where WHERE clause (without WHERE keyword)
 * @param array $params Query parameters
 * @return bool True on success, false on error
 */
function dbDelete($db, $table, $where, $params = [])
{
    try {
        $sql = "DELETE FROM {$table} WHERE {$where}";

        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Database DELETE error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a record exists
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param string $where WHERE clause (without WHERE keyword)
 * @param array $params Query parameters
 * @return bool True if exists, false otherwise
 */
function dbExists($db, $table, $where, $params = [])
{
    return dbCount($db, $table, $where, $params) > 0;
}

/**
 * Get record by ID
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param int $id Record ID
 * @param string $idColumn ID column name (default: 'id')
 * @return array|false Record data or false
 */
function dbGetById($db, $table, $id, $idColumn = 'id')
{
    return dbSelectOne($db, "SELECT * FROM {$table} WHERE {$idColumn} = ? LIMIT 1", [$id]);
}

/**
 * Check if email exists in users table
 *
 * @param PDO $db Database connection
 * @param string $email Email to check
 * @param int $excludeId User ID to exclude (for update operations)
 * @return bool True if exists, false otherwise
 */
function dbEmailExists($db, $email, $excludeId = null)
{
    if ($excludeId) {
        return dbExists($db, 'users', 'email = ? AND id != ?', [$email, $excludeId]);
    } else {
        return dbExists($db, 'users', 'email = ?', [$email]);
    }
}

/**
 * Get paginated results
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @param int $page Current page (1-indexed)
 * @param int $perPage Items per page (default: 20)
 * @param string $where WHERE clause (optional)
 * @param array $params Query parameters (default: [])
 * @param string $orderBy ORDER BY clause (default: 'id DESC')
 * @return array ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0]
 */
function dbPaginate($db, $table, $page = 1, $perPage = 20, $where = '', $params = [], $orderBy = 'id DESC')
{
    $page = max(1, (int)$page);
    $perPage = max(1, (int)$perPage);
    $offset = ($page - 1) * $perPage;

    // Get total count
    $total = dbCount($db, $table, $where, $params);

    // Get paginated data
    $sql = "SELECT * FROM {$table}";
    if (!empty($where)) {
        $sql .= " WHERE {$where}";
    }
    $sql .= " ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}";

    $data = dbSelect($db, $sql, $params);

    return [
        'data' => $data ?: [],
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => ceil($total / $perPage)
    ];
}

/**
 * Execute raw SQL query (use with caution)
 *
 * @param PDO $db Database connection
 * @param string $sql SQL query
 * @param array $params Query parameters (default: [])
 * @return bool True on success, false on error
 */
function dbExecute($db, $sql, $params = [])
{
    try {
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        error_log("Database EXECUTE error: " . $e->getMessage());
        return false;
    }
}

/**
 * Begin transaction
 *
 * @param PDO $db Database connection
 * @return bool True on success, false on error
 */
function dbBeginTransaction($db)
{
    try {
        return $db->beginTransaction();
    } catch (PDOException $e) {
        error_log("Database BEGIN TRANSACTION error: " . $e->getMessage());
        return false;
    }
}

/**
 * Commit transaction
 *
 * @param PDO $db Database connection
 * @return bool True on success, false on error
 */
function dbCommit($db)
{
    try {
        return $db->commit();
    } catch (PDOException $e) {
        error_log("Database COMMIT error: " . $e->getMessage());
        return false;
    }
}

/**
 * Rollback transaction
 *
 * @param PDO $db Database connection
 * @return bool True on success, false on error
 */
function dbRollback($db)
{
    try {
        return $db->rollBack();
    } catch (PDOException $e) {
        error_log("Database ROLLBACK error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get table columns
 *
 * @param PDO $db Database connection
 * @param string $table Table name
 * @return array Column names
 */
function dbGetColumns($db, $table)
{
    try {
        $stmt = $db->query("DESCRIBE {$table}");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $columns ?: [];
    } catch (PDOException $e) {
        error_log("Database GET COLUMNS error: " . $e->getMessage());
        return [];
    }
}

/**
 * Sanitize table/column name to prevent SQL injection
 *
 * @param string $name Table or column name
 * @return string Sanitized name
 */
function dbSanitizeName($name)
{
    // Only allow alphanumeric and underscore
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}
?>
