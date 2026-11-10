<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use InvalidArgumentException;
use RuntimeException;
use \Stringable;
use function explode;
use function str_replace;
use function chr;

class SqlUtils {
    const int JSON_MYSQL_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING;

    protected function __construct() {}

    /**
     * What a server is and its version, from the string it reports (SELECT VERSION(), mysqli::$server_info).
     * MariaDB: "10.11.14-MariaDB-0ubuntu0.24.04.1", behind a proxy or replication "5.5.5-10.6.12-MariaDB" (the 5.5.5- is dropped).
     * MySQL and the servers that speak like it: "8.0.36", "5.7.44-log", "8.0.mysql_aurora.3.04.0" (missing numbers are 0).
     *
     * @pure
     * @param string $raw
     * @return array{type: string, version: string, major: int, minor: int, patch: int, raw: string}
     *   type is 'mariadb' or 'mysql', version is "major.minor.patch" for version_compare()
     */
    public static function parseServerVersion(string $raw): array {
        $raw = trim($raw);
        $isMariaDb = stripos($raw, 'mariadb') !== false;
        $numbers = $isMariaDb ? preg_replace('/^5\.5\.5-/', '', $raw) : $raw;
        preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $numbers, $match);
        $major = (int)($match[1] ?? 0);
        $minor = (int)($match[2] ?? 0);
        $patch = (int)($match[3] ?? 0);
        return [
          'type' => $isMariaDb ? 'mariadb' : 'mysql',
          'version' => "$major.$minor.$patch",
          'major' => $major,
          'minor' => $minor,
          'patch' => $patch,
          'raw' => $raw,
        ];
    }

    /**
     * The utf8mb4 collations to try, best first, for text that compares accent insensitive and case insensitive (ai_ci), by server type and version:
     * MySQL 8.0.1+ has utf8mb4_0900_ai_ci; older MySQL and every MariaDB use utf8mb4_unicode_ci, which every one of these servers has.
     * (MariaDB 10.10+ documents utf8mb4_uca1400_ai_ci, but not every build has it, so it is not used.)
     *
     * @pure
     * @param array{type: string, major: int, minor: int, patch: int} $serverInfo the result of parseServerVersion()
     * @return string[]
     */
    public static function collationAiCiCandidates(array $serverInfo): array {
        $version = $serverInfo['major'] * 10000 + $serverInfo['minor'] * 100 + $serverInfo['patch'];
        if($serverInfo['type'] === 'mariadb')
            return ['utf8mb4_unicode_ci'];
        return $version >= 80001 ? ['utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci'] : ['utf8mb4_unicode_ci'];
    }

    /**
     * Changes a column name to a nice label or title
     *
     * @phpstan-pure
     * @param string $fieldName
     * @return string
     */
    public static function toLabel(string $fieldName): string {
        return ucwords(strtolower(str_replace('_', ' ', $fieldName)));
    }

    /**
     * Protect with ` backticks for column name to column name respecting . table.column to `table`.`column`
     *
     * @phpstan-pure
     * @param string $fieldName
     * @return string The protected field name (e.g., '`table`.`column`').
     * @phpstan-pure
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
     * Trim and make multiple spaces with a single space
     * @phpstan-pure
     * @param string|int|float|bool|null|Stringable $s
     * @return string
     */
    public static function sTrim(string|int|float|bool|null|Stringable $s):string {
        if($s === NULL)
            return '';
        return trim( preg_replace('/\s\s+/', ' ', (string)$s));
    }

    /**
     *
     * @phpstan-pure
     * @param string $comment
     * @return string
     */
    public static function commentIt(string $comment):string {
        if(empty($comment))
            return "";
        return  str_replace("*/", "*", $comment);
    }

    /**
     * @phpstan-pure
     * @psalm-pure
     */
    public static function strIt(string|null $str): string {
        if($str === NULL)
            return 'NULL';
        if($str === '0') return "'0'";
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
     * @phpstan-pure
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

    /**
     * Convert named SQL placeholders to mysqli positional placeholders.
     *
     * Supports :name, :key#, and :{arbitrary key}.
     * Ignores placeholders inside quotes, backticks, and SQL comments.
     *
     * @phpstan-pure
     * @return array{string, array}
     * @throws InvalidArgumentException If a parameter is missing.
     */
    public static function  namedToPositional(string $query, array $params): array {
        $values = [];

        $pattern = <<<'REGEX'
~('(?:\\.|''|[^'\\])*'|"(?:\\.|""|[^"\\])*"|`(?:``|[^`])*`|--[ \t][^\r\n]*|\#[^\r\n]*|/\*[\s\S]*?\*/)|:\{([^{}]+)\}|:([A-Za-z0-9_#]+)~
REGEX;

        $sql = preg_replace_callback(
          $pattern,
          static function(array $match) use ($params, &$values): string {
              // Preserve quoted strings, identifiers, and comments.
              if($match[1] !== '') {
                  return $match[0];
              }

              $key = $match[2] !== '' ? $match[2] : $match[3];

              if(!array_key_exists($key, $params)) {
                  throw new InvalidArgumentException(
                    "Missing SQL parameter: $key"
                  );
              }

              $values[] = $params[$key];

              return '?';
          },
          $query
        );

        if($sql === NULL)
            throw new RuntimeException('Could not parse SQL placeholders.');

        return [$sql, $values];
    }


}
