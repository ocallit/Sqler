# Sqler Repository Documentation

**Claude's Internal Reference Guide for Database Access Classes**

This documentation serves as my reference guide for the Ocallit\Sqler PHP classes, enabling me to help write database access code effectively.

## Overview

The repository provides a complete MySQL database access layer with:
- **SqlExecutor**: Core database executor with error handling and retry logic
- **QueryBuilder**: SQL query construction helper
- **DatabaseMetadata**: Database introspection and metadata retrieval
- **Historian**: Audit trail system for tracking record changes
- **SqlUtils**: Utility functions for SQL operations

---

## 1. SqlExecutor Class

**Claude Note**: The SqlExecutor class has comprehensive error handling, retry logic, and logging. All public methods have specific return types and error handling patterns documented below.

### Purpose
Core database execution class providing MySQLi wrapper with automatic retry on locks/disconnections, comprehensive error handling, and query logging.

### Key Features
- Automatic retry on deadlocks, timeouts, and connection issues (when not in transaction)
- Comprehensive error logging and query logging
- Multiple result return formats
- Transaction management
- Connection management with reconnection logic

### Constructor
```php
__construct(array $connect, array $connect_options = [], string $charset = 'utf8', string $coalition = 'utf8_unicode_ci', int $flags = 0)
```
**Parameters:**
- `$connect`: Connection parameters [hostname, username, password, database, port, socket, flags]
- `$connect_options`: MySQLi options (default: AUTOCOMMIT = 1)
- `$charset`: Character set (default: utf8)
- `$coalition`: Collation (default: utf8_unicode_ci)

### Query Execution Methods

#### `query(string|mysqli_stmt $query, array $parameters = []): bool|mysqli_result`
**Returns**: Boolean for non-SELECT queries, mysqli_result for SELECT queries
**Throws**: Exception on error
**Purpose**: Execute any SQL query with optional parameters

#### `firstValue(string|mysqli_stmt $query, array $parameters = [], string|null|bool $default = ""): string|null|bool`
**Returns**: Single scalar value from first column of first row, or $default if not found
**Shape**: Single primitive value
**Use**: Getting counts, single field values, checking existence

#### `row(string|mysqli_stmt $query, array $parameters = [], array $default = [], int $resultType = MYSQLI_ASSOC): array`
**Returns**: Single row as associative array, or $default if not found
**Shape**: `[column_name => value, ...]`
**Use**: Fetching single record details

#### `array(string|mysqli_stmt $query, array $parameters = [], array $default = [], int $resultType = MYSQLI_ASSOC): array`
**Returns**: All rows as array of associative arrays, or $default if not found
**Shape**: `[[column_name => value, ...], [column_name => value, ...], ...]`
**Use**: Fetching multiple records

#### `arrayKeyed(string|mysqli_stmt $query, string $key, array $parameters = [], array $default = [], int $resultType = MYSQLI_ASSOC): array`
**Returns**: Rows keyed by specified column value
**Shape**: `[key_value => [column_name => value, ...], ...]`
**Use**: Creating lookup tables, indexing by specific field

#### `vector(string|mysqli_stmt $query, array $parameters = [], array $default = []): array`
**Returns**: Array of values from first column of each row
**Shape**: `[value1, value2, value3, ...]`
**Use**: Getting lists of IDs, names, or single values

#### `keyValue(string|mysqli_stmt $query, array $parameters = [], array $default = []): array`
**Returns**: Key-value pairs from first two columns
**Shape**: `[key1 => value1, key2 => value2, ...]`
**Use**: Creating simple lookup maps

#### `multiKey(string|mysqli_stmt $query, array $keys, array $parameters = [], array $default = []): array`
**Returns**: Multi-dimensional array keyed by specified columns
**Shape**: Nested array structure based on key columns
**Use**: Complex hierarchical data organization

#### `multiKeyLast(string|mysqli_stmt $query, array $parameters = [], array $default = []): array`
**Returns**: Multi-dimensional array using all but last column as keys, last column as values
**Use**: Creating nested structures from flat query results

#### `result(string|mysqli_stmt $query, array $parameters = []): mysqli_result|bool`
**Returns**: Raw mysqli_result object (caller must free)
**Use**: When you need direct access to result object for custom processing

### Transaction Methods

#### `begin(string $comment = ''): bool`
**Purpose**: Start transaction with optional comment for logging

#### `commit(string $comment = ''): bool`
**Purpose**: Commit current transaction

#### `rollback(string $comment = ''): bool`
**Purpose**: Rollback current transaction

#### `transaction(array $queries, string|int $comment = ''): void`
**Purpose**: Execute multiple queries in a transaction with automatic retry
**Throws**: Exception if all retry attempts fail

### Error Detection Helper Methods

#### `is_last_error_table_not_found(): bool`
**Purpose**: Check if last error was table not found (1146, 1051, 1109)

#### `is_last_error_duplicate_key(): bool`
**Purpose**: Check if last error was duplicate/unique key violation (1062, 1022)

#### `is_last_error_invalid_foreign_key(): bool`
**Purpose**: Check if last error was foreign key violation (1216, 1452)

#### `is_last_error_child_records_exist(): bool`
**Purpose**: Check if last error was due to existing child records (1451)

#### `is_last_error_column_not_found(): bool`
**Purpose**: Check if last error was column not found (1054, 1166, 1063)

### Utility Methods

#### `last_insert_id(): int`
**Purpose**: Get last auto-increment ID

#### `affected_rows(): int`
**Purpose**: Get number of affected rows from last query

#### `getLastErrorNumber(): int`
**Purpose**: Get MySQL error number from last operation

#### `getLog(): array`
**Purpose**: Get query execution log

#### `getErrorLog(): array`
**Purpose**: Get error log with details

#### `closeConnection(): void`
**Purpose**: Close database connection and cleanup

---

## 2. QueryBuilder Class

**Claude Note**: QueryBuilder creates parameterized queries with proper escaping. All methods return arrays with 'query' and 'parameters' keys.

### Purpose
Builds parameterized SQL queries (INSERT, UPDATE, WHERE clauses) with proper field escaping and parameter binding.

### Constructor
```php
__construct(bool $useNewOnDuplicate = true)
```
**Parameters:**
- `$useNewOnDuplicate`: Use MySQL 8.0.19+ `new.column` syntax instead of `VALUES(column)`

### Methods

#### `insert(string $table, array $array, bool $onDuplicateKeyUpdate = false, array $onDuplicateKeyDontUpdate = [], array $onDuplicateKeyOverride = [], string $comment = ''): array`
**Returns**: `['query' => string, 'parameters' => array]`
**Purpose**: Build INSERT query with ON DUPLICATE KEY UPDATE functionality
**Parameters:**
- `$table`: Table name
- `$array`: Column => value pairs
- `$onDuplicateKeyUpdate`: Enable ON DUPLICATE KEY UPDATE clause
- `$onDuplicateKeyDontUpdate`: Fields to exclude from duplicate key updates (e.g., creation timestamps)
- `$onDuplicateKeyOverride`: Custom expressions for specific fields on duplicate
  **Special Features:**
- **Smart MySQL function handling**: Recognizes MySQL functions (NOW(), CURDATE(), CURRENT_TIMESTAMP, etc.) and doesn't parameterize them
- **ON DUPLICATE KEY UPDATE**: Automatically generates update clauses for all fields except excluded ones
- **MySQL 8.0.19+ support**: Uses `new.column` syntax when `$useNewOnDuplicate` is true, otherwise uses `VALUES(column)`
- **Automatic field exclusion**: Won't update creation audit fields like 'alta_db', 'registered', etc.

#### `update(string $table, array $array, array $where = [], string $comment = ''): array`
**Returns**: `['query' => string, 'parameters' => array]`
**Purpose**: Build UPDATE query with WHERE clause
**Special Features:**
- **Smart MySQL function handling**: Automatically detects and handles MySQL functions without parameterization

#### `where(array $array, string $op = "AND", string $comment = ""): array`
**Returns**: `['query' => string, 'parameters' => array]`
**Purpose**: Build WHERE clause from associative array with flexible operators
**Features:**
- **Array value support**: Automatically converts arrays to IN clauses
- **MySQL function recognition**: Handles special values like NOW(), CURRENT_DATE(), SYSDATE(), etc. without quotes
- **Flexible operators**: Supports AND/OR between conditions
- **Smart parameterization**: Only parameterizes actual values, not MySQL functions
  **MySQL Functions Recognized:**
- Date/Time: `NOW()`, `NOW(6)`, `CURDATE()`, `CURRENT_DATE()`, `SYSDATE()`, `UTC_DATE()`
- Time: `CURRENT_TIME()`, `CURTIME()`, `UTC_TIME()`
- Timestamp: `CURRENT_TIMESTAMP()`, `LOCALTIMESTAMP()`, `UNIX_TIMESTAMP()`, `UTC_TIMESTAMP()`
- UUID: `IA_UUID()`

---

## 3. DatabaseMetadata Class (Singleton)

**Claude Note**: DatabaseMetadata is a singleton that must be initialized with SqlExecutor. All public methods provide database introspection capabilities.

### Purpose
Provides database schema information, table structure, and query result metadata.

### Initialization
```php
DatabaseMetadata::initialize(SqlExecutor $sql): void
DatabaseMetadata::getInstance(): static
```
**Usage**: Must call `initialize()` once before using `getInstance()`

### Methods

#### `table(string $tableName): array`
**Returns**: Array of column information for specified table
**Shape**: Array of column details with Field, Type, Null, Key, Default, Extra, etc.
**Purpose**: Get complete table structure information

#### `primaryKeys(): array`
**Returns**: Multi-dimensional array of primary keys by table
**Shape**: `[table_name => [column_name => column_name, ...], ...]`
**Purpose**: Get primary key information for all tables

#### `getForeignKeys(string $tableName): array`
**Returns**: Foreign key relationships for specified table
**Shape**: `[column_name => ['referenced_table' => string, 'referenced_column' => string], ...]`
**Purpose**: Get foreign key constraints for a table

#### `foreignKeysAll(): array`
**Returns**: Complete foreign key information for database
**Shape**: `['childs' => [...], 'parents' => [...], 'foreign_keys' => [...]]`
**Purpose**: Get comprehensive foreign key relationships

#### `query(string $query, array $parameters = []): array`
**Returns**: Metadata about query result columns
**Shape**: Array with field information including kind (table, derived, aggregate, calculated)
**Purpose**: Analyze query results and understand column sources

#### `clear(): void`
**Purpose**: Clear cached metadata (useful after schema changes)

---

## 4. Historian Class (Audit System)

**Claude Note**: Historian provides complete audit trail functionality. All public methods handle change tracking and history retrieval.

### Purpose
Tracks and stores audit trails of record changes (insert, update, delete) with user attribution and change analysis.

### Constructor
```php
__construct(SqlExecutor $sqlExecutor, string $table, array $primaryKeyFieldNames = [], array $ingoreDifferenceForFields = [])
```
**Parameters:**
- `$sqlExecutor`: Database executor instance
- `$table`: Table name to track (history table will be `{table}_hist`)
- `$primaryKeyFieldNames`: Primary key field names (defaults to `[{table}_id]`)
- `$ingoreDifferenceForFields`: Fields to ignore in change detection

### Methods

#### `register(string $action, array $pk, array $values, string $user_nick = '', string $motive = ''): void`
**Purpose**: Record a change to the audit trail with complete record state
**Parameters:**
- `$action`: 'insert', 'update', or 'delete'
- `$pk`: Primary key values
- `$values`: Complete record data (current state after change)
- `$user_nick`: User making the change (uses session if empty)
- `$motive`: Optional reason for change
  **Usage Patterns:**
- **INSERT**: After inserting, SELECT the complete new record and register with current state
- **UPDATE**: After updating, SELECT the complete updated record and register the new state
- **DELETE**: Before deleting, SELECT the complete record and register the final state before deletion
  **Auto-creates history table**: If `{table}_hist` doesn't exist, it creates it automatically

#### `getChanges(array $primaryKeyValues, int|string $offset = 0, int|string $rows = 100): array`
**Returns**: Array of change records with diff analysis
**Shape**: Array of objects with history_id, action, date, user_nick, diff, record
**Purpose**: Get paginated change history for a record

#### `getNLastChanges(array $primaryKeyValues, int $numEntries = 7): array`
**Returns**: Last N changes for a record
**Purpose**: Get recent change history

#### `getLastChange(array $primaryKeyValues): array`
**Returns**: Last 2 changes for a record
**Purpose**: Get most recent changes

#### `changesAsHTML(array $changes): string`
**Returns**: HTML table representation of changes
**Purpose**: Format change history for display

#### `setIngoreDifferenceForFields(array $ingoreDifferenceForFields): void`
**Purpose**: Update fields to ignore in change detection

---

## 5. SqlUtils Class

**Claude Note**: SqlUtils provides static utility methods for SQL operations. All methods are pure functions.

### Purpose
Static utility methods for SQL string handling and formatting.

### Methods

#### `fieldIt(string $fieldName): string`
**Returns**: Field name properly escaped with backticks
**Purpose**: Safely escape field/table names (handles `table`.`column` format)
**Example**: `fieldIt('user.name')` → `` `user`.`name` ``

#### `strIt(string|null $str): string`
**Returns**: Properly escaped and quoted string for SQL
**Purpose**: Escape string values for safe SQL inclusion
**Handles**: NULL values, empty strings, special characters

#### `toLabel(string $fieldName): string`
**Returns**: Human-readable label from field name
**Purpose**: Convert field names to display labels
**Example**: `toLabel('user_name')` → `"User Name"`

### Constants
- `JSON_MYSQL_OPTIONS`: JSON encoding options optimized for MySQL storage

---

## Error Handling Patterns

### SqlExecutor Error Flow
1. **Automatic Retry**: On locks, deadlocks, timeouts (only outside transactions)
2. **Reconnection**: On connection lost errors
3. **Exception Throwing**: All errors are thrown as exceptions
4. **Error Logging**: All errors logged with query, parameters, and attempt number
5. **Helper Methods**: Use `is_last_error_*()` methods to identify error types

### Recovery Behavior
- **In Transaction**: No retry, immediate exception
- **Outside Transaction**: Retry up to 3 times with reconnection if needed
- **Supported Errors**: Deadlocks (1213), lock timeouts (1205), connection lost (2006, 2013)

---

## Usage Patterns

### Basic Query Execution
```php
// Get single value
$count = $sql->firstValue("SELECT COUNT(*) FROM users WHERE active = ?", [1]);

// Get single record
$user = $sql->row("SELECT * FROM users WHERE id = ?", [$userId]);

// Get multiple records
$users = $sql->array("SELECT * FROM users WHERE department_id = ?", [$deptId]);
```

### Query Building with Smart Function Recognition
```php
$qb = new QueryBuilder();

// INSERT with ON DUPLICATE KEY UPDATE
$insert = $qb->insert('users', [
    'name' => 'John',
    'email' => 'john@example.com',
    'last_login' => 'NOW()',  // Recognized as MySQL function
    'created_at' => 'CURRENT_TIMESTAMP'  // Not parameterized
], true, ['created_at'], ['last_login' => 'NOW()']);

// WHERE clause from array with MySQL functions
$where = $qb->where([
    'active' => 1,
    'department_id' => [1, 2, 3],  // Becomes IN clause
    'created_date' => 'CURDATE()',  // Recognized function
    'status' => ['active', 'pending']  // IN clause
], 'AND');

$sql->query($insert['query'], $insert['parameters']);
```

### Proper Audit Trail Usage
```php
$historian = new Historian($sql, 'users');

// INSERT audit - after inserting, get the complete new record
$sql->query("INSERT INTO users (name, email) VALUES (?, ?)", ['John', 'j@example.com']);
$newRecord = $sql->row("SELECT * FROM users WHERE id = ?", [$sql->last_insert_id()]);
$historian->register('insert', ['id' => $newRecord['id']], $newRecord, $currentUser);

// UPDATE audit - after updating, get the complete updated record
$sql->query("UPDATE users SET name = ? WHERE id = ?", ['Jane', $userId]);
$updatedRecord = $sql->row("SELECT * FROM users WHERE id = ?", [$userId]);
$historian->register('update', ['id' => $userId], $updatedRecord, $currentUser, 'Name correction');

// DELETE audit - before deleting, get the complete record to preserve final state
$recordToDelete = $sql->row("SELECT * FROM users WHERE id = ?", [$userId]);
$sql->query("DELETE FROM users WHERE id = ?", [$userId]);
$historian->register('delete', ['id' => $userId], $recordToDelete, $currentUser, 'Account cleanup');

// Get change history with diff analysis
$changes = $historian->getChanges(['id' => $userId]);
```

### Metadata Usage
```php
DatabaseMetadata::initialize($sql);
$meta = DatabaseMetadata::getInstance();
$tableInfo = $meta->table('users');
$primaryKeys = $meta->primaryKeys();
```

---

## Potential Issues Identified


1. **Historian Logic**: In `diff()` method, the loop seems to have inverted logic - `continue` when `$differ` is not empty
2. **Transaction Counter**: `openTransactions` counter could get out of sync if exceptions occur during begin/commit/rollback
3. **Memory Usage**: Logs have max entries but no cleanup mechanism
4. **Error Handling**: Some methods don't check if `mysqli` is null before accessing properties they throw an error

---

## Best Practices for Usage

1. **Always use parameterized queries** through SqlExecutor methods
2. **Initialize DatabaseMetadata once** at application start
3. **Use appropriate return type methods** based on expected data shape
4. **Handle exceptions** from all SqlExecutor methods
5. **Check error helper methods** after operations for specific error handling
6. **Use transactions** for multi-query operations
7. **Implement proper cleanup** with `closeConnection()` in finally blocks