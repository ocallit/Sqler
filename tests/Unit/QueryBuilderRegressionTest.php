<?php

use Ocallit\Sqler\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

#[CoversClass(QueryBuilder::class)]
class QueryBuilderRegressionTest extends TestCase {
    public function testNullConditionUsesIsNullWithoutAParameter(): void {
        $result = (new QueryBuilder())->where(['deleted_at' => null], comment: 'regression');

        self::assertSame(' /*regression*/ (`deleted_at` IS NULL)', $result['query']);
        self::assertSame([], $result['parameters']);
    }

    public function testNullConditionPreservesOtherParameterOrder(): void {
        $result = (new QueryBuilder())->where(
            ['status' => 'active', 'deleted_at' => null, 'tenant_id' => 7],
            comment: 'regression'
        );

        self::assertSame(
            ' /*regression*/ (`status`=? AND `deleted_at` IS NULL AND `tenant_id`=?)',
            $result['query']
        );
        self::assertSame(['active', 7], $result['parameters']);
    }

    public function testUpdateUsesIsNullInWhereButBindsNullInSet(): void {
        $result = (new QueryBuilder())->update(
            'users',
            ['nickname' => null],
            ['deleted_at' => null, 'id' => 7],
            comment: 'regression'
        );

        self::assertStringContainsString('SET `nickname`=?', $result['query']);
        self::assertStringContainsString('(`deleted_at` IS NULL AND `id`=?)', $result['query']);
        self::assertSame([null, 7], $result['parameters']);
    }

    #[DataProvider('upsertSyntaxProvider')]
    public function testUpsertUsesOnlyTheSelectedSyntax(bool $useNew): void {
        $result = (new QueryBuilder(useNewOnDuplicate: $useNew))->insert(
            'users',
            ['id' => 7, 'name' => 'Alice'],
            onDuplicateKeyUpdate: true,
            comment: 'regression'
        );

        self::assertSame([7, 'Alice'], $result['parameters']);
        self::assertStringContainsString('VALUES(?,?)', $result['query']);
        if($useNew) {
            self::assertMatchesRegularExpression('/\bAS\s+new\s+ON DUPLICATE KEY UPDATE\b/i', $result['query']);
            self::assertStringContainsString('`name`=new.`name`', $result['query']);
            self::assertStringNotContainsString('VALUES(`name`)', $result['query']);
        } else {
            self::assertStringContainsString('`name`=VALUES(`name`)', $result['query']);
            self::assertStringNotContainsString('new.`', $result['query']);
            self::assertDoesNotMatchRegularExpression('/\bAS\s+new\b/i', $result['query']);
        }
    }

    public static function upsertSyntaxProvider(): array {
        return ['row alias' => [true], 'legacy VALUES' => [false]];
    }

    #[DataProvider('exclusionFormatsProvider')]
    public function testExclusionsKeepInsertValuesButOmitDuplicateAssignments(bool $useNew, array $excluded): void {
        $result = (new QueryBuilder(useNewOnDuplicate: $useNew))->insert(
            'users',
            ['name' => 'Alice', 'created_at' => '2026-09-07'],
            onDuplicateKeyUpdate: true,
            onDuplicateKeyDontUpdate: $excluded,
            comment: 'regression'
        );

        self::assertSame(['Alice', '2026-09-07'], $result['parameters']);
        self::assertStringContainsString('(`name`,`created_at`)', $result['query']);
        $assignments = explode('ON DUPLICATE KEY UPDATE ', $result['query'])[1];
        self::assertSame($useNew ? '`name`=new.`name`' : '`name`=VALUES(`name`)', $assignments);
    }

    public static function exclusionFormatsProvider(): array {
        return [
            'modern list' => [true, ['created_at']],
            'legacy list' => [false, ['created_at']],
            'modern keyed set' => [true, ['created_at' => true]],
            'legacy keyed set' => [false, ['created_at' => true]],
            'keyed false still excludes' => [true, ['created_at' => false]],
            'keyed null still excludes' => [false, ['created_at' => null]],
        ];
    }
}
