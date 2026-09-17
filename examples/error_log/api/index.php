<?php
/**
 * Endpoint for the ErrorLog javascript client, js/errorLog.js posts action=log here
 *
 * Copy to public_html/error_log/api/index.php, adjust the dirname depth to where private/
 * lives in the project.
 *
 * Decide whether bootstrap() must authenticate here: an authenticated endpoint loses the
 * errors of every page a logged out visitor sees, which are the ones nobody else reports.
 * The DoS check bootstrap() runs first is what keeps the endpoint from being flooded, the
 * client posts at most 4 errors per page load, and ErrorLog::$maxPerType caps what is stored.
 */

use Ocallit\Sqler\ErrorLog;

define('PRIVATE_PATH', dirname(__DIR__, 3) . '/private');
require PRIVATE_PATH . '/vendor/autoload.php';
require_once PRIVATE_PATH . '/api/common/respond.php';
require_once PRIVATE_PATH . '/api/common/bootstrap.php';
require_once PRIVATE_PATH . '/api/error_log/inc/ErrorLogApi.php';

['db' => $db, 'user' => $user] = bootstrap();
ErrorLog::initialize($db, $user['nick'] ?? '');
$feature = new ErrorLogApi($db, $user);

try {
    $result = match($_POST['action'] ?? null) {
        'log' => $feature->actionLog($_POST),
        default => apiError(400, 'Unknown action.'),
    };
} catch(Throwable $e) {
    $result = apiError(500, 'Something went wrong.', $e);
}

// respond() exits, the shutdown function ErrorLog::initialize() registered still runs and
// writes what actionLog() collected
respond($result);
