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
 * Historian Quick Reference (Audit Trail):
 *
 * Setup:
 *   $hist = new Historian($sql, 'tablename', ['pk_col'], ['ignore_col1', ...])
 *
 * Recording (always pass fresh SELECT * row):
 *   INSERT: insert → select → register('insert', $pk, $row, $user, $motive?)
 *   UPDATE: update → select → register('update', $pk, $row, $user, $motive?)
 *   DELETE: select → delete → register('delete', $pk, $row, $user, $motive?)
 *
 * Retrieval:
 *   - getChanges($pk, $offset=0, $limit=100): array of {history_id, action, date, user_nick, diff, record}
 *   - getNLastChanges($pk, $n=7): last N changes
 *   - getLastChange($pk): last 2 changes
 *   - changesAsHTML($changes): string
 *
 * Auto-ignored fields: ultimo_cambio, ultimo_cambio_por, last_changed, last_changed_by, last_change, last_change_by
 * Creates {table}_hist automatically on first write.
 */
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
    public static string $SessionNickKey = 'nick';
    protected array $ignoreDifferenceForFields = [
      'ultimo_cambio', 'ultimo_cambio_por',
      'last_changed', 'last_changed_by',
      'last_change', 'last_change_by'
    ];

    protected string $jsonSqlType = 'JSON';
    protected string $table;
    protected string $tableHistory;
    protected array $primaryKeys;
    protected SqlExecutor $sqlExecutor;
    protected QueryBuilder $queryBuilder;

    public function __construct(SqlExecutor $sqlExecutor, string $table, array $primaryKeyFieldNames = [], array $ingoreDifferenceForFields = []) {
        $this->sqlExecutor = $sqlExecutor;
        $this->queryBuilder = new QueryBuilder();
        
        $this->table = $table;
        $this->tableHistory = $table . '_hist';
        if(count($primaryKeyFieldNames) > 0)
            $this->primaryKeys = $primaryKeyFieldNames;
        else
            $this->primaryKeys = [$table . '_id'];
        $this->ignoreDifferenceForFields = array_merge($this->ignoreDifferenceForFields, $ingoreDifferenceForFields);
    }

    public function setIgnoreDifferenceForFields(array $ignoreDifferenceForFields): void {
        $this->ignoreDifferenceForFields = $ignoreDifferenceForFields;
    }

    /**
     * @param string $action
     * @param array $primaryKeys
     * @param array $values
     * @param string $userNick
     * @param string $motive
     * @return void
     * @throws Exception when the audit row cannot be written: an audit trail must not fail silently
     */
    public function register(string $action, array $primaryKeys, array $values, string $userNick = '', string $motive = ''):void {
        $insertValues = [
          'action' => $action,
          'motive' => $motive,
          'pk' => $this->primaryKeyEncode($primaryKeys),
          'record' => json_encode($values, SqlUtils::JSON_MYSQL_OPTIONS),
          'user_nick' => empty($userNick) ? ($_SESSION[self::$SessionNickKey] ?? '?') : $userNick,
          'date' => 'NOW(6)'
        ];

        $insertHistorySql = $this->queryBuilder->insert($this->tableHistory, $insertValues);
        try {
            $this->sqlExecutor->query($insertHistorySql['query'], $insertHistorySql['parameters']);
            return;
        } catch (Exception $e) {
            if(!$this->sqlExecutor->is_last_error_table_not_found())
                throw $e;
        }
        $this->historyTableCreate();
        $this->sqlExecutor->query($insertHistorySql['query'], $insertHistorySql['parameters']);
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
        return $this->getChanges($primaryKeyValues, 0, $numEntries);
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
     * @return string HTML table representing the change's history.
     */
    public function changesAsHTML(array $changes): string {
        $html = '<table class="laTabla"><tbody>';
        foreach ($changes as $change) {
            if(empty($change['diff']))
                continue;
            $html .= '<tr><td class="cen">' . $this->e($change['date']) . '<br>' . $this->e($change['action']) .
                '<br>' . $this->e($change['user_nick']) . '</td>' .
                '<td><table><thead><tr><th></th><th>Era</th><th>Cambio A:</th></tr></thead><tbody>';
            foreach ($change['diff'] as $field => $diffData) {
                $before = is_array($diffData['before']) ? json_encode($diffData['before'], SqlUtils::JSON_MYSQL_OPTIONS) : $diffData['before'];
                $after = is_array($diffData['after']) ? json_encode($diffData['after'], SqlUtils::JSON_MYSQL_OPTIONS) : $diffData['after'];
                $html .= '<tr><td>' . $this->e(SqlUtils::toLabel($field)) . '</td><td>' . $this->e($before) .
                    '</td><td>' . $this->e($after) . '</td></tr>';
            }
            $html .= '</tbody></table></td></tr>';
        }
        return  $html . '</tbody></table>';
    }

    protected function e(mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    protected function primaryKeyEncode(array $values):string {
        $pkValues = [];
        foreach($this->primaryKeys as $primaryKey)
            if(array_key_exists($primaryKey, $values))
                $pkValues[] = $values[$primaryKey];
        return implode("\t" ,$pkValues);
    }

    protected function differ(array $before, array $after):array {
        $diff = [];
        $keysToCheck = array_diff(
          array_merge(array_keys($before), array_keys($after)),
    $this->ignoreDifferenceForFields
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

    protected function diff(array $recordHistory): array {
        $records = array_values($recordHistory);  // Reindex for sequential access
        foreach($records as &$record) {
            // record is stored as JSON; differ() needs the decoded array
            if(is_string($record['record'] ?? null))
                $record['record'] = json_decode($record['record'], true) ?? [];
        }
        unset($record);
        $count = count($records);
        if($count < 2)
            return [];

        $diff = [];
        for($i = 0; $i < $count; ++$i) {
            $current = $records[$i];
            $older = $records[$i + 1] ?? null;

            $differ = ($older !== null)
              ? $this->differ($older['record'], $current['record'])
              : [];

            if(empty($differ))
                continue;

            $diff[] = [
              'history_id' => $current['history_id'],
              'action' => $current['action'],
              'motive' => $current['motive'],
              'date' => $current['date'],
              'user_nick' => $current['user_nick'],
              'diff' => $differ,
              'record' => $current['record'],
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
