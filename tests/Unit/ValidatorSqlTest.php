<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Ocallit\Sqler\DatabaseMetadata;
use Ocallit\Sqler\SqlExecutor;
use Ocallit\Sqler\ValidatorSql;

require_once __DIR__ . '/../../vendor/autoload.php';

#[CoversClass(ValidatorSql::class)]
class ValidatorSqlTest extends TestCase {
    private SqlExecutor $mockSqlExecutor;
    private DatabaseMetadata $mockMetadata;
    private ValidatorSql $validator;

    protected function setUp(): void {
        $this->mockSqlExecutor = $this->createMock(SqlExecutor::class);
        $this->mockMetadata = $this->getMockBuilder(DatabaseMetadata::class)
          ->disableOriginalConstructor()
          ->onlyMethods(['table', 'query', 'primaryKeys', 'uniqueIndexes', 'foreignKeysAll'])
          ->getMock();
        $this->mockMetadata->method('table')->willReturnCallback(fn(string $table) => $table === 'item' ? self::itemColumns() : []);
        $this->mockMetadata->method('primaryKeys')->willReturn(['item' => ['item_id' => 'item_id']]);
        (new ReflectionProperty(DatabaseMetadata::class, 'instance'))->setValue(NULL, $this->mockMetadata);
        $this->validator = new ValidatorSql($this->mockSqlExecutor);
    }

    protected function tearDown(): void {
        (new ReflectionProperty(DatabaseMetadata::class, 'instance'))->setValue(NULL, NULL);
    }

    private static function col(string $name, string $dataType, string $type, array $extra = []): array {
        return array_merge([
          'name' => $name, 'data_type' => $dataType, 'Type' => $type, 'is_nullable' => 'NO', 'extra' => '',
          'character_maximum_length' => NULL, 'numeric_precision' => NULL, 'numeric_scale' => NULL,
        ], $extra);
    }

    private static function itemColumns(): array {
        return [
          'item_id' => self::col('item_id', 'int', 'int unsigned', ['extra' => 'auto_increment']),
          'code' => self::col('code', 'varchar', 'varchar(5)', ['character_maximum_length' => 5]),
          'qty' => self::col('qty', 'smallint', 'smallint'),
          'price' => self::col('price', 'decimal', 'decimal(5,2) unsigned', ['numeric_precision' => 5, 'numeric_scale' => 2]),
          'ratio' => self::col('ratio', 'double', 'double', ['is_nullable' => 'YES']),
          'status' => self::col('status', 'enum', "enum('active','inactive')"),
          'born' => self::col('born', 'date', 'date', ['is_nullable' => 'YES']),
          'updated' => self::col('updated', 'datetime', 'datetime', ['is_nullable' => 'YES']),
          'category_id' => self::col('category_id', 'int', 'int unsigned', ['is_nullable' => 'YES']),
          'notes' => self::col('notes', 'text', 'text', ['is_nullable' => 'YES']),
        ];
    }

    // fix

    #[DataProvider('fixProvider')]
    public function testFix(array $data, array $expected): void {
        self::assertSame($expected, $this->validator->fixVsTable('item', $data));
    }

    public static function fixProvider(): array {
        return [
          'integer zero fraction' => [['qty' => ' 1,234.00 '], ['qty' => '1234']],
          'integer non zero fraction kept' => [['qty' => '3.14'], ['qty' => '3.14']],
          'negative integer' => [['qty' => '-5.0'], ['qty' => '-5']],
          'decimal keeps fraction' => [['price' => ' 1,234.50'], ['price' => '1234.50']],
          'double commas removed' => [['ratio' => '1,000.5 '], ['ratio' => '1000.5']],
          'varchar sTrim' => [['code' => "  a   b  "], ['code' => 'a b']],
          'text untouched' => [['notes' => '  a  '], ['notes' => '  a  ']],
          'not string untouched' => [['qty' => 7, 'ratio' => NULL], ['qty' => 7, 'ratio' => NULL]],
          'unknown field untouched' => [['csrf' => ' x,1 '], ['csrf' => ' x,1 ']],
          'float to decimal string' => [['price' => 3.14, 'ratio' => 0.1 + 0.2], ['price' => '3.14', 'ratio' => '0.30000000000000004']],
          'float zero fraction integer' => [['qty' => 3.0, 'item_id' => -5.0], ['qty' => '3', 'item_id' => '-5']],
          'float exponent expanded' => [['ratio' => 1.0E+25, 'price' => 1.0E-5], ['ratio' => '10000000000000000000000000', 'price' => '0.00001']],
          'float in non numeric column untouched' => [['code' => 3.14], ['code' => 3.14]],
        ];
    }

    public function testFixWithQueryUsesQueryMetadata(): void {
        $this->mockMetadata->expects(self::once())->method('query')
          ->willReturn(['total' => self::col('total', 'bigint', 'bigint')]);
        self::assertSame(['total' => '12', 'item_id' => ' 3.00'], $this->validator->fixVsQuery('SELECT SUM(qty) AS total FROM item', ['total' => '12.00', 'item_id' => ' 3.00']));
    }

    // validateVsTable

    #[DataProvider('validateVsTableProvider')]
    public function testValidateVsTable(array $data, array $expected): void {
        self::assertSame($expected, $this->validator->validateVsTable('item', $data));
    }

    public static function validateVsTableProvider(): array {
        return [
          'valid html strings' => [
            ['item_id' => '', 'code' => ' AB ', 'ratio' => '3.14', 'status' => 'active',
             'born' => '2024-02-29', 'updated' => '2024-01-01 10:00:00', 'notes' => '', 'csrf' => 'ignored'],
            [],
          ],
          'required' => [['code' => '', 'qty' => NULL], ['code' => 'Code is required.', 'qty' => 'Qty is required.']],
          'integer with decimals' => [['qty' => '3.14'], ['qty' => 'Qty must be a whole number, 3.14 has decimals.']],
          'integer not a number' => [['qty' => 'abc'], ['qty' => 'Qty must be a whole number.']],
          'decimal not a number' => [['price' => '1e3'], ['price' => 'Price must be a number.']],
          'unsigned negative decimal' => [['price' => '-1'], ['price' => 'Price can not be negative.']],
          'double not a number' => [['ratio' => 'x'], ['ratio' => 'Ratio must be a number.']],
          'varchar too long after trim' => [['code' => 'ABCDEF'], ['code' => 'Code can have at most 5 characters, it has 6.']],
          'enum' => [['status' => 'gone'], ['status' => 'Status must be one of: active, inactive.']],
          'date format' => [['born' => '01/02/2024'], ['born' => 'Born must be a date as yyyy-mm-dd.']],
          'invalid date' => [['born' => '2023-02-29'], ['born' => 'Born is not a valid date: 2023-02-29.']],
          'datetime invalid time' => [['updated' => '2024-01-01 25:00:00'], ['updated' => 'Updated is not a valid time: 25:00:00.']],
          'valid php numbers' => [['ratio' => 3.14, 'status' => 'active'], []],
          'php float with decimals in integer' => [['qty' => 3.14], ['qty' => 'Qty must be a whole number, 3.14 has decimals.']],
          'php float not finite' => [['ratio' => INF, 'qty' => NAN], ['ratio' => 'Ratio must be a number.', 'qty' => 'Qty must be a whole number.']],
        ];
    }

    #[RequiresPhpExtension('bcmath')]
    public function testValidateVsTableRanges(): void {
        self::assertSame([], $this->validator->validateVsTable('item', ['qty' => '1,000.00', 'price' => '999.99']));
        self::assertSame(
          ['qty' => 'Qty must be between -32768 and 32767.', 'price' => 'Price is too large, at most 3 digits before the decimal point.'],
          $this->validator->validateVsTable('item', ['qty' => '32,768', 'price' => '1000'])
        );
        self::assertSame([], $this->validator->validateVsTable('item', ['item_id' => 7, 'qty' => 3.0, 'price' => 999.99]));
        self::assertSame(['qty' => 'Qty must be between -32768 and 32767.'], $this->validator->validateVsTable('item', ['qty' => 1.0E+25]));
    }

    public function testValidateVsTableUnknownTableThrows(): void {
        $this->expectException(Exception::class);
        $this->validator->validateVsTable('nope', []);
    }

    // validateVsQuery

    public function testValidateVsQueryDerivedColumnUsesFlags(): void {
        $this->mockMetadata->method('query')->willReturn([
          'label' => self::col('label', 'varchar', 'varchar', ['flags' => MYSQLI_NOT_NULL_FLAG, 'is_nullable' => NULL]),
          'amount' => ['name' => 'amount', 'data_type' => 'decimal', 'Type' => 'decimal(10,2)', 'flags' => 0],
        ]);
        $errors = $this->validator->validateVsQuery('SELECT ...', ['label' => ' ', 'amount' => 'x', 'other' => 'x']);
        self::assertSame(['label' => 'Label is required.', 'amount' => 'Amount must be a number.'], $errors);
    }

    // validateUnique

    public function testValidateUniqueExcludesSameRecordAndSkipsIncompleteIndexes(): void {
        $this->mockMetadata->method('uniqueIndexes')->willReturn([
          'uk_code' => ['cols' => ['code'], 'comment' => ''],
          'uk_status_qty' => ['cols' => ['status', 'qty'], 'comment' => 'Only one per status and qty.'],
          'uk_category' => ['cols' => ['category_id'], 'comment' => ''],
          'uk_notes' => ['cols' => ['notes'], 'comment' => ''],
        ]);
        $this->mockSqlExecutor->expects(self::exactly(2))->method('row')
          ->willReturnCallback(function(string $sql, array $parameters) {
              self::assertStringContainsString('AND NOT (`item_id` = ?)', $sql);
              self::assertSame('7', end($parameters));
              return str_contains($sql, '`code` = ?') ? ['1' => '1'] : [];
          });

        $errors = $this->validator->validateUnique('item', ['item_id' => '7', 'code' => ' AB ', 'status' => 'active', 'qty' => '2', 'category_id' => '']);
        self::assertSame(['uk_code' => 'A record with the same Code already exists.'], $errors);
    }

    public function testValidateUniqueUsesIndexCommentAndNoPkOnInsert(): void {
        $this->mockMetadata->method('uniqueIndexes')->willReturn([
          'uk_status_qty' => ['cols' => ['status', 'qty'], 'comment' => 'Only one per status and qty.'],
        ]);
        $this->mockSqlExecutor->expects(self::once())->method('row')
          ->willReturnCallback(function(string $sql, array $parameters) {
              self::assertStringNotContainsString('NOT (', $sql);
              self::assertSame(['active', '2'], $parameters);
              return ['1' => '1'];
          });

        $errors = $this->validator->validateUnique('item', ['status' => 'active', 'qty' => '2.00']);
        self::assertSame(['uk_status_qty' => 'Only one per status and qty.'], $errors);
    }

    // validateForeignKeys

    public function testValidateForeignKeys(): void {
        $this->mockMetadata->method('foreignKeysAll')->willReturn(['references' => ['item' => [
          'category' => ['fk_item_category' => ['referencing_column' => ['category_id'], 'referenced_column' => ['category_id']]],
          'status_type' => ['fk_item_status' => ['referencing_column' => ['status'], 'referenced_column' => ['status_code']]],
          'box' => ['fk_item_box' => ['referencing_column' => ['code', 'qty'], 'referenced_column' => ['box_code', 'box_qty']]],
        ]]]);
        $this->mockSqlExecutor->expects(self::exactly(2))->method('row')
          ->willReturnCallback(function(string $sql, array $parameters) {
              if(str_contains($sql, '`category`')) {
                  self::assertSame(['99'], $parameters);
                  return [];
              }
              self::assertStringContainsString('`status_type` WHERE `status_code` = ?', $sql);
              return ['1' => '1'];
          });

        $errors = $this->validator->validateForeignKeys('item', ['category_id' => ' 99 ', 'status' => 'active', 'code' => 'AB']);
        self::assertSame(['fk_item_category' => 'The selected Category Id does not exist.'], $errors);
    }

    public function testValidateForeignKeysSkipsNullAndEmptyNullable(): void {
        $this->mockMetadata->method('foreignKeysAll')->willReturn(['references' => ['item' => [
          'category' => ['fk_item_category' => ['referencing_column' => ['category_id'], 'referenced_column' => ['category_id']]],
        ]]]);
        $this->mockSqlExecutor->expects(self::never())->method('row');
        self::assertSame([], $this->validator->validateForeignKeys('item', ['category_id' => '']));
        self::assertSame([], $this->validator->validateForeignKeys('item', ['category_id' => NULL]));
    }
}
