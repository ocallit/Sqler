<?php
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;
use RuntimeException;

/**
 * Validates and fixes an associative array of values, usually from an HTML form, against the MySQL schema.
 *
 * Usage:
 *   $validator = new ValidatorSql($sqlExecutor);
 *   $data   = $validator->fix('orders', $_POST);
 *   $errors = $validator->validateVsTable('orders', $data);          // [colName => userFriendlyError]
 *   $errors = $validator->validateVsQuery('SELECT ...', $data);      // [selectClauseName => userFriendlyError]
 *   $errors = $validator->validateUnique('orders', $data);           // [indexName => userFriendlyError]
 *   $errors = $validator->validateForeignKeys('orders', $data);      // [constraintName => userFriendlyError]
 *
 * Rules:
 *   - DatabaseMetadata is initialized with $sqlExecutor if it was not initialized already.
 *   - Keys in $data that are not columns of the table/query are ignored.
 *   - Values are checked as fix() would leave them: " 1,234.00 " is a valid integer.
 *   - Source is HTML, so "33" or "3.14" are valid numbers and "3.00" is a valid integer.
 *   - Decimal/integer values are never cast to float; bcmath is used for precision.
 *   - Date strings must be Y-m-d; datetime/timestamp Y-m-d H:i:s (time part completed if missing).
 *   - Time strings must be H:i:s (seconds completed if missing).
 *   - A unique index comment, when set, is used as its error message.
 */
class ValidatorSql {
    protected SqlExecutor $sqlExecutor;
    protected DatabaseMetadata $databaseMetadata;

    public function __construct(SqlExecutor $sqlExecutor) {
        $this->sqlExecutor = $sqlExecutor;
        try {
            $this->databaseMetadata = DatabaseMetadata::getInstance();
        } catch(RuntimeException) {
            DatabaseMetadata::initialize($sqlExecutor);
            $this->databaseMetadata = DatabaseMetadata::getInstance();
        }
    }

    // -------------------------------------------------------------------------
    // Input validator vs db-schema
    // -------------------------------------------------------------------------

    /**
     * @param string $tableName
     * @param array<string, mixed> $data Keys are column names, values are submitted values.
     * @return array<string, string> [colNameWithError => userFriendlyError]
     * @throws Exception
     */
    public function validateVsTable(string $tableName, array $data): array {
        return $this->validateVsColumns($this->tableColumns($tableName), $data);
    }

    /**
     * Note: the query is executed to read its metadata, add a LIMIT 0 or WHERE 0 to make it cheap.
     *
     * @param string $selectQuery
     * @param array<string, mixed> $data Keys are select clause names (aliases), values are submitted values.
     * @return array<string, string> [selectClauseNameWithError => userFriendlyError]
     * @throws Exception
     */
    public function validateVsQuery(string $selectQuery, array $data): array {
        return $this->validateVsColumns($this->databaseMetadata->query($selectQuery), $data);
    }

    // -------------------------------------------------------------------------
    // Unique check
    // -------------------------------------------------------------------------

    /**
     * For each unique index (not the primary key) whose columns are all in $data, check no other record,
     * one with a different primary key, has the same values.
     * An index with a NULL value, or with a column missing from $data, is not checked.
     *
     * @param string $tableName
     * @param array<string, mixed> $data [colName => value], include the primary key when updating
     * @return array<string, string> [indexName => userFriendlyError]
     * @throws Exception
     */
    public function validateUnique(string $tableName, array $data): array {
        $columns = $this->tableColumns($tableName);
        $data = $this->fixValues($columns, $data);
        $primaryKey = $this->databaseMetadata->primaryKeys()[$tableName] ?? [];
        $table = SqlUtils::fieldIt($tableName);

        $notSameRecord = "";
        $notSameRecordParameters = $this->keyValues($primaryKey, $columns, $data) ?? [];
        if(!empty($notSameRecordParameters))
            $notSameRecord = " AND NOT (" . self::whereEquals($primaryKey) . ")";

        $errors = [];
        foreach($this->databaseMetadata->uniqueIndexes($tableName) as $indexName => $index) {
            $parameters = $this->keyValues($index['cols'], $columns, $data);
            if($parameters === NULL)
                continue;
            $method = __METHOD__;
            $found = $this->sqlExecutor->row(
              "SELECT /*$method*/ 1 FROM $table WHERE " . self::whereEquals($index['cols']) . "$notSameRecord LIMIT 1",
              array_merge($parameters, $notSameRecordParameters)
            );
            if(empty($found))
                continue;
            $errors[$indexName] = !empty($index['comment']) ? $index['comment'] :
              "A record with the same " . $this->labels($index['cols']) . " already exists.";
        }
        return $errors;
    }

    // -------------------------------------------------------------------------
    // Valid foreign keys
    // -------------------------------------------------------------------------

    /**
     * For each foreign key whose columns are all in $data, check the referenced record exists.
     * A foreign key with a NULL value, or with a column missing from $data, is not checked.
     *
     * @param string $tableName
     * @param array<string, mixed> $data [colName => value]
     * @return array<string, string> [constraintName => userFriendlyError]
     * @throws Exception
     */
    public function validateForeignKeys(string $tableName, array $data): array {
        $columns = $this->tableColumns($tableName);
        $data = $this->fixValues($columns, $data);
        $errors = [];
        foreach($this->databaseMetadata->foreignKeysAll()['references'][$tableName] ?? [] as $referencedTable => $constraints) {
            foreach($constraints as $constraintName => $fk) {
                $parameters = $this->keyValues($fk['referencing_column'], $columns, $data);
                if($parameters === NULL)
                    continue;
                $method = __METHOD__;
                $found = $this->sqlExecutor->row(
                  "SELECT /*$method*/ 1 FROM " . SqlUtils::fieldIt($referencedTable) . " WHERE " . self::whereEquals($fk['referenced_column']) . " LIMIT 1",
                  $parameters
                );
                if(!empty($found))
                    continue;
                $errors[$constraintName] = "The selected " . $this->labels($fk['referencing_column']) . " does not exist.";
            }
        }
        return $errors;
    }

    // -------------------------------------------------------------------------
    // Input fix
    // -------------------------------------------------------------------------

    /**
     * - Numbers that come as strings stay as strings: trimmed and commas removed.
     * - Integer columns: a zero fractional part is removed, "3.00" becomes "3".
     * - char/varchar are trimmed with SqlUtils::sTrim().
     * - Fields not in the table or query stay as they are.
     *
     * @param string $tableNameOrSelectQuery A table name, or a query starting with SELECT, WITH or (
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws Exception
     */
    public function fix(string $tableNameOrSelectQuery, array $data): array {
        $columns = preg_match('/^\s*(\(|SELECT\b|WITH\b)/i', $tableNameOrSelectQuery) ?
          $this->databaseMetadata->query($tableNameOrSelectQuery) :
          $this->tableColumns($tableNameOrSelectQuery);
        return $this->fixValues($columns, $data);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array> [colName => column metadata]
     * @throws Exception
     */
    protected function tableColumns(string $tableName): array {
        $columns = $this->databaseMetadata->table($tableName);
        if(empty($columns))
            throw new Exception("Table '$tableName' not found.");
        return $columns;
    }

    /**
     * @param array<string, array> $columns table() or query() metadata
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    protected function validateVsColumns(array $columns, array $data): array {
        $errors = [];
        foreach($this->fixValues($columns, $data) as $colName => $value) {
            if(!isset($columns[$colName]))
                continue;
            $error = self::validateValue($value, $columns[$colName]);
            if($error !== '')
                $errors[$colName] = $error;
        }
        return $errors;
    }

    /**
     * @param array<string, array> $columns table() or query() metadata
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function fixValues(array $columns, array $data): array {
        foreach($data as $colName => $value)
            if(isset($columns[$colName]))
                $data[$colName] = self::fixValue($value, $columns[$colName]);
        return $data;
    }

    protected static function fixValue(mixed $value, array $col): mixed {
        if(!is_string($value))
            return $value;
        $dataType = strtolower($col['data_type'] ?? '');
        switch($dataType) {
            case 'tinyint': case 'smallint': case 'int': case 'mediumint': case 'bigint': case 'year':
                $value = str_replace(',', '', trim($value));
                return preg_match('/^([+-]?\d+)\.0*$/', $value, $matches) ? $matches[1] : $value;
            case 'decimal': case 'numeric': case 'float': case 'double': case 'real':
                return str_replace(',', '', trim($value));
            case 'char': case 'varchar':
                return SqlUtils::sTrim($value);
        }
        return $value;
    }

    /**
     * The values of $colNames in $data, NULL when a column is missing from $data or its value is NULL.
     * An empty string counts as NULL for nullable non char/varchar columns, as sent by an empty HTML input.
     *
     * @param array<array-key, string> $colNames
     * @param array<string, array> $columns table() metadata
     * @param array<string, mixed> $data
     * @return list<mixed>|null
     */
    protected function keyValues(array $colNames, array $columns, array $data): ?array {
        if(empty($colNames))
            return NULL;
        $values = [];
        foreach($colNames as $colName) {
            $value = $data[$colName] ?? NULL;
            if($value === NULL || ($value === '' && self::isEmptyNull($columns[$colName] ?? [])))
                return NULL;
            $values[] = $value;
        }
        return $values;
    }

    /** @param array<array-key, string> $colNames  @return string "`col1` = ? AND `col2` = ?" */
    protected static function whereEquals(array $colNames): string {
        return implode(' AND ', array_map(static fn(string $colName): string => SqlUtils::fieldIt($colName) . " = ?", $colNames));
    }

    protected static function isEmptyNull(array $col): bool {
        return strcasecmp($col['is_nullable'] ?? 'NO', 'YES') === 0 &&
          !in_array(strtolower($col['data_type'] ?? ''), ['char', 'varchar'], TRUE);
    }

    /** @param array<array-key, string> $colNames */
    protected function labels(array $colNames): string {
        return implode(', ', array_map([SqlUtils::class, 'toLabel'], $colNames));
    }

    protected static function label(array $col): string {
        return SqlUtils::toLabel($col['name'] ?? '');
    }

    protected static function isNullable(array $col): bool {
        if(isset($col['is_nullable']))
            return strcasecmp($col['is_nullable'], 'YES') === 0;
        return !(($col['flags'] ?? 0) & MYSQLI_NOT_NULL_FLAG);
    }

    // -------------------------------------------------------------------------
    // Per-value type dispatch
    // -------------------------------------------------------------------------

    /** @return string '' when valid, else a user-friendly error */
    protected static function validateValue(mixed $value, array $col): string {
        $dataType = strtolower($col['data_type'] ?? '');

        if($value === NULL || $value === '') {
            if(!self::isNullable($col) && !str_contains(strtolower($col['extra'] ?? ''), 'auto_increment'))
                return self::label($col) . " is required.";
            return '';
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
            default => '',
        };
    }

    /**
     * Split a decimal number string into [sign, integerDigits, fractionDigits], NULL if not a decimal number.
     * Accepts "3", "-3.14", "+3.", ".5"; no exponent.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    protected static function decimalParts(mixed $value): ?array {
        if(!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', (string)$value, $matches) || ($matches[2] === '' && ($matches[3] ?? '') === ''))
            return NULL;
        return [$matches[1] === '-' ? '-' : '', $matches[2] === '' ? '0' : $matches[2], $matches[3] ?? ''];
    }

    // -------------------------------------------------------------------------
    // Integer types
    // -------------------------------------------------------------------------

    protected static function validateInteger(mixed $value, array $col): string {
        $label = self::label($col);
        $parts = self::decimalParts($value);
        if($parts === NULL)
            return "$label must be a whole number.";

        // Reject non-zero fractional part (e.g. "3.14") but accept "3.00"
        [$sign, $intPart, $fraction] = $parts;
        if(rtrim($fraction, '0') !== '')
            return "$label must be a whole number, $value has decimals.";

        $intStr = $sign . $intPart;
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

        if(isset($ranges[$dataType])) {
            [$min, $max] = $ranges[$dataType];
            if(bccomp($intStr, $min) < 0 || bccomp($intStr, $max) > 0)
                return "$label must be between $min and $max.";
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Decimal / numeric
    // -------------------------------------------------------------------------

    protected static function validateDecimal(mixed $value, array $col): string {
        $label = self::label($col);
        $parts = self::decimalParts($value);
        if($parts === NULL)
            return "$label must be a number.";

        [$sign, $intPart, $fraction] = $parts;
        $unsigned = str_contains(strtolower($col['Type'] ?? ''), 'unsigned');
        if($unsigned && $sign === '-' && trim($intPart . $fraction, '0') !== '')
            return "$label can not be negative.";

        // Unknown precision, ie a derived query column
        if(!isset($col['numeric_precision']))
            return '';
        $precision = (int)$col['numeric_precision'];
        $scale = (int)($col['numeric_scale'] ?? 0);

        // Round to defined scale without converting to float
        $rounded = bcadd($fraction === '' ? $intPart : "$intPart.$fraction", '0', $scale);

        // Max integer digits = precision - scale
        $maxIntDigits = $precision - $scale;
        $actualDigits = strlen(ltrim(explode('.', $rounded, 2)[0], '0'));
        if($actualDigits > $maxIntDigits)
            return "$label is too large, at most $maxIntDigits digits before the decimal point.";

        return '';
    }

    // -------------------------------------------------------------------------
    // Float / double
    // -------------------------------------------------------------------------

    protected static function validateFloat(mixed $value, array $col): string {
        if(!is_numeric($value))
            return self::label($col) . " must be a number.";
        return '';
    }

    // -------------------------------------------------------------------------
    // String types
    // -------------------------------------------------------------------------

    protected static function validateString(mixed $value, array $col): string {
        $maxLen = $col['character_maximum_length'] ?? NULL;
        if($maxLen === NULL)
            return '';
        $len = mb_strlen((string)$value, 'UTF-8');
        if($len > (int)$maxLen)
            return self::label($col) . " can have at most $maxLen characters, it has $len.";
        return '';
    }

    // -------------------------------------------------------------------------
    // ENUM
    // -------------------------------------------------------------------------

    protected static function validateEnum(mixed $value, array $col): string {
        $options = DatabaseMetadata::getInstance()->parseEnumSetOptions($col['Type'] ?? '');
        if(!isset($options[(string)$value])) {
            $allowed = implode(', ', array_keys($options));
            return self::label($col) . " must be one of: $allowed.";
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // SET
    // -------------------------------------------------------------------------

    protected static function validateSet(mixed $value, array $col): string {
        $options = DatabaseMetadata::getInstance()->parseEnumSetOptions($col['Type'] ?? '');
        $items = array_map('trim', explode(',', (string)$value));
        $invalid = [];
        foreach($items as $item)
            if(!isset($options[$item]))
                $invalid[] = $item;
        if(!empty($invalid)) {
            $allowed = implode(', ', array_keys($options));
            return self::label($col) . " has invalid options: " . implode(', ', $invalid) . ". Allowed: $allowed.";
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Date
    // -------------------------------------------------------------------------

    protected static function validateDate(mixed $value, array $col): string {
        $str = (string)$value;
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $str))
            return self::label($col) . " must be a date as yyyy-mm-dd.";
        [$y, $m, $d] = explode('-', $str);
        if(!checkdate((int)$m, (int)$d, (int)$y))
            return self::label($col) . " is not a valid date: $str.";
        return '';
    }

    // -------------------------------------------------------------------------
    // DateTime / Timestamp
    // -------------------------------------------------------------------------

    protected static function validateDateTime(mixed $value, array $col): string {
        $str = (string)$value;

        // Complete missing time parts
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
            $str .= ' 00:00:00';
        } elseif(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $str)) {
            $str .= ':00';
        } elseif(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $str)) {
            $str = str_replace('T', ' ', $str);
        } elseif(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $str)) {
            $str = str_replace('T', ' ', $str) . ':00';
        }

        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $str))
            return self::label($col) . " must be a date and time as yyyy-mm-dd hh:mm:ss.";

        [$datePart, $timePart] = explode(' ', $str, 2);
        [$y, $m, $d] = explode('-', $datePart);
        [$h, $i, $s] = explode(':', $timePart);

        if(!checkdate((int)$m, (int)$d, (int)$y))
            return self::label($col) . " is not a valid date: $datePart.";
        if((int)$h > 23 || (int)$i > 59 || (int)$s > 59)
            return self::label($col) . " is not a valid time: $timePart.";

        return '';
    }

    // -------------------------------------------------------------------------
    // Time
    // -------------------------------------------------------------------------

    protected static function validateTime(mixed $value, array $col): string {
        $str = (string)$value;

        // Complete missing seconds
        if(preg_match('/^-?\d{1,3}:\d{2}$/', $str))
            $str .= ':00';

        // MySQL TIME: -838:59:59 to 838:59:59
        if(!preg_match('/^-?\d{1,3}:\d{2}:\d{2}$/', $str))
            return self::label($col) . " must be a time as hh:mm:ss.";

        [$h, $i, $s] = explode(':', ltrim($str, '-'));
        if((int)$h > 838 || (int)$i > 59 || (int)$s > 59)
            return self::label($col) . " must be a time between -838:59:59 and 838:59:59.";

        return '';
    }

    // -------------------------------------------------------------------------
    // Bit
    // -------------------------------------------------------------------------

    protected static function validateBit(mixed $value, array $col): string {
        if($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1')
            return self::label($col) . " must be 0 or 1.";
        return '';
    }

}
