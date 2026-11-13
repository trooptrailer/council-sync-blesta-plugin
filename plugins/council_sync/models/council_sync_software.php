<?php

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'council_sync_model.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'software_snapshot.php';

class CouncilSyncSoftware extends CouncilSyncModel
{
    private const MARKER = "Council Sync private update storage v1\n";
    private static bool $nativeUpgrade = false;

    public function __construct()
    {
        parent::__construct();
        Loader::loadComponents($this, ['Record']);
        Loader::loadModels($this, ['CouncilSync.CouncilSync', 'PluginManager', 'StaffGroups']);
    }

    public static function installControl(object $record): void
    {
        $record->query("CREATE TABLE IF NOT EXISTS council_sync_software (
            id INT NOT NULL PRIMARY KEY, backup_dir TEXT NULL, window_hours BIGINT NOT NULL DEFAULT 48,
            active_id CHAR(32) NULL, phase VARCHAR(32) NOT NULL DEFAULT 'idle', last_result TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$record->connect()->query('SELECT id FROM council_sync_software WHERE id = 1')->fetch()) {
            $record->insert('council_sync_software', [
                'id' => 1, 'backup_dir' => null, 'window_hours' => 48, 'active_id' => null,
                'phase' => 'idle', 'last_result' => null
            ]);
        }
    }

    public static function guard(object $record, callable $operation, bool $ordinaryWork = true): mixed
    {
        $name = self::lockBase($record) . ':software';
        $lock = $record->query('SELECT GET_LOCK(?, 0) AS acquired', $name)->fetch(PDO::FETCH_OBJ);
        if (!$lock || (int) $lock->acquired !== 1) {
            throw new CouncilSyncIssue('software', 'busy');
        }
        try {
            $control = $record->connect()->query('SELECT * FROM council_sync_software WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            if (!$control) {
                throw new CouncilSyncIssue('software', 'schema_upgrade');
            }
            if ($ordinaryWork && !in_array($control['phase'], ['idle', 'trial', 'purging'], true)) {
                throw new CouncilSyncIssue('software', 'software_maintenance');
            }
            return $operation();
        } finally {
            $released = $record->query('SELECT RELEASE_LOCK(?) AS released', $name)->fetch(PDO::FETCH_OBJ);
            if (!$released || (int) $released->released !== 1) {
                error_log('COUNCIL_SYNC_SOFTWARE_LOCK_RELEASE_FAILED');
            }
        }
    }

    public static function lockBase(object $record): string
    {
        $row = $record->query('SELECT DATABASE() AS database_name')->fetch(PDO::FETCH_OBJ);
        if (!$row || !is_string($row->database_name) || $row->database_name === '') {
            throw new CouncilSyncIssue('software', 'software_schema');
        }
        return 'council_sync:' . substr(hash('sha256', $row->database_name), 0, 24);
    }

    public static function assertIdle(object $record): void
    {
        $active = $record->connect()->query('SELECT active_id FROM council_sync_software WHERE id = 1')->fetchColumn();
        if ($active !== null && $active !== false) {
            throw new CouncilSyncIssue('software', 'software_pending');
        }
    }

    public static function allowNativeUpgrade(object $record, string $from, string $to): void
    {
        $phase = $record->connect()->query('SELECT phase FROM council_sync_software WHERE id = 1')->fetchColumn();
        if ($phase !== 'idle' && !self::$nativeUpgrade) {
            throw new CouncilSyncIssue('software', 'software_pending');
        }
        if (version_compare($from, $to, '>') && !(self::$nativeUpgrade && $phase === 'rollback_ready')) {
            throw new CouncilSyncIssue('software', 'downgrade');
        }
    }

    public function configuration(): array
    {
        $row = $this->Record->connect()->query('SELECT * FROM council_sync_software WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new CouncilSyncIssue('software', 'schema_upgrade');
        }
        $row['window_hours'] = (int) $row['window_hours'];
        return $row;
    }

    public function authorized(int $staff, int $company): bool
    {
        Loader::loadComponents($this, ['Acl']);
        $group = $this->StaffGroups->getStaffGroupByStaff($staff, $company);
        return $group && (
            $this->Acl->check('staff_group_' . $group->id, 'council_sync.admin_software', '*')
            || $this->Acl->check('staff_group_' . $group->id, 'council_sync_software', '*')
        );
    }

    private function requireAuthorized(int $staff, int $company): void
    {
        if (!$this->authorized($staff, $company)) {
            throw new CouncilSyncIssue('software', 'software_permission');
        }
    }

    protected function now(): int
    {
        return time();
    }

    protected function pluginDirectory(): string
    {
        return dirname(__DIR__);
    }

    protected function checkPlatform(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new CouncilSyncIssue('software', 'software_platform');
        }
    }

    protected function publicDirectories(): array
    {
        if (!defined('ROOTWEBDIR') || (PHP_SAPI !== 'cli' && empty($_SERVER['DOCUMENT_ROOT']))) {
            throw new CouncilSyncIssue('software', 'software_private');
        }
        return empty($_SERVER['DOCUMENT_ROOT']) ? [ROOTWEBDIR] : [ROOTWEBDIR, $_SERVER['DOCUMENT_ROOT']];
    }

    protected function snapshotStore(): CouncilSyncSoftwareSnapshot
    {
        return new CouncilSyncSoftwareSnapshot($this->Record->connect());
    }

    private function control(array $values): void
    {
        $this->Record->where('id', '=', 1)->update('council_sync_software', $values);
    }

    private static function contains(string $parent, string $path): bool
    {
        $parent = rtrim(str_replace('\\', '/', $parent), '/') . '/';
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($path), strtolower($parent)) : str_starts_with($path, $parent);
    }

    private function privateRoot(string $path, bool $initialize = false): string
    {
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\')
            || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':' && in_array($path[2], ['/', '\\'], true));
        if ($path === '' || str_contains($path, "\0") || !$absolute) {
            throw new CouncilSyncIssue('software', 'software_private');
        }
        $root = realpath($path);
        if (!$root || !is_dir($root) || is_link($path) || !is_writable($root)) {
            throw new CouncilSyncIssue('software', 'software_private');
        }
        foreach (array_merge($this->publicDirectories(), [$this->pluginDirectory()]) as $public) {
            $resolved = realpath($public);
            if (!$resolved || self::contains($resolved, $root) || self::contains($root, $resolved)) {
                throw new CouncilSyncIssue('software', 'software_private');
            }
        }
        $marker = $root . DIRECTORY_SEPARATOR . '.council-sync-updates';
        if ($initialize && !file_exists($marker)) {
            if (iterator_count(new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS)) !== 0 || !@chmod($root, 0700)) {
                throw new CouncilSyncIssue('software', 'software_private');
            }
            self::writeFile($marker, self::MARKER);
        }
        if (is_link($marker) || !is_file($marker) || filesize($marker) !== strlen(self::MARKER) || @file_get_contents($marker) !== self::MARKER) {
            throw new CouncilSyncIssue('software', 'software_private');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && (fileperms($root) & 0077) !== 0) {
            throw new CouncilSyncIssue('software', 'software_private');
        }
        return $root;
    }

    public function saveConfiguration(int $staff, int $company, array $input): void
    {
        $this->requireAuthorized($staff, $company);
        $hours = filter_var($input['window_hours'] ?? null, FILTER_VALIDATE_INT);
        if (
            $hours === false || $hours === null || $hours < 1 || $hours > 4294967295
            || ($input['confirm_private'] ?? null) !== '1' || !is_string($input['backup_dir'] ?? null)
        ) {
            throw new CouncilSyncIssue('software', 'software_configuration');
        }
        self::guard($this->Record, function () use ($input, $hours) {
            self::assertIdle($this->Record);
            $root = $this->privateRoot(trim($input['backup_dir']), true);
            $this->control(['backup_dir' => $root, 'window_hours' => $hours]);
        }, false);
    }

    private static function makeDirectory(string $path): void
    {
        if (!@mkdir($path, 0700) || !@chmod($path, 0700)) {
            throw new CouncilSyncIssue('software', 'software_files');
        }
    }

    private static function writeFile(string $path, string $bytes): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if (!$handle) {
            throw new CouncilSyncIssue('software', 'software_files');
        }
        try {
            if (!@chmod($temporary, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
        } finally {
            fclose($handle);
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new CouncilSyncIssue('software', 'software_files');
        }
    }

    private function batchDirectory(string $id): string
    {
        if (!preg_match('~^[a-f0-9]{32}$~D', $id)) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
        $root = $this->configuration()['backup_dir'];
        if (!is_string($root)) {
            throw new CouncilSyncIssue('software', 'software_private');
        }
        return $this->privateRoot($root) . DIRECTORY_SEPARATOR . $id;
    }

    private function saveState(array $state): void
    {
        $directory = $this->batchDirectory($state['id']);
        if (is_link($directory) || !is_dir($directory)) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
        $encrypted = $this->systemEncrypt(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (!is_string($encrypted)) {
            throw new CouncilSyncIssue('software', 'audit_crypto');
        }
        self::writeFile($directory . DIRECTORY_SEPARATOR . 'state.enc', $encrypted);
        $this->control(['active_id' => $state['id'], 'phase' => $state['phase']]);
    }

    private function state(): ?array
    {
        $config = $this->configuration();
        if ($config['active_id'] === null) {
            return null;
        }
        $directory = $this->batchDirectory($config['active_id']);
        $path = $directory . DIRECTORY_SEPARATOR . 'state.enc';
        if ($config['phase'] === 'purging') {
            $state = json_decode($config['last_result'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state) || ($state['id'] ?? null) !== $config['active_id'] || ($state['phase'] ?? null) !== 'purging') {
                throw new CouncilSyncIssue('software', 'software_state');
            }
            return $state;
        }
        if (is_link($directory) || is_link($path) || !is_file($path) || filesize($path) > 8388608) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
        $encrypted = @file_get_contents($path);
        if (!is_string($encrypted)) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
        $plain = $this->systemDecrypt($encrypted);
        if (!is_string($plain)) {
            throw new CouncilSyncIssue('software', 'audit_crypto');
        }
        try {
            $state = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
        if (
            !is_array($state) || ($state['id'] ?? null) !== $config['active_id']
            || ($state['protocol'] ?? null) !== 1 || !is_string($state['phase'] ?? null)
        ) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
        // A crash between the private journal and its DB phase must not unblock work.
        if ($state['phase'] !== $config['phase']) {
            $this->control(['phase' => $state['phase']]);
        }
        return $state;
    }

    public function banner(): ?array
    {
        $state = $this->state();
        if (!$state) {
            return null;
        }
        $remaining = $state['phase'] === 'trial' ? max(0, $state['expires_at'] - $this->now()) : null;
        return [
            'id' => $state['id'], 'phase' => $state['phase'], 'from' => $state['from'],
            'to' => $state['to'], 'remaining' => $remaining, 'expires_at' => $state['expires_at'] ?? null,
            'can_preserve' => $state['phase'] === 'trial' && $remaining > 0
                && $this->snapshotStore()->sameSchema($state['snapshot']['schema']),
            'can_finish' => in_array($state['phase'], ['files_ready', 'rollback_ready'], true)
                || ($state['phase'] === 'failed' && $state['files_swapped']),
            'error' => $state['error'] ?? null
        ];
    }

    public static function package(string $directory): array
    {
        $file = $directory . DIRECTORY_SEPARATOR . 'config.json';
        if (is_link($file) || !is_file($file) || filesize($file) > 65536) {
            throw new CouncilSyncIssue('software', 'software_protocol');
        }
        $bytes = @file_get_contents($file);
        if (!is_string($bytes)) {
            throw new CouncilSyncIssue('software', 'software_protocol');
        }
        try {
            $config = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new CouncilSyncIssue('software', 'software_protocol');
        }
        if (
            !is_array($config) || !is_string($config['version'] ?? null)
            || !preg_match('~^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$~D', $config['version'])
            || ($config['software_update']['protocol'] ?? null) !== 1
        ) {
            throw new CouncilSyncIssue('software', 'software_protocol');
        }
        $config['software_update']['data_tables'] = CouncilSyncSoftwareSnapshot::dataTables(
            $config['software_update']['data_tables'] ?? null
        );
        foreach ([
            'council_sync_plugin.php', 'council_sync_controller.php', 'council_sync_model.php',
            'controllers/admin_main.php', 'controllers/admin_software.php',
            'models/council_sync.php', 'models/council_sync_software.php', 'models/software_snapshot.php',
            'views/default/admin_main.pdt', 'views/default/admin_resolve.pdt', 'views/default/admin_history.pdt',
            'views/default/admin_software.pdt', 'views/default/admin_software_finish.pdt', 'views/default/software_banner.pdt',
            'language/en_us/admin_main.php', 'language/en_us/admin_software.php',
            'language/en_us/council_sync.php', 'language/en_us/council_sync_plugin.php'
        ] as $required) {
            if (!is_file($directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required))) {
                throw new CouncilSyncIssue('software', 'software_protocol');
            }
        }
        return $config;
    }

    public static function safeRelative(string $path): string
    {
        if (
            $path === '' || strlen($path) > 240 || str_contains($path, '\\') || str_contains($path, "\0")
            || preg_match('~[:\x00-\x1f]~', $path)
        ) {
            throw new CouncilSyncIssue('software', 'software_archive');
        }
        foreach (explode('/', $path) as $component) {
            if (
                $component === '' || str_starts_with($component, '.') || rtrim($component, '. ') !== $component
                || preg_match('~^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)~i', $component)
            ) {
                throw new CouncilSyncIssue('software', 'software_archive');
            }
        }
        return $path;
    }

    private static function inventory(string $directory): array
    {
        $files = [];
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
            self::safeRelative($relative);
            $bytes += $file->getSize();
            if ($bytes > 67108864 || count($files) >= 2000) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
            $files[$relative] = $hash;
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function modes(string $directory): array
    {
        $modes = ['' => ['directory' => true, 'mode' => (fileperms($directory) & 0777) | 0700]];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($entries as $entry) {
            if ($entry->isLink()) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
            $relative = self::safeRelative(str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($directory) + 1)));
            $modes[$relative] = [
                'directory' => $entry->isDir(),
                'mode' => ($entry->getPerms() & 0777) | ($entry->isDir() ? 0700 : 0400)
            ];
        }
        ksort($modes, SORT_STRING);
        return $modes;
    }

    private static function copyTree(string $from, string $to, array $inventory, array $modes = []): void
    {
        self::makeDirectory($to);
        foreach ($inventory as $relative => $hash) {
            $source = $from . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $target = $to . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
            if (!@copy($source, $target) || !@chmod($target, 0600) || hash_file('sha256', $target) !== $hash) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
        }
        foreach ($modes as $relative => $entry) {
            if ($relative !== '' && $entry['directory']) {
                self::safeRelative($relative);
                $path = $to . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_dir($path) && !@mkdir($path, 0700, true)) {
                    throw new CouncilSyncIssue('software', 'software_files');
                }
            }
        }
    }

    protected function invalidate(array $files): void
    {
        if (!filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN) || PHP_SAPI === 'cli') {
            return;
        }
        if (!function_exists('opcache_get_status') || !function_exists('opcache_invalidate') || @opcache_get_status(false) === false) {
            throw new CouncilSyncIssue('software', 'software_opcache');
        }
        foreach (array_keys($files) as $relative) {
            if (in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['php', 'pdt'], true)) {
                $file = $this->pluginDirectory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!@opcache_invalidate($file, true)) {
                    throw new CouncilSyncIssue('software', 'software_opcache');
                }
            }
        }
    }

    protected function download(string $commit, string $path): void
    {
        if (!preg_match('~^[a-f0-9]{40}$~D', $commit) || !extension_loaded('curl')) {
            throw new CouncilSyncIssue('software', 'software_download');
        }
        $stream = @fopen($path, 'xb');
        if (!$stream) {
            throw new CouncilSyncIssue('software', 'software_files');
        }
        if (!@chmod($path, 0600)) {
            fclose($stream);
            throw new CouncilSyncIssue('software', 'software_files');
        }
        $handle = curl_init('https://codeload.github.com/' . CouncilSync::REPOSITORY . '/zip/' . $commit);
        $bytes = 0;
        curl_setopt_array($handle, [
            CURLOPT_USERAGENT => 'CouncilSync-Blesta/' . CouncilSync::packageVersion(),
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use ($stream, &$bytes) {
                $bytes += strlen($chunk);
                return $bytes <= 16777216 ? (int) @fwrite($stream, $chunk) : 0;
            }
        ]);
        try {
            if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200 || !fflush($stream)) {
                throw new CouncilSyncIssue('software', 'software_download');
            }
        } finally {
            curl_close($handle);
            fclose($stream);
        }
    }

    public static function extract(string $archive, string $directory): array
    {
        if (!class_exists('ZipArchive')) {
            throw new CouncilSyncIssue('software', 'software_zip');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new CouncilSyncIssue('software', 'software_archive');
        }
        self::makeDirectory($directory);
        $seen = [];
        $bytes = 0;
        $wrapper = null;
        try {
            if ($zip->numFiles > 10000) {
                throw new CouncilSyncIssue('software', 'software_archive');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (!$entry || !is_string($entry['name'])) {
                    throw new CouncilSyncIssue('software', 'software_archive');
                }
                $parts = explode('/', $entry['name']);
                $top = array_shift($parts);
                if (!$top || ($wrapper !== null && $wrapper !== $top)) {
                    throw new CouncilSyncIssue('software', 'software_archive');
                }
                self::safeRelative($top);
                $wrapper = $top;
                if (($parts[0] ?? null) !== 'plugins' || ($parts[1] ?? null) !== 'council_sync') {
                    continue;
                }
                $relative = implode('/', array_slice($parts, 2));
                if ($relative === '') {
                    continue;
                }
                $directoryEntry = str_ends_with($relative, '/');
                $relative = self::safeRelative(rtrim($relative, '/'));
                if (!$zip->getExternalAttributesIndex($i, $os, $attributes)) {
                    throw new CouncilSyncIssue('software', 'software_archive');
                }
                $mode = $os === ZipArchive::OPSYS_UNIX ? ($attributes >> 16) & 0170000 : 0;
                if ($mode !== 0 && $mode !== ($directoryEntry ? 0040000 : 0100000)) {
                    throw new CouncilSyncIssue('software', 'software_archive');
                }
                $key = strtolower($relative);
                if (isset($seen[$key]) || $entry['size'] > 8388608 || count($seen) >= 2000) {
                    throw new CouncilSyncIssue('software', 'software_archive');
                }
                $seen[$key] = true;
                $path = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if ($directoryEntry) {
                    if (!is_dir($path) && !@mkdir($path, 0700, true)) {
                        throw new CouncilSyncIssue('software', 'software_files');
                    }
                    continue;
                }
                $bytes += $entry['size'];
                if ($bytes > 67108864) {
                    throw new CouncilSyncIssue('software', 'software_archive');
                }
                $stream = $zip->getStreamIndex($i);
                if (!$stream) {
                    throw new CouncilSyncIssue('software', 'software_archive');
                }
                $content = '';
                try {
                    while (!feof($stream)) {
                        $chunk = @fread($stream, 65536);
                        if ($chunk === false || strlen($content) + strlen($chunk) > $entry['size']) {
                            throw new CouncilSyncIssue('software', 'software_archive');
                        }
                        if ($chunk === '' && !feof($stream)) {
                            throw new CouncilSyncIssue('software', 'software_archive');
                        }
                        $content .= $chunk;
                    }
                } finally {
                    fclose($stream);
                }
                if (strlen($content) !== $entry['size']) {
                    throw new CouncilSyncIssue('software', 'software_archive');
                }
                if (in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['php', 'pdt'], true)) {
                    try {
                        token_get_all($content, TOKEN_PARSE);
                    } catch (ParseError $error) {
                        throw new CouncilSyncIssue('software', 'software_archive');
                    }
                }
                if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true)) {
                    throw new CouncilSyncIssue('software', 'software_files');
                }
                self::writeFile($path, $content);
            }
        } finally {
            $zip->close();
        }
        return self::package($directory);
    }

    private function instances(): array
    {
        $rows = $this->Record->connect()->prepare('SELECT id, version FROM plugins WHERE dir = ? ORDER BY id');
        $rows->execute(['council_sync']);
        $versions = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $versions[(int) $row['id']] = $row['version'];
        }
        if (!$versions) {
            throw new CouncilSyncIssue('software', 'not_installed');
        }
        return $versions;
    }

    private function requireId(array $state, string $id, bool $sameInstances = true): void
    {
        if ($state['id'] !== $id) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
        if ($state['phase'] === 'purging') {
            return;
        }
        $current = array_keys($this->instances());
        $before = array_keys($state['instances']);
        if ($sameInstances ? $current !== $before : array_diff($before, $current)) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
    }

    private function fail(array $state, Throwable $error): never
    {
        $state['phase'] = 'failed';
        $state['error'] = $error instanceof CouncilSyncIssue ? $error->reason : 'software_failed';
        $this->saveState($state);
        error_log('COUNCIL_SYNC_SOFTWARE_FAILED id=' . $state['id'] . ' reason=' . $state['error']);
        if ($error instanceof CouncilSyncIssue) {
            throw $error;
        }
        throw new CouncilSyncIssue('software', 'software_failed');
    }

    public function install(int $staff, int $company, string $expectedVersion): void
    {
        $this->requireAuthorized($staff, $company);
        $this->checkPlatform();
        self::guard($this->Record, function () use ($staff, $expectedVersion) {
            self::assertIdle($this->Record);
            $config = $this->configuration();
            $root = $this->privateRoot((string) $config['backup_dir']);
            if (!is_writable(dirname($this->pluginDirectory())) || is_link($this->pluginDirectory())) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
            if (stat($root)['dev'] !== stat($this->pluginDirectory())['dev'] || !class_exists('ZipArchive')) {
                throw new CouncilSyncIssue('software', 'software_zip');
            }
            $before = self::package($this->pluginDirectory());
            $versions = $this->instances();
            if (array_filter($versions, static fn($version) => $version !== $before['version'])) {
                throw new CouncilSyncIssue('software', 'software_version');
            }
            $release = $this->CouncilSync->softwareRelease();
            if ($release['version'] !== $expectedVersion || !version_compare($release['version'], $before['version'], '>')) {
                throw new CouncilSyncIssue('software', 'software_version');
            }
            $files = self::inventory($this->pluginDirectory());
            $this->invalidate($files);
            $id = bin2hex(random_bytes(16));
            $directory = $root . DIRECTORY_SEPARATOR . $id;
            self::makeDirectory($directory);
            $state = [
                'protocol' => 1, 'id' => $id, 'phase' => 'preparing', 'from' => $before['version'],
                'to' => $release['version'], 'instances' => $versions, 'staff_id' => $staff,
                'created_at' => $this->now(), 'hours' => $config['window_hours'],
                'before_files' => $files, 'before_modes' => self::modes($this->pluginDirectory()),
                'files_swapped' => false, 'temp_tables' => []
            ];
            try {
                $this->saveState($state);
                self::copyTree($this->pluginDirectory(), $directory . DIRECTORY_SEPARATOR . 'before', $files, $state['before_modes']);
                $state['snapshot'] = $this->snapshotStore()->capture($directory, $before['software_update']['data_tables']);
                $this->snapshotStore()->verify($state['snapshot'], $directory);
                $this->saveState($state);
                $this->download($release['commit'], $directory . DIRECTORY_SEPARATOR . 'release.zip');
                $package = self::extract($directory . DIRECTORY_SEPARATOR . 'release.zip', $directory . DIRECTORY_SEPARATOR . 'staged');
                if ($package['version'] !== $state['to']) {
                    throw new CouncilSyncIssue('software', 'software_version');
                }
                $state['target_tables'] = $package['software_update']['data_tables'];
                $state['after_files'] = self::inventory($directory . DIRECTORY_SEPARATOR . 'staged');
                $this->saveState($state);
                if (self::inventory($this->pluginDirectory()) !== $files) {
                    throw new CouncilSyncIssue('software', 'software_files_changed');
                }
                $this->replaceFiles($directory . DIRECTORY_SEPARATOR . 'staged', $directory . DIRECTORY_SEPARATOR . 'displaced');
                $state['files_swapped'] = true;
                $state['phase'] = 'files_ready';
                $this->saveState($state);
                $this->invalidate($state['after_files']);
            } catch (Throwable $error) {
                $this->fail($state, $error);
            }
        }, false);
    }

    private function replaceFiles(string $staged, string $displaced, ?array $modes = null): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($staged, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        if (!@chmod($staged, $modes['']['mode'] ?? 0755)) {
            throw new CouncilSyncIssue('software', 'software_files');
        }
        foreach ($entries as $entry) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($staged) + 1));
            $mode = $modes === null ? ($entry->isDir() ? 0755 : 0644) : ($modes[$relative]['mode'] ?? null);
            if (!is_int($mode) || $mode < 0 || $mode > 0777 || $entry->isLink() || !@chmod($entry->getPathname(), $mode)) {
                throw new CouncilSyncIssue('software', 'software_files');
            }
        }
        if (!@rename($this->pluginDirectory(), $displaced)) {
            throw new CouncilSyncIssue('software', 'software_files');
        }
        if (!@rename($staged, $this->pluginDirectory())) {
            if (!@rename($displaced, $this->pluginDirectory())) {
                throw new CouncilSyncIssue('software', 'software_recovery');
            }
            throw new CouncilSyncIssue('software', 'software_files');
        }
    }

    private function nativeUpgrade(array $state): void
    {
        $this->PluginManager->Record->setConnection($this->Record->connect());
        self::$nativeUpgrade = true;
        try {
            $this->PluginManager->upgrade(array_key_first($state['instances']));
            if ($this->PluginManager->errors()) {
                throw new CouncilSyncIssue('software', 'software_upgrade');
            }
            $this->refreshNavigation();
        } finally {
            self::$nativeUpgrade = false;
        }
    }

    protected function refreshNavigation(): void
    {
        $companies = $this->Record->connect()->query(
            "SELECT DISTINCT company_id FROM plugins WHERE dir = 'council_sync'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $manager = new class extends \Blesta\App\Models\PluginManager {
            public function refresh(array $companies): void
            {
                foreach ($companies as $company) {
                    $this->clearNavCache($company);
                }
            }
        };
        $manager->refresh($companies);
    }

    private function verifyTrial(array $state): void
    {
        $this->requireId($state, $state['id'], false);
        if (
            self::package($this->pluginDirectory())['version'] !== $state['to']
            || self::inventory($this->pluginDirectory()) !== $state['after_files']
            || array_filter($this->instances(), static fn($version) => $version !== $state['to'])
        ) {
            throw new CouncilSyncIssue('software', 'software_files_changed');
        }
    }

    public function finish(int $staff, int $company, string $id): void
    {
        $this->requireAuthorized($staff, $company);
        self::guard($this->Record, function () use ($id, $staff) {
            $state = $this->state();
            if (
                !$state || (!in_array($state['phase'], ['files_ready', 'rollback_ready'], true)
                    && !($state['phase'] === 'failed' && $state['files_swapped']))
            ) {
                throw new CouncilSyncIssue('software', 'software_state');
            }
            $this->requireId($state, $id, false);
            $rollback = $state['phase'] === 'rollback_ready' || isset($state['rollback_mode']);
            $expected = $rollback ? $state['from'] : $state['to'];
            if (self::package($this->pluginDirectory())['version'] !== $expected) {
                throw new CouncilSyncIssue('software', 'software_fresh_request');
            }
            if (self::inventory($this->pluginDirectory()) !== ($rollback ? $state['before_files'] : $state['after_files'])) {
                throw new CouncilSyncIssue('software', 'software_files_changed');
            }
            try {
                $state['phase'] = $rollback ? 'rollback_ready' : 'files_ready';
                $this->saveState($state);
                $this->nativeUpgrade($state);
                if (array_filter($this->instances(), static fn($version) => $version !== $expected)) {
                    throw new CouncilSyncIssue('software', 'software_upgrade');
                }
                $tables = $rollback ? array_keys($state['snapshot']['schema']) : $state['target_tables'];
                if ($this->snapshotStore()->actualDataTables() !== $tables) {
                    throw new CouncilSyncIssue('software', 'software_schema');
                }
                if ($rollback && !$this->snapshotStore()->sameSchema($state['snapshot']['schema'])) {
                    throw new CouncilSyncIssue('software', 'software_schema');
                }
                if (!$rollback) {
                    $state['phase'] = 'trial';
                    $state['started_at'] = $this->now();
                    $state['expires_at'] = $state['started_at'] + $state['hours'] * 3600;
                    $state['after_schema'] = $this->snapshotStore()->schema($state['target_tables']);
                    $state['error'] = null;
                    $this->saveState($state);
                }
            } catch (Throwable $error) {
                $this->fail($state, $error);
            }
            if ($rollback) {
                $this->purge($state, 'rolled_back', $staff);
            }
        }, false);
    }

    public function rollback(int $staff, int $company, string $id, string $mode): void
    {
        $this->requireAuthorized($staff, $company);
        if (!in_array($mode, ['preserve', 'restore'], true)) {
            throw new CouncilSyncIssue('software', 'software_choice');
        }
        self::guard($this->Record, function () use ($id, $mode) {
            $state = $this->state();
            if (!$state || !in_array($state['phase'], ['trial', 'failed', 'preparing', 'files_ready'], true) || !isset($state['snapshot'])) {
                throw new CouncilSyncIssue('software', 'software_state');
            }
            $this->requireId($state, $id);
            if ($state['phase'] === 'trial' && $this->now() >= $state['expires_at']) {
                $this->verifyTrial($state);
                $this->purge($state, 'expired');
                throw new CouncilSyncIssue('software', 'software_expired');
            }
            $store = $this->snapshotStore();
            if ($mode === 'preserve' && ($state['phase'] !== 'trial' || !$store->sameSchema($state['snapshot']['schema']))) {
                throw new CouncilSyncIssue('software', 'software_preserve');
            }
            $directory = $this->batchDirectory($id);
            $currentFiles = self::inventory($this->pluginDirectory());
            if (
                $currentFiles !== $state['before_files']
                && (!isset($state['after_files']) || $currentFiles !== $state['after_files'])
            ) {
                throw new CouncilSyncIssue('software', 'software_files_changed');
            }
            if (self::inventory($directory . DIRECTORY_SEPARATOR . 'before') !== $state['before_files']) {
                throw new CouncilSyncIssue('software', 'software_snapshot');
            }
            try {
                $state['phase'] = 'restoring';
                $state['rollback_mode'] = $mode;
                $this->saveState($state);
                if ($state['temp_tables']) {
                    $store->dropTemps($state['temp_tables']);
                    $state['temp_tables'] = [];
                    $this->saveState($state);
                }
                if ($mode === 'restore') {
                    $store->verify($state['snapshot'], $directory);
                    $store->restoreData(
                        $state['snapshot'], $directory,
                        array_values(array_unique(array_merge(array_keys($state['snapshot']['schema']), $state['target_tables'] ?? []))),
                        function ($tables) use (&$state) {
                            $state['temp_tables'] = $tables;
                            $this->saveState($state);
                        }
                    );
                    $store->restoreRegistrations($state['snapshot'], $directory);
                }
                if ($currentFiles !== $state['before_files']) {
                    $this->invalidate($currentFiles);
                    self::copyTree($directory . DIRECTORY_SEPARATOR . 'before', $directory . DIRECTORY_SEPARATOR . 'rollback', $state['before_files'], $state['before_modes']);
                    $this->replaceFiles($directory . DIRECTORY_SEPARATOR . 'rollback', $directory . DIRECTORY_SEPARATOR . 'replaced', $state['before_modes']);
                    $this->invalidate($state['before_files']);
                }
                $state['phase'] = 'rollback_ready';
                $state['rollback_mode'] = $mode;
                $this->saveState($state);
            } catch (Throwable $error) {
                $this->fail($state, $error);
            }
        }, false);
    }

    public function commit(int $staff, int $company, string $id): void
    {
        $this->requireAuthorized($staff, $company);
        self::guard($this->Record, function () use ($id, $staff) {
            $state = $this->state();
            if (!$state) {
                throw new CouncilSyncIssue('software', 'software_state');
            }
            $this->requireId($state, $id, false);
            if ($state['phase'] === 'purging') {
                $this->purge($state, $state['result']);
                return;
            }
            if ($state['phase'] !== 'trial') {
                if ($state['phase'] === 'failed' && isset($state['after_schema'])) {
                    $this->verifyTrial($state);
                    if ($this->snapshotStore()->schema($state['target_tables']) !== $state['after_schema']) {
                        throw new CouncilSyncIssue('software', 'software_schema');
                    }
                    $this->purge($state, 'committed', $staff);
                    return;
                }
                if (
                    !in_array($state['phase'], ['failed', 'preparing'], true) || $state['files_swapped']
                    || self::inventory($this->pluginDirectory()) !== $state['before_files']
                    || (isset($state['snapshot']) && !$this->snapshotStore()->sameSchema($state['snapshot']['schema']))
                ) {
                    throw new CouncilSyncIssue('software', 'software_state');
                }
            } else {
                $this->verifyTrial($state);
            }
            $this->purge($state, 'committed', $staff);
        }, false);
    }

    public function cleanup(): void
    {
        self::guard($this->Record, function () {
            $state = $this->state();
            if ($state && $state['phase'] === 'purging') {
                $this->purge($state, $state['result']);
            } elseif ($state && $state['phase'] === 'trial' && $this->now() >= $state['expires_at']) {
                $this->verifyTrial($state);
                $this->purge($state, 'expired');
            }
        }, false);
    }

    private function purge(array $state, string $result, ?int $actor = null): void
    {
        $directory = $this->batchDirectory($state['id']);
        $summary = [
            'protocol' => 1, 'id' => $state['id'], 'phase' => 'purging',
            'from' => $state['from'], 'to' => $state['to'], 'result' => $result, 'at' => $this->now(),
            'actor_id' => $actor ?? ($state['actor_id'] ?? null),
            'mode' => $state['rollback_mode'] ?? ($state['mode'] ?? null),
            'installed_by' => $state['staff_id'] ?? ($state['installed_by'] ?? null)
        ];
        $this->control(['phase' => 'purging', 'last_result' => json_encode($summary, JSON_THROW_ON_ERROR)]);
        if (is_dir($directory) || is_link($directory)) {
            $this->removeTree($directory);
        }
        $this->control([
            'active_id' => null, 'phase' => 'idle',
            'last_result' => json_encode(array_replace($summary, ['phase' => 'finished']), JSON_THROW_ON_ERROR)
        ]);
        error_log('COUNCIL_SYNC_SOFTWARE_FINALIZED id=' . $state['id'] . ' result=' . $result
            . ' from=' . $state['from'] . ' to=' . $state['to']
            . ' actor_id=' . ($summary['actor_id'] ?? 'system') . ' mode=' . ($summary['mode'] ?? 'none')
            . ' installed_by=' . ($summary['installed_by'] ?? 'unknown'));
    }

    protected function removeTree(string $directory): void
    {
        if (is_link($directory) || !is_dir($directory)) {
            throw new CouncilSyncIssue('software', 'software_purge');
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isLink() || !($file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()))) {
                throw new CouncilSyncIssue('software', 'software_purge');
            }
        }
        if (!@rmdir($directory)) {
            throw new CouncilSyncIssue('software', 'software_purge');
        }
    }
}
