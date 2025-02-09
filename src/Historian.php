<?php
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;
use function array_diff;
use function array_key_exists;
use function array_merge;
use function count;
use function implode;
use function is_array;

/**
 * Audit of changes to a record
 *
 * @usage
 *   $history = new Historian($gSqlClass, 'tabla');
 *   Insert: insert a row, select * the row, $history->register('insert', $pk, $values, $userNick, 'optional motive...');
 *   Update: update a row, select * the row, $history->register('update', $values, $userNick, 'optional motive...');
 *   Delete: select * the row, delete row, $history->register('delete', $values, $userNick, 'optional motive...');
 *   Tip: read and add child relations in 'items' or 'product' keys, also register changes to childs only
 *
 *   $changes = $history->getAllChanges($primaryKeyValues)
 *   $lastChange = $history->getLastChange($primaryKeyValues);
 */
class Historian {
    protected array $ingoreDifferenceForFields = [
      'ultimo_cambio', 'ultimo_cambio_por',
      'last_changed', 'last_changed_by',
      'last_change', 'last_change_by'
    ];

    protected string $jsonSqlType = 'JSON';
    protected string $table;
    protected string $tableHistory;
    protected array $pk;
    protected SqlExecutor $sqlExecutor;
    protected QueryBuilder $queryBuilder;

    public function __construct(SqlExecutor $sqlExecutor, string $table, array $primaryKeyFieldNames = [], array $ingoreDifferenceForFields = []) {
        $this->sqlExecutor = $sqlExecutor;
        $this->queryBuilder = new QueryBuilder();
        
        $this->table = $table;
        $this->tableHistory = $table . '_hist';
        if(count($primaryKeyFieldNames) > 0)
            $this->pk = $primaryKeyFieldNames;
        else
            $this->pk = [$table . '_id'];
        $this->ingoreDifferenceForFields = array_merge($this->ingoreDifferenceForFields, $ingoreDifferenceForFields);
    }

    public function setIngoreDifferenceForFields(array $ingoreDifferenceForFields): void {
        $this->ingoreDifferenceForFields = $ingoreDifferenceForFields;
    }

    /**
     * @param string $action
     * @param array $pk
     * @param array $values
     * @param string $user_nick
     * @param string $motive
     * @return void
     */
    public function register(string $action, array $pk, array $values, string $user_nick = '',  string $motive = ''):void {
        $insertValues = [
          'action' => $action,
          'motive' => $motive,
          'pk' => $this->primaryKeyEncode($pk),
          'record' => json_encode($values, SqlUtils::JSON_MYSQL_OPTIONS),
          'user_nick' => empty($user_nick) ? ($_SESSION['nick'] ?? $_SESSION['usuario'] ?? '?') : $user_nick,
          'date' => 'NOW(6)',
          'date' => 'NOW(6)'
        ];
        $this->queryBuilder->insert($this->tableHistory, $insertValues);
        $insertHistorySql = $this->queryBuilder->insert($this->tableHistory, $insertValues);
        try {
            $this->sqlExecutor->query($insertHistorySql['query'], $insertHistorySql['parameters']);
            return;
        } catch (Exception) { }
        if($this->sqlExecutor->is_last_error_table_not_found())
            try {
                $this-> historyTableCreate();
                $this->sqlExecutor->query($insertHistorySql['query'], $insertHistorySql['parameters']);
            } catch (Exception) {}
    }

    /**
     * @param array $primaryKeyValues
     * @param int|string $offset
     * @param int|string $rows
     * @return array<array{
     *    history_id: int,
     *    action: string,
     *    motive: string,
     *    date: string,
     *    user_nick: string,
     *    diff: array<string, array{before: string|array, after: string|array}>,
     *    record: array<string, string|array>
     * } diff's key is the changed fieldName and record is keyValue pair of fieldName value
     * @throws Exception
     */
    public function getChanges(array $primaryKeyValues, int|string $offset = 0, int|string $rows = 100 ):array {
        $params = [ 
          $this->primaryKeyEncode($primaryKeyValues),
          $offset,
          $rows  
        ];
        $method = __METHOD__;
        $sql = "SELECT /*$method*/ history_id, `action`, `date`, `user_nick`, `record`, `motive` 
            FROM $this->tableHistory 
            WHERE `pk` = ?
            ORDER BY `date` DESC, history_id DESC LIMIT ?, ?";

        return
          $this->diff($this->sqlExecutor->arrayKeyed($sql, 'history_id', $params));
    }

    /**
     * @param array $primaryKeyValues
     * @param int $numEntries
     * @return array<array{
     *      history_id: int,
     *      action: string,
     *      motive: string,
     *      date: string,
     *      user_nick: string,
     *      diff: array<string, array{before: string|array, after: string|array}>,
     *      record: array<string, string|array>
     *   } diff's key is the changed fieldName and record is keyValue pair of fieldName value
 * @throws Exception
     */
    public function getNLastChanges(array $primaryKeyValues, int $numEntries =  7 ):array {
        return $this->getChanges($primaryKeyValues, '', "LIMIT $numEntries");
    }

    /**
     * @param array $primaryKeyValues
     * @return array<array{
     *      history_id: int,
     *      action: string,
     *      motive: string,
     *      date: string,
     *      user_nick: string,
     *      diff: array<string, array{before: string|array, after: string|array}>,
     *      record: array<string, string|array>
     *   } diff's key is the changed fieldName and record is keyValue pair of fieldName value
 * @throws Exception
     */
    public function getLastChange(array $primaryKeyValues):array {
        return  $this->getNLastChanges($primaryKeyValues, 2);
    }

    /**
     * @param array $changes
     * @return string HTML table representing the changes history.
     */
    public function changesAsHTML(array $changes): string {
        $html = '<table class="laTabla">' . '<tbody>';
        foreach ($changes as $change) {
            if(empty($change['diff']))
                continue;
            $html .= '<tr>' . '<td class="cen">' . $change['date'] . '<br>' . $change['action'] . '<br>' . $change['user_nick'] .
                '<td><table><thead><tr><th> <th>Era<th>Cambio A:</tr></thead><tbody>';
                foreach ($change['diff'] as $field => $diffData) {
                    $before = is_array($diffData['before']) ? json_encode($diffData['before']) : $diffData['before'];
                    $after = is_array($diffData['after']) ? json_encode($diffData['after']) : $diffData['after'];
                    $html .= '<tr><td>' . SqlUtils::toLabel($field) . '<td>' . $before . '<td>' . $after . '</table>';
                }
        }
        return  $html . '</tbody></table>';
    }

    protected function primaryKeyEncode(array $values):string {
        $pkValues = [];
        foreach($this->pk as $pk)
            if(array_key_exists($pk, $values))
                $pkValues[] = $values[$pk];
        return implode("\t" ,$pkValues);
    }

    protected function differ(array $before, array $after):array {
        $diff = [];
        $keysToCheck = array_diff(
          array_merge(array_keys($before), array_keys($after)),
    $this->ingoreDifferenceForFields
        );
        foreach($keysToCheck as $key) {
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;
            if(is_array($beforeValue) && is_array($afterValue))
                $diff[$key] = $this->differ($beforeValue, $afterValue);
            elseif($beforeValue !== $afterValue)
                $diff[$key] = ['before' => $beforeValue, 'after' => $afterValue];
        }
        return $diff;
    }

    protected function diff(array $recordHistory):array {
        if(count($recordHistory) < 2)
            return [];
        $diff = [];
        for($i = 0, $len = count($recordHistory) -1; $i < $len; ++$i) {
            $h = $recordHistory[$i];
            if($i === 0) {
                $differ = [];
            } else {
                $differ = $this->differ($recordHistory[$i-1]['record'], $h['record']);
                if(!empty($differ))
                    continue;
            }
            $diff[$i] = [
                'history_id' => $h['history_id'],
                'action' => $h['action'],
                'motive' => $h['motive'],
                'date' => $h['date'],
                'user_nick' => $h['user_nick'],
                'diff' => $differ,
                'record' => $h['record'],
            ];
        }
        return $diff;
    }

    /**
     * Create changes table if it doesn't exist
     * 
     * @return void
     * @throws Exception
     */
    protected function historyTableCreate(): void {
        $method = __METHOD__;
        $this->sqlExecutor->query( "
        CREATE /* $method */ TABLE IF NOT EXISTS $this->tableHistory (
            `history_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `action` VARCHAR(32) NOT NULL,
            `motive` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Optional short description of change',
            `pk` VARCHAR(191) NOT NULL COMMENT 'filed names tab separated, same order as in definition',
            `record` $this->jsonSqlType,
            `user_nick` VARCHAR(32) COMMENT 'change by',
            `date` DATETIME(6) NOT NULL,
            KEY perRecord(pk, `date` DESC)
        )");
    }

}
