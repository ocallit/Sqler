# Skill: Ocallit\Sqler — PHP Database Interface

When helping with PHP projects that use `Ocallit\Sqler`, use this skill to write
correct database code. This library wraps MySQLi with safe parameterized queries,
a fluent query builder, schema introspection, and a full audit-trail system.

## Namespace & Files

```
Ocallit\Sqler\SqlExecutor       — core query engine
Ocallit\Sqler\QueryBuilder      — INSERT / UPDATE / WHERE builder
Ocallit\Sqler\DatabaseMetadata  — schema introspection (singleton)
Ocallit\Sqler\Historian         — audit trail / change history
Ocallit\Sqler\SqlUtils          — static escaping / label helpers
```

---

## 1. Initialization

```php
use Ocallit\Sqler\SqlExecutor;
use Ocallit\Sqler\QueryBuilder;
use Ocallit\Sqler\DatabaseMetadata;
use Ocallit\Sqler\Historian;

$sql = new SqlExecutor(
    connect: [
        'hostname' => 'localhost',
        'username' => 'user',
        'password' => 'secret',
        'database' => 'mydb',
        // 'port'   => 3306,   // optional
    ],
    charset:   'utf8mb4',
    collation: 'utf8mb4_0900_ai_ci'
);

// Call once at application startup — caches schema metadata globally
DatabaseMetadata::initialize($sql);
$meta = DatabaseMetadata::getInstance();
```

---

## 2. Reading Data

Choose the method that matches the shape you need:

| Method | Returns | Use when |
|--------|---------|----------|
| `firstValue($q, $p, $default)` | scalar | COUNT, SUM, single cell |
| `row($q, $p, $default)` | `[col => val]` | fetch one record |
| `array($q, $p, $default)` | `[[col => val], ...]` | fetch all records |
| `arrayKeyed($q, $key, $p)` | `[keyVal => [col => val], ...]` | index rows by a column |
| `vector($q, $p)` | `[val, val, ...]` | single-column list |
| `keyValue($q, $p)` | `[col1 => col2, ...]` | two-column key→value map |
| `multiKey($q, $keys, $p)` | nested array | group by named columns |
| `multiKeyN($q, $n, $p)` | nested array | group by first N SELECT cols |
| `result($q, $p)` | `mysqli_result` | raw result (caller frees) |

```php
$count   = $sql->firstValue("SELECT COUNT(*) FROM orders WHERE status = ?", ['open']);
$order   = $sql->row("SELECT * FROM orders WHERE order_id = ?", [42]);
$orders  = $sql->array("SELECT * FROM orders WHERE customer_id = ?", [7]);
$byId    = $sql->arrayKeyed("SELECT * FROM orders", 'order_id');
$ids     = $sql->vector("SELECT order_id FROM orders WHERE status = ?", ['open']);
$totals  = $sql->keyValue("SELECT status, COUNT(*) FROM orders GROUP BY status");

// Nested grouping: ['IT' => ['Admin' => [row, ...], ...], ...]
$grouped = $sql->multiKey(
    "SELECT department, role, name, email FROM users",
    ['department', 'role']
);
```

---

## 3. INSERT

Always use `QueryBuilder` for inserts — it handles parameterization and MySQL
date/time functions safely.

```php
$qb = new QueryBuilder();   // pass false for MySQL < 8.0.19

$ins = $qb->insert(
    table:                   'orders',
    array:                   [
        'customer_id'  => 7,
        'status'       => 'open',
        'created_at'   => 'NOW()',   // MySQL function — not parameterized
        'amount'       => 99.90,
    ],
    onDuplicateKeyUpdate:    true,   // optional: ON DUPLICATE KEY UPDATE
    onDuplicateKeyDontUpdate:['created_at'],  // never overwrite these
);

$sql->query($ins['query'], $ins['parameters']);
$newId = $sql->last_insert_id();
```

**Magic MySQL functions** (passed as string values, not quoted):
`NOW()`, `NOW(6)`, `CURDATE()`, `CURTIME()`, `CURRENT_TIMESTAMP`,
`SYSDATE()`, `UTC_DATE()`, `UTC_TIME()`, `UTC_TIMESTAMP()`,
`UNIX_TIMESTAMP()`, `IA_UUID()`

---

## 4. UPDATE

```php
$upd = $qb->update(
    table: 'orders',
    array: ['status' => 'shipped', 'shipped_at' => 'NOW()'],
    where: ['order_id' => 42]
);
$sql->query($upd['query'], $upd['parameters']);
```

---

## 5. WHERE Clause Helper

```php
$w = $qb->where([
    'status'      => ['open', 'pending'],   // → status IN (?, ?)
    'created_at'  => 'CURDATE()',           // → created_at = CURDATE()
    'customer_id' => 7,                     // → customer_id = ?
], 'AND');

$rows = $sql->array(
    "SELECT * FROM orders WHERE {$w['query']}",
    $w['parameters']
);
```

---

## 6. Transactions

```php
// Simple: pass an array of SQL strings (retried 3× automatically)
$sql->transaction([
    "UPDATE accounts SET balance = balance - 100 WHERE account_id = 1",
    "UPDATE accounts SET balance = balance + 100 WHERE account_id = 2",
], 'fund transfer');

// Manual: use begin / commit / rollback
$sql->begin('checkout');
try {
    $sql->query("UPDATE inventory SET qty = qty - 1 WHERE sku = ?", ['ABC']);
    $sql->query("INSERT INTO sales (sku, qty) VALUES (?, ?)", ['ABC', 1]);
    $sql->commit('checkout');
} catch (Throwable $e) {
    $sql->rollback('checkout');
    throw $e;
}
```

---

## 7. Audit Trail (Historian)

`Historian` auto-creates a `{table}_hist` table and records every insert,
update, and delete with full diffs.

### Setup

```php
$historian = new Historian(
    sqlExecutor:            $sql,
    table:                  'orders',
    primaryKeyFieldNames:   ['order_id'],         // defaults to ["{table}_id"]
    ingoreDifferenceForFields: ['updated_at'],    // fields to skip in diff
);
```

### Recording Changes

```php
// INSERT: execute → select → register
$sql->query($ins['query'], $ins['parameters']);
$newRow = $sql->row("SELECT * FROM orders WHERE order_id = ?", [$sql->last_insert_id()]);
$historian->register('insert', ['order_id' => $newRow['order_id']], $newRow, $_SESSION['nick']);

// UPDATE: execute → select → register
$sql->query($upd['query'], $upd['parameters']);
$updatedRow = $sql->row("SELECT * FROM orders WHERE order_id = ?", [42]);
$historian->register('update', ['order_id' => 42], $updatedRow, $_SESSION['nick'], 'status change');

// DELETE: select → execute → register
$rowToDelete = $sql->row("SELECT * FROM orders WHERE order_id = ?", [42]);
$sql->query("DELETE FROM orders WHERE order_id = ?", [42]);
$historian->register('delete', ['order_id' => 42], $rowToDelete, $_SESSION['nick'], 'cancelled');
```

> **Pattern**: For INSERT/UPDATE fetch the row *after* the write so the historian
> captures the final state. For DELETE fetch *before* so the record exists.

### Reading History

```php
// Paginated history with field-level diffs
$changes = $historian->getChanges(
    primaryKeyValues: ['order_id' => 42],
    offset: 0,
    rows:   50
);

foreach ($changes as $c) {
    echo "{$c['action']} by {$c['user_nick']} on {$c['date']}\n";
    foreach ($c['diff'] as $field => $diff) {
        echo "  $field: '{$diff['before']}' → '{$diff['after']}'\n";
    }
}

// Last N changes
$recent = $historian->getNLastChanges(['order_id' => 42], 5);

// HTML table output
echo $historian->changesAsHTML($changes);
```

**Change record structure:**
```php
[
    'history_id' => 1234,
    'action'     => 'update',          // insert | update | delete
    'motive'     => 'status change',
    'date'       => '2026-06-25 14:30:00.000000',
    'user_nick'  => 'jsmith',
    'diff'       => [
        'status' => ['before' => 'open', 'after' => 'shipped'],
    ],
    'record'     => [/* full row snapshot */],
]
```

**Auto-ignored fields** (never included in diffs):
`ultimo_cambio`, `ultimo_cambio_por`, `last_changed`, `last_changed_by`,
`last_change`, `last_change_by`

---

## 8. Error Detection

After a failed query, use typed checks instead of parsing error messages:

```php
$sql->query("DELETE FROM customers WHERE customer_id = ?", [5]);

if ($sql->is_last_error_child_records_exist()) {
    // FK violation: child rows exist (1451)
} elseif ($sql->is_last_error_duplicate_key()) {
    // Unique/primary key conflict (1022, 1062)
} elseif ($sql->is_last_error_invalid_foreign_key()) {
    // FK target row missing (1216, 1452)
} elseif ($sql->is_last_error_table_not_found()) {
    // Table doesn't exist (1051, 1109, 1146)
} elseif ($sql->is_last_error_column_not_found()) {
    // Column doesn't exist (1054, 1063, 1166)
}

$errNo = $sql->getLastErrorNumber();
```

**Automatic retries** (transparent, no code needed):
- Deadlock (1213), lock timeout (1205), connection lost (2006, 2013)
- Only outside transactions; max 3 attempts, 50 ms between

---

## 9. Schema Introspection

```php
$meta = DatabaseMetadata::getInstance();

$cols    = $meta->table('orders');              // [colName => column metadata]
$pks     = $meta->primaryKeys();               // [table => [col => col]]
$fks     = $meta->getForeignKeys('orders');    // [col => ['referenced_table', ...]]
$allFKs  = $meta->foreignKeysAll();            // full relationship map
$checks  = $meta->getCheckConstraints('orders');
$uniques = $meta->uniqueIndexes('orders');

// ENUM/SET or FK lookup options (key → label)
$statuses = $meta->getColumnOptions('orders', 'status');
```

---

## 10. Static Utilities

```php
use Ocallit\Sqler\SqlUtils;

SqlUtils::fieldIt('users.email')       // `users`.`email`
SqlUtils::strIt("O'Brien")             // 'O''Brien'
SqlUtils::toLabel('order_created_at')  // "Order Created At"
```

---

## Quick Reference — Full CRUD with Audit

```php
$qb   = new QueryBuilder();
$hist = new Historian($sql, 'products', ['product_id']);
$user = $_SESSION['nick'] ?? 'system';

// CREATE
$ins = $qb->insert('products', ['name' => 'Widget', 'price' => 9.99, 'created_at' => 'NOW()']);
$sql->query($ins['query'], $ins['parameters']);
$id  = $sql->last_insert_id();
$row = $sql->row("SELECT * FROM products WHERE product_id = ?", [$id]);
$hist->register('insert', ['product_id' => $id], $row, $user);

// READ
$product  = $sql->row("SELECT * FROM products WHERE product_id = ?", [$id]);
$products = $sql->array("SELECT * FROM products WHERE active = ?", [1]);

// UPDATE
$upd = $qb->update('products', ['price' => 12.99, 'updated_at' => 'NOW()'], ['product_id' => $id]);
$sql->query($upd['query'], $upd['parameters']);
$row = $sql->row("SELECT * FROM products WHERE product_id = ?", [$id]);
$hist->register('update', ['product_id' => $id], $row, $user, 'price change');

// DELETE
$row = $sql->row("SELECT * FROM products WHERE product_id = ?", [$id]);
$sql->query("DELETE FROM products WHERE product_id = ?", [$id]);
$hist->register('delete', ['product_id' => $id], $row, $user, 'discontinued');

// HISTORY
$changes = $hist->getChanges(['product_id' => $id]);
```
