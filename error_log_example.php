<?php
/** @noinspection PhpUnused */
/** @noinspection PhpRedundantOptionalArgumentInspection */

require_once __DIR__ . '/vendor/autoload.php';

use Ocallit\Sqler\ErrorLog;
use Ocallit\Sqler\SqlExecutor;

$gSqlExecutor = new SqlExecutor(
  connect: [
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => 'teisha',
    'database' => 'zihuarooms',
      // 'port'  => 3306,
  ],
  charset:   'utf8mb4',
  collation: 'utf8mb4_0900_as_cs'
);

ErrorLog::initialize($gSqlExecutor, 'tester');

function tasl($gSqlExecutor) {
    try {
        $gSqlExecutor->firstValue("SELECT * FROM users WHERE id = ? and ta='asdr'", ['id' => 42]);
    } catch(Throwable $t) {

    }
}
tasl($gSqlExecutor);
echo "<li>def class";
class baba {
    public function taka() {return $this->taka2();}
    public function taka2() {$a = $vb/0; return "this->taka2();";}
}
echo "<li>create";
$baba = new baba();
try {
    echo "call";
    $baba->taka();
} catch(Throwable $t) {
    ErrorLog::log($t);
}
