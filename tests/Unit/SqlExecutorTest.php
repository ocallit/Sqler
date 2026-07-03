<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\SqlExecutor;

/**
 * mysqli's properties (errno, ...) are virtual and cannot be set on mocks or
 * shadowed in subclasses, so the error-check tests stub getLastErrorNumber(),
 * which all is_last_error_*() methods read through.
 */
class StubErrnoSqlExecutor extends SqlExecutor {
    public int $stubErrno = 0;

    public function __construct() {
        parent::__construct([]);
    }

    public function getLastErrorNumber(): int {
        return $this->stubErrno;
    }
}

/**
 * Feeds a stubbed mysqli_result through the protected runSql() seam so the
 * result-shaping methods (query, keyValue, vector, multiKey*, ...) can be
 * tested without a database connection.
 */
class StubResultSqlExecutor extends SqlExecutor {
    public function __construct(private readonly mysqli_result|bool $stubResult) {
        parent::__construct([]);
    }

    protected function runSql(string|mysqli_stmt $query, array $parameters = []): bool|mysqli_result {
        return $this->stubResult;
    }
}

#[CoversClass(SqlExecutor::class)]
class SqlExecutorTest extends TestCase {

    private function executorReturning(array $rows, ?int $expectedFetchMode = null): StubResultSqlExecutor {
        $result = $this->createMock(mysqli_result::class);
        $method = $result->method('fetch_array');
        if($expectedFetchMode !== null)
            $method->with($expectedFetchMode);
        $method->willReturnOnConsecutiveCalls(...[...$rows, null]);
        return new StubResultSqlExecutor($result);
    }

    #[DataProvider('isLastErrorProvider')]
    public function testErrorCheckMethods(string $method, int $errorCode, bool $expected): void {
        $executor = new StubErrnoSqlExecutor();
        $executor->stubErrno = $errorCode;

        $this->assertSame($expected, $executor->$method());
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
        $sqlExecutor = new SqlExecutor([
          'hostname' => 'localhost',
          'username' => 'test',
          'password' => 'test',
          'database' => 'test',
        ]);
        $this->assertSame(0, $sqlExecutor->getLastErrorNumber());
    }

    public function testErrorCheckMethodsWithNullMysqli(): void {
        $sqlExecutor = new SqlExecutor([
          'hostname' => 'localhost',
          'username' => 'test',
          'password' => 'test',
          'database' => 'test',
        ]);

        $this->assertFalse($sqlExecutor->is_last_error_table_not_found());
        $this->assertFalse($sqlExecutor->is_last_error_duplicate_key());
        $this->assertFalse($sqlExecutor->is_last_error_invalid_foreign_key());
        $this->assertFalse($sqlExecutor->is_last_error_child_records_exist());
        $this->assertFalse($sqlExecutor->is_last_error_column_not_found());
    }

    public function testGetLogAndGetErrorLog(): void {
        $sqlExecutor = new StubErrnoSqlExecutor();

        $this->assertIsArray($sqlExecutor->getLog());
        $this->assertIsArray($sqlExecutor->getErrorLog());
    }

    public function testQueryReturnsAssociativeRowsForSelect(): void {
        // MYSQLI_ASSOC expectation: query() must return column-name-keyed rows
        $executor = $this->executorReturning([
          ['id' => 1, 'name' => 'John'],
          ['id' => 2, 'name' => 'Jane'],
        ], MYSQLI_ASSOC);

        $rows = $executor->query("SELECT id, name FROM users");

        $this->assertSame([
          ['id' => 1, 'name' => 'John'],
          ['id' => 2, 'name' => 'Jane'],
        ], $rows);
    }

    public function testQueryReturnsEmptyArrayForSelectWithNoRows(): void {
        $executor = $this->executorReturning([]);

        $this->assertSame([], $executor->query("SELECT id FROM users WHERE 1=0"));
    }

    public function testQueryReturnsBoolForNonSelect(): void {
        $executor = new StubResultSqlExecutor(true);

        $this->assertTrue($executor->query("UPDATE users SET active = 1"));
    }

    public function testKeyValue(): void {
        $executor = $this->executorReturning([
          ['open', 3],
          ['closed', 5],
        ]);

        $this->assertSame(['open' => 3, 'closed' => 5],
          $executor->keyValue("SELECT status, cnt FROM t"));
    }

    public function testVector(): void {
        $executor = $this->executorReturning([[1], [2], [3]]);

        $this->assertSame([1, 2, 3], $executor->vector("SELECT id FROM t"));
    }

    public function testMultiKey(): void {
        $executor = $this->executorReturning([
          ['dept' => 'IT', 'role' => 'Admin', 'name' => 'John'],
          ['dept' => 'IT', 'role' => 'User', 'name' => 'Bob'],
        ]);

        $this->assertSame([
          'IT' => [
            'Admin' => ['dept' => 'IT', 'role' => 'Admin', 'name' => 'John'],
            'User' => ['dept' => 'IT', 'role' => 'User', 'name' => 'Bob'],
          ],
        ], $executor->multiKey("SELECT dept, role, name FROM users", ['dept', 'role']));
    }

    public function testMultiKeyLastAccumulatesRowsSharingTheSameKeyPath(): void {
        $executor = $this->executorReturning([
          ['A', 'X', 'v1'],
          ['A', 'X', 'v2'],
          ['A', 'Y', 'v3'],
          ['B', 'X', 'v4'],
        ]);

        $this->assertSame([
          'A' => ['X' => ['v1', 'v2'], 'Y' => ['v3']],
          'B' => ['X' => ['v4']],
        ], $executor->multiKeyLast("SELECT k1, k2, v FROM t"));
    }

    public function testMultiKeyValueAccumulatesLastColumn(): void {
        $executor = $this->executorReturning([
          ['A', 'key1', 'val1'],
          ['A', 'key1', 'val2'],
          ['A', 'key2', 'val3'],
        ]);

        $this->assertSame([
          'A' => ['key1' => ['val1', 'val2'], 'key2' => ['val3']],
        ], $executor->multiKeyValue("SELECT g, k, v FROM t"));
    }
}
