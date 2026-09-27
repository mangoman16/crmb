<?php
declare(strict_types=1);

/**
 * A SQLite connection that accepts the MySQL dialect the application writes.
 *
 * Used by the test harness, and by a local preview server, so neither needs a
 * MySQL instance. Kept apart from harness.php because that file is CLI-only by
 * design, while a preview runs over HTTP.
 *
 * This is a development aid. It is never loaded by the application itself.
 */

/**
 * A SQLite connection that accepts the MySQL dialect the application writes.
 *
 * Rewriting at prepare() time means the application's own SQL strings are what
 * gets exercised - no parallel copy of a query can drift out of step with the
 * one that ships. The conflict target for an upsert is read from the table's
 * own unique indexes rather than a hand-kept map, so adding a table needs no
 * change here.
 *
 * What this cannot emulate is MySQL's row locking, so a translated FOR UPDATE
 * is dropped: tests using it verify the logic, not the locking.
 */
final class TestSqlitePdo extends PDO {
    /** @var array<string,string[]> table => unique column groups, cached */
    private array $uniques = [];
    private int $prepared = 0;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null) {
        parent::__construct($dsn, $username, $password, $options);
        $this->addMysqlFunctions();
    }

    /** Statements prepared so far, so a test can assert a query count. */
    public function statementsPrepared(): int { return $this->prepared; }

    /**
     * The MySQL functions the application and the migrations call.
     *
     * Registered by the connection itself, so every connection has the same set:
     * tests/migration-data.php opens its own, and a copy of this list kept there
     * would be the one nobody updates when a migration starts calling something
     * new - found out only when that migration fails in a run that stops half way.
     *
     * A NULL argument makes the result NULL, because that is what MySQL does. Both
     * SQLite's own concat() and a PHP implode() would quietly treat it as '', so a
     * query that loses a value on the real engine would pass here.
     */
    private function addMysqlFunctions(): void {
        $strict = fn(callable $f) => fn(...$a) => in_array(null, $a, true) ? null : $f($a);
        $this->sqliteCreateFunction('CONCAT', $strict(fn($a) => implode('', $a)), -1);
        $this->sqliteCreateFunction('GREATEST', $strict(fn($a) => max($a)), -1);
        $this->sqliteCreateFunction('LEAST', $strict(fn($a) => min($a)), -1);
        $this->sqliteCreateFunction('UTC_TIMESTAMP', fn() => gmdate('Y-m-d H:i:s'), 0);
        // Advisory locks are a MySQL concept; a single-connection test always
        // "holds" the lock, which is the behaviour the code expects.
        $this->sqliteCreateFunction('GET_LOCK', fn($n, $t) => 1, 2);
        $this->sqliteCreateFunction('RELEASE_LOCK', fn($n) => 1, 1);
        // Built into SQLite since 3.38. Registered only where it is missing, so a
        // build that has it is tested with the real one.
        try { parent::query("SELECT json_object('a', 1)"); }
        catch (PDOException) {
            $this->sqliteCreateFunction('JSON_OBJECT', function (...$a) {
                $object = [];
                for ($i = 0; $i + 1 < count($a); $i += 2) $object[(string)$a[$i]] = $a[$i + 1];
                return json_encode((object)$object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }, -1);
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->prepared++;
        return parent::prepare($this->translate($query), $options);
    }

    public function exec(string $statement): int|false {
        return parent::exec($this->translate($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = $this->translate($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    private function translate(string $sql): string {
        // Storage engine and charset are MySQL's business. The migration files go
        // through sqlite_translate(); this covers the CREATE TABLE that
        // schema_apply() carries inline, so the migration runner itself can be
        // exercised rather than only the files it reads.
        if (stripos($sql, 'ENGINE=') !== false) $sql = preg_replace('/\s*ENGINE=\w+(\s+DEFAULT\s+CHARSET=\w+)?(\s+COLLATE=\w+)?/i', '', $sql) ?? $sql;
        if (stripos($sql, 'FOR UPDATE') !== false) $sql = preg_replace('/\s+FOR UPDATE\b/i', '', $sql) ?? $sql;
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) $sql = $this->upsert($sql);
        // MySQL IF(cond, a, b) has no SQLite equivalent.
        if (preg_match('/\bIF\s*\(/i', $sql)) $sql = $this->inlineIf($sql);
        return $sql;
    }

    private function upsert(string $sql): string {
        if (!preg_match('/INSERT\s+INTO\s+`?(\w+)`?/i', $sql, $m)) return $sql;
        $target = $this->conflictTarget($m[1], $sql);
        [$head, $tail] = preg_split('/\s*ON DUPLICATE KEY UPDATE\s*/i', $sql, 2);
        // VALUES(col) inside the update clause becomes excluded.col.
        $tail = preg_replace('/VALUES\s*\(\s*`?(\w+)`?\s*\)/i', 'excluded.$1', $tail) ?? $tail;
        return $head.' ON CONFLICT('.$target.') DO UPDATE SET '.$tail;
    }

    /**
     * The unique column group an upsert on this table conflicts against.
     *
     * Prefers a unique index whose columns all appear in the INSERT's column
     * list, which is what the application is actually relying on.
     */
    private function conflictTarget(string $table, string $sql): string {
        if (!isset($this->uniques[$table])) {
            $groups = [];
            foreach (parent::query('PRAGMA index_list('.$table.')')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                if ((int)$index['unique'] !== 1) continue;
                $cols = [];
                foreach (parent::query('PRAGMA index_info('.$index['name'].')')->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[] = $c['name'];
                if ($cols) $groups[] = $cols;
            }
            foreach (parent::query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC) as $c)
                if ((int)$c['pk'] > 0) $groups[] = [$c['name']];
            $this->uniques[$table] = $groups;
        }
        $inserted = [];
        if (preg_match('/INSERT\s+INTO\s+`?\w+`?\s*\(([^)]*)\)/i', $sql, $m))
            $inserted = array_map(fn($c) => trim($c, " `\t\n"), explode(',', $m[1]));
        foreach ($this->uniques[$table] as $group)
            if (!array_diff($group, $inserted)) return implode(',', $group);
        if (!$this->uniques[$table]) throw new RuntimeException('No unique index on '.$table.' to upsert against');
        return implode(',', $this->uniques[$table][0]);
    }

    /** Rewrite IF(a,b,c) as CASE WHEN a THEN b ELSE c END, innermost first. */
    private function inlineIf(string $sql): string {
        for ($guard = 0; $guard < 20; $guard++) {
            $at = null;
            // Find an IF( whose argument list contains no further IF(.
            if (!preg_match_all('/\bIF\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE)) break;
            foreach (array_reverse($m[0]) as $hit) { $at = $hit[1]; break; }
            if ($at === null) break;
            $open = strpos($sql, '(', $at);
            $depth = 0; $end = null;
            for ($i = $open; $i < strlen($sql); $i++) {
                if ($sql[$i] === '(') $depth++;
                elseif ($sql[$i] === ')') { $depth--; if ($depth === 0) { $end = $i; break; } }
            }
            if ($end === null) break;
            $args = $this->splitArgs(substr($sql, $open + 1, $end - $open - 1));
            if (count($args) !== 3) break;
            $sql = substr($sql, 0, $at).'(CASE WHEN '.$args[0].' THEN '.$args[1].' ELSE '.$args[2].' END)'.substr($sql, $end + 1);
        }
        return $sql;
    }

    /** Split a comma-separated argument list, respecting nested parentheses. */
    private function splitArgs(string $inner): array {
        $args = []; $depth = 0; $buf = '';
        for ($i = 0; $i < strlen($inner); $i++) {
            $ch = $inner[$i];
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $args[] = trim($buf); $buf = ''; continue; }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $args[] = trim($buf);
        return $args;
    }
}
