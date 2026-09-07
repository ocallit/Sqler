<?php

use Ocallit\Sqler\SqlExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

#[CoversClass(SqlExecutor::class)]
class SqlExecutorResultShapeTest extends TestCase {
    public function testMultiKeyLastPreservesDistinctPathsSharingAPrefix(): void {
        // Execute the real SqlExecutor with result doubles in a process without mysqli.
        $process = proc_open(
            [PHP_BINARY, '-n', __DIR__ . '/../Fixtures/multi_key_shared_prefix.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);
        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        // A/B/C and A/B/D are distinct paths, not duplicate keys to overwrite.
        // Check paths only: the methods intentionally have different leaf shapes.
        self::assertSame(['C', 'D'], array_keys($results['multiKey']['A']['B']));
        self::assertSame(
            ['C', 'D'],
            array_keys($results['multiKeyLast']['A']['B']),
            'Adding A/B/D must not erase the existing A/B/C path.'
        );
        self::assertSame(
            ['A' => ['B' => ['C' => 1, 'D' => 3], 'D' => ['C' => 4]]],
            $results['multiKeyLast'],
            'Leaves must be scalar values, with the last row winning for duplicate paths.'
        );
    }
}
