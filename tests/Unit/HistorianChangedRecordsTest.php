<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\Historian;
use Ocallit\Sqler\SqlExecutor;

require_once __DIR__ . '/../../vendor/autoload.php';

#[CoversClass(Historian::class)]
class HistorianChangedRecordsTest extends TestCase {
    private Historian $historian;
    private SqlExecutor $mockSqlExecutor;

    protected function setUp(): void {
        $this->mockSqlExecutor = $this->createStub(SqlExecutor::class);
        $this->historian = new Historian($this->mockSqlExecutor, 'test_table');
    }

    /** Replace the stub with a mock, for tests that set expectations on SqlExecutor. */
    private function useMockSqlExecutor(): void {
        $this->mockSqlExecutor = $this->createMock(SqlExecutor::class);
        $this->historian = new Historian($this->mockSqlExecutor, 'test_table');
    }

    /** History row as SqlExecutor::arrayKeyed returns it. $record is an array (json encoded) or a raw json string. */
    private static function row(int $id, array|string $record, string $action = 'update'): array {
        return [
          'history_id' => $id,
          'action' => $action,
          'motive' => '',
          'date' => sprintf('2026-09-07 10:%02d:00.000000', $id),
          'user_nick' => 'tester',
          'record' => is_string($record) ? $record : json_encode($record),
        ];
    }

    /** @param array<int, array> $rows keyed by history_id, in the order the database returns them (date ASC) */
    private function givenHistory(array $rows, ?Historian $historian = null): Historian {
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn($rows);
        return $historian ?? $this->historian;
    }

    private function givenRecords(array $records): void {
        $rows = [];
        foreach($records as $id => $record)
            $rows[$id] = self::row($id, $record);
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn($rows);
    }

    private const PK = ['test_table_id' => 1];

    // ------------------------------------------------------------------ getChangedRecords: query

    public function testGetChangedRecordsQueriesTheHistoryTableOldestFirstWithEncodedPk(): void {
        $this->useMockSqlExecutor();
        $this->mockSqlExecutor->expects(self::once())->method('arrayKeyed')
          ->with(
            self::callback(static function(string $sql): bool {
                return str_contains($sql, 'FROM test_table_hist')
                  && str_contains($sql, '`pk` = ?')
                  && preg_match('/ORDER BY\s+`date`,\s*history_id\s*$/', $sql) === 1
                  && !str_contains($sql, 'LIMIT')
                  && !str_contains($sql, 'DESC');
            }),
            'history_id',
            ['1']
          )->willReturn([]);

        $this->historian->getChangedRecords(self::PK);
    }

    public function testGetChangedRecordsSqlSelectsTheColumnsTheResultNeeds(): void {
        $this->useMockSqlExecutor();
        $this->mockSqlExecutor->expects(self::once())->method('arrayKeyed')
          ->with(self::callback(static function(string $sql): bool {
              foreach(['history_id', '`action`', '`date`', '`user_nick`', '`record`', '`motive`'] as $column)
                  if(!str_contains($sql, $column))
                      return false;
              return str_contains($sql, 'Historian::getChangedRecords');
          }), 'history_id', self::anything())->willReturn([]);

        $this->historian->getChangedRecords(self::PK);
    }

    public function testGetChangedRecordsEncodesCompositePrimaryKeyTabSeparated(): void {
        $this->useMockSqlExecutor();
        $historian = new Historian($this->mockSqlExecutor, 'test_table', ['a_id', 'b_id']);
        $this->mockSqlExecutor->expects(self::once())->method('arrayKeyed')
          ->with(self::anything(), 'history_id', ["1\t2"])->willReturn([]);

        $historian->getChangedRecords(['b_id' => 2, 'a_id' => 1, 'extra' => 'ignored']);
    }

    public function testGetChangedRecordsRejectsMissingPrimaryKey(): void {
        $this->useMockSqlExecutor();
        $this->mockSqlExecutor->expects(self::never())->method('arrayKeyed');
        $this->expectException(\InvalidArgumentException::class);

        $this->historian->getChangedRecords(['other_id' => 1]);
    }

    public function testDeleteRowIsAlwaysReturnedEvenWhenNothingChanged(): void {
        $rec = ['name' => 'A'];
        $rows = [1 => self::row(1, $rec, 'insert'), 2 => self::row(2, $rec, 'update'), 3 => self::row(3, $rec, 'delete')];
        $this->givenHistory($rows);

        self::assertSame([1, 3], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testGetChangesReturnsDeleteWithoutChangesAndAsOldestRow(): void {
        $rec = ['name' => 'A'];
        $this->givenHistory([3 => self::row(3, $rec, 'delete'), 2 => self::row(2, $rec, 'update'), 1 => self::row(1, $rec, 'delete')]);

        $changes = $this->historian->getChanges(self::PK);

        self::assertSame([3, 1], array_column($changes, 'history_id'));
        self::assertSame([], $changes[0]['diff']);
        self::assertSame(['name' => ['before' => null, 'after' => 'A']], $changes[1]['diff']);
    }

    // ------------------------------------------------------------------ getChangedRecords: which rows

    public function testNoHistoryReturnsEmptyArray(): void {
        $this->givenHistory([]);

        self::assertSame([], $this->historian->getChangedRecords(self::PK));
    }

    public function testSingleRowWithValuesIsReturned(): void {
        $this->givenRecords([5 => ['name' => 'A']]);

        $changed = $this->historian->getChangedRecords(self::PK);

        self::assertSame([5], array_keys($changed));
    }

    public function testFirstRowIsAlwaysKeptEvenWithoutAnyValue(): void {
        $this->givenRecords([1 => [], 2 => ['name' => null]]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testFirstEmptyRowIsKeptAsBaseAndLaterRowWithValuesIsKept(): void {
        $this->givenRecords([1 => [], 2 => ['name' => 'A']]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testFirstRowIsKeptEvenIfOnlyIgnoredFieldsHaveValues(): void {
        $this->givenRecords([1 => ['last_change' => 1], 2 => ['last_change' => 2]]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testFirstRowIsKeptEvenIfItHasNoneOfTheOnlyFields(): void {
        $this->givenRecords([1 => ['name' => 'A'], 2 => ['name' => 'B']]);

        $changed = $this->historian->getChangedRecords(self::PK, ['price']);

        self::assertSame([1], array_keys($changed));
        self::assertSame(['name' => 'A'], $changed[1]['record']);
    }

    public function testFirstRowIsTheBaseForComparingLaterRows(): void {
        $this->givenRecords([1 => ['name' => 'A', 'price' => 1], 2 => ['name' => 'B', 'price' => 1], 3 => ['name' => 'B', 'price' => 2]]);

        self::assertSame([1, 3], array_keys($this->historian->getChangedRecords(self::PK, ['price'])));
    }

    public function testResultIsKeyedByHistoryIdAndKeepsDateOrder(): void {
        $this->givenRecords([
          10 => ['v' => 1],
          20 => ['v' => 2],
          30 => ['v' => 3],
        ]);

        $changed = $this->historian->getChangedRecords(self::PK);

        self::assertSame([10, 20, 30], array_keys($changed));
        foreach($changed as $id => $row)
            self::assertSame($id, $row['history_id']);
    }

    public function testRowsKeepAllHistoryMetadata(): void {
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn([
          7 => [
            'history_id' => 7, 'action' => 'insert', 'motive' => 'why', 'date' => '2026-01-02 03:04:05.000000',
            'user_nick' => 'ana', 'record' => '{"name":"A"}',
          ],
        ]);

        $row = $this->historian->getChangedRecords(self::PK)[7];

        self::assertSame(7, $row['history_id']);
        self::assertSame('insert', $row['action']);
        self::assertSame('why', $row['motive']);
        self::assertSame('2026-01-02 03:04:05.000000', $row['date']);
        self::assertSame('ana', $row['user_nick']);
        self::assertSame(['name' => 'A'], $row['record']);
    }

    public function testUnchangedRowsAreSkipped(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'qty' => 1],
          2 => ['name' => 'A', 'qty' => 1],
          3 => ['name' => 'B', 'qty' => 1],
          4 => ['name' => 'B', 'qty' => 1],
          5 => ['name' => 'B', 'qty' => 2],
        ]);

        self::assertSame([1, 3, 5], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testRowsDifferingOnlyInDefaultIgnoredFieldsAreSkipped(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'ultimo_cambio' => '1', 'ultimo_cambio_por' => 'a', 'last_changed' => '1',
                'last_changed_by' => 'a', 'last_change' => '1', 'last_change_by' => 'a'],
          2 => ['name' => 'A', 'ultimo_cambio' => '2', 'ultimo_cambio_por' => 'b', 'last_changed' => '2',
                'last_changed_by' => 'b', 'last_change' => '2', 'last_change_by' => 'b'],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testReturnedRecordsAreFullAndStillContainIgnoredFields(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'last_change' => '1'],
          2 => ['name' => 'B', 'last_change' => '2'],
        ]);

        $changed = $this->historian->getChangedRecords(self::PK);

        self::assertSame(['name' => 'A', 'last_change' => '1'], $changed[1]['record']);
        self::assertSame(['name' => 'B', 'last_change' => '2'], $changed[2]['record']);
    }

    public function testConstructorIgnoredFieldsAreAddedToTheDefaults(): void {
        $historian = new Historian($this->mockSqlExecutor, 'test_table', [], ['version']);
        $this->givenRecords([
          1 => ['name' => 'A', 'version' => 1, 'last_change' => 1],
          2 => ['name' => 'A', 'version' => 2, 'last_change' => 2],
        ]);

        self::assertSame([1], array_keys($historian->getChangedRecords(self::PK)));
    }

    public function testSetIgnoreDifferenceForFieldsReplacesTheIgnoredFields(): void {
        $this->historian->setIgnoreDifferenceForFields(['version']);
        $this->givenRecords([
          1 => ['name' => 'A', 'version' => 1, 'last_change' => 1],
          2 => ['name' => 'A', 'version' => 2, 'last_change' => 2], // version ignored, last_change no longer ignored
          3 => ['name' => 'A', 'version' => 3, 'last_change' => 2], // only version differs
        ]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testEmptyIgnoredFieldsListComparesEveryField(): void {
        $this->historian->setIgnoreDifferenceForFields([]);
        $this->givenRecords([
          1 => ['name' => 'A', 'last_change' => 1],
          2 => ['name' => 'A', 'last_change' => 2],
        ]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testChangeIsComparedAgainstPreviousKeptRowNotPreviousRow(): void {
        // row 2 only changes an ignored field, row 3 changes name relative to row 1 (and row 2)
        $this->givenRecords([
          1 => ['name' => 'A', 'last_change' => 1],
          2 => ['name' => 'A', 'last_change' => 2],
          3 => ['name' => 'B', 'last_change' => 3],
        ]);

        self::assertSame([1, 3], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testValueGoingBackToAnEarlierValueIsAChange(): void {
        $this->givenRecords([1 => ['v' => 'A'], 2 => ['v' => 'B'], 3 => ['v' => 'A']]);

        self::assertSame([1, 2, 3], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testDeleteRowIdenticalToPreviousIsSkippedButChangedOneIsKept(): void {
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn([
          1 => self::row(1, ['name' => 'A'], 'insert'),
          2 => self::row(2, ['name' => 'A'], 'delete'),
          3 => self::row(3, ['name' => 'B'], 'insert'),
          4 => self::row(4, ['name' => 'C'], 'delete'),
        ]);

        $changed = $this->historian->getChangedRecords(self::PK);

        self::assertSame([1, 3, 4], array_keys($changed));
        self::assertSame('delete', $changed[4]['action']);
    }

    public function testComparisonIsLoose(): void {
        // 1, '1' and 1.0 are equal (==); true is a bool so it differs from them and from ''
        $this->givenRecords([1 => ['v' => 1], 2 => ['v' => '1'], 3 => ['v' => 1.0], 4 => ['v' => true], 5 => ['v' => '']]);

        self::assertSame([1, 4, 5], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testMissingKeyAndNullAreTheSame(): void {
        $this->givenRecords([1 => ['a' => 1, 'b' => null], 2 => ['a' => 1]]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testKeyOrderDoesNotMatter(): void {
        $this->givenRecords([1 => ['a' => 1, 'b' => 2], 2 => ['b' => 2, 'a' => 1]]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    // ------------------------------------------------------------------ getChangedRecords: nested / decoding

    public function testEqualNestedArraysAreNotAChange(): void {
        $this->givenRecords([
          1 => ['items' => [['a' => 1]], 'x' => 1],
          2 => ['items' => [['a' => 1]], 'x' => 1],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testChangedNestedArrayIsAChange(): void {
        $this->givenRecords([
          1 => ['items' => [['a' => 1]]],
          2 => ['items' => [['a' => 2]]],
        ]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testAddedOrRemovedNestedItemIsAChange(): void {
        $this->givenRecords([
          1 => ['items' => [['a' => 1]]],
          2 => ['items' => [['a' => 1], ['a' => 2]]],
          3 => ['items' => [['a' => 1]]],
        ]);

        self::assertSame([1, 2, 3], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testNestedIgnoredFieldsAreIgnored(): void {
        $this->givenRecords([
          1 => ['items' => ['a' => 1, 'last_change' => 1]],
          2 => ['items' => ['a' => 1, 'last_change' => 2]],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    #[DataProvider('accentedProvider')]
    public function testAccentedTextIsNormalizedBeforeComparingAndReturned(string $first, string $second): void {
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn([
          1 => self::row(1, $first),
          2 => self::row(2, $second),
        ]);

        $changed = $this->historian->getChangedRecords(self::PK);

        self::assertSame([1], array_keys($changed), 'composed and decomposed forms are the same text');
        self::assertSame('Á', $changed[1]['record']['name']);
        self::assertSame('Á', $changed[1]['record']['nested']['name']);
    }

    public static function accentedProvider(): array {
        return [
          'composed vs decomposed' => ['{"name":"Á","nested":{"name":"Á"}}', '{"name":"Á","nested":{"name":"Á"}}'],
          'escaped composed vs decomposed' => ['{"name":"Á","nested":{"name":"Á"}}', '{"name":"Á","nested":{"name":"Á"}}'],
          'decomposed vs composed' => ['{"name":"Á","nested":{"name":"Á"}}', '{"name":"Á","nested":{"name":"Á"}}'],
        ];
    }

    public function testBigIntegersAreKeptAsStrings(): void {
        $this->givenHistory([1 => self::row(1, '{"big":12345678901234567890123}')]);

        self::assertSame('12345678901234567890123', $this->historian->getChangedRecords(self::PK)[1]['record']['big']);
    }

    public function testInvalidJsonThrows(): void {
        $this->givenHistory([1 => self::row(1, '{not json')]);
        $this->expectException(\JsonException::class);

        $this->historian->getChangedRecords(self::PK);
    }

    public function testNullRecordThrows(): void {
        $row = self::row(1, '{}');
        $row['record'] = 'null';
        $this->givenHistory([1 => $row]);
        $this->expectException(\UnexpectedValueException::class);

        $this->historian->getChangedRecords(self::PK);
    }

    #[DataProvider('nonArrayJsonProvider')]
    public function testRecordThatIsNotAnArrayThrows(string $json): void {
        $this->givenHistory([1 => self::row(1, $json)]);
        $this->expectException(\UnexpectedValueException::class);

        $this->historian->getChangedRecords(self::PK);
    }

    public static function nonArrayJsonProvider(): array {
        return ['scalar number' => ['5'], 'string' => ['"text"'], 'boolean' => ['true'], 'null' => ['null']];
    }

    public function testInvalidUnicodeThrows(): void {
        // lone combining sequence is valid; an unpaired surrogate escape is not decodable to NFC text
        $this->givenHistory([1 => self::row(1, '{"name":"\ud800"}')]);
        $this->expectException(\Throwable::class);

        $this->historian->getChangedRecords(self::PK);
    }

    // ------------------------------------------------------------------ getChangedRecords: $onlyFields

    public function testOnlyFieldsKeepsOnlyRowsWhereAListedFieldChanged(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'price' => 1, 'stock' => 1],
          2 => ['name' => 'B', 'price' => 1, 'stock' => 1], // name only
          3 => ['name' => 'B', 'price' => 2, 'stock' => 1], // price
          4 => ['name' => 'B', 'price' => 2, 'stock' => 5], // stock only
          5 => ['name' => 'C', 'price' => 3, 'stock' => 5], // both
        ]);

        self::assertSame([1, 3, 5], array_keys($this->historian->getChangedRecords(self::PK, ['price'])));
    }

    public function testOnlyFieldsWithSeveralFields(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'price' => 1, 'stock' => 1],
          2 => ['name' => 'B', 'price' => 1, 'stock' => 1],
          3 => ['name' => 'B', 'price' => 2, 'stock' => 1],
          4 => ['name' => 'B', 'price' => 2, 'stock' => 5],
        ]);

        self::assertSame([1, 3, 4], array_keys($this->historian->getChangedRecords(self::PK, ['price', 'stock'])));
    }

    public function testOnlyFieldsStillReturnsFullRecords(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'price' => 1],
          2 => ['name' => 'B', 'price' => 2],
        ]);

        $changed = $this->historian->getChangedRecords(self::PK, ['price']);

        self::assertSame(['name' => 'B', 'price' => 2], $changed[2]['record']);
    }

    public function testOnlyFieldsIsComparedAgainstThePreviousKeptRow(): void {
        // row 2 changes only an unlisted field, so row 3 is compared against row 1: still price 1 -> not a change
        $this->givenRecords([
          1 => ['name' => 'A', 'price' => 1],
          2 => ['name' => 'B', 'price' => 1],
          3 => ['name' => 'C', 'price' => 1],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['price'])));
    }

    public function testOnlyFieldsFirstRowWithoutListedFieldValueIsKeptAsBase(): void {
        $this->givenRecords([
          1 => ['name' => 'A'],
          2 => ['name' => 'A', 'price' => 5],
        ]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['price'])));
    }

    public function testOnlyFieldsMatchingNoFieldReturnsOnlyTheFirstRow(): void {
        $this->givenRecords([1 => ['name' => 'A'], 2 => ['name' => 'B']]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['nonexistent'])));
    }

    public function testEmptyOnlyFieldsMeansAllFields(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'price' => 1],
          2 => ['name' => 'B', 'price' => 1],
        ]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, [])));
        self::assertSame($this->historian->getChangedRecords(self::PK), $this->historian->getChangedRecords(self::PK, []));
    }

    public function testOnlyFieldsWinsOverIgnoredFields(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'last_change' => 1],
          2 => ['name' => 'A', 'last_change' => 2],
          3 => ['name' => 'B', 'last_change' => 2],
        ]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['last_change'])));
        self::assertSame([1, 3], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testOnlyFieldsWinsOverConstructorAndSetIgnoredFields(): void {
        $historian = new Historian($this->mockSqlExecutor, 'test_table', [], ['version']);
        $this->givenRecords([1 => ['version' => 1], 2 => ['version' => 2]]);

        self::assertSame([1], array_keys($historian->getChangedRecords(self::PK)));
        self::assertSame([1, 2], array_keys($historian->getChangedRecords(self::PK, ['version'])));

        $historian->setIgnoreDifferenceForFields(['version']);
        self::assertSame([1, 2], array_keys($historian->getChangedRecords(self::PK, ['version'])));
    }

    public function testOnlyFieldsDoesNotUnignoreOtherIgnoredFields(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'last_change' => 1, 'last_change_by' => 'a'],
          2 => ['name' => 'A', 'last_change' => 1, 'last_change_by' => 'b'],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['last_change'])));
    }

    public function testOnlyFieldsDoesNotChangeTheIgnoredFieldsForLaterCalls(): void {
        $this->givenRecords([1 => ['last_change' => 1], 2 => ['last_change' => 2]]);

        $this->historian->getChangedRecords(self::PK, ['last_change']);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testOnlyFieldsAppliesToNestedValuesOfAListedField(): void {
        $this->givenRecords([
          1 => ['items' => ['a' => 1], 'x' => 1],
          2 => ['items' => ['a' => 2], 'x' => 1],
          3 => ['items' => ['a' => 2], 'x' => 2],
        ]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['items'])));
    }

    public function testOnlyFieldsNestedIgnoredKeyIsIgnoredUnlessItsNameIsListed(): void {
        $this->givenRecords([
          1 => ['meta' => ['last_change' => 1]],
          2 => ['meta' => ['last_change' => 2]],
        ]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['meta'])), 'nested last_change ignored');
        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK, ['meta', 'last_change'])));
    }

    public function testOnlyFieldsNamesAreMatchedExactly(): void {
        $this->givenRecords([1 => ['price' => 1], 2 => ['price' => 2]]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['Price'])));
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK, ['pric'])));
    }

    // ------------------------------------------------------------------ getFieldChanges

    public function testNoRecordsGivesNoFieldChanges(): void {
        self::assertSame([], $this->historian->getFieldChanges([]));
    }

    public function testFirstRecordAddsEveryFieldWithAValue(): void {
        $this->givenRecords([4 => ['name' => 'A', 'qty' => 0, 'flag' => false, 'text' => '', 'none' => null]]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        // null versus a value (0, false, '') is a change, null versus null is not
        self::assertSame(['name', 'qty', 'flag', 'text'], array_keys($fields), 'null valued field is not a change');
        foreach($fields as $field)
            self::assertSame(['added' => [4], 'modified' => [], 'deleted' => []], $field);
    }

    public function testEveryFieldHasTheThreeKinds(): void {
        $this->givenRecords([1 => ['a' => 1]]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['added', 'modified', 'deleted'], array_keys($fields['a']));
    }

    public function testAddedModifiedAndDeleted(): void {
        $this->givenRecords([
          1 => ['name' => 'A'],
          2 => ['name' => 'B', 'phone' => '1'],
          3 => ['name' => 'B', 'phone' => '2'],
          4 => ['name' => 'B', 'phone' => null],
        ]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['added' => [1], 'modified' => [2], 'deleted' => []], $fields['name']);
        self::assertSame(['added' => [2], 'modified' => [3], 'deleted' => [4]], $fields['phone']);
    }

    public function testFieldRemovedFromTheRecordIsDeleted(): void {
        $this->givenRecords([1 => ['a' => 1, 'b' => 1], 2 => ['a' => 1]]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['added' => [1], 'modified' => [], 'deleted' => [2]], $fields['b']);
        self::assertSame(['added' => [1], 'modified' => [], 'deleted' => []], $fields['a']);
    }

    public function testFieldAddedByNewKeyIsAdded(): void {
        $this->givenRecords([1 => ['a' => 1], 2 => ['a' => 1, 'b' => 'x']]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['added' => [2], 'modified' => [], 'deleted' => []], $fields['b']);
    }

    public function testFieldAddedDeletedAndAddedAgainKeepsEachIdInItsKind(): void {
        $this->givenRecords([
          1 => ['a' => 'x'],
          2 => ['a' => null],
          3 => ['a' => 'y'],
          4 => ['a' => 'z'],
          5 => ['a' => null],
          6 => ['a' => 'w'],
        ]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['added' => [1, 3, 6], 'modified' => [4], 'deleted' => [2, 5]], $fields['a']);
    }

    public function testIdsAreInTheOrderOfTheGivenRecords(): void {
        $this->givenRecords([30 => ['v' => 1], 10 => ['v' => 2], 20 => ['v' => 3]]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['added' => [30], 'modified' => [10, 20], 'deleted' => []], $fields['v']);
    }

    public function testIdsAreIntegers(): void {
        $this->givenRecords([1 => ['v' => 1], 2 => ['v' => 2]]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        foreach(['added', 'modified', 'deleted'] as $kind)
            foreach($fields['v'][$kind] as $id)
                self::assertIsInt($id);
    }

    public function testUnchangedFieldIsOnlyAddedOnce(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'qty' => 1],
          2 => ['name' => 'A', 'qty' => 2],
          3 => ['name' => 'A', 'qty' => 3],
        ]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['added' => [1], 'modified' => [], 'deleted' => []], $fields['name']);
        self::assertSame(['added' => [1], 'modified' => [2, 3], 'deleted' => []], $fields['qty']);
    }

    public function testFieldsAreInOrderOfFirstAppearance(): void {
        $this->givenRecords([1 => ['b' => 1], 2 => ['b' => 1, 'a' => 1]]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['b', 'a'], array_keys($fields));
    }

    #[DataProvider('kindProvider')]
    public function testClassificationOfValueTransitions(mixed $before, mixed $after, string $expectedKind): void {
        $this->givenRecords([1 => ['filler' => 1, 'f' => $before], 2 => ['filler' => 1, 'f' => $after]]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        $f = $fields['f'];
        self::assertSame([2], $f[$expectedKind]);
        foreach(array_diff(['added', 'modified', 'deleted'], [$expectedKind]) as $other)
            self::assertSame([], array_diff($f[$other], [1]), "unexpected $other for the second row");
    }

    public static function kindProvider(): array {
        return [
          'null to value' => [null, 'x', 'added'],
          'value to null' => ['x', null, 'deleted'],
          'string to string' => ['x', 'y', 'modified'],
          'int to int' => [1, 2, 'modified'],
          'zero to null' => [0, null, 'deleted'],
          'null to zero' => [null, 0, 'added'],
          'false to null' => [false, null, 'deleted'],
          'null to false' => [null, false, 'added'],
          'empty string to null' => ['', null, 'deleted'],
          'null to empty string' => [null, '', 'added'],
          'string zero to null' => ['0', null, 'deleted'],
          'null to string zero' => [null, '0', 'added'],
          'empty string to value' => ['', 'x', 'modified'],
          'value to empty string' => ['x', '', 'modified'],
          'false to true' => [false, true, 'modified'],
          'null to array' => [null, ['a' => 1], 'added'],
          'array to null' => [['a' => 1], null, 'deleted'],
          'array to other array' => [['a' => 1], ['a' => 2], 'modified'],
          'array to scalar' => [['a' => 1], 'x', 'modified'],
          'scalar to array' => ['x', ['a' => 1], 'modified'],
        ];
    }

    // ------------------------------------------------------------------ values read from sql are strings and stay strings

    public function testStringValuesStayStringsExactlyAsStored(): void {
        $values = [
          'int' => '5', 'decimal' => '1.10', 'leading_zero' => '007', 'exp' => '1e3', 'plus' => '+5', 'hex' => '0x1A',
          'spaces' => ' 3 ', 'negative' => '-0.50', 'zero' => '0', 'empty' => '',
          'big' => '12345678901234567890123', 'float_noise' => '0.30000000000000004',
          'nested' => ['n' => '7', 'list' => ['1', '2.50']],
        ];
        $this->givenHistory([1 => self::row(1, $values)]);

        self::assertSame($values, $this->historian->getChangedRecords(self::PK)[1]['record']);
    }

    #[DataProvider('dateTimeProvider')]
    public function testDateDatetimeAndTimeValuesStayStrings(string $value): void {
        $this->givenHistory([1 => self::row(1, ['v' => $value, 'nested' => ['v' => $value]])]);

        $record = $this->historian->getChangedRecords(self::PK)[1]['record'];

        self::assertSame($value, $record['v']);
        self::assertSame($value, $record['nested']['v']);
    }

    public static function dateTimeProvider(): array {
        return [
          'date' => ['2026-09-07'],
          'datetime' => ['2026-09-07 10:30:15'],
          'datetime fractional' => ['2026-09-07 10:30:15.123456'],
          'time' => ['10:30:15'],
          'time fractional' => ['10:30:15.500000'],
          'negative time' => ['-12:30:00'],
          'big time' => ['838:59:59'],
          'zero date' => ['0000-00-00'],
          'zero datetime' => ['0000-00-00 00:00:00'],
          'year' => ['2026'],
          'compact datetime' => ['20260907103015'],
        ];
    }

    public function testNumericStringsAreComparedAsNumbersAndOtherStringsAsText(): void {
        $this->givenRecords([
          1 => ['p' => '1.10', 'd' => '2026-09-07', 't' => '10:30:00'],
          2 => ['p' => '1.1', 'd' => '2026-09-07', 't' => '10:30:00'],        // 1.10 == 1.1: no change
          3 => ['p' => '01.1', 'd' => '2026-09-07', 't' => '10:30:00'],       // no change
          4 => ['p' => '1.1', 'd' => '2026-09-07', 't' => '10:30:00.000000'], // time text differs: change
          5 => ['p' => '1.1', 'd' => '2026-09-08', 't' => '10:30:00.000000'], // date differs: change
        ]);

        self::assertSame([1, 4, 5], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testNumericStringEqualsTheNumber(): void {
        $this->givenRecords([1 => ['v' => '1'], 2 => ['v' => 1], 3 => ['v' => 1.0]]);
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testHugeIntegerStringsAreStillComparedAsText(): void {
        // PHP compares two overflowing integer strings as strings
        $this->givenRecords([1 => ['v' => '12345678901234567890123'], 2 => ['v' => '12345678901234567890124']]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testDecimalStringsBeyondFloatPrecisionAreEqual(): void {
        // known limit of ==: decimals are compared as floats
        $this->givenRecords([1 => ['v' => '0.10000000000000000001'], 2 => ['v' => '0.10000000000000000002']]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    #[DataProvider('nullVersusValueProvider')]
    public function testNullVersusAnyValueIsAChange(mixed $before, mixed $after): void {
        $this->givenRecords([1 => ['filler' => 1, 'v' => $before], 2 => ['filler' => 1, 'v' => $after]]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public static function nullVersusValueProvider(): array {
        return [
          'zero and null' => [0, null], 'null and zero' => [null, 0],
          'false and null' => [false, null], 'null and false' => [null, false],
          'empty string and null' => ['', null], 'null and empty string' => [null, ''],
          'string zero and null' => ['0', null], 'null and string zero' => [null, '0'],
          'empty array and null' => [[], null], 'null and empty array' => [null, []],
        ];
    }

    #[DataProvider('boolVersusOtherProvider')]
    public function testBoolVersusAnotherTypeIsAChange(mixed $before, mixed $after): void {
        $this->givenRecords([1 => ['filler' => 1, 'v' => $before], 2 => ['filler' => 1, 'v' => $after]]);

        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public static function boolVersusOtherProvider(): array {
        return [
          'zero and false' => [0, false], 'false and zero' => [false, 0],
          'empty string and false' => ['', false], 'false and empty string' => [false, ''],
          'string zero and false' => ['0', false], 'false and string zero' => [false, '0'],
          'null and false' => [null, false], 'false and null' => [false, null],
          'one and true' => [1, true], 'true and one' => [true, 1],
          'string one and true' => ['1', true], 'true and string one' => [true, '1'],
          'empty array and false' => [[], false], 'text and true' => ['x', true],
          'float zero and false' => [0.0, false],
        ];
    }

    public function testSameBoolIsNotAChangeAndBoolToBoolIs(): void {
        $this->givenRecords([1 => ['v' => false], 2 => ['v' => false], 3 => ['v' => true], 4 => ['v' => true], 5 => ['v' => false]]);

        self::assertSame([1, 3, 5], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testFieldChangesBoolVersusOtherIsModified(): void {
        $this->givenRecords([1 => ['v' => 0], 2 => ['v' => false], 3 => ['v' => '']]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['added' => [1], 'modified' => [2, 3], 'deleted' => []], $fields['v']);
    }

    public function testMissingKeyVersusZeroOrEmptyIsAChange(): void {
        $this->givenRecords([1 => ['a' => 1], 2 => ['a' => 1, 'b' => 0], 3 => ['a' => 1, 'b' => ''], 4 => ['a' => 1]]);

        self::assertSame([1, 2, 3, 4], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testNullToNullIsNotAChange(): void {
        $this->givenRecords([1 => ['a' => 1, 'b' => null], 2 => ['a' => 1, 'b' => null], 3 => ['a' => 1]]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    #[DataProvider('looselyEqualProvider')]
    public function testLooselyEqualValuesAreNotAChange(mixed $before, mixed $after): void {
        $this->givenRecords([1 => ['filler' => 1, 'v' => $before], 2 => ['filler' => 1, 'v' => $after]]);

        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public static function looselyEqualProvider(): array {
        return [
          'int and numeric string' => [1, '1'],
          'decimal strings' => ['1.10', '1.1'], 'exponent string' => ['1e3', '1000'],
          'leading zeros' => ['007', '7'],
        ];
    }

    public function testBigIntegerNumbersInJsonAreDecodedAsStrings(): void {
        $this->givenHistory([1 => self::row(1, '{"big":12345678901234567890123}')]);

        self::assertSame('12345678901234567890123', $this->historian->getChangedRecords(self::PK)[1]['record']['big']);
    }

    public function testNumbersAlreadyInTheJsonAreNotConvertedByHistorian(): void {
        // register() stores what it is given; a JSON number is decoded by json_decode as is
        $this->givenHistory([1 => self::row(1, '{"i":5,"f":1.5}')]);

        $record = $this->historian->getChangedRecords(self::PK)[1]['record'];

        self::assertSame(5, $record['i']);
        self::assertSame(1.5, $record['f']);
    }

    public function testRegisterStoresValuesAsGiven(): void {
        $this->useMockSqlExecutor();
        $captured = [];
        $this->mockSqlExecutor->expects(self::once())->method('query')
          ->willReturnCallback(function(string $sql, array $params = []) use (&$captured) {
              $captured = $params;
              return true;
          });

        $this->historian->register('insert', ['test_table_id' => '1'], [
          'test_table_id' => '1', 'price' => '0.30', 'qty' => 3, 'date' => '2026-09-07', 'time' => '10:30:00',
          'nested' => ['n' => '2', 'm' => 2], 'none' => null,
        ], 'tester');

        $json = null;
        foreach($captured as $param)
            if(is_string($param) && str_starts_with($param, '{'))
                $json = $param;
        self::assertSame(
          '{"test_table_id":"1","price":"0.30","qty":3,"date":"2026-09-07","time":"10:30:00","nested":{"n":"2","m":2},"none":null}',
          $json
        );
    }

    public function testNestedChangesAreReportedOnTheTopLevelField(): void {
        $this->givenRecords([
          1 => ['items' => ['a' => 1]],
          2 => ['items' => ['a' => 1, 'b' => 2]],
          3 => ['items' => ['a' => 1, 'b' => 3]],
          4 => ['items' => ['a' => 1]],
          5 => ['items' => null],
        ]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['items'], array_keys($fields));
        self::assertSame(['added' => [1], 'modified' => [2, 3, 4], 'deleted' => [5]], $fields['items']);
    }

    public function testIgnoredFieldsAreNotReported(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'last_change' => 1],
          2 => ['name' => 'B', 'last_change' => 2],
        ]);

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK));

        self::assertSame(['name'], array_keys($fields));
    }

    public function testFieldChangesUseTheIgnoredFieldsOfTheHistorian(): void {
        $historian = new Historian($this->mockSqlExecutor, 'test_table', [], ['version']);
        $this->givenRecords([1 => ['name' => 'A', 'version' => 1], 2 => ['name' => 'B', 'version' => 2]]);

        $fields = $historian->getFieldChanges($historian->getChangedRecords(self::PK));

        self::assertSame(['name'], array_keys($fields));
    }

    public function testFieldChangesWithOnlyFieldsReportsOnlyThoseFields(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'price' => 1, 'stock' => 1],
          2 => ['name' => 'B', 'price' => 2, 'stock' => 1],
          3 => ['name' => 'C', 'price' => 2, 'stock' => 2],
        ]);
        $onlyFields = ['price'];

        $changed = $this->historian->getChangedRecords(self::PK, $onlyFields);
        $fields = $this->historian->getFieldChanges($changed, $onlyFields);

        self::assertSame([1, 2], array_keys($changed));
        self::assertSame(['price'], array_keys($fields));
        self::assertSame(['added' => [1], 'modified' => [2], 'deleted' => []], $fields['price']);
    }

    public function testFieldChangesWithOnlyFieldsListingSeveralFields(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'price' => 1, 'stock' => 1],
          2 => ['name' => 'B', 'price' => 2, 'stock' => 1],
          3 => ['name' => 'C', 'price' => 2, 'stock' => 2],
        ]);
        $onlyFields = ['price', 'stock'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $onlyFields), $onlyFields);

        self::assertSame(['price', 'stock'], array_keys($fields));
        self::assertSame(['added' => [1], 'modified' => [2], 'deleted' => []], $fields['price']);
        self::assertSame(['added' => [1], 'modified' => [3], 'deleted' => []], $fields['stock']);
    }

    public function testFieldChangesOnlyFieldsWinsOverIgnoredFields(): void {
        $this->givenRecords([
          1 => ['name' => 'A', 'last_change' => 1],
          2 => ['name' => 'A', 'last_change' => 2],
        ]);
        $onlyFields = ['last_change'];

        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK, $onlyFields), $onlyFields);

        self::assertSame(['last_change'], array_keys($fields));
        self::assertSame(['added' => [1], 'modified' => [2], 'deleted' => []], $fields['last_change']);
    }

    public function testFieldChangesWithoutOnlyFieldsOnRecordsFetchedWithOnlyFieldsReportsOtherFieldsToo(): void {
        // documents why the same $onlyFields must be passed to both methods
        $this->givenRecords([
          1 => ['name' => 'A', 'price' => 1],
          2 => ['name' => 'B', 'price' => 2],
        ]);

        $changed = $this->historian->getChangedRecords(self::PK, ['price']);

        self::assertEqualsCanonicalizing(['name', 'price'], array_keys($this->historian->getFieldChanges($changed)));
        self::assertSame(['price'], array_keys($this->historian->getFieldChanges($changed, ['price'])));
    }

    public function testFieldChangesOnlyFieldsMatchingNothingIsEmpty(): void {
        $this->givenRecords([1 => ['name' => 'A'], 2 => ['name' => 'B']]);
        $changed = $this->historian->getChangedRecords(self::PK);

        self::assertSame([], $this->historian->getFieldChanges($changed, ['nonexistent']));
    }

    public function testEmptyOnlyFieldsReportsAllFields(): void {
        $this->givenRecords([1 => ['a' => 1, 'b' => 1], 2 => ['a' => 2, 'b' => 1]]);
        $changed = $this->historian->getChangedRecords(self::PK);

        self::assertSame($this->historian->getFieldChanges($changed), $this->historian->getFieldChanges($changed, []));
    }

    public function testGetFieldChangesWorksOnHandBuiltRecords(): void {
        $changed = [
          3 => ['history_id' => 3, 'record' => ['a' => 1]],
          9 => ['history_id' => 9, 'record' => ['a' => 2, 'b' => 'x']],
        ];

        self::assertSame([
          'a' => ['added' => [3], 'modified' => [9], 'deleted' => []],
          'b' => ['added' => [9], 'modified' => [], 'deleted' => []],
        ], $this->historian->getFieldChanges($changed));
    }

    public function testGetFieldChangesDoesNotQueryTheDatabase(): void {
        $this->useMockSqlExecutor();
        $this->mockSqlExecutor->expects(self::never())->method('arrayKeyed');
        $this->mockSqlExecutor->expects(self::never())->method('query');

        $this->historian->getFieldChanges([1 => ['history_id' => 1, 'record' => ['a' => 1]]]);
    }

    // ------------------------------------------------------------------ both methods together

    public function testEveryKeptRowAppearsInSomeFieldChangeAndNoOtherIdDoes(): void {
        $this->givenRecords([
          1 => ['a' => 1, 'b' => 1, 'last_change' => 1],
          2 => ['a' => 1, 'b' => 1, 'last_change' => 2],
          3 => ['a' => 2, 'b' => 1, 'last_change' => 3],
          4 => ['a' => 2, 'b' => null, 'last_change' => 4],
          5 => ['a' => 2, 'b' => null, 'last_change' => 5],
          6 => ['a' => 3, 'b' => 7, 'last_change' => 6],
        ]);

        $changed = $this->historian->getChangedRecords(self::PK);
        $ids = [];
        foreach($this->historian->getFieldChanges($changed) as $field)
            foreach($field as $fieldIds)
                $ids = array_merge($ids, $fieldIds);
        $ids = array_values(array_unique($ids));
        sort($ids);

        self::assertSame([1, 3, 4, 6], array_keys($changed));
        self::assertSame(array_keys($changed), $ids);
    }

    public function testEveryKeptRowAppearsInSomeFieldChangeWithOnlyFields(): void {
        $this->givenRecords([
          1 => ['a' => 1, 'b' => 1],
          2 => ['a' => 2, 'b' => 1],
          3 => ['a' => 2, 'b' => 2],
          4 => ['a' => 3, 'b' => 2],
        ]);
        $onlyFields = ['b'];

        $changed = $this->historian->getChangedRecords(self::PK, $onlyFields);
        $ids = [];
        foreach($this->historian->getFieldChanges($changed, $onlyFields) as $field)
            foreach($field as $fieldIds)
                $ids = array_merge($ids, $fieldIds);
        $ids = array_values(array_unique($ids));
        sort($ids);

        self::assertSame([1, 3], array_keys($changed));
        self::assertSame([1, 3], $ids);
    }

    public function testFullScenarioOfARecordLifetime(): void {
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn([
          1 => self::row(1, ['name' => 'Chair', 'price' => 10, 'note' => null, 'last_change' => 'a'], 'insert'),
          2 => self::row(2, ['name' => 'Chair', 'price' => 10, 'note' => null, 'last_change' => 'b']),
          3 => self::row(3, ['name' => 'Chair', 'price' => 12, 'note' => 'sale', 'last_change' => 'c']),
          4 => self::row(4, ['name' => 'Armchair', 'price' => 12, 'note' => 'sale', 'last_change' => 'd']),
          5 => self::row(5, ['name' => 'Armchair', 'price' => 12, 'note' => null, 'last_change' => 'e']),
          6 => self::row(6, ['name' => 'Armchair', 'price' => 12, 'note' => null, 'last_change' => 'e'], 'delete'),
        ]);

        $changed = $this->historian->getChangedRecords(self::PK);
        $fields = $this->historian->getFieldChanges($changed);

        self::assertSame([1, 3, 4, 5], array_keys($changed));
        self::assertSame([
          'name' => ['added' => [1], 'modified' => [4], 'deleted' => []],
          'price' => ['added' => [1], 'modified' => [3], 'deleted' => []],
          'note' => ['added' => [3], 'modified' => [], 'deleted' => [5]],
        ], $fields);

        $priceOnly = $this->historian->getChangedRecords(self::PK, ['price']);
        self::assertSame([1, 3], array_keys($priceOnly));
        self::assertSame(
          ['price' => ['added' => [1], 'modified' => [3], 'deleted' => []]],
          $this->historian->getFieldChanges($priceOnly, ['price'])
        );

        $lastChange = $this->historian->getChangedRecords(self::PK, ['last_change']);
        self::assertSame([1, 2, 3, 4, 5], array_keys($lastChange));
    }
}
