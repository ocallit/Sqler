<?php
/** @noinspection PhpRedundantOptionalArgumentInspection */
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;
use RuntimeException;

/**
 * DatabaseMetadata 0.0.0 WIP
 * @TODO UniqueKeys: per table, per table.field, fields are unique tmemself, FK, FK from standard field names, de ida y vuelta
 *
 */
class DatabaseMetadata {
    protected static ?self $instance = null;
    protected SqlExecutor $sqlExecutor;
    protected array $tableColumns = [];
    protected array $primaryKeys = [];


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
            $sql = "SELECT /" . __METHOD__ . "/ t.TABLE_NAME, c.COLUMN_NAME
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
            $this->tableColumns[$tableName] = $this->sqlExecutor->array("SHOW /" . __METHOD__ . "/ FULL COLUMNS FROM " . SqlUtils::fieldIt($tableName), 'Field');
        return $this->tableColumns[$tableName];
    }

    /**
     * @param string $query
     * @param array $parameters
     * @return array
     * @throws Exception
     *  table: empty is a function call not updatable
     */
    public function query(string $query, array $parameters = []): array {
        $mysqliResult = $this->sqlExecutor->result($query, $parameters);
        if(!$mysqliResult)
            throw new Exception("Failed to get result metadata");

        $metadata = [];
        $fields = mysqli_fetch_fields($mysqliResult);
        foreach($fields as $field) {
            $tableColumns = self::table($field->orgtable);
            $metadata[$field->name] = [
              ...(array)$field,
              ...$tableColumns[$field->orgname] ?? [],
              ...[
                'index' => empty($field->table) ? $field->name : $field->table . '.' . $field->orgname,
                'Type' => self::getType($field),
              ],
            ];
        }
        return $metadata;
    }

    public function clear(): void {
        $this->primaryKeys = [];
        $this->tableColumns = [];
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

    /**
     * @throws Exception
     */
    final protected function __sleep() {throw new Exception("Cannot serialize a singleton.");}

    /**
     * @throws Exception */
    final public function __wakeup() {throw new Exception("Cannot unserialize a singleton.");}

    /**
     * @throws Exception
     */
    final protected function __clone() {throw new Exception("Cannot clone a singleton.");}

    /**
     * @throws Exception
     */
    final public static function __set_state(array $an_array) {throw new Exception('Cannot instantiate singleton via __set_state.');}

}
