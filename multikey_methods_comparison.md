# SqlExecutor MultiKey Methods Comparison

## Summary Table

| Method | Key Specification | Value Structure | Primary Use Case |
|--------|------------------|-----------------|------------------|
| `multiKey()` | Explicit array of column names | Complete row (associative array) | Flexible hierarchical structures with named keys |
| `multiKeyN()` | First N columns | Complete row (associative array) | Quick hierarchical grouping without naming columns |
| `multiKeyLast()` | All but last column | Last column value (scalar) | Hierarchical lookup tables with primitive values |
| `multiKeyValue()` | All but last 2 columns | Array of last column values, keyed by next-to-last | Accumulating multiple values per key combination |

---

## Detailed Examples

### Sample Query Result
```sql
SELECT category, brand, size, color, price 
FROM products
```

**Result Set:**
```
Row 1: ['Electronics', 'Sony', 'Medium', 'Black', 299.99]
Row 2: ['Electronics', 'Sony', 'Medium', 'White', 319.99]
Row 3: ['Electronics', 'Sony', 'Large', 'Black', 399.99]
Row 4: ['Electronics', 'Samsung', 'Medium', 'Black', 279.99]
```

---

## 1. `multiKey(string $query, array $keys, array $parameters = [])`

### Characteristics
- **Key Control**: Explicit - you specify which columns are keys
- **Value**: Complete row as associative array (includes all columns)
- **Flexibility**: Highest - use any columns as keys in any order

### Example Usage
```php
$result = $sql->multiKey(
    "SELECT category, brand, size, color, price FROM products",
    ['category', 'brand', 'size']  // Explicit key columns
);
```

### Output Structure
```php
[
    'Electronics' => [
        'Sony' => [
            'Medium' => [
                'category' => 'Electronics',
                'brand' => 'Sony',
                'size' => 'Medium',
                'color' => 'Black',
                'price' => 299.99
            ],
            'Large' => [
                'category' => 'Electronics',
                'brand' => 'Sony',
                'size' => 'Large',
                'color' => 'Black',
                'price' => 399.99
            ]
        ],
        'Samsung' => [
            'Medium' => [
                'category' => 'Electronics',
                'brand' => 'Samsung',
                'size' => 'Medium',
                'color' => 'Black',
                'price' => 279.99
            ]
        ]
    ]
]
```

**Note**: Last matching row wins (row 2 overwrites row 1 in this structure)

---

## 2. `multiKeyN(string $query, int $numFields, array $parameters = [])`

### Characteristics
- **Key Control**: Implicit - first N columns become keys
- **Value**: Complete row as associative array
- **Flexibility**: Medium - simpler API, sequential column selection

### Example Usage
```php
$result = $sql->multiKeyN(
    "SELECT category, brand, size, color, price FROM products",
    2  // First 2 columns are keys
);
```

### Output Structure
```php
[
    'Electronics' => [
        'Sony' => [
            'category' => 'Electronics',
            'brand' => 'Sony',
            'size' => 'Medium',    // Last matching row
            'color' => 'White',
            'price' => 319.99
        ],
        'Samsung' => [
            'category' => 'Electronics',
            'brand' => 'Samsung',
            'size' => 'Medium',
            'color' => 'Black',
            'price' => 279.99
        ]
    ]
]
```

**Note**: Simpler than `multiKey()` when you want keys in column order

---

## 3. `multiKeyLast(string $query, array $parameters = [])`

### Characteristics
- **Key Control**: Automatic - all but last column are keys
- **Value**: Last column value only (scalar/primitive)
- **Flexibility**: Low - fixed pattern, optimized for lookup tables

### Example Usage
```php
// Note: Need to adjust SELECT to make sense for this method
$result = $sql->multiKeyLast(
    "SELECT category, brand, size, price FROM products"
);
```

### Output Structure
```php
[
    'Electronics' => [
        'Sony' => [
            'Medium' => [299.99, 319.99],  // Accumulates last column values
            'Large' => [399.99]
        ],
        'Samsung' => [
            'Medium' => [279.99]
        ]
    ]
]
```

**Note**: Values are accumulated in arrays - all matching rows are kept

---

## 4. `multiKeyValue(string $query, array $parameters = [])` ⭐ NEW

### Characteristics
- **Key Control**: Automatic - all but last 2 columns are structure keys, next-to-last is array key
- **Value**: Last column values accumulated in array, keyed by next-to-last column
- **Flexibility**: Medium - optimized for key-value pair accumulation

### Example Usage
```php
$result = $sql->multiKeyValue(
    "SELECT category, brand, color, price FROM products"
);
```

### Output Structure
```php
[
    'Electronics' => [
        'Sony' => [
            'Black' => [299.99, 399.99],   // Multiple prices for Black Sony
            'White' => [319.99]             // Single price for White Sony
        ],
        'Samsung' => [
            'Black' => [279.99]
        ]
    ]
]
```

**Key Benefit**: Perfect for creating lookup structures where you need to group by multiple dimensions and collect related values

---

## Use Case Decision Tree

```
Do you need complete row data?
├─ YES → Use multiKey() or multiKeyN()
│   └─ Do you need flexible key column selection?
│       ├─ YES → multiKey() - specify columns by name
│       └─ NO → multiKeyN() - use first N columns
│
└─ NO (only need specific values) →
    └─ How many value columns?
        ├─ ONE value column → multiKeyLast()
        └─ TWO columns (key-value pairs) → multiKeyValue()
```

---

## Performance Considerations

| Method | Memory Usage | Best For |
|--------|--------------|----------|
| `multiKey()` | High - stores complete rows | Small to medium datasets with complex hierarchies |
| `multiKeyN()` | High - stores complete rows | Small to medium datasets with simple hierarchies |
| `multiKeyLast()` | Low - stores only last column | Large datasets, simple lookups |
| `multiKeyValue()` | Medium - stores key-value pairs | Medium datasets, aggregated value collections |

---

## Common Patterns

### Configuration Lookup (multiKeyLast)
```php
// SELECT environment, setting_name, setting_value FROM config
$config = $sql->multiKeyLast("SELECT ...");
// Access: $config['production']['database_host'][0]
```

### Inventory by Location (multiKeyN)
```php
// SELECT warehouse, aisle, shelf, bin, product_id, quantity FROM inventory
$inventory = $sql->multiKeyN("SELECT ...", 4);
// Access: $inventory[$warehouse][$aisle][$shelf][$bin]['quantity']
```

### Price List with Options (multiKeyValue)
```php
// SELECT product_id, option_name, option_price FROM product_options
$options = $sql->multiKeyValue("SELECT ...");
// Access: $options[$product_id]['size'] = [10.00, 15.00, 20.00]
```

### Hierarchical Menu (multiKey)
```php
// SELECT menu_group, menu_item, url, icon, permission FROM menu_items
$menu = $sql->multiKey("SELECT ...", ['menu_group', 'menu_item']);
// Access: $menu['Admin']['Users']['permission']
```

---

## Important Notes

1. **Overwrites vs Accumulation**:
   - `multiKey()` and `multiKeyN()`: Last matching row **overwrites** previous
   - `multiKeyLast()` and `multiKeyValue()`: Values are **accumulated** in arrays

2. **Minimum Column Requirements**:
   - `multiKeyLast()`: Minimum 2 columns (1 key + 1 value)
   - `multiKeyValue()`: Minimum 2 columns (1 key + 1 value)
   - `multiKey()` and `multiKeyN()`: Minimum 1 column

3. **Query Design**:
   - Order your SELECT columns to match the key structure you want
   - Place the most general categories first (left) and most specific last (right)
   - For `multiKeyValue()`: structure keys → array key → array value

4. **Empty Results**:
   - All methods return `$default` parameter (empty array by default) if no results found
