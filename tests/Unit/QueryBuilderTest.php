<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\QueryBuilder;



#[CoversClass(QueryBuilder::class)]
class QueryBuilderTest extends TestCase {
    private QueryBuilder $queryBuilder;

    protected function setUp(): void {
        $this->queryBuilder = new QueryBuilder();
    }


    #[DataProvider('insertProvider')]
    public function testInsert(
      string $table,
      array  $data,
      bool   $onDuplicateKeyUpdate,
      array  $onDuplicateKeyDontUpdate,
      array  $onDuplicateKeyOverride,
      string $comment,
      array  $expectedStructure
    ): void {
        $result = $this->queryBuilder->insert(
          $table,
          $data,
          $onDuplicateKeyUpdate,
          $onDuplicateKeyDontUpdate,
          $onDuplicateKeyOverride,
          $comment
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('parameters', $result);
        $this->assertIsString($result['query']);
        $this->assertIsArray($result['parameters']);

        // Check parameter count matches expected
        $this->assertCount($expectedStructure['parameter_count'], $result['parameters']);

        // Check query contains expected elements
        foreach($expectedStructure['query_contains'] as $substring) {
            $this->assertStringContainsString($substring, $result['query']);
        }

        // Check parameters match expected values
        $this->assertEquals($expectedStructure['parameters'], $result['parameters']);
    }

    public static function insertProvider(): array {
        return [
          'simple_insert' => [
            'users',
            ['name' => 'John', 'age' => 30],
            FALSE,
            [],
            [],
            '',
            [
              'parameter_count' => 2,
              'query_contains' => ['INSERT', '`users`', '(`name`,`age`)', 'VALUES(?,?)'],
              'parameters' => ['John', 30],
            ],
          ],
          'insert_with_functions' => [
            'users',
            ['name' => 'John', 'created_at' => 'NOW()'],
            FALSE,
            [],
            [],
            '',
            [
              'parameter_count' => 1,
              'query_contains' => ['INSERT', '`users`', '(`name`,`created_at`)', 'VALUES(?,NOW())'],
              'parameters' => ['John'],
            ],
          ],
          'insert_with_duplicate_key_update' => [
            'users',
            ['name' => 'John', 'age' => 30],
            TRUE,
            [],
            [],
            '',
            [
              'parameter_count' => 2,
              'query_contains' => ['INSERT', 'ON DUPLICATE KEY UPDATE', '`name`=new.`name`', '`age`=new.`age`'],
              'parameters' => ['John', 30],
            ],
          ],
          'insert_with_dont_update_fields' => [
            'users',
            ['name' => 'John', 'alta_db' => 'NOW()'],
            TRUE,
            ['alta_db' => TRUE],
            [],
            '',
            [
              'parameter_count' => 1,
              'query_contains' => ['INSERT', 'ON DUPLICATE KEY UPDATE', '`name`=new.`name`'],
              'parameters' => ['John'],
            ],
          ],
          'insert_with_override_fields' => [
            'users',
            ['name' => 'John', 'age' => 30],
            TRUE,
            [],
            ['age' => 'VALUES(`age`) + 1'],
            '',
            [
              'parameter_count' => 2,
              'query_contains' => ['INSERT', 'ON DUPLICATE KEY UPDATE', '`age`=VALUES(`age`) + 1'],
              'parameters' => ['John', 30],
            ],
          ],
        ];
    }


    #[DataProvider('updateProvider')]
    public function testUpdate(
      string $table,
      array  $data,
      array  $where,
      string $comment,
      array  $expectedStructure
    ): void {
        $result = $this->queryBuilder->update($table, $data, $where, $comment);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('parameters', $result);
        $this->assertIsString($result['query']);
        $this->assertIsArray($result['parameters']);

        // Check parameter count matches expected
        $this->assertCount($expectedStructure['parameter_count'], $result['parameters']);

        // Check query contains expected elements
        foreach($expectedStructure['query_contains'] as $substring) {
            $this->assertStringContainsString($substring, $result['query']);
        }

        // Check parameters match expected values
        $this->assertEquals($expectedStructure['parameters'], $result['parameters']);
    }

    public static function updateProvider(): array {
        return [
          'simple_update' => [
            'users',
            ['name' => 'John', 'age' => 30],
            ['id' => 1],
            '',
            [
              'parameter_count' => 3,
              'query_contains' => ['UPDATE', '`users`', 'SET', '`name`=?', '`age`=?', 'WHERE', '`id`=?'],
              'parameters' => ['John', 30, 1],
            ],
          ],
          'update_with_functions' => [
            'users',
            ['name' => 'John', 'updated_at' => 'NOW()'],
            ['id' => 1],
            '',
            [
              'parameter_count' => 2,
              'query_contains' => ['UPDATE', '`users`', 'SET', '`name`=?', '`updated_at`=NOW()', 'WHERE', '`id`=?'],
              'parameters' => ['John', 1],
            ],
          ],
          'update_no_where' => [
            'users',
            ['status' => 'active'],
            [],
            '',
            [
              'parameter_count' => 1,
              'query_contains' => ['UPDATE', '`users`', 'SET', '`status`=?', 'WHERE'],
              'parameters' => ['active'],
            ],
          ],
        ];
    }
    
    #[DataProvider('whereProvider')]
    public function testWhere(
      array  $conditions,
      string $operator,
      string $comment,
      array  $expectedStructure
    ): void {
        $result = $this->queryBuilder->where($conditions, $operator, $comment);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('parameters', $result);
        $this->assertIsString($result['query']);
        $this->assertIsArray($result['parameters']);

        // Check parameter count matches expected
        $this->assertCount($expectedStructure['parameter_count'], $result['parameters']);

        // Check query contains expected elements
        foreach($expectedStructure['query_contains'] as $substring) {
            $this->assertStringContainsString($substring, $result['query']);
        }

        // Check parameters match expected values
        $this->assertEquals($expectedStructure['parameters'], $result['parameters']);
    }

    public static function whereProvider(): array {
        return [
          'simple_where' => [
            ['id' => 1, 'status' => 'active'],
            'AND',
            '',
            [
              'parameter_count' => 2,
              'query_contains' => ['`id`=?', 'AND', '`status`=?'],
              'parameters' => [1, 'active'],
            ],
          ],
          'where_with_or' => [
            ['status' => 'active', 'priority' => 'high'],
            'OR',
            '',
            [
              'parameter_count' => 2,
              'query_contains' => ['`status`=?', 'OR', '`priority`=?'],
              'parameters' => ['active', 'high'],
            ],
          ],
          'where_with_in_clause' => [
            ['id' => [1, 2, 3], 'status' => 'active'],
            'AND',
            '',
            [
              'parameter_count' => 4,
              'query_contains' => ['`id` IN', '(?,?,?)', 'AND', '`status`=?'],
              'parameters' => [1, 2, 3, 'active'],
            ],
          ],
          'where_with_functions' => [
            ['created_at' => 'NOW()', 'status' => 'active'],
            'AND',
            '',
            [
              'parameter_count' => 1,
              'query_contains' => ['`created_at`=NOW()', 'AND', '`status`=?'],
              'parameters' => ['active'],
            ],
          ],
          'empty_where' => [
            [],
            'AND',
            'test',
            [
              'parameter_count' => 0,
              'query_contains' => ['/*test*/'],
              'parameters' => [],
            ],
          ],
        ];
    }

    public function testJunctionTableSyncsRows(): void {
        $result = $this->queryBuilder->junctionTable(
          'tableA_to_tableB', 'tableA_id', 'A-1', 'tableB_id',
          [
            ['tableB_id' => 10],
            ['tableB_id' => 20, 'sort_order' => 2],
          ]
        );

        $this->assertCount(3, $result);

        $delete = $result[0];
        $this->assertStringContainsString('DELETE', $delete['query']);
        $this->assertStringContainsString('`tableA_to_tableB`', $delete['query']);
        $this->assertStringContainsString('`tableA_id`=?', $delete['query']);
        $this->assertStringContainsString('`tableB_id` NOT IN (?,?)', $delete['query']);
        $this->assertEquals(['A-1', 10, 20], $delete['parameters']);

        $firstUpsert = $result[1];
        $this->assertStringContainsString('INSERT', $firstUpsert['query']);
        $this->assertStringContainsString('(`tableA_id`,`tableB_id`)', $firstUpsert['query']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $firstUpsert['query']);
        $this->assertEquals(['A-1', 10], $firstUpsert['parameters']);

        $secondUpsert = $result[2];
        $this->assertStringContainsString('(`tableA_id`,`tableB_id`,`sort_order`)', $secondUpsert['query']);
        $this->assertStringContainsString('`sort_order`=new.`sort_order`', $secondUpsert['query']);
        $this->assertEquals(['A-1', 20, 2], $secondUpsert['parameters']);
    }

    public function testJunctionTableEmptyValuesDeletesAllForTableAId(): void {
        $result = $this->queryBuilder->junctionTable('tableA_to_tableB', 'tableA_id', 7, 'tableB_id', []);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('DELETE', $result[0]['query']);
        $this->assertStringContainsString('`tableA_id`=?', $result[0]['query']);
        $this->assertStringNotContainsString('NOT IN', $result[0]['query']);
        $this->assertEquals([7], $result[0]['parameters']);
    }

    public function testJunctionTableRowValueOverridesTableAColumn(): void {
        $result = $this->queryBuilder->junctionTable(
          'tableA_to_tableB', 'tableA_id', 7, 'tableB_id',
          [['tableB_id' => 10, 'tableA_id' => 99]]
        );

        $this->assertEquals([7, 10], $result[1]['parameters']);
    }

    public function testJunctionTableMissingTableBColumnThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->queryBuilder->junctionTable(
          'tableA_to_tableB', 'tableA_id', 7, 'tableB_id',
          [['sort_order' => 1]]
        );
    }
}
