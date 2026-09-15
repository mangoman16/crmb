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

    public function prepare(string $query, array $options = []): PDOStatement|false {
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
