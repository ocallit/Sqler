<?php
/**
 * error_log api feature, one action, log, for the js/errorLog.js client
 *
 * Copy to private/api/error_log/inc/ErrorLogApi.php. Named ErrorLogApi so that it does not
 * collide with Ocallit\Sqler\ErrorLog, which does the work.
 */

use Ocallit\Sqler\ErrorLog;
use Ocallit\Sqler\SqlExecutor;

final class ErrorLogApi {
    public function __construct(
      private readonly SqlExecutor $db,
      private readonly array $user,
    ) {}

    /**
     * Stores one javascript error
     *
     * ErrorLog::javascriptErrors() makes the hash that is stored, xxh3 of
     * file|line|JS|error_code, the hash the browser made is its own guard against posting
     * the same error twice and is neither sent nor used here.
     *
     * @param array $input $_POST
     * @return array
     */
    public function actionLog(array $input): array {
        $errorMessage = (string)($input['error_message'] ?? '');
        if($errorMessage === '')
            return apiError(422, 'error_message is required.');
        ErrorLog::javascriptErrors([[
          'error_code' => (string)($input['error_code'] ?? ''),
          'error_message' => $errorMessage,
          'file' => (string)($input['file'] ?? ''),
          'line_number' => (int)($input['line_number'] ?? 0),
          'column_number' => (int)($input['column_number'] ?? 0),
          'function_name' => (string)($input['function_name'] ?? ''),
          'content' => (string)($input['content'] ?? ''),
          'request_uri' => (string)($input['request_uri'] ?? ''),
        ]]);
        return apiOk();
    }
}
