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
    protected function downloadRequest(string $url, $stream): array
    {
        if ($this->downloadFails || !$this->archive) {
            throw new CouncilSyncIssue('software', 'software_download');
        }
        $source = fopen($this->archive, 'rb');
        try {
            if (stream_copy_to_stream($source, $stream) === false) {
                throw new RuntimeException('Unable to copy the synthetic release asset.');
            }
        } finally {
            fclose($source);
        }
        return ['status' => 200, 'location' => null];
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
        $zip->addFile($file->getPathname(), 'council_sync/' . $relative);
    }
    foreach ($extra as $name => $content) {
        $zip->addFromString('council_sync/' . $name, $content);
    }
    $zip->close();
}

function archiveAsset(string $path): array
{
    clearstatcache(true, $path);
    return ['id' => 1, 'size' => filesize($path), 'sha256' => hash_file('sha256', $path)];
}

function developmentZip(string $directory, string $path, string $commit): void
{
    $zip = new ZipArchive();
    same(true, $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
    $prefix = 'council-sync-blesta-plugin-' . $commit . '/';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        $zip->addFile($file->getPathname(), $prefix . 'plugins/council_sync/' . $relative);
    }
    foreach (['README.md', 'LICENSE', 'CHANGELOG.md'] as $name) {
        $zip->addFile(dirname(__DIR__) . '/' . $name, $prefix . $name);
    }
    $zip->addFromString($prefix . 'tests/ignored.php', '<?php');
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
                'values' => serialize(['Old' => 'old']), 'default' => null, 'regex' => null, 'show_client' => 1, 'read_only' => 0, 'encrypted' => 1
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
        $model->installRelease = [
            'version' => '2.0.0', 'commit' => str_repeat('a', 40),
            'asset' => archiveAsset($base . '/release.zip')
        ];
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

function developmentRig(callable $operation): void
{
    softwareRig(function ($software, $model, $base, $manager) use ($operation) {
        $path = $base . '/web/plugins/council_sync/config.json';
        $config = json_decode(file_get_contents($path), true);
        $config['version'] = '1.2.3-beta.1';
        $config['software_update']['development_branch'] = 'dev';
        file_put_contents($path, json_encode($config));
        $model->Record->query('UPDATE plugins SET version = ? WHERE id = 1', $config['version']);
        $model->development = true;
        $commit = str_repeat('d', 40);
        developmentZip($base . '/source', $base . '/dev.zip', $commit);
        $software->archive = $base . '/dev.zip';
        $model->installRelease = [
            'version' => '2.0.0', 'development' => true, 'commit' => $commit,
            'manifest_sha256' => hash_file('sha256', $base . '/source/config.json')
        ];
        $operation($software, $model, $base, $manager, $commit);
    });
}

test('beta versions require the explicit fixed-dev flag and rollback capability', function () {
    issue('version_manifest', fn() => CouncilSync::manifestVersion(['version' => '1.2.3-beta.1']));
    foreach ([
        ['development_branch' => 'main', 'development_protocol' => 1],
        ['development_branch' => 'dev'],
        ['development_branch' => 'dev', 'development_protocol' => 2]
    ] as $settings) {
        issue('version_manifest', fn() => CouncilSync::manifestVersion(['version' => '1.2.3-beta.1', 'software_update' => $settings]));
    }
    $settings = ['development_branch' => 'dev', 'development_protocol' => 1];
    same('1.2.3-beta.1', CouncilSync::manifestVersion(['version' => '1.2.3-beta.1', 'software_update' => $settings]));
    same(true, version_compare('1.2.3', '1.2.3-beta.1', '>'));
});

test('a dev trial pins its commit, clears the test flag on install and restores it on rollback', function () {
    developmentRig(function ($software, $model, $base, $manager, $commit) {
        issue('software_version', fn() => $software->install(1, 1, '2.0.0'));
        issue('software_version', fn() => $software->install(1, 1, '2.0.0', str_repeat('a', 40)));
        same(null, $software->banner());
        $software->install(1, 1, '2.0.0', $commit);
        $software->finish(1, 1, $software->banner()['id']);
        $config = CouncilSyncSoftware::package($base . '/web/plugins/council_sync');
        same('2.0.0', $config['version']);
        same(false, isset($config['software_update']['development_branch']));
        same(false, is_dir($base . '/web/plugins/council_sync/tests'));
        $software->rollbackUpdate(1, 1, $software->banner()['id'], 'preserve');
        $software->finish(1, 1, $software->banner()['id']);
        $config = CouncilSyncSoftware::package($base . '/web/plugins/council_sync');
        same('1.2.3-beta.1', $config['version']);
        same('dev', $config['software_update']['development_branch']);
        same(null, $software->banner());
        same('old', $model->Clients->getCustomFieldValues(1)[0]->value);
    });
});

test('dev archives reject a wrong commit or manifest without replacing installed software', function () {
    developmentRig(function ($software, $model, $base, $manager, $commit) {
        $wrong = $model->installRelease;
        $wrong['commit'] = str_repeat('a', 40);
        issue('software_archive', fn() => CouncilSyncSoftware::extract($base . '/dev.zip', $base . '/wrong-commit', $wrong));
        $model->installRelease['manifest_sha256'] = str_repeat('0', 64);
        issue('software_development', fn() => $software->install(1, 1, '2.0.0', $commit));
        same('failed', $software->banner()['phase']);
        same('1.2.3-beta.1', CouncilSyncSoftware::package($base . '/web/plugins/council_sync')['version']);
    });
});

test('a normal release cannot silently enable development updating', function () {
    softwareRig(function ($software, $model, $base) {
        $path = $base . '/source/config.json';
        $config = json_decode(file_get_contents($path), true);
        $config['software_update']['development_branch'] = 'dev';
        file_put_contents($path, json_encode($config));
        softwareZip($base . '/source', $base . '/release.zip');
        $model->installRelease['asset'] = archiveAsset($base . '/release.zip');
        issue('software_development', fn() => $software->install(1, 1, '2.0.0'));
        same('1.2.0', CouncilSyncSoftware::package($base . '/web/plugins/council_sync')['version']);
        same('failed', $software->banner()['phase']);
    });
});

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
        $software->commitUpdate(1, 1, $trial['id']);
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
        $software->rollbackUpdate(1, 1, $trial['id'], 'preserve');
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
        issue('software_preserve', fn() => $software->rollbackUpdate(1, 1, $trial['id'], 'preserve'));
        $model->Record->query('UPDATE council_sync_settings SET max_drop = 50');
        $model->Record->query("UPDATE emails SET subject = 'After' WHERE id = 1");
        $model->Record->query("INSERT INTO invoices VALUES (1,'Keep this')");
        $software->rollbackUpdate(1, 1, $trial['id'], 'restore');
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
        issue('software_expired', fn() => $software->rollbackUpdate(1, 1, $trial['id'], 'restore'));
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
        $software->commitUpdate(1, 1, $failed['id']);
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
            ['models/bad.php' => '<?php function ('],
            ['CONFIG.json' => '{}']
        ] as $index => $extra) {
            softwareZip($base . '/source', $base . '/bad.zip', $extra);
            issue('software_archive', fn() => CouncilSyncSoftware::extract($base . '/bad.zip', $base . '/bad-' . $index));
        }
        same(false, is_file($base . '/escape.php'));
    });
});

test('release ZIP installs bundled docs while repository-shaped archives and test files are rejected', function () {
    softwareRig(function ($software, $model, $base) {
        foreach (['README.md', 'LICENSE', 'CHANGELOG.md'] as $name) {
            copy(dirname(__DIR__) . '/' . $name, $base . '/source/' . $name);
        }
        softwareZip($base . '/source', $base . '/docs.zip');
        $software->archive = $base . '/docs.zip';
        $model->installRelease['asset'] = archiveAsset($software->archive);
        installTrial($software);
        foreach (['README.md', 'LICENSE', 'CHANGELOG.md'] as $name) {
            same(true, is_file($base . '/web/plugins/council_sync/' . $name));
        }
        $zip = new ZipArchive();
        $zip->open($base . '/docs.zip');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        foreach ($names as $name) {
            $zip->renameName($name, 'repo-commit/plugins/' . $name);
        }
        $zip->close();
        issue('software_archive', fn() => CouncilSyncSoftware::extract($base . '/docs.zip', $base . '/old-shape'));
        softwareZip($base . '/source', $base . '/tests.zip', ['tests/private.php' => '<?php']);
        issue('software_archive', fn() => CouncilSyncSoftware::extract($base . '/tests.zip', $base . '/test-files'));
    });
});

test('release asset checksum mismatch retains recovery state without swapping plugin files', function () {
    softwareRig(function ($software, $model, $base) {
        $model->installRelease['asset']['sha256'] = str_repeat('0', 64);
        issue('software_download', fn() => $software->install(1, 1, '2.0.0'));
        same('failed', $software->banner()['phase']);
        same('1.2.0', CouncilSyncSoftware::package($base . '/web/plugins/council_sync')['version']);
        same('old', $model->Clients->getCustomFieldValues(1)[0]->value);
    });
});

class AssetDownloadProbe extends CouncilSyncSoftware
{
    public array $responses = [];
    public array $urls = [];
    public function __construct() {}
    protected function downloadRequest(string $url, $stream): array
    {
        $this->urls[] = $url;
        if (!$this->responses) {
            throw new RuntimeException('Unexpected release asset request.');
        }
        $response = array_shift($this->responses);
        if ($response['status'] === 200) {
            fwrite($stream, $response['body']);
        }
        return $response;
    }
    public function fetch(array $release, string $path): void { parent::download($release, $path); }
}

class AssetWireProbe extends CouncilSyncSoftware
{
    public function __construct() {}
    public function transfer(string $url, $stream): array { return parent::downloadRequest($url, $stream); }
}

test('asset HTTP transport parses response headers, discards redirect bodies and rejects duplicate locations', function () {
    softwareRig(function ($software, $model, $base) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        $router = $base . '/asset-router.php';
        file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect' || $path === '/duplicate') {
    http_response_code(302);
    header('Location: https://release-assets.githubusercontent.com/example', false);
    if ($path === '/duplicate') {
        header('Location: https://release-assets.githubusercontent.com/other', false);
    }
    echo 'Discard this redirect response body.';
} else {
    header('Content-Type: application/octet-stream');
    echo 'Synthetic asset response.';
}
PHP
        );
        $process = proc_open([PHP_BINARY, '-S', $address, $router], [
            0 => ['pipe', 'r'], 1 => ['file', $base . '/asset-server.log', 'a'],
            2 => ['file', $base . '/asset-server.log', 'a']
        ], $pipes, $base, []);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the loopback asset test server.');
        }
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 5;
            do {
                $ready = str_contains(file_get_contents($base . '/asset-server.log'), 'Development Server');
                if ($ready || !proc_get_status($process)['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            same(true, $ready);
            $probe = new AssetWireProbe();
            foreach (['ok', 'redirect', 'duplicate'] as $route) {
                $stream = fopen($base . '/wire-' . $route, 'x+b');
                try {
                    if ($route === 'duplicate') {
                        issue('software_download', fn() => $probe->transfer('http://' . $address . '/' . $route, $stream));
                    } else {
                        $result = $probe->transfer('http://' . $address . '/' . $route, $stream);
                        same($route === 'ok' ? 200 : 302, $result['status']);
                        rewind($stream);
                        same($route === 'ok' ? 'Synthetic asset response.' : '', stream_get_contents($stream));
                        if ($route === 'redirect') {
                            same('https://release-assets.githubusercontent.com/example', $result['location']);
                        }
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    });
});

test('release assets accept direct or one approved HTTPS redirect and verify exact bytes', function () {
    softwareRig(function ($software, $model, $base) {
        $body = 'Synthetic release bytes.';
        $release = ['asset' => ['id' => 42, 'size' => strlen($body), 'sha256' => hash('sha256', $body)]];
        foreach ([false, true] as $redirect) {
            $probe = new AssetDownloadProbe();
            if ($redirect) {
                $probe->responses[] = ['status' => 302, 'location' => 'https://release-assets.githubusercontent.com/example?test=1'];
            }
            $probe->responses[] = ['status' => 200, 'body' => $body];
            $probe->fetch($release, $base . '/transport-' . (int) $redirect . '.zip');
            same('https://api.github.com/repos/' . CouncilSync::REPOSITORY . '/releases/assets/42', $probe->urls[0]);
            same($redirect ? 2 : 1, count($probe->urls));
        }
        foreach ([
            ['size' => strlen($body) + 1],
            ['sha256' => str_repeat('0', 64)]
        ] as $index => $invalid) {
            $probe = new AssetDownloadProbe();
            $probe->responses[] = ['status' => 200, 'body' => $body];
            issue('software_download', fn() => $probe->fetch(
                ['asset' => array_merge($release['asset'], $invalid)], $base . '/mismatch-' . $index . '.zip'
            ));
        }
    });
});

test('release asset redirects cannot leave the approved host or chain further redirects', function () {
    softwareRig(function ($software, $model, $base) {
        $release = ['asset' => ['id' => 42, 'size' => 1, 'sha256' => hash('sha256', 'x')]];
        foreach ([
            'http://release-assets.githubusercontent.com/file',
            'https://release-assets.githubusercontent.com.evil.example/file',
            'https://user:password@release-assets.githubusercontent.com/file',
            'https://release-assets.githubusercontent.com:444/file',
            'https://release-assets.githubusercontent.com/file#fragment',
            'https://127.0.0.1/file'
        ] as $index => $location) {
            $probe = new AssetDownloadProbe();
            $probe->responses[] = ['status' => 302, 'location' => $location];
            issue('software_download', fn() => $probe->fetch($release, $base . '/redirect-' . $index . '.zip'));
            same(1, count($probe->urls));
        }
        $probe = new AssetDownloadProbe();
        $probe->responses = [
            ['status' => 302, 'location' => 'https://release-assets.githubusercontent.com/first'],
            ['status' => 302, 'location' => 'https://release-assets.githubusercontent.com/second']
        ];
        issue('software_download', fn() => $probe->fetch($release, $base . '/redirect-chain.zip'));
        same(2, count($probe->urls));
    });
});

test('purge failure remains visible and can be retried manually without restoring data', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $software->purgeFails = true;
        issue('software_purge', fn() => $software->commitUpdate(1, 1, $trial['id']));
        same('purging', $software->banner()['phase']);
        $software->purgeFails = false;
        $software->commitUpdate(1, 1, $trial['id']);
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
        issue('software_preserve', fn() => $software->rollbackUpdate(1, 1, $pending['id'], 'preserve'));
        $manager->fail = false;
        $software->rollbackUpdate(1, 1, $pending['id'], 'restore');
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
        $software->commitUpdate(1, 1, $pending['id']);
    });
});

test('backup corruption is detected before restoring any live data', function () {
    softwareRig(function ($software, $model, $base) {
        $trial = installTrial($software);
        $model->Record->query('UPDATE council_sync_settings SET max_drop = 31');
        file_put_contents($base . '/private/' . $trial['id'] . '/core_emails.gz', 'damaged');
        issue('software_snapshot', fn() => $software->rollbackUpdate(1, 1, $trial['id'], 'restore'));
        same(31, $model->settings(1)['max_drop']);
        same('2.0.0', $model->Record->query('SELECT version FROM plugins WHERE id = 1')->fetchColumn());
        $software->commitUpdate(1, 1, $trial['id']);
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
        $model->installRelease['asset'] = archiveAsset($base . '/release.zip');
        $manager->migration = static fn($pdo) => $pdo->exec('CREATE TABLE council_sync_added (id INTEGER PRIMARY KEY, value TEXT)');
        $trial = installTrial($software);
        same(false, $trial['can_preserve']);
        $software->rollbackUpdate(1, 1, $trial['id'], 'restore');
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
        $zip->addFromString('council_sync/models/linked.php', '../outside');
        $zip->setExternalAttributesName('council_sync/models/linked.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        issue('software_archive', fn() => CouncilSyncSoftware::extract($base . '/release.zip', $base . '/linked'));
    });
});

test('a release missing recovery dependencies is rejected before replacing installed files', function () {
    softwareRig(function ($software, $model, $base) {
        $zip = new ZipArchive();
        same(true, $zip->open($base . '/release.zip'));
        same(true, $zip->deleteName('council_sync/models/software_snapshot.php'));
        same(true, $zip->close());
        $model->installRelease['asset'] = archiveAsset($base . '/release.zip');
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

class DevelopmentMetadataProbe extends TestCouncilSync
{
    public array $responses = [];
    public array $urls = [];
    protected function httpGet(string $url, string $agent, int $limit, array $headers = ['Accept: application/json']): array
    {
        $this->urls[] = $url;
        if (!$this->responses) {
            throw new RuntimeException('Unexpected development network request.');
        }
        return array_shift($this->responses);
    }
}

function developmentMetadata(DevelopmentMetadataProbe $model, ?array $manifest = null): string
{
    $body = json_encode($manifest ?? [
        'version' => '1.2.3', 'software_update' => ['protocol' => 1, 'development_protocol' => 1]
    ]);
    $model->responses = [
        ['status' => 200, 'body' => json_encode(['ref' => 'refs/heads/dev', 'object' => ['type' => 'commit', 'sha' => str_repeat('c', 40)]])],
        ['status' => 200, 'body' => $body]
    ];
    return $body;
}

test('manual dev checks pin the manifest URL and never present dev cache as a stable release', function () {
    $base = database([]);
    $model = new DevelopmentMetadataProbe($base->Record, $base->Clients);
    plugin($model);
    $model->release('v2.0.0');
    $model->checkForUpdates(1, true);
    $model->development = true;
    same('unchecked', $model->versionStatus(1)['status']);
    $body = developmentMetadata($model);
    $candidate = $model->developmentRelease();
    same(hash('sha256', $body), $candidate['manifest_sha256']);
    same('https://raw.githubusercontent.com/' . CouncilSync::REPOSITORY . '/' . str_repeat('c', 40) . '/plugins/council_sync/config.json', $model->urls[1]);
    developmentMetadata($model);
    $status = $model->checkForUpdates(1, true);
    same(true, $status['development']);
    same(str_repeat('c', 40), $status['commit']);
    $requests = count($model->urls);
    $plugin = new CouncilSyncPlugin();
    $plugin->cron('updates');
    same($requests, count($model->urls));
    same([], Loader::$models['Emails']->sent);
    same([], Loader::$models['SupportManagerTickets']->added);
    $model->development = false;
    same('unchecked', $model->versionStatus(1)['status']);
    same(null, $model->versionStatus(1)['latest']);
});

test('dev lookup refuses other refs, invalid objects and targets unable to restore beta backups', function () {
    $base = database([]);
    $model = new DevelopmentMetadataProbe($base->Record, $base->Clients);
    issue('software_development', fn() => $model->developmentRelease());
    same([], $model->urls);
    $model->development = true;
    foreach ([
        ['version' => '1.2.3', 'software_update' => ['protocol' => 1]],
        ['version' => '1.2.3-beta.1', 'software_update' => ['protocol' => 1, 'development_protocol' => 1]],
        ['version' => '1.2.3', 'software_update' => ['protocol' => 1, 'development_protocol' => 1, 'development_branch' => 'dev']]
    ] as $manifest) {
        developmentMetadata($model, $manifest);
        issue('development_response', fn() => $model->developmentRelease());
    }
    foreach ([
        ['ref' => 'refs/heads/main', 'object' => ['type' => 'commit', 'sha' => str_repeat('c', 40)]],
        ['ref' => 'refs/heads/dev', 'object' => ['type' => 'tag', 'sha' => str_repeat('c', 40)]],
        ['ref' => 'refs/heads/dev', 'object' => ['type' => 'commit', 'sha' => '../../other']]
    ] as $reference) {
        $model->responses = [['status' => 200, 'body' => json_encode($reference)]];
        issue('development_response', fn() => $model->developmentRelease());
    }
});

test('failed checks without any successful URL remain isolated by source', function () {
    $base = database([]);
    $model = new DevelopmentMetadataProbe($base->Record, $base->Clients);
    plugin($model);
    same('unavailable', $model->checkForUpdates(1, true)['status']);
    $model->development = true;
    same('unchecked', $model->versionStatus(1)['status']);
    $model->responses = [['status' => 403, 'body' => '{}']];
    $status = $model->checkForUpdates(1, true);
    same('unavailable', $status['status']);
    same('dev:http_403', $status['error_code']);
    $model->development = false;
    same('unchecked', $model->versionStatus(1)['status']);
    same(null, $model->versionStatus(1)['error_code']);
});

test('dev install UI requires a separate confirmation and the displayed commit', function () {
    developmentRig(function ($software, $model, $base, $manager, $commit) {
        Loader::$models['CouncilSyncSoftware'] = $software;
        $controller = new AdminSoftware();
        $controller->preAction();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller->post = [
            'confirm_install' => '1', 'version' => '2.0.0', 'commit' => $commit,
            '_csrf_token' => $controller->Form->token('/billing/admin/plugin/council_sync/admin_software/install/')
        ];
        runSoftwareAction($controller, 'install');
        same(null, $software->banner());
        $renderer = new TestRenderer();
        $status = $model->versionStatus(1);
        $status['status'] = 'outdated';
        $status['latest'] = '2.0.0';
        $status['upgrade_required'] = false;
        $status['commit'] = $commit;
        $html = $renderer->render('admin_software', [
            'software_trial' => null, 'software_allowed' => true, 'software_uri' => '/billing/admin/plugin/council_sync/admin_software/',
            'sync_uri' => '/billing/admin/plugin/council_sync/admin_main/', 'configuration' => $software->configuration(),
            'version_status' => $status, 'install_supported' => true
        ]);
        same(true, str_contains($html, 'confirm_development'));
        same(true, str_contains($html, $commit));
        same(true, str_contains($html, 'DEVELOPMENT TEST BUILD'));
    });
});

function releaseAssetFixture(): array
{
    return [
        'id' => 1, 'name' => 'council-sync-2.0.0.zip', 'size' => 100, 'state' => 'uploaded',
        'digest' => 'sha256:' . str_repeat('c', 64),
        'url' => 'https://api.github.com/repos/' . CouncilSync::REPOSITORY . '/releases/assets/1',
        'browser_download_url' => 'https://github.com/' . CouncilSync::REPOSITORY . '/releases/download/v2.0.0/council-sync-2.0.0.zip'
    ];
}

test('software archives resolve the release tag to an immutable commit, not empty main', function () {
    $base = database([]);
    $model = new SoftwareTagProbe($base->Record, $base->Clients);
    $model->release('v2.0.0', [releaseAssetFixture()]);
    $model->objects = [['type' => 'tag', 'sha' => str_repeat('a', 40)], ['type' => 'commit', 'sha' => str_repeat('b', 40)]];
    same(str_repeat('b', 40), $model->softwareRelease()['commit']);
    same('https://api.github.com/repos/' . CouncilSync::REPOSITORY . '/git/ref/tags/v2.0.0', $model->paths[0]);
    same('https://api.github.com/repos/' . CouncilSync::REPOSITORY . '/git/tags/' . str_repeat('a', 40), $model->paths[1]);
});

test('one-click installation requires the uniquely named uploaded asset with canonical URLs and digest', function () {
    $base = database([]);
    $model = new SoftwareTagProbe($base->Record, $base->Clients);
    foreach ([
        ['id' => true], ['id' => 0], ['size' => '100'], ['size' => 0], ['size' => 16777217],
        ['state' => 'open'], ['digest' => null], ['digest' => 'sha256:' . str_repeat('z', 64)],
        ['name' => 'other.zip'], ['url' => 'https://example.com/asset'],
        ['browser_download_url' => 'https://github.com/' . CouncilSync::REPOSITORY . '/releases/download/v2.0.1/council-sync-2.0.0.zip']
    ] as $invalid) {
        $model->release('v2.0.0', [array_merge(releaseAssetFixture(), $invalid)]);
        issue('software_download', fn() => $model->softwareRelease());
    }
    foreach ([[], [releaseAssetFixture(), releaseAssetFixture()]] as $assets) {
        $model->release('v2.0.0', $assets);
        issue('software_download', fn() => $model->softwareRelease());
    }
    same([], $model->paths);
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
        same(true, str_contains($html, '1.2.3 / 1.2.3 / 2.0.0'));
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
        $software->commitUpdate(1, 1, $trial['id']);
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
        $software->rollbackUpdate(1, 1, $trial['id'], 'preserve');
        $software->finish(1, 1, $trial['id']);
        same('synthetic private content', file_get_contents($directory . '/private.txt'));
        same(true, is_dir($directory . '/empty'));
        if (DIRECTORY_SEPARATOR !== '\\') {
            same(0600, fileperms($directory . '/private.txt') & 0777);
            same(0700, fileperms($directory . '/empty') & 0777);
        }
    });
});
