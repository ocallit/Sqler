<?php /** @noinspection PhpIllegalPsrClassPathInspection */

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Ocallit\Sqler\ErrorLog;

require_once __DIR__ . '/../../vendor/autoload.php';

#[CoversClass(ErrorLog::class)]
class ErrorLogTest extends TestCase {

    protected function setUp(): void {
        $reflection = new ReflectionClass(ErrorLog::class);
        $errorsProp = $reflection->getProperty('errors');
      //  $errorsProp->setAccessible(true);
        $errorsProp->setValue([]);

        $keptProp = $reflection->getProperty('kept');
      //  $keptProp->setAccessible(true);
        $keptProp->setValue([]);
    }

    public function testLogWithThrowable(): void {
        $exception = new RuntimeException('Something broke', 42);
        ErrorLog::log($exception);

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_PHP, $error['error_type']);
        $this->assertSame(42, $error['error_code']);
        $this->assertStringContainsString('RuntimeException: Something broke', $error['error_message']);
        $this->assertSame(__FILE__, $error['file']);
    }

    public function testLogWithCustomException(): void {
        $customError = new class('Custom error', 99) extends Exception {};
        ErrorLog::log($customError);

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_PHP, $error['error_type']);
        $this->assertSame(99, $error['error_code']);
        $this->assertStringContainsString('Custom error', $error['error_message']);
    }

    public function testLogWithStringDefaultTypeInfo(): void {
        $line = __LINE__ + 1;
        ErrorLog::log('Info debug trace message');

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_INFO, $error['error_type']);
        $this->assertSame('Info debug trace message', $error['error_message']);
        $this->assertSame(__FILE__, $error['file']);
        $this->assertSame($line, $error['line_number']);
    }

    public function testLogWithStringDomainType(): void {
        $line = __LINE__ + 1;
        ErrorLog::log('Business rule broken', 'domain');

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_DOMAIN, $error['error_type']);
        $this->assertSame('Business rule broken', $error['error_message']);
        $this->assertSame(__FILE__, $error['file']);
        $this->assertSame($line, $error['line_number']);
        $this->assertSame(self::class . '->testLogWithStringDomainType', $error['function_name']);
    }

    public function testLogWithStringDomainConstantType(): void {
        ErrorLog::log('Domain violation', ErrorLog::TYPE_DOMAIN);

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_DOMAIN, $error['error_type']);
        $this->assertSame('Domain violation', $error['error_message']);
    }

    public function testLogWithStringableObject(): void {
        $stringable = new class implements Stringable {
            public function __toString(): string {
                return 'Stringable object message';
            }
        };

        ErrorLog::log($stringable, 'domain');

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_DOMAIN, $error['error_type']);
        $this->assertSame('Stringable object message', $error['error_message']);
    }

    public function testLogWithStringableObjectInfo(): void {
        $stringable = new class implements Stringable {
            public function __toString(): string {
                return 'Stringable info message';
            }
        };

        ErrorLog::log($stringable);

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_INFO, $error['error_type']);
        $this->assertSame('Stringable info message', $error['error_message']);
    }

    public function testLogWithUnrecognizedTypeDefaultsToInfo(): void {
        ErrorLog::log('Unknown type falls back to info', 'unknown_type');

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_INFO, $error['error_type']);
        $this->assertSame('Unknown type falls back to info', $error['error_message']);
    }

    public function testShutdownHandlerWithJsonLastError(): void {
        error_clear_last();
        @preg_match('/a/', 'a');
        json_decode('{"invalid": json}');
        $this->assertNotSame(JSON_ERROR_NONE, json_last_error());

        ErrorLog::shutdownHandler();

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_PHP, $error['error_type']);
        $this->assertSame(json_last_error(), $error['error_code']);
        $this->assertSame(json_last_error_msg(), $error['error_message']);
        $this->assertSame('json_last_error', $error['function_name']);

        json_decode('{}');
    }

    public function testShutdownHandlerWithPregLastError(): void {
        error_clear_last();
        json_decode('{}');
        @preg_match('/foo/u', "\xff");
        $this->assertNotSame(PREG_NO_ERROR, preg_last_error());

        ErrorLog::shutdownHandler();

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = array_values($errors)[0];
        $this->assertSame(ErrorLog::TYPE_PHP, $error['error_type']);
        $this->assertSame(preg_last_error(), $error['error_code']);
        $this->assertSame(preg_last_error_msg(), $error['error_message']);
        $this->assertSame('preg_last_error', $error['function_name']);

        @preg_match('/a/', 'a');
    }

    public function testSqlErrorLog(): void {
        $template = 'SELECT * FROM users WHERE id = ?';
        $query = 'SELECT * FROM users WHERE id = ?';
        $parameters = [42, 'admin'];
        $hash = hash('xxh3', $template);
        $expectedLine = __LINE__ + 11;
        $callerLine = $expectedLine + 20;
        $sqlErrorLog = [
            $hash => [
                'error' => 1054,
                'error_message' => "Unknown column 'admin' in 'where clause'",
                'query' => $query,
                'parameters' => $parameters,
                'attempt' => 1,
                'template' => $template,
                'stack_trace' => [
                  ['file' => __FILE__, 'line' => $expectedLine, 'class' => 'Ocallit\\Sqler\\SqlExecutor',
                    'type' => '->', 'function' => 'firstValue'],
                  ['file' => __FILE__, 'line' => $callerLine, 'class' => self::class,
                    'type' => '->', 'function' => 'testSqlErrorLog'],
                ],
            ],
        ];

        ErrorLog::sqlErrorLog($sqlErrorLog);

        $errors = ErrorLog::getErrors();
        $this->assertCount(1, $errors);

        $error = $errors[hash('xxh3', $template)];
        $this->assertSame(ErrorLog::TYPE_SQL, $error['error_type']);
        $this->assertSame(1054, $error['error_code']);
        $this->assertSame("Unknown column 'admin' in 'where clause'", $error['error_message']);
        $this->assertSame(__FILE__, $error['file']);
        $this->assertSame($expectedLine, $error['line_number']);
        $this->assertSame($query . PHP_EOL . ' -- (42, admin)', $error['query']);
        $this->assertSame(self::class . '->testSqlErrorLog', $error['function_name']);
        $this->assertSame(
          __FILE__ . ':' . $expectedLine . ' Ocallit\\Sqler\\SqlExecutor->firstValue()' . "\n" .
          __FILE__ . ':' . $callerLine . ' ' . self::class . '->testSqlErrorLog()', $error['content']);
    }

}
