<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\DatabaseMetadata;
use Ocallit\Sqler\SqlExecutor;
use Ocallit\Sqler\ValidatorSql;

/**
 * Schema under test (all metadata mocked, no database):
 *   users(user_id VARCHAR(32) PK, email VARCHAR(191), UNIQUE uq_email(email))
 * String columns only, so validation never reaches the bcmath-based numeric checks.
 */
#[CoversClass(ValidatorSql::class)]
class ValidatorSqlTest extends TestCase {
    private SqlExecutor $mockSql;
    public array $capturedFirstValue = [];

    protected function setUp(): void {
        DatabaseMetadata::reset();
        $this->mockSql = $this->createMock(SqlExecutor::class);

        $columns = [
          'user_id' => [
            'name' => 'user_id', 'data_type' => 'varchar', 'Type' => 'varchar(32)',
            'default_value' => null, 'is_nullable' => 'NO', 'character_maximum_length' => 32,
            'numeric_precision' => null, 'numeric_scale' => null, 'extra' => '',
            'generation_expression' => null,
          ],
          'email' => [
            'name' => 'email', 'data_type' => 'varchar', 'Type' => 'varchar(191)',
            'default_value' => null, 'is_nullable' => 'YES', 'character_maximum_length' => 191,
            'numeric_precision' => null, 'numeric_scale' => null, 'extra' => '',
            'generation_expression' => null,
          ],
        ];

        $this->mockSql->method('arrayKeyed')->willReturn($columns);
        $this->mockSql->method('array')->willReturnCallback(static function(string $query): array {
            if(str_contains($query, "CONSTRAINT_NAME = 'PRIMARY'"))
                return [['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'user_id']];
            if(str_contains($query, 'STATISTICS'))
                return [['TABLE_NAME' => 'users', 'INDEX_NAME' => 'uq_email', 'COLUMN_NAME' => 'email', 'INDEX_COMMENT' => '']];
            return []; // foreign keys, check constraints
        });
        $this->mockSql->method('firstValue')->willReturnCallback(function(string $query, array $parameters = []) {
            $this->capturedFirstValue[] = ['query' => $query, 'parameters' => $parameters];
            return $this->firstValueReturn;
        });

        DatabaseMetadata::initialize($this->mockSql);
    }

    protected function tearDown(): void {
        DatabaseMetadata::reset();
    }

    private string $firstValueReturn = '';

    public function testValidPassesAndUniqueCheckMatchesValuesExcludingCurrentRow(): void {
        $this->firstValueReturn = ''; // no colliding row found

        $result = ValidatorSql::validate('users', ['user_id' => 'u-1', 'email' => 'a@b.c'], $this->mockSql);

        $this->assertTrue($result['valid'], print_r($result['errors'], true));
        $this->assertSame([], $result['errors']);

        $this->assertCount(1, $this->capturedFirstValue);
        $check = $this->capturedFirstValue[0];
        // must search rows MATCHING the unique columns, excluding only the current PK
        $this->assertStringContainsString("WHERE `email` = ? AND NOT (`user_id` = ?) LIMIT 1", $check['query']);
        $this->assertSame(['a@b.c', 'u-1'], $check['parameters']);
    }

    public function testDuplicateUniqueValueIsReported(): void {
        $this->firstValueReturn = '1'; // a colliding row exists

        $result = ValidatorSql::validate('users', ['user_id' => 'u-1', 'email' => 'a@b.c'], $this->mockSql);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertStringContainsString("unique index 'uq_email'", $result['errors']['email'][0]);
    }

    public function testNullUniqueValueSkipsUniqueCheck(): void {
        $result = ValidatorSql::validate('users', ['user_id' => 'u-1', 'email' => null], $this->mockSql);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $this->capturedFirstValue, 'no unique-check query should run for NULL values');
    }

    public function testMissingPrimaryKeyIsAnError(): void {
        $result = ValidatorSql::validate('users', ['email' => 'a@b.c'], $this->mockSql);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('user_id', $result['errors']);
    }

    public function testUnknownColumnIsAnError(): void {
        $result = ValidatorSql::validate('users', ['user_id' => 'u-1', 'nope' => 1], $this->mockSql);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('nope', $result['errors']);
    }

    public function testStringTooLongIsAnError(): void {
        $result = ValidatorSql::validate('users',
          ['user_id' => str_repeat('x', 33)], $this->mockSql);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('user_id', $result['errors']);
        $this->assertStringContainsString('maximum length', $result['errors']['user_id'][0]);
    }
}
