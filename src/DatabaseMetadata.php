<?php
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;
use RuntimeException;

/**
 * DatabaseMetadata Quick Reference (Singleton):
 *
 * Setup:
 * - initialize(SqlExecutor $sql): void — call once at startup
 * - getInstance(): static
 * - clear(): void — flush cached metadata
 *
 * Schema inspection:
 * - table($table, $db = ''): [colName => [name, data_type, Type, default_value, is_nullable, ...]]
 * - primaryKeys(): [tableName => [colName => colName, ...]]
 * - getForeignKeys($table): [colName => [referenced_table, referenced_column]]
 * - foreignKeysAll(): [referencedBy => [...], references => [...], foreign_keys => [...]]
 * - getCheckConstraints($table): [[CONSTRAINT_NAME, CHECK_CLAUSE], ...]
 *
 * Query metadata:
 * - Call query($sql, $params=[]): [fieldName => [kind, type, data_type, unsigned, ...]]
 *
 * Options for UI:
 * - getColumnOptions($table, $col): [value => label, ...] — ENUM/SET or FK lookup
 */
// TABLE_COMMENT, table's data nature, table's structural role

/**
 * @phpstan-type DbCacheKey string
 * @phpstan-type TableName string
 * @phpstan-type ColumnName string
 * @phpstan-type IndexName string
 * @phpstan-type ConstraintName string
 *
 * @phpstan-type TableColumnMeta array{
 *   name: string,
 *   data_type: string,
 *   Type: string,
 *   default_value: mixed,
 *   is_nullable: string,
 *   character_maximum_length: int|string|null,
 *   character_octet_length: int|string|null,
 *   numeric_precision: int|string|null,
 *   numeric_scale: int|string|null,
 *   datetime_precision: int|string|null,
 *   decimals: int|string|null,
 *   column_comment: string|null,
 *   generation_expression: string|null,
 *   key_type: string|null,
 *   extra: string|null,
 *   character_set_name: string|null,
 *   collation_name: string|null,
 *   srs_id: int|string|null
 * }
 * @phpstan-type TableColumnMap array<ColumnName, TableColumnMeta>
 *
 * @phpstan-type PrimaryKeyMap array<ColumnName, ColumnName>
 * @phpstan-type PrimaryKeysByTable array<TableName, PrimaryKeyMap>
 *
 * @phpstan-type UniqueIndexMeta array{
 *   cols: list<ColumnName>,
 *   comment: string
 * }
 * @phpstan-type UniqueIndexMap array<IndexName, UniqueIndexMeta>
 * @phpstan-type UniqueIndexesByTable array<TableName, UniqueIndexMap>
 *
 * @phpstan-type ForeignKeyMeta array{
 *   referenced_table: TableName,
 *   referenced_column: ColumnName
 * }
 * @phpstan-type ForeignKeyMap array<ColumnName, ForeignKeyMeta>
 * @phpstan-type ForeignKeysByTable array<TableName, ForeignKeyMap>
 *
 * @phpstan-type CheckConstraintRow array{
 *   CONSTRAINT_NAME: ConstraintName,
 *   CHECK_CLAUSE: string
 * }
 * @phpstan-type CheckConstraintList list<CheckConstraintRow>
 * @phpstan-type CheckConstraintsByTable array<TableName, CheckConstraintList>
 *
 * @phpstan-type ForeignKeyConstraintMeta array{
 *   constraint_name: ConstraintName,
 *   on_delete_action: string,
 *   on_update_action: string,
 *   referencing_table: TableName,
 *   referencing_column: list<ColumnName>,
 *   referenced_table: TableName,
 *   referenced_column: list<ColumnName>
 * }
 * @phpstan-type ForeignKeyConstraintMap array<ConstraintName, ForeignKeyConstraintMeta>
 * @phpstan-type ForeignKeyRelationMap array<TableName, array<TableName, ForeignKeyConstraintMap>>
 * @phpstan-type ForeignKeysAllMeta array{
 *   referencedBy: ForeignKeyRelationMap,
 *   references: ForeignKeyRelationMap,
 *   foreign_keys: ForeignKeyConstraintMap
 * }
 *
 * @phpstan-type OptionMap array<array-key, string>
 *
 * @phpstan-type MysqliFieldArray array{
 *   name: string,
 *   orgname: string,
 *   table: string,
 *   orgtable: string,
 *   def: mixed,
 *   db: string,
 *   catalog: string,
 *   max_length: int,
 *   length: int,
 *   charsetnr: int,
 *   flags: int,
 *   type: int,
 *   decimals: int
 * }
 *
 * @phpstan-type QueryFieldMeta array{
 *   name: string,
 *   orgname: string,
 *   table: string,
 *   orgtable: string,
 *   def: mixed,
 *   db: string,
 *   catalog: string,
 *   max_length: int,
 *   length: int,
 *   charsetnr: int,
 *   flags: int,
 *   type: int,
 *   decimals: int,
 *   kind: 'table'|'derived'|'aggregate'|'constant',
 *   index: string,
 *   unsigned: bool,
 *   Type: string,
 *   data_type: string,
 *   default_value?: mixed,
 *   is_nullable?: string,
 *   character_maximum_length?: int|string|null,
 *   character_octet_length?: int|string|null,
 *   numeric_precision?: int|string|null,
 *   numeric_scale?: int|string|null,
 *   datetime_precision?: int|string|null,
 *   column_comment?: string|null,
 *   generation_expression?: string|null,
 *   key_type?: string|null,
 *   extra?: string|null,
 *   character_set_name?: string|null,
 *   collation_name?: string|null,
 *   srs_id?: int|string|null
 * }
 */
class DatabaseMetadata {
    protected static ?self $instance = NULL;
    protected SqlExecutor $sqlExecutor;

    /** @var array<DbCacheKey, array<TableName, TableColumnMap>> */
    protected array $tableColumns = [];
    /** @var array<DbCacheKey, PrimaryKeysByTable> */
    protected array $primaryKeys = [];
    /** Cache for unique indexes: [tableName => [indexName => ['cols' => [], 'comment' => '']]] */
    protected array $uniqueIndexes = [];

    /** @var array<DbCacheKey, ForeignKeysByTable> */
    protected array $foreignKeys = [];
    protected array $foreignKeysAll = [];
    protected array $foreignKeysDeduced = [];

    /** @var array<DbCacheKey, CheckConstraintsByTable> */
    protected array $checkConstraints = [];


    protected function __construct(SqlExecutor $sqlExecutor) {
        $this->sqlExecutor = $sqlExecutor;
    }

    public function clear(): void {
        $this->tableColumns = [];
        $this->primaryKeys = [];
        $this->uniqueIndexes = [];
        $this->foreignKeys = [];
        $this->checkConstraints = [];
    }

    public static function initialize(SqlExecutor $sql): void {
        if(static::$instance !== NULL)
            throw new RuntimeException(static::class . ' is already initialized.');
        static::$instance = new static($sql);
    }

    public static function getInstance(): static {
        if(static::$instance === NULL)
            throw new RuntimeException(static::class . ' must be initialized first. Call ' . static::class . '::initialize().');
        return static::$instance;
    }

    /**
     * @param string $database, empty the current database
     * @return PrimaryKeysByTable array<string TableName, array<primaryKeyColumn, primaryKeyColumn>>
     * @throws Exception
     */
    public function primaryKeys(string $database = ""): array {
        $dbName = empty($database) ? "DATABASE()" : SqlUtils::strIt($database);
        if(empty($this->primaryKeys[$dbName])) {
            $sql = "
            SELECT /*" . __METHOD__ . "*/ c.TABLE_NAME, c.COLUMN_NAME
            FROM  information_schema.KEY_COLUMN_USAGE c
            WHERE c.TABLE_SCHEMA = $dbName AND c.CONSTRAINT_NAME = 'PRIMARY'
            ";
            foreach($this->sqlExecutor->array($sql) as $d)
                $this->primaryKeys[$dbName][$d['TABLE_NAME']][$d['COLUMN_NAME']] = $d['COLUMN_NAME'];
        }
        return $this->primaryKeys[$dbName];
    }

    /**
     * Get unique indexes for a table or all tables in the database.
     * @param string $tableName Optional. If empty, returns unique indexes for all tables.
     * @return UniqueIndexesByTable|UniqueIndexMap array [tableName => [indexName => ['cols' => [col1, col2],  'comment' => '']]]
     * @phpstan-return ($tableName is non-empty-string ? UniqueIndexMap : UniqueIndexesByTable)
     * @throws Exception
     */
    public function uniqueIndexes(string $tableName = "", string $database = ""): array {
        $dbName = empty($database) ? "DATABASE()" : SqlUtils::strIt($database);
        if(empty($this->uniqueIndexes[$dbName])) {
            $method = __METHOD__;
            $sql = "SELECT /*$method*/ 
                        TABLE_NAME, 
                        INDEX_NAME, 
                        COLUMN_NAME, 
                        INDEX_COMMENT
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = $dbName 
                      AND NON_UNIQUE = 0 
                      AND INDEX_NAME != 'PRIMARY'
                    ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX";

            foreach($this->sqlExecutor->array($sql) as $row) {
                $tbl = $row['TABLE_NAME'];
                $idx = $row['INDEX_NAME'];

                if(!isset($this->uniqueIndexes[$dbName][$tbl][$idx])) {
                    $this->uniqueIndexes[$dbName][$tbl][$idx] = [
                      'cols' => [],
                      'comment' => $row['INDEX_COMMENT'] ?? '',
                    ];
                }
                $this->uniqueIndexes[$dbName][$tbl][$idx]['cols'][] = $row['COLUMN_NAME'];
            }
        }

        if(!empty($tableName)) {
            return $this->uniqueIndexes[$dbName][$tableName] ?? [];
        }

        return $this->uniqueIndexes[$dbName];
    }


    /**
     * Get the column metadata for one table, keyed by the column name.
     *
     * @param string $tableName
     * @param string $database, empty the current database
     * @return TableColumnMap
     * @throws Exception
     */
    public function table(string $tableName, string $database = ""): array {
        if(empty($tableName))
            return [];
        $dbName = empty($database) ? "DATABASE()" : SqlUtils::strIt($database);
        if(!isset($this->tableColumns[$dbName][$tableName])) {
            $method = __METHOD__;
            $this->tableColumns[$dbName][$tableName] = $this->sqlExecutor->arrayKeyed(
              "SELECT /*$method*/
                        COLUMN_NAME AS name,
                        data_type,
                        COLUMN_TYPE AS Type, 
                        COLUMN_DEFAULT AS default_value,
                        is_nullable,
                        character_maximum_length,
                        character_octet_length,
                        numeric_precision,
                        numeric_scale,
                        datetime_precision,
                        IFNULL(numeric_scale, datetime_precision) as decimals,
                        column_comment,
                        generation_expression,
                        COLUMN_KEY AS key_type,
                        extra,
                        character_set_name,
                        collation_name,
                        srs_id
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = $dbName AND TABLE_NAME = ?
                    ORDER BY ORDINAL_POSITION", "name", [$tableName]
            );
        }
        return $this->tableColumns[$dbName][$tableName];
    }

    /**
     * Return only generated columns from table() metadata.
     *
     * The original column-name keys and ordinal order are preserved.
     *
     * @param TableColumnMap $columns Output from table()
     * @return TableColumnMap
     */
    public function filterGeneratedColumns(array $columns): array {
        return array_filter(
          $columns,
          static fn(array $column): bool => !empty($column['generation_expression'] ?? '')
        );
    }

    /**
     * Return only primary-key columns from table() metadata.
     *
     * Composite primary keys return all their columns.
     * The original column-name keys and ordinal order are preserved.
     *
     * @param TableColumnMap $columns Output from table()
     * @return TableColumnMap
     */
    public function filterPrimaryKeyColumns(array $columns): array {
        return array_filter(
          $columns,
          static fn(array $column): bool =>
            ($column['key_type'] ?? '') === 'PRI'
        );
    }

    /**
     * Get result-set metadata for a query, enriched with table column metadata when available.
     *
     * @param string $query
     * @param array<array-key, mixed> $parameters
     * @param string $database, empty the current database
     * @return array<string, QueryFieldMeta>
     * @throws Exception
     */
    public function query(string $query, array $parameters = [], string $database = ""): array {
        $mysqliResult = $this->sqlExecutor->result($query, $parameters);
        if(!$mysqliResult) {
            throw new Exception("Failed to get result metadata");
        }

        $metadata = [];
        $fields = mysqli_fetch_fields($mysqliResult);

        foreach($fields as $field) {
            $orgDatabase = $database; //@Todo
            $tableColumns = !empty($field->orgtable) ? $this->table($field->orgtable, $orgDatabase) : [];
            $tableColumns = array_combine(array_column($tableColumns, 'name'), $tableColumns);

            $kind = 'derived';
            $isAggregate = (bool)($field->flags & MYSQLI_GROUP_FLAG);
            if(!empty($field->orgtable)) {
                if(isset($tableColumns[$field->orgname])) {
                    $kind = 'table';
                }
            } elseif($isAggregate) {
                $kind = 'aggregate';
            } elseif(empty($field->orgtable) && empty($field->orgname)) {
                $kind = 'constant';
            }

            // Build field metadata with the correct precedence using array_merge
            $metadata[$field->name] = array_merge(
              $tableColumns[$field->orgname] ?? [],
              (array)$field,
              [
                'kind' => $kind,
                'index' => empty($field->table) ? $field->name : $field->table . '.' . $field->orgname,
                'unsigned' => (bool)($field->flags & MYSQLI_UNSIGNED_FLAG),
                'Type' => isset($tableColumns[$field->orgname]) ? $tableColumns[$field->orgname]['Type'] : $this->getType($field),
                'data_type' => isset($tableColumns[$field->orgname]) ? $tableColumns[$field->orgname]['data_type'] : $this->getBaseType($field->type),
              ]
            );
        }

        return $metadata;
    }


    /**
     * Get foreign key metadata for one table, keyed by the local column name.
     *
     * @param string $tableName
     * @param string $database, empty the current database
     * @return ForeignKeyMap [column_name => ['referenced_table' => string, 'referenced_column' => string]]
     * @throws Exception
     */
    public function getForeignKeys(string $tableName, string $database = ""): array {
        if(empty($tableName)) {
            return [];
        }
        $dbName = empty($database) ? "DATABASE()" : SqlUtils::strIt($database);

        if(!isset($this->foreignKeys[$dbName][$tableName])) {
            $sql = "SELECT /*" . __METHOD__ . "*/ 
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE kcu
                WHERE kcu.TABLE_SCHEMA = $dbName
                    AND kcu.TABLE_NAME = ?
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL";

            $fks = [];
            foreach($this->sqlExecutor->array($sql, [$tableName]) as $row) {
                $fks[$row['COLUMN_NAME']] = [
                  'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                  'referenced_column' => $row['REFERENCED_COLUMN_NAME'],
                ];
            }
            $this->foreignKeys[$dbName][$tableName] = $fks;
        }

        return $this->foreignKeys[$dbName][$tableName];
    }

    /**
     * Get all foreign key relationships in the selected database, grouped in multiple navigation maps.
     *
     * @param string $database, empty the current database
     * @return ForeignKeysAllMeta ['referencedBy' => $children, 'references' => $parents, 'foreign_keys' => $foreignKeys]
     * @throws Exception
     */
    public function foreignKeysAll(string $database = ""): array {
        $dbName = empty($database) ? "DATABASE()" : SqlUtils::strIt($database);
        if(!empty($this->foreignKeysAll[$dbName]))
            return $this->foreignKeysAll[$dbName];
        $parents = [];
        $children = [];
        $foreignKeys = [];
        $method = __METHOD__;
        $sql = "
        SELECT /*$method*/
            tc.CONSTRAINT_NAME,
            tc.TABLE_NAME AS referencing_table,
            kcu.COLUMN_NAME AS referencing_column,
            kcu.REFERENCED_TABLE_NAME AS referenced_table,
            kcu.REFERENCED_COLUMN_NAME AS referenced_column,
            rc.UPDATE_RULE AS on_update_action,
            rc.DELETE_RULE AS on_delete_action
        FROM
            INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc ON tc.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND tc.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
        WHERE
            tc.CONSTRAINT_TYPE = 'FOREIGN KEY' AND tc.TABLE_SCHEMA = $dbName
        ORDER BY tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION";

        $data = $this->sqlExecutor->array($sql);
        foreach($data as $row) {
            $constraintName = $row['CONSTRAINT_NAME'];
            $parentTable = $row['referencing_table'];
            $childTable = $row['referenced_table'];

            if(!array_key_exists($constraintName, $foreignKeys)) {
                $children[$childTable][$parentTable][$constraintName] =
                $parents[$parentTable][$childTable][$constraintName] =
                $foreignKeys[$constraintName] = [
                  'constraint_name' => $constraintName,
                  'on_delete_action' => $row['on_delete_action'],
                  'on_update_action' => $row['on_update_action'],

                  'referencing_table' => $row['referencing_table'],
                  'referencing_column' => [$row['referencing_column']],

                  'referenced_table' => $row['referenced_table'],
                  'referenced_column' => [$row['referenced_column']],

                ];
                continue;
            }

            $parents[$parentTable][$childTable][$constraintName]['referencing_column'][] =
            $children[$childTable][$parentTable][$constraintName]['referencing_column'][] =
            $foreignKeys[$constraintName]['referencing_column'][] =
              $row['referencing_column'];

            $parents[$parentTable][$childTable][$constraintName]['referenced_column'][] =
            $children[$childTable][$parentTable][$constraintName]['referenced_column'][] =
            $foreignKeys[$constraintName]['referenced_column'][] =
              $row['referenced_column'];
        }
        return $this->foreignKeysAll[$dbName] = ['referencedBy' => $children, 'references' => $parents, 'foreign_keys' => $foreignKeys];
    }

    /**
     * foreign by naming columns: a column named {tableName}_id is a) in the table with the same name the primary key
     *    (single column primary key) or b) in another table a, single column, foreign key to tableName Audit report
     * list all names that according to the naming conventions should be a foreign key so a code review may check if
     *    there is a foreign key constraint declared and if not why not
     * 
     * @param string $database, empty the current database, empty the current database
     * @return array<tableName:[columnName: [referenced_table_exists => 'Yes' | 'No', has_foreign_key_constraint => 'Yes' | 'No', existing_constraint_name => string, ...]]>
     */
    public function foreignKeysNamed(string $database = ""):array {
        $dbName = empty($database) ? "DATABASE()" : SqlUtils::strIt($database);
        if(!empty($this->foreignKeysDeduced[$dbName]))
            return $this->foreignKeysDeduced[$dbName];
        $sql = "
            SELECT
                c.TABLE_NAME AS 'table_name',
                c.COLUMN_NAME AS 'column_name',
                LEFT(c.COLUMN_NAME, CHAR_LENGTH(c.COLUMN_NAME) - 3) AS 'referenced_table',
                IF(expected.TABLE_NAME IS NULL, 'No', 'Yes') AS 'referenced_table_exists',
                IF(kcu.CONSTRAINT_NAME IS NULL, 'No', 'Yes') AS 'has_foreign_key_constraint',
                kcu.CONSTRAINT_NAME AS 'existing_constraint_name'
            FROM information_schema.COLUMNS c
            -- 1. Ensure we only audit actual tables, not views
                     JOIN information_schema.TABLES parent_table
                          ON c.TABLE_NAME = parent_table.TABLE_NAME
                              AND c.TABLE_SCHEMA = parent_table.TABLE_SCHEMA
                              AND parent_table.TABLE_TYPE = 'BASE TABLE'
            -- 2. See if a table exists that matches the expected naming convention
                     LEFT JOIN information_schema.TABLES expected
                               ON expected.TABLE_NAME = LEFT(c.COLUMN_NAME, CHAR_LENGTH(c.COLUMN_NAME) - 3)
                                   AND expected.TABLE_SCHEMA = c.TABLE_SCHEMA
            -- 3. Check if an actual foreign key constraint exists for this specific column
                     LEFT JOIN information_schema.KEY_COLUMN_USAGE kcu
                               ON c.TABLE_NAME = kcu.TABLE_NAME
                                   AND c.COLUMN_NAME = kcu.COLUMN_NAME
                                   AND c.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                                   AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            WHERE c.TABLE_SCHEMA = $dbName
              AND c.COLUMN_NAME LIKE '%\_id'
              -- 4. Exclude the primary key of the table itself
              AND c.TABLE_NAME <> LEFT(c.COLUMN_NAME, CHAR_LENGTH(c.COLUMN_NAME) - 3);
        ";
        return $this->foreignKeysDeduced[$dbName] = $this->sqlExecutor->multiKey($sql,['table_name','column_name']);
    }

    /**
     * Get check constraints for a table, indexed by column name.
     * For each column, lists the constraints that reference it, with constraint_name => check_clause.
     * Constraints may reference multiple columns; they will appear under each referenced column.
     *
     * @param string $tableName
     * @param string $database, empty the current database
     * @return CheckConstraintList array [column_name => [constraint_name => check_clause, ...]]
     */
    public function getCheckConstraints(string $tableName, string $database = ""): array {
        if(empty($tableName)) {
            return [];
        }
        $dbName = empty($database) ? "DATABASE()" : SqlUtils::strIt($database);

        if(!isset($this->checkConstraints[$dbName][$tableName])) {
            $sql = "SELECT /*" . __METHOD__ . "*/ tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
                FROM information_schema.TABLE_CONSTRAINTS tc
                JOIN information_schema.CHECK_CONSTRAINTS cc 
                    ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA 
                    AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                WHERE tc.TABLE_SCHEMA = $dbName
                    AND tc.TABLE_NAME = ?
                    AND tc.CONSTRAINT_TYPE = 'CHECK'";
            $this->checkConstraints[$dbName][$tableName] = $this->sqlExecutor->array($sql, [$tableName]);
        }
        return $this->checkConstraints[$dbName][$tableName];
    }

    /**
     * Get options for a column - either ENUM/SET values or foreign key lookups
     * Returns [value => label, ...] Sorted by label
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $database, empty the current database
     * @return OptionMap array [value => label, ...]
     * @throws Exception
     */
    public function getColumnOptions(string $tableName, string $columnName, string $database = ""): array {
        if(empty($tableName) || empty($columnName))
            return [];

        $columns = $this->table($tableName, $database);
        if(!isset($columns[$columnName]))
            return [];

        $column = $columns[$columnName];
        $dataType = strtolower($column['data_type'] ?? '');

        // Handle ENUM or SET
        if($dataType === 'enum' || $dataType === 'set')
            return $this->parseEnumSetOptions($column['Type'] ?? '');

        // Check for the foreign keys
        $foreignKeys = $this->getForeignKeys($tableName, $database);
        if(!isset($foreignKeys[$columnName]))
            return [];

        $fk = $foreignKeys[$columnName];
        return $this->fetchForeignKeyOptions($fk['referenced_table'], $fk['referenced_column']);
    }

    /**
     * Parse ENUM or SET type string to extract options
     * @param string $type e.g., "enum('Yes','No')" or "set('a','b','c')"
     * @return OptionMap array [value => value, ...]
     */
    public function parseEnumSetOptions(string $type): array {
        if(!preg_match("/^(?:enum|set)\s*\((.+)\)\s*$/i", $type, $matches))
            return [];

        $options = [];
        preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $matches[1], $valueMatches);
        foreach($valueMatches[1] as $value) {
            $unescaped = str_replace(["''", "\\'"], "'", $value);
            $options[$unescaped] = $unescaped;
        }
        return $options;
    }

    /**
     * Get id => label options from a foreign key referenced table
     *  Label determined by:
     *  1. If PK is string (not UUID) - use PK as the label, or if only one column
     *  2. If PK is autoincrement/UUID - first varchar/char column, else column after PK
     * Load id => label options from a referenced table used by a foreign key.
     *
     * @param string $referencedTable
     * @param string $referencedColumn
     * @param string $database, empty the current database
     * @return OptionMap [id => label, ...] sorted by label
     * @throws Exception
     */
    protected function fetchForeignKeyOptions(string $referencedTable, string $referencedColumn, string $database = ""): array {
        $columns = $this->table($referencedTable, $database);
        if(empty($columns))
            return [];

        // Only one column - use it as both id and label
        if(count($columns) === 1)
            return $this->fetchOptionsFromTable($referencedTable, $referencedColumn, $referencedColumn);

        $labelColumn = $this->determineLabelColumn($columns, $referencedColumn);
        return $this->fetchOptionsFromTable($referencedTable, $referencedColumn, $labelColumn);
    }

    /**
     * Choose the label column for a table.
     *
     * @param TableColumnMap $columns
     * @param string $pkColumn
     * @return string
     */
    protected function determineLabelColumn(array $columns, string $pkColumn): string {
        $pk = $columns[$pkColumn] ?? NULL;
        if(!$pk)
            return $pkColumn;

        $columnNames = array_keys($columns);
        $dataType = strtolower($pk['data_type'] ?? '');

        $isStringPK = in_array($dataType, ['char', 'varchar'], TRUE);
        $charLength = (int)($pk['character_maximum_length'] ?? 0);
        $isUUID = $isStringPK && ($charLength === 36 || $charLength === 32);

        // If PK is string and not UUID, use PK as the label
        if($isStringPK && !$isUUID)
            return $pkColumn;

        // Find the first varchar/char column (excluding PK)
        foreach($columns as $name => $col) {
            if($name === $pkColumn)
                continue;
            $colType = strtolower($col['data_type'] ?? '');
            if($colType === 'char' || $colType === 'varchar')
                return $name;
        }

        // Fallback: column after PK
        $pkIndex = array_search($pkColumn, $columnNames, TRUE);
        if($pkIndex !== FALSE && isset($columnNames[$pkIndex + 1]))
            return $columnNames[$pkIndex + 1];

        return $pkColumn;
    }

    /**
     * Fetch options from the table sorted by label
     * Fetch an id => label map from one table.
     *
     * @param string $table
     * @param string $idColumn
     * @param string $labelColumn
     * @return OptionMap
     */
    protected function fetchOptionsFromTable(string $table, string $idColumn, string $labelColumn): array {
        $idCol = SqlUtils::fieldIt($idColumn);
        $labelCol = SqlUtils::fieldIt($labelColumn);
        $tbl = SqlUtils::fieldIt($table);

        $sql = "SELECT /*" . __METHOD__ . "*/ $idCol, $labelCol FROM $tbl ORDER BY $labelCol";
        return $this->sqlExecutor->keyValue($sql);
    }


    /**
     * field class to mysql data type string, in lower case
     * Convert a mysqli field descriptor into a MySQL type string.
     *
     * @pure
     * @param object{
          *   type: int,
          *   flags: int,
          *   length: int,
          *   decimals: int,
          *   orgtable: string,
          *   orgname: string
          * } $field
     * @return string
     * @throws Exception
     */
    protected function getType(object $field): string {
        $baseType = $this->getBaseType($field->type);

        if($field->flags & MYSQLI_UNSIGNED_FLAG) {
            $baseType .= ' unsigned';
        }

        switch($field->type) {
            case MYSQLI_TYPE_DECIMAL:
            case MYSQLI_TYPE_NEWDECIMAL:
                $baseType .= "($field->length,$field->decimals)";
                break;

            case MYSQLI_TYPE_VAR_STRING:
            case MYSQLI_TYPE_STRING:
                if($field->flags & MYSQLI_ENUM_FLAG) {
                    if(!empty($field->orgtable) && !empty($field->orgname)) {
                        $tableColumns = $this->table($field->orgtable); //@ToDo org.database
                        if(isset($tableColumns[$field->orgname])) {
                            $baseType = $tableColumns[$field->orgname]['Type'];
                        }
                    }
                }
                break;
        }

        return $baseType;
    }

    /**
     * Get base data_type from mysqli type id
     * Convert a mysqli numeric type id into a lower-case MySQL base type string.
     *
     * @param int $typeId
     * @return string
     */
    protected function getBaseType(int $typeId): string {
        $mysqliTypes = [
          MYSQLI_TYPE_TINY => 'tinyint',
          MYSQLI_TYPE_SHORT => 'smallint',
          MYSQLI_TYPE_LONG => 'int',
          MYSQLI_TYPE_FLOAT => 'float',
          MYSQLI_TYPE_DOUBLE => 'double',
          MYSQLI_TYPE_TIMESTAMP => 'timestamp',
          MYSQLI_TYPE_LONGLONG => 'bigint',
          MYSQLI_TYPE_INT24 => 'mediumint',
          MYSQLI_TYPE_DATE => 'date',
          MYSQLI_TYPE_TIME => 'time',
          MYSQLI_TYPE_DATETIME => 'datetime',
          MYSQLI_TYPE_YEAR => 'year',
          MYSQLI_TYPE_NEWDATE => 'date',
          MYSQLI_TYPE_ENUM => 'enum',
          MYSQLI_TYPE_SET => 'set',
          MYSQLI_TYPE_TINY_BLOB => 'tinyblob',
          MYSQLI_TYPE_MEDIUM_BLOB => 'mediumblob',
          MYSQLI_TYPE_LONG_BLOB => 'longblob',
          MYSQLI_TYPE_BLOB => 'blob',
          MYSQLI_TYPE_VAR_STRING => 'varchar',
          MYSQLI_TYPE_STRING => 'char',
          MYSQLI_TYPE_DECIMAL => 'decimal',
          MYSQLI_TYPE_NEWDECIMAL => 'decimal',
          MYSQLI_TYPE_JSON => 'json',
          MYSQLI_TYPE_GEOMETRY => 'geometry',
          MYSQLI_TYPE_BIT => 'bit',
        ];
        return $mysqliTypes[$typeId] ?? 'unknown';
    }

}
