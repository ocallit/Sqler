<?php
/** @noinspection PhpUnused */
/** @noinspection SqlNoDataSourceInspection */

namespace Ocallit\Sqler;

use InvalidArgumentException;
use function array_key_exists;
use function implode;
use function is_array;

/**
 * QueryBuilder Quick Reference:
 *
 * All methods return: ['query' => string, 'parameters' => array]
 *
 * - insert($table, $data, $onDupUpdate=false, $dontUpdate=[], $override=[], $comment='')
 * - update($table, $data, $where=[], $comment='')
 * - where($conditions, $conjunction='AND', $comment='')
 *
 * Magic values (not parameterized): NOW(), CURDATE(), CURRENT_TIMESTAMP, UUID(), etc.
 * Where arrays: null → 'IS NULL', other scalar → '=?', array → 'IN (?,...)'
 * Auto-skipped on duplicate: alta_db, alta_por, registered, registered_by
 */

class QueryBuilder {
    public bool $useNewOnDuplicate = true; // Beginning with MySQL 8.0.19,

    protected array $dontQuoteValue = [
        'IA_UUID()' => 1,
        'CURDATE()'=>1,'CURRENT_DATE()'=>1,'CURRENT_DATE'=>1,'SYSDATE()'=>1,'UTC_DATE()'=>1,
        'CURRENT_DATETIME'=>1,'NOW()'=>1, 'NOW(6)' => 1,
        'CURRENT_TIME()'=>1,'CURRENT_TIME'=>1,'CURTIME()'=>1,'UTC_TIME()'=>1,
        'CURRENT_TIMESTAMP()'=>1,
        'CURRENT_TIMESTAMP'=>1,'LOCALTIMESTAMP()'=>1,
        'LOCALTIMESTAMP'=>1,
        'UNIX_TIMESTAMP()'=>1,'UTC_TIMESTAMP()'=>1
    ];

    protected array $dontOnUpdateFieldName = [
        'alta_db' => 1, 'alta_por' => 1,
        'registered' => 1, 'registered_by' => 1
    ];

    public function __construct(bool $useNewOnDuplicate = true) {
        $this->useNewOnDuplicate = $useNewOnDuplicate;
    }

    /**
     * Returns an insert statement using array keys as column names and values as values, and the parameters
     * @pure
     *
     * @param string $table
     * @param array $array
     * @param bool $onDuplicateKeyUpdate
     * @param array $onDuplicateKeyDontUpdate Column names as a list, or as keys (associated values ignored).
     * @param array $onDuplicateKeyOverride
     * @param string $comment
     * @return array
     */
    public function insert(string $table, array $array,
           bool $onDuplicateKeyUpdate = false, array $onDuplicateKeyDontUpdate = [],array $onDuplicateKeyOverride = [],
           string $comment = ''
    ):array {
        if(array_is_list($onDuplicateKeyDontUpdate))
            $onDuplicateKeyDontUpdate = array_fill_keys($onDuplicateKeyDontUpdate, true);
        $columns = [];
        $values = [];
        $parameters = [];
        $onDuplicateKey = [];
        foreach($array as $columnName => $value) {
            $col = SqlUtils::fieldIt($columnName);
            $columns[] = $col;
            if(is_string($value) && array_key_exists($value, $this->dontQuoteValue)) {
                $values[] = $value;
            } else {
                $values[] = "?";
                $parameters[] = $value;
            }
            if($onDuplicateKeyUpdate && !array_key_exists($columnName, $onDuplicateKeyDontUpdate) && !array_key_exists($columnName, $this->dontOnUpdateFieldName)) {
                if(array_key_exists($columnName, $onDuplicateKeyOverride))
                    $onDuplicateKey[] = "$col=" . $onDuplicateKeyOverride[$columnName];
                elseif($this->useNewOnDuplicate)
                    $onDuplicateKey[] = "$col=new.$col";
                else
                    $onDuplicateKey[] = "$col=VALUES($col)";
            }
        }
        $comment = $this->commentIt($comment);
        $insert = "INSERT $comment " .
            " INTO " . SqlUtils::fieldIt($table) . "(" . implode(",", $columns) . ") " .
            " VALUES(" . implode(",", $values) . ")";
        if(!empty($onDuplicateKey)) {
            $insert .= ($this->useNewOnDuplicate ? "  as new" : "") .
                " ON DUPLICATE KEY UPDATE " . implode(",", $onDuplicateKey);
        }
        return ["query" => $insert, "parameters" => $parameters];
    }

    /**
     * Returns an update statement using array keys as column names and values as values, same with where, and the parameters
     * @pure
     *
     * @param string $table
     * @param array $array
     * @param array $where
     * @param string $comment
     * @return array
     */
    public function update(string $table, array $array, array $where = [], string $comment = ''):array {
        $set = [];
        $parameters = [];
        foreach($array as $columnName => $value) {
            $col = SqlUtils::fieldIt($columnName);
            if(is_string($value) && array_key_exists($value, $this->dontQuoteValue)) {
                $set[] = "$col=$value";
            } else {
                $set[] = "$col=?";
                $parameters[] = $value;
            }
        }
        $comment = $this->commentIt($comment);

        $whereArray = $this->where($where);
        $update = "UPDATE $comment " . SqlUtils::fieldIt($table) . " SET " . implode(",", $set) .
          " WHERE $whereArray[query]";
        return ["query" => $update, "parameters" => array_merge($parameters, $whereArray['parameters']) ];
    }

    /**
     * Returns a where statement using array keys as column names and values as values, concatenated with $conjunction, and the parameters
     * @pure
     *
     */
    public function where(array $array, string $conjunction = "AND", string $comment = ""):array {
        $comment = $this->commentIt($comment);
        if(empty($array))
            return ["query" => " $comment ", "parameters" => []];
        $clause = [];
        $parameters = [];
        foreach($array as $columnName => $value) {
            $col = SqlUtils::fieldIt($columnName);
            if($value === null) {
                $clause[] = "$col IS NULL";
            } elseif(is_string($value) && array_key_exists($value, $this->dontQuoteValue)) {
                $clause[] = "$col=$value";
            } elseif(is_array($value)) {
                $inClause = [];
                if(empty($value))
                    $value = [null];
                foreach($value as $v) {
                    if(is_string($v) && array_key_exists($v, $this->dontQuoteValue))
                        $inClause[] = $v;
                    else {
                        $inClause[] = "?";
                        $parameters[] = $v;
                    }
                }
                if(!empty($inClause))
                    $clause[] = "$col IN (" . implode(",", $inClause) . ")";
            } else {
                $clause[] = "$col=?";
                $parameters[] = $value;
            }
        }
        return ["query" => " $comment (" . implode(" $conjunction ", $clause) . ")", "parameters" => $parameters];
    }

    public function inValues(array $array):array {
        if(empty($array))
            $array = [null];
        $parameters = [];
        $inClause = [];
        foreach($array as $v) {
            if(is_string($v) && array_key_exists($v, $this->dontQuoteValue))
                $inClause[] = $v;
            else {
                $inClause[] = "?";
                $parameters[] = $v;
            }
        }
        return ["(" . implode(",", $inClause) . ")", $parameters];
    }

    /**
     * Returns the statements to synchronize a junction table (n:m between tableA and tableB)
     * for a single tableA id: rows in $values are inserted or, if the (tableA_id, tableB_id)
     * pair already exists, updated (extra columns refreshed); existing rows whose tableB id
     * is not in $values are deleted. Rows already present are never deleted and re-inserted,
     * so extra columns, triggers and foreign keys are not churned.
     * @pure
     *
     * @param string $tableName junction table name
     * @param string $tableA_column junction table column holding tableA's id
     * @param int|string $tableA_id_value the tableA id whose relations are synchronized
     * @param string $tableB_column junction table column holding tableB's id
     * @param array $values [ [$tableB_column => value, otherColumn => value, ...], ... ]
     *   each row must include $tableB_column and may include extra junction columns,
     *   which are updated ON DUPLICATE KEY. An empty $values deletes all rows for $tableA_id_value.
     * @param string $comment
     * @return array [ ['query' => string, 'parameters' => array], ... ] the DELETE first,
     *   then one INSERT ... ON DUPLICATE KEY UPDATE per row, meant to run in one transaction
     * @throws InvalidArgumentException when a row in $values is missing $tableB_column
     */
    public function junctionTable(string $tableName, string $tableA_column, int|string $tableA_id_value,
                                  string $tableB_column, array $values, string $comment = ''
    ):array {
        $comment = $this->commentIt($comment);

        $keepTableB_ids = [];
        $upserts = [];
        foreach($values as $index => $row) {
            if(!is_array($row) || !array_key_exists($tableB_column, $row))
                throw new InvalidArgumentException(
                  __METHOD__ . " values[$index] must be an array with a '$tableB_column' key");
            $keepTableB_ids[] = $row[$tableB_column];
            // key columns update to themselves on duplicate, so the clause is never empty
            // and re-sent existing pairs don't raise a duplicate key error
            $upserts[] = $this->insert($tableName, [$tableA_column => $tableA_id_value] + $row,
              true, [], [], $comment);
        }

        $whereDelete = $this->where([$tableA_column => $tableA_id_value]);
        $delete = "DELETE $comment FROM " . SqlUtils::fieldIt($tableName) . " WHERE $whereDelete[query]";
        $parameters = $whereDelete['parameters'];
        if(!empty($keepTableB_ids)) {
            $notIn = [];
            foreach($keepTableB_ids as $id) {
                if(is_string($id) && array_key_exists($id, $this->dontQuoteValue)) {
                    $notIn[] = $id;
                } else {
                    $notIn[] = "?";
                    $parameters[] = $id;
                }
            }
            $delete .= " AND " . SqlUtils::fieldIt($tableB_column) . " NOT IN (" . implode(",", $notIn) . ")";
        }

        return array_merge([["query" => $delete, "parameters" => $parameters]], $upserts);
    }

    protected function commentIt(string $comment):string {
        if(empty($comment)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $className = $trace[2]['class'] ?? '';
            $methodName = $trace[2]['function'] ??  basename($trace[2]['file'] ?? 'GlobalScope');
            return "/*$className::$methodName*/";
        }
        return "/*" .  str_replace(["/*", "*/"], "", $comment) . "*/";
    }

}
