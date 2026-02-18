<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;
use RuntimeException;

/**
 * DatabaseMetadata Quick Reference (Singleton):
 *
 * Setup:
 * - initialize(SqlExecutor $sql): void  — call once at startup
 * - getInstance(): static
 * - clear(): void                       — flush cached metadata
 *
 * Schema inspection:
 * - table($table, $db=''): [colName => [name, data_type, Type, default_value, is_nullable, ...]]
 * - primaryKeys(): [tableName => [colName => colName, ...]]
 * - getForeignKeys($table): [colName => [referenced_table, referenced_column]]
 * - foreignKeysAll(): [referencedBy => [...], references => [...], foreign_keys => [...]]
 * - getCheckConstraints($table): [[CONSTRAINT_NAME, CHECK_CLAUSE], ...]
 *
 * Query metadata:
 * - query($sql, $params=[]): [fieldName => [kind, Type, data_type, unsigned, ...]]
 *
 * Options for UI:
 * - getColumnOptions($table, $col): [value => label, ...]  — ENUM/SET or FK lookup
 */

/**
 * DatabaseMetadata 0.0.2
 *
 */
class DatabaseMetadata {
    protected static ?self $instance = null;
    protected SqlExecutor $sqlExecutor;
    protected array $tableColumns = [];
    protected array $primaryKeys = [];
    protected array $foreignKeys = [];
    protected array $checkConstraints = [];

    protected function __construct(SqlExecutor $sqlExecutor) {$this->sqlExecutor = $sqlExecutor;}

    public static function initialize(SqlExecutor $sql): void {
        if(static::$instance !== null)
            throw new RuntimeException(static::class . ' is already initialized.');
        static::$instance = new static($sql);
    }

    public static function getInstance(): static {
        if(static::$instance === null)
            throw new RuntimeException(static::class . ' must be initialized first. Call ' . static::class . '::initialize().');
        return static::$instance;
    }

    public function primaryKeys(): array {
        if(empty($this->primaryKeys)) {
            $sql = "SELECT /*" . __METHOD__ . "*/ t.TABLE_NAME, c.COLUMN_NAME
                FROM information_schema.TABLES t
                    JOIN information_schema.KEY_COLUMN_USAGE c ON t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_SCHEMA = c.TABLE_SCHEMA
                WHERE t.TABLE_SCHEMA = DATABASE() AND c.CONSTRAINT_NAME = 'PRIMARY'";
            foreach($this->sqlExecutor->array($sql) as $d)
                $this->primaryKeys[$d['TABLE_NAME']][$d['COLUMN_NAME']] = $d['COLUMN_NAME'];
        }
        return $this->primaryKeys;
    }

    /**
     * @return array<string, array{
     *      Field: string,
     *      Type: string,
     *      Collation: null|string,
     *      Null: 'YES'|'NO',
     *      Key: 'PRI'|'UNI'|'MUL'|null,
     *      Default: mixed,
     *      Extra: string,
     *      Privileges: string,
     *      Comment: string
     *  }>
     *  Privileges: Column privileges (select,insert,update,references)
     * extra auto_increment, VIRTUAL GENERATED
     * @throws Exception
     */
    public function table(string $tableName, string $database = ""): array {
        if(empty($tableName))
            return [];
        $dbName = empty($database) ? "DATABASE()" : SqlUtils::fieldIt($database);
        if(!isset($this->tableColumns[$dbName][$tableName]))
            $this->tableColumns[$dbName][$tableName] =
             $this->sqlExecutor->arrayKeyed(
        "SELECT
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
        return $this->tableColumns[$dbName][$tableName];
    }

    /**
     * @param string $query
     * @param array $parameters
     * @return array
     * @throws Exception
     */
    public function query(string $query, array $parameters = []): array {
        $mysqliResult = $this->sqlExecutor->result($query, $parameters);
        if(!$mysqliResult) {
            throw new Exception("Failed to get result metadata");
        }

        $metadata = [];
        $fields = mysqli_fetch_fields($mysqliResult);

        foreach($fields as $field) {

            $tableColumns = !empty($field->orgtable) ? $this->table($field->orgtable) : [];
            $tableColumns = array_combine(array_column($tableColumns, 'Field'), $tableColumns);

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

            // Build field metadata with correct precedence using array_merge
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

    public function clear(): void {
        $this->primaryKeys = [];
        $this->tableColumns = [];
        $this->foreignKeys = [];
        $this->checkConstraints = [];
    }

    /**
     * Get foreign key constraints for a table as [column_name => ['referenced_table' => string, 'referenced_column' => string]]
     *
     * @param string $tableName
     * @return array [column_name => ['referenced_table' => string, 'referenced_column' => string]]
     * @throws Exception
     */
    public function getForeignKeys(string $tableName): array {
        if (empty($tableName)) {
            return [];
        }

        if (!isset($this->foreignKeys[$tableName])) {
            $sql = "SELECT /*" . __METHOD__ . "*/ 
                    kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE kcu
                WHERE kcu.TABLE_SCHEMA = DATABASE()
                    AND kcu.TABLE_NAME = ?
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL";

            $fks = [];
            foreach ($this->sqlExecutor->array($sql, [$tableName]) as $row) {
                $fks[$row['COLUMN_NAME']] = [
                  'referenced_table' => $row['REFERENCED_TABLE_NAME'],
                  'referenced_column' => $row['REFERENCED_COLUMN_NAME']
                ];
            }
            $this->foreignKeys[$tableName] = $fks;
        }

        return $this->foreignKeys[$tableName];
    }

    public function foreignKeysAll(): array {
        $parents =[];
        $childs = [];
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
            tc.CONSTRAINT_TYPE = 'FOREIGN KEY' AND tc.TABLE_SCHEMA = DATABASE()
        ORDER BY tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION";

        $data = $this->sqlExecutor->array($sql);
        foreach($data as $row) {
            $constraintName = $row['CONSTRAINT_NAME'];
            $parentTable = $row['referencing_table'];
            $chidTable = $row['referenced_table'];

            if(!array_key_exists($constraintName, $foreignKeys)) {
                $childs[$chidTable][$parentTable][$constraintName] =
                $parents[$parentTable][$chidTable][$constraintName] =
                $foreignKeys[$constraintName] = [
                  'constraint_name'=>$constraintName,
                  'on_delete_action'=>$row['on_delete_action'],
                  'on_update_action'=>$row['on_update_action'],

                  'referencing_table'=>$row['referencing_table'],
                  'referencing_column'=>[$row['referencing_column']],

                  'referenced_table'=>$row['referenced_table'],
                  'referenced_column'=>[$row['referenced_column']],

                ];
                continue;
            }

            $parents[$parentTable][$chidTable][$constraintName]['referencing_column'][] =
            $childs[$chidTable][$parentTable][$constraintName]['referencing_column'][] =
            $foreignKeys[$constraintName]['referencing_column'][] =
              $row['referencing_column'];

            $parents[$parentTable][$chidTable][$constraintName]['referenced_column'][] =
            $childs[$chidTable][$parentTable][$constraintName]['referenced_column'][] =
            $foreignKeys[$constraintName]['referenced_column'][] =
              $row['referenced_column'];
        }
        return ['referencedBy' => $childs, 'references' => $parents, 'foreign_keys' => $foreignKeys];
    }

    /**
     * Get check constraints for a table, indexed by column name.
     * For each column, lists the constraints that reference it, with constraint_name => check_clause.
     * Constraints may reference multiple columns; they will appear under each referenced column.
     *
     * @param string $tableName
     * @return array [column_name => [constraint_name => check_clause, ...]]
     * @throws Exception
     */
    public function getCheckConstraints(string $tableName): array {
        if (empty($tableName)) {
            return [];
        }

        if (!isset($this->checkConstraints[$tableName])) {
            $sql = "SELECT /*" . __METHOD__ . "*/ tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
                FROM information_schema.TABLE_CONSTRAINTS tc
                JOIN information_schema.CHECK_CONSTRAINTS cc 
                    ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA 
                    AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                WHERE tc.TABLE_SCHEMA = DATABASE()
                    AND tc.TABLE_NAME = ?
                    AND tc.CONSTRAINT_TYPE = 'CHECK'";
            $this->checkConstraints[$tableName] = $this->sqlExecutor->array($sql, [$tableName]);
        }
        return $this->checkConstraints[$tableName];
    }



    /**
     * Get options for a column - either ENUM/SET values or foreign key lookups
     * Returns [value => label, ...] sorted by label
     *
     * @param string $tableName
     * @param string $columnName
     * @return array [value => label, ...]
     * @throws Exception
     */
    public function getColumnOptions(string $tableName, string $columnName): array {
        if(empty($tableName) || empty($columnName))
            return [];

        $columns = $this->table($tableName);
        if(!isset($columns[$columnName]))
            return [];

        $column = $columns[$columnName];
        $dataType = strtolower($column['data_type'] ?? '');

        // Handle ENUM or SET
        if($dataType === 'enum' || $dataType === 'set')
            return $this->parseEnumSetOptions($column['Type'] ?? '');

        // Check for foreign key
        $foreignKeys = $this->getForeignKeys($tableName);
        if(!isset($foreignKeys[$columnName]))
            return [];

        $fk = $foreignKeys[$columnName];
        return $this->fetchForeignKeyOptions($fk['referenced_table'], $fk['referenced_column']);
    }

    /**
     * Parse ENUM or SET type string to extract options
     * @param string $type e.g., "enum('Yes','No')" or "set('a','b','c')"
     * @return array [value => value, ...]
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
     * Label determined by:
     * 1. If PK is string (not UUID) - use PK as label, or if only one column
     * 2. If PK is autoincrement/UUID - first varchar/char column, else column after PK
     *
     * @param string $referencedTable
     * @param string $referencedColumn
     * @return array [id => label, ...] sorted by label
     * @throws Exception
     */
    protected function fetchForeignKeyOptions(string $referencedTable, string $referencedColumn): array {
        $columns = $this->table($referencedTable);
        if(empty($columns))
            return [];

        // Only one column - use it as both id and label
        if(count($columns) === 1)
            return $this->fetchOptionsFromTable($referencedTable, $referencedColumn, $referencedColumn);

        $labelColumn = $this->determineLabelColumn($columns, $referencedColumn);
        return $this->fetchOptionsFromTable($referencedTable, $referencedColumn, $labelColumn);
    }

    /**
     * Determine label column for FK referenced table
     */
    protected function determineLabelColumn(array $columns, string $pkColumn): string {
        $pk = $columns[$pkColumn] ?? null;
        if(!$pk)
            return $pkColumn;

        $columnNames = array_keys($columns);
        $dataType = strtolower($pk['data_type'] ?? '');

        $isStringPK = in_array($dataType, ['char', 'varchar'], true);
        $charLength = (int)($pk['character_maximum_length'] ?? 0);
        $isUUID = $isStringPK && ($charLength === 36 || $charLength === 32);

        // If PK is string and not UUID, use PK as label
        if($isStringPK && !$isUUID)
            return $pkColumn;

        // Find first varchar/char column (excluding PK)
        foreach($columns as $name => $col) {
            if($name === $pkColumn)
                continue;
            $colType = strtolower($col['data_type'] ?? '');
            if($colType === 'char' || $colType === 'varchar')
                return $name;
        }

        // Fallback: column after PK
        $pkIndex = array_search($pkColumn, $columnNames, true);
        if($pkIndex !== false && isset($columnNames[$pkIndex + 1]))
            return $columnNames[$pkIndex + 1];

        return $pkColumn;
    }

    /**
     * Fetch options from table sorted by label
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
     *
     * @pure
     * @param $field
     * @return string
     * @throws Exception
     */
    protected function getType($field): string {
        $types = [
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

        $baseType = $types[$field->type] ?? 'unknown';

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
                        $tableColumns = $this->table($field->orgtable);
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
     */
    protected function getBaseType(int $typeId): string {
        $types = [
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
        return $types[$typeId] ?? 'unknown';
    }
}
