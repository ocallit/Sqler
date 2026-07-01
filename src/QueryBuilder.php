<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use function array_fill;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
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
 * - syncBridgeTable($table, $columnA, $columnB, $values, $comment='') returns [['query'=>..,'parameters'=>..], ...]
 *
 * Magic values (not parameterized): NOW(), CURDATE(), CURRENT_TIMESTAMP, UUID(), etc.
 * Where arrays: scalar → '=?', array → 'IN (?,...)'
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
     * @param $table
     * @param $array
     * @param bool $onDuplicateKeyUpdate
     * @param array $onDuplicateKeyDontUpdate
     * @param array $onDuplicateKeyOverride
     * @param string $comment
     * @return array
     */
    public function insert($table, $array,
           bool $onDuplicateKeyUpdate = false, array $onDuplicateKeyDontUpdate = [],array $onDuplicateKeyOverride = [],
           string $comment = ''
    ):array {
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
        if(empty($comment))
            $comment = "/*" . __METHOD__ . "*/";

        $insert = "INSERT $comment " .
            " INTO " . SqlUtils::fieldIt($table) . "(" . implode(",", $columns) . ") " .
            " VALUES(" . implode(",", $values) . ")";
        if(!empty($onDuplicateKey)) {
            $insert .= "  as new ON DUPLICATE KEY UPDATE " . implode(",", $onDuplicateKey);
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
        if(empty($comment))
            $comment = "/*" . __METHOD__ . "*/";

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
        if(!empty($comment))
            $comment = "/*$comment*/";
        if(empty($array))
            return ["query" => " $comment ", "parameters" => []];
        $clause = [];
        $parameters = [];
        foreach($array as $columnName => $value) {
            $col = SqlUtils::fieldIt($columnName);
            if(is_string($value) && array_key_exists($value, $this->dontQuoteValue)) {
                $clause[] = "$col=$value";
            } elseif(is_array($value)) {
                $inClause = [];
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

    /**
     * Synchronizes a many-to-many bridge/pivot table (tableA_id, tableB_id[, extra columns...])
     * with the rows given in $values: relations of the tableA ids present in $values that are not
     * in $values get deleted, and every row in $values gets inserted, updating the extra columns
     * on duplicate key (the (tableA_column, tableB_column) pair is expected to be the primary key).
     * @pure
     *
     * @param string $table the bridge table name
     * @param string $columnA column holding tableA's id/key, e.g. tableA_id
     * @param string $columnB column holding tableB's id/key, e.g. tableB_id
     * @param array $values [ [$columnA=>value, $columnB=>value, otherColumn=>value, ...], ... ] rows to keep/upsert.
     *        Every row must have the same set of columns, including $columnA and $columnB.
     * @param string $comment
     * @return array [ ['query'=>string,'parameters'=>array], ... ] a DELETE statement followed by an
     *         INSERT ... ON DUPLICATE KEY UPDATE statement, or [] when $values is empty
     */
    public function syncBridgeTable(string $table, string $columnA, string $columnB, array $values, string $comment = ''): array {
        if(empty($values))
            return [];

        if(empty($comment))
            $comment = "/*" . __METHOD__ . "*/";

        $tbl = SqlUtils::fieldIt($table);
        $colA = SqlUtils::fieldIt($columnA);
        $colB = SqlUtils::fieldIt($columnB);

        $aIds = [];
        $deleteParameters = [];
        $pairPlaceholders = [];
        foreach($values as $row) {
            $aIds[$row[$columnA]] = $row[$columnA];
            $pairPlaceholders[] = "(?,?)";
            $deleteParameters[] = $row[$columnA];
            $deleteParameters[] = $row[$columnB];
        }
        $aPlaceholders = implode(",", array_fill(0, count($aIds), "?"));
        $delete = "DELETE $comment FROM $tbl" .
          " WHERE $colA IN ($aPlaceholders)" .
          " AND ($colA,$colB) NOT IN (" . implode(",", $pairPlaceholders) . ")";
        $deleteParameters = array_merge(array_values($aIds), $deleteParameters);

        $columns = array_keys($values[array_key_first($values)]);
        $insertParameters = [];
        $rows = [];
        foreach($values as $row) {
            $rowValues = [];
            foreach($columns as $columnName) {
                $value = $row[$columnName];
                if(is_string($value) && array_key_exists($value, $this->dontQuoteValue)) {
                    $rowValues[] = $value;
                } else {
                    $rowValues[] = "?";
                    $insertParameters[] = $value;
                }
            }
            $rows[] = "(" . implode(",", $rowValues) . ")";
        }
        $onDuplicateKey = [];
        foreach($columns as $columnName) {
            if(array_key_exists($columnName, $this->dontOnUpdateFieldName))
                continue;
            $col = SqlUtils::fieldIt($columnName);
            $onDuplicateKey[] = $this->useNewOnDuplicate ? "$col=new.$col" : "$col=VALUES($col)";
        }
        $insert = "INSERT $comment INTO $tbl(" . implode(",", array_map([SqlUtils::class, 'fieldIt'], $columns)) . ")" .
          " VALUES" . implode(",", $rows);
        if(!empty($onDuplicateKey))
            $insert .= " AS new ON DUPLICATE KEY UPDATE " . implode(",", $onDuplicateKey);

        return [
          ["query" => $delete, "parameters" => $deleteParameters],
          ["query" => $insert, "parameters" => $insertParameters],
        ];
    }

}
