<?php

error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

#[AllowDynamicProperties]
class AppModel
{
    protected function systemEncrypt(string $value): string { return 'enc:' . base64_encode($value); }
    protected function systemDecrypt(string $value): string|false
    {
        return str_starts_with($value, 'enc:') ? base64_decode(substr($value, 4), true) : false;
    }
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR
    . 'council_sync' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'council_sync.php';

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . json_encode($expected) . '; got ' . json_encode($actual));
    }
}

function issue(string $reason, callable $operation): void
{
    try {
        $operation();
    } catch (CouncilSyncIssue $error) {
        same($reason, $error->reason);
        return;
    }
    throw new RuntimeException('Expected CouncilSyncIssue: ' . $reason);
}

function wiki(array $rows): string
{
    $text = "{| class=\"wikitable\"\n! Council number !! Council name !! Headquarters city !! Headquarters state !! Camps\n";
    foreach ($rows as $row) {
        $text .= "|-\n| " . $row['number'] . ' || ' . $row['name'] . ' || '
            . ($row['city'] ?? 'Fixture city') . ' || ' . $row['state'] . " || {{plainlist|\n* Fixture camp\n}}\n";
    }
    return $text . "|}\n";
}

function council(string $name, int $number, string $state = 'Texas'): array
{
    return ['name' => $name, 'number' => (string) $number, 'city' => 'Fixture city', 'state' => $state];
}

class TestCouncilSync extends CouncilSync
{
    public array $response = [];
    public int $requests = 0;
    public array $releaseResponse = ['status' => 404, 'body' => '{}'];
    public ?CouncilSyncIssue $releaseFailure = null;
    public int $releaseRequests = 0;
    public int $clock;

    public function __construct(public TestRecord $Record, public TestClients $Clients)
    {
        $this->clock = time();
    }

    public function source(array $rows): void
    {
        $this->response = [
            'status' => 200,
            'body' => json_encode(['parse' => ['title' => 'List of councils (Scouting America)', 'wikitext' => wiki($rows)]])
        ];
    }

    protected function httpRequest(string $contact): array
    {
        $this->requests++;
        return $this->response;
    }

    public function release(string $tag): void
    {
        $this->releaseResponse = [
            'status' => 200,
            'body' => json_encode([
                'tag_name' => $tag, 'draft' => false, 'prerelease' => false,
                'html_url' => 'https://github.com/' . self::REPOSITORY . '/releases/tag/' . $tag
            ])
        ];
    }

    protected function updateRequest(): array
    {
        $this->releaseRequests++;
        if ($this->releaseFailure !== null) {
            throw $this->releaseFailure;
        }
        return $this->releaseResponse;
    }

    public function now(): int
    {
        return $this->clock;
    }
}

class TestRecord
{
    public array $trace = [];
    public bool $lockAvailable = true;
    public bool $failSchema = false;
    private string $table = '';
    private string $columns = '*';
    private array $conditions = [];
    private array $parameters = [];
    private string $ordering = '';
    private string $limitClause = '';

    public function __construct(public PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
    }

    public function connect(): PDO { return $this->pdo; }
    public function getConnection(): PDO { return $this->pdo; }
    public function setConnection(PDO $pdo): void { $this->pdo = $pdo; }
    public function begin(): void { $this->trace[] = 'begin'; $this->pdo->beginTransaction(); }
    public function commit(): void { $this->trace[] = 'commit'; $this->pdo->commit(); }
    public function rollBack(): void { $this->trace[] = 'rollback'; $this->pdo->rollBack(); }
    public function lastInsertId(): string { return $this->pdo->lastInsertId(); }

    public function query(string $sql, mixed ...$parameters): PDOStatement
    {
        $this->trace[] = $sql;
        if (count($parameters) === 1 && is_array($parameters[0])) {
            $parameters = $parameters[0];
        }
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS')) {
                if ($this->failSchema) {
                    throw new PDOException('Synthetic schema failure');
                }
                $sql = preg_replace('~\) ENGINE=InnoDB.*$~s', ')', $sql);
                $sql = preg_replace("~ENUM\\([^)]*\\)~", 'TEXT', $sql);
                $sql = str_replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
                $sql = preg_replace('~, KEY company_id_id \(company_id,id\)~', '', $sql);
            }
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return $this->query(
                    'SELECT name, CASE WHEN "notnull" = 1 THEN \'NO\' ELSE \'YES\' END AS nullable FROM pragma_table_info(?)',
                    $parameters[0]
                );
            }
            if ($sql === 'ALTER TABLE council_sync_issues MODIFY notified_at DATETIME NULL') {
                $columns = $this->pdo->query('PRAGMA table_info(council_sync_issues)')->fetchAll();
                $definitions = [];
                $keys = [];
                foreach ($columns as $column) {
                    $definitions[] = $column->name . ' ' . $column->type
                        . ($column->notnull && $column->name !== 'notified_at' ? ' NOT NULL' : '');
                    if ($column->pk) {
                        $keys[$column->pk] = $column->name;
                    }
                }
                ksort($keys);
                $definitions[] = 'PRIMARY KEY (' . implode(',', $keys) . ')';
                $this->pdo->exec('CREATE TABLE council_sync_issues_nullable (' . implode(',', $definitions) . ')');
                $this->pdo->exec('INSERT INTO council_sync_issues_nullable SELECT * FROM council_sync_issues');
                $this->pdo->exec('DROP TABLE council_sync_issues');
                $this->pdo->exec('ALTER TABLE council_sync_issues_nullable RENAME TO council_sync_issues');
                return $this->pdo->query('SELECT 1');
            }
            if (str_contains($sql, 'GET_LOCK')) {
                return $this->pdo->query('SELECT ' . (int) $this->lockAvailable . ' AS acquired');
            }
            if (str_contains($sql, 'RELEASE_LOCK')) {
                return $this->pdo->query('SELECT 1 AS released');
            }
            if (str_starts_with($sql, 'SET TRANSACTION')) {
                return $this->pdo->query('SELECT 1');
            }
            $sql = str_replace(' FOR UPDATE', '', $sql);
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    public function select(mixed $columns = null): self
    {
        $this->reset();
        $this->columns = is_array($columns) ? implode(',', $columns) : ($columns ?? '*');
        return $this;
    }

    public function from(string $table): self { $this->table = $table; return $this; }
    public function where(string $column, string $operator, mixed $value): self
    {
        same('=', $operator);
        $this->conditions[] = $column . ' = ?';
        $this->parameters[] = $value;
        return $this;
    }
    public function order(array $order): self
    {
        $parts = [];
        foreach ($order as $column => $direction) {
            $parts[] = $column . ' ' . $direction;
        }
        $this->ordering = ' ORDER BY ' . implode(',', $parts);
        return $this;
    }
    public function limit(int $limit, int $offset = 0): self
    {
        $this->limitClause = ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        return $this;
    }
    private function whereSql(): string
    {
        return $this->conditions ? ' WHERE ' . implode(' AND ', $this->conditions) : '';
    }
    private function selectStatement(): PDOStatement
    {
        $statement = $this->query(
            'SELECT ' . $this->columns . ' FROM ' . $this->table . $this->whereSql() . $this->ordering . $this->limitClause,
            $this->parameters
        );
        $this->reset();
        return $statement;
    }
    public function fetch(): mixed { return $this->selectStatement()->fetch(); }
    public function fetchAll(): array { return $this->selectStatement()->fetchAll(); }
    public function insert(string $table, array $values): void
    {
        $this->query(
            'INSERT INTO ' . $table . ' (' . implode(',', array_map(static fn($column) => '`' . $column . '`', array_keys($values))) . ') VALUES ('
            . implode(',', array_fill(0, count($values), '?')) . ')',
            array_values($values)
        );
        $this->reset();
    }
    public function update(string $table, array $values): void
    {
        $this->query(
            'UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn($key) => '`' . $key . '` = ?', array_keys($values)))
            . $this->whereSql(),
            array_merge(array_values($values), $this->parameters)
        );
        $this->reset();
    }
    private function reset(): void
    {
        $this->table = '';
        $this->columns = '*';
        $this->conditions = $this->parameters = [];
        $this->ordering = $this->limitClause = '';
    }
}

class TestClients
{
    public ?int $failClient = null;
    public bool $silentFailure = false;
    public bool $failOptions = false;
    public array $writes = [];
    private array $errors = [];

    public function __construct(public TestRecord $Record)
    {
    }
    public function errors(): array { return $this->errors; }
    public function getCustomField(int $field, ?int $company = null): mixed
    {
        $this->Record->trace[] = 'model-field-read';
        $record = $this->Record->query(
            'SELECT f.* FROM client_fields f JOIN client_groups g ON g.id = f.client_group_id WHERE f.id = ?'
            . ($company === null ? '' : ' AND g.company_id = ?'),
            $company === null ? [$field] : [$field, $company]
        )->fetch();
        if ($record) {
            $record->values = json_decode($record->values ?? '{}', true);
            $record->real_name = htmlspecialchars($record->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return $record;
    }
    public function getCustomFields(int $company): array
    {
        return [$this->getCustomField(1, $company)];
    }
    public function editCustomField(int $field, array $values): void
    {
        $this->errors = [];
        if ($this->failOptions) {
            $this->errors = ['values' => 'Rejected'];
            return;
        }
        $default = $this->Record->query('SELECT `default` FROM client_fields WHERE id = ?', $field)->fetchColumn();
        if (array_key_exists('default', $values)) {
            $default = $values['default'];
            if ($default !== null && !in_array($default, $values['values'], true)) {
                $this->errors = ['default' => 'Rejected'];
                return;
            }
        }
        $this->Record->query(
            'UPDATE client_fields SET `values` = ?, `default` = ? WHERE id = ?',
            [json_encode($values['values']), $default, $field]
        );
    }
    public function getCustomFieldValues(int $client): array
    {
        $values = $this->Record->query(
            'SELECT f.*, v.value, v.encrypted AS value_encrypted FROM client_values v JOIN client_fields f'
            . ' ON f.id = v.client_field_id WHERE v.client_id = ?', $client
        )->fetchAll();
        foreach ($values as $field) {
            if ((int) $field->encrypted === 1 && (int) $field->value_encrypted === 1) {
                $field->value = base64_decode(substr($field->value, 4), true);
            }
        }
        return $values;
    }
    public function setCustomField(int $field, int $client, string $value): void
    {
        $this->errors = [];
        $record = $this->getCustomField($field);
        if ($this->failClient === $client || !in_array($value, $record->values, true)) {
            $this->errors = $this->silentFailure ? [] : ['value' => 'Rejected'];
            return;
        }
        $stored = (int) $record->encrypted === 1 ? 'enc:' . base64_encode($value) : $value;
        $this->Record->query(
            'UPDATE client_values SET value = ?, encrypted = ? WHERE client_field_id = ? AND client_id = ?',
            [$stored, $record->encrypted, $field, $client]
        );
        $this->writes[] = [$client, $value];
    }
    public function get(int $client, bool $settings = true): object
    {
        return (object) ['id' => $client, 'first_name' => 'Synthetic', 'last_name' => 'Client ' . $client];
    }
}

function database(array $options, array $assignments = [], bool $encrypted = false, ?string $default = null): TestCouncilSync
{
    $pdo = new PDO('sqlite::memory:');
    $record = new TestRecord($pdo);
    $pdo->exec('CREATE TABLE client_groups (id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL)');
    $pdo->exec('INSERT INTO client_groups VALUES (1,1)');
    $pdo->exec('CREATE TABLE client_fields (id INTEGER PRIMARY KEY, client_group_id INTEGER, name TEXT, link TEXT,
        is_lang INTEGER, type TEXT, `values` TEXT, `default` TEXT, regex TEXT, show_client INTEGER, read_only INTEGER, encrypted INTEGER)');
    $pdo->exec('CREATE TABLE client_values (client_field_id INTEGER, client_id INTEGER, value TEXT, encrypted INTEGER,
        PRIMARY KEY (client_field_id,client_id))');
    $pdo->exec('CREATE TABLE council_sync_settings (company_id INTEGER PRIMARY KEY, field_id INTEGER, staff_group_id INTEGER,
        department_id INTEGER, contact TEXT, max_drop INTEGER, frequency INTEGER, previous_count INTEGER, purge INTEGER,
        email_enabled INTEGER, ticket_enabled INTEGER, updates_enabled INTEGER)');
    $pdo->exec('CREATE TABLE council_sync_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, field_id INTEGER,
        created_at TEXT, source TEXT, staff_id INTEGER, summary TEXT, before_options TEXT, after_options TEXT,
        before_default TEXT, after_default TEXT, field_signature TEXT, status TEXT, kind TEXT, rollback_of INTEGER)');
    $pdo->exec('CREATE TABLE council_sync_items (batch_id INTEGER, client_id INTEGER, old_value TEXT, new_value TEXT, action TEXT,
        PRIMARY KEY (batch_id,client_id))');
    $pdo->exec('CREATE TABLE council_sync_issues (company_id INTEGER, field_id INTEGER, hash TEXT, kind TEXT, ticket_id INTEGER,
        notified_at TEXT, email_notified_at TEXT, ticket_notified_at TEXT, emailed_staff TEXT, PRIMARY KEY (company_id,field_id,hash))');
    $pdo->exec('CREATE TABLE council_sync_updates (company_id INTEGER PRIMARY KEY, checked_at TEXT, last_success_at TEXT,
        latest_version TEXT, release_url TEXT, error_code TEXT)');
    $record->insert('client_fields', [
        'id' => 1, 'client_group_id' => 1, 'name' => 'Council', 'link' => null, 'is_lang' => 0, 'type' => 'select',
        'values' => json_encode(array_flip($options)), 'default' => $default, 'regex' => null,
        'show_client' => 1, 'read_only' => 0, 'encrypted' => (int) $encrypted
    ]);
    $record->insert('council_sync_settings', [
        'company_id' => 1, 'field_id' => 1, 'staff_group_id' => 1, 'department_id' => null,
        'contact' => 'https://example.com/council-sync', 'max_drop' => 10, 'frequency' => 1440, 'previous_count' => null, 'purge' => 0,
        'email_enabled' => 1, 'ticket_enabled' => 1, 'updates_enabled' => 1
    ]);
    foreach ($assignments as $client => $value) {
        $record->insert('client_values', [
            'client_field_id' => 1, 'client_id' => $client, 'value' => $encrypted ? 'enc:' . base64_encode($value) : $value,
            'encrypted' => (int) $encrypted
        ]);
    }
    return new TestCouncilSync($record, new TestClients(new TestRecord(new PDO('sqlite::memory:'))));
}
