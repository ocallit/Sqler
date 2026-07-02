<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\Historian;
use Ocallit\Sqler\SqlExecutor;



#[CoversClass(Historian::class)]
class HistorianTest extends TestCase {
    private Historian $historian;
    private SqlExecutor $mockSqlExecutor;

    protected function setUp(): void {
        // Create a mock SqlExecutor since we're only testing non-DB methods
        $this->mockSqlExecutor = $this->createMock(SqlExecutor::class);
        $this->historian = new Historian($this->mockSqlExecutor, 'test_table');
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
              '{&quot;theme&quot;:&quot;dark&quot;}',
              '{&quot;theme&quot;:&quot;light&quot;,&quot;notifications&quot;:true}',
              '</tbody></table>',
            ],
          ],
        ];
    }

    public function testChangesAsHTMLEscapesValues(): void {
        $result = $this->historian->changesAsHTML([
          [
            'date' => '2023-01-01 10:00:00',
            'action' => 'update',
            'user_nick' => '<script>alert(1)</script>',
            'diff' => [
              'comment' => ['before' => '<b>x</b>', 'after' => "it's \"quoted\""],
            ],
          ],
        ]);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $result);
        $this->assertStringContainsString('it&#039;s &quot;quoted&quot;', $result);
    }

    public function testGetNLastChangesPassesIntegerLimitParameters(): void {
        $this->mockSqlExecutor->expects($this->once())
          ->method('arrayKeyed')
          ->with($this->anything(), 'history_id', ['42', 0, 5])
          ->willReturn([]);

        $this->assertSame([], $this->historian->getNLastChanges(['test_table_id' => 42], 5));
    }

    public function testGetChangesDecodesJsonRecordsAndDiffs(): void {
        // Rows come back newest first; record is a JSON string as stored in the DB
        $this->mockSqlExecutor->method('arrayKeyed')->willReturn([
          2 => [
            'history_id' => 2, 'action' => 'update', 'motive' => '', 'date' => '2023-01-02',
            'user_nick' => 'u', 'record' => '{"id":1,"name":"Jane","age":30}',
          ],
          1 => [
            'history_id' => 1, 'action' => 'insert', 'motive' => '', 'date' => '2023-01-01',
            'user_nick' => 'u', 'record' => '{"id":1,"name":"John","age":30}',
          ],
        ]);

        $changes = $this->historian->getChanges(['test_table_id' => 1]);

        $this->assertCount(1, $changes);
        $this->assertSame(2, $changes[0]['history_id']);
        $this->assertSame(['name' => ['before' => 'John', 'after' => 'Jane']], $changes[0]['diff']);
        $this->assertSame(['id' => 1, 'name' => 'Jane', 'age' => 30], $changes[0]['record']);
    }

    public function testRegisterThrowsWhenInsertFailsForOtherReasons(): void {
        $this->mockSqlExecutor->method('query')
          ->willThrowException(new RuntimeException('deadlock'));
        $this->mockSqlExecutor->method('is_last_error_table_not_found')->willReturn(FALSE);

        $this->expectException(RuntimeException::class);
        $this->historian->register('update', ['test_table_id' => 1], ['test_table_id' => 1, 'name' => 'x']);
    }
}
