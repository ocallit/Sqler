# Sqler - PHP Database Access Library

- A php sql builder, database & query metadata, auditor, helpers and mysqli wrapper.
- A powerful, feature-rich PHP database access layer built on MySQLi, providing intelligent query building, comprehensive error handling, database metadata introspection, and complete audit trail functionality.

## Key Features

- **🔄 Intelligent Error Recovery** - Automatic retry on deadlocks, timeouts, and connection issues
- **🛡️ Robust Error Handling** - Comprehensive exception handling with specific error type detection
- **📊 Multiple Result Formats** - Get data in the shape you need: single values, rows, arrays, key-value pairs
- **🔍 Database Introspection** - Complete metadata access for tables, columns, relationships
- **📝 Audit Trail System** - Full change tracking with diff analysis and user attribution
- **⚡ Smart Query Building** - Parameterized queries with MySQL function recognition
- **🔒 Transaction Management** - Nested transaction support with automatic cleanup
- **📋 Query & Error Logging** - Comprehensive logging for debugging and monitoring

## AI Assistant Documentation

**For AI assistants and comprehensive API reference**: See [SQLER_AI_DOCUMENTATION.md](SQLER_AI_DOCUMENTATION.md) - This contains detailed method signatures, return types, usage patterns, and examples specifically formatted for AI assistance.

## Installation & Quick Start

### Requirements
- PHP 8.2+
- MySQLi extension
- MySQL 5.7+ / MariaDB 10.2+

### Installation via Composer
```bash
composer require ocallit/sqler
```

### Basic Usage

```php
use Ocallit\Sqler\SqlExecutor;
use Ocallit\Sqler\QueryBuilder;
use Ocallit\Sqler\DatabaseMetadata;
use Ocallit\Sqler\Historian;

// Initialize database connection
$sql = new SqlExecutor([
    'hostname' => 'localhost',
    'username' => 'user',
    'password' => 'password',
    'database' => 'mydb'
]);

// Execute queries in different result formats
$userCount = $sql->firstValue("SELECT COUNT(*) FROM users WHERE active = ?", [1]);
$user = $sql->row("SELECT * FROM users WHERE id = ?", [123]);
$users = $sql->array("SELECT * FROM users WHERE department = ?", ['IT']);
$usersByDept = $sql->arrayKeyed("SELECT * FROM users", 'department');

// Build queries safely
$qb = new QueryBuilder();
$insert = $qb->insert('users', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'created_at' => 'NOW()',  // MySQL functions recognized
    'active' => 1
], true); // Enable ON DUPLICATE KEY UPDATE

$sql->query($insert['query'], $insert['parameters']);
```

## Core Components

### SqlExecutor - Database Query Engine
The heart of the library, providing multiple ways to execute and retrieve data:

```php
// Different result shapes for different needs
$count = $sql->firstValue("SELECT COUNT(*) FROM orders");           // Single value
$order = $sql->row("SELECT * FROM orders WHERE id = ?", [123]);    // Single row
$orders = $sql->array("SELECT * FROM orders WHERE status = ?", ['pending']); // All rows
$ordersByStatus = $sql->arrayKeyed("SELECT * FROM orders", 'status'); // Keyed by column
$statusList = $sql->vector("SELECT DISTINCT status FROM orders");    // Single column values
$statusCounts = $sql->keyValue("SELECT status, COUNT(*) FROM orders GROUP BY status"); // Key-value pairs
```

**Error Handling with Smart Recovery:**
```php
try {
    $sql->query("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ?", [5, 123]);
} catch (Exception $e) {
    if ($sql->is_last_error_duplicate_key()) {
        // Handle unique constraint violation
    } elseif ($sql->is_last_error_table_not_found()) {
        // Handle missing table
    }
    // Other specific error checks available
}
```

### QueryBuilder - Safe Query Construction
Build parameterized queries with automatic escaping and MySQL function recognition:

```php
$qb = new QueryBuilder();

// INSERT with ON DUPLICATE KEY UPDATE
$insert = $qb->insert('products', [
    'name' => 'Widget',
    'price' => 29.99,
    'updated_at' => 'NOW()',
    'stock' => 100
], true, ['created_at']); // Update on duplicate, but don't touch created_at

// UPDATE with WHERE conditions
$update = $qb->update('products', 
    ['price' => 34.99, 'updated_at' => 'NOW()'],
    ['category' => 'electronics', 'active' => 1]
);

// Complex WHERE clauses
$where = $qb->where([
    'category' => ['electronics', 'gadgets'],  // IN clause
    'price' => 50,                             // Exact match
    'created_date' => 'CURDATE()',             // MySQL function
    'active' => 1
], 'AND');

$sql->query($update['query'], $update['parameters']);
```

### DatabaseMetadata - Schema Introspection
Get complete information about your database structure:

```php
// Initialize once
DatabaseMetadata::initialize($sql);
$meta = DatabaseMetadata::getInstance();

// Get table structure
$userColumns = $meta->table('users');
foreach ($userColumns as $column) {
    echo "{$column['Field']} - {$column['Type']} - {$column['Key']}\n";
}

// Get relationships
$primaryKeys = $meta->primaryKeys();
$foreignKeys = $meta->getForeignKeys('orders');
$allRelationships = $meta->foreignKeysAll();

// Analyze query results
$queryMeta = $meta->query("SELECT u.name, o.total FROM users u JOIN orders o ON u.id = o.user_id");
// Returns detailed info about each column including source table and type
```

### Historian - Complete Audit Trail
Track all changes with automatic diff analysis:

```php
$historian = new Historian($sql, 'users', ['id']); // Track 'users' table, primary key is 'id'

// Track an INSERT
$sql->query("INSERT INTO users (name, email) VALUES (?, ?)", ['Alice', 'alice@example.com']);
$newUser = $sql->row("SELECT * FROM users WHERE id = ?", [$sql->last_insert_id()]);
$historian->register('insert', ['id' => $newUser['id']], $newUser, 'admin', 'New user registration');

// Track an UPDATE
$sql->query("UPDATE users SET email = ? WHERE id = ?", ['alice.smith@example.com', 123]);
$updatedUser = $sql->row("SELECT * FROM users WHERE id = ?", [123]);
$historian->register('update', ['id' => 123], $updatedUser, 'admin', 'Email change request');

// Track a DELETE
$userToDelete = $sql->row("SELECT * FROM users WHERE id = ?", [123]);
$sql->query("DELETE FROM users WHERE id = ?", [123]);
$historian->register('delete', ['id' => 123], $userToDelete, 'admin', 'Account deactivation');

// Get change history with automatic diff analysis
$changes = $historian->getChanges(['id' => 123]);
foreach ($changes as $change) {
    echo "Action: {$change['action']} by {$change['user_nick']} on {$change['date']}\n";
    foreach ($change['diff'] as $field => $diff) {
        echo "  {$field}: '{$diff['before']}' → '{$diff['after']}'\n";
    }
}
```

## Advanced Features

### Transaction Management
```php
// Automatic transaction with retry
$sql->transaction([
    "UPDATE accounts SET balance = balance - 100 WHERE id = 1",
    "UPDATE accounts SET balance = balance + 100 WHERE id = 2",
    "INSERT INTO transactions (from_account, to_account, amount) VALUES (1, 2, 100)"
], 'Money transfer');

// Manual transaction control
$sql->begin('Complex operation');
try {
    $sql->query("UPDATE inventory SET quantity = quantity - ? WHERE id = ?", [5, 123]);
    $sql->query("INSERT INTO orders (product_id, quantity) VALUES (?, ?)", [123, 5]);
    $sql->commit('Complex operation');
} catch (Exception $e) {
    $sql->rollback('Complex operation');
    throw $e;
}
```

### Error Recovery & Logging
```php
// The library automatically retries on:
// - Deadlocks (1213)
// - Lock wait timeouts (1205) 
// - Connection lost errors (2006, 2013)
// - Other transient errors

// Access logs for debugging
$queryLog = $sql->getLog();        // All executed queries
$errorLog = $sql->getErrorLog();   // All errors with retry attempts

// Specific error type checking
if ($sql->is_last_error_foreign_key_violation()) {
    echo "Referenced record doesn't exist";
} elseif ($sql->is_last_error_child_records_exist()) {
    echo "Cannot delete: child records exist";
}
```

## Configuration Options

### SqlExecutor Configuration
```php
$sql = new SqlExecutor(
    connect: [
        'hostname' => 'localhost',
        'username' => 'user', 
        'password' => 'pass',
        'database' => 'mydb',
        'port' => 3306,
        'socket' => null,
        'flags' => 0
    ],
    connect_options: [
        MYSQLI_INIT_COMMAND => 'SET AUTOCOMMIT = 1',
        MYSQLI_OPT_CONNECT_TIMEOUT => 10
    ],
    charset: 'utf8mb4',
    coalition: 'utf8mb4_0900_ai_ci'
);
```

### QueryBuilder Options
```php
$qb = new QueryBuilder(
    useNewOnDuplicate: true  // Use MySQL 8.0.19+ syntax for ON DUPLICATE KEY UPDATE
);
```

## Best Practices

1. **Always use parameterized queries** - The library handles this automatically
2. **Initialize DatabaseMetadata once** at application startup
3. **Use appropriate result methods** based on expected data shape
4. **Implement proper error handling** with specific error type checking
5. **Close connections properly** in finally blocks
6. **Use transactions for multi-query operations**
7. **Leverage audit trails** for compliance and debugging

## Error Handling Reference

The library provides specific error detection methods:

- `is_last_error_table_not_found()` - Missing tables
- `is_last_error_duplicate_key()` - Unique constraint violations
- `is_last_error_foreign_key_violation()` - Invalid foreign key references
- `is_last_error_child_records_exist()` - Cannot delete parent with children
- `is_last_error_column_not_found()` - Invalid column names

## License

MIT License - see LICENSE file for details.

## Contributing

Contributions are welcome! Please ensure:
- PHP 8.2+ compatibility
- Comprehensive error handling
- Unit tests for new features
- Documentation updates

## Support

For issues, feature requests, or questions, please use the GitHub issue tracker.



