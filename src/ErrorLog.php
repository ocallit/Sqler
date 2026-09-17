<?php /** @noinspection PhpPipeOperatorCanBeUsedInspection */
/** @noinspection PhpUnused */
/** @noinspection SqlNoDataSourceInspection */

namespace Ocallit\Sqler;

use Throwable;
use Stringable;
use function array_key_exists;
use function array_keys;
use function array_diff;
use function array_merge;
use function array_slice;
use function array_values;
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
 *   ErrorLog::initialize($sqlExecutor, $userNick); // table error_log
 *
 * Registers the error handler, chaining any previously registered one, and a shutdown
 * function that adds php/json/preg last error and writes everything to the database.
 *
 * Collecting:
 *   ErrorLog::log($e) caught Throwable
 *   ErrorLog::log($message, "") business rule broken by the code
 *   ErrorLog::log($message, "Info) trace left to debug
 *   ErrorLog::javascriptErrors($array) rows posted by the JavaScript error api
 *   ErrorLog::sqlErrorLog($sql->getErrorLog())
 *
 * Fixing:
 *   ErrorLog::fix($errorHash, 'what was done') status Fixed, fixed=NOW(), fixed_times+1
 *
 * Only the first ErrorLog::$maxPerType distinct hashes of each error type are kept, repeats of
 * a kept hash increment seen_count. The hash is xxh3 of file|line|error_type|error_code,
 * of the query template for SQL errors, 0 when there is no line number.
 * On a repeat last_seen, seen_count, user_nick, user_agent, and request_uri are refreshed, and
 * status goes back to Bug unless it is Won't Fix, everything else keeps the first occurrence.
 * Creates the error_log table automatically on the first insert.
 */
class ErrorLog {
    public const string TYPE_PHP = 'PHP';
    public const string TYPE_SQL = 'SQL';
    public const string TYPE_JS = 'JS';
    public const string TYPE_DOMAIN = 'Domain';
    public const string TYPE_INFO = 'Info';

    /** Distinct errors kept per error type */
    public static int $maxPerType = 4;
    /** Longest error_message and content stored, cut to keep a runaway trace out of the query */
    public static int $maxTextLength = 65535;

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
    protected static bool $isSaving = false;
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
     * Generic log method: logs a Throwable, or logs a message as domain or info
     *
     * @param Throwable|string|Stringable $error
     * @param string $type
     * @return void
     */
    public static function log(Throwable|string|Stringable $error, string $type = 'info'): void {
        if($error instanceof Throwable) {
            self::throwable($error);
            return;
        }
        if(strcasecmp($type, 'domain') === 0 || $type === self::TYPE_DOMAIN) {
            self::domain((string)$error);
            return;
        }
        self::info((string)$error);
    }

    /**
     * Adds the JavaScript errors collected by an api
     *
     * @param array $errors [ ['file'=>, 'line_number'=>, 'column_number'=>, 'error_code'=>, 'error_message'=>,
     *   'function_name'=>, 'content'=>, 'request_uri'=>, 'user_agent'=>], ... ]
     *   line, column, message, stack and url also accepted
     * @return void
     */
    public static function javascriptErrors(array $errors): void {
        foreach($errors as $error) {
            if(!is_array($error))
                continue;
            $file = (string)($error['file'] ?? $error['filename'] ?? '');
            $lineNumber = (int)($error['line_number'] ?? $error['line'] ?? 0);
            $errorCode = $error['error_code'] ?? $error['code'] ?? '';
            self::add(self::TYPE_JS, self::hashIt($file, $lineNumber, self::TYPE_JS, $errorCode), [
              'error_code' => $errorCode,
              'error_message' => (string)($error['error_message'] ?? $error['message'] ?? ''),
              'file' => $file,
              'line_number' => $lineNumber,
              'column_number' => (int)($error['column_number'] ?? $error['column'] ?? 0),
              'function_name' => (string)($error['function_name'] ?? $error['function'] ?? ''),
              'content' => (string)($error['content'] ?? $error['stack'] ?? ''),
              'request_uri' => (string)($error['request_uri'] ?? $error['url'] ?? self::requestUri()),
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
        foreach($sqlErrorLog as $hash => $error) {
            if(!is_array($error))
                continue;
            $template = (string)($error['template'] ?? '');
            $query = is_string($error['query'] ?? '') ? $error['query'] : $template;
            $parameters = is_array($error['parameters'] ?? []) ? $error['parameters'] : [];
            if(!empty($parameters))
                $query .= PHP_EOL ." -- (" . implode(", ", $parameters) . ")";
            $stackTrace = is_array($error['stack_trace'] ?? null) ? $error['stack_trace'] : ($error['stack trace'] ?? []);
            $caller = is_array($stackTrace[0] ?? null) ? $stackTrace[0] : [];
            self::add(self::TYPE_SQL, $hash, [
              'error_code' => $error['error'] ?? '',
              'error_message' => (string)($error['error_message'] ?? ''),
              'file' => (string)($caller['file'] ?? ''),
              'line_number' => (int)($caller['line'] ?? 0),
              'function_name' => self::functionIt(array_slice($stackTrace, 1)),
              'content' => self::traceIt($stackTrace),
              'query' => $query,
            ]);
        }
    }

    /**
     * Registers that an error was fixed, does not touch last_seen so last_seen > fixed
     * means the error came back after this fix
     *
     * @param string $errorHash
     * @param string $comment developer notes, keeps the stored one when empty
     * @return void
     */
    public static function fix(string $errorHash, string $comment = ''): void {
        if(self::$sqlExecutor === null)
            return;
        $table = SqlUtils::fieldIt(self::$table);
        $method = __METHOD__;
        $set = "`status`='Fixed',`fixed`=NOW(),`fixed_times`=`fixed_times`+1";
        $parameters = [];
        if($comment !== '') {
            $set .= ",`comment`=?";
            $parameters[] = self::text($comment, self::$maxTextLength);
        }
        $parameters[] = $errorHash;
        try {
            self::$sqlExecutor->query("UPDATE /* $method */ $table SET $set WHERE `error_hash`=?", $parameters);
        } catch (Throwable) { }
    }

    /**
     * @return array<string, array<string, mixed>> the errors collected so far, hash => error
     * @pure
     */
    public static function getErrors(): array {return self::$errors;}

    /**
     * Registered error handler, stores the error and calls the previously registered handler
     *
     * @param int $errorNumber
     * @param string $errorMessage
     * @param string $file
     * @param int $lineNumber
     * @return bool false to let php's internal handler run when there was no previous handler
     */
    public static function errorHandler(int $errorNumber, string $errorMessage, string $file = '', int $lineNumber = 0): bool {
        $frames = self::callerFrames();
        self::add(self::TYPE_PHP, self::hashIt($file, $lineNumber, self::TYPE_PHP, $errorNumber), [
          'error_code' => $errorNumber,
          'error_message' => $errorMessage,
          'file' => $file,
          'line_number' => $lineNumber,
          'function_name' => self::functionIt($frames),
          'content' => self::traceIt($frames),
        ]);
        if(self::$previousErrorHandler !== null)
            return (bool)call_user_func(self::$previousErrorHandler, $errorNumber, $errorMessage, $file, $lineNumber);
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
              self::hashIt($lastError['file'] ?? '', $lastError['line'] ?? 0, self::TYPE_PHP, $lastError['type'] ?? 0), [
                'error_code' => $lastError['type'] ?? 0,
                'error_message' => $lastError['message'] ?? '',
                'file' => $lastError['file'] ?? '',
                'line_number' => $lastError['line'] ?? 0,
              ]);
        if(json_last_error() !== JSON_ERROR_NONE)
            self::add(self::TYPE_PHP, self::hashIt('json_last_error', 0, self::TYPE_PHP, json_last_error()), [
              'error_code' => json_last_error(),
              'error_message' => json_last_error_msg(),
              'function_name' => 'json_last_error',
            ]);
        if(preg_last_error() !== PREG_NO_ERROR)
            self::add(self::TYPE_PHP, self::hashIt('preg_last_error', 0, self::TYPE_PHP, preg_last_error()), [
              'error_code' => preg_last_error(),
              'error_message' => preg_last_error_msg(),
              'function_name' => 'preg_last_error',
            ]);
        if(self::$sqlExecutor !== null)
            self::sqlErrorLog(self::$sqlExecutor->getErrorLog());
        self::save();
    }

    /**
     * Adds a caught Throwable
     *
     * @param Throwable $throwable
     * @return void
     */
    protected static function throwable(Throwable $throwable): void {
        $frame = $throwable->getTrace()[0] ?? [];
        self::add(self::TYPE_PHP,
          self::hashIt($throwable->getFile(), $throwable->getLine(), self::TYPE_PHP, $throwable->getCode()), [
            'error_code' => $throwable->getCode(),
            'error_message' => $throwable::class . ': ' . $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line_number' => $throwable->getLine(),
            'function_name' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''),
            'content' => $throwable->getTraceAsString(),
          ]);
    }

    /**
     * Adds a domain error: a business rule broken by the code, never by user input,
     * a defect the code caught instead of crashing. Invalid user input is validation,
     * it is rejected and shown to the user, it is not logged here.
     *
     * @param string $errorMessage
     * @param int|string $errorCode business rule code
     * @param string $content extra information
     * @return void
     */
    protected static function domain(string $errorMessage, int|string $errorCode = '', string $content = ''): void {
        self::rule(self::TYPE_DOMAIN, $errorMessage, $errorCode, $content);
    }

    /**
     * Adds a trace the developer places to help debug, to be deleted from the code, or
     * closed here, once it has served its purpose
     *
     * @param string $errorMessage
     * @param int|string $errorCode
     * @param string $content extra information
     * @return void
     */
    protected static function info(string $errorMessage, int|string $errorCode = '', string $content = ''): void {
        self::rule(self::TYPE_INFO, $errorMessage, $errorCode, $content);
    }

    /**
     * Writes the collected errors and empties the log, called by the shutdown function
     *
     * @return void
     */
    protected static function save(): void {
        if(self::$isSaving || self::$sqlExecutor === null || self::$queryBuilder === null || empty(self::$errors))
            return;
        self::$isSaving = true;
        $errors = self::$errors;
        self::$errors = [];
        self::$kept = [];
        $table = SqlUtils::fieldIt(self::$table);
        $refresh = ['last_seen', 'seen_count', 'status', 'user_nick', 'user_agent', 'request_uri'];
        $override = [
          'seen_count' => "$table.`seen_count`+new.`seen_count`",
          'status' => "IF($table.`status`='Won''t Fix',$table.`status`,'Bug')",
        ];
        foreach($errors as $hash => $error) {
            $values = [
              'error_hash' => $hash,
              'first_seen' => 'NOW(6)',
              'last_seen' => 'NOW(6)',
              'seen_count' => $error['seen_count'],
              'status' => 'Bug',
              'error_type' => $error['error_type'],
              'error_code' => self::text((string)$error['error_code'], 32),
              'error_message' => self::text($error['error_message'], self::$maxTextLength),
              'content' => self::text($error['content'], self::$maxTextLength),
              'query' => self::text($error['query'], self::$maxTextLength),
              'file' => self::text($error['file'], 500),
              'function_name' => self::text($error['function_name'], 255),
              'line_number' => $error['line_number'],
              'column_number' => $error['column_number'],
              'request_uri' => self::text($error['request_uri'], 1000),
              'user_nick' => self::text($error['user_nick'], 16),
              'user_agent' => self::text($error['user_agent'], 1000),
                'comment' => '',
            ];
            $insert = self::$queryBuilder->insert(self::$table, $values, true,
              array_values(array_diff(array_keys($values), $refresh)), $override, __METHOD__);
            self::write($insert);
        }
        self::$isSaving = false;
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

    protected static function rule(string $errorType, string $errorMessage, int|string $errorCode, string $content): void {
        $frames = [];
        foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9) as $frame) {
            if(($frame['file'] ?? '') === __FILE__)
                continue;
            $frames[] = $frame;
        }
        $caller = $frames[0] ?? [];
        self::add($errorType, self::hashIt($caller['file'] ?? '', $caller['line'] ?? 0, $errorType, $errorCode), [
          'error_code' => $errorCode,
          'error_message' => $errorMessage,
          'file' => $caller['file'] ?? '',
          'line_number' => $caller['line'] ?? 0,
          'function_name' => self::functionIt(array_slice($frames, 1)),
          'content' => $content === '' ? self::traceIt($frames) : $content,
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
              'error_code' => '',
              'error_message' => '',
              'content' => '',
              'query' => '',
              'file' => '',
              'function_name' => '',
              'line_number' => 0,
              'column_number' => 0,
              'request_uri' => self::requestUri(),
              'user_nick' => self::$nick,
              'user_agent' => self::server('HTTP_USER_AGENT'),
              'seen_count' => 1,
            ], $error);
            if(self::$errors[$hash]['file'] === '')
                self::$errors[$hash]['file'] = self::scriptFile();
        }
    }

    /**
     * @pure
     */
    protected static function hashIt(string $file, int|string $lineNumber, string $errorType, int|string $errorCode): string {
        return hash('xxh3', "$file|$lineNumber|$errorType|$errorCode");
    }

    /**
     * @return array the stack frames outside this class
     */
    protected static function callerFrames(): array {
        $frames = [];
        foreach(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9) as $frame)
            if(($frame['class'] ?? '') !== self::class)
                $frames[] = $frame;
        return $frames;
    }

    /**
     * @param array $frames
     * @return string the function the error occurred in
     */
    protected static function functionIt(array $frames): string {
        $frame = $frames[0] ?? [];
        return ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
    }

    protected static function traceIt(array $frames): string {
        $trace = [];
        foreach($frames as $frame)
            $trace[] = ($frame['file'] ?? '') . ':' . ($frame['line'] ?? 0) . ' ' .
              ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
        return implode("\n", $trace);
    }

    /**
     * Cuts the text to length and drops the invalid utf8 the cut or the error itself may leave,
     * mysql rejects invalid utf8, and an error logger must not fail on the error it is logging
     */
    protected static function text(string $text, int $length): string {
        $text = json_decode(json_encode(substr($text, 0, $length), SqlUtils::JSON_MYSQL_OPTIONS), true);
        return is_string($text) ? $text : '';
    }

    /**
     * @return string the script that ran, the php binary when there is none, used when the
     *   error carries no file
     */
    protected static function scriptFile(): string {
        $file = self::server('SCRIPT_FILENAME');
        if($file === '')
            $file = self::server('PHP_SELF');
        return $file === '' ? PHP_BINARY : $file;
    }

    protected static function server(string $key): string {
        $value = $_SERVER[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    protected static function requestUri(): string {
        $host = self::server('HTTP_HOST');
        if($host === '')
            return self::server('REQUEST_URI');
        /** @noinspection HttpUrlsUsage */
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
            `error_hash` VARCHAR(16) NOT NULL PRIMARY KEY COMMENT 'xxh3 hash of the normalized error template: file|line|error_type|error_code or sqlQueryTemplate',

            `first_seen` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'When this error template was first encountered',
            `last_seen` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'When this error template was last encountered',
            `seen_count` MEDIUMINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Number of times this error template has occurred. Adds 1 per run',

            `status` ENUM('Bug', 'Fixed', 'Won''t Fix') NOT NULL DEFAULT 'Bug' COMMENT 'Resolution status, every error_type starts as Bug and stays until closed as Fixed, or as Won''t Fix to keep it out of the pending list for good. A new occurrence returns Fixed to Bug, it does not touch Won''t Fix',
            `error_type` ENUM('SQL', 'PHP', 'JS', 'Domain', 'Info') NOT NULL COMMENT 'What raised it. SQL, PHP, JS: runtime errors. Domain: a business rule broken by the code, never by user input, a defect the code caught instead of crashing, invalid user input is validation and is not logged here. Info: a trace the developer placed to help debug, deleted from the code, or closed here, once it has served its purpose',
            `error_code` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'Error code (errno, SQL error code, HTTP status, etc.)',
            `error_message` MEDIUMTEXT COMMENT 'Original error message',
            `content` MEDIUMTEXT COMMENT 'Original error content, extra info (message, stack trace, etc.)',
            `query` MEDIUMTEXT COMMENT 'Query and its parameters on SQL errors',

            `file` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'File where error occurred, query template on SQL errors',
            `function_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Function/method name where error occurred',
            `line_number` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Line number (stored and used in hash)',
            `column_number` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Column number (stored but not used in hash)',

            `request_uri` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'URL/URI where error occurred',
            `user_nick` VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'User nickname if available when last error occurred',
            `user_agent` TEXT COMMENT 'Browser user agent, if available, for last error',

            `fixed` DATETIME NULL COMMENT 'Last time it was fixed',
            `fixed_times` MEDIUMINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times fixed',
            `comment` LONGTEXT COMMENT 'Developer notes and comment about this error',
            KEY usual_view(`status`, `last_seen` DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Error logging and tracking'");
    }

}
