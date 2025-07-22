<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\SqlExecutor;



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
        $mockMysqli->errno = $errorCode;
        $mysqliProperty->setValue($this->sqlExecutor, $mockMysqli);

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
        $mockMysqli->errno = 1146;
        $mysqliProperty->setValue($this->sqlExecutor, $mockMysqli);

        $result = $this->sqlExecutor->getLastErrorNumber();
        $this->assertSame(1146, $result);
    }

    public function testGetLogAndGetErrorLog(): void {
        $log = $this->sqlExecutor->getLog();
        $errorLog = $this->sqlExecutor->getErrorLog();

        $this->assertIsArray($log);
        $this->assertIsArray($errorLog);
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
