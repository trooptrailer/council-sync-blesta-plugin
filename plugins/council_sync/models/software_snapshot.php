<?php

class CouncilSyncSoftwareSnapshot
{
    public const CONTROL = 'council_sync_software';
    private const CORE = [
        'plugins', 'permission_groups', 'permissions', 'actions', 'navigation_items',
        'plugin_cards', 'plugin_events', 'cron_tasks', 'cron_task_runs', 'email_groups', 'emails'
    ];
    private const MAX_BYTES = 536870912;

    public function __construct(protected PDO $pdo)
    {
    }

    public static function dataTables(mixed $tables): array
    {
        if (!is_array($tables) || !$tables || !array_is_list($tables) || count($tables) > 40) {
            throw new CouncilSyncIssue('software', 'software_protocol');
        }
        foreach ($tables as $table) {
            if (
                !is_string($table) || !preg_match('~^council_sync_[a-z][a-z0-9_]{0,40}$~D', $table)
                || $table === self::CONTROL
            ) {
                throw new CouncilSyncIssue('software', 'software_protocol');
            }
        }
        if (count(array_unique($tables)) !== count($tables)) {
            throw new CouncilSyncIssue('software', 'software_protocol');
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    protected function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    protected function names(): array
    {
        return $this->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?',
            ['BASE TABLE']
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    public function actualDataTables(): array
    {
        $tables = array_values(array_filter($this->names(), static fn($table) =>
            str_starts_with($table, 'council_sync_') && $table !== self::CONTROL));
        sort($tables, SORT_STRING);
        return $tables;
    }

    protected function ddl(string $table): string
    {
        $row = $this->query('SHOW CREATE TABLE ' . self::identifier($table))->fetch(PDO::FETCH_NUM);
        if (!$row || !is_string($row[1]) || !str_contains($row[1], 'ENGINE=InnoDB')) {
            throw new CouncilSyncIssue('software', 'software_schema');
        }
        return $row[1];
    }

    protected function assertExportable(string $table): void
    {
        if (
            preg_match('~\bGENERATED\b|\bCHECK\s*\(~i', $this->ddl($table))
            || $this->query(
                'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
                [$table]
            )->fetchColumn()
        ) {
            throw new CouncilSyncIssue('software', 'software_schema');
        }
    }

    public function schema(array $tables): array
    {
        $existing = $this->names();
        $schema = [];
        sort($tables, SORT_STRING);
        foreach ($tables as $table) {
            $schema[$table] = in_array($table, $existing, true) ? $this->fingerprint($table) : null;
        }
        return $schema;
    }

    protected function fingerprint(string $table): string
    {
        $this->ddl($table);
        $parts = [
            $this->query(
                'SELECT ENGINE, TABLE_COLLATION, ROW_FORMAT, CREATE_OPTIONS FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->query(
                'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA,'
                . ' CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->query(
                'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, COLLATION, INDEX_TYPE'
                . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table]
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->query(
                'SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME'
                . ' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                . ' ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION', [$table]
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->query(
                'SELECT CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE'
                . ' FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                . ' ORDER BY CONSTRAINT_NAME', [$table]
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->query(
                'SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME', [$table]
            )->fetchAll(PDO::FETCH_ASSOC),
            $this->query(
                'SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS'
                . ' WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? ORDER BY TRIGGER_NAME', [$table]
            )->fetchAll(PDO::FETCH_ASSOC)
        ];
        return json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function sameSchema(array $backup): bool
    {
        return $this->actualDataTables() === array_keys($backup)
            && $this->schema(array_keys($backup)) === $backup;
    }

    private static function identifier(string $value): string
    {
        if (!preg_match('~^[a-zA-Z_][a-zA-Z0-9_]{0,63}$~D', $value)) {
            throw new CouncilSyncIssue('software', 'software_snapshot');
        }
        return '`' . $value . '`';
    }

    private function scoped(string $table, string $column, array $ids): array
    {
        if (!in_array($table, self::CORE, true)) {
            throw new CouncilSyncIssue('software', 'software_snapshot');
        }
        return [
            'SELECT * FROM ' . self::identifier($table) . ($ids
                ? ' WHERE ' . self::identifier($column) . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
                : ' WHERE 1 = 0'),
            $ids
        ];
    }

    private function registrationScopes(): array
    {
        $pluginIds = $this->query('SELECT id FROM plugins WHERE dir = ?', ['council_sync'])->fetchAll(PDO::FETCH_COLUMN);
        if (!$pluginIds) {
            throw new CouncilSyncIssue('software', 'not_installed');
        }
        $cronIds = $this->query('SELECT id FROM cron_tasks WHERE dir = ? AND task_type = ?', ['council_sync', 'plugin'])
            ->fetchAll(PDO::FETCH_COLUMN);
        $emailIds = $this->query('SELECT id FROM email_groups WHERE plugin_dir = ?', ['council_sync'])->fetchAll(PDO::FETCH_COLUMN);
        [$sql, $args] = $this->scoped('actions', 'plugin_id', $pluginIds);
        $actionIds = array_column($this->query($sql, $args)->fetchAll(PDO::FETCH_ASSOC), 'id');
        $scopes = [];
        foreach (['plugins', 'permission_groups', 'permissions', 'actions', 'plugin_cards', 'plugin_events'] as $table) {
            $scopes[$table] = $this->scoped($table, $table === 'plugins' ? 'id' : 'plugin_id', $pluginIds);
        }
        $scopes['navigation_items'] = $this->scoped('navigation_items', 'action_id', $actionIds);
        $scopes['cron_tasks'] = $this->scoped('cron_tasks', 'id', $cronIds);
        $scopes['cron_task_runs'] = $this->scoped('cron_task_runs', 'task_id', $cronIds);
        $scopes['email_groups'] = $this->scoped('email_groups', 'id', $emailIds);
        $scopes['emails'] = $this->scoped('emails', 'email_group_id', $emailIds);
        return $scopes;
    }

    protected function beginSnapshot(): void
    {
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
    }

    public function capture(string $directory, array $tables): array
    {
        $tables = self::dataTables($tables);
        if ($this->actualDataTables() !== $tables || $this->pdo->inTransaction()) {
            throw new CouncilSyncIssue('software', 'software_schema');
        }
        $this->assertNoExternalReferences($tables);
        $this->assertNoExternalReferences(self::CORE);
        foreach (array_merge($tables, self::CORE) as $table) {
            $this->assertExportable($table);
        }
        $schema = $this->schema($tables);
        $result = ['schema' => $schema, 'ddl' => [], 'data' => [], 'core' => []];
        foreach ($tables as $table) {
            $result['ddl'][$table] = $this->ddl($table);
        }
        $bytes = 0;
        $this->beginSnapshot();
        try {
            foreach ($tables as $table) {
                $result['data'][$table] = $this->export(
                    $directory, 'data_' . $table . '.gz',
                    $this->query('SELECT * FROM ' . self::identifier($table)), $bytes
                );
            }
            foreach ($this->registrationScopes() as $table => [$sql, $args]) {
                $result['core'][$table] = $this->export(
                    $directory, 'core_' . $table . '.gz', $this->query($sql, $args), $bytes
                );
                $result['core'][$table]['schema'] = $this->schema([$table])[$table];
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if ($this->schema($tables) !== $schema) {
            throw new CouncilSyncIssue('software', 'software_schema');
        }
        return $result;
    }

    private function export(string $directory, string $file, PDOStatement $rows, int &$bytes): array
    {
        $path = $directory . DIRECTORY_SEPARATOR . $file;
        $stream = @gzopen($path, 'wb9');
        if ($stream === false) {
            throw new CouncilSyncIssue('software', 'software_backup');
        }
        $count = 0;
        try {
            if (!@chmod($path, 0600)) {
                throw new CouncilSyncIssue('software', 'software_backup');
            }
            while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $encoded = array_map(static fn($value) => $value === null ? null : base64_encode((string) $value), $row);
                $line = json_encode($encoded, JSON_THROW_ON_ERROR) . "\n";
                $bytes += strlen($line);
                if ($bytes > self::MAX_BYTES || strlen($line) > 16777216 || gzwrite($stream, $line) !== strlen($line)) {
                    throw new CouncilSyncIssue('software', 'software_backup');
                }
                $count++;
            }
        } finally {
            if (!@gzclose($stream)) {
                throw new CouncilSyncIssue('software', 'software_backup');
            }
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new CouncilSyncIssue('software', 'software_backup');
        }
        return ['file' => $file, 'sha256' => $hash, 'rows' => $count];
    }

    private function rows(string $directory, array $entry): Generator
    {
        $file = $entry['file'] ?? '';
        if (
            !is_string($file) || !preg_match('~^(?:data_council_sync_[a-z0-9_]+|core_[a-z_]+)\.gz$~D', $file)
            || !is_string($entry['sha256'] ?? null) || !is_int($entry['rows'] ?? null)
        ) {
            throw new CouncilSyncIssue('software', 'software_snapshot');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $file;
        if (is_link($path) || !is_file($path) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
            throw new CouncilSyncIssue('software', 'software_snapshot');
        }
        $stream = @gzopen($path, 'rb');
        if (!$stream) {
            throw new CouncilSyncIssue('software', 'software_snapshot');
        }
        $count = $bytes = 0;
        try {
            while (($line = gzgets($stream, 16777217)) !== false) {
                $bytes += strlen($line);
                if ($bytes > self::MAX_BYTES || !str_ends_with($line, "\n")) {
                    throw new CouncilSyncIssue('software', 'software_snapshot');
                }
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($row) || !$row) {
                    throw new CouncilSyncIssue('software', 'software_snapshot');
                }
                foreach ($row as $column => &$value) {
                    self::identifier($column);
                    if ($value !== null) {
                        if (!is_string($value) || ($value = base64_decode($value, true)) === false) {
                            throw new CouncilSyncIssue('software', 'software_snapshot');
                        }
                    }
                }
                unset($value);
                $count++;
                yield $row;
            }
            if (!gzeof($stream) || $count !== $entry['rows']) {
                throw new CouncilSyncIssue('software', 'software_snapshot');
            }
        } finally {
            gzclose($stream);
        }
    }

    private function insert(string $table, array $row): void
    {
        $columns = array_map([self::class, 'identifier'], array_keys($row));
        $this->query(
            'INSERT INTO ' . self::identifier($table) . ' (' . implode(',', $columns) . ') VALUES ('
            . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row)
        );
    }

    public function verify(array $snapshot, string $directory): void
    {
        foreach (['data', 'core'] as $group) {
            foreach ($snapshot[$group] as $entry) {
                iterator_count($this->rows($directory, $entry));
            }
        }
        $this->validateRegistrations($snapshot);
    }

    protected function assertNoExternalReferences(array $tables): void
    {
        if (!$tables) {
            return;
        }
        $marks = implode(',', array_fill(0, count($tables), '?'));
        $refs = $this->query(
            'SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IN (' . $marks . ')'
            . ' AND TABLE_NAME NOT IN (' . $marks . ')',
            array_merge($tables, $tables)
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($refs) {
            throw new CouncilSyncIssue('software', 'software_external_reference');
        }
    }

    protected function createShadow(string $original, string $shadow, string $ddl): array
    {
        $foreign = [];
        $ddl = preg_replace_callback('~(?:^|\n)\s*(CONSTRAINT `[^`]+` FOREIGN KEY[^\n]+),?~', static function ($match) use (&$foreign) {
            $foreign[] = rtrim(trim($match[1]), ',');
            return '';
        }, $ddl);
        $ddl = preg_replace('~,\s*\)~', "\n)", $ddl);
        $ddl = preg_replace('~^CREATE TABLE `' . preg_quote($original, '~') . '`~', 'CREATE TABLE `' . $shadow . '`', $ddl, 1, $changed);
        if ($changed !== 1) {
            throw new CouncilSyncIssue('software', 'software_snapshot');
        }
        $this->pdo->exec($ddl);
        return $foreign;
    }

    protected function swap(array $trash, array $shadows): void
    {
        $renames = [];
        foreach ($trash as $live => $old) {
            $renames[] = self::identifier($live) . ' TO ' . self::identifier($old);
        }
        foreach ($shadows as $live => $new) {
            $renames[] = self::identifier($new) . ' TO ' . self::identifier($live);
        }
        $this->pdo->exec('RENAME TABLE ' . implode(',', $renames));
    }

    protected function validateShadowKeys(array $foreign, array $shadows): void
    {
        $created = [];
        foreach ($foreign as $table => $keys) {
            foreach ($keys as $definition) {
                $name = 'csu_fk_' . bin2hex(random_bytes(12));
                $check = preg_replace('~^CONSTRAINT `[^`]+`~', 'CONSTRAINT `' . $name . '`', $definition);
                $check = preg_replace_callback('~REFERENCES `([^`]+)`~', static fn($match) =>
                    'REFERENCES ' . self::identifier($shadows[$match[1]] ?? $match[1]), $check);
                $this->pdo->exec('ALTER TABLE ' . self::identifier($shadows[$table]) . ' ADD ' . $check);
                $created[] = [$shadows[$table], $name];
            }
        }
        foreach ($created as [$table, $name]) {
            $this->pdo->exec('ALTER TABLE ' . self::identifier($table) . ' DROP FOREIGN KEY ' . self::identifier($name));
        }
    }

    public function dropTemps(array $tables): void
    {
        foreach ($tables as $table) {
            if (!is_string($table) || !preg_match('~^csu_[a-f0-9]{16}_[a-f0-9]{8}$~D', $table)) {
                throw new CouncilSyncIssue('software', 'software_snapshot');
            }
        }
        $checks = $this->pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . self::identifier($table));
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = ' . (int) $checks);
        }
    }

    public function restoreData(array $snapshot, string $directory, array $allowed, callable $trackTemps): void
    {
        $allowed = self::dataTables($allowed);
        $current = $this->actualDataTables();
        $before = self::dataTables(array_keys($snapshot['schema']));
        if (array_diff($current, $allowed) || array_diff($before, $allowed)) {
            throw new CouncilSyncIssue('software', 'software_schema');
        }
        $this->assertNoExternalReferences($current);
        $prefix = 'csu_' . bin2hex(random_bytes(8)) . '_';
        $trash = $shadows = [];
        foreach ($current as $table) {
            $trash[$table] = $prefix . substr(hash('sha256', 'old:' . $table), 0, 8);
        }
        foreach ($before as $table) {
            $shadows[$table] = $prefix . substr(hash('sha256', 'new:' . $table), 0, 8);
        }
        $temps = array_merge(array_values($trash), array_values($shadows));
        if (array_intersect($temps, $this->names())) {
            throw new CouncilSyncIssue('software', 'software_snapshot');
        }
        $trackTemps($temps);
        $foreign = [];
        foreach ($shadows as $table => $shadow) {
            $foreign[$table] = $this->createShadow($table, $shadow, $snapshot['ddl'][$table]);
            foreach ($this->rows($directory, $snapshot['data'][$table]) as $row) {
                $this->insert($shadow, $row);
            }
        }
        $this->validateShadowKeys($foreign, $shadows);
        $this->swap($trash, $shadows);
        $this->dropTemps(array_values($trash));
        foreach ($foreign as $table => $keys) {
            foreach ($keys as $definition) {
                $this->pdo->exec('ALTER TABLE ' . self::identifier($table) . ' ADD ' . $definition);
            }
        }
        if (!$this->sameSchema($snapshot['schema'])) {
            throw new CouncilSyncIssue('software', 'software_schema');
        }
        $trackTemps([]);
    }

    private function validateRegistrations(array $snapshot): array
    {
        $this->assertNoExternalReferences(self::CORE);
        $scopes = $this->registrationScopes();
        foreach (self::CORE as $table) {
            if (
                !isset($snapshot['core'][$table])
                || $this->schema([$table])[$table] !== $snapshot['core'][$table]['schema']
            ) {
                throw new CouncilSyncIssue('software', 'software_registration');
            }
        }
        [$sql, $parameters] = $scopes['navigation_items'];
        $navIds = array_column($this->query($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC), 'id');
        if ($navIds) {
            $marks = implode(',', array_fill(0, count($navIds), '?'));
            if ($this->query(
                'SELECT id FROM navigation_items WHERE parent_id IN (' . $marks . ') AND id NOT IN (' . $marks . ')',
                array_merge($navIds, $navIds)
            )->fetch()) {
                throw new CouncilSyncIssue('software', 'software_external_reference');
            }
        }
        return $scopes;
    }

    public function restoreRegistrations(array $snapshot, string $directory): void
    {
        $scopes = $this->validateRegistrations($snapshot);
        $this->pdo->beginTransaction();
        try {
            foreach (array_reverse(self::CORE) as $table) {
                if ($table === 'plugins') {
                    continue;
                }
                [$select, $args] = $scopes[$table];
                $this->query(preg_replace('~^SELECT \*~', 'DELETE', $select), $args);
            }
            foreach (self::CORE as $table) {
                foreach ($this->rows($directory, $snapshot['core'][$table]) as $row) {
                    if ($table === 'plugins') {
                        $id = $row['id'];
                        unset($row['id']);
                        $columns = array_map(static fn($column) => self::identifier($column) . ' = ?', array_keys($row));
                        $this->query('UPDATE plugins SET ' . implode(',', $columns) . ' WHERE id = ? AND dir = ?',
                            array_merge(array_values($row), [$id, 'council_sync']));
                    } else {
                        $this->insert($table, $row);
                    }
                }
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
