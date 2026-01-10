<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use function explode;
use function str_replace;
use function chr;

class SqlUtils {
    const JSON_MYSQL_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Changes a column name to a nice label or title
     *
     * @pure
     * @param string $fieldName
     * @return string
     */
    public static function toLabel(string $fieldName): string {
        return ucwords(strtolower(str_replace('_', ' ', $fieldName)));
    }

    /**
     * Protect with ` backticks a: column name to column name respecting . table.column to `table`.`column`
     *
     * @pure
     * @param string $fieldName
     * @return string The protected field name (e.g., '`table`.`column`').
     * @pure
     */
    public static function fieldIt(string $fieldName): string {
        $protected = [];
        foreach(explode('.', $fieldName) as $field)
            if(preg_match('/^`[^`]*`$/S', $field))
                $protected[] = $field;
            else
                $protected[] = '`' . str_replace('`', '', $field) . '`';
        return implode('.', $protected);
    }


    /**
     * @pure
     * @psalm-pure
     */
    public static function strIt(string|null $str): string {
        if($str === NULL)
            return 'NULL';
        return empty($str) ? "''" :
          "'" . str_replace(["\\", chr(8), chr(0), chr(26), chr(27)],
            ["\\\\", '', '', '', ''],
            str_replace("'", "''", "$str")
          ) . "'";
    }

    /**
     * Creates a template from a SQL query string by replacing literals with '?'.
     * This is a simplified approach for logging or comparison, not a full SQL parser.
     *
     * @param string $sql The SQL query string.
     * @return string The templated SQL query string.
     */
    public static function createQueryTemplate(string $sql): string {

        $templatedSql = preg_replace("/\s+/", ' ', $sql);
        $sql = $templatedSql !== NULL ? trim($templatedSql) : trim($sql);

        // Pattern for single-quoted strings (handles MySQL's backslash escapes)
        $singleQuotedStringPattern = "/'([^'\\\\]|\\\\.)*'/S";
        // Pattern for double-quoted strings (handles MySQL's backslash escapes)
        // Note: Double quotes are standard for identifiers, but MySQL can use them for strings if ANSI_QUOTES mode is set.
        $doubleQuotedStringPattern = '/"([^"\\\\]|\\\\.)*"/S';

        // Replace string literals first to avoid replacing numbers inside strings
        $templatedSql = preg_replace($singleQuotedStringPattern, '?', $sql);
        if($templatedSql !== NULL) {
            $sql = $templatedSql;
        }
        $templatedSql = preg_replace($doubleQuotedStringPattern, '?', $sql);
        if($templatedSql !== NULL) {
            $sql = $templatedSql;
        }
        // Pattern for numeric literals
        // Matches decimal numbers (e.g., 123.45, .5)
        $decimalPattern = '/\b\d+\.\d+\b/';
        // Matches integer numbers (e.g., 123)
        // \b ensures word boundaries, so it doesn't match numbers within identifiers (e.g., column1)
        $integerPattern = '/\b\d+\b/';

        // Replace decimal numbers
        $templatedSql = preg_replace($decimalPattern, '?', $sql);
        if($templatedSql !== NULL) {
            $sql = $templatedSql;
        }
        // Replace integer numbers
        $templatedSql = preg_replace($integerPattern, '?', $sql);
        if($templatedSql !== NULL) {
            $sql = $templatedSql;
        }
        // Normalize whitespace (multiple spaces to one, trim) for further consistency
        $templatedSql = preg_replace('/\s+/', ' ', trim($sql));
        if($templatedSql !== NULL) {
            $sql = $templatedSql;
        }

        return $sql;
    }
}
