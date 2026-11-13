<?php

require dirname(__DIR__) . '/plugins/council_sync/controllers/admin_software.php';
Language::loadLang('admin_software');

class TestSoftwareSnapshot extends CouncilSyncSoftwareSnapshot
{
    protected function names(): array
    {
        return $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    }
    protected function ddl(string $table): string
    {
        $statement = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE name = ? AND type = 'table'");
        $statement->execute([$table]);
        $sql = $statement->fetchColumn();
        if (!is_string($sql)) {
            throw new CouncilSyncIssue('software', 'software_schema');
        }
        $sql = preg_replace('~^CREATE TABLE ["`]?[^\\s"`]+["`]?~', 'CREATE TABLE `' . $table . '`', $sql);
        return preg_replace('~\\s+~', ' ', $sql);
    }
    protected function beginSnapshot(): void { $this->pdo->beginTransaction(); }
    protected function fingerprint(string $table): string { return $this->ddl($table); }
    protected function assertExportable(string $table): void {}
    protected function validateShadowKeys(array $foreign, array $shadows): void {}
    protected function assertNoExternalReferences(array $tables): void
    {
        foreach (array_diff($this->names(), $tables) as $table) {
            foreach ($this->pdo->query('PRAGMA foreign_key_list("' . $table . '")')->fetchAll() as $key) {
                if (in_array($key->table, $tables, true)) {
                    throw new CouncilSyncIssue('software', 'software_external_reference');
                }
            }
        }
    }
    protected function createShadow(string $original, string $shadow, string $ddl): array
    {
        $this->pdo->exec(str_replace('CREATE TABLE `' . $original . '`', 'CREATE TABLE `' . $shadow . '`', $ddl));
        return [];
    }
    protected function swap(array $trash, array $shadows): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($trash as $live => $old) {
                $this->pdo->exec('ALTER TABLE `' . $live . '` RENAME TO `' . $old . '`');
            }
            foreach ($shadows as $live => $new) {
                $this->pdo->exec('ALTER TABLE `' . $new . '` RENAME TO `' . $live . '`');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }
    public function dropTemps(array $tables): void
    {
        foreach ($tables as $table) {
            if (!preg_match('~^csu_[a-f0-9]{16}_[a-f0-9]{8}$~D', $table)) {
                throw new RuntimeException('Unexpected temporary table');
            }
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}

class TestSoftware extends CouncilSyncSoftware
{
    public bool $allow = true;
    public bool $purgeFails = false;
    public bool $downloadFails = false;
    public int $clock;
    public ?string $archive = null;
    private string $directory;
    private string $documentRoot;

    public function __construct(
        public TestRecord $Record, public TestCouncilSync $CouncilSync, public object $PluginManager,
        ?string $directory = null, ?string $documentRoot = null
    ) {
        $this->directory = $directory ?? dirname(__DIR__) . '/plugins/council_sync';
        $this->documentRoot = $documentRoot ?? dirname(__DIR__);
        $this->clock = time();
    }
    public function authorized(int $staff, int $company): bool { return $this->allow; }
    protected function now(): int { return $this->clock; }
    protected function pluginDirectory(): string { return $this->directory; }
    protected function publicDirectories(): array { return [$this->documentRoot]; }
    protected function snapshotStore(): CouncilSyncSoftwareSnapshot
    {
        return $this->Record->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? new CouncilSyncSoftwareSnapshot($this->Record->pdo) : new TestSoftwareSnapshot($this->Record->pdo);
    }
    protected function invalidate(array $files): void {}
    protected function checkPlatform(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            parent::checkPlatform();
        }
    }
    protected function refreshNavigation(): void {}
    protected function download(string $commit, string $path): void
    {
        if ($this->downloadFails || !$this->archive || !copy($this->archive, $path)) {
            throw new CouncilSyncIssue('software', 'software_download');
        }
        chmod($path, 0600);
    }
    protected function removeTree(string $directory): void
    {
        if ($this->purgeFails) {
            throw new CouncilSyncIssue('software', 'software_purge');
        }
        parent::removeTree($directory);
    }
}

class SoftwareManager
{
    public bool $fail = false;
    public $migration = null;
    private array $errors = [];
    public function __construct(public TestRecord $Record, private string $directory) {}
    public function errors(): array { return $this->errors; }
    public function upgrade(int $id): void
    {
        $this->errors = [];
        if ($this->fail) {
            $this->errors = ['upgrade' => 'Synthetic failure'];
            return;
        }
        $package = CouncilSyncSoftware::package($this->directory);
        $from = $this->Record->query('SELECT version FROM plugins WHERE id = ?', $id)->fetchColumn();
        CouncilSyncSoftware::allowNativeUpgrade($this->Record, $from, $package['version']);
        if ($this->migration && $package['version'] === '2.0.0') {
            ($this->migration)($this->Record->pdo);
        }
        $this->Record->query('UPDATE plugins SET version = ? WHERE dir = ?', [$package['version'], 'council_sync']);
    }
}

function softwarePackage(string $directory, string $version): void
{
    mkdir($directory, 0700, true);
    $source = dirname(__DIR__) . '/plugins/council_sync';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($files as $file) {
        $target = $directory . DIRECTORY_SEPARATOR . substr($file->getPathname(), strlen($source) + 1);
        if ($file->isDir()) {
            mkdir($target, 0700);
        } elseif (!copy($file->getPathname(), $target)) {
            throw new RuntimeException('Unable to build the synthetic software package.');
        }
    }
    $config = json_decode(file_get_contents($directory . '/config.json'), true);
    $config['version'] = $version;
    file_put_contents($directory . '/config.json', json_encode($config));
}

function softwareZip(string $directory, string $path, array $extra = []): void
{
    $zip = new ZipArchive();
    same(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        $zip->addFile($file->getPathname(), 'repo-commit/plugins/council_sync/' . $relative);
    }
    foreach ($extra as $name => $content) {
        $zip->addFromString('repo-commit/plugins/council_sync/' . $name, $content);
    }
    $zip->close();
}

function softwareRig(callable $operation): void
{
    $base = dirname(__DIR__) . '/scratch/software-test-' . bin2hex(random_bytes(6));
    $admin = null;
    $database = null;
    try {
        mkdir($base . '/web/plugins', 0700, true);
        mkdir($base . '/private', 0700);
        $directory = $base . '/web/plugins/council_sync';
        softwarePackage($directory, '1.2.0');
        softwarePackage($base . '/source', '2.0.0');
        softwareZip($base . '/source', $base . '/release.zip');
        $native = getenv('COUNCIL_SOFTWARE_TEST_DSN');
        if ($native !== false && $native !== '') {
            if (!preg_match('~^mysql:host=127\.0\.0\.1;port=\d+$~D', $native)) {
                throw new RuntimeException('Native updater tests require an explicitly configured disposable loopback MySQL server.');
            }
            $user = getenv('COUNCIL_SOFTWARE_TEST_USER');
            $password = getenv('COUNCIL_SOFTWARE_TEST_PASSWORD');
            if ($user === false || $password === false) {
                throw new RuntimeException('Native updater test credentials were not supplied.');
            }
            $admin = new PDO($native, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $database = 'csu_test_' . bin2hex(random_bytes(6));
            $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $record = new TestRecord(new PDO($native . ';dbname=' . $database, $user, $password));
            $model = new TestCouncilSync($record, new TestClients($record));
            $model->installTables(1);
            $record->pdo->exec('CREATE TABLE client_groups (id INT PRIMARY KEY, company_id INT)');
            $record->pdo->exec('INSERT INTO client_groups VALUES (1,1)');
            $record->pdo->exec('CREATE TABLE client_fields (id INT PRIMARY KEY, client_group_id INT, name TEXT, link TEXT,
                is_lang INT, type TEXT, `values` TEXT, `default` TEXT, regex TEXT, show_client INT, read_only INT, encrypted INT)');
            $record->pdo->exec('CREATE TABLE client_values (client_field_id INT, client_id INT, value TEXT, encrypted INT,
                PRIMARY KEY (client_field_id,client_id))');
            $record->insert('client_fields', [
                'id' => 1, 'client_group_id' => 1, 'name' => 'Council', 'link' => null, 'is_lang' => 0, 'type' => 'select',
                'values' => '{"Old":"old"}', 'default' => null, 'regex' => null, 'show_client' => 1, 'read_only' => 0, 'encrypted' => 1
            ]);
            $record->insert('client_values', ['client_field_id' => 1, 'client_id' => 1, 'value' => 'enc:' . base64_encode('old'), 'encrypted' => 1]);
            $record->pdo->exec("UPDATE council_sync_settings SET field_id = 1, staff_group_id = 1, contact = 'https://example.com/contact'");
        } else {
            $model = database(['old' => 'Old'], [1 => 'old'], true);
        }
        plugin($model);
        $model->bindCoreConnection($model->Clients);
        $pdo = $model->Record->pdo;
        $definitions = [
            'plugins' => 'id INTEGER PRIMARY KEY, company_id INTEGER, dir TEXT, name TEXT, version TEXT, enabled INTEGER',
            'permission_groups' => 'id INTEGER PRIMARY KEY, plugin_id INTEGER, alias TEXT, name TEXT, level TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, plugin_id INTEGER, group_id INTEGER, alias TEXT, name TEXT, action TEXT',
            'actions' => 'id INTEGER PRIMARY KEY, plugin_id INTEGER, company_id INTEGER, url TEXT, location TEXT',
            'navigation_items' => 'id INTEGER PRIMARY KEY, action_id INTEGER, parent_id INTEGER, company_id INTEGER',
            'plugin_cards' => 'id INTEGER PRIMARY KEY, plugin_id INTEGER',
            'plugin_events' => 'id INTEGER PRIMARY KEY, plugin_id INTEGER',
            'cron_tasks' => 'id INTEGER PRIMARY KEY, dir TEXT, task_type TEXT',
            'email_groups' => 'id INTEGER PRIMARY KEY, plugin_dir TEXT, action TEXT',
            'emails' => 'id INTEGER PRIMARY KEY, email_group_id INTEGER, company_id INTEGER, subject TEXT',
            'invoices' => 'id INTEGER PRIMARY KEY, description TEXT'
        ];
        foreach ($definitions as $table => $columns) {
            $pdo->exec('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        $model->Record->insert('council_sync_batches', [
            'id' => 1, 'company_id' => 1, 'field_id' => 1, 'created_at' => '2026-01-01 00:00:00',
            'source' => 'manual', 'staff_id' => 1, 'summary' => '{}', 'before_options' => '{}', 'after_options' => '{}',
            'before_default' => null, 'after_default' => null, 'field_signature' => str_repeat('b', 64),
            'status' => 'applied', 'kind' => 'update', 'rollback_of' => null
        ]);
        $model->Record->insert('council_sync_items', [
            'batch_id' => 1, 'client_id' => 1, 'old_value' => 'enc:' . base64_encode('old'),
            'new_value' => 'enc:' . base64_encode('old'), 'action' => 'remap'
        ]);
        $pdo->exec("INSERT INTO plugins VALUES (1,1,'council_sync','Council Sync','1.2.0',1), (2,1,'unrelated','Unrelated','7.0.0',1)");
        $pdo->exec("INSERT INTO cron_tasks VALUES (1,'council_sync','plugin')");
        $pdo->exec("INSERT INTO email_groups VALUES (1,'council_sync','CouncilSync.issue'),(2,'other','Other.issue')");
        $pdo->exec("INSERT INTO emails VALUES (1,1,1,'Before'),(2,2,1,'Unrelated')");
        $manager = new SoftwareManager($model->Record, $directory);
        $software = new TestSoftware($model->Record, $model, $manager, $directory, $base . '/web');
        $software->archive = $base . '/release.zip';
        $model->installRelease = ['version' => '2.0.0', 'commit' => str_repeat('a', 40)];
        $software->saveConfiguration(1, 1, ['backup_dir' => $base . '/private', 'window_hours' => '48', 'confirm_private' => '1']);
        $operation($software, $model, $base, $manager);
    } finally {
        if (is_dir($base)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isLink() || !$file->isDir()) {
                    unlink($file->getPathname());
                } else {
                    rmdir($file->getPathname());
                }
            }
            rmdir($base);
        }
        if ($admin && $database) {
            $admin->exec('DROP DATABASE `' . $database . '`');
        }
    }
}

function installTrial(TestSoftware $software): array
{
    $software->install(1, 1, '2.0.0');
    $pending = $software->banner();
    same('files_ready', $pending['phase']);
    same(null, $pending['remaining']);
    $software->finish(1, 1, $pending['id']);
    $trial = $software->banner();
    same('trial', $trial['phase']);
    return $trial;
}

test('software install starts the 48-hour clock only after native upgrade succeeds', function () {
    softwareRig(function ($software, $model, $base) {
        $software->install(1, 1, '2.0.0');
        $pending = $software->banner();
        same('files_ready', $pending['phase']);
        same(null, $pending['remaining']);
        same('1.2.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
        issue('software_maintenance', fn() => $model->locked(1, fn() => null));
        $software->clock += 600;
        $software->finish(1, 1, $pending['id']);
        $trial = $software->banner();
        same(172800, $trial['remaining']);
        same(true, $trial['can_preserve']);
        same('2.0.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
        same(true, is_file($base . '/private/' . $trial['id'] . '/before/config.json'));
        same('old', $model->Clients->getCustomFieldValues(1)[0]->value);
    });
});

test('commit removes backup and management banner without reverting data', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $model->Record->query("UPDATE council_sync_settings SET max_drop = 25");
        $software->commit(1, 1, $trial['id']);
        same(null, $software->banner());
        same(false, is_dir($base . '/private/' . $trial['id']));
        same(25, $model->settings(1)['max_drop']);
        same('2.0.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
    });
});

test('data-preserving software rollback retains current settings and unrelated billing and client values', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $model->Record->query('UPDATE council_sync_settings SET max_drop = 30');
        $model->Record->query("INSERT INTO invoices VALUES (1,'New invoice')");
        $model->Record->query("UPDATE emails SET subject = 'New customization' WHERE id = 1");
        $software->rollback(1, 1, $trial['id'], 'preserve');
        same('rollback_ready', $software->banner()['phase']);
        $software->finish(1, 1, $trial['id']);
        same(null, $software->banner());
        same('1.2.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
        same(30, $model->settings(1)['max_drop']);
        same('New customization', $model->Record->query('SELECT subject FROM emails WHERE id = 1')->fetchColumn());
        same('New invoice', $model->Record->query('SELECT description FROM invoices')->fetchColumn());
        same('old', $model->Clients->getCustomFieldValues(1)[0]->value);
        same(false, is_dir($base . '/private/' . $trial['id']));
    });
});

test('changed schema blocks preserve mode; scoped snapshot restoration leaves unrelated data untouched', function () {
    softwareRig(function ($software, $model, $base, $manager) {
        $manager->migration = static function ($pdo) {
            $pdo->exec('ALTER TABLE council_sync_settings ADD COLUMN new_column TEXT');
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $pdo->exec('ALTER TABLE council_sync_settings ADD CONSTRAINT software_test_check CHECK (max_drop >= 0)');
            }
        };
        $trial = installTrial($software);
        same(false, $trial['can_preserve']);
        issue('software_preserve', fn() => $software->rollback(1, 1, $trial['id'], 'preserve'));
        $model->Record->query('UPDATE council_sync_settings SET max_drop = 50');
        $model->Record->query("UPDATE emails SET subject = 'After' WHERE id = 1");
        $model->Record->query("INSERT INTO invoices VALUES (1,'Keep this')");
        $software->rollback(1, 1, $trial['id'], 'restore');
        $software->finish(1, 1, $trial['id']);
        same(null, $software->banner());
        same(10, $model->settings(1)['max_drop']);
        same('Before', $model->Record->query('SELECT subject FROM emails WHERE id = 1')->fetchColumn());
        same('Unrelated', $model->Record->query('SELECT subject FROM emails WHERE id = 2')->fetchColumn());
        same('7.0.0', $model->Record->query('SELECT version FROM plugins WHERE id = 2')->fetchColumn());
        same('Keep this', $model->Record->query('SELECT description FROM invoices')->fetchColumn());
        same('old', $model->Clients->getCustomFieldValues(1)[0]->value);
    });
});

test('expiry is exact and cleanup never automatically restores plugin data', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $model->Record->query('UPDATE council_sync_settings SET max_drop = 35');
        $software->clock += 172799;
        $software->cleanup();
        same(1, $software->banner()['remaining']);
        $software->clock++;
        $software->cleanup();
        same(null, $software->banner());
        same('2.0.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
        same(35, $model->settings(1)['max_drop']);
        same(false, is_dir($base . '/private/' . $trial['id']));
    });
});

test('expired rollback is rejected even before the cleanup cron runs', function () {
    softwareRig(function ($software) {
        $trial = installTrial($software);
        $software->clock += 172800;
        issue('software_expired', fn() => $software->rollback(1, 1, $trial['id'], 'restore'));
        same(null, $software->banner());
    });
});

test('configured trial duration is frozen and settings cannot move an active backup', function () {
    softwareRig(function ($software, $model, $base) {
        $software->saveConfiguration(1, 1, ['backup_dir' => $base . '/private', 'window_hours' => '2', 'confirm_private' => '1']);
        $trial = installTrial($software);
        same(7200, $trial['remaining']);
        issue('software_pending', fn() => $software->saveConfiguration(1, 1, [
            'backup_dir' => $base . '/private', 'window_hours' => '99', 'confirm_private' => '1'
        ]));
    });
});

test('failed install retains recovery after 48 hours and can be explicitly discarded before code changes', function () {
    softwareRig(function ($software, $model, $base) {
        $software->downloadFails = true;
        issue('software_download', fn() => $software->install(1, 1, '2.0.0'));
        $failed = $software->banner();
        same('failed', $failed['phase']);
        $software->clock += 172801;
        $software->cleanup();
        same(true, is_dir($base . '/private/' . $failed['id']));
        same('failed', $software->banner()['phase']);
        $software->commit(1, 1, $failed['id']);
        same(null, $software->banner());
        same('1.2.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
    });
});

test('private storage and authorization are mandatory before any installation', function () {
    softwareRig(function ($software, $model, $base) {
        issue('software_private', fn() => $software->saveConfiguration(1, 1, [
            'backup_dir' => $base . '/web', 'window_hours' => '48', 'confirm_private' => '1'
        ]));
        $software->allow = false;
        issue('software_permission', fn() => $software->install(1, 1, '2.0.0'));
        same(null, $software->banner());
    });
});

test('archive extraction rejects traversal, unsafe PHP and case-colliding payloads', function () {
    softwareRig(function ($software, $model, $base) {
        foreach ([
            ['../escape.php' => '<?php'],
            ['bad.php' => '<?php function ('],
            ['CONFIG.json' => '{}']
        ] as $index => $extra) {
            softwareZip($base . '/source', $base . '/bad.zip', $extra);
            issue('software_archive', fn() => CouncilSyncSoftware::extract($base . '/bad.zip', $base . '/bad-' . $index));
        }
        same(false, is_file($base . '/escape.php'));
    });
});

test('purge failure remains visible and can be retried manually without restoring data', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $software->purgeFails = true;
        issue('software_purge', fn() => $software->commit(1, 1, $trial['id']));
        same('purging', $software->banner()['phase']);
        $software->purgeFails = false;
        $software->commit(1, 1, $trial['id']);
        same(null, $software->banner());
        same(false, is_dir($base . '/private/' . $trial['id']));
    });
});

class TestSoftwareAcl
{
    public array $grants = [];
    public function check(string $aro, string $aco, string $action): bool
    {
        return in_array([$aro, $aco, $action], $this->grants, true);
    }
}

class NativeSoftwareAuthorization extends CouncilSyncSoftware
{
    public function __construct(public TestGroups $StaffGroups, public TestSoftwareAcl $Acl) {}
    public function roots(): array { return parent::publicDirectories(); }
}

test('ordinary management permission does not grant software installation authority', function () {
    $acl = new TestSoftwareAcl();
    $acl->grants = [['staff_group_1', 'council_sync.admin_main', '*'], ['staff_group_1', 'council_sync', '*']];
    $software = new NativeSoftwareAuthorization(new TestGroups(), $acl);
    same(false, $software->authorized(1, 1));
    $acl->grants[] = ['staff_group_1', 'council_sync_software', '*'];
    same(true, $software->authorized(1, 1));
    same(false, $software->authorized(2, 1));
});

test('CLI cleanup can validate known roots without HTTP DOCUMENT_ROOT', function () {
    if (!defined('ROOTWEBDIR')) {
        define('ROOTWEBDIR', dirname(__DIR__));
    }
    $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
    unset($_SERVER['DOCUMENT_ROOT']);
    try {
        $software = new NativeSoftwareAuthorization(new TestGroups(), new TestSoftwareAcl());
        same([ROOTWEBDIR], $software->roots());
    } finally {
        if ($previous !== null) {
            $_SERVER['DOCUMENT_ROOT'] = $previous;
        }
    }
});

test('failed native upgrade does not start the clock or permit data-preserving rollback', function () {
    softwareRig(function ($software, $model, $base, $manager) {
        $software->install(1, 1, '2.0.0');
        $pending = $software->banner();
        $manager->fail = true;
        issue('software_upgrade', fn() => $software->finish(1, 1, $pending['id']));
        same('failed', $software->banner()['phase']);
        same(null, $software->banner()['remaining']);
        $software->clock += 200000;
        $software->cleanup();
        same(true, is_dir($base . '/private/' . $pending['id']));
        issue('software_preserve', fn() => $software->rollback(1, 1, $pending['id'], 'preserve'));
        $manager->fail = false;
        $software->rollback(1, 1, $pending['id'], 'restore');
        $software->finish(1, 1, $pending['id']);
        same(null, $software->banner());
    });
});

test('a failed native finalization can be retried in a fresh request', function () {
    softwareRig(function ($software, $model, $base, $manager) {
        $software->install(1, 1, '2.0.0');
        $pending = $software->banner();
        $manager->fail = true;
        issue('software_upgrade', fn() => $software->finish(1, 1, $pending['id']));
        same(true, $software->banner()['can_finish']);
        $manager->fail = false;
        $software->finish(1, 1, $pending['id']);
        same(172800, $software->banner()['remaining']);
        $software->commit(1, 1, $pending['id']);
    });
});

test('backup corruption is detected before restoring any live data', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $model->Record->query('UPDATE council_sync_settings SET max_drop = 31');
        file_put_contents($base . '/private/' . $trial['id'] . '/core_emails.gz', 'damaged');
        issue('software_snapshot', fn() => $software->rollback(1, 1, $trial['id'], 'restore'));
        same(31, $model->settings(1)['max_drop']);
        same('2.0.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
        $software->commit(1, 1, $trial['id']);
        same(null, $software->banner());
    });
});

test('restore mode removes newly declared plugin tables but never unrelated tables', function () {
    softwareRig(function ($software, $model, $base, $manager) {
        $file = $base . '/source/config.json';
        $package = json_decode(file_get_contents($file), true);
        $package['software_update']['data_tables'][] = 'council_sync_added';
        file_put_contents($file, json_encode($package));
        softwareZip($base . '/source', $base . '/release.zip');
        $manager->migration = static fn($pdo) => $pdo->exec('CREATE TABLE council_sync_added (id INTEGER PRIMARY KEY, value TEXT)');
        $trial = installTrial($software);
        same(false, $trial['can_preserve']);
        $software->rollback(1, 1, $trial['id'], 'restore');
        $software->finish(1, 1, $trial['id']);
        same(null, $software->banner());
        $store = $model->Record->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? new CouncilSyncSoftwareSnapshot($model->Record->pdo) : new TestSoftwareSnapshot($model->Record->pdo);
        same(false, in_array('council_sync_added', $store->actualDataTables(), true));
        same(0, (int) $model->Record->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    });
});

test('code modifications block automatic expiry purge and keep recovery files', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        file_put_contents($base . '/web/plugins/council_sync/council_sync_plugin.php', "<?php\n// changed locally\n");
        $software->clock += 172800;
        issue('software_files_changed', fn() => $software->cleanup());
        same(true, is_dir($base . '/private/' . $trial['id']));
    });
});

test('archive extraction rejects symbolic-link payloads', function () {
    softwareRig(function ($software, $model, $base) {
        $zip = new ZipArchive();
        $zip->open($base . '/release.zip');
        $zip->addFromString('repo-commit/plugins/council_sync/linked.php', '../outside');
        $zip->setExternalAttributesName('repo-commit/plugins/council_sync/linked.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        issue('software_archive', fn() => CouncilSyncSoftware::extract($base . '/release.zip', $base . '/linked'));
    });
});

test('a release missing recovery dependencies is rejected before replacing installed files', function () {
    softwareRig(function ($software, $model, $base) {
        $zip = new ZipArchive();
        same(true, $zip->open($base . '/release.zip'));
        same(true, $zip->deleteName('repo-commit/plugins/council_sync/models/software_snapshot.php'));
        same(true, $zip->close());
        issue('software_protocol', fn() => $software->install(1, 1, '2.0.0'));
        same('1.2.0', CouncilSyncSoftware::package($base . '/web/plugins/council_sync')['version']);
        same('1.2.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
        same(null, $software->banner()['remaining']);
    });
});

class SoftwareTagProbe extends TestCouncilSync
{
    public array $objects = [];
    public array $paths = [];
    protected function httpGet(string $url, string $agent, int $limit, array $headers = ['Accept: application/json']): array
    {
        $this->paths[] = $url;
        if (!$this->objects) {
            throw new RuntimeException('Unexpected network request');
        }
        return ['status' => 200, 'body' => json_encode(['object' => array_shift($this->objects)])];
    }
}

test('software archives resolve the release tag to an immutable commit, not empty main', function () {
    $base = database([]);
    $model = new SoftwareTagProbe($base->Record, $base->Clients);
    $model->release('v2.0.0');
    $model->objects = [['type' => 'tag', 'sha' => str_repeat('a', 40)], ['type' => 'commit', 'sha' => str_repeat('b', 40)]];
    same(str_repeat('b', 40), $model->softwareRelease()['commit']);
    same('https://api.github.com/repos/' . CouncilSync::REPOSITORY . '/git/ref/tags/v2.0.0', $model->paths[0]);
    same('https://api.github.com/repos/' . CouncilSync::REPOSITORY . '/git/tags/' . str_repeat('a', 40), $model->paths[1]);
});

function runSoftwareAction(AdminSoftware $controller, string $method): void
{
    $_SERVER['REQUEST_URI'] = '/billing/admin/plugin/council_sync/admin_software/' . $method . '/';
    try {
        $controller->$method();
    } catch (TestRedirect $redirect) {
        $controller->variables['redirect'] = $redirect->getMessage();
    }
}

test('software actions require CSRF and explicit data-loss confirmation', function () {
    softwareRig(function ($software, $model, $base) {
        Loader::$models['CouncilSyncSoftware'] = $software;
        $controller = new AdminSoftware();
        $controller->preAction();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller->post = ['confirm_install' => '1', 'version' => '2.0.0', '_csrf_token' => 'invalid'];
        runSoftwareAction($controller, 'install');
        same(null, $software->banner());
        $trial = installTrial($software);
        $controller->post = [
            'id' => $trial['id'], 'mode' => 'restore', 'confirm_rollback' => '1',
            '_csrf_token' => $controller->Form->token('/billing/admin/plugin/council_sync/admin_software/rollback/')
        ];
        runSoftwareAction($controller, 'rollback');
        same('trial', $software->banner()['phase']);
        same('2.0.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
    });
});

test('software operators can check releases with CSRF without sending notices', function () {
    softwareRig(function ($software, $model) {
        Loader::$models['CouncilSyncSoftware'] = $software;
        $model->release('v2.0.0');
        $model->Record->query('UPDATE council_sync_settings SET updates_enabled = 0');
        $controller = new AdminSoftware();
        $controller->preAction();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        runSoftwareAction($controller, 'checkupdates');
        same(0, $model->releaseRequests);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller->post = ['_csrf_token' => 'invalid'];
        runSoftwareAction($controller, 'checkupdates');
        same(0, $model->releaseRequests);
        $uri = '/billing/admin/plugin/council_sync/admin_software/';
        $controller->post = ['_csrf_token' => $controller->Form->token($uri . 'checkupdates/')];
        runSoftwareAction($controller, 'checkupdates');
        same(1, $model->releaseRequests);
        same('outdated', $model->versionStatus(1)['status']);
        same([], Loader::$models['Emails']->sent);
        same([], Loader::$models['SupportManagerTickets']->added);
        same($uri, $controller->variables['redirect']);
    });
});

test('software settings show the release and withhold installation on unsupported hosts', function () {
    softwareRig(function ($software, $model) {
        $model->release('v2.0.0');
        $model->checkForUpdates(1, true);
        $renderer = new TestRenderer();
        $uri = '/billing/admin/plugin/council_sync/admin_software/';
        $variables = [
            'software_trial' => null, 'software_allowed' => true, 'software_uri' => $uri,
            'sync_uri' => '/billing/admin/plugin/council_sync/admin_main/index/',
            'configuration' => $software->configuration(), 'version_status' => $model->versionStatus(1),
            'install_supported' => true
        ];
        $html = $renderer->render('admin_software', $variables);
        same(true, str_contains($html, '1.2.0 / 1.2.0 / 2.0.0'));
        same(true, str_contains($html, 'action="' . $uri . 'install/"'));
        same(true, str_contains($html, $renderer->Form->token($uri . 'checkupdates/')));
        $variables['install_supported'] = false;
        $html = $renderer->render('admin_software', $variables);
        same(false, str_contains($html, 'action="' . $uri . 'install/"'));
        same(true, str_contains($html, Language::_('AdminSoftware.platform_requirement', true)));
    });
});

test('management banner renders the clock and choices, then disappears after commit', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $renderer = new TestRenderer();
        $variables = ['software_trial' => $trial, 'software_allowed' => true, 'software_uri' => '/billing/admin/plugin/council_sync/admin_software/'];
        $html = $renderer->render('software_banner', $variables);
        same(true, str_contains($html, 'data-seconds="172800"'));
        same(true, str_contains($html, 'value="preserve"'));
        same(true, str_contains($html, 'value="restore"'));
        same(true, str_contains($html, 'confirm_data_loss'));
        same(true, str_contains($html, $renderer->Form->token($variables['software_uri'] . 'rollback/')));
        $software->commit(1, 1, $trial['id']);
        $variables['software_trial'] = $software->banner();
        same('', $renderer->render('software_banner', $variables));
    });
});

test('cleanup cron is independent of disabled release checks', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $model->Record->query('UPDATE council_sync_settings SET updates_enabled = 0');
        Loader::$models['CouncilSyncSoftware'] = $software;
        $software->clock += 172800;
        $plugin = new CouncilSyncPlugin();
        $plugin->cron('software_cleanup');
        same(null, $software->banner());
        same(0, $model->releaseRequests);
    });
});

test('one software lock blocks council work across companies before any files change', function () {
    softwareRig(function ($software, $model, $base) {
        $peer = null;
        $name = CouncilSyncSoftware::lockBase($model->Record) . ':software';
        if ($model->Record->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $database = $model->Record->pdo->query('SELECT DATABASE()')->fetchColumn();
            $peer = new PDO(getenv('COUNCIL_SOFTWARE_TEST_DSN') . ';dbname=' . $database,
                getenv('COUNCIL_SOFTWARE_TEST_USER'), getenv('COUNCIL_SOFTWARE_TEST_PASSWORD'));
            $statement = $peer->prepare('SELECT GET_LOCK(?, 0)');
            $statement->execute([$name]);
            same(1, (int) $statement->fetchColumn());
        } else {
            $model->Record->lockAvailable = false;
        }
        try {
            issue('busy', fn() => $model->locked(2, fn() => null));
            issue('busy', fn() => $software->install(1, 1, '2.0.0'));
            same(null, $software->banner());
        } finally {
            $model->Record->lockAvailable = true;
            if ($peer) {
                $statement = $peer->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$name]);
            }
        }
    });
});

test('rollback preserves original restricted files and empty directories', function () {
    softwareRig(function ($software, $model, $base) {
        $directory = $base . '/web/plugins/council_sync';
        file_put_contents($directory . '/private.txt', 'synthetic private content');
        chmod($directory . '/private.txt', 0600);
        mkdir($directory . '/empty', 0700);
        $trial = installTrial($software);
        $software->rollback(1, 1, $trial['id'], 'preserve');
        $software->finish(1, 1, $trial['id']);
        same('synthetic private content', file_get_contents($directory . '/private.txt'));
        same(true, is_dir($directory . '/empty'));
        if (DIRECTORY_SEPARATOR !== '\\') {
            same(0600, fileperms($directory . '/private.txt') & 0777);
            same(0700, fileperms($directory . '/empty') & 0777);
        }
    });
});
