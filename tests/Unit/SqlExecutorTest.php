<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\SqlExecutor;


require_once __DIR__ . '/../../vendor/autoload.php';
#[CoversClass(SqlExecutor::class)]
class SqlExecutorTest extends TestCase {
    private SqlExecutor $sqlExecutor;

    protected function setUp(): void {
        // Create SqlExecutor with dummy connection params since we're only testing utility methods
        $this->sqlExecutor = new SqlExecutor([
          'hostname' => 'localhost',
          'username' => 'test',
          'password' => 'test',
          'database' => 'test',
        ]);
    }
    
    #[DataProvider('isLastErrorProvider')]
    public function testErrorCheckMethods(string $method, int $errorCode, bool $expected): void {
        // Use reflection to set the mysqli property with a mock
        $reflection = new ReflectionClass($this->sqlExecutor);
        $mysqliProperty = $reflection->getProperty('mysqli');
        $mysqliProperty->setAccessible(TRUE);

        $mockMysqli = $this->createMock(\mysqli::class);
        $mockMysqli->method('query')->willReturnCallback(static function () use ($errorCode) { if($errorCode !== 0) throw new mysqli_sql_exception('SQL failure', $errorCode); return true; });
        $mysqliProperty->setValue($this->sqlExecutor, $mockMysqli);

        try { $this->sqlExecutor->query('SELECT fixture'); } catch(mysqli_sql_exception) {}
        $result = $this->sqlExecutor->$method();
        $this->assertSame($expected, $result);
    }

    public static function isLastErrorProvider(): array {
        return [
            // is_last_error_table_not_found tests
          ['is_last_error_table_not_found', 1146, TRUE],  // ERROR_TABLE_NOT_FOUND
          ['is_last_error_table_not_found', 1051, TRUE],  // ERROR_NO_SUCH_TABLE
          ['is_last_error_table_not_found', 1109, TRUE],  // ERROR_UNKNOWN_TABLE
          ['is_last_error_table_not_found', 1062, FALSE], // ERROR_UNIQUE_VIOLATION
          ['is_last_error_table_not_found', 0, FALSE],    // No error

            // is_last_error_duplicate_key tests
          ['is_last_error_duplicate_key', 1062, TRUE],    // ERROR_UNIQUE_VIOLATION
          ['is_last_error_duplicate_key', 1022, TRUE],    // ERROR_PRIMARY_KEY_VIOLATION
          ['is_last_error_duplicate_key', 1146, FALSE],   // ERROR_TABLE_NOT_FOUND
          ['is_last_error_duplicate_key', 0, FALSE],      // No error

            // is_last_error_invalid_foreign_key tests
          ['is_last_error_invalid_foreign_key', 1216, TRUE],  // ERROR_FOREIGN_KEY_VIOLATION
          ['is_last_error_invalid_foreign_key', 1452, TRUE],  // ERROR_FOREIGN_KEY_PARENT_NOT_FOUND
          ['is_last_error_invalid_foreign_key', 1451, FALSE], // ERROR_FOREIGN_KEY_CHILD_EXISTS
          ['is_last_error_invalid_foreign_key', 0, FALSE],    // No error

            // is_last_error_child_records_exist tests
          ['is_last_error_child_records_exist', 1451, TRUE],  // ERROR_FOREIGN_KEY_CHILD_EXISTS
          ['is_last_error_child_records_exist', 1452, FALSE], // ERROR_FOREIGN_KEY_PARENT_NOT_FOUND
          ['is_last_error_child_records_exist', 0, FALSE],    // No error

            // is_last_error_column_not_found tests
          ['is_last_error_column_not_found', 1054, TRUE],     // ERROR_UNKNOWN_COLUMN
          ['is_last_error_column_not_found', 1166, TRUE],     // ERROR_BAD_FIELD
          ['is_last_error_column_not_found', 1063, TRUE],     // ERROR_WRONG_FIELD_SPEC
          ['is_last_error_column_not_found', 1146, FALSE],    // ERROR_TABLE_NOT_FOUND
          ['is_last_error_column_not_found', 0, FALSE],       // No error
        ];
    }

    public function testGetLastErrorNumberWithNoMysqli(): void {
        // Test when mysqli is null
        $reflection = new ReflectionClass($this->sqlExecutor);
        $mysqliProperty = $reflection->getProperty('mysqli');
        $mysqliProperty->setAccessible(TRUE);
        $mysqliProperty->setValue($this->sqlExecutor, NULL);

        $result = $this->sqlExecutor->getLastErrorNumber();
        $this->assertSame(0, $result);
    }

    public function testGetLastErrorNumberWithValidMysqli(): void {
        // Test when mysqli has an error
        $reflection = new ReflectionClass($this->sqlExecutor);
        $mysqliProperty = $reflection->getProperty('mysqli');
        $mysqliProperty->setAccessible(TRUE);

        $mockMysqli = $this->createMock(\mysqli::class);
        $mockMysqli->method('query')->willThrowException(new mysqli_sql_exception('Missing table', 1146));
        $mysqliProperty->setValue($this->sqlExecutor, $mockMysqli);

        $result = $this->sqlExecutor->getLastErrorNumber();
        try { $this->sqlExecutor->query('SELECT fixture'); } catch(mysqli_sql_exception) {}
        $this->assertSame(1146, $this->sqlExecutor->getLastErrorNumber());
    }

    public function testGetLogAndGetErrorLog(): void {
        $log = $this->sqlExecutor->getLog();
        $errorLog = $this->sqlExecutor->getErrorLog();

        $this->assertIsArray($log);
        $this->assertIsArray($errorLog);
    }

    private function connectionWithQueryCallback(callable $callback): void {
        $connection = $this->createMock(mysqli::class);
        $connection->method('query')->willReturnCallback($callback);
        (new ReflectionProperty(SqlExecutor::class, 'mysqli'))->setValue($this->sqlExecutor, $connection);
    }

    public function testTransactionPreservesFailureAfterSuccessfulRollbacks(): void {
        $failure = new mysqli_sql_exception('Duplicate entry', 1062);
        $rollbacks = 0;
        $this->connectionWithQueryCallback(static function (string $query) use ($failure, &$rollbacks) {
            if($query === 'INSERT fixture') throw $failure;
            if(str_starts_with($query, 'ROLLBACK')) ++$rollbacks;
            return true;
        });

        try {
            $this->sqlExecutor->transaction(['INSERT fixture']);
            self::fail('Expected the transaction to fail.');
        } catch(mysqli_sql_exception $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(3, $rollbacks);
        self::assertSame(1062, $this->sqlExecutor->getLastErrorNumber());
        self::assertTrue($this->sqlExecutor->is_last_error_duplicate_key());

        $this->sqlExecutor->query('SELECT success');
        self::assertSame(0, $this->sqlExecutor->getLastErrorNumber());
        self::assertFalse($this->sqlExecutor->is_last_error_duplicate_key());
    }

    public function testSuccessfulQueryRetryClearsError(): void {
        $attempts = 0;
        $this->connectionWithQueryCallback(static function () use (&$attempts) {
            if(++$attempts === 1) throw new mysqli_sql_exception('Deadlock', 1213);
            return true;
        });
        self::assertTrue($this->sqlExecutor->query('UPDATE fixture'));
        self::assertSame(2, $attempts);
        self::assertSame(0, $this->sqlExecutor->getLastErrorNumber());
    }

    public function testExhaustedRetriesPreserveErrorAndEmptyQueryClearsIt(): void {
        $attempts = 0;
        $failure = new mysqli_sql_exception('Deadlock', 1213);
        $this->connectionWithQueryCallback(static function () use (&$attempts, $failure) {
            ++$attempts;
            throw $failure;
        });
        try {
            $this->sqlExecutor->query('UPDATE fixture');
            self::fail('Expected retries to fail.');
        } catch(mysqli_sql_exception $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(3, $attempts);
        self::assertSame(1213, $this->sqlExecutor->getLastErrorNumber());
        self::assertSame([], $this->sqlExecutor->array(''));
        self::assertSame(0, $this->sqlExecutor->getLastErrorNumber());
    }

    public function testSuccessfulTransactionRetryClearsError(): void {
        $attempts = 0;
        $this->connectionWithQueryCallback(static function (string $query) use (&$attempts) {
            if($query === 'UPDATE fixture' && ++$attempts === 1)
                throw new mysqli_sql_exception('Deadlock', 1213);
            return true;
        });
        $this->sqlExecutor->transaction(['UPDATE fixture']);
        self::assertSame(2, $attempts);
        self::assertSame(0, $this->sqlExecutor->getLastErrorNumber());
    }

    public function testRollbackFailureMatchesReportedException(): void {
        $failure = new mysqli_sql_exception('Rollback failed', 2013);
        $this->connectionWithQueryCallback(static function (string $query) use ($failure) {
            if($query === 'INSERT fixture') throw new mysqli_sql_exception('Duplicate entry', 1062);
            if(str_starts_with($query, 'ROLLBACK')) throw $failure;
            return true;
        });
        try {
            $this->sqlExecutor->transaction(['INSERT fixture']);
            self::fail('Expected rollback failure.');
        } catch(mysqli_sql_exception $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(2013, $this->sqlExecutor->getLastErrorNumber());
        self::assertFalse($this->sqlExecutor->is_last_error_duplicate_key());
    }

    public function testConnectionFailureIsSaved(): void {
        $sql = $this->getMockBuilder(SqlExecutor::class)->disableOriginalConstructor()->onlyMethods(['connect'])->getMock();
        $sql->method('connect')->willThrowException(new mysqli_sql_exception('Connection failed', 1045));
        try {
            $sql->query('SELECT fixture');
            self::fail('Expected connection failure.');
        } catch(mysqli_sql_exception $caught) {
            self::assertSame(1045, $caught->getCode());
        }
        self::assertSame(1045, $sql->getLastErrorNumber());
    }

    public function testErrorCheckMethodsWithNullMysqli(): void {
        // Test error check methods when mysqli is null
        $reflection = new ReflectionClass($this->sqlExecutor);
        $mysqliProperty = $reflection->getProperty('mysqli');
        $mysqliProperty->setAccessible(TRUE);
        $mysqliProperty->setValue($this->sqlExecutor, NULL);

        $this->assertFalse($this->sqlExecutor->is_last_error_table_not_found());
        $this->assertFalse($this->sqlExecutor->is_last_error_duplicate_key());
        $this->assertFalse($this->sqlExecutor->is_last_error_invalid_foreign_key());
        $this->assertFalse($this->sqlExecutor->is_last_error_child_records_exist());
        $this->assertFalse($this->sqlExecutor->is_last_error_column_not_found());
    }
}
