<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;
use RuntimeException;
/**
 * DatabaseMetadata 0.0.1 WIP
 * @TODO UniqueKeys: per table, per table.field, fields are unique tmemself, FK, FK from standard field names, de ida y vuelta
 *
 */
class DatabaseMetadata {
    protected static ?self $instance = null;
    protected SqlExecutor $sqlExecutor;
    protected array $tableColumns = [];
    protected array $primaryKeys = [];
    protected array $foreignKeys = [];

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
    public function table(string $tableName): array {
        if(empty($tableName))
            return [];
        if(!isset($this->tableColumns[$tableName]))
            $this->tableColumns[$tableName] = $this->sqlExecutor->array(
              "SHOW /*" . __METHOD__ . "*/ FULL COLUMNS FROM " . SqlUtils::fieldIt($tableName));
        return $this->tableColumns[$tableName];
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
            if(!empty($field->orgtable)) {
                if(isset($tableColumns[$field->orgname])) {
                    $kind = 'table'; // Field exists in the original table
                }
            } elseif($field->flags & MYSQLI_GROUP_FLAG) {
                $kind = 'aggregate';
            } elseif(empty($field->table)) {
                if(strpos(strtoupper($field->name), 'CALCULATED_') === 0) {
                    $kind = 'calculated'; // parece bug aqui
                }
            }

            // Build field metadata with correct precedence using array_merge
            $metadata[$field->name] = array_merge(
              $tableColumns[$field->orgname] ?? [],
              (array)$field,
              [
                'kind' => $kind,
                'index' => empty($field->table) ? $field->name : $field->table . '.' . $field->orgname,
                'Type' => isset($tableColumns[$field->orgname]) ? $tableColumns[$field->orgname]['Type'] : $this->getType($field)
              ]
            );
        }

        return $metadata;
    }

    public function clear(): void {
        $this->primaryKeys = [];
        $this->tableColumns = [];
        $this->foreignKeys = [];
    }
    /**
     * Get foreign key constraints for a table
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

}
