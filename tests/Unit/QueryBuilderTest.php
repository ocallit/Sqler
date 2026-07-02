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

    public function testSyncBridgeTableEmptyValuesReturnsEmptyArray(): void {
        $this->assertSame([], $this->queryBuilder->syncBridgeTable('tableA_to_tableB', 'tableA_id', 'tableB_id', []));
    }

    public function testSyncBridgeTableReturnsDeleteThenInsertStatements(): void {
        $result = $this->queryBuilder->syncBridgeTable('tableA_to_tableB', 'tableA_id', 'tableB_id', [
          ['tableA_id' => 'a1', 'tableB_id' => 1, 'note' => 'x', 'priority' => 1],
          ['tableA_id' => 'a1', 'tableB_id' => 2, 'note' => 'y', 'priority' => 2],
          ['tableA_id' => 'a2', 'tableB_id' => 1, 'note' => 'z', 'priority' => 3],
          ['tableA_id' => 'a2', 'tableB_id' => 5, 'note' => 'w', 'priority' => 4],
        ]);

        $this->assertCount(2, $result);
        foreach($result as $statement) {
            $this->assertArrayHasKey('query', $statement);
            $this->assertArrayHasKey('parameters', $statement);
            $this->assertIsString($statement['query']);
            $this->assertIsArray($statement['parameters']);
        }

        [$delete, $insert] = $result;

        $comment = '/*Ocallit\Sqler\QueryBuilder::syncBridgeTable*/';

        $this->assertSame(
          "DELETE $comment FROM `tableA_to_tableB`" .
          " WHERE `tableA_id` IN (?,?)" .
          " AND (`tableA_id`,`tableB_id`) NOT IN ((?,?),(?,?),(?,?),(?,?))",
          $delete['query']
        );
        $this->assertSame(['a1', 'a2', 'a1', 1, 'a1', 2, 'a2', 1, 'a2', 5], $delete['parameters']);

        $this->assertSame(
          "INSERT $comment INTO `tableA_to_tableB`(`tableA_id`,`tableB_id`,`note`,`priority`)" .
          " VALUES(?,?,?,?),(?,?,?,?),(?,?,?,?),(?,?,?,?)" .
          " AS new ON DUPLICATE KEY UPDATE `tableA_id`=new.`tableA_id`,`tableB_id`=new.`tableB_id`,`note`=new.`note`,`priority`=new.`priority`",
          $insert['query']
        );
        $this->assertSame(
          ['a1', 1, 'x', 1, 'a1', 2, 'y', 2, 'a2', 1, 'z', 3, 'a2', 5, 'w', 4],
          $insert['parameters']
        );
    }

    public function testSyncBridgeTableUsesValuesSyntaxWhenNotUsingNewOnDuplicate(): void {
        $queryBuilder = new QueryBuilder(false);
        $result = $queryBuilder->syncBridgeTable('tableA_to_tableB', 'tableA_id', 'tableB_id', [
          ['tableA_id' => 'a1', 'tableB_id' => 1],
        ]);

        $this->assertStringContainsString('`tableB_id`=VALUES(`tableB_id`)', $result[1]['query']);
    }
}
