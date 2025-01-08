<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use Throwable;
use function array_key_exists;
use function array_merge;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function mysqli_connect_error;
use function mysqli_connect_errno;
use function usleep;


class SqlExecutor {
    protected mysqli|null $mysqli;
    protected string $charset = 'utf8mb4';
    protected string $coalition = 'utf8mb4_0900_ai_ci';

    /**
     * @var array{
     * hostname: ?string,
     * username: ?string,
     * password: ?string,
     * database: ?string,
     * port: ?string,
     * socket: ?string,
     * flags: int,
     * }
     */
    protected array $connect = [
      'hostname' => null,
      'username' => null,
      'password' => null,
      'database' => null,
      'port' => null,
      'socket' => null,
      'flags' => 0,
    ];

    protected array $connect_options = [
      MYSQLI_INIT_COMMAND => 'SET AUTOCOMMIT = 1',
        //    'MYSQLI_OPT_CONNECT_TIMEOUT' => 10,
    ];

    protected int $flags = 0;

    protected int $retries = 3;
    protected int $retrySleep = 50;
    protected int $maxLogEntries = 256;

    // Database connection error constants
    protected const ERROR_CANT_LOCK = 1015;
    protected const ERROR_LOCK_ABORTED = 1689;
    protected const ERROR_LOCK_WAIT_TIMEOUT = 1205;
    protected const ERROR_LOCK_TABLE_FULL = 1206;
    protected const ERROR_LOCK_DEADLOCK = 1213;
    protected const ERROR_TRANSACTION_ROLLBACK = 1622;
    protected const ERROR_XA_DEADLOCK = 1614;
    protected const ERROR_SERVER_GONE = 2006;
    protected const ERROR_SERVER_LOST = 2013;
    protected const ERROR_PROBE_SLAVE_CONNECT = 2024;
    protected const ERROR_PROBE_MASTER_CONNECT = 2025;
    protected const ERROR_SSL_CONNECTION = 2026;
    protected const ERROR_PACKET_TOO_LARGE = 2020;

    // Table and constraint error constants
    protected const ERROR_TABLE_NOT_FOUND = 1146;
    protected const ERROR_NO_SUCH_TABLE = 1051;
    protected const ERROR_UNKNOWN_TABLE = 1109;
    protected const ERROR_UNIQUE_VIOLATION = 1062;
    protected const ERROR_PRIMARY_KEY_VIOLATION = 1022;
    protected const ERROR_FOREIGN_KEY_VIOLATION = 1216;
    protected const ERROR_FOREIGN_KEY_PARENT_NOT_FOUND = 1452;
    protected const ERROR_FOREIGN_KEY_CHILD_EXISTS = 1451;
    // bad column name
    protected const ERROR_UNKNOWN_COLUMN = 1054;   // Unknown column 'column' in 'table'
    protected const ERROR_BAD_FIELD = 1166;        // Incorrect column name 'column'
    protected const ERROR_WRONG_FIELD_SPEC = 1063; // Incorrect column specifier for column

    /**
     * $retryOnErrors
     * Mysqli errors on which to retry the transaction or query if not inside a transaction.
     *
     * @var array $retryOnErrors  mysqli errors on which to retry if not inside transaction
     * * Error: 1015 SQLSTATE: HY000 (ER_CANT_LOCK) Message: Can't lock file (errno: %d)
     * * Error?: 1027 SQLSTATE: HY000 (ER_FILE_USED) Message: '%s' is locked against change
     * * Error: 1689 SQLSTATE: HY000 (ER_LOCK_ABORTED) Message: Wait on a lock was aborted due to a pending exclusive lock
     * * Error: 1205 SQLSTATE: HY000 (ER_LOCK_WAIT_TIMEOUT) Message: Lock wait timeout exceeded; try restarting transaction
     * * Error: 1206 SQLSTATE: HY000 (ER_LOCK_TABLE_FULL) Message: The total number of locks exceeds the lock table size
     * * Error: 1213 SQLSTATE: 40001 (ER_LOCK_DEADLOCK) Message: Deadlock found when trying to get lock; try restarting transaction
     * * Error: 1622 SQLSTATE: HY000 (ER_WARN_ENGINE_TRANSACTION_ROLLBACK) Message: Storage engine %s does not support rollback for this statement.
     *      Transaction rolled back and must be restarted
     * * Error: 1614 SQLSTATE: XA102 (ER_XA_RBDEADLOCK) Message: XA_RBDEADLOCK: Transaction branch was rolled back: deadlock was detected
     * * Error: 2006 (CR_SERVER_GONE_ERROR) Message: MySQL server has gone away
     * * Error: 2013 (CR_SERVER_LOST) Message: Lost connection to MySQL server during query
     *
     * @see IacMysqli::runSql() IacMysqli::runSql()
     * 
     */
    protected array $retryOnErrors = [
      SqlExecutor::ERROR_CANT_LOCK => 1,
      SqlExecutor::ERROR_LOCK_ABORTED => 1,
      SqlExecutor::ERROR_LOCK_WAIT_TIMEOUT => 1,
      SqlExecutor::ERROR_LOCK_TABLE_FULL => 1,
      SqlExecutor::ERROR_LOCK_DEADLOCK => 1,
      SqlExecutor::ERROR_TRANSACTION_ROLLBACK => 1,
      SqlExecutor::ERROR_XA_DEADLOCK => 1,
      SqlExecutor::ERROR_SERVER_GONE => 1,
      SqlExecutor::ERROR_SERVER_LOST => 1
    ];
    
    /**
     * $reconnectOnErrors
     * Mysqli errors on which to try to reconnect.
     *
     * @var array $reconnectOnErrors mysql error codes on which to reconnect
     *
     * Error: 2006 (CR_SERVER_GONE_ERROR) Message: MySQL server has gone away
     * Error: 2013 (CR_SERVER_LOST) Message: Lost connection to MySQL server during query
     * Error: 2024 (CR_PROBE_SLAVE_CONNECT) Message: Error connecting to slave:
     * Error: 2025 (CR_PROBE_MASTER_CONNECT) Message: Error connecting to master:
     * Error: 2026 (CR_SSL_CONNECTION_ERROR) Message: SSL connection error: %s
     * --
     * Error: 2020 (CR_NET_PACKET_TOO_LARGE) Message: Got packet bigger than 'max_allowed_packet' bytes
     *  On queries larger than max_ a lost connection error may be returned
     *
     * 
     */

    /**
     * $reconnectOnErrors
     * Mysqli errors on which to try to reconnect.
     *
     * @var array $reconnectOnErrors mysql error codes on which to reconnect
     */
    protected array $reconnectOnErrors = [
      SqlExecutor::ERROR_SERVER_GONE => 1,
      SqlExecutor::ERROR_SERVER_LOST => 1
    ];

    public string $lastPreparedQuery = "";

    protected array $log = [];
    protected array $logError = [];

    protected int $openTransactions = 0;

    /**
     *
     *
     * @param array{hostname: ?string, username: ?string, password: ?string, database: ?string, port: ?string, socket: ?string, flags: int } $connect
     * @param array $connect_options default [MYSQLI_INIT_COMMAND => 'SET AUTOCOMMIT = 1']
     * @param string $charset default utf8mb4
     * @param string $coalition default utf8mb4_0900_ai_ci
     * @param int $flags
     */
    public function __construct(array $connect, array $connect_options = [], string $charset = 'utf8', string $coalition = 'utf8_unicode_ci', int $flags = 0) {
        $this->connect = array_merge($this->connect, $connect) ;
        $this->connect_options = array_merge($this->connect_options, $connect_options);
        $this->charset = $charset;
        $this->coalition = $coalition;
        $this->flags = $flags;
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function connect():void {
        $this->mysqli = new mysqli();
        foreach($this->connect_options as $option => $value)
            if(!$this->mysqli->options($option, $value)) {
                throw new mysqli_sql_exception("Setting $option $value failed");
            }
        $attempts = 0;
        while(++$attempts <= $this->retries) {
            if($this->mysqli->real_connect($this->connect['hostname'], $this->connect['username'],
              $this->connect['password'], $this->connect['database'], $this->connect['port'],
              $this->connect['socket'], $this->connect['flags'])) {
                $this->mysqli->set_charset($this->charset);
                // $charset = SqlUtils::strIt($this->charset);
                // $coalition = SqlUtils::strIt($this->coalition);
                $this->query( "SET NAMES ? COLLATE ?", [$this->charset, $this->coalition]);
                return;
            }
        }
        throw new mysqli_sql_exception('Connect Error (' . mysqli_connect_errno() . ') ' . mysqli_connect_error());
    }

    /**
     *
     *
     * @param string|mysqli_stmt $query
     * @param array $parameters
     * @return bool|mysqli_result
     * @throws Exception
     */
    public function query(string|mysqli_stmt $query, array $parameters = []):bool|mysqli_result {
        return $this->runSql($query, $parameters);
    }

    /**
     * Retrieves the ID of the last inserted auto increment record.
     * use ON DUPLICATE KEY ... primary_key = LAST_INSERT_ID(primary_key);
     *
     * @return int The ID of the last inserted record.
     */
    public function last_insert_id(): int {return $this->mysqli->insert_id;}

    public function affected_rows():int {return $this->mysqli->affected_rows;}

    /**
     * return value of first column in first row $default on not found
     *
     * @param string|mysqli_stmt $query
     * @param array $parameters
     * @param string|null|bool $default
     * @return string|null|bool
     * @throws Exception
     */
    public function firstValue(string|mysqli_stmt $query, array $parameters = [], string|null|bool $default = ""):string|null|bool {
        if(empty($query))
            return $default;
        try {
            $result = $this->runSql($query, $parameters);
            $ret = $result->fetch_array(MYSQLI_NUM);
            return empty($ret) ? $default : $ret[0];
        } finally {$this->freeResult($result ?? false);}
    }

    /**
     * return [col1=>value1, ...] $default on not found
     *
     * @param string|mysqli_stmt $query
     * @param array $parameters
     * @param array $default
     * @param int $resultType MYSQLI_ASSOC|MYSQLI_NUM|MYSQLI_BOTH
     * @return array [$key1=>[col1=>value1],$key2=>[]]
     * @throws Exception
     */
    public function row(string|mysqli_stmt $query, array $parameters = [], array $default =[], int $resultType = MYSQLI_ASSOC): array {
        if(empty($query))
            return $default;
        try {
            $result = $this->runSql($query, $parameters);
            $ret = $result->fetch_array($resultType);
            return empty($ret) ? $default : $ret;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * return [$key1=>[col1=>value1],$key2=>[]], $default on not found
     *
     * @param string|mysqli_stmt $query
     * @param string $key
     * @param array $parameters
     * @param array $default
     * @param int $resultType MYSQLI_ASSOC|MYSQLI_NUM|MYSQLI_BOTH
     * @return array [$key1=>[col1=>value1],$key2=>[]]
     * @throws Exception
     */
    public function arrayKeyed(string|mysqli_stmt $query, string $key, array $parameters = [], array $default =[], int $resultType = MYSQLI_ASSOC): array {
        if(empty($query))
            return $default;
        try {
            $result = $this->runSql($query, $parameters);
            for($ret = []; $tmp = $result->fetch_array($resultType);)
                $ret[$tmp[$key]] = $tmp;
            return empty($ret) ? $default : $ret;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * return [[colName1=>value1, colName2=>value2, ...],[]], $default on not found
     *
     * @param string|mysqli_stmt $query
     * @param array $parameters
     * @param array $default
     * @param int $resultType MYSQLI_ASSOC|MYSQLI_NUM|MYSQLI_BOTH
     * @return array [$key1=>[col1=>value1],$key2=>[]]
     * @throws Exception
     */
    public function array(string|mysqli_stmt $query, array $parameters = [], array $default =[], int $resultType = MYSQLI_ASSOC): array {
        if(empty($query))
            return $default;
        try {
            $result = $this->runSql($query, $parameters);
            for($ret = []; $tmp = $result->fetch_array($resultType);)
                $ret[] = $tmp;
            return empty($ret) ? $default : $ret;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * @param string|mysqli_stmt $query
     * @param array $keys
     * @param array $parameters
     * @param array $default
     * @return array
     * @throws Exception
     */
    public function multiKey(string|mysqli_stmt $query, array $keys, array $parameters = [], array $default = []): array {
        if(empty($query))
            return $default;
        try {
            $result = $this->runSql($query, $parameters);
            for($ret = []; $tmp = $result->fetch_array(MYSQLI_ASSOC);) {
                $r = &$ret;
                foreach($keys as $v) {
                    $key = $tmp[$v] ?? $v;
                    if(!array_key_exists($key, $r))
                        $r[$key] = [];
                    $r = &$r[$key];
                }
                $r = $tmp;
            }
            return empty($ret) ? $default : $ret;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * @param string|mysqli_stmt $query
     * @param int $numFields Number of fields to use as keys
     * @param array $parameters
     * @param array $default
     * @return array
     * @throws Exception
     */
    public function multiKeyN(string|mysqli_stmt $query, int $numFields, array $parameters = [], array $default = []): array {
        if(empty($query))
            return $default;
        try {
            $result = $this->runSql($query, $parameters);
            for($ret = []; $tmp = $result->fetch_array(MYSQLI_ASSOC);) {
                $r = &$ret;
                if(!isset($keys))
                    $keys = array_slice(array_keys($tmp), 0, $numFields);
                foreach($keys as $v) {
                    $key = $tmp[$v] ?? $v;
                    if(!array_key_exists($key, $r)) {
                        $r[$key] = [];
                    }
                    $r = &$r[$key];
                }
                $r = $tmp;
            }
            return empty($ret) ? $default : $ret;
        } finally {
            $this->freeResult($result ?? false);
        }
    }

    /**
     * @param string|mysqli_stmt $query
     * @param array $parameters
     * @param array $default
     * @return array
     * @throws Exception
     */
    public function multiKeyLast(string|mysqli_stmt $query, array $parameters = [], array $default = []): array {
       if(empty($query))
           return $default;
        try {
            $result = $this->runSql($query, $parameters);
            $numFields = $result->field_count;
            $keyedFields = $numFields - 1;
            for($ret = []; $tmp = $result->fetch_array(MYSQLI_NUM);) {
                $r = &$ret;
                for($iField = 0; $iField < $keyedFields; ++$iField) {
                    $key = $tmp[$iField];
                    if(!array_key_exists($key, $ret))
                        $r[$key] = [];
                    $r = &$r[$key];
                }
                $r[] = $tmp[$keyedFields];
            }
            return empty($ret) ? $default : $ret;
        } finally {
            $this->freeResult($result ?? false);
        }
    }

    /**
     * return [[row1.col1 => row1.col2], [row2.col1 => row2.col2], ...] a key => value array, $default on not found
     *
     * @param string|mysqli_stmt $query
     * @param array $parameters
     * @param array $default
     * @return array
     * @throws Exception
     */
    public function keyValue(string|mysqli_stmt $query, array $parameters = [], array $default =[]): array {
        if(empty($query))
            return $default;
        try {
            $result = $this->runSql($query, $parameters);
            for($ret = []; $tmp = $result->fetch_array(MYSQLI_NUM);)
                $ret[$tmp[0]] = $tmp[1] ?? null;
            return empty($ret) ? $default : $ret;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * return [row1.col1, row2.col1, ...], $default on not found
     *
     * @param string|mysqli_stmt  $query
     * @param array $parameters
     * @param array $default
     * @return array
     * @throws Exception
     */
    public function vector(string|mysqli_stmt $query, array $parameters = [], array $default =[]): array {
        if(empty($query))
            return $default;
        try {
            $result = $this->runSql($query, $parameters);
            for($ret = []; $tmp = $result->fetch_array(MYSQLI_NUM);)
                $ret[] = $tmp[0];
            return empty($ret) ? $default : $ret;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * Returns mysqli_result without freeing it. Caller is responsible for cleanup.
     *
     * @param string|mysqli_stmt $query
     * @param array $parameters
     * @return mysqli_result|bool
     * @throws Exception
     */
    public function result(string|mysqli_stmt $query, array $parameters = []): mysqli_result|bool {
        if(empty($query))
            return $this->runSql("SELECT /*" . __METHOD__ . "*/ NULL FROM DUAL LIMIT 0");
        return $this->runSql($query, $parameters);
    }

    /**
     * @param array $queries
     * @param string|int $comment
     * @return void
     * @throws Exception
     */
    public function transaction(array $queries, string|int $comment = ''):void {
        $attempts = 0;
        while(++$attempts <= $this->retries) {
            try {
                if($this->begin($comment)) {
                    foreach($queries as $query)
                        $this->query($query);
                    $this->commit($comment);
                }
            } catch (mysqli_sql_exception $e) {
                $lastError = $e;
                $this->rollback($comment);
            }
        }
        throw $lastError ?? new mysqli_sql_exception("Transaction failed after $attempts attempts");
    }

    public function begin($comment = ''): bool {
        try {
            $result = $this->runSql("START TRANSACTION /*$comment*/");
            if($result !== false) {
                $this->openTransactions++;
                return true;
            }
            return false;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * Commit current transaction.
     *
     * @throws mysqli_sql_exception
     */
    public function commit($comment = ''): bool {
        try {
            $result = $this->runSql("COMMIT /*$comment*/");
            if($result !== false && $this->openTransactions > 0) {
                $this->openTransactions--;
                return true;
            }
            return false;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * Rollback current transaction.
     *
     * @throws mysqli_sql_exception
     */
    public function rollback($comment = ''): bool {
        try {
            $result = $this->runSql("ROLLBACK /*$comment*/");
            if($result !== false && $this->openTransactions > 0) {
                $this->openTransactions--;
                return true;
            }
            return false;
        } finally { $this->freeResult($result ?? false); }
    }

    /**
     * Checks if the last error was a "table not found" error
     * This includes ERROR_TABLE_NOT_FOUND, ERROR_NO_SUCH_TABLE, and ERROR_UNKNOWN_TABLE
     *
     * @return bool True if the last error was a table not found error
     */
    public function is_last_error_table_not_found(): bool {
        if(!$this->mysqli) return false;

        return in_array($this->mysqli->errno, [
          SqlExecutor::ERROR_TABLE_NOT_FOUND,
          SqlExecutor::ERROR_NO_SUCH_TABLE,
          SqlExecutor::ERROR_UNKNOWN_TABLE
        ], true);
    }

    /**
     * Checks if the last error was a duplicate/unique key violation
     * This includes both unique key and primary key violations
     *
     * @return bool True if the last error was a duplicate key error
     */
    public function is_last_error_duplicate_key(): bool {
        if(!$this->mysqli) return false;

        return in_array($this->mysqli->errno, [
          SqlExecutor::ERROR_UNIQUE_VIOLATION,
          SqlExecutor::ERROR_PRIMARY_KEY_VIOLATION
        ], true);
    }

    /**
     * Checks if the last error was a foreign key violation
     * This checks for invalid foreign key references
     *
     * @return bool True if the last error was a foreign key violation
     */
    public function is_last_error_invalid_foreign_key(): bool {
        if(!$this->mysqli) return false;

        return in_array($this->mysqli->errno, [
          SqlExecutor::ERROR_FOREIGN_KEY_VIOLATION,
          SqlExecutor::ERROR_FOREIGN_KEY_PARENT_NOT_FOUND
        ], true);
    }

    /**
     * Checks if the last error was due to existing child records
     * This occurs when trying to delete a parent record that has child records
     *
     * @return bool True if the last error was due to existing child records
     */
    public function is_last_error_child_records_exist(): bool {
        if(!$this->mysqli) return false;

        return $this->mysqli->errno === SqlExecutor::ERROR_FOREIGN_KEY_CHILD_EXISTS;
    }
    
    /**
     * Checks if the last error was related to a non-existent column
     * This includes unknown column and incorrect column name errors
     *
     * @return bool True if the last error was a column not found error
     */
    public function is_last_error_column_not_found(): bool {
        if(!$this->mysqli) return false;

        return in_array($this->mysqli->errno, [
          SqlExecutor::ERROR_UNKNOWN_COLUMN,
          SqlExecutor::ERROR_BAD_FIELD,
          SqlExecutor::ERROR_WRONG_FIELD_SPEC
        ], true);
    }

    public function getLog(): array {return $this->log;}

    public function getErrorLog(): array {return $this->logError;}

    /**
     * Closes the current connection
     */
    public function closeConnection(): void {
        if($this->mysqli instanceof mysqli) {
            try {
                if($this->openTransactions > 0) {
                    try {
                        $this->logErrorAdd(
                          0,
                          "Unclosed transaction detected during cleanup",
                          "",
                          [],
                          0
                        );
                        $this->mysqli->rollback();
                    } catch (mysqli_sql_exception $e) {
                        $this->logErrorAdd(
                          $e->getCode(),
                          "Rollback failed during cleanup: " . $e->getMessage(),
                          "",
                          [],
                          0
                        );
                    }
                }
                $this->mysqli->close();
            } catch (mysqli_sql_exception $e) {
                $this->logErrorAdd(
                  $e->getCode(),
                  "Error during connection close: " . $e->getMessage(),
                  "",
                  [],
                  0
                );
            } finally {
                $this->mysqli = null;
            }
        }
    }

    /**
     * @param  string|mysqli_stmt $query
     * @param array $parameters
     * @return bool|mysqli_result
     * @throws Exception
     */
    protected function runSql(string|mysqli_stmt $query, array $parameters = []):bool|mysqli_result {
        if(empty($this->mysqli))
            $this->connect();
        $this->logAdd($query, $parameters);
        $lastError = null;
        $attempts = 0;
        while(++$attempts <= $this->retries) {
            try {
                if(is_string($query)) {
                    if(empty($parameters))
                        return $this->mysqli->query($query);
                    return $this->mysqli->execute_query($query, $parameters);
                }
                $query->execute();
                $result = $query->get_result();
                $query->store_result();
                return $result;
            } catch(mysqli_sql_exception $error) {
                $this->logErrorAdd($error->getCode(), $error->getMessage(), $query, $parameters, $attempts);
                if(!$this->retryQuery($error->getCode()))
                    throw $error;
                $lastError = $error;
                usleep($this->retrySleep);
            }
        }
        throw $lastError === null ? new Exception("Unknown Error") : $lastError;
    }

    protected function freeResult(mysqli_result|bool $result): void {
        try {
            if($result instanceof mysqli_result)
                $result->free();
        }catch(mysqli_sql_exception $error) {
            $this->logErrorAdd($error->getCode(), $error->getMessage(), "", [], 0);
        } catch(Throwable) {}
    }

    /**
     * @param int $errorNumber
     * @return bool
     * @throws Exception
     */
    protected function retryQuery(int $errorNumber):bool {
        if($this->openTransactions > 0)
            return false;
        if(array_key_exists($errorNumber, $this->reconnectOnErrors))
            $this->connect();
        return array_key_exists($errorNumber, $this->retryOnErrors);
    }

    protected function logAdd($query, $parameters =[]):void {
        if(count($this->log) > $this->maxLogEntries)
            return;
        $logEntry = is_string($query) ? $query : $this->lastPreparedQuery;
        if(!empty($parameters) && is_array($parameters))
            $logEntry .= " -- (" . implode(", ", $parameters) . ")";
        $this->log[] = $logEntry;
    }

    protected function logErrorAdd(int $errorNumber, string $errorMessage, string|mysqli_stmt $query, array $parameters, $attempt):void {
        if(count($this->logError) > $this->maxLogEntries)
            return;
        $this->logError[] = ["error" => $errorNumber, "error message" => $errorMessage, "query" => $query, "parameters" => $parameters, "attempt" => $attempt];
    }

}
