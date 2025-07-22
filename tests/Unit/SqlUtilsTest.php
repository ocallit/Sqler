<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\SqlUtils;



#[CoversClass(SqlUtils::class)]
class SqlUtilsTest extends TestCase {

    #[DataProvider('toLabelProvider')]
    public function testToLabel(string $input, string $expected): void {
        $result = SqlUtils::toLabel($input);
        $this->assertSame($expected, $result);
    }

    public static function toLabelProvider(): array {
        return [
          'simple_field' => ['user_name', 'User Name'],
          'single_word' => ['email', 'Email'],
          'multiple_underscores' => ['first_name_last_name', 'First Name Last Name'],
          'empty_string' => ['', ''],
          'already_capitalized' => ['USER_NAME', 'User Name'],
          'mixed_case' => ['User_Name', 'User Name'],
          'single_char' => ['a', 'A'],
          'underscore_only' => ['_', ' '],
          'leading_underscore' => ['_name', ' Name'],
          'trailing_underscore' => ['name_', 'Name '],
          'multiple_consecutive_underscores' => ['user__name', 'User  Name'],
        ];
    }


    #[DataProvider('fieldItProvider')]
    public function testFieldIt(string $input, string $expected): void {
        $result = SqlUtils::fieldIt($input);
        $this->assertSame($expected, $result);
    }


    public static function fieldItProvider(): array {
        return [
          'simple_field' => ['username', '`username`'],
          'table_dot_field' => ['users.username', '`users`.`username`'],
          'already_quoted_field' => ['`username`', '`username`'],
          'already_quoted_table_field' => ['`users`.`username`', '`users`.`username`'],
          'mixed_quoted' => ['users.`username`', '`users`.`username`'],
          'mixed_quoted_reverse' => ['`users`.username', '`users`.`username`'],
          'empty_string' => ['', '``'],
          'multiple_dots' => ['db.users.username', '`db`.`users`.`username`'],
          'backticks_in_name' => ['user`name', '`username`'], // backticks are removed
          'space_in_name' => ['user name', '`user name`'],
        ];
    }


    #[DataProvider('strItProvider')]
    public function testStrIt(string|null $input, string $expected): void {
        $result = SqlUtils::strIt($input);
        $this->assertSame($expected, $result);
    }

    public static function strItProvider(): array {
        return [
          'null_value' => [NULL, 'NULL'],
          'empty_string' => ['', "''"],
          'simple_string' => ['hello', "'hello'"],
          'string_with_single_quote' => ["don't", "'don''t'"],
          'string_with_backslash' => ['back\\slash', "'back\\\\slash'"],
          'string_with_special_chars' => ["test\x00\x08\x1a\x1b", "'test'"],
          'multiple_quotes' => ["it's a 'test'", "'it''s a ''test'''"],
          'numeric_string' => ['123', "'123'"],
          'unicode_string' => ['héllo', "'héllo'"],
        ];
    }


    #[DataProvider('createQueryTemplateProvider')]
    public function testCreateQueryTemplate(string $input, string $expected): void {
        $result = SqlUtils::createQueryTemplate($input);
        $this->assertSame($expected, $result);
    }

    public static function createQueryTemplateProvider(): array {
        return [
          'simple_select' => [
            "SELECT * FROM users WHERE id = 123",
            "SELECT * FROM users WHERE id = ?",
          ],
          'string_literals' => [
            "SELECT * FROM users WHERE name = 'John Doe'",
            "SELECT * FROM users WHERE name = ?",
          ],
          'mixed_literals' => [
            "SELECT * FROM users WHERE name = 'John' AND age > 25",
            "SELECT * FROM users WHERE name = ? AND age > ?",
          ],
          'decimal_numbers' => [
            "SELECT * FROM products WHERE price = 19.989",
            "SELECT * FROM products WHERE price = ?",
          ],
          'escaped_quotes' => [
            "SELECT * FROM users WHERE name = 'John\\'s'",
            "SELECT * FROM users WHERE name = ?",
          ],
          'double_quotes' => [
            'SELECT * FROM users WHERE name = "John Doe"',
            "SELECT * FROM users WHERE name = ?",
          ],
          'multiple_whitespace' => [
            "SELECT  *   FROM    users   WHERE id =   123",
            "SELECT * FROM users WHERE id = ?",
          ],
          'in_clause' => [
            "SELECT  *   FROM    users   WHERE gat IN (1, 2, 'baba', NULL, NOW())",
            "SELECT * FROM users WHERE gat IN (?, ?, ?, NULL, NOW())",
          ],
          'no_literals' => [
            "SELECT * FROM users",
            "SELECT * FROM users",
          ],
          'complex_query' => [
            "INSERT INTO users (name, age, salary) VALUES ('John', 30, 50000.50)",
            "INSERT INTO users (name, age, salary) VALUES (?, ?, ?)",
          ],
        ];
    }
}
