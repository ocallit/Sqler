<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

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

}
