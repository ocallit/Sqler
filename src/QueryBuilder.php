<?php
/** @noinspection PhpMissingParamTypeInspection */
/** @noinspection PhpUnused */

namespace ocallit\sqler;

use function array_key_exists;
use function explode;
use function implode;
use function is_array;
use function str_replace;
use function trim;
use function chr;

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

    public function strIt($str):string|array {
        if($str === null) {
            return 'NULL';
        }
        if(is_array($str)) {
            foreach($str as &$d)
                $d = $this->strIt($d);
            return $str;
        }
        return "'".str_replace( array("\\",chr(8),chr(0),chr(26),chr(27)), array("\\\\",'','','',''),str_replace("'","''", "$str"))."'";
    }

    public function fieldIt($fieldName):string|array {
        if($fieldName[0] === '(')
            return $fieldName;
        if(is_array($fieldName)) {
            foreach($fieldName as &$d)
                $d = $this->fieldIt($d);
            return $fieldName;
        }
        $protected = [];
        $n = explode('.',$fieldName);
        foreach($n as $field) {
            $protected[]= '`'.
                str_replace(['`',"\r","\n","\t","\0", "\\",
                    chr(8),chr(0),chr(26),chr(27)],
        '',
        trim($field) ).'`';
        }
        return implode('.', $protected);
    }

    public function insert($table, $array,
           $onDuplicateKeyUpdate = false, $onDuplicateKeyDontUpdate = [], $onDuplicateKeyOverride = [],
           $comment = ''
    ):array {
        $columns = [];
        $values = [];
        $parameters = [];
        $onDuplicateKey = [];
        foreach($array as $columnName => $value) {
            $col = $this->fieldIt($columnName);
            $columns[] = $col;
            if(array_key_exists($value, $this->dontQuoteValue)) {
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
            $comment = __METHOD__;

        $insert = "INSERT /*$comment*/ " .
            " INTO " . $this->fieldIt($table) . "(" . implode(",", $columns) . ") " .
            " VALUES(" . implode(",", $values) . ")";
        if(!empty($onDuplicateKey)) {
            $insert .= "  as new ON DUPLICATE KEY UPDATE " . implode(",", $onDuplicateKey);
        }
        return ["query" => $insert, "parameters" => $parameters];
    }

    public function update(string $table, array $array, array $where = [], string $comment = ''):array {
        $set = [];
        $parameters = [];
        foreach($array as $columnName => $value) {
            $col = $this->fieldIt($columnName);
            if(array_key_exists($value, $this->dontQuoteValue)) {
                $set[] = "$col=$value";
            } else {
                $set[] = "$col=?";
                $parameters[] = $value;
            }
        }
        if(empty($comment))
            $comment = __METHOD__;
        $whereArray = $this->where($where);
        $update = "UPDATE /*$comment*/ " . $this->fieldIt($table) . " SET " . implode(",", $set) .
          " WHERE $whereArray[query]";
        return ["query" => $update, "parameters" => array_merge($parameters, $whereArray['parameters']) ];
    }

    public function where($array, $op = "AND", $comment = ""):array {
        if(!empty($comment))
            $comment = "/*$comment*/";
        if(empty($array))
            return ["query" => " $comment ", "parameters" => []];
        $clause = [];
        $parameters = [];
        foreach($array as $columnName => $value) {
            $col = $this->fieldIt($columnName);
            if(array_key_exists($value, $this->dontQuoteValue)) {
                $clause[] = "$col=$value";
            } elseif(is_array($value)) {
                $in = [];
                foreach($value as $v) {
                    if(array_key_exists($v, $this->dontQuoteValue))
                        $in[] = $v;
                    else {
                        $in[] = "?";
                        $parameters[] = $v;
                    }
                }
                if(!empty($in))
                    $clause[] = "$col IN (" . implode(",", $in) . ")";
            } else {
                $clause[] = "$col=?";
                $parameters[] = $value;
            }
        }
        return ["query" => " $comment (" . implode(" $op ", $clause) . ")", "parameters" => $parameters];
    }

}
