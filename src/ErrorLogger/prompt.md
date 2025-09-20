Here's the perfect prompt for creating similar table interfaces:

---

**Database Table Interface Generator Prompt**

Create a complete database table interface with server-side operations using existing infrastructure:

**Requirements:**
- **Table**: `{table_name}` with primary key `{primary_key_field}`
- **Editable fields**: `{editable_field_1, editable_field_2}` (inline editing)
- **Default filter**: `{field} = '{value}'` 
- **Default sort**: `{field} DESC`
- **Backend integration**: Uses existing `../config/config.php` (provides `$gSqlExecutor`, auth, security)

**Architecture:**
- **Frontend**: Single HTML file using Tabulator.js 6.3 with server-side everything (`filterMode/sortMode/paginationMode: "remote"`)
- **Backend**: Framework-free PHP using global `$gSqlExecutor` and `QueryBuilder` from Ocallit\Sqler
- **Features**: All existing ocTabulator utilities (advanced filters, row selection, bulk ops, export)

**Leverage Existing Libraries:**
```html
<script src="ocTabulatorUtil.js"></script>           <!-- Advanced filters & natural sorting -->
<script src="ocTabulatorRowSelector.js"></script>    <!-- Checkboxes, bulk operations, export -->
<script src="tabulator_row_edit_class.js"></script>  <!-- Edit/save/cancel actions -->
<link rel="stylesheet" href="ocTabulator.css">       <!-- Styling -->
```

**Column Configuration:**
1. Row numbers (first column)
2. Actions column (second column) - edit/save/cancel from `TabulatorRowEditManager`  
3. Checkbox selection (third column) from `ocTabulatorRowSelector`
4. Data columns with appropriate filters:
   - String fields: `ocTabulatorUtil.headerFilterString`
   - Number fields: `ocTabulatorUtil.headerFilterNumber` 
   - Date fields: `ocTabulatorUtil.headerFilterYmdDate`
   - Enum/status fields: `headerFilter: "select"`
   - All with `headerFilterLiveFilter: false` for server-side filtering

**Backend Pattern:**
```php
require_once '../config/config.php';  // Provides $gSqlExecutor, auth, security
use Ocallit\Sqler\QueryBuilder;
$qb = new QueryBuilder();

// Handle: getData (with filters/sorting/pagination)
// Handle: update{Field} for each editable field
// Use: $gSqlExecutor->array(), ->firstValue() for queries
// Use: $qb->update() for safe updates
```

**Output 3 artifacts:**
1. **interface.html** - Complete HTML with Tabulator table using all ocTabulator features
2. **api.php** - Simple PHP backend with getData and update handlers
3. **setup-guide.md** - Installation steps and integration requirements

**Generate for table**: `{table_name}`

---

**Usage Examples:**

```
Generate for table: users
Primary key: user_id  
Editable fields: status, notes
Default filter: active = 1
Default sort: created_at DESC
```

```
Generate for table: orders
Primary key: order_id
Editable fields: status, priority, assigned_to
Default filter: status = 'pending'
Default sort: created_at DESC
```

This prompt will produce consistent, maintainable interfaces that integrate seamlessly with your existing config system and leverage all the ocTabulator utilities you've built.