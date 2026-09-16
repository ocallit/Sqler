<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\SqlUtils;

require_once __DIR__ . '/../../vendor/autoload.php';

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
            // only '_' is a word separator: '-' and '.' are not, so what follows stays lowercase
          'hyphen_is_not_a_separator' => ['user-name', 'User-name'],
          'dot_is_not_a_separator' => ['users.user_name', 'Users.user Name'],
            // acronyms are lowercased first, so they come back as plain words
          'acronym_is_not_preserved' => ['ID', 'Id'],
          'acronym_inside_name' => ['user_ID', 'User Id'],
            // digits are not letters: ucwords does not touch them
          'digit_in_name' => ['user2_name', 'User2 Name'],
          'starts_with_digit' => ['2nd_line', '2nd Line'],
            // strtolower/ucwords are byte oriented: non ascii bytes pass through untouched
          'non_ascii_bytes_pass_through' => ['ÁREA_NOMBRE', 'Área Nombre'],
          'spaces_already_present' => ['user name_last', 'User Name Last'],
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
          'four_parts' => ['a.b.c.d', '`a`.`b`.`c`.`d`'],
            // a lone dot yields two empty parts, each quoted
          'dot_only' => ['.', '``.``'],
          'trailing_dot' => ['a.', '`a`.``'],
          'leading_dot' => ['.a', '``.`a`'],
            // '``' already matches the already quoted pattern, so it is left as is
          'empty_quoted_is_left_alone' => ['``', '``'],
          'quoted_single_space_is_left_alone' => ['` `', '` `'],
            // an unbalanced backtick does not match the pattern: it is stripped and requoted
          'leading_backtick_only' => ['`a', '`a`'],
          'trailing_backtick_only' => ['a`', '`a`'],
          'inner_backtick_in_quoted' => ['`a`b`', '`ab`'],
            // the split on '.' happens before the quoted check, so a quoted dotted name is split
          'quoted_name_containing_a_dot_is_split' => ['`a.b`', '`a`.`b`'],
          'injection_attempt_is_neutralized' => ['a` OR 1=1 -- ', '`a OR 1=1 -- `'],
          'newline_in_name' => ["a\nb", "`a\nb`"],
          'non_ascii_name' => ['año', '`año`'],
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
            // '0' is falsy: it has its own branch so it does not come back as ''
          'zero_string_has_its_own_branch' => ['0', "'0'"],
          'zero_point_zero_is_not_the_zero_branch' => ['0.0', "'0.0'"],
          'double_zero_is_not_the_zero_branch' => ['00', "'00'"],
            // each control char is dropped independently
          'backspace_only' => ["a\x08b", "'ab'"],
          'nul_only' => ["a\x00b", "'ab'"],
          'sub_only' => ["a\x1ab", "'ab'"],
          'escape_only' => ["a\x1bb", "'ab'"],
            // newline and tab are NOT stripped: they are legal inside a mysql string literal
          'newline_is_kept' => ["a\nb", "'a\nb'"],
          'tab_is_kept' => ["a\tb", "'a\tb'"],
            // quotes are doubled first, then backslashes are doubled
          'backslash_then_quote' => ["a\\'b", "'a\\\\''b'"],
          'only_a_quote' => ["'", "''''"],
          'only_a_backslash' => ['\\', "'\\\\'"],
          'trailing_backslash_cannot_escape_the_closing_quote' => ['ab\\', "'ab\\\\'"],
          'injection_attempt_is_neutralized' => ["' OR '1'='1", "''' OR ''1''=''1'"],
          'emoji' => ['🙂', "'🙂'"],
          'double_quote_is_untouched' => ['say "hi"', "'say \"hi\"'"],
        ];
    }


    #[DataProvider('sTrimProvider')]
    public function testSTrim(string|int|float|bool|null|Stringable $input, string $expected): void {
        $result = SqlUtils::sTrim($input);
        $this->assertSame($expected, $result);
    }

    public static function sTrimProvider(): array {
        return [
          'null_becomes_empty_string' => [NULL, ''],
          'empty_string' => ['', ''],
          'only_spaces' => ['   ', ''],
          'single_space' => [' ', ''],
          'trimmed_already' => ['abc', 'abc'],
          'outer_spaces_trimmed' => ['  abc  ', 'abc'],
          'runs_collapse_to_one_space' => ['a   b', 'a b'],
          'two_spaces_collapse' => ['a  b', 'a b'],
            // the pattern is \s\s+ : a SINGLE whitespace char is left exactly as it is
          'single_newline_is_kept_verbatim' => ["a\nb", "a\nb"],
          'single_tab_is_kept_verbatim' => ["a\tb", "a\tb"],
          'mixed_run_collapses_to_a_space' => ["a \t b", 'a b'],
          'double_newline_collapses_to_a_space' => ["a\n\nb", 'a b'],
          'outer_newlines_trimmed' => ["\n a \n", 'a'],
          'crlf_collapses' => ["a\r\nb", 'a b'],
            // \s is ascii only: a non breaking space is not whitespace here
          'non_breaking_spaces_are_not_collapsed' => ["a\u{00A0}\u{00A0}b", "a\u{00A0}\u{00A0}b"],
          'int_is_cast' => [42, '42'],
          'negative_int_is_cast' => [-7, '-7'],
          'zero_int_is_cast' => [0, '0'],
          'float_without_fraction_loses_it' => [1.0, '1'],
          'float_with_fraction' => [1.5, '1.5'],
          'true_casts_to_one' => [TRUE, '1'],
          'false_casts_to_empty' => [FALSE, ''],
          'stringable_is_cast_then_squeezed' => [new SplFileInfo('/tmp/a  b.txt'), '/tmp/a b.txt'],
        ];
    }

    public function testSTrimUsesStringableObjects(): void {
        $stringable = new class implements Stringable {
            public function __toString(): string { return "  keep   me  "; }
        };
        $this->assertSame('keep me', SqlUtils::sTrim($stringable));
    }


    #[DataProvider('commentItProvider')]
    public function testCommentIt(string $input, string $expected): void {
        $result = SqlUtils::commentIt($input);
        $this->assertSame($expected, $result);
    }

    public static function commentItProvider(): array {
        return [
          'empty_string_returns_empty' => ['', ''],
            // empty('0') is true, so the literal string '0' takes the empty branch
          'zero_string_is_treated_as_empty' => ['0', ''],
          'plain_text_is_returned_unchanged' => ['hello', 'hello'],
          'space_is_not_empty' => [' ', ' '],
          'zero_zero_is_not_empty' => ['00', '00'],
            // only the closing marker is neutralized, and only its last char is dropped
          'closing_marker_is_broken' => ['a*/b', 'a*b'],
          'every_closing_marker_is_broken' => ['*/*/', '**'],
          'comment_wrapper_loses_only_the_close' => ['/*x*/', '/*x*'],
            // the opening marker is NOT removed
          'opening_marker_is_kept' => ['/*', '/*'],
          'nested_open_is_kept' => ['a/*b', 'a/*b'],
          'injection_attempt_cannot_close_the_comment' => ["*/ DROP TABLE t; /*", "* DROP TABLE t; /*"],
          'newline_is_kept' => ["a\nb", "a\nb"],
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
          'empty_query' => ['', ''],
          'only_whitespace' => ["  \n\t  ", ''],
          'tabs_and_newlines_become_single_spaces' => [
            "  \n SELECT\t*\nFROM users  ",
            "SELECT * FROM users",
          ],
            // string literals are blanked BEFORE numbers, so digits inside them never leak
          'digits_inside_a_string_are_not_seen' => [
            "SELECT * FROM t WHERE a = 'has 123 in string'",
            "SELECT * FROM t WHERE a = ?",
          ],
          'digits_inside_a_double_quoted_string' => [
            'SELECT * FROM t WHERE a = "dq 99"',
            "SELECT * FROM t WHERE a = ?",
          ],
          'datetime_literal_is_one_placeholder' => [
            "SELECT * FROM t WHERE d = '2024-01-31 10:20:30'",
            "SELECT * FROM t WHERE d = ?",
          ],
          'empty_string_literal' => [
            "SELECT * FROM t WHERE a = ''",
            "SELECT * FROM t WHERE a = ?",
          ],
          'backslash_only_literal' => [
            "SELECT * FROM t WHERE a = '\\\\'",
            "SELECT * FROM t WHERE a = ?",
          ],
            // a doubled '' is read as two adjacent literals, not as one escaped quote
          'sql_standard_doubled_quote_becomes_two_placeholders' => [
            "SELECT * FROM t WHERE a = 'it''s'",
            "SELECT * FROM t WHERE a = ??",
          ],
            // an unterminated literal matches nothing and is left untouched
          'unterminated_literal_is_left_alone' => [
            "SELECT * FROM t WHERE a='unterminated",
            "SELECT * FROM t WHERE a='unterminated",
          ],
            // \b keeps digits that are glued to identifier characters
          'digit_suffixed_identifier_is_kept' => [
            "SELECT * FROM t WHERE column1 = 5",
            "SELECT * FROM t WHERE column1 = ?",
          ],
          'underscore_digit_identifier_is_kept' => [
            "SELECT * FROM t WHERE col_1 = 5",
            "SELECT * FROM t WHERE col_1 = ?",
          ],
          'digits_glued_inside_backticked_identifiers_are_kept' => [
            "SELECT `t2`.`c3` FROM `t2` WHERE id=7",
            "SELECT `t2`.`c3` FROM `t2` WHERE id=?",
          ],
          'scientific_notation_is_not_replaced' => [
            "SELECT * FROM t WHERE a = 1e5",
            "SELECT * FROM t WHERE a = 1e5",
          ],
          'hex_literal_is_not_replaced' => [
            "SELECT * FROM t WHERE a = 0x1F",
            "SELECT * FROM t WHERE a = 0x1F",
          ],
            // the sign is not part of the number pattern
          'negative_number_keeps_its_sign' => [
            "SELECT * FROM t WHERE a = -5",
            "SELECT * FROM t WHERE a = -?",
          ],
          'leading_dot_float_keeps_its_dot' => [
            "SELECT * FROM t WHERE a = .5",
            "SELECT * FROM t WHERE a = .?",
          ],
          'three_part_number_takes_the_decimal_pattern_first' => [
            "SELECT * FROM t WHERE a = 1.2.3",
            "SELECT * FROM t WHERE a = ?.?",
          ],
          'limit_and_offset' => [
            "SELECT * FROM t LIMIT 10 OFFSET 20",
            "SELECT * FROM t LIMIT ? OFFSET ?",
          ],
          'existing_placeholders_survive' => [
            "SELECT * FROM t WHERE a=? AND b=?",
            "SELECT * FROM t WHERE a=? AND b=?",
          ],
          'function_calls_are_not_literals' => [
            "INSERT INTO t(a) VALUES(NOW())",
            "INSERT INTO t(a) VALUES(NOW())",
          ],
          'star_in_count_is_kept' => [
            "SELECT COUNT(*) FROM t",
            "SELECT COUNT(*) FROM t",
          ],
          'sql_comments_are_kept' => [
            "SELECT * FROM t /* c1 */ WHERE a = 5",
            "SELECT * FROM t /* c1 */ WHERE a = ?",
          ],
        ];
    }

    /**
     * Two different literals of the same shape must template to the same string:
     * that is the whole point of the method (grouping queries in a log).
     */
    #[DataProvider('sameTemplateProvider')]
    public function testQueriesDifferingOnlyInLiteralsShareATemplate(string $a, string $b): void {
        $this->assertSame(SqlUtils::createQueryTemplate($a), SqlUtils::createQueryTemplate($b));
    }

    public static function sameTemplateProvider(): array {
        return [
          'different_ints' => [
            "SELECT * FROM t WHERE id = 1",
            "SELECT * FROM t WHERE id = 987654",
          ],
          'different_strings' => [
            "SELECT * FROM t WHERE n = 'a'",
            "SELECT * FROM t WHERE n = 'a much longer value'",
          ],
          'different_spacing' => [
            "SELECT * FROM t WHERE id = 1",
            "SELECT   *\nFROM t\n WHERE id =    2",
          ],
          'different_decimals' => [
            "SELECT * FROM t WHERE p = 1.00",
            "SELECT * FROM t WHERE p = 12345.6789",
          ],
        ];
    }

    public function testCreateQueryTemplateIsIdempotent(): void {
        $once = SqlUtils::createQueryTemplate("SELECT * FROM t WHERE a = 'x' AND b = 12 AND c = 1.5");
        $this->assertSame($once, SqlUtils::createQueryTemplate($once));
    }


    public function testJsonMysqlOptionsIsTheDocumentedFlagCombination(): void {
        $this->assertSame(
          JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING,
          SqlUtils::JSON_MYSQL_OPTIONS
        );
    }

    #[DataProvider('jsonMysqlFlagProvider')]
    public function testJsonMysqlOptionsCarriesFlag(int $flag): void {
        $this->assertSame($flag, SqlUtils::JSON_MYSQL_OPTIONS & $flag);
    }

    public static function jsonMysqlFlagProvider(): array {
        return [
          'unescaped_unicode' => [JSON_UNESCAPED_UNICODE],
          'invalid_utf8_ignore' => [JSON_INVALID_UTF8_IGNORE],
          'invalid_utf8_substitute' => [JSON_INVALID_UTF8_SUBSTITUTE],
          'bigint_as_string' => [JSON_BIGINT_AS_STRING],
        ];
    }

    public function testJsonMysqlOptionsKeepsUnicodeReadableWhenEncoding(): void {
        $this->assertSame('{"a":"h\u00e9llo"}', json_encode(['a' => 'héllo']));
        $this->assertSame('{"a":"héllo"}', json_encode(['a' => 'héllo'], SqlUtils::JSON_MYSQL_OPTIONS));
    }

    public function testJsonMysqlOptionsEncodesInvalidUtf8InsteadOfFailing(): void {
        $this->assertFalse(json_encode(['a' => "bad\xB1\x31"]));
        $this->assertSame('{"a":"bad1"}', json_encode(['a' => "bad\xB1\x31"], SqlUtils::JSON_MYSQL_OPTIONS));
    }

    public function testJsonMysqlOptionsDecodesBigIntegersAsStrings(): void {
        $json = '{"a":12345678901234567890}';
        $this->assertSame(
          ['a' => '12345678901234567890'],
          json_decode($json, TRUE, 512, SqlUtils::JSON_MYSQL_OPTIONS)
        );
    }
}
