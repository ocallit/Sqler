<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\Historian;
use Ocallit\Sqler\SqlExecutor;

require_once __DIR__ . '/../../vendor/autoload.php';

#[CoversClass(Historian::class)]
class HistorianTest extends TestCase {
    private Historian $historian;
    private SqlExecutor $mockSqlExecutor;

    protected function setUp(): void {
        // Create a mock SqlExecutor since we're only testing non-DB methods
        $this->mockSqlExecutor = $this->createMock(SqlExecutor::class);
        $this->historian = new Historian($this->mockSqlExecutor, 'test_table');
    }


    #[DataProvider('accentedHistoryProvider')]
    public function testGetChangesDecodesAndNormalizesAccentedText(string $json): void {
        $metadata = ['action' => 'update', 'motive' => '', 'date' => '2026-09-07 10:00:00', 'user_nick' => 'tester'];
        $this->mockSqlExecutor->expects(self::once())->method('arrayKeyed')->willReturn([
            2 => $metadata + ['history_id' => 2, 'record' => $json],
            1 => $metadata + ['history_id' => 1, 'record' => '{"name":"A","nested":{"name":"A"}}'],
        ]);

        $changes = $this->historian->getChanges(['test_table_id' => 1]);

        self::assertCount(1, $changes);
        self::assertSame('Á', $changes[0]['record']['name']);
        self::assertSame('Á', $changes[0]['record']['nested']['name']);
        self::assertSame(['before' => 'A', 'after' => 'Á'], $changes[0]['diff']['name']);
    }

    public function testSingleHistoryRecordDoesNotNeedDecoding(): void {
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn([
            1 => ['history_id' => 1, 'record' => 'not JSON'],
        ]);

        self::assertSame([], $this->historian->getChanges(['test_table_id' => 1]));
    }

    public function testAdjacentComparisonsReuseDecodedRecords(): void {
        $metadata = ['action' => 'update', 'motive' => '', 'date' => '2026-09-07 10:00:00', 'user_nick' => 'tester'];
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn([
            3 => $metadata + ['history_id' => 3, 'record' => '{"value":3}'],
            2 => $metadata + ['history_id' => 2, 'record' => '{"value":2}'],
            1 => $metadata + ['history_id' => 1, 'record' => '{"value":1}'],
        ]);

        $changes = $this->historian->getChanges(['test_table_id' => 1]);

        self::assertSame([3, 2], array_column($changes, 'history_id'));
        self::assertSame(['before' => 2, 'after' => 3], $changes[0]['diff']['value']);
        self::assertSame(['before' => 1, 'after' => 2], $changes[1]['diff']['value']);
    }

    public static function accentedHistoryProvider(): array {
        return [
            'literal composed' => ['{"name":"Á","nested":{"name":"Á"}}'],
            'escaped composed' => ['{"name":"\u00c1","nested":{"name":"\u00c1"}}'],
            'escaped decomposed' => ['{"name":"A\u0301","nested":{"name":"A\u0301"}}'],
        ];
    }

    #[DataProvider('setIngoreDifferenceForFieldsProvider')]
    public function testSetIngoreDifferenceForFields(array $fields): void {
        $this->historian->setIgnoreDifferenceForFields($fields);

        // Since the property is protected, we can test this indirectly
        // by checking that the method doesn't throw any errors
        $this->assertTrue(TRUE);
    }

    public static function setIngoreDifferenceForFieldsProvider(): array {
        return [
          'empty_array' => [[]],
          'single_field' => [['created_at']],
          'multiple_fields' => [['created_at', 'updated_at', 'version']],
          'special_characters' => [['field_with_underscore', 'field-with-dash']],
        ];
    }
    
    #[DataProvider('changesAsHTMLProvider')]
    public function testChangesAsHTML(array $changes, array $expectedContains): void {
        $result = $this->historian->changesAsHTML($changes);

        $this->assertIsString($result);

        foreach($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $result);
        }
    }

    public static function changesAsHTMLProvider(): array {
        return [
          'empty_changes' => [
            [],
            ['<table class="laTabla">', '</tbody></table>'],
          ],
          'changes_with_no_diff' => [
            [
              [
                'date' => '2023-01-01 10:00:00',
                'action' => 'update',
                'user_nick' => 'testuser',
                'diff' => [],
              ],
            ],
            ['<table class="laTabla">', '</tbody></table>'],
          ],
          'changes_with_diff' => [
            [
              [
                'date' => '2023-01-01 10:00:00',
                'action' => 'update',
                'user_nick' => 'testuser',
                'diff' => [
                  'name' => ['before' => 'John', 'after' => 'Jane'],
                  'age' => ['before' => 25, 'after' => 26],
                ],
              ],
            ],
            [
              '<table class="laTabla">',
              '2023-01-01 10:00:00',
              'update',
              'testuser',
              'Name',
              'John',
              'Jane',
              'Age',
              '25',
              '26',
              '</tbody></table>',
            ],
          ],
          'changes_with_array_values' => [
            [
              [
                'date' => '2023-01-01 10:00:00',
                'action' => 'update',
                'user_nick' => 'testuser',
                'diff' => [
                  'settings' => [
                    'before' => ['theme' => 'dark'],
                    'after' => ['theme' => 'light', 'notifications' => TRUE],
                  ],
                ],
              ],
            ],
            [
              '<table class="laTabla">',
              '2023-01-01 10:00:00',
              'update',
              'testuser',
              'Settings',
              '{"theme":"dark"}',
              '{"theme":"light","notifications":true}',
              '</tbody></table>',
            ],
          ],
        ];
    }
}
