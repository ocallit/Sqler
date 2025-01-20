<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use function explode;
use function str_replace;
use function chr;

class SqlUtils {
    const JSON_MYSQL_OPTIONS = JSON_UNESCAPED_UNICODE |JSON_INVALID_UTF8_IGNORE |JSON_INVALID_UTF8_SUBSTITUTE;

    public static function toLabel(string $fieldName):string {
        return ucwords(strtolower( str_replace('_', ' ', $fieldName)));
    }

    /**
     * Protect with ` backticks a: column name to column name respecting . table.column to table.column
     *
     * @param string $fieldName
     * @return string The protected field name (e.g., '`table`.`column`').
     * @pure
     * @psalm-pure
     */
    public static function fieldIt(string $fieldName): string {
        $protected = [];
        foreach(explode('.',$fieldName) as $field)
            if(preg_match('/^`[^`]*`$/S', $field))
                $protected[] = $field;
            else
                $protected[] = '`'. str_replace('`', '', $field ).'`';
        return implode('.', $protected);
    }


    /**
     * @pure
     * @psalm-pure
     */
    public static function strIt(string|null $str):string {
        if($str === null)
            return 'NULL';
        return empty($str) ? "''" :
            "'" . str_replace( ["\\",chr(8),chr(0),chr(26),chr(27)],
              ["\\\\",'','','',''],
              str_replace("'","''", "$str")
            ) . "'";
    }

}