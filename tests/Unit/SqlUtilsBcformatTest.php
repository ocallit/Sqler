<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\SqlUtils;

require_once __DIR__ . '/../../vendor/autoload.php';

/** SqlUtils::bcformat: rounds half up and puts thousands separators on a numeric string, no floats; what is not numeric is returned as it came */
#[CoversClass(SqlUtils::class)]
class SqlUtilsBcformatTest extends TestCase {

    #[DataProvider('formatProvider')]
    public function testBcformat(mixed $num, int|string $decimals, string $expected): void {
        self::assertSame($expected, SqlUtils::bcformat($num, $decimals));
    }

    public static function formatProvider(): array {
        return [
          'groups thousands' => ['1234567.891', 2, '1,234,567.89'],
          'negative' => ['-1234567.891', 1, '-1,234,567.9'],
          'integer padded' => [12, 2, '12.00'],
          'padded to 3' => [3, 3, '3.000'],
          'zero' => ['0', 2, '0.00'],
          'no decimals has no point' => ['1.5', 0, '2'],
          'leading point' => ['.5', 0, '1'],
          'trailing point' => ['5.', 2, '5.00'],
          'explicit plus' => ['+5', 2, '5.00'],
          'whitespace' => ['  42 ', 2, '42.00'],
          'ties round up' => ['0.285', 2, '0.29'],
          'tie 1.005' => ['1.005', 2, '1.01'],
          'carry through the integer part' => ['999.995', 2, '1,000.00'],
          'negative carry' => ['-999999.999', 2, '-1,000,000.00'],
          'negative that rounds to zero has no sign' => ['-0.001', 2, '0.00'],
          'exact beyond float precision' => ['12345678901234567.89', 2, '12,345,678,901,234,567.89'],
          'no change when it has the decimals' => ['1.17', 2, '1.17'],
          'less decimals rounds' => ['1.17', 1, '1.2'],
          'negative decimals is zero' => ['1234.567', -3, '1,235'],
          'decimals as text' => ['1234.567', '1', '1,234.6'],
          'float 4.999' => [4.999, 2, '5.00'],
          'null' => [null, 2, ''],
          'empty' => ['', 2, ''],
          'not numeric is returned' => ['abc', 2, 'abc'],
          'not numeric with a comma' => ['1,234.50', 2, '1,234.50'],
          'incomplete exponent is not numeric' => ['1e', 2, '1e'],
          'false' => [false, 2, ''],
          // scientific notation: below ten million it is expanded and rounded, otherwise it is not touched but for the commas of the mantissa
          'e expanded' => ['1234.5e3', 1, '1,234,500.0'],
          'e small negative' => ['-0.001E5', 2, '-100.00'],
          'e tiny at 5 decimals' => ['1.0E-5', 5, '0.00001'],
          'e tiny at 2 decimals' => ['1.0E-5', 2, '0.00'],
          'e negative tie' => ['-5E-3', 2, '-0.01'],
          'e just below ten million' => ['9999999E0', 2, '9,999,999.00'],
          'e below ten million rounds up' => ['9.9999999E6', 2, '9,999,999.90'],
          'e point first' => ['.5E3', 2, '500.00'],
          'e zero' => ['0E5', 2, '0.00'],
          'e ten million is not expanded' => ['10000000E0', 2, '10,000,000E0'],
          'e 1E7 is not expanded' => ['1E7', 2, '1E7'],
          'e big negative' => ['-3.141592E9', 4, '-3.141592E9'],
          'e big mantissa gets commas' => ['1234.5678E9', 2, '1,234.5678E9'],
          'e float' => [1.0E+25, 2, '1.0E+25'],
          'e huge negative exponent' => ['1E-999999999', 2, '0.00'],
          'e huge exponent' => ['1E999999999', 2, '1E999999999'],
        ];
    }

    public function testSeparatorAndDecimalPoint(): void {
        self::assertSame('1.234.567,50', SqlUtils::bcformat('1234567.5', 2, '.', ','));
        self::assertSame('1 234٫50', SqlUtils::bcformat('1234.5', 2, ' ', '٫'));
        self::assertSame('1$234', SqlUtils::bcformat('1234', 0, '$'));
        self::assertSame('1234.50', SqlUtils::bcformat('1234.5', 2, ''));
        self::assertSame('1\\234', SqlUtils::bcformat('1234', 0, '\\'));
    }
}
