<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\Historian;
use Ocallit\Sqler\SqlExecutor;

require_once __DIR__ . '/../../vendor/autoload.php';

/** setChildKeys(): the rows of a child list are matched by their own primary key, never by position */
#[CoversClass(Historian::class)]
class HistorianChildKeysTest extends TestCase {
    private Historian $historian;
    private SqlExecutor $mockSqlExecutor;

    protected function setUp(): void {
        $this->mockSqlExecutor = $this->createStub(SqlExecutor::class);
        $this->historian = new Historian($this->mockSqlExecutor, 'test_table');
        $this->historian->setChildKeys(['productos' => 'row_id']);
    }

    /** a child row as the application stores it: its own pk, orden, hash and a quantity; $product is the same product in several rows */
    private static function line(string $id, int $orden, string $cantidad, string $product = 'P'): array {
        return ['row_id' => $id, 'orden' => (string)$orden, 'hash' => md5($id . $cantidad), 'producto_id' => $product, 'cantidad' => $cantidad, 'ultimo_cambio' => 'when ' . $cantidad];
    }

    /** @param array<int, array> $records history_id => record, oldest first */
    private function givenRecords(array $records): void {
        $rows = [];
        foreach($records as $id => $record)
            $rows[$id] = ['history_id' => $id, 'action' => $id === array_key_first($records) ? 'insert' : 'update', 'motive' => '',
              'date' => sprintf('2026-09-07 10:%02d:00.000000', $id), 'user_nick' => 'tester', 'record' => json_encode($record)];
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn($rows);
    }

    private const PK = ['test_table_id' => 1];

    public function testMovedRowsOnlyIsNotAChange(): void {
        $this->givenRecords([
          1 => ['productos' => [self::line('a', 0, '5'), self::line('b', 1, '4')]],
          2 => ['productos' => [self::line('b', 0, '4'), self::line('a', 1, '5')]],
        ]);
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testOrdenAndHashAloneAreNotAChange(): void {
        $moved = self::line('a', 7, '5');
        $moved['hash'] = 'another';
        $this->givenRecords([1 => ['productos' => [self::line('a', 0, '5')]], 2 => ['productos' => [$moved]]]);
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testQuantityOfARowIsAChange(): void {
        $this->givenRecords([1 => ['productos' => [self::line('a', 0, '5')]], 2 => ['productos' => [self::line('a', 0, '6')]]]);
        $changed = $this->historian->getChangedRecords(self::PK);
        self::assertSame([1, 2], array_keys($changed));
        $fields = $this->historian->getFieldChanges($changed, ['productos.cantidad']);
        self::assertSame([2], $fields['productos.cantidad']['modified']);
    }

    public function testRowsWithTheSameProductAreTwoRowsAndOnlyTheEditedOneChanges(): void {
        $this->givenRecords([
          1 => ['productos' => [self::line('a', 0, '5'), self::line('b', 1, '4')]],
          2 => ['productos' => [self::line('a', 0, '5'), self::line('b', 1, '9')]],
        ]);
        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK), ['productos.cantidad']);
        self::assertSame([2], $fields['productos.cantidad']['modified']);
        self::assertSame([], $fields['productos.cantidad']['deleted']);
    }

    public function testDeletingTheFirstRowDoesNotShiftTheOthers(): void {
        $this->givenRecords([
          1 => ['productos' => [self::line('a', 0, '5'), self::line('b', 1, '4'), self::line('c', 2, '3')]],
          2 => ['productos' => [self::line('b', 0, '4'), self::line('c', 1, '3')]],
        ]);
        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK), ['productos.cantidad']);
        self::assertSame([2], $fields['productos.cantidad']['deleted']);
        self::assertSame([], $fields['productos.cantidad']['modified']);
    }

    public function testInsertingARowInTheMiddleIsOnlyThatRowAdded(): void {
        $this->givenRecords([
          1 => ['productos' => [self::line('a', 0, '5'), self::line('b', 1, '4')]],
          2 => ['productos' => [self::line('a', 0, '5'), self::line('c', 1, '9'), self::line('b', 2, '4')]],
        ]);
        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK), ['productos.cantidad']);
        self::assertSame([1, 2], $fields['productos.cantidad']['added']); // the first save adds every row, the second only c
        self::assertSame([], $fields['productos.cantidad']['modified']);
    }

    public function testAnEmptyListMeansAllRowsWereDeleted(): void {
        $this->givenRecords([1 => ['productos' => [self::line('a', 0, '5'), self::line('b', 1, '4')]], 2 => ['productos' => []]]);
        $fields = $this->historian->getFieldChanges($this->historian->getChangedRecords(self::PK), ['productos.cantidad']);
        self::assertSame([2], $fields['productos.cantidad']['deleted']);
    }

    public function testNewRowsNotChangeAlsoMatchesByTheRowKey(): void {
        $this->historian->setNewRowsNotChange(['productos']);
        $this->givenRecords([
          1 => ['productos' => [self::line('a', 0, '5')]],
          2 => ['productos' => [self::line('a', 0, '5'), self::line('b', 1, '4')]],
        ]);
        self::assertSame([1], array_keys($this->historian->getChangedRecords(self::PK)));
    }

    public function testACollectionWhereARowHasNoKeyIsComparedAsItComes(): void {
        $this->historian->setChildKeys(['productos' => 'missing_key']);
        $this->givenRecords([
          1 => ['productos' => [self::line('a', 0, '5'), self::line('b', 1, '4')]],
          2 => ['productos' => [self::line('b', 0, '4'), self::line('a', 1, '5')]],
        ]);
        self::assertSame([1, 2], array_keys($this->historian->getChangedRecords(self::PK)));
    }
}
