<?php
/** @noinspection PhpUnused */
/** @noinspection SqlNoDataSourceInspection */

namespace Ocallit\Sqler;

use Throwable;
use function array_key_exists;
use function array_keys;
use function array_diff;
use function array_values;
use function array_merge;
use function call_user_func;
use function hash;
use function implode;
use function is_array;
use function is_string;
use function substr;

/**
 * ErrorLog Quick Reference:
 *
 * Setup, once, as early as possible:
 *   ErrorLog::initialize($sqlExecutor, $userNick);              // table error_log
 *   ErrorLog::initialize($sqlExecutor, $userNick, 'app_error'); // other table
 *
 * Registers the error handler, chaining any previously registered one, and a shutdown
 * function that adds php/json/preg last error and writes everything to the database.
 *
 * Collecting:
 *   ErrorLog::throwable($e)                 catched Throwable
 *   ErrorLog::domain($message, $code)       business rule worthy of a record
 *   ErrorLog::info($message, $code)         anything else worth a record
 *   ErrorLog::javascriptErrors($array)      rows posted by the javascript error api
 *   ErrorLog::sqlErrorLog($sql->getErrorLog())
 *
 * Only the first ErrorLog::$maxPerType distinct hashes of each type are kept, repeats of
 * a kept hash only increment seen_times. The hash is xxh128 of filename, error number and
 * line number, 0 when there is no line number, of the query template for sql errors.
 * Creates the error_log table automatically on first write.
 */
class ErrorLog {
    public const string TYPE_PHP = 'php';
    public const string TYPE_SQL = 'sql';
    public const string TYPE_JS = 'js';
    public const string TYPE_DOMAIN = 'domain';
    public const string TYPE_INFO = 'info';

    /** Distinct errors kept per error type */
    public static int $maxPerType = 4;

    protected static ?SqlExecutor $sqlExecutor = null;
    protected static ?QueryBuilder $queryBuilder = null;
    protected static string $table = 'error_log';
    protected static string $nick = '';

    /** @var array<string, array<string, mixed>> hash => error */
    protected static array $errors = [];
    /** @var array<string, int> error type => distinct errors kept */
    protected static array $kept = [];

    protected static mixed $previousErrorHandler = null;
    protected static bool $isRegistered = false;
    protected static bool $isFlushing = false;
    protected static bool $isTableCreated = false;

    /**
     * Configures the log and registers the error handler and the shutdown function
     *
     * @param SqlExecutor $sqlExecutor configured instance
     * @param string $nick user's nick name
     * @param string $table
     * @return void
     */
    public static function initialize(SqlExecutor $sqlExecutor, string $nick, string $table = 'error_log'): void {
        self::$sqlExecutor = $sqlExecutor;
        self::$queryBuilder = new QueryBuilder();
        self::$nick = $nick;
        self::$table = $table;
        if(self::$isRegistered)
            return;
        self::$isRegistered = true;
        self::$previousErrorHandler = set_error_handler([self::class, 'errorHandler']);
        register_shutdown_function([self::class, 'shutdownHandler']);
    }

    /**
     * Registered error handler, stores the error and calls the previously registered handler
     *
     * @param int $errorNumber
     * @param string $errorMessage
     * @param string $filename
     * @param int $lineNumber
     * @return bool false to let php's internal handler run when there was no previous handler
     */
    public static function errorHandler(int $errorNumber, string $errorMessage, string $filename = '', int $lineNumber = 0): bool {
        self::add(self::TYPE_PHP, self::hashIt($filename, $errorNumber, $lineNumber), [
          'error_number' => $errorNumber,
          'error_message' => $errorMessage,
          'filename' => $filename,
          'linenumber' => $lineNumber,
          'stack_trace' => self::backtrace(),
        ]);
        if(self::$previousErrorHandler !== null)
            return (bool)call_user_func(self::$previousErrorHandler, $errorNumber, $errorMessage, $filename, $lineNumber);
        return false;
    }

    /**
     * Registered shutdown function, adds the last php, json and preg error and writes the log
     *
     * @return void
     */
    public static function shutdownHandler(): void {
        $lastError = error_get_last();
        if(is_array($lastError))
            self::add(self::TYPE_PHP,
              self::hashIt($lastError['file'] ?? '', $lastError['type'] ?? 0, $lastError['line'] ?? 0), [
                'error_number' => $lastError['type'] ?? 0,
                'error_message' => $lastError['message'] ?? '',
                'filename' => $lastError['file'] ?? '',
                'linenumber' => $lastError['line'] ?? 0,
              ]);
        if(json_last_error() !== JSON_ERROR_NONE)
            self::add(self::TYPE_PHP, self::hashIt('json_last_error', json_last_error(), 0), [
              'error_number' => json_last_error(),
              'error_message' => json_last_error_msg(),
              'filename' => 'json_last_error',
            ]);
        if(preg_last_error() !== PREG_NO_ERROR)
            self::add(self::TYPE_PHP, self::hashIt('preg_last_error', preg_last_error(), 0), [
              'error_number' => preg_last_error(),
              'error_message' => preg_last_error_msg(),
              'filename' => 'preg_last_error',
            ]);
        self::flush();
    }

    /**
     * Adds a catched Throwable
     *
     * @param Throwable $throwable
     * @return void
     */
    public static function throwable(Throwable $throwable): void {
        self::add(self::TYPE_PHP,
          self::hashIt($throwable->getFile(), $throwable->getCode(), $throwable->getLine()), [
            'error_number' => $throwable->getCode(),
            'error_message' => $throwable::class . ': ' . $throwable->getMessage(),
            'filename' => $throwable->getFile(),
            'linenumber' => $throwable->getLine(),
            'stack_trace' => $throwable->getTraceAsString(),
          ]);
    }

    /**
     * Adds a domain or business rule worthy of a record
     *
     * @param string $errorMessage
     * @param int|string $errorNumber business rule code
     * @param string $filename
     * @param int $lineNumber
     * @return void
     */
    public static function domain(string $errorMessage, int|string $errorNumber = '', string $filename = '', int $lineNumber = 0): void {
        self::rule(self::TYPE_DOMAIN, $errorMessage, $errorNumber, $filename, $lineNumber);
    }

    /**
     * Adds an informative record
     *
     * @param string $errorMessage
     * @param int|string $errorNumber
     * @param string $filename
     * @param int $lineNumber
     * @return void
     */
    public static function info(string $errorMessage, int|string $errorNumber = '', string $filename = '', int $lineNumber = 0): void {
        self::rule(self::TYPE_INFO, $errorMessage, $errorNumber, $filename, $lineNumber);
    }

    /**
     * Adds the javascript errors collected by an api
     *
     * @param array $errors [ ['filename'=>, 'error_number'=>, 'error_message'=>, 'linenumber'=>,
     *   'stack_trace'=>, 'url'=>, 'user_agent'=>], ... ] file, message, line and stack also accepted
     * @return void
     */
    public static function javascriptErrors(array $errors): void {
        foreach($errors as $error) {
            if(!is_array($error))
                continue;
            $filename = (string)($error['filename'] ?? $error['file'] ?? '');
            $errorNumber = $error['error_number'] ?? $error['code'] ?? '';
            $lineNumber = (int)($error['linenumber'] ?? $error['line'] ?? 0);
            self::add(self::TYPE_JS, self::hashIt($filename, $errorNumber, $lineNumber), [
              'error_number' => $errorNumber,
              'error_message' => (string)($error['error_message'] ?? $error['message'] ?? ''),
              'filename' => $filename,
              'linenumber' => $lineNumber,
              'stack_trace' => (string)($error['stack_trace'] ?? $error['stack'] ?? ''),
              'url' => (string)($error['url'] ?? self::url()),
              'user_agent' => (string)($error['user_agent'] ?? self::server('HTTP_USER_AGENT')),
            ]);
        }
    }

    /**
     * Adds the error log of a SqlExecutor, SqlExecutor::getErrorLog()
     *
     * @param array $sqlErrorLog [ template => ['error'=>, 'error message'=>, 'query'=>, 'parameters'=>, 'attempt'=>, 'template'=>] ]
     * @return void
     */
    public static function sqlErrorLog(array $sqlErrorLog): void {
        foreach($sqlErrorLog as $template => $error) {
            if(!is_array($error))
                continue;
            $query = is_string($error['query'] ?? '') ? $error['query'] : (string)($error['template'] ?? $template);
            $parameters = is_array($error['parameters'] ?? []) ? $error['parameters'] : [];
            if(!empty($parameters))
                $query .= " -- (" . implode(", ", $parameters) . ")";
            self::add(self::TYPE_SQL, hash('xxh128', (string)$template), [
              'error_number' => $error['error'] ?? '',
              'error_message' => (string)($error['error message'] ?? ''),
              'filename' => (string)($error['template'] ?? $template),
              'stack_trace' => $query,
            ]);
        }
    }

    /**
     * @return array<string, array<string, mixed>> the errors collected so far, hash => error
     * @pure
     */
    public static function getErrors(): array {return self::$errors;}

    /**
     * Writes the collected errors and empties the log, called by the shutdown function
     *
     * @return void
     */
    public static function flush(): void {
        if(self::$isFlushing || self::$sqlExecutor === null || self::$queryBuilder === null || empty(self::$errors))
            return;
        self::$isFlushing = true;
        $errors = self::$errors;
        self::$errors = [];
        self::$kept = [];
        $seenTimes = SqlUtils::fieldIt(self::$table) . '.' . SqlUtils::fieldIt('seen_times');
        foreach($errors as $hash => $error) {
            $values = [
              'error_hash' => $hash,
              'first_seen' => 'NOW(6)',
              'last_seen' => 'NOW(6)',
              'seen_times' => $error['seen_times'],
              'error_type' => $error['error_type'],
              'error_number' => (string)$error['error_number'],
              'error_message' => $error['error_message'],
              'filename' => substr($error['filename'], 0, 255),
              'linenumber' => $error['linenumber'],
              'stack_trace' => $error['stack_trace'],
              'user_agent' => substr($error['user_agent'], 0, 255),
              'php_self' => substr($error['php_self'], 0, 255),
              'url' => $error['url'],
              'nick' => substr($error['nick'], 0, 32),
            ];
            $insert = self::$queryBuilder->insert(self::$table, $values, true,
              array_values(array_diff(array_keys($values), ['seen_times', 'last_seen', 'nick'])),
              ['seen_times' => "$seenTimes+new." . SqlUtils::fieldIt('seen_times')],
              __METHOD__);
            self::write($insert);
        }
        self::$isFlushing = false;
    }

    /**
     * @param array{query: string, parameters: array} $insert
     * @return void
     */
    protected static function write(array $insert): void {
        try {
            self::$sqlExecutor->query($insert['query'], $insert['parameters']);
            return;
        } catch (Throwable) { }
        if(self::$isTableCreated || !self::$sqlExecutor->is_last_error_table_not_found())
            return;
        self::$isTableCreated = true;
        try {
            self::tableCreate();
            self::$sqlExecutor->query($insert['query'], $insert['parameters']);
        } catch (Throwable) { }
    }

    protected static function rule(string $errorType, string $errorMessage, int|string $errorNumber, string $filename, int $lineNumber): void {
        if($filename === '') {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
            $filename = $caller['file'] ?? '';
            $lineNumber = $caller['line'] ?? 0;
        }
        self::add($errorType, self::hashIt($filename, $errorNumber, $lineNumber), [
          'error_number' => $errorNumber,
          'error_message' => $errorMessage,
          'filename' => $filename,
          'linenumber' => $lineNumber,
          'stack_trace' => self::backtrace(),
        ]);
    }

    /**
     * Stores the error when it is new and there is room for its type, counts it when it repeats
     *
     * @param string $errorType
     * @param string $hash
     * @param array $error
     * @return void
     */
    protected static function add(string $errorType, string $hash, array $error): void {
        if(!array_key_exists($hash, self::$errors)) {
            if((self::$kept[$errorType] ?? 0) >= self::$maxPerType)
                return;
            self::$kept[$errorType] = (self::$kept[$errorType] ?? 0) + 1;
            self::$errors[$hash] = array_merge([
              'error_type' => $errorType,
              'error_number' => '',
              'error_message' => '',
              'filename' => '',
              'linenumber' => 0,
              'stack_trace' => '',
              'user_agent' => self::server('HTTP_USER_AGENT'),
              'php_self' => self::server('PHP_SELF'),
              'url' => self::url(),
              'nick' => self::$nick,
              'seen_times' => 0,
            ], $error);
        }
        ++self::$errors[$hash]['seen_times'];
    }

    /**
     * @pure
     */
    protected static function hashIt(string $filename, int|string $errorNumber, int $lineNumber): string {
        return hash('xxh128', "$filename\t$errorNumber\t$lineNumber");
    }

    protected static function backtrace(): string {
        $trace = [];
        foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if(($frame['class'] ?? '') === self::class)
                continue;
            $trace[] = ($frame['file'] ?? '') . ':' . ($frame['line'] ?? 0) . ' ' .
              ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
        }
        return implode("\n", $trace);
    }

    protected static function server(string $key): string {
        $value = $_SERVER[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    protected static function url(): string {
        $host = self::server('HTTP_HOST');
        if($host === '')
            return self::server('REQUEST_URI');
        return (self::server('HTTPS') === '' ? 'http://' : 'https://') . $host . self::server('REQUEST_URI');
    }

    /**
     * Create the error log table if it doesn't exist
     *
     * @return void
     */
    protected static function tableCreate(): void {
        $method = __METHOD__;
        self::$sqlExecutor->query("
        CREATE /* $method */ TABLE IF NOT EXISTS " . SqlUtils::fieldIt(self::$table) . " (
            `error_hash` CHAR(32) NOT NULL PRIMARY KEY COMMENT 'xxh128 of filename, error number and line number',
            `first_seen` DATETIME(6) NOT NULL,
            `last_seen` DATETIME(6) NOT NULL,
            `seen_times` MEDIUMINT UNSIGNED NOT NULL DEFAULT 1,
            `error_type` VARCHAR(8) NOT NULL COMMENT 'php, sql, js, domain, info',
            `error_number` VARCHAR(32) NOT NULL DEFAULT '',
            `error_message` TEXT,
            `filename` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'query template on sql errors',
            `linenumber` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
            `stack_trace` TEXT COMMENT 'query and parameters on sql errors',
            `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
            `php_self` VARCHAR(255) NOT NULL DEFAULT '',
            `url` TEXT,
            `nick` VARCHAR(32) NOT NULL DEFAULT '',
            `status` ENUM('bug','fixed','won''t fix') NOT NULL DEFAULT 'bug',
            `fixed_date` DATETIME(6) NULL DEFAULT NULL,
            `fixed_times` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `comment` TEXT,
            KEY pending(`status`, `last_seen` DESC)
        )");
    }

}
