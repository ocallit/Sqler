<?php
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use Exception;
use InvalidArgumentException;
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
 *   - getChangedRecords($pk, $onlyFields=[]): full rows that changed, keyed by history_id, oldest first
 *   - getFieldChanges($changedRecords, $onlyFields=[]): fieldName => [added=>[ids], modified=>[ids], deleted=>[ids]]
 *   - changesAsHTML($changes, $fieldFormat=[]): string, $fieldFormat per field: class, label, fieldPrefix/Suffix, valuePrefix/Suffix, child
 *   - $onlyFields entries may be paths into child lines: 'items.price'
 *
 * Values: SQL returns strings (numbers, dates, datetimes, times) and they must stay strings: register() stores values
 *   as given, nothing is converted when reading; JSON_BIGINT_AS_STRING is the only number flag json_decode offers.
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
    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
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
     */
    public function register(string $action, array $primaryKeys, array $values, string $userNick = '', string $motive = ''):void {
        $insertValues = [
          'action' => $action,
          'motive' => $motive,
          'pk' => $this->primaryKeyEncode($primaryKeys),
          'record' => json_encode($values, SqlUtils::JSON_MYSQL_OPTIONS | JSON_THROW_ON_ERROR),
          'user_nick' => empty($userNick) ? ($_SESSION[self::$SessionNickKey] ?? '?') : $userNick,
          'date' => 'NOW(6)'
        ];

        $insertHistorySql = $this->queryBuilder->insert($this->tableHistory, $insertValues);
        try {
            $this->sqlExecutor->query($insertHistorySql['query'], $insertHistorySql['parameters']);
            return;
        } catch (Exception $e) {
            if(!$this->sqlExecutor->is_last_error_table_not_found()) {
                ErrorLog::log($e);
                return;
            }
        }
        try {
            $this->historyTableCreate();
            $this->sqlExecutor->query($insertHistorySql['query'], $insertHistorySql['parameters']);
        } catch (Exception $e) {
            ErrorLog::log($e);
        }
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
          $rows + 1 // diff() compares each row with the next older one, the extra row is only compared against
        ];
        $method = __METHOD__;
        $sql = "SELECT /*$method*/ history_id, `action`, `date`, `user_nick`, `record`, `motive` 
            FROM $this->tableHistory 
            WHERE `pk` = ?
            ORDER BY `date` DESC, history_id DESC LIMIT ?, ?";

        $history = $this->sqlExecutor->arrayKeyed($sql, 'history_id', $params);
        return $this->diff($history, count($history) <= $rows);
    }

    /**
     * Full history rows of a record that did change, oldest first, ignoring $ignoreDifferenceForFields.
     * HARD REQUIREMENT: the first (oldest) row is always returned, even if it has no values or none of $onlyFields,
     * it is the initial base every later row is compared against. Never filter it out.
     * Later rows are included only if they differ from the previous included row, except a delete row: it is always included.
     *
     * @param array $primaryKeyValues
     * @param string[] $onlyFields only changes to these fields count (even if ignored), empty: all non ignored fields
     * @return array<int, array{
     *      history_id: int,
     *      action: string,
     *      motive: string,
     *      date: string,
     *      user_nick: string,
     *      record: array<string, string|array>
     *   }> keyed by history_id, sorted by date
     * @throws Exception
     */
    public function getChangedRecords(array $primaryKeyValues, array $onlyFields = []): array {
        $method = __METHOD__;
        $sql = "SELECT /*$method*/ history_id, `action`, `date`, `user_nick`, `record`, `motive`
            FROM $this->tableHistory
            WHERE `pk` = ?
            ORDER BY `date`, history_id";

        $changed = [];
        $previous = [];
        foreach($this->sqlExecutor->arrayKeyed($sql, 'history_id', [$this->primaryKeyEncode($primaryKeyValues)]) as $historyId => $row) {
            $record = json_decode(
                json: $row['record'],
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
            );
            if(!is_array($record))
                throw new \UnexpectedValueException('History record must decode to an array.');
            $row['record'] = $this->normalizeRecordText($record);

            if(!empty($changed) && $row['action'] !== 'delete' && empty($this->differFields($previous, $row['record'], $onlyFields)))
                continue; // a delete is always kept, even when no field changed
            $previous = $row['record'];
            $changed[$historyId] = $row;
        }
        return $changed;
    }

    /**
     * Which history rows added, modified or deleted each field.
     * added: field went from missing/null to a value; deleted: from a value to missing/null; modified: any other change.
     *
     * $onlyFields entries with a dot are paths into child lines, 'items.price' reports under the key 'items.price'
     * every history row where the price of some line was added (new line or value), modified or deleted (removed
     * line or value). A row with several kinds (a new line and a changed one) is listed under each kind.
     * Other entries are plain field names, reported as before.
     *
     * @param array $changedRecords result of getChangedRecords()
     * @param string[] $onlyFields only report these fields or 'collection.field' paths, empty: all fields
     * @return array<string, array{added: int[], modified: int[], deleted: int[]}> fieldName => history_ids, date sorted
     */
    public function getFieldChanges(array $changedRecords, array $onlyFields = []): array {
        $names = [];
        $paths = [];
        foreach($onlyFields as $onlyField)
            if(str_contains($onlyField, '.'))
                $paths[] = $onlyField;
            else
                $names[] = $onlyField;
        $ignore = array_diff($this->ignoreDifferenceForFields, ...array_map(fn(string $p): array => explode('.', $p), $paths));

        $fieldChanges = [];
        $previous = [];
        foreach($changedRecords as $historyId => $row) {
            if(empty($onlyFields) || !empty($names)) {
                foreach($this->differFields($previous, $row['record'], $names) as $field => $diffData) {
                    if(!array_key_exists($field, $fieldChanges))
                        $fieldChanges[$field] = ['added' => [], 'modified' => [], 'deleted' => []];
                    $leaf = array_key_exists('before', $diffData) && array_key_exists('after', $diffData);
                    $kind = $leaf ? $this->changeKind($diffData['before'], $diffData['after']) : 'modified'; // else nested record
                    $fieldChanges[$field][$kind][] = $historyId;
                }
            }
            foreach($paths as $path) {
                $segments = explode('.', $path);
                $before = $this->valuesAtPath($previous, $segments);
                $after = $this->valuesAtPath($row['record'], $segments);
                $kinds = [];
                foreach(array_unique(array_merge(array_keys($before), array_keys($after))) as $line) {
                    $beforeValue = $before[$line] ?? null;
                    $afterValue = $after[$line] ?? null;
                    $changed = is_array($beforeValue) && is_array($afterValue)
                      ? !empty($this->differ($beforeValue, $afterValue, $ignore))
                      : $this->valuesDiffer($beforeValue, $afterValue);
                    if($changed)
                        $kinds[$this->changeKind($beforeValue, $afterValue)] = true;
                }
                if(empty($kinds))
                    continue;
                if(!array_key_exists($path, $fieldChanges))
                    $fieldChanges[$path] = ['added' => [], 'modified' => [], 'deleted' => []];
                foreach($kinds as $kind => $unused)
                    $fieldChanges[$path][$kind][] = $historyId;
            }
            $previous = $row['record'];
        }
        return $fieldChanges;
    }

    /** Normalize decoded string values to NFC, including values in nested records. */
    protected function normalizeRecordText(array $record): array {
        foreach($record as $key => $value) {
            if(is_array($value)) {
                $record[$key] = $this->normalizeRecordText($value);
            } elseif(is_string($value)) {
                $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
                if($normalized === false)
                    throw new \UnexpectedValueException('History record contains invalid Unicode text.');
                $record[$key] = $normalized;
            }
        }
        return $record;
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
     *  Renders trusted HTML. Callers must escape or sanitize every supplied value before rendering.
     *  HTML is intentionally not escaped here; upstream code owns that responsibility.
     *
     * $fieldFormat only affects how the table is built. It is keyed by field name, a field without an entry is shown
     * as always. Every key of an entry is optional, nothing in it is escaped:
     *   'class'       css class(es) of the field's row (of the value, for fields of a child)
     *   'label'       replaces SqlUtils::toLabel($fieldName)
     *   'fieldPrefix', 'fieldSuffix'  around the label (around the whole value, for fields of a child)
     *   'valuePrefix', 'valueSuffix'  around each value (before and after)
     *   'child'       [fieldName => entry with these same keys], for a field that holds child lines keyed by the
     *                 line's key (items => [101 => [...], 102 => [...]]). A changed line prints one row: all its
     *                 listed fields in that sequence, before and after (12 circles 3.14), then any other field of
     *                 the line that changed, labeled. Only ignored fields are left out. Added line: empty before,
     *                 deleted line: empty after (their other fields show when they have a value). Deeper levels print all their lines, never only the changed ones.
     *
     * @param array $changes
     * @param array<string, array<string, mixed>> $fieldFormat
     * @return string HTML table representing the change's history.
     */
    public function changesAsHTML(array $changes, array $fieldFormat = []): string {
        $html = '<table class="laTabla"><tbody>';
        foreach ($changes as $change) {
            if(empty($change['diff']) && $change['action'] !== 'delete')
                continue;
            $html .= '<tr>' . '<td class="cen">' . $change['date'] . '<br>' . $change['action'] . '<br>' . $change['user_nick'] .
                '<td><table><thead><tr><th> <th>Era<th>Cambio A:</tr></thead><tbody>';
                foreach ($change['diff'] as $field => $diffData) {
                    if(array_key_exists($field, $fieldFormat)) {
                        $html .= $this->fieldAsHTML((string)$field, $diffData, $change['record'][$field] ?? [], $fieldFormat[$field]);
                        continue;
                    }
                    [$before, $after] = $this->diffSidesAsText($diffData);
                    $html .= '<tr><td>' . SqlUtils::toLabel($field) . '<td>' . $before . '<td>' . $after;
                }
                $html .= '</tbody></table>';
        }
        return  $html . '</tbody></table>';
    }

    /** @return array{0: string, 1: string} before and after text of a field's diff */
    protected function diffSidesAsText(array $diffData): array {
        if(!array_key_exists('before', $diffData) || !array_key_exists('after', $diffData)) {
            // nested record, differ() returned [subField => [before, after]]
            $side = static fn(string $which): string => json_encode(array_map(
              static fn($d) => is_array($d) && array_key_exists($which, $d) ? $d[$which] : $d, $diffData
            ), JSON_UNESCAPED_UNICODE);
            return [$side('before'), $side('after')];
        }
        return [
          is_array($diffData['before']) ? json_encode($diffData['before']) : $diffData['before'],
          is_array($diffData['after']) ? json_encode($diffData['after']) : $diffData['after'],
        ];
    }

    /** One field of changesAsHTML() that has a $fieldFormat entry. $collection is the field's value in the change's record. */
    protected function fieldAsHTML(string $field, array $diffData, mixed $collection, array $format): string {
        $class = isset($format['class']) ? ' class="' . $format['class'] . '"' : '';
        $label = ($format['fieldPrefix'] ?? '') . ($format['label'] ?? SqlUtils::toLabel($field)) . ($format['fieldSuffix'] ?? '');

        if(!isset($format['child']) || !is_array($format['child'])) {
            [$before, $after] = $this->diffSidesAsText($diffData);
            return '<tr' . $class . '><td>' . $label . '<td>' . ($format['valuePrefix'] ?? '') . $before . ($format['valueSuffix'] ?? '') .
              '<td>' . ($format['valuePrefix'] ?? '') . $after . ($format['valueSuffix'] ?? '');
        }

        $html = '';
        foreach($this->childLines($diffData, is_array($collection) ? $collection : []) as $line) {
            $changedNames = $line['changed'] ?? array_keys(array_filter($line['before'] ?? $line['after'] ?? [], static fn($v) => $v !== null));
            $extra = array_values(array_diff($changedNames, array_keys($format['child']), $this->ignoreDifferenceForFields));
            $html .= '<tr' . $class . '><td>' . $label .
              '<td>' . $this->childLineAsHTML($line['before'], $format['child'], $extra) .
              '<td>' . $this->childLineAsHTML($line['after'], $format['child'], $extra);
        }
        return $html;
    }

    /**
     * The lines of a child collection that changed, in order, each with its full before and after
     * (null before: new line, null after: deleted line) and, for a changed line, the names of its changed fields.
     *
     * @param array $diffData the field's diff
     * @param array $collection the field's value in the change's record (all current lines)
     * @return array<int, array{before: ?array, after: ?array, changed?: string[]}>
     */
    protected function childLines(array $diffData, array $collection): array {
        $lines = [];
        if(array_key_exists('before', $diffData) && array_key_exists('after', $diffData)) {
            // whole collection added, deleted or replaced
            $before = is_array($diffData['before']) ? $diffData['before'] : [];
            $after = is_array($diffData['after']) ? $diffData['after'] : [];
            foreach(array_unique(array_merge(array_keys($before), array_keys($after))) as $key)
                $lines[] = [
                  'before' => is_array($before[$key] ?? null) ? $before[$key] : null,
                  'after' => is_array($after[$key] ?? null) ? $after[$key] : null,
                ];
            return $lines;
        }
        foreach($diffData as $key => $lineDiff) {
            if(array_key_exists('before', $lineDiff) && array_key_exists('after', $lineDiff)) {
                $lines[] = [
                  'before' => is_array($lineDiff['before']) ? $lineDiff['before'] : null,
                  'after' => is_array($lineDiff['after']) ? $lineDiff['after'] : null,
                ];
                continue;
            }
            $after = is_array($collection[$key] ?? null) ? $collection[$key] : [];
            $lines[] = ['before' => $this->beforeFromDiff($after, $lineDiff), 'after' => $after, 'changed' => array_keys($lineDiff)];
        }
        return $lines;
    }

    /** The record as it was before $diff: the current record with the changed values put back. */
    protected function beforeFromDiff(array $after, array $diff): array {
        $before = $after;
        foreach($diff as $key => $change) {
            if(array_key_exists('before', $change) && array_key_exists('after', $change)) {
                if($change['before'] === null)
                    unset($before[$key]);
                else
                    $before[$key] = $change['before'];
            }
            else
                $before[$key] = $this->beforeFromDiff(is_array($after[$key] ?? null) ? $after[$key] : [], $change);
        }
        return $before;
    }

    /**
     * One side (before or after) of a child line: its listed fields in sequence, then the $extra changed fields
     * labeled, joined by a space. Nothing for a missing line.
     *
     * @param ?array $line
     * @param array<string, array<string, mixed>> $childFormat
     * @param string[] $extra changed fields that are not listed in $childFormat
     */
    protected function childLineAsHTML(?array $line, array $childFormat, array $extra): string {
        if($line === null)
            return '';
        $columns = [];
        foreach($childFormat as $field => $format) {
            $class = isset($format['class']) ? ' class="' . $format['class'] . '"' : '';
            $title = ' title="' . ($format['label'] ?? SqlUtils::toLabel((string)$field)) . '"';
            $columns[] = ($format['fieldPrefix'] ?? '') . '<span' . $class . $title . '>' . ($format['valuePrefix'] ?? '') .
              $this->childValueAsHTML($line[$field] ?? null, $format) . ($format['valueSuffix'] ?? '') . '</span>' . ($format['fieldSuffix'] ?? '');
        }
        foreach($extra as $field)
            $columns[] = '<span>' . SqlUtils::toLabel((string)$field) . ': ' . $this->childValueAsHTML($line[$field] ?? null, []) . '</span>';
        return implode(' ', $columns);
    }

    protected function childValueAsHTML(mixed $value, array $format): string {
        if(!is_array($value))
            return (string)$value;
        if(!isset($format['child']) || !is_array($format['child']))
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        $lines = [];
        foreach($value as $line)
            if(is_array($line))
                $lines[] = $this->childLineAsHTML($line, $format['child'], array_diff(array_keys($line), array_keys($format['child']), $this->ignoreDifferenceForFields));
        return implode('; ', $lines);
    }

    protected function primaryKeyEncode(array $values):string {
        $pkValues = [];
        foreach($this->primaryKeys as $primaryKey) {
            if(!array_key_exists($primaryKey, $values))
                throw new InvalidArgumentException(__METHOD__ . " primary key '$primaryKey' missing for table $this->table");
            $pkValues[] = $values[$primaryKey];
        }
        return implode("\t" ,$pkValues);
    }

    /** Loose comparison (!=) on purpose: '1.10' == '1.1', 1 == '1' are not differences. But null or bool versus any other type (0, '', false, true) is. */
    protected function differ(array $before, array $after, ?array $ignoreFields = null):array {
        $ignoreFields ??= $this->ignoreDifferenceForFields;
        $diff = [];
        $keysToCheck = array_diff(
          array_merge(array_keys($before), array_keys($after)),
    $ignoreFields
        );
        foreach($keysToCheck as $key) {
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;
            if(is_array($beforeValue) && is_array($afterValue)) {
                $nested = $this->differ($beforeValue, $afterValue, $ignoreFields);
                if(!empty($nested))
                    $diff[$key] = $nested;
            }
            elseif($this->valuesDiffer($beforeValue, $afterValue))
                $diff[$key] = ['before' => $beforeValue, 'after' => $afterValue];
        }
        return $diff;
    }

    /** The one rule for "is it a change" between two non array values, see differ() */
    protected function valuesDiffer(mixed $before, mixed $after): bool {
        return ($before === null) !== ($after === null) || is_bool($before) !== is_bool($after) || $before != $after;
    }

    /** added: null before, deleted: null after, modified: anything else */
    protected function changeKind(mixed $before, mixed $after): string {
        if($before === null)
            return 'added';
        return $after === null ? 'deleted' : 'modified';
    }

    /**
     * differ() restricted to $onlyFields, which win over ignored fields; empty $onlyFields: all non ignored fields.
     * An $onlyFields entry is a field name or a path 'items.price' (see projectPaths()). Every name in a path is
     * un-ignored, at any depth.
     */
    protected function differFields(array $before, array $after, array $onlyFields = []): array {
        if(empty($onlyFields))
            return $this->differ($before, $after);
        $paths = $this->onlyFieldsPaths($onlyFields);
        return $this->differ(
          $this->projectPaths($before, $paths),
          $this->projectPaths($after, $paths),
          array_diff($this->ignoreDifferenceForFields, array_merge(...$paths))
        );
    }

    /** @param string[] $onlyFields 'field' or 'collection.field' entries @return string[][] each entry as list of names */
    protected function onlyFieldsPaths(array $onlyFields): array {
        return array_map(static fn(string $onlyField): array => explode('.', $onlyField), $onlyFields);
    }

    /**
     * Keep of $record only what $paths list. 'items' keeps the whole field, ['items', 'price'] keeps the price of
     * every line of items. Child lines are keyed by their own key (the line's pk), that key level is skipped when
     * the path's next name is not a key of the collection, so 'items.price' reaches items[101]['price'].
     * Lines are skipped only one level, and never at the top level ($fanOut false), so 'price' alone never
     * reaches a nested price.
     *
     * @param string[][] $paths list of paths, each a list of names
     */
    protected function projectPaths(array $record, array $paths, bool $fanOut = false): array {
        $projected = [];
        foreach($record as $key => $value) {
            $rest = [];
            $whole = false;
            foreach($paths as $path) {
                if((string)$path[0] !== (string)$key)
                    continue;
                if(count($path) === 1) {
                    $whole = true;
                    break;
                }
                $rest[] = array_slice($path, 1);
            }
            if($whole) {
                $projected[$key] = $value;
                continue;
            }
            if(!is_array($value))
                continue;
            $part = !empty($rest) ? $this->projectPaths($value, $rest, true) : ($fanOut ? $this->projectPaths($value, $paths) : []);
            if(!empty($part))
                $projected[$key] = $part;
        }
        return $projected;
    }

    /**
     * Values a path reaches in a record, keyed by the child line key ('' when there are no lines):
     * 'name' => ['' => value], 'items.price' => [101 => '3.14', 102 => '2.00']
     *
     * @param string[] $path
     * @return array<string|int, mixed>
     */
    protected function valuesAtPath(array $record, array $path, bool $fanOut = false): array {
        if(array_key_exists($path[0], $record)) {
            $value = $record[$path[0]];
            if(count($path) === 1)
                return ['' => $value];
            return is_array($value) ? $this->valuesAtPath($value, array_slice($path, 1), true) : [];
        }
        $values = [];
        if($fanOut)
            foreach($record as $key => $line)
                if(is_array($line))
                    foreach($this->valuesAtPath($line, $path) as $sub => $value)
                        $values[$sub === '' ? $key : $key . '/' . $sub] = $value;
        return $values;
    }

    /** @param bool $oldestIsLast the last row is the oldest of the record (not the extra row only compared against): it is returned only when it is a delete */
    protected function diff(array $recordHistory, bool $oldestIsLast = false): array {
        $records = array_values($recordHistory);  // Reindex for sequential access
        $count = count($records);
        if($count < 1)
            return [];

        $diff = [];
        for($i = 0; $i < $count - 1 || ($oldestIsLast && $i < $count); ++$i) {
            // Decode each record once, when it first participates in a comparison.
            foreach($i === 0 ? array_keys(array_slice($records, 0, 2)) : ($i + 1 < $count ? [$i + 1] : []) as $index) {
                $record = json_decode(
                    json: $records[$index]['record'],
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
                );
                if(!is_array($record))
                    throw new \UnexpectedValueException('History record must decode to an array.');
                $records[$index]['record'] = $this->normalizeRecordText($record);
            }
            $current = $records[$i];
            $differ = $this->differ($records[$i + 1]['record'] ?? [], $current['record']); // no older row: the record was created by this one

            if(empty($differ) && $current['action'] !== 'delete')
                continue; // a delete is always returned, even when no field changed
            if($i === $count - 1 && $current['action'] !== 'delete')
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
     * Create the history, audit or changes table if it doesn't exist
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
