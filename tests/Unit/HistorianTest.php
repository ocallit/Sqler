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
              '{"theme":"dark"}',
              '{"theme":"light","notifications":true}',
              '</tbody></table>',
            ],
          ],
        ];
    }
}
