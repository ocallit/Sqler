<?php /** @noinspection SqlResolve */

use Ocallit\Sqler\SqlUtils;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\QueryBuilder;


require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * A plain collaborator used to prove that the auto generated comment names the
 * CALLER of the builder, not the builder itself. It is not a mock: it really
 * calls the real QueryBuilder.
 */
class QueryBuilderCallerDouble {
    public static function buildStatically(QueryBuilder $queryBuilder): array {
        return $queryBuilder->where(['a' => 1]);
    }

    public function buildFromInstance(QueryBuilder $queryBuilder): array {
        return $queryBuilder->where(['a' => 1]);
    }
}

#[CoversClass(QueryBuilder::class)]
class QueryBuilderTest extends TestCase {

    private QueryBuilder $queryBuilder;

    protected function setUp(): void {
        $this->queryBuilder = new QueryBuilder();
    }


    /**
     * The full result is compared with assertSame: the exact SQL text, the exact
     * parameter values and their order are all part of the contract.
     */
    #[DataProvider('insertProvider')]
    public function testInsert(
      string $table,
      array  $data,
      bool   $onDuplicateKeyUpdate,
      array  $onDuplicateKeyDontUpdate,
      array  $onDuplicateKeyOverride,
      string $comment,
      string $expectedQuery,
      array  $expectedParameters
    ): void {
        $result = $this->queryBuilder->insert(
          $table,
          $data,
          $onDuplicateKeyUpdate,
          $onDuplicateKeyDontUpdate,
          $onDuplicateKeyOverride,
          $comment
        );

        $this->assertSame(['query', 'parameters'], array_keys($result));
        $this->assertSame($expectedQuery, $result['query']);
        $this->assertSame($expectedParameters, $result['parameters']);
    }

    public static function insertProvider(): array {
        // an empty comment makes insert() name its caller, which here is the test method
        $auto = '/*QueryBuilderTest::testInsert*/';
        return [
          'simple_insert' => [
            'users', ['name' => 'John', 'age' => 30], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `users`(`name`,`age`)  VALUES(?,?)',
            ['John', 30],
          ],
          'insert_with_functions' => [
            'users', ['name' => 'John', 'created_at' => 'NOW()'], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `users`(`name`,`created_at`)  VALUES(?,NOW())',
            ['John'],
          ],
          'insert_with_duplicate_key_update' => [
            'users', ['name' => 'John', 'age' => 30], TRUE, [], [], 'c',
            'INSERT /*c*/  INTO `users`(`name`,`age`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `name`=new.`name`,`age`=new.`age`',
            ['John', 30],
          ],
          'insert_with_dont_update_fields' => [
            'users', ['name' => 'John', 'alta_db' => 'NOW()'], TRUE, ['alta_db' => TRUE], [], 'c',
            'INSERT /*c*/  INTO `users`(`name`,`alta_db`)  VALUES(?,NOW())'
            . '  as new ON DUPLICATE KEY UPDATE `name`=new.`name`',
            ['John'],
          ],
          'insert_with_override_fields' => [
            'users', ['name' => 'John', 'age' => 30], TRUE, [], ['age' => 'VALUES(`age`) + 1'], 'c',
            'INSERT /*c*/  INTO `users`(`name`,`age`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `name`=new.`name`,`age`=VALUES(`age`) + 1',
            ['John', 30],
          ],

            // ---- the magic value list is an exact, case sensitive string match ----
          'lowercase_function_is_a_plain_parameter' => [
            't', ['a' => 'now()'], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)',
            ['now()'],
          ],
          'function_with_spaces_is_a_plain_parameter' => [
            't', ['a' => 'NOW ()'], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)',
            ['NOW ()'],
          ],
            // the class docblock advertises UUID(), but the list only holds IA_UUID()
          'plain_uuid_is_not_in_the_magic_list' => [
            't', ['a' => 'UUID()'], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)',
            ['UUID()'],
          ],
          'ia_uuid_is_in_the_magic_list' => [
            't', ['a' => 'IA_UUID()'], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(IA_UUID())',
            [],
          ],
          'now_with_precision_is_in_the_magic_list' => [
            't', ['a' => 'NOW(6)'], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(NOW(6))',
            [],
          ],
          'current_timestamp_without_parentheses_is_magic' => [
            't', ['a' => 'CURRENT_TIMESTAMP'], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(CURRENT_TIMESTAMP)',
            [],
          ],

            // ---- non string values never take the magic branch ----
          'null_is_parameterized' => [
            't', ['a' => NULL], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)',
            [NULL],
          ],
          'scalars_keep_their_php_type' => [
            't', ['a' => TRUE, 'b' => FALSE, 'c' => 1.5, 'd' => 0], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`b`,`c`,`d`)  VALUES(?,?,?,?)',
            [TRUE, FALSE, 1.5, 0],
          ],

            // ---- degenerate input ----
          'empty_data_produces_an_empty_column_list' => [
            't', [], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`()  VALUES()',
            [],
          ],
          'empty_data_with_upsert_has_no_duplicate_clause' => [
            't', [], TRUE, [], [], 'c',
            'INSERT /*c*/  INTO `t`()  VALUES()',
            [],
          ],
          'list_keys_become_numeric_column_names' => [
            't', ['x', 'y'], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`0`,`1`)  VALUES(?,?)',
            ['x', 'y'],
          ],

            // ---- onDuplicateKeyDontUpdate accepts both shapes ----
          'dont_update_as_a_list' => [
            't', ['a' => 1, 'b' => 2, 'c' => 3], TRUE, ['a', 'c'], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`b`,`c`)  VALUES(?,?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `b`=new.`b`',
            [1, 2, 3],
          ],
          'dont_update_as_a_keyed_set' => [
            't', ['a' => 1, 'b' => 2], TRUE, ['a' => TRUE], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`b`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `b`=new.`b`',
            [1, 2],
          ],
            // array_key_exists is used, so a falsy value still excludes the column
          'dont_update_keyed_with_a_falsy_value_still_excludes' => [
            't', ['a' => 1, 'b' => 2], TRUE, ['a' => FALSE, 'x' => NULL], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`b`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `b`=new.`b`',
            [1, 2],
          ],
          'dont_update_of_an_absent_column_changes_nothing' => [
            't', ['a' => 1], TRUE, ['zzz'], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)'
            . '  as new ON DUPLICATE KEY UPDATE `a`=new.`a`',
            [1],
          ],
          'dont_update_wins_over_override_for_the_same_column' => [
            't', ['a' => 1, 'b' => 2], TRUE, ['b'], ['b' => '99'], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`b`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `a`=new.`a`',
            [1, 2],
          ],
          'override_is_emitted_verbatim_and_is_not_a_parameter' => [
            't', ['a' => 1], TRUE, [], ['a' => '`a` + 1'], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)'
            . '  as new ON DUPLICATE KEY UPDATE `a`=`a` + 1',
            [1],
          ],
          'override_applies_to_a_magic_valued_column_too' => [
            't', ['a' => 'NOW()'], TRUE, [], ['a' => 'CURDATE()'], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(NOW())'
            . '  as new ON DUPLICATE KEY UPDATE `a`=CURDATE()',
            [],
          ],
          'override_of_an_absent_column_is_ignored' => [
            't', ['a' => 1], TRUE, [], ['zzz' => 'x'], 'c',
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)'
            . '  as new ON DUPLICATE KEY UPDATE `a`=new.`a`',
            [1],
          ],

            // ---- the built in never-update column list ----
          'alta_db_is_never_updated' => [
            't', ['a' => 1, 'alta_db' => 2], TRUE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`alta_db`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `a`=new.`a`',
            [1, 2],
          ],
          'alta_por_is_never_updated' => [
            't', ['a' => 1, 'alta_por' => 2], TRUE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`alta_por`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `a`=new.`a`',
            [1, 2],
          ],
          'registered_is_never_updated' => [
            't', ['a' => 1, 'registered' => 2], TRUE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`registered`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `a`=new.`a`',
            [1, 2],
          ],
          'registered_by_is_never_updated' => [
            't', ['a' => 1, 'registered_by' => 2], TRUE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`a`,`registered_by`)  VALUES(?,?)'
            . '  as new ON DUPLICATE KEY UPDATE `a`=new.`a`',
            [1, 2],
          ],
            // an override cannot resurrect a built in excluded column
          'override_cannot_resurrect_a_built_in_excluded_column' => [
            't', ['registered' => 2], TRUE, [], ['registered' => 'NOW()'], 'c',
            'INSERT /*c*/  INTO `t`(`registered`)  VALUES(?)',
            [2],
          ],
            // when every column is excluded the row alias and the whole clause disappear
          'no_duplicate_clause_and_no_row_alias_when_every_column_is_excluded' => [
            't', ['alta_db' => 1, 'registered' => 2], TRUE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`alta_db`,`registered`)  VALUES(?,?)',
            [1, 2],
          ],

            // ---- identifier quoting ----
          'dotted_table_name_is_quoted_per_part' => [
            'mydb.users', ['a' => 1], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `mydb`.`users`(`a`)  VALUES(?)',
            [1],
          ],
          'already_quoted_table_name_is_left_alone' => [
            '`mydb`.`users`', ['a' => 1], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `mydb`.`users`(`a`)  VALUES(?)',
            [1],
          ],
          'backticks_in_a_column_name_are_stripped' => [
            't', ['a`b' => 1], FALSE, [], [], 'c',
            'INSERT /*c*/  INTO `t`(`ab`)  VALUES(?)',
            [1],
          ],

            // ---- comment handling ----
          'comment_markers_inside_the_comment_are_stripped' => [
            't', ['a' => 1], FALSE, [], [], 'x*/ DROP TABLE t; /*y',
            'INSERT /*x DROP TABLE t; y*/  INTO `t`(`a`)  VALUES(?)',
            [1],
          ],
          'an_empty_comment_names_the_calling_method' => [
            't', ['a' => 1], FALSE, [], [], '',
            "INSERT $auto  INTO `t`(`a`)  VALUES(?)",
            [1],
          ],
            // empty('0') is true, so the literal comment '0' falls back to the caller name
          'the_comment_zero_is_treated_as_empty' => [
            't', ['a' => 1], FALSE, [], [], '0',
            "INSERT $auto  INTO `t`(`a`)  VALUES(?)",
            [1],
          ],
        ];
    }


    /**
     * With useNewOnDuplicate = false the pre 8.0.19 VALUES() syntax is used and
     * the "as new" row alias must not appear anywhere.
     */
    #[DataProvider('insertLegacyProvider')]
    public function testInsertUsesLegacyValuesSyntaxWhenRowAliasIsDisabled(
      array  $data,
      bool   $onDuplicateKeyUpdate,
      array  $onDuplicateKeyDontUpdate,
      array  $onDuplicateKeyOverride,
      string $expectedQuery,
      array  $expectedParameters
    ): void {
        $result = (new QueryBuilder(FALSE))->insert(
          't', $data, $onDuplicateKeyUpdate, $onDuplicateKeyDontUpdate, $onDuplicateKeyOverride, 'c'
        );

        $this->assertSame($expectedQuery, $result['query']);
        $this->assertSame($expectedParameters, $result['parameters']);
    }

    public static function insertLegacyProvider(): array {
        return [
          'no_upsert_is_identical_to_the_modern_builder' => [
            ['a' => 1], FALSE, [], [],
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)',
            [1],
          ],
            // note the single space: the "  as new" chunk is what carries the second one
          'upsert_uses_values_and_a_single_leading_space' => [
            ['a' => 1, 'b' => 2], TRUE, [], [],
            'INSERT /*c*/  INTO `t`(`a`,`b`)  VALUES(?,?)'
            . ' ON DUPLICATE KEY UPDATE `a`=VALUES(`a`),`b`=VALUES(`b`)',
            [1, 2],
          ],
          'exclusions_behave_the_same_as_in_modern_mode' => [
            ['a' => 1, 'b' => 2], TRUE, ['b'], [],
            'INSERT /*c*/  INTO `t`(`a`,`b`)  VALUES(?,?)'
            . ' ON DUPLICATE KEY UPDATE `a`=VALUES(`a`)',
            [1, 2],
          ],
          'overrides_bypass_the_values_syntax' => [
            ['a' => 1], TRUE, [], ['a' => '`a` + 1'],
            'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)'
            . ' ON DUPLICATE KEY UPDATE `a`=`a` + 1',
            [1],
          ],
          'every_column_excluded_drops_the_clause' => [
            ['registered' => 1], TRUE, [], [],
            'INSERT /*c*/  INTO `t`(`registered`)  VALUES(?)',
            [1],
          ],
        ];
    }

    public function testUseNewOnDuplicateDefaultsToTrue(): void {
        $this->assertTrue((new QueryBuilder())->useNewOnDuplicate);
    }

    public function testUseNewOnDuplicateCanBeFlippedAfterConstruction(): void {
        $queryBuilder = new QueryBuilder();
        $queryBuilder->useNewOnDuplicate = FALSE;

        $this->assertSame(
          'INSERT /*c*/  INTO `t`(`a`)  VALUES(?) ON DUPLICATE KEY UPDATE `a`=VALUES(`a`)',
          $queryBuilder->insert('t', ['a' => 1], TRUE, [], [], 'c')['query']
        );

        $queryBuilder->useNewOnDuplicate = TRUE;

        $this->assertSame(
          'INSERT /*c*/  INTO `t`(`a`)  VALUES(?)  as new ON DUPLICATE KEY UPDATE `a`=new.`a`',
          $queryBuilder->insert('t', ['a' => 1], TRUE, [], [], 'c')['query']
        );
    }


    #[DataProvider('updateProvider')]
    public function testUpdate(
      string $table,
      array  $data,
      array  $where,
      string $comment,
      string $expectedQuery,
      array  $expectedParameters
    ): void {
        $result = $this->queryBuilder->update($table, $data, $where, $comment);

        $this->assertSame(['query', 'parameters'], array_keys($result));
        $this->assertSame( SqlUtils::sTrim($expectedQuery), SqlUtils::sTrim($result['query']));
        $this->assertSame($expectedParameters, $result['parameters']);
    }

    public static function updateProvider(): array {
        // update() never forwards its comment to where(), so the WHERE always names update()

        $auto = '/*QueryBuilderTest::testUpdate*/';
        return [
          'simple_update' => [
            'users', ['name' => 'John', 'age' => 30], ['id' => 1], 'c',
            "UPDATE /*c*/ `users` SET `name`=?,`age`=? WHERE   (`id`=?)",
            ['John', 30, 1],
          ],
          'update_with_functions' => [
            'users', ['name' => 'John', 'updated_at' => 'NOW()'], ['id' => 1], 'c',
            "UPDATE /*c*/ `users` SET `name`=?,`updated_at`=NOW() WHERE   (`id`=?)",
            ['John', 1],
          ],
            // no WHERE conditions still emits the WHERE keyword: the statement hits every row
          'update_no_where' => [
            'users', ['status' => 'active'], [], 'c',
            "UPDATE /*c*/ `users` SET `status`=?",
            ['active'],
          ],
          'empty_data_leaves_the_set_list_empty' => [
            't', [], ['id' => 1], 'c',
            "UPDATE /*c*/ `t` SET  WHERE   (`id`=?)",
            [1],
          ],
          'empty_data_and_empty_where' => [
            't', [], [], 'c',
            "UPDATE /*c*/ `t` SET",
            [],
          ],
            // SET parameters always come before WHERE parameters
          'set_parameters_precede_where_parameters' => [
            't', ['a' => 1, 'b' => 2], ['c' => 3, 'd' => 4], 'c',
            "UPDATE /*c*/ `t` SET `a`=?,`b`=? WHERE   (`c`=? AND `d`=?)",
            [1, 2, 3, 4],
          ],
          'null_is_bound_in_set_but_becomes_is_null_in_where' => [
            't', ['a' => NULL], ['b' => NULL, 'id' => 7], 'c',
            "UPDATE /*c*/ `t` SET `a`=? WHERE   (`b` IS NULL AND `id`=?)",
            [NULL, 7],
          ],
          'where_list_becomes_an_in_clause' => [
            't', ['a' => 1], ['id' => [7, 8]], 'c',
            "UPDATE /*c*/ `t` SET `a`=? WHERE   (`id` IN (?,?))",
            [1, 7, 8],
          ],
          'magic_value_in_where_is_inlined' => [
            't', ['a' => 1], ['b' => 'CURDATE()'], 'c',
            "UPDATE /*c*/ `t` SET `a`=? WHERE   (`b`=CURDATE())",
            [1],
          ],
          'dotted_table_name_is_quoted_per_part' => [
            'mydb.t', ['a' => 1], ['id' => 2], 'c',
            "UPDATE /*c*/ `mydb`.`t` SET `a`=? WHERE   (`id`=?)",
            [1, 2],
          ],
          'comment_markers_inside_the_comment_are_stripped' => [
            't', ['a' => 1], ['id' => 2], 'x*/y',
            "UPDATE /*xy*/ `t` SET `a`=? WHERE   (`id`=?)",
            [1, 2],
          ],
          'an_empty_comment_names_the_calling_method' => [
            't', ['a' => 1], ['id' => 2], '',
            "UPDATE $auto `t` SET `a`=? WHERE   (`id`=?)",
            [1, 2],
          ],
        ];
    }


    #[DataProvider('whereProvider')]
    public function testWhere(
      array  $conditions,
      string $operator,
      string $comment,
      string $expectedQuery,
      array  $expectedParameters
    ): void {
        $result = $this->queryBuilder->where($conditions, $operator, $comment);

        $this->assertSame(['query', 'parameters'], array_keys($result));
        $this->assertSame($expectedQuery, $result['query']);
        $this->assertSame($expectedParameters, $result['parameters']);
    }

    public static function whereProvider(): array {

        return [
          'simple_where' => [
            ['id' => 1, 'status' => 'active'], 'AND', 'c',
            ' /*c*/ (`id`=? AND `status`=?)',
            [1, 'active'],
          ],
          'where_with_or' => [
            ['status' => 'active', 'priority' => 'high'], 'OR', 'c',
            ' /*c*/ (`status`=? OR `priority`=?)',
            ['active', 'high'],
          ],
          'where_with_in_clause' => [
            ['id' => [1, 2, 3], 'status' => 'active'], 'AND', 'c',
            ' /*c*/ (`id` IN (?,?,?) AND `status`=?)',
            [1, 2, 3, 'active'],
          ],
          'where_with_functions' => [
            ['created_at' => 'NOW()', 'status' => 'active'], 'AND', 'c',
            ' /*c*/ (`created_at`=NOW() AND `status`=?)',
            ['active'],
          ],
            // no conditions: only the comment, and the caller must add its own guard
          'empty_where' => [
            [], 'AND', 'test',
            '',
            [],
          ],
          'empty_where_still_names_the_caller' => [
            [], 'AND', '',
            "",
            [],
          ],
          'single_condition_is_still_parenthesized' => [
            ['id' => 1], 'AND', 'c',
            ' /*c*/ (`id`=?)',
            [1],
          ],

            // ---- null handling ----
          'null_becomes_is_null_without_a_parameter' => [
            ['deleted_at' => NULL], 'AND', 'c',
            ' /*c*/ (`deleted_at` IS NULL)',
            [],
          ],
          'null_does_not_disturb_the_parameter_order' => [
            ['a' => 1, 'b' => NULL, 'c' => 2], 'AND', 'c',
            ' /*c*/ (`a`=? AND `b` IS NULL AND `c`=?)',
            [1, 2],
          ],
            // only a real null takes the IS NULL branch
          'false_is_a_bound_parameter_not_is_null' => [
            ['a' => FALSE], 'AND', 'c',
            ' /*c*/ (`a`=?)',
            [FALSE],
          ],
          'empty_string_is_a_bound_parameter_not_is_null' => [
            ['a' => ''], 'AND', 'c',
            ' /*c*/ (`a`=?)',
            [''],
          ],
          'zero_is_a_bound_parameter' => [
            ['a' => 0], 'AND', 'c',
            ' /*c*/ (`a`=?)',
            [0],
          ],

            // ---- IN handling ----
            // an empty list would produce invalid SQL, so it degrades to IN (NULL), which matches nothing
          'empty_list_becomes_in_with_a_single_null_parameter' => [
            ['id' => []], 'AND', 'c',
            ' /*c*/ (`id` IN (?))',
            [NULL],
          ],
          'single_element_list_still_uses_in' => [
            ['id' => [5]], 'AND', 'c',
            ' /*c*/ (`id` IN (?))',
            [5],
          ],
          'magic_values_inside_a_list_are_inlined' => [
            ['d' => ['NOW()', 5]], 'AND', 'c',
            ' /*c*/ (`d` IN (NOW(),?))',
            [5],
          ],
            // null inside a list is bound, it does NOT become IS NULL
          'null_inside_a_list_is_bound' => [
            ['d' => [NULL, 5]], 'AND', 'c',
            ' /*c*/ (`d` IN (?,?))',
            [NULL, 5],
          ],
          'list_keys_are_ignored_only_values_are_used' => [
            ['d' => ['x' => 1, 'y' => 2]], 'AND', 'c',
            ' /*c*/ (`d` IN (?,?))',
            [1, 2],
          ],
          'duplicate_values_in_a_list_are_kept' => [
            ['d' => [1, 1]], 'AND', 'c',
            ' /*c*/ (`d` IN (?,?))',
            [1, 1],
          ],

            // ---- conjunction and identifiers ----
          'conjunction_is_padded_with_one_space_on_each_side' => [
            ['a' => 1, 'b' => 2], ',', 'c',
            ' /*c*/ (`a`=? , `b`=?)',
            [1, 2],
          ],
          'conjunction_is_emitted_verbatim' => [
            ['a' => 1, 'b' => 2], 'and', 'c',
            ' /*c*/ (`a`=? and `b`=?)',
            [1, 2],
          ],
          'conjunction_is_irrelevant_for_a_single_condition' => [
            ['a' => 1], 'OR', 'c',
            ' /*c*/ (`a`=?)',
            [1],
          ],
          'list_keys_become_numeric_column_names' => [
            ['a'], 'AND', 'c',
            ' /*c*/ (`0`=?)',
            ['a'],
          ],
          'qualified_column_names_are_quoted_per_part' => [
            ['t.id' => 1], 'AND', 'c',
            ' /*c*/ (`t`.`id`=?)',
            [1],
          ],
          'backticks_in_a_column_name_are_stripped' => [
            ['a`b' => 1], 'AND', 'c',
            ' /*c*/ (`ab`=?)',
            [1],
          ],
          'comment_markers_inside_the_comment_are_stripped' => [
            ['a' => 1], 'AND', '/*x*/',
            ' /*x*/ (`a`=?)',
            [1],
          ],
        ];
    }


    /**
     * inValues does NOT return the ['query' => .., 'parameters' => ..] shape the
     * other methods use: it returns a two element list [sqlFragment, parameters].
     */
    #[DataProvider('inValuesProvider')]
    public function testInValues(array $values, string $expectedFragment, array $expectedParameters): void {
        $result = $this->queryBuilder->inValues($values);

        $this->assertSame([0, 1], array_keys($result));
        $this->assertSame($expectedFragment, $result[0]);
        $this->assertSame($expectedParameters, $result[1]);
    }

    public static function inValuesProvider(): array {
        return [
            // an empty list would produce invalid SQL, so it degrades to (NULL), which matches nothing
          'empty_list_becomes_a_single_null_parameter' => [
            [], '(?)', [NULL],
          ],
          'single_value' => [
            [1], '(?)', [1],
          ],
          'mixed_scalars_keep_their_type_and_order' => [
            [1, 'a', NULL, TRUE, 1.5], '(?,?,?,?,?)', [1, 'a', NULL, TRUE, 1.5],
          ],
          'magic_values_are_inlined' => [
            ['NOW()', 'CURDATE()'], '(NOW(),CURDATE())', [],
          ],
          'magic_and_plain_values_mix' => [
            ['NOW()', 'now()', 5], '(NOW(),?,?)', ['now()', 5],
          ],
          'keys_are_ignored_only_values_are_used' => [
            ['k' => 1, 'j' => 2], '(?,?)', [1, 2],
          ],
          'duplicates_are_kept' => [
            [1, 1], '(?,?)', [1, 1],
          ],
          'an_explicit_null_is_bound' => [
            [NULL], '(?)', [NULL],
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
        // Outer statements identify the caller; the WHERE identifies junctionTable.
        $c = '/*QueryBuilderTest::testJunctionTable*/';
        $whereComment = "";
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
                'query' => "DELETE $c FROM `tableA_to_tableB` WHERE  $whereComment (`tableA_id`=?) AND `tableB_id` NOT IN (?,?)",
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
                'query' => "DELETE $c FROM `tableA_to_tableB` WHERE  $whereComment (`tableA_id`=?)",
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
                'query' => "DELETE $c FROM `tableA_to_tableB` WHERE  $whereComment (`tableA_id`=?) AND `tableB_id` NOT IN (?)",
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
                'query' => "DELETE $c FROM `user_to_role` WHERE  $whereComment (`user_id`=?) AND `role_id` NOT IN (?)",
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
                'query' => "DELETE $c FROM `tableA_to_tableB` WHERE  $whereComment (`tableA_id`=?) AND `tableB_id` NOT IN (?,?)",
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
                'query' => "DELETE /*myC*/ FROM `t` WHERE  $whereComment (`a`=?) AND `b` NOT IN (?)",
                'parameters' => [1, 2],
              ],
              [
                'query' => "INSERT /*myC*/  INTO `t`(`a`,`b`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => [1, 2],
              ],
            ],
          ],
            // the comment is sanitized once by junctionTable and survives insert() re-wrapping it
          'comment_markers_are_stripped_once_and_stay_stripped' => [
            't', 'a', 1, 'b',
            [['b' => 2]],
            'x*/ DROP TABLE t; /*y',
            [
              [
                'query' => "DELETE /*x DROP TABLE t; y*/ FROM `t` WHERE  $whereComment (`a`=?) AND `b` NOT IN (?)",
                'parameters' => [1, 2],
              ],
              [
                'query' => "INSERT /*x DROP TABLE t; y*/  INTO `t`(`a`,`b`)  VALUES(?,?)"
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
                'query' => "DELETE $c FROM `mydb`.`tableA_to_tableB` WHERE  $whereComment (`tableA_id`=?) AND `tableB_id` NOT IN (?)",
                'parameters' => [7, 10],
              ],
              [
                'query' => "INSERT $c  INTO `mydb`.`tableA_to_tableB`(`tableA_id`,`tableB_id`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `tableA_id`=new.`tableA_id`,`tableB_id`=new.`tableB_id`",
                'parameters' => [7, 10],
              ],
            ],
          ],
            // the DELETE parameters are the tableA id first, then the kept tableB ids in input order
          'delete_parameters_are_the_tableA_id_then_the_kept_tableB_ids' => [
            't', 'a', 'A', 'b',
            [['b' => 1], ['b' => 2], ['b' => 3]],
            'jc',
            [
              [
                'query' => "DELETE /*jc*/ FROM `t` WHERE  $whereComment (`a`=?) AND `b` NOT IN (?,?,?)",
                'parameters' => ['A', 1, 2, 3],
              ],
              [
                'query' => "INSERT /*jc*/  INTO `t`(`a`,`b`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => ['A', 1],
              ],
              [
                'query' => "INSERT /*jc*/  INTO `t`(`a`,`b`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => ['A', 2],
              ],
              [
                'query' => "INSERT /*jc*/  INTO `t`(`a`,`b`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => ['A', 3],
              ],
            ],
          ],
            // a magic value as the tableA id is inlined in both the DELETE and the INSERT
          'magic_value_as_tableA_id_is_inlined' => [
            't', 'a', 'NOW()', 'b',
            [['b' => 1]],
            'jc',
            [
              [
                'query' => "DELETE /*jc*/ FROM `t` WHERE  $whereComment (`a`=NOW()) AND `b` NOT IN (?)",
                'parameters' => [1],
              ],
              [
                'query' => "INSERT /*jc*/  INTO `t`(`a`,`b`)  VALUES(NOW(),?)"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => [1],
              ],
            ],
          ],
            // a magic value as a tableB id is inlined in the NOT IN list too
          'magic_value_as_tableB_id_is_inlined_in_not_in' => [
            't', 'a', 1, 'b',
            [['b' => 'NOW()']],
            'jc',
            [
              [
                'query' => "DELETE /*jc*/ FROM `t` WHERE  $whereComment (`a`=?) AND `b` NOT IN (NOW())",
                'parameters' => [1],
              ],
              [
                'query' => "INSERT /*jc*/  INTO `t`(`a`,`b`)  VALUES(?,NOW())"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => [1],
              ],
            ],
          ],
            // insert()'s built in exclusions still apply to junction extra columns
          'never_updated_extra_column_is_inserted_but_not_refreshed' => [
            't', 'a', 1, 'b',
            [['b' => 2, 'registered' => 'NOW()']],
            'jc',
            [
              [
                'query' => "DELETE /*jc*/ FROM `t` WHERE  $whereComment (`a`=?) AND `b` NOT IN (?)",
                'parameters' => [1, 2],
              ],
              [
                'query' => "INSERT /*jc*/  INTO `t`(`a`,`b`,`registered`)  VALUES(?,?,NOW())"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => [1, 2],
              ],
            ],
          ],
          'null_tableB_id_is_bound_in_both_statements' => [
            't', 'a', 1, 'b',
            [['b' => NULL]],
            'jc',
            [
              [
                'query' => "DELETE /*jc*/ FROM `t` WHERE  $whereComment (`a`=?) AND `b` NOT IN (?)",
                'parameters' => [1, NULL],
              ],
              [
                'query' => "INSERT /*jc*/  INTO `t`(`a`,`b`)  VALUES(?,?)"
                  . "  as new ON DUPLICATE KEY UPDATE `a`=new.`a`,`b`=new.`b`",
                'parameters' => [1, NULL],
              ],
            ],
          ],
        ];
    }

    public function testJunctionTableUsesLegacySyntaxWhenRowAliasIsDisabled(): void {
        $result = (new QueryBuilder(FALSE))->junctionTable('t', 'a', 1, 'b', [['b' => 2]], 'jc');

        $this->assertSame(
          'INSERT /*jc*/  INTO `t`(`a`,`b`)  VALUES(?,?)'
          . ' ON DUPLICATE KEY UPDATE `a`=VALUES(`a`),`b`=VALUES(`b`)',
          $result[1]['query']
        );
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

    #[DataProvider('junctionTableInvalidRowProvider')]
    public function testJunctionTableRejectsRowsWithoutTheTableBColumn(array $values, string $expectedIndex): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
          "Ocallit\Sqler\QueryBuilder::junctionTable values[$expectedIndex] must be an array with a 'b' key"
        );
        $this->queryBuilder->junctionTable('t', 'a', 1, 'b', $values, 'jc');
    }

    public static function junctionTableInvalidRowProvider(): array {
        return [
          'scalar_row' => [[['b' => 1], 'not-an-array'], '1'],
          'null_row' => [[NULL], '0'],
          'row_without_the_key' => [[['x' => 1]], '0'],
            // the index in the message is the array KEY, not a position
          'string_keys_are_preserved_in_the_message' => [['first' => ['x' => 1]], 'first'],
        ];
    }






    // ---- cross method consistency ----

    public function testUpdateEmbedsExactlyWhatWhereProduces(): void {
        $conditions = ['id' => 1, 'deleted_at' => NULL, 'kind' => ['a', 'b']];
        // built here so that where() names this test method, then compared on the clause only
        $where = $this->queryBuilder->where($conditions);
        $update = $this->queryBuilder->update('t', ['x' => 9], $conditions, 'c');

        $clause = substr($where['query'], strpos($where['query'], '('));
        $this->assertStringEndsWith($clause, $update['query']);
        $this->assertSame([9, ...$where['parameters']], $update['parameters']);
    }

    public function testInValuesMatchesTheInClauseWhereProduces(): void {
        $values = ['NOW()', 5, NULL, 'x'];
        [$fragment, $parameters] = $this->queryBuilder->inValues($values);
        $where = $this->queryBuilder->where(['id' => $values], 'AND', 'c');

        $this->assertSame(" /*c*/ (`id` IN $fragment)", $where['query']);
        $this->assertSame($parameters, $where['parameters']);
    }

    /** every builder is independent: no state leaks between calls */
    public function testRepeatedCallsAreIndependent(): void {
        $first = $this->queryBuilder->insert('t', ['a' => 1], TRUE, [], [], 'c');
        $second = $this->queryBuilder->insert('t', ['a' => 2], TRUE, [], [], 'c');

        $this->assertSame($first['query'], $second['query']);
        $this->assertSame([1], $first['parameters']);
        $this->assertSame([2], $second['parameters']);
    }
}
