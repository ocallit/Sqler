<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Ocallit\Sqler\DatabaseMetadata;
use Ocallit\Sqler\Historian;
use Ocallit\Sqler\SqlExecutor;

require_once __DIR__ . '/../../vendor/autoload.php';

/** How changesAsHTML shows numbers, dates, datetimes and times: $fieldFormat keys, the column types (setMetadata) and what the value looks like. */
#[CoversClass(Historian::class)]
class HistorianFormatTest extends TestCase {
    private Historian $historian;
    private string $timezone;

    protected function setUp(): void {
        $this->historian = new Historian($this->createStub(SqlExecutor::class), 'test_table');
        $this->timezone = date_default_timezone_get();
        Historian::$Words = [];
        Historian::$DefaultDecimals = 2;
    }

    protected function tearDown(): void {
        date_default_timezone_set($this->timezone);
        Historian::$Words = [];
        Historian::$DefaultDecimals = 2;
    }

    /** the before and after cells of one field's row */
    private function cells(string $field, mixed $before, mixed $after, array $fieldFormat = [], ?Historian $historian = null): array {
        $change = ['history_id' => 1, 'action' => 'update', 'motive' => '', 'date' => 'd', 'user_nick' => 'u',
          'diff' => [$field => ['before' => $before, 'after' => $after]], 'record' => [$field => $after]];
        $html = ($historian ?? $this->historian)->changesAsHTML([$change], $fieldFormat === [] ? [] : [$field => $fieldFormat]);
        self::assertSame(1, preg_match('/<tr><td>[^<]*<td>([^<]*)<td>([^<]*)/', $html, $m), $html);
        return [$m[1], $m[2]];
    }

    private function withColumns(array $columns): Historian {
        $metadata = $this->createStub(DatabaseMetadata::class);
        $metadata->method('table')->willReturn($columns);
        $historian = new Historian($this->createStub(SqlExecutor::class), 'test_table');
        $historian->setMetadata($metadata);
        return $historian;
    }

    private static function column(string $dataType, ?int $scale = null, string $key = ''): array {
        return ['data_type' => $dataType, 'numeric_scale' => $scale, 'key_type' => $key];
    }

    // ------------------------------------------------------------------ numbers: format keys

    #[DataProvider('numberFormatProvider')]
    public function testNumberFormatKeys(array $format, string $before, string $after, string $expectedBefore, string $expectedAfter): void {
        self::assertSame([$expectedBefore, $expectedAfter], $this->cells('amount', $before, $after, $format));
    }

    public static function numberFormatProvider(): array {
        return [
          'pattern with commas and two decimals' => [['format' => '#,##0.00'], '1234.5', '1234567.891', '1,234.50', '1,234,567.89'],
          'pattern without commas' => [['format' => '0.00'], '1234.5', '1234567.891', '1234.50', '1234567.89'],
          'pattern with no point: no decimals' => [['format' => '#,###'], '1234.5', '1234567.891', '1,235', '1,234,568'],
          'pattern four decimals' => [['format' => '#,##0.0000'], '1.5', '2', '1.5000', '2.0000'],
          'decimals key' => [['decimals' => 3], '1234.5', '2', '1,234.500', '2.000'],
          'decimals 0' => [['decimals' => 0], '1234.5', '2', '1,235', '2'],
          'commas false' => [['commas' => false], '1234.5', '1234567.891', '1234.50', '1234567.89'],
          'separator and decimal point' => [['separator' => '.', 'decimalPoint' => ','], '1234.5', '1234567.891', '1.234,50', '1.234.567,89'],
          'the pattern wins over decimals and commas' => [['format' => '0.0', 'decimals' => 4, 'commas' => true], '1234.56', '2', '1234.6', '2.0'],
          'type number' => [['type' => 'number'], '1234.5', '2', '1,234.50', '2.00'],
          'exact: more than 15 digits' => [['format' => '#,##0.00'], '12345678901234567.89', '0.285', '12,345,678,901,234,567.89', '0.29'],
        ];
    }

    public function testNumberDefaultIsTwoDecimalsWithCommas(): void {
        self::assertSame(['1,234.50', '1,234,567.89'], $this->cells('amount', '1234.5', '1234567.891'));
    }

    public function testDefaultDecimalsIsConfigurable(): void {
        Historian::$DefaultDecimals = 0;
        self::assertSame(['1,235', '2'], $this->cells('amount', '1234.5', '2'));
    }

    public function testNoValueAndNonNumericAreShownAsTheyAre(): void {
        self::assertSame(['', 'abc'], $this->cells('amount', null, 'abc'));
        self::assertSame(['', ''], $this->cells('amount', '', null));
        self::assertSame(['abc', '1,234.00'], $this->cells('amount', 'abc', '1234', ['type' => 'number']));
    }

    // ------------------------------------------------------------------ numbers: no format, no metadata

    #[DataProvider('identifierNameProvider')]
    public function testIdentifierNamesAreLeftAlone(string $field): void {
        self::assertSame(['12345', '1234567'], $this->cells($field, '12345', '1234567'), $field);
    }

    public static function identifierNameProvider(): array {
        return ['id' => ['id'], 'ends in _id' => ['customer_id'], 'camel case id' => ['customerId'], 'numero' => ['numero'],
          'number' => ['invoice_number'], 'camel case number' => ['folioNumber'], 'num' => ['num_factura']];
    }

    public function testNamesThatOnlyContainTheWordsAreNotIdentifiers(): void {
        self::assertSame(['1,000.00', '2,000.00'], $this->cells('enumerado', '1000', '2000'));
        self::assertSame(['1,000.00', '2,000.00'], $this->cells('width', '1000', '2000'));
    }

    public function testCodesAreLeftAlone(): void {
        self::assertSame(['01234', '007'], $this->cells('zip', '01234', '007'));
        self::assertSame(['12345678901234567', '1234567890123456'], $this->cells('card', '12345678901234567', '1234567890123456'));
    }

    public function testExplicitFormatFormatsAnIdentifierToo(): void {
        self::assertSame(['12345.00', '1234567.00'], $this->cells('customer_id', '12345', '1234567', ['decimals' => 2]));
        self::assertSame(['12,345.00', '1,234,567.00'], $this->cells('customer_id', '12345', '1234567', ['decimals' => 2, 'commas' => true]));
    }

    public function testTypeTextLeavesTheValueAlone(): void {
        self::assertSame(['1234.5', '2026-01-05'], $this->cells('amount', '1234.5', '2026-01-05', ['type' => 'text']));
    }

    // ------------------------------------------------------------------ dates, datetimes and times

    public function testDateDatetimeAndTimeDefaults(): void {
        self::assertSame(['05-Jan-26', '06-Feb-26'], $this->cells('born', '2026-01-05', '2026-02-06'));
        self::assertSame(['05-Jan-26 14:30', '06-Feb-26 09:05'], $this->cells('born_at', '2026-01-05 14:30:59', '2026-02-06 09:05:00.123456'));
        self::assertSame(['14:30', '09:05'], $this->cells('at', '14:30:59', '09:05:00'));
    }

    public function testDateFormatKey(): void {
        self::assertSame(['05/01/2026', '06/02/2026'], $this->cells('born', '2026-01-05', '2026-02-06', ['format' => 'd/m/Y']));
        self::assertSame(['2026-01-05 02:30 PM', '2026-01-05 09:05 AM'], $this->cells('at', '2026-01-05 14:30:00', '2026-01-05 09:05:00', ['format' => 'Y-m-d h:i A']));
    }

    public function testWordsTranslateTheFormattedDate(): void {
        Historian::$Words = ['Jan' => 'Ene', 'Apr' => 'Abr'];
        self::assertSame(['05-Ene-26', '06-Feb-26'], $this->cells('born', '2026-01-05', '2026-02-06'));
    }

    #[DataProvider('notADateProvider')]
    public function testValuesThatAreNotValidDatesAreShownAsTheyAre(string $value): void {
        self::assertSame([$value, $value], $this->cells('born', $value, $value, ['format' => 'd-M-y']));
    }

    public static function notADateProvider(): array {
        return ['zero date' => ['0000-00-00'], 'zero datetime' => ['0000-00-00 00:00:00'], 'day that does not exist' => ['2026-02-30'],
          'month 13' => ['2026-13-01'], 'mysql time over 24 hours' => ['838:59:59'], '24 hours' => ['24:00:00'], 'negative mysql time over 24 hours' => ['-838:59:59'], 'negative date' => ['-2026-01-05'],
          'a word' => ['now'], 'minutes' => ['10:61:00'], 'hour of a datetime' => ['2026-01-05 24:00:00']];
    }

    public function testNegativeTimeIsFormattedWithoutTheMinusAndTheMinusPutBack(): void {
        self::assertSame(['-01:30', '-12:05'], $this->cells('elapsed', '-01:30:00', '-12:05:59'));
        self::assertSame(['-01:30:00', '-13:30:00'], $this->cells('elapsed', '-01:30:00', '-13:30:00', ['format' => 'H:i:s']));
        self::assertSame(['-01:30 PM', '-01:30 AM'], $this->cells('elapsed', '-13:30:00', '-01:30:00', ['format' => 'h:i A']));
    }

    public function testDecimalsAreCappedAt15(): void {
        [$before] = $this->cells('amount', '1', '2', ['decimals' => 1000000000]);
        self::assertSame(15, strlen(explode('.', $before)[1]));
        self::assertSame(15, strlen(explode('.', $this->cells('amount', '1', '2', ['format' => '0.' . str_repeat('0', 20)])[0])[1]));
    }

    public function testDaylightSavingDoesNotShiftTheTime(): void {
        date_default_timezone_set('America/New_York'); // 2026-03-08 02:30 does not exist there
        self::assertSame(['08-Mar-26 02:30', '08-Mar-26 03:30'], $this->cells('at', '2026-03-08 02:30:00', '2026-03-08 03:30:00'));
    }

    public function testDateFormatOnANumberLeavesItAlone(): void {
        self::assertSame(['1234.5', '12'], $this->cells('amount', '1234.5', '12', ['format' => 'd-M-y']));
    }

    // ------------------------------------------------------------------ metadata

    public function testDecimalColumnUsesItsScale(): void {
        $historian = $this->withColumns(['rate' => self::column('decimal', 4)]);
        self::assertSame(['1.5000', '1,234.5679'], $this->cells('rate', '1.5', '1234.56789', [], $historian));
    }

    public function testIntegerColumnHasNoDecimals(): void {
        $historian = $this->withColumns(['qty' => self::column('int'), 'user_id' => self::column('bigint', null, 'PRI')]);
        self::assertSame(['5', '12,345'], $this->cells('qty', '5', '12345', [], $historian));
        self::assertSame(['5', '12345'], $this->cells('user_id', '5', '12345', [], $historian));
    }

    public function testFloatColumnUsesTheDefaultDecimals(): void {
        $historian = $this->withColumns(['ratio' => self::column('double')]);
        self::assertSame(['1.50', '0.29'], $this->cells('ratio', '1.5', '0.285', [], $historian));
    }

    public function testTextColumnsAreNeverFormattedEvenWhenTheValueLooksLikeANumberOrADate(): void {
        $historian = $this->withColumns(['zip' => self::column('varchar'), 'code' => self::column('char'), 'note' => self::column('text')]);
        self::assertSame(['01234', '5'], $this->cells('zip', '01234', '5', [], $historian));
        self::assertSame(['1234.5', '2026-01-05'], $this->cells('code', '1234.5', '2026-01-05', [], $historian));
        self::assertSame(['20260105', '10:30:00'], $this->cells('note', '20260105', '10:30:00', [], $historian));
    }

    public function testDateColumnsUseTheColumnType(): void {
        $historian = $this->withColumns(['born' => self::column('date'), 'at' => self::column('datetime'), 'seen' => self::column('timestamp'), 'hour' => self::column('time')]);
        self::assertSame(['05-Jan-26', '06-Feb-26'], $this->cells('born', '2026-01-05', '2026-02-06', [], $historian));
        self::assertSame(['05-Jan-26 14:30', '06-Feb-26 00:00'], $this->cells('at', '2026-01-05 14:30:00', '2026-02-06 00:00:00', [], $historian));
        self::assertSame(['05-Jan-26 14:30', '06-Feb-26 00:00'], $this->cells('seen', '2026-01-05 14:30:00', '2026-02-06 00:00:00', [], $historian));
        self::assertSame(['14:30', '00:05'], $this->cells('hour', '14:30:00', '00:05:00', [], $historian));
    }

    public function testAFieldThatIsNotAColumnFallsBackToTheValue(): void {
        $historian = $this->withColumns(['qty' => self::column('int')]);
        self::assertSame(['1,234.50', '05-Jan-26'], $this->cells('price', '1234.5', '2026-01-05', [], $historian));
        self::assertSame(['12345', '12345'], $this->cells('line_id', '12345', '12345', [], $historian));
    }

    public function testAFormatWinsOverTheColumn(): void {
        $historian = $this->withColumns(['rate' => self::column('decimal', 4), 'born' => self::column('date')]);
        self::assertSame(['1.5', '2.0'], $this->cells('rate', '1.5', '2', ['format' => '0.0'], $historian));
        self::assertSame(['05/01/2026', '06/02/2026'], $this->cells('born', '2026-01-05', '2026-02-06', ['format' => 'd/m/Y'], $historian));
        self::assertSame(['1,234,567.89', '2.00'], $this->cells('rate', '1234567.891', '2', ['decimals' => 2], $historian));
    }

    public function testFailingMetadataFallsBackToTheValue(): void {
        $metadata = $this->createStub(DatabaseMetadata::class);
        $metadata->method('table')->willThrowException(new RuntimeException('no access to information_schema'));
        $historian = new Historian($this->createStub(SqlExecutor::class), 'test_table');
        $historian->setMetadata($metadata);
        self::assertSame(['1,234.50', '12,345.00'], $this->cells('amount', '1234.5', '12345', [], $historian));
    }

    public function testMetadataIsReadOnceAndCanBeRemoved(): void {
        $metadata = $this->createMock(DatabaseMetadata::class);
        $metadata->expects(self::once())->method('table')->with('test_table')->willReturn(['qty' => self::column('int')]);
        $historian = new Historian($this->createStub(SqlExecutor::class), 'test_table');
        $historian->setMetadata($metadata);
        $this->cells('qty', '1', '2', [], $historian);
        $this->cells('qty', '3', '4', [], $historian);

        $historian->setMetadata(null);
        self::assertSame(['1.00', '2.00'], $this->cells('qty', '1', '2', [], $historian));
    }

    // ------------------------------------------------------------------ child lines

    public function testChildLineFieldsAreFormatted(): void {
        $change = ['history_id' => 1, 'action' => 'update', 'motive' => '', 'date' => 'd', 'user_nick' => 'u',
          'diff' => ['items' => ['101' => ['price' => ['before' => '1234.5', 'after' => '2000']]]],
          'record' => ['items' => ['101' => ['price' => '2000', 'sku' => '007']]]];
        $html = $this->historian->changesAsHTML([$change], ['items' => ['child' => ['price' => ['format' => '#,##0.00'], 'sku' => []]]]);

        self::assertStringContainsString('1,234.50', $html);
        self::assertStringContainsString('2,000.00', $html);
        self::assertStringContainsString('>007<', $html);
    }
}
