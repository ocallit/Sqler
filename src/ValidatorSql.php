<?php
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;

/**
 * Validates an associative array of column values against the MySQL schema of a given table.
 *
 * Usage:
 *   $result = ValidatorSql::validate('orders', $data, $sqlExecutor);
 *   // $result['valid']    bool
 *   // $result['errors']   array<columnName, string[]>  — per-column error messages
 *   // $result['warnings'] array<string, string[]>       — column names absent from $data with no default
 *
 * Requirements:
 *   - DatabaseMetadata::initialize($sql) must have been called before first use.
 *   - Primary key column(s) must always be present in $data.
 *   - Missing non-PK columns without a default value produce warnings, not errors.
 *   - Source is HTML, so "33" or "3.14" (with .00 fractional) are valid integers.
 *   - Decimal/float values are never cast to float; bcmath is used for precision.
 *   - Date strings must be Y-m-d; datetime/timestamp Y-m-d H:i:s (time part completed if missing).
 *   - Time strings must be H:i:s (seconds completed if missing).
 */
class ValidatorSql {

    /**
     * @param string      $tableName
     * @param array<string, mixed> $data       Keys are column names, values are submitted values.
     * @param string      $database   Optional database name; defaults to the connection's current DB.
     *
     * @return array{
     *   valid: bool,
     *   errors: array<string, string[]>,
     *   warnings: array<string, string[]>
     * }
     * @throws Exception
     */
    public static function validate(
        string $tableName,
        array $data,
        string $database = ""
    ): array {
        $meta = DatabaseMetadata::getInstance();
        $columns = $meta->table($tableName, $database);

        /** @var array<string, string[]> $errors */
        $errors   = [];
        /** @var array<string, string[]> $warnings */
        $warnings = [];

        // 2. Warn about non-PK columns absent from $data that have no default
        foreach ($columns as $colName => $col) {
            if (array_key_exists($colName, $data)) {
                continue;
            }
            if (isset($primaryKeys[$colName])) {
                continue;
            }
            $hasDefault      = $col['default_value'] !== null;
            $isNullable      = strcasecmp($col['is_nullable'] ?? 'NO', 'YES') === 0;
            $isAutoIncrement = str_contains(strtolower($col['extra'] ?? ''), 'auto_increment');
            $isGenerated     = !empty($col['generation_expression']);

            if (!$hasDefault && !$isNullable && !$isAutoIncrement && !$isGenerated) {
                $warnings[$colName][] = "Column '$colName' has no default value and is not present in data.";
            }
        }

        // 3. Per-column type validation
        foreach ($data as $colName => $value) {
            if (!isset($columns[$colName])) {
                $errors[$colName][] = "Column '$colName' does not exist in table '$tableName'.";
                continue;
            }
            $colErrors = self::validateValue($value, $columns[$colName]);
            if (!empty($colErrors)) {
                $errors[$colName] = array_merge($errors[$colName] ?? [], $colErrors);
            }
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    // -------------------------------------------------------------------------
    // Per-value type dispatch
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateValue(mixed $value, array $col): array {
        $dataType   = strtolower($col['data_type'] ?? '');
        $isNullable = strcasecmp($col['is_nullable'] ?? 'NO', 'YES') === 0;
        $colName    = $col['name'];

        if ($value === null || $value === '') {
            if (!$isNullable) {
                return ["Column '$colName' cannot be null/empty."];
            }
            return [];
        }

        return match ($dataType) {
            'tinyint', 'smallint', 'int', 'mediumint', 'bigint', 'year'
                => self::validateInteger($value, $col),
            'decimal', 'numeric'
                => self::validateDecimal($value, $col),
            'float', 'double', 'real'
                => self::validateFloat($value, $col),
            'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'
                => self::validateString($value, $col),
            'enum'
                => self::validateEnum($value, $col),
            'set'
                => self::validateSet($value, $col),
            'date'
                => self::validateDate($value, $col),
            'datetime', 'timestamp'
                => self::validateDateTime($value, $col),
            'time'
                => self::validateTime($value, $col),
            'bit'
                => self::validateBit($value, $col),
            default => [],
        };
    }

    // -------------------------------------------------------------------------
    // Integer types
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateInteger(mixed $value, array $col): array {
        $colName = $col['name'];

        if (!is_numeric($value)) {
            return ["Column '$colName' must be a numeric integer value."];
        }

        $strVal = (string)$value;

        // Reject non-zero fractional part (e.g. "3.14") but accept "3.00"
        if (str_contains($strVal, '.')) {
            $frac = rtrim(explode('.', $strVal, 2)[1] ?? '0', '0');
            if ($frac !== '') {
                return ["Column '$colName' must be an integer; fractional value '$value' is not allowed."];
            }
        }

        // Integer part only (strip any trailing .00)
        $intStr  = str_contains($strVal, '.') ? explode('.', $strVal, 2)[0] : $strVal;
        $dataType = strtolower($col['data_type']);
        $unsigned = str_contains(strtolower($col['Type'] ?? ''), 'unsigned');

        // Signed/unsigned ranges as strings for bccomp safety
        $ranges = [
            'tinyint'   => $unsigned ? ['0', '255']                                        : ['-128', '127'],
            'smallint'  => $unsigned ? ['0', '65535']                                      : ['-32768', '32767'],
            'mediumint' => $unsigned ? ['0', '16777215']                                   : ['-8388608', '8388607'],
            'int'       => $unsigned ? ['0', '4294967295']                                 : ['-2147483648', '2147483647'],
            'bigint'    => $unsigned ? ['0', '18446744073709551615']                       : ['-9223372036854775808', '9223372036854775807'],
            'year'      => ['1901', '2155'],
        ];

        if (isset($ranges[$dataType])) {
            [$min, $max] = $ranges[$dataType];
            if (bccomp($intStr, $min) < 0 || bccomp($intStr, $max) > 0) {
                return ["Column '$colName' value $value is out of range [$min, $max]."];
            }
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Decimal / numeric
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateDecimal(mixed $value, array $col): array {
        $colName = $col['name'];

        if (!is_numeric($value)) {
            return ["Column '$colName' must be a numeric value."];
        }

        $precision = (int)($col['numeric_precision'] ?? 10);
        $scale     = (int)($col['numeric_scale'] ?? 0);
        $unsigned  = str_contains(strtolower($col['Type'] ?? ''), 'unsigned');
        $strVal    = (string)$value;

        // Round to defined scale without converting to float
        $rounded = bcadd($strVal, '0', $scale);

        if ($unsigned && bccomp($rounded, '0') < 0) {
            return ["Column '$colName' must be non-negative (unsigned decimal)."];
        }

        // Max integer digits = precision - scale
        $maxIntDigits = $precision - $scale;
        $intPart      = explode('.', $rounded, 2)[0];
        $intPart      = ltrim($intPart, '-');           // absolute integer part
        $actualDigits = strlen(ltrim($intPart, '0')) ?: 0;

        if ($actualDigits > $maxIntDigits) {
            return ["Column '$colName' value $value exceeds allowed precision ($precision,$scale): too many integer digits."];
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Float / double
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateFloat(mixed $value, array $col): array {
        if (!is_numeric($value)) {
            return ["Column '{$col['name']}' must be a numeric value."];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // String types
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateString(mixed $value, array $col): array {
        $maxLen = $col['character_maximum_length'] ?? null;
        if ($maxLen === null) {
            return [];
        }
        $len = mb_strlen((string)$value, 'UTF-8');
        if ($len > (int)$maxLen) {
            return ["Column '{$col['name']}' exceeds maximum length of $maxLen characters (got $len)."];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // ENUM
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateEnum(mixed $value, array $col): array {
        $options = DatabaseMetadata::getInstance()->parseEnumSetOptions($col['Type'] ?? '');
        if (!isset($options[(string)$value])) {
            $allowed = implode(', ', array_keys($options));
            return ["Column '{$col['name']}' value '$value' is not a valid ENUM. Allowed: $allowed"];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // SET
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateSet(mixed $value, array $col): array {
        $options = DatabaseMetadata::getInstance()->parseEnumSetOptions($col['Type'] ?? '');
        $items   = array_map('trim', explode(',', (string)$value));
        $invalid = [];
        foreach ($items as $item) {
            if (!isset($options[$item])) {
                $invalid[] = $item;
            }
        }
        if (!empty($invalid)) {
            $allowed = implode(', ', array_keys($options));
            return ["Column '{$col['name']}' contains invalid SET values: " . implode(', ', $invalid) . ". Allowed: $allowed"];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // Date
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateDate(mixed $value, array $col): array {
        $str = (string)$value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            return ["Column '{$col['name']}' must be a date in Y-m-d format. Got: $str"];
        }
        [$y, $m, $d] = explode('-', $str);
        if (!checkdate((int)$m, (int)$d, (int)$y)) {
            return ["Column '{$col['name']}' has an invalid date: $str"];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // DateTime / Timestamp
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateDateTime(mixed $value, array $col): array {
        $str = (string)$value;

        // Complete missing time parts
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            $str .= ' 00:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $str)) {
            $str .= ':00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $str)) {
            $str = str_replace('T', ' ', $str);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $str)) {
            $str = str_replace('T', ' ', $str) . ':00';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $str)) {
            return ["Column '{$col['name']}' must be a datetime in Y-m-d H:i:s format. Got: $value"];
        }

        [$datePart, $timePart] = explode(' ', $str, 2);
        [$y, $m, $d]           = explode('-', $datePart);
        [$h, $i, $s]           = explode(':', $timePart);

        if (!checkdate((int)$m, (int)$d, (int)$y)) {
            return ["Column '{$col['name']}' has an invalid date part: $datePart"];
        }
        if ((int)$h > 23 || (int)$i > 59 || (int)$s > 59) {
            return ["Column '{$col['name']}' has an invalid time part: $timePart"];
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Time
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateTime(mixed $value, array $col): array {
        $str = (string)$value;

        // Complete missing seconds
        if (preg_match('/^-?\d{1,3}:\d{2}$/', $str)) {
            $str .= ':00';
        }

        // MySQL TIME: -838:59:59 to 838:59:59
        if (!preg_match('/^-?\d{1,3}:\d{2}:\d{2}$/', $str)) {
            return ["Column '{$col['name']}' must be a time in H:i:s format. Got: $value"];
        }

        $abs    = ltrim($str, '-');
        [$h, $i, $s] = explode(':', $abs);

        if ((int)$h > 838 || (int)$i > 59 || (int)$s > 59) {
            return ["Column '{$col['name']}' time value '$str' is out of MySQL TIME range (-838:59:59 to 838:59:59)."];
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // Bit
    // -------------------------------------------------------------------------

    /** @return string[] */
    protected static function validateBit(mixed $value, array $col): array {
        if ($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
            return ["Column '{$col['name']}' must be 0 or 1 (BIT)."];
        }
        return [];
    }


}
