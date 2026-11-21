<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\Historian;
use Ocallit\Sqler\SqlExecutor;

require_once __DIR__ . '/../../vendor/autoload.php';

/** Parent/child records (invoice and invoice items): $onlyFields paths and changesAsHTML($fieldFormat). */
#[CoversClass(Historian::class)]
class HistorianChildRecordsTest extends TestCase {
    private Historian $historian;
    private SqlExecutor $mockSqlExecutor;

    private const PK = ['test_table_id' => 1];

    protected function setUp(): void {
        $this->mockSqlExecutor = $this->createStub(SqlExecutor::class);
        $this->historian = new Historian($this->mockSqlExecutor, 'test_table');
    }

    private static function row(int $id, array $record): array {
        return [
          'history_id' => $id,
          'action' => 'update',
          'motive' => '',
          'date' => sprintf('2026-09-07 10:%02d:00.000000', $id),
          'user_nick' => 'tester',
          'record' => json_encode($record),
        ];
    }

    /** Records in date order (oldest first) as getChangedRecords reads them. */
    private function givenRecords(array $records): void {
        $rows = [];
        foreach($records as $id => $record)
            $rows[$id] = self::row($id, $record);
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn($rows);
    }

    /** Records in date order, read as getChanges does (newest first). */
    private function givenChanges(array $records): array {
        $rows = [];
        foreach(array_reverse($records, true) as $id => $record)
            $rows[$id] = self::row($id, $record);
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn($rows);
        return $this->historian->getChanges(self::PK);
    }

    private static function line(string $quantity, string $product, string $price, ?string $discount = null, string $lastChange = 'x'): array {
        return ['quantity' => $quantity, 'product' => $product, 'price' => $price, 'discount' => $discount, 'last_change' => $lastChange];
    }

    /** An invoice history used by many tests: line 101 changes price, 102 is removed, 103 appears, total changes. */
    private static function invoiceHistory(): array {
        return [
          1 => ['total' => '10', 'last_change' => 'a', 'items' => [
            101 => self::line('12', 'circles', '3.14'),
            102 => self::line('2', 'triangles', '1.00'),
          ]],
          2 => ['total' => '10', 'last_change' => 'b', 'items' => [ // only ignored fields changed
            101 => self::line('12', 'circles', '3.14', null, 'y'),
            102 => self::line('2', 'triangles', '1.00', null, 'y'),
          ]],
          3 => ['total' => '20', 'last_change' => 'c', 'items' => [ // header changed, lines same
            101 => self::line('12', 'circles', '3.14'),
            102 => self::line('2', 'triangles', '1.00'),
          ]],
          4 => ['total' => '20', 'last_change' => 'd', 'items' => [ // line 101 price modified
            101 => self::line('12', 'circles', '3.50'),
            102 => self::line('2', 'triangles', '1.00'),
          ]],
          5 => ['total' => '20', 'last_change' => 'e', 'items' => [ // line 102 removed
            101 => self::line('12', 'circles', '3.50'),
          ]],
          6 => ['total' => '20', 'last_change' => 'f', 'items' => [ // line 103 added
            101 => self::line('12', 'circles', '3.50'),
            103 => self::line('1', 'squares', '9.00'),
          ]],
          7 => ['total' => '20', 'last_change' => 'g', 'items' => [ // 101 modified, 103 modified product, 104 added
            101 => self::line('12', 'circles', '4.00'),
            103 => self::line('1', 'cubes', '9.00'),
            104 => self::line('5', 'dots', '0.10'),
          ]],
        ];
    }

    // ------------------------------------------------------------------ $onlyFields paths: getChangedRecords

    public function testWithoutOnlyFieldsAnyLineChangeIsAChangeOfTheCollection(): void {
        $this->givenRecords(self::invoiceHistory());

        $changed = $this->historian->getChangedRecords(self::PK);
        $fields = $this->historian->getFieldChanges($changed);

        self::assertSame([1, 3, 4, 5, 6, 7], array_keys($changed), 'row 2 differs only in ignored fields');
        self::assertSame(['added' => [1], 'modified' => [4, 5, 6, 7], 'deleted' => []], $fields['items']);
        self::assertSame(['added' => [1], 'modified' => [3], 'deleted' => []], $fields['total']);
    }

    public function testOnlyFieldsPathKeepsRowsWhereThatFieldOfSomeLineChanged(): void {
        $this->givenRecords(self::invoiceHistory());

        $changed = $this->historian->getChangedRecords(self::PK, ['items.price']);

        // 1 first row, 4 price modified, 5 line removed, 6 line added, 7 price modified
        self::assertSame([1, 4, 5, 6, 7], array_keys($changed));
    }

    public function testOnlyFieldsPathDoesNotCountOtherFieldsOfTheLines(): void {
        $this->givenRecords([
          1 => ['items' => [101 => self::line('1', 'a', '1.00')]],
          2 => ['items' => [101 => self::line('2', 'b', '1.00')]], // quantity and product, not price
          3 => ['items' => [101 => self::line('2', 'b', '2.00')]],
        ]);

        self::assertSame([1, 3], array_keys($this->historian->getChangedRecords(self::PK, ['items.price'])));
        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['items.quantity'])));
        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['items.product'])));
    }

    public function testOnlyFieldsPathDoesNotCountTheHeader(): void {
        $this->givenRecords(self::invoiceHistory());

        self::assertNotContains(3, array_keys($this->historian->getChangedRecords(self::PK, ['items.price'])));
    }

    public function testOnlyFieldsPathsReturnFullRecords(): void {
        $this->givenRecords(self::invoiceHistory());

        $changed = $this->historian->getChangedRecords(self::PK, ['items.price']);

        self::assertSame('20', $changed[4]['record']['total']);
        self::assertSame('3.50', $changed[4]['record']['items'][101]['price']);
        self::assertSame('triangles', $changed[4]['record']['items'][102]['product']);
    }

    public function testSeveralPathsAndNamesCanBeMixed(): void {
        $this->givenRecords(self::invoiceHistory());

        // total: 1, 3; items.price: 1, 4, 5, 6, 7
        self::assertSame([1, 3, 4, 5, 6, 7], array_keys($this->historian->getChangedRecords(self::PK, ['total', 'items.price'])));
        // items.product: 1 and 6 (103 added), 7 (103 product modified, 104 added)
        self::assertSame([1, 5, 6, 7], array_keys($this->historian->getChangedRecords(self::PK, ['items.product'])));
    }

    public function testPathWithTheLineKeyReachesOnlyThatLine(): void {
        $this->givenRecords(self::invoiceHistory());

        // line 101 price: first row, 4 (3.14 -> 3.50), 7 (3.50 -> 4.00)
        self::assertSame([1, 4, 7], array_keys($this->historian->getChangedRecords(self::PK, ['items.101.price'])));
        // line 102: removed in 5
        self::assertSame([1, 5], array_keys($this->historian->getChangedRecords(self::PK, ['items.102.price'])));
    }

    public function testWholeFieldNameStillWatchesAllOfIt(): void {
        $this->givenRecords(self::invoiceHistory());

        self::assertSame([1, 4, 5, 6, 7], array_keys($this->historian->getChangedRecords(self::PK, ['items'])));
    }

    public function testPathToAPlainNestedObject(): void {
        $this->givenRecords([
          1 => ['meta' => ['theme' => 'dark', 'size' => '1']],
          2 => ['meta' => ['theme' => 'dark', 'size' => '2']],
          3 => ['meta' => ['theme' => 'light', 'size' => '2']],
        ]);

        self::assertSame([1, 3], array_keys($this->historian->getChangedRecords(self::PK, ['meta.theme'])));
    }

    public function testPlainNameNeverReachesANestedFieldWithTheSameName(): void {
        $this->givenRecords([
          1 => ['price' => '1', 'items' => [101 => ['price' => '1']]],
          2 => ['price' => '1', 'items' => [101 => ['price' => '2']]], // only the line's price
          3 => ['price' => '2', 'items' => [101 => ['price' => '2']]], // only the header's price
        ]);

        self::assertSame([1, 3], array_keys($this->historian->getChangedRecords(self::PK, ['price'])));
        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['items.price'])));
    }

    public function testPathsSkipTheLineLevelOnlyOnce(): void {
        $this->givenRecords([
          1 => ['items' => [101 => ['sub' => ['price' => '1'], 'price' => '1']]],
          2 => ['items' => [101 => ['sub' => ['price' => '2'], 'price' => '1']]], // sub.price is deeper than a line
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['items.price'])));
    }

    public function testPathToAMissingOrScalarFieldOnlyKeepsTheFirstRow(): void {
        $this->givenRecords([
          1 => ['total' => '1', 'items' => [101 => self::line('1', 'a', '1')]],
          2 => ['total' => '2', 'items' => [101 => self::line('2', 'b', '2')]],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['items.nonexistent'])));
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['nonexistent.price'])));
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['total.price'])), 'total is not a collection');
    }

    public function testPathWorksWhenTheCollectionAppearsOrDisappears(): void {
        $this->givenRecords([
          1 => ['total' => '1'],
          2 => ['total' => '1', 'items' => [101 => self::line('1', 'a', '1.00')]],
          3 => ['total' => '1', 'items' => null],
        ]);

        self::assertSame([1, 2, 3], array_keys($this->historian->getChangedRecords(self::PK, ['items.price'])));
    }

    public function testListedLinesAreComparedByKeyNotByPosition(): void {
        // line 101 is removed: with the pk as key only that line changes, the others are not shifted
        $this->givenRecords([
          1 => ['items' => [101 => self::line('1', 'a', '1'), 102 => self::line('2', 'b', '2'), 103 => self::line('3', 'c', '3')]],
          2 => ['items' => [102 => self::line('2', 'b', '2'), 103 => self::line('3', 'c', '3')]],
        ]);
        $changed = $this->historian->getChangedRecords(self::PK, ['items.101.price', 'items.102.price', 'items.103.price']);
        $fields = $this->historian->getFieldChanges($changed, ['items.101.price', 'items.102.price', 'items.103.price']);

        self::assertSame(['added' => [1], 'modified' => [], 'deleted' => [2]], $fields['items.101.price']);
        self::assertSame(['added' => [1], 'modified' => [], 'deleted' => []], $fields['items.102.price']);
        self::assertSame(['added' => [1], 'modified' => [], 'deleted' => []], $fields['items.103.price']);
    }

    public function testLinesSwappingPositionWithTheSameKeysIsNotAChange(): void {
        $first = self::line('1', 'circles', '3.14');
        $second = self::line('2', 'triangles', '1.00');
        $this->givenRecords([
          1 => ['items' => [101 => $first, 102 => $second]],
          2 => ['items' => [102 => $second, 101 => $first]], // same lines, same pk, swapped order
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['items'])));
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['items.price'])));
        self::assertSame(
          ['items' => ['added' => [1], 'modified' => [], 'deleted' => []]],
          array_intersect_key($this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK)), ['items' => 1])
        );
    }

    public function testLinesSwappingPositionAfterJsonRoundTripIsNotAChange(): void {
        // registered as json, the swapped collection is an object with its keys in another order
        $json1 = '{"items":{"101":{"quantity":"1"},"102":{"quantity":"2"}}}';
        $json2 = '{"items":{"102":{"quantity":"2"},"101":{"quantity":"1"}}}';
        $rows = [1 => self::row(1, []), 2 => self::row(2, [])];
        $rows[1]['record'] = $json1;
        $rows[2]['record'] = $json2;
        $historian = new Historian($stub = $this->createStub(SqlExecutor::class), 'test_table');
        $stub->method('arrayKeyed')->willReturn($rows);

        self::assertSame([1], array_keys($historian->getChangedRecords(self::PK)));
    }

    public function testSwappingTheSortFieldOfTheLinesIsAChangeOfThatField(): void {
        $this->givenRecords([
          1 => ['items' => [101 => ['product' => 'a', 'line_no' => '1'], 102 => ['product' => 'b', 'line_no' => '2']]],
          2 => ['items' => [101 => ['product' => 'a', 'line_no' => '2'], 102 => ['product' => 'b', 'line_no' => '1']]],
        ]);
        $only = ['items.line_no'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $only), $only);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, $only)));
        self::assertSame(['added' => [1], 'modified' => [2], 'deleted' => []], $fields['items.line_no']);
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['items.product'])), 'products did not change');
    }

    public function testLinesSwappingPositionInAPositionalListAreBothModified(): void {
        // why lines must be keyed by their pk: without keys a swap changes both lines
        $first = self::line('1', 'circles', '3.14');
        $second = self::line('2', 'triangles', '1.00');
        $this->givenRecords([
          1 => ['items' => [$first, $second]],
          2 => ['items' => [$second, $first]],
        ]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testSwappedLinesPrintNoHtmlRow(): void {
        $first = self::line('1', 'circles', '3.14');
        $second = self::line('2', 'triangles', '1.00');
        $changes = $this->givenChanges([
          1 => ['total' => '10', 'items' => [101 => $first, 102 => $second]],
          2 => ['total' => '20', 'items' => [102 => $second, 101 => $first]],
        ]);

        $html = $this->historian->changesAsHTML($changes, self::ITEMS_FORMAT);

        self::assertStringContainsString('<td>Total<td>10<td>20', $html);
        self::assertStringNotContainsString('<tr class="lines">', $html);
        self::assertStringNotContainsString('circles', $html);
    }

    // ------------------------------------------------------------------ $onlyFields paths and ignored fields

    public function testPathUnignoresTheFieldAtThatPathOnly(): void {
        $this->givenRecords([
          1 => ['last_change' => 'a', 'items' => [101 => self::line('1', 'a', '1', null, 'a')]],
          2 => ['last_change' => 'b', 'items' => [101 => self::line('1', 'a', '1', null, 'b')]],
          3 => ['last_change' => 'c', 'items' => [101 => self::line('1', 'a', '1', null, 'b')]],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)), 'ignored everywhere');
        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['items.last_change'])), 'line last_change counts, header does not');
        self::assertSame([1, 2, 3], array_keys($this->historian->getChangedRecords(self::PK, ['last_change'])), 'header counts');
    }

    public function testOtherIgnoredFieldsOfTheLinesStayIgnored(): void {
        $this->historian->setIgnoreDifferenceForFields(['last_change', 'version']);
        $this->givenRecords([
          1 => ['items' => [101 => ['price' => '1', 'last_change' => 'a', 'version' => '1']]],
          2 => ['items' => [101 => ['price' => '1', 'last_change' => 'a', 'version' => '2']]], // only version
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['items.last_change'])), 'version is still ignored');
        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['items.version'])));
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['items.price'])));
    }

    public function testWholeCollectionStillIgnoresIgnoredFieldsInItsLines(): void {
        $this->givenRecords([
          1 => ['items' => [101 => self::line('1', 'a', '1', null, 'a')]],
          2 => ['items' => [101 => self::line('1', 'a', '1', null, 'b')]],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['items'])));
    }

    // ------------------------------------------------------------------ $onlyFields paths: getFieldChanges

    public function testFieldChangesOfAPathAreKeyedByThePathWithAddedModifiedDeleted(): void {
        $this->givenRecords(self::invoiceHistory());
        $only = ['items.price'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $only), $only);

        self::assertSame(['items.price'], array_keys($fields));
        // 1: first row (added), 4 and 7: modified, 5: line removed, 6: line added; 7 also adds line 104
        self::assertSame(['added' => [1, 6, 7], 'modified' => [4, 7], 'deleted' => [5]], $fields['items.price']);
    }

    public function testARowWithSeveralKindsIsListedUnderEach(): void {
        $this->givenRecords([
          1 => ['items' => [101 => self::line('1', 'a', '1.00')]],
          2 => ['items' => [101 => self::line('1', 'a', '2.00'), 102 => self::line('1', 'b', '5.00')]],
        ]);
        $only = ['items.price'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $only), $only);

        self::assertSame(['added' => [1, 2], 'modified' => [2], 'deleted' => []], $fields['items.price']);
    }

    public function testARowIsListedOnlyOncePerKind(): void {
        $this->givenRecords([
          1 => ['items' => [101 => self::line('1', 'a', '1'), 102 => self::line('1', 'b', '1')]],
          2 => ['items' => [101 => self::line('1', 'a', '2'), 102 => self::line('1', 'b', '2')]], // two lines modified
        ]);
        $only = ['items.price'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $only), $only);

        self::assertSame(['added' => [1], 'modified' => [2], 'deleted' => []], $fields['items.price']);
    }

    public function testFieldChangesOfAPathWithTheLineKey(): void {
        $this->givenRecords(self::invoiceHistory());
        $only = ['items.101.price', 'items.103.product'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $only), $only);

        self::assertSame(['added' => [1], 'modified' => [4, 7], 'deleted' => []], $fields['items.101.price']);
        self::assertSame(['added' => [6], 'modified' => [7], 'deleted' => []], $fields['items.103.product']);
    }

    public function testFieldChangesMixNamesAndPaths(): void {
        $this->givenRecords(self::invoiceHistory());
        $only = ['total', 'items.product'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $only), $only);

        self::assertSame(['total', 'items.product'], array_keys($fields));
        self::assertSame(['added' => [1], 'modified' => [3], 'deleted' => []], $fields['total']);
        self::assertSame(['added' => [1, 6, 7], 'modified' => [7], 'deleted' => [5]], $fields['items.product']);
    }

    public function testOnlyPathsDoNotReportPlainFields(): void {
        $this->givenRecords(self::invoiceHistory());
        $only = ['items.price'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK), $only);

        self::assertSame(['items.price'], array_keys($fields));
    }

    public function testPathThatNeverChangesIsNotReported(): void {
        $this->givenRecords(self::invoiceHistory());
        $only = ['items.nonexistent'];

        self::assertSame([], $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $only), $only));
    }

    public function testFieldChangesOfAPathUseTheIgnoredFieldRules(): void {
        $this->givenRecords([
          1 => ['items' => [101 => self::line('1', 'a', '1', null, 'a')]],
          2 => ['items' => [101 => self::line('1', 'a', '1', null, 'b')]],
        ]);

        $changed = $this->historian->getChangedRecords(self::PK, ['items.last_change']);

        self::assertSame(['items.last_change' => ['added' => [1], 'modified' => [2], 'deleted' => []]],
          $this->historian->getFieldChanges($changed, ['items.last_change']));
        // price never changed after the first row
        self::assertSame(['items.price' => ['added' => [1], 'modified' => [], 'deleted' => []]],
          $this->historian->getFieldChanges($changed, ['items.price']));
    }

    public function testFieldChangesNullAndBoolRulesApplyToPaths(): void {
        $this->givenRecords([
          1 => ['items' => [101 => ['paid' => false, 'note' => null]]],
          2 => ['items' => [101 => ['paid' => 0, 'note' => '']]],
        ]);
        $only = ['items.paid', 'items.note'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $only), $only);

        self::assertSame(['added' => [1], 'modified' => [2], 'deleted' => []], $fields['items.paid']);
        self::assertSame(['added' => [2], 'modified' => [], 'deleted' => []], $fields['items.note']);
    }

    // ------------------------------------------------------------------ changesAsHTML without / with $fieldFormat

    public function testEmptyFieldFormatGivesTheSameHtmlAsNoFieldFormat(): void {
        $changes = $this->givenChanges(self::invoiceHistory());

        self::assertSame($this->historian->changesAsHTML($changes), $this->historian->changesAsHTML($changes, []));
    }

    public function testFieldFormatForOtherFieldsLeavesTheHtmlAsIs(): void {
        $changes = $this->givenChanges(self::invoiceHistory());

        self::assertSame($this->historian->changesAsHTML($changes), $this->historian->changesAsHTML($changes, ['nonexistent' => ['class' => 'x']]));
    }

    public function testFieldFormatClassLabelAndPrefixesOfAPlainField(): void {
        $changes = $this->givenChanges([1 => ['total' => '10'], 2 => ['total' => '20']]);

        $html = $this->historian->changesAsHTML($changes, ['total' => [
          'class' => 'money big', 'label' => 'Invoice <i>total</i>', 'fieldPrefix' => '[', 'fieldSuffix' => ']',
          'valuePrefix' => '$', 'valueSuffix' => ' USD',
        ]]);

        self::assertStringContainsString('<tr class="money big"><td>[Invoice <i>total</i>]<td>$10 USD<td>$20 USD', $html);
    }

    public function testFieldFormatLabelDefaultsToToLabel(): void {
        $changes = $this->givenChanges([1 => ['grand_total' => '10'], 2 => ['grand_total' => '20']]);

        $html = $this->historian->changesAsHTML($changes, ['grand_total' => ['class' => 'c']]);

        self::assertStringContainsString('<tr class="c"><td>Grand Total<td>10<td>20', $html);
    }

    public function testFieldFormatKeysAreOptional(): void {
        $changes = $this->givenChanges([1 => ['total' => '10'], 2 => ['total' => '20']]);

        $html = $this->historian->changesAsHTML($changes, ['total' => []]);

        self::assertStringContainsString('<tr><td>Total<td>10<td>20', $html);
    }

    public function testFieldFormatDoesNotEscapeAnything(): void {
        $changes = $this->givenChanges([1 => ['name' => '<b>a</b>'], 2 => ['name' => '<i>b</i>']]);

        $html = $this->historian->changesAsHTML($changes, ['name' => ['valuePrefix' => '<u>', 'valueSuffix' => '</u>']]);

        self::assertStringContainsString('<td><u><b>a</b></u><td><u><i>b</i></u>', $html);
    }

    public function testFieldFormatOnlyAppliesToTheHtmlNotToTheData(): void {
        $this->givenRecords([1 => ['total' => '10'], 2 => ['total' => '20']]);

        $changed = $this->historian->getChangedRecords(self::PK);

        self::assertSame('20', $changed[2]['record']['total']);
        self::assertSame(['total' => ['added' => [1], 'modified' => [2], 'deleted' => []]], $this->historian->getFieldChanges($changed));
    }

    public function testFieldFormatWithoutChildKeepsTheNestedJsonRendering(): void {
        $changes = $this->givenChanges([1 => ['meta' => ['a' => '1']], 2 => ['meta' => ['a' => '2']]]);

        $html = $this->historian->changesAsHTML($changes, ['meta' => ['valuePrefix' => '~']]);

        self::assertStringContainsString('<td>Meta<td>~{"a":"1"}<td>~{"a":"2"}', $html);
    }

    public function testChangesWithoutRecordCanStillBeFormatted(): void {
        $html = $this->historian->changesAsHTML([[
          'date' => '2026-01-01', 'action' => 'update', 'user_nick' => 'u',
          'diff' => ['total' => ['before' => '1', 'after' => '2']],
        ]], ['total' => ['class' => 'k']]);

        self::assertStringContainsString('<tr class="k"><td>Total<td>1<td>2', $html);
    }

    // ------------------------------------------------------------------ changesAsHTML: child lines

    private const ITEMS_FORMAT = [
      'items' => [
        'class' => 'lines',
        'label' => 'Lines',
        'child' => [
          'quantity' => [],
          'product' => [],
          'price' => [],
        ],
      ],
    ];

    /** The html of change $id out of the history. */
    private function htmlOfChange(array $history, int $id, array $format): string {
        $changes = $this->givenChanges($history);
        foreach($changes as $change)
            if($change['history_id'] === $id)
                return $this->historian->changesAsHTML([$change], $format);
        self::fail("change $id not found");
    }

    public function testChangedLinePrintsAllListedFieldsInSequenceBeforeAndAfter(): void {
        $html = $this->htmlOfChange(self::invoiceHistory(), 4, self::ITEMS_FORMAT);

        self::assertStringContainsString(
          '<tr class="lines"><td>Lines'
          . '<td><span title="Quantity">12</span> <span title="Product">circles</span> <span title="Price">3.14</span>'
          . '<td><span title="Quantity">12</span> <span title="Product">circles</span> <span title="Price">3.50</span>',
          $html
        );
    }

    public function testOnlyChangedLinesArePrinted(): void {
        $html = $this->htmlOfChange(self::invoiceHistory(), 4, self::ITEMS_FORMAT);

        self::assertSame(1, substr_count($html, '<tr class="lines">'));
        self::assertStringNotContainsString('triangles', $html, 'line 102 did not change');
    }

    public function testFieldsAreInTheSequenceOfTheFormatNotOfTheRecord(): void {
        $format = ['items' => ['child' => ['price' => [], 'product' => [], 'quantity' => []]]];
        $html = $this->htmlOfChange(self::invoiceHistory(), 4, $format);

        self::assertStringContainsString(
          '<td><span title="Price">3.14</span> <span title="Product">circles</span> <span title="Quantity">12</span>'
          . '<td><span title="Price">3.50</span> <span title="Product">circles</span> <span title="Quantity">12</span>',
          $html
        );
    }

    public function testAnyChangeInTheLinePrintsTheWholeLine(): void {
        // only the quantity changes, price and product are printed too
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => self::line('1', 'circles', '3.14')]],
          2 => ['items' => [101 => self::line('5', 'circles', '3.14')]],
        ], 2, self::ITEMS_FORMAT);

        self::assertStringContainsString(
          '<td><span title="Quantity">1</span> <span title="Product">circles</span> <span title="Price">3.14</span>'
          . '<td><span title="Quantity">5</span> <span title="Product">circles</span> <span title="Price">3.14</span>',
          $html
        );
    }

    public function testUnlistedFieldThatChangedIsShownLabeled(): void {
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => self::line('1', 'circles', '3.14', '0')]],
          2 => ['items' => [101 => self::line('1', 'circles', '3.14', '5')]],
        ], 2, self::ITEMS_FORMAT);

        self::assertStringContainsString('<span title="Price">3.14</span> <span>Discount: 0</span><td>', $html);
        self::assertStringContainsString('<span title="Price">3.14</span> <span>Discount: 5</span>', $html);
    }

    public function testIgnoredFieldsOfTheLinesAreNotShown(): void {
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => self::line('1', 'circles', '3.14', null, 'a')]],
          2 => ['items' => [101 => self::line('2', 'circles', '3.14', null, 'b')]],
        ], 2, self::ITEMS_FORMAT);

        self::assertStringNotContainsString('Last Change', $html);
    }

    public function testUnlistedFieldThatDidNotChangeIsNotShown(): void {
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => self::line('1', 'circles', '3.14', '5')]],
          2 => ['items' => [101 => self::line('2', 'circles', '3.14', '5')]],
        ], 2, self::ITEMS_FORMAT);

        self::assertStringNotContainsString('Discount', $html);
    }

    public function testAddedLineHasEmptyBefore(): void {
        $html = $this->htmlOfChange(self::invoiceHistory(), 6, self::ITEMS_FORMAT);

        self::assertStringContainsString(
          '<tr class="lines"><td>Lines<td>'
          . '<td><span title="Quantity">1</span> <span title="Product">squares</span> <span title="Price">9.00</span>',
          $html
        );
        self::assertSame(1, substr_count($html, '<tr class="lines">'));
    }

    public function testDeletedLineHasEmptyAfter(): void {
        $html = $this->htmlOfChange(self::invoiceHistory(), 5, self::ITEMS_FORMAT);

        self::assertStringContainsString(
          '<tr class="lines"><td>Lines'
          . '<td><span title="Quantity">2</span> <span title="Product">triangles</span> <span title="Price">1.00</span>'
          . '<td></tbody>',
          $html
        );
    }

    public function testAddedLineShowsOtherFieldsThatHaveAValue(): void {
        $html = $this->htmlOfChange([
          1 => ['total' => '1'],
          2 => ['total' => '1', 'items' => [101 => self::line('1', 'circles', '3.14', '5')]],
        ], 2, self::ITEMS_FORMAT);

        self::assertStringContainsString('<span>Discount: 5</span>', $html);
    }

    public function testAddedLineDoesNotShowOtherFieldsThatAreNull(): void {
        $html = $this->htmlOfChange([
          1 => ['total' => '1'],
          2 => ['total' => '1', 'items' => [101 => self::line('1', 'circles', '3.14', null)]],
        ], 2, self::ITEMS_FORMAT);

        self::assertStringNotContainsString('Discount', $html);
    }

    public function testSeveralChangedLinesPrintOneRowEach(): void {
        $html = $this->htmlOfChange(self::invoiceHistory(), 7, self::ITEMS_FORMAT);

        // 101 price modified, 103 product modified, 104 added
        self::assertSame(3, substr_count($html, '<tr class="lines">'));
        self::assertStringContainsString('<span title="Price">3.50</span><td><span title="Quantity">12</span> <span title="Product">circles</span> <span title="Price">4.00</span>', $html);
        self::assertStringContainsString('<span title="Product">squares</span> <span title="Price">9.00</span><td><span title="Quantity">1</span> <span title="Product">cubes</span>', $html);
        self::assertStringContainsString('<td><td><span title="Quantity">5</span> <span title="Product">dots</span>', $html);
    }

    public function testWholeCollectionAppearingPrintsEveryLineAsAdded(): void {
        $html = $this->htmlOfChange([
          1 => ['total' => '1'],
          2 => ['total' => '1', 'items' => [101 => self::line('1', 'a', '1'), 102 => self::line('2', 'b', '2')]],
        ], 2, self::ITEMS_FORMAT);

        self::assertSame(2, substr_count($html, '<tr class="lines">'));
        self::assertSame(2, substr_count($html, '<td>Lines<td><td>'));
    }

    public function testWholeCollectionDisappearingPrintsEveryLineAsDeleted(): void {
        $html = $this->htmlOfChange([
          1 => ['total' => '1', 'items' => [101 => self::line('1', 'a', '1'), 102 => self::line('2', 'b', '2')]],
          2 => ['total' => '1', 'items' => null],
        ], 2, self::ITEMS_FORMAT);

        self::assertSame(2, substr_count($html, '<tr class="lines">'));
        self::assertSame(2, substr_count($html, '<td></tbody>') + substr_count($html, '<td><tr class="lines">'));
    }

    public function testChildFieldFormatKeys(): void {
        $format = ['items' => ['child' => [
          'quantity' => ['class' => 'num', 'valueSuffix' => ' u.'],
          'product' => ['label' => 'Item', 'fieldPrefix' => '(', 'fieldSuffix' => ')'],
          'price' => ['valuePrefix' => '$', 'class' => 'money'],
        ]]];

        $html = $this->htmlOfChange(self::invoiceHistory(), 4, $format);

        self::assertStringContainsString(
          '<td><span class="num" title="Quantity">12 u.</span> (<span title="Item">circles</span>) <span class="money" title="Price">$3.14</span>'
          . '<td><span class="num" title="Quantity">12 u.</span> (<span title="Item">circles</span>) <span class="money" title="Price">$3.50</span>',
          $html
        );
    }

    public function testChildRowUsesTheFieldFormatClassLabelAndPrefixes(): void {
        $format = ['items' => ['class' => 'a b', 'label' => 'Lineas', 'fieldPrefix' => '<b>', 'fieldSuffix' => '</b>', 'child' => ['price' => []]]];

        $html = $this->htmlOfChange(self::invoiceHistory(), 4, $format);

        self::assertStringContainsString('<tr class="a b"><td><b>Lineas</b><td>', $html);
    }

    public function testChildFieldWithoutFormatKeysUsesToLabelAsTitle(): void {
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => ['unit_price' => '1']]],
          2 => ['items' => [101 => ['unit_price' => '2']]],
        ], 2, ['items' => ['child' => ['unit_price' => []]]]);

        self::assertStringContainsString('<span title="Unit Price">1</span>', $html);
    }

    public function testChildValuesAreNotEscaped(): void {
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => ['product' => '<b>x</b>']]],
          2 => ['items' => [101 => ['product' => '<i>y</i>']]],
        ], 2, ['items' => ['child' => ['product' => ['valuePrefix' => '<u>']]]]);

        self::assertStringContainsString('<span title="Product"><u><b>x</b></span>', $html);
        self::assertStringContainsString('<span title="Product"><u><i>y</i></span>', $html);
    }

    public function testNullFieldOfAChildPrintsEmpty(): void {
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => ['quantity' => '1', 'note' => null]]],
          2 => ['items' => [101 => ['quantity' => '2', 'note' => null]]],
        ], 2, ['items' => ['child' => ['quantity' => [], 'note' => []]]]);

        self::assertStringContainsString('<span title="Quantity">1</span> <span title="Note"></span>', $html);
    }

    public function testChildRecursion(): void {
        $format = ['items' => ['child' => [
          'product' => [],
          'options' => ['child' => ['name' => ['valuePrefix' => '#'], 'extra' => []]],
        ]]];
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => ['product' => 'a', 'options' => [1 => ['name' => 'red', 'extra' => '1'], 2 => ['name' => 'blue', 'extra' => '2']]]]],
          2 => ['items' => [101 => ['product' => 'a', 'options' => [1 => ['name' => 'red', 'extra' => '1'], 2 => ['name' => 'green', 'extra' => '2']]]]],
        ], 2, $format);

        self::assertStringContainsString(
          '<span title="Options"><span title="Name">#red</span> <span title="Extra">1</span>; <span title="Name">#blue</span> <span title="Extra">2</span></span>',
          $html
        );
        self::assertStringContainsString('<span title="Name">#green</span>', $html);
    }

    public function testChildFormatDoesNotAffectOtherFieldsOfTheSameChange(): void {
        $html = $this->htmlOfChange(self::invoiceHistory(), 7, self::ITEMS_FORMAT + ['total' => ['class' => 't']]);
        $plain = $this->htmlOfChange(self::invoiceHistory(), 3, ['total' => ['class' => 't']]);

        self::assertStringNotContainsString('Total', $html, 'total did not change in 7');
        self::assertStringContainsString('<tr class="t"><td>Total<td>10<td>20', $plain);
    }

    public function testHeaderAndChildChangesInTheSameChange(): void {
        $html = $this->htmlOfChange([
          1 => ['total' => '10', 'items' => [101 => self::line('1', 'a', '1.00')]],
          2 => ['total' => '20', 'items' => [101 => self::line('1', 'a', '2.00')]],
        ], 2, self::ITEMS_FORMAT + ['total' => ['class' => 't']]);

        self::assertStringContainsString('<tr class="t"><td>Total<td>10<td>20', $html);
        self::assertStringContainsString('<tr class="lines"><td>Lines', $html);
    }

    public function testChildFormatOnAFieldThatIsNotACollectionFallsBackToNothingBroken(): void {
        $html = $this->htmlOfChange([1 => ['total' => '10'], 2 => ['total' => '20']], 2, ['total' => ['child' => ['x' => []]]]);

        self::assertStringNotContainsString('<tr class', $html);
        self::assertStringContainsString('<table', $html);
    }

    public function testChildHtmlLinesAreKeyedByTheLineKeyNotByPosition(): void {
        // line 101 removed: line 102 did not change and must not be printed
        $html = $this->htmlOfChange([
          1 => ['items' => [101 => self::line('1', 'a', '1'), 102 => self::line('2', 'b', '2')]],
          2 => ['items' => [102 => self::line('2', 'b', '2')]],
        ], 2, self::ITEMS_FORMAT);

        self::assertSame(1, substr_count($html, '<tr class="lines">'));
        self::assertStringContainsString('<span title="Product">a</span>', $html);
        self::assertStringNotContainsString('<span title="Product">b</span>', $html);
    }
}
