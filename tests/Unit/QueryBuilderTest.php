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


    /**
     * The whole returned array is compared with assertSame: statement order,
     * exact SQL text and exact parameter order are all part of the contract.
     */
    #[DataProvider('junctionTableProvider')]
    public function testJunctionTable(
      string $tableName,
      string $tableA_column,
      int|string $tableA_id_value,
      string $tableB_column,
      array $values,
      string $comment,
      array $expected
    ): void {
        $result = $this->queryBuilder->junctionTable(
          $tableName, $tableA_column, $tableA_id_value, $tableB_column, $values, $comment
        );

        $this->assertSame($expected, $result);
    }

    public static function junctionTableProvider(): array {
        $c = '/*Ocallit\Sqler\QueryBuilder::junctionTable*/';
        return [
          'delete_first_then_one_upsert_per_row_in_input_order' => [
            'tableA_to_tableB', 'tableA_id', 'A-1', 'tableB_id',
            [
              ['tableB_id' => 10],
              ['tableB_id' => 20, 'sort_order' => 2],
            ],
            '',
            [
              [
                'query' => "DELETE $c FROM `tableA_to_tableB` WHERE  (`tableA_id`=?) AND `tableB_id` NOT IN (?,?)",
                'parameters' => ['A-1', 10, 20],
              ],
              [
                'query' => "INSERT $c  INTO `tableA_to_tableB`(`tableA_id`,`tableB_id`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `tableA_id`=new.`tableA_id`,`tableB_id`=new.`tableB_id`",
                'parameters' => ['A-1', 10],
              ],
              [
                'query' => "INSERT $c  INTO `tableA_to_tableB`(`tableA_id`,`tableB_id`,`sort_order`)  VALUES(?,?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `tableA_id`=new.`tableA_id`,`tableB_id`=new.`tableB_id`,`sort_order`=new.`sort_order`",
                'parameters' => ['A-1', 20, 2],
              ],
            ],
          ],
          'empty_values_deletes_all_rows_for_tableA_id_no_not_in' => [
            'tableA_to_tableB', 'tableA_id', 7, 'tableB_id',
            [],
            '',
            [
              [
                'query' => "DELETE $c FROM `tableA_to_tableB` WHERE  (`tableA_id`=?)",
                'parameters' => [7],
              ],
            ],
          ],
          'row_carrying_conflicting_tableA_value_is_overridden_by_argument' => [
            'tableA_to_tableB', 'tableA_id', 7, 'tableB_id',
            [['tableB_id' => 10, 'tableA_id' => 99]],
            '',
            [
              [
                'query' => "DELETE $c FROM `tableA_to_tableB` WHERE  (`tableA_id`=?) AND `tableB_id` NOT IN (?)",
                'parameters' => [7, 10],
              ],
              [
                  // 99 must appear nowhere: the argument value 7 wins
                'query' => "INSERT $c  INTO `tableA_to_tableB`(`tableA_id`,`tableB_id`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `tableA_id`=new.`tableA_id`,`tableB_id`=new.`tableB_id`",
                'parameters' => [7, 10],
              ],
            ],
          ],
          'magic_mysql_function_in_extra_column_is_inlined_not_parameterized' => [
            'user_to_role', 'user_id', 5, 'role_id',
            [['role_id' => 1, 'granted_at' => 'NOW()']],
            '',
            [
              [
                'query' => "DELETE $c FROM `user_to_role` WHERE  (`user_id`=?) AND `role_id` NOT IN (?)",
                'parameters' => [5, 1],
              ],
              [
                'query' => "INSERT $c  INTO `user_to_role`(`user_id`,`role_id`,`granted_at`)  VALUES(?,?,NOW())"
                  . "  as new ON DUPLICATE KEY UPDATE `user_id`=new.`user_id`,`role_id`=new.`role_id`,`granted_at`=new.`granted_at`",
                'parameters' => [5, 1],
              ],
            ],
          ],
          'duplicate_tableB_ids_kept_in_order_in_not_in_and_upserts' => [
            'tableA_to_tableB', 'tableA_id', 7, 'tableB_id',
            [['tableB_id' => 10], ['tableB_id' => 10]],
            '',
            [
              [
                'query' => "DELETE $c FROM `tableA_to_tableB` WHERE  (`tableA_id`=?) AND `tableB_id` NOT IN (?,?)",
                'parameters' => [7, 10, 10],
              ],
              [
                'query' => "INSERT $c  INTO `tableA_to_tableB`(`tableA_id`,`tableB_id`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `tableA_id`=new.`tableA_id`,`tableB_id`=new.`tableB_id`",
                'parameters' => [7, 10],
              ],
              [
                'query' => "INSERT $c  INTO `tableA_to_tableB`(`tableA_id`,`tableB_id`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `tableA_id`=new.`tableA_id`,`tableB_id`=new.`tableB_id`",
                'parameters' => [7, 10],
              ],
            ],
          ],
          'custom_comment_used_in_every_statement' => [
            't', 'a', 1, 'b',
            [['b' => 2]],
            '/*myC*/',
            [
              [
                'query' => "DELETE /*myC*/ FROM `t` WHERE  (`a`=?) AND `b` NOT IN (?)",
                'parameters' => [1, 2],
              ],
              [
                'query' => "INSERT /*myC*/  INTO `t`(`a`,`b`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => [1, 2],
              ],
            ],
          ],
          'dotted_table_name_is_backticked_per_part' => [
            'mydb.tableA_to_tableB', 'tableA_id', 7, 'tableB_id',
            [['tableB_id' => 10]],
            '',
            [
              [
                'query' => "DELETE $c FROM `mydb`.`tableA_to_tableB` WHERE  (`tableA_id`=?) AND `tableB_id` NOT IN (?)",
                'parameters' => [7, 10],
              ],
              [
                'query' => "INSERT $c  INTO `mydb`.`tableA_to_tableB`(`tableA_id`,`tableB_id`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `tableA_id`=new.`tableA_id`,`tableB_id`=new.`tableB_id`",
                'parameters' => [7, 10],
              ],
            ],
          ],
        ];
    }

    public function testJunctionTableMissingTableBColumnThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("values[1] must be an array with a 'tableB_id' key");
        $this->queryBuilder->junctionTable(
          'tableA_to_tableB', 'tableA_id', 7, 'tableB_id',
          [['tableB_id' => 10], ['sort_order' => 1]]
        );
    }

    public function testJunctionTableNonArrayRowThrows(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("values[0] must be an array with a 'tableB_id' key");
        $this->queryBuilder->junctionTable(
          'tableA_to_tableB', 'tableA_id', 7, 'tableB_id',
          [10]
        );
    }

}
