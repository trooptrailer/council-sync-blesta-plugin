<?php

define('APPDIR', 'app' . DIRECTORY_SEPARATOR);

class Language
{
    private static array $values = [];
    public static function loadLang(array|string $names, mixed $language = null, ?string $path = null): void
    {
        foreach ((array) $names as $name) {
            $lang = [];
            require ($path ?? dirname(__DIR__) . '/plugins/council_sync/language/') . 'en_us/' . $name . '.php';
            self::$values += $lang;
        }
    }
    public static function _(string $key, bool $return = false, mixed ...$arguments): mixed
    {
        if (!isset(self::$values[$key])) {
            throw new RuntimeException('Missing language key: ' . $key);
        }
        $text = $arguments ? vsprintf(self::$values[$key], $arguments) : self::$values[$key];
        if ($return) {
            return $text;
        }
        echo $text;
        return null;
    }
}

class Configure
{
    public static array $values = ['Blesta.company_id' => 1, 'SupportManager.system_staff_id' => 0];
    public static function get(string $key): mixed { return self::$values[$key] ?? null; }
}

class Loader
{
    public static array $models = [];
    public static function loadComponents(object $target, array $names): void
    {
        foreach ($names as $name) {
            if ($name !== 'Input') {
                throw new RuntimeException('Unexpected test component: ' . $name);
            }
            $target->Input = new TestInput();
        }
    }
    public static function loadModels(object $target, array $names): void
    {
        foreach ($names as $name) {
            $parts = explode('.', $name);
            $property = end($parts);
            if (!isset($target->$property)) {
                if (!isset(self::$models[$property])) {
                    throw new RuntimeException('Missing test model: ' . $property);
                }
                $target->$property = self::$models[$property];
            }
        }
    }
}

class TestInput
{
    public array $errors = [];
    public function setErrors(array $errors): void { $this->errors = $errors; }
}

#[AllowDynamicProperties]
class Plugin
{
    public TestInput $Input;
    private array $config;
    protected function loadConfig(string $path): void
    {
        $this->config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
    public function getVersion(): string { return $this->config['version']; }
}

class TestSession
{
    public array $data = ['blesta_staff_id' => 1];
    public function read(string $key): mixed { return $this->data[$key] ?? null; }
    public function write(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function clear(string $key): void { unset($this->data[$key]); }
}

class TestHtml
{
    public function safe(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

class TestForm
{
    public ?string $tokenKey = null;
    private function safe(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public function token(string $uri): string { return hash_hmac('sha256', 'synthetic-session', $uri); }
    public function verifyCsrfToken(?string $uri, mixed $token): bool
    {
        $uri = $uri ?? $this->tokenKey ?? $_SERVER['REQUEST_URI'];
        return is_string($token) && hash_equals($this->token($uri), $token);
    }
    public function create(string $uri): void
    {
        echo '<form method="post" action="' . $this->safe($uri) . '">';
        $this->fieldHidden('_csrf_token', $this->token($this->tokenKey ?? $uri));
    }
    public function end(): void { echo '</form>'; }
    public function fieldHidden(string $name, mixed $value): void
    {
        echo '<input type="hidden" name="' . $this->safe($name) . '" value="' . $this->safe($value) . '">';
    }
    public function fieldText(string $name, mixed $value, array $attributes = []): void { $this->fieldHidden($name, $value); }
    public function fieldNumber(string $name, mixed $value, int $min, int $max, int $step, array $attributes = []): void { $this->fieldHidden($name, $value); }
    public function fieldCheckbox(string $name, mixed $value, bool $checked, array $attributes = []): void { $this->fieldHidden($name, $value); }
    public function fieldRadio(string $name, mixed $value, bool $checked, array $attributes = []): void { $this->fieldHidden($name, $value); }
    public function fieldSelect(string $name, array $values, mixed $selected, array $attributes = []): void
    {
        echo '<select name="' . $this->safe($name) . '">';
        foreach ($values as $value => $label) {
            echo '<option value="' . $this->safe($value) . '">' . $this->safe($label) . '</option>';
        }
        echo '</select>';
    }
}

class TestView
{
    public ?string $file = null;
    public function setView(?string $file, string $view): void { $this->file = $file; }
    public function setDefaultView(string $path): void {}
    public function set(string $key, mixed $value): void {}
}

class TestRedirect extends RuntimeException {}

#[AllowDynamicProperties]
class AppController
{
    public string $base_uri = '/billing/admin/';
    public array $post = [];
    public array $get = [];
    public array $variables = [];
    public array $messages = [];
    public bool $loginRequired = false;
    public TestSession $Session;
    public TestForm $Form;
    public TestHtml $Html;
    public TestView $view;
    public TestView $structure;
    public function __construct()
    {
        $this->Session = new TestSession();
        $this->Form = new TestForm();
        $this->Html = new TestHtml();
        $this->view = $this->structure = new TestView();
    }
    public function preAction() {}
    public function requireLogin(): void { $this->loginRequired = true; }
    public function helpers(array $helpers): void {}
    public function uses(array $models): void { Loader::loadModels($this, $models); }
    public function set(string|array $key, mixed $value = null): void
    {
        $this->variables = is_array($key) ? array_merge($this->variables, $key) : array_merge($this->variables, [$key => $value]);
    }
    public function setMessage(string $kind, string $message): void { $this->messages[] = [$kind, $message]; }
    public function redirect(string $uri): void { throw new TestRedirect($uri); }
}

require dirname(__DIR__) . '/plugins/council_sync/council_sync_plugin.php';
require dirname(__DIR__) . '/plugins/council_sync/council_sync_controller.php';
require dirname(__DIR__) . '/plugins/council_sync/controllers/admin_main.php';

class TestGroups
{
    public function get(int $id): object { return (object) ['id' => $id, 'company_id' => $id, 'name' => 'Synthetic staff']; }
    public function getAll(int $company): array { return [$this->get($company)]; }
}

class TestStaff
{
    public bool $empty = false;
    public function getAll(int $company, ?string $status = null, ?int $group = null): array
    {
        return $this->empty ? [] : [
            (object) ['id' => 1, 'email' => 'one@example.com'],
            (object) ['id' => 2, 'email' => 'two@example.com']
        ];
    }
}

class TestPluginManager
{
    public bool $active = false;
    public ?string $installedVersion = null;
    public function getByDir(string $dir, ?int $company = null): array
    {
        if ($dir === 'council_sync') {
            return [(object) ['id' => 1, 'company_id' => $company ?? 1, 'version' => $this->installedVersion ?? CouncilSync::packageVersion()]];
        }
        return $this->active ? [(object) ['id' => 1, 'company_id' => $company ?? 1, 'enabled' => 1]] : [];
    }
    public function get(int $id): object { return (object) ['id' => $id, 'company_id' => 1]; }
}

class TestDepartments
{
    public function get(int $id): object { return (object) ['id' => $id, 'company_id' => 1, 'name' => 'Synthetic support']; }
    public function getAll(int $company): array { return [$this->get(1)]; }
}

class TestEmails
{
    public array $sent = [];
    public ?string $failRecipient = null;
    public bool $missingTemplate = false;
    private array $errors = [];
    public function errors(): array { return $this->errors; }
    public function getByType(int $company, string $action): mixed
    {
        if ($this->missingTemplate) {
            return false;
        }
        $prefix = $action === 'CouncilSync.version' ? 'version_email' : 'email';
        return (object) [
            'from' => 'noreply@example.com', 'from_name' => 'Council Sync', 'subject' => 'Council Sync',
            'text' => Language::_('CouncilSyncPlugin.' . $prefix . '_text', true),
            'html' => Language::_('CouncilSyncPlugin.' . $prefix . '_html', true)
        ];
    }
    public function buildEmail(string $action, int $company, mixed $lang, array $tags): mixed
    {
        $email = $this->getByType($company, $action);
        if (!$email) {
            return false;
        }
        $tags['admin_uri'] = 'example.com/billing/admin/';
        foreach (['text', 'html'] as $key) {
            foreach ($tags as $name => $value) {
                $email->$key = str_replace('{' . $name . '}', $value, $email->$key);
            }
        }
        return $email;
    }
    public function sendCustom(
        string $from, string $name, string $to, string $subject, array $body, array $tags,
        mixed $cc, mixed $bcc, mixed $attachments, array $options
    ): bool {
        $this->errors = [];
        $this->sent[] = compact('to', 'body', 'tags', 'options', 'cc', 'bcc');
        if ($to === $this->failRecipient) {
            $this->errors = ['delivery' => 'Synthetic failure'];
            return false;
        }
        return true;
    }
    public function deleteAll(int $group, int $company): void {}
}

class TestEmailGroups
{
    public function getByAction(string $action): object { return (object) ['id' => 1]; }
    public function delete(int $id): void {}
    public function errors(): array { return []; }
}

class TestTickets
{
    public bool $failAdd = false;
    public bool $failReply = false;
    public bool $rejectClientless = false;
    public bool $omitCompany = false;
    public array $added = [];
    private array $errors = [];
    public function __construct(public TestRecord $Record) {}
    public function errors(): array { return $this->errors; }
    public function add(array $vars, bool $requireEmail = false): ?int
    {
        $this->added[] = $vars;
        $this->errors = [];
        if ($this->failAdd || ($this->rejectClientless && $vars['client_id'] === null)) {
            $this->errors = ['client' => 'Synthetic rejection'];
            return null;
        }
        $this->Record->insert('test_tickets', [
            'client_id' => $vars['client_id'], 'summary' => $vars['summary'], 'status' => $vars['status'], 'company_id' => 1
        ]);
        return (int) $this->Record->lastInsertId();
    }
    public function get(int $id, bool $replies = true): mixed
    {
        $ticket = $this->Record->query('SELECT * FROM test_tickets WHERE id = ?', $id)->fetch();
        if ($ticket && $this->omitCompany) {
            unset($ticket->company_id);
        }
        return $ticket;
    }
    public function addReply(int $ticket, array $vars, mixed $files, bool $newTicket): ?int
    {
        $this->errors = [];
        if ($this->failReply) {
            $this->errors = ['details' => 'Synthetic rejection'];
            return null;
        }
        $this->Record->insert('test_replies', ['ticket_id' => $ticket, 'details' => $vars['details'], 'staff_id' => $vars['staff_id']]);
        return (int) $this->Record->lastInsertId();
    }
}

class TestCronTasks
{
    public bool $failWrite = false;
    private array $errors = [];
    public function __construct(public TestRecord $Record) {}
    public function getByKey(string $key, string $dir): object { return (object) ['id' => 1]; }
    public function getTaskRunByKey(string $key, string $dir): mixed
    {
        return $this->Record->query('SELECT id AS task_run_id, company_id, interval FROM cron_task_runs WHERE company_id = 1')->fetch();
    }
    public function editTaskRun(int $id, array $vars): void
    {
        $this->errors = [];
        if ($this->failWrite) {
            $this->errors = ['interval' => 'Rejected'];
            return;
        }
        $this->Record->query('UPDATE cron_task_runs SET interval = ? WHERE id = ?', [$vars['interval'], $id]);
    }
    public function deleteTaskRun(int $id): void { $this->Record->query('DELETE FROM cron_task_runs WHERE id = ?', $id); }
    public function deleteTask(int $id, string $type, string $dir): void {}
    public function errors(): array { return $this->errors; }
}

function plugin(TestCouncilSync $model, bool $ticket = false): CouncilSyncPlugin
{
    $model->Record->pdo->exec('CREATE TABLE test_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, summary TEXT, status TEXT, company_id INTEGER)');
    $model->Record->pdo->exec('CREATE TABLE test_replies (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER, staff_id INTEGER, details TEXT)');
    $model->Record->pdo->exec('CREATE TABLE cron_task_runs (id INTEGER PRIMARY KEY, task_id INTEGER, company_id INTEGER, interval INTEGER, enabled INTEGER)');
    $model->Record->pdo->exec('INSERT INTO cron_task_runs VALUES (1,1,1,1440,1)');
    $model->Staff = new TestStaff();
    $model->StaffGroups = new TestGroups();
    $model->CronTasks = new TestCronTasks($model->Record);
    $model->PluginManager = new TestPluginManager();
    $model->PluginManager->active = $ticket;
    Loader::$models = [
        'CouncilSync' => $model, 'Emails' => new TestEmails(), 'Staff' => $model->Staff,
        'StaffGroups' => $model->StaffGroups, 'PluginManager' => $model->PluginManager,
        'SupportManagerTickets' => new TestTickets(new TestRecord(new PDO('sqlite::memory:'))),
        'SupportManagerDepartments' => new TestDepartments(), 'CronTasks' => $model->CronTasks,
        'EmailGroups' => new TestEmailGroups()
    ];
    $instance = new CouncilSyncPlugin();
    $instance->CouncilSync = $model;
    if ($ticket) {
        $model->Record->query('UPDATE council_sync_settings SET department_id = 1');
    }
    return $instance;
}

function controller(TestCouncilSync $model): AdminMain
{
    plugin($model);
    Language::loadLang('admin_main');
    $controller = new AdminMain();
    $controller->preAction();
    return $controller;
}

function runAction(AdminMain $controller, string $method): void
{
    $_SERVER['REQUEST_URI'] = $controller->base_uri . 'plugin/council_sync/admin_main/' . $method . '/';
    try {
        $controller->$method();
    } catch (TestRedirect $redirect) {
        $controller->variables['redirect'] = $redirect->getMessage();
    }
}

test('Blesta 6 declarations register daily cron, staff ACL and company email template', function () {
    $instance = plugin(database([]));
    same(CouncilSync::packageVersion(), $instance->getVersion());
    same(1440, $instance->getCronTaskDefinitions()[0]['type_value']);
    same('staff', $instance->getPermissionGroups()[0]['level']);
    same('council_sync.admin_main', $instance->getPermissions()[0]['alias']);
    same('CouncilSync.issue', $instance->getEmailTemplates()[0]['action']);
    same('nav_primary_staff', $instance->getActions()[0]['action']);
});
test('plugin lifecycle errors use an explicitly initialized Input component', function () {
    $model = database([]);
    $instance = plugin($model);
    $model->Record->failSchema = true;
    $instance->install(1);
    same(true, isset($instance->Input->errors['council_sync']['operation']));
});
test('clean cron never sends mail or creates tickets', function () {
    $model = database([]);
    $model->source([council('X', 3)]);
    $instance = plugin($model, true);
    $instance->cron('sync');
    same([], Loader::$models['Emails']->sent);
    same([], Loader::$models['SupportManagerTickets']->added);
    same(1, count($model->history(1)));
});
test('email notifies only the configured group initially and at the weekly boundary', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1', 2 => 'a-tx-1']);
    $model->source([council('X', 3)]);
    $instance = plugin($model);
    $instance->cron('sync');
    $instance->cron('sync');
    same(2, count(Loader::$models['Emails']->sent));
    $model->clock += 604799;
    $instance->cron('sync');
    same(2, count(Loader::$models['Emails']->sent));
    $model->clock++;
    $instance->cron('sync');
    $sent = Loader::$models['Emails']->sent;
    same(['one@example.com', 'two@example.com', 'one@example.com', 'two@example.com'], array_column($sent, 'to'));
    same(null, $sent[0]['bcc']);
    same(true, str_contains($sent[0]['tags']['details'], 'Affected clients: 2'));
    same(true, str_contains($sent[0]['tags']['details'], 'a-tx-1'));
    same([], $model->history(1));
    same(null, $model->settings(1)['previous_count']);
});
test('open ticket sets deduplicate, and changed in-use counts open a new ticket', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
    $model->source([council('X', 3)]);
    $instance = plugin($model, true);
    $instance->cron('sync');
    $instance->cron('sync');
    same(1, (int) $model->Record->query('SELECT COUNT(*) FROM test_tickets')->fetchColumn());
    same(null, Loader::$models['SupportManagerTickets']->added[0]['client_id']);
    $model->Record->insert('client_values', ['client_field_id' => 1, 'client_id' => 2, 'value' => 'a-tx-1', 'encrypted' => 0]);
    $instance->cron('sync');
    same(2, (int) $model->Record->query('SELECT COUNT(*) FROM test_tickets')->fetchColumn());
    $model->Record->query('DELETE FROM client_values WHERE client_id = 2');
    $instance->cron('sync');
    same(2, (int) $model->Record->query('SELECT COUNT(*) FROM test_tickets')->fetchColumn());
    $note = $model->Record->query('SELECT details FROM test_replies ORDER BY id LIMIT 1')->fetchColumn();
    same(true, str_contains($note, 'https://example.com/billing/admin/plugin/council_sync/admin_main/resolve/'));
    same(4, count(Loader::$models['Emails']->sent));
});
test('ticket and initial note failures roll back without causing extra reminder emails', function () {
    foreach (['failAdd', 'failReply'] as $failure) {
        $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
        $model->source([council('X', 3)]);
        $instance = plugin($model, true);
        Loader::$models['SupportManagerTickets']->$failure = true;
        $instance->cron('sync');
        $instance->cron('sync');
        same(0, (int) $model->Record->query('SELECT COUNT(*) FROM test_tickets')->fetchColumn());
        same(0, (int) $model->Record->query('SELECT COUNT(*) FROM test_replies')->fetchColumn());
        same(2, count(Loader::$models['Emails']->sent));
        same(true, str_contains(Loader::$models['Emails']->sent[0]['tags']['details'], 'fallback notification'));
    }
});
test('temporary Support Manager disablement preserves the prior ticket deduplication', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
    $model->source([council('X', 3)]);
    $instance = plugin($model, true);
    $instance->cron('sync');
    $model->PluginManager->active = false;
    $instance->cron('sync');
    same(2, count(Loader::$models['Emails']->sent));
    $model->PluginManager->active = true;
    $instance->cron('sync');
    same(1, count(Loader::$models['SupportManagerTickets']->added));
});
test('fetch, sanity and collision failures share the ticket/email path with distinct hashes', function () {
    $model = database([]);
    $instance = plugin($model, true);
    $model->response = ['status' => 403, 'body' => '{}'];
    $instance->cron('sync');
    $instance->cron('sync');
    $model->response = ['status' => 429, 'body' => '{}'];
    $instance->cron('sync');
    $model->source([council('A-B', 1), council('A B', 1)]);
    $instance->cron('sync');
    $model->Record->query('UPDATE council_sync_settings SET previous_count = 240');
    $model->source([council('X', 3)]);
    $instance->cron('sync');
    same(4, (int) $model->Record->query('SELECT COUNT(*) FROM test_tickets')->fetchColumn());
    same([], $model->history(1));
    same([], $model->Clients->getCustomField(1)->values);
});
test('partial email delivery and missing templates are explicit failures', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
    $model->source([council('X', 3)]);
    $instance = plugin($model);
    Loader::$models['Emails']->failRecipient = 'two@example.com';
    issue('email_failed', fn() => $instance->cron('sync'));
    same(2, count(Loader::$models['Emails']->sent));
    same(null, $model->Record->query('SELECT email_notified_at FROM council_sync_issues')->fetchColumn());
    Loader::$models['Emails']->missingTemplate = true;
    issue('email_template', fn() => $instance->cron('sync'));
});
test('settings validate groups, schedule changes, and destructive confirmation atomically', function () {
    $model = database([]);
    plugin($model);
    $input = [
        'field_id' => '1', 'staff_group_id' => '1', 'contact' => 'support@example.com', 'max_drop' => '10', 'frequency' => '60',
        'email_enabled' => '1', 'ticket_enabled' => '1', 'updates_enabled' => '1'
    ];
    $model->saveSettings(1, $input);
    same(60, $model->CronTasks->getTaskRunByKey('sync', 'council_sync')->interval);
    $model->CronTasks->failWrite = true;
    $input['frequency'] = '90';
    issue('cron_write', fn() => $model->saveSettings(1, $input));
    same(60, $model->settings(1)['frequency']);
    $model->CronTasks->failWrite = false;
    $input['purge'] = '1';
    issue('purge_confirmation', fn() => $model->saveSettings(1, $input));
    $input['purge_confirmation'] = 'DELETE COUNCIL SYNC DATA';
    $model->saveSettings(1, $input);
    same(1, $model->settings(1)['purge']);
    $input['staff_group_id'] = '2';
    issue('staff_group', fn() => $model->saveSettings(1, $input));
});
test('uninstall retains audit tables by default and drops only with explicit final-instance authorization', function () {
    $model = database([]);
    $instance = plugin($model);
    $instance->uninstall(1, true);
    same([], $instance->Input->errors);
    same(1, (int) $model->Record->query('SELECT COUNT(*) FROM council_sync_settings')->fetchColumn());
    $model->Record->query('UPDATE council_sync_settings SET purge = 1');
    $model->uninstallTables(1, false);
    same(1, (int) $model->Record->query('SELECT COUNT(*) FROM council_sync_settings')->fetchColumn());
    $model->uninstallTables(1, true);
    same(0, (int) $model->Record->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'council_sync_%'")->fetchColumn());
    same(1, (int) $model->Record->query('SELECT COUNT(*) FROM client_fields')->fetchColumn());
});
test('manual update requires staff login and a canonical CSRF token, and never notifies', function () {
    $model = database([]);
    $model->source([council('X', 3)]);
    $controller = controller($model);
    same(true, $controller->loginRequired);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    runAction($controller, 'update');
    same(0, $model->requests);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $controller->post = ['_csrf_token' => 'invalid'];
    runAction($controller, 'update');
    same(0, $model->requests);
    $controller->post = ['_csrf_token' => $controller->Form->token('/billing/admin/plugin/council_sync/admin_main/update/')];
    runAction($controller, 'update');
    same(1, count($model->history(1)));
    same([], Loader::$models['Emails']->sent);
    same([], Loader::$models['SupportManagerTickets']->added);
});
test('manual conflict confirmation uses a staff-bound nonce and only server-staged targets', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
    $model->source([council('X', 3)]);
    $controller = controller($model);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $controller->post = ['_csrf_token' => $controller->Form->token('/billing/admin/plugin/council_sync/admin_main/update/')];
    runAction($controller, 'update');
    same([], $model->history(1));
    $pending = $controller->Session->read('council_sync_preview_1_1');
    $controller->post = [
        '_csrf_token' => $controller->Form->token('/billing/admin/plugin/council_sync/admin_main/resolve/'),
        'nonce' => 'wrong', 'confirm_apply' => '1', 'choices' => []
    ];
    runAction($controller, 'resolve');
    same([], $model->history(1));
    $controller->Session->write('council_sync_preview_1_1', $pending);
    $controller->post['nonce'] = $pending['nonce'];
    $controller->post['choices'] = [['mode' => 'remap', 'target' => 'x-tx-3']];
    runAction($controller, 'resolve');
    same('x-tx-3', $model->Clients->getCustomFieldValues(1)[0]->value);
    same(null, $controller->Session->read('council_sync_preview_1_1'));
    same([], Loader::$models['Emails']->sent);
});
test('encrypted-row flag mismatch aborts instead of treating ciphertext as an unused value', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1'], true);
    $model->Record->query('UPDATE client_fields SET encrypted = 0');
    $model->source([council('X', 3)]);
    issue('value_read', fn() => $model->preview(1));
    same([], $model->history(1));
});
test('empty unset defaults remain unchanged during option updates and rollback', function () {
    $model = database([], [], false, '');
    $model->source([council('X', 3)]);
    $batch = $model->synchronize(1, 'cron', null)['batch_id'];
    same('', $model->Clients->getCustomField(1)->default);
    $model->rollback(1, $batch, 1);
    same('', $model->Clients->getCustomField(1)->default);
});
test('CSRF validation honors a platform-configured token key', function () {
    $model = database([]);
    $model->source([council('X', 3)]);
    $controller = controller($model);
    $controller->Form->tokenKey = 'synthetic-platform-key';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $controller->post = ['_csrf_token' => $controller->Form->token('synthetic-platform-key')];
    runAction($controller, 'update');
    same(1, count($model->history(1)));
});
test('client-value audit copies are encrypted and unreadable copies block rollback', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1'], true);
    $model->source([council('X', 3)]);
    $result = $model->apply(1, $model->preview(1), ['a-tx-1' => ['mode' => 'remap', 'target' => 'x-tx-3']], 'manual', 1);
    $stored = $model->Record->query('SELECT old_value FROM council_sync_items')->fetchColumn();
    same(false, $stored === 'a-tx-1');
    same('a-tx-1', $model->batchItems(1, $result['batch_id'])[0]->old_value);
    $model->Record->query("UPDATE council_sync_items SET old_value = 'unreadable'");
    issue('audit_crypto', fn() => $model->rollback(1, $result['batch_id'], 1));
    same('applied', $model->batch(1, $result['batch_id'])->status);
    same('x-tx-3', $model->Clients->getCustomFieldValues(1)[0]->value);
});
class TestChoiceController extends AdminMain
{
    public static function map(array $conflicts, mixed $choices): array
    {
        return parent::indexedChoices($conflicts, $choices);
    }
}
test('truncated per-client forms cannot silently discard overrides and use remap-all', function () {
    $conflicts = ['old][value' => ['clients' => [['id' => 1], ['id' => 2]]]];
    issue('form_incomplete', fn() => TestChoiceController::map($conflicts, [
        ['mode' => 'clients', 'all_target' => 'x', 'clients' => [1 => 'x']]
    ]));
    same(['old][value' => ['mode' => 'clients', 'all_target' => 'x', 'clients' => [1 => '', 2 => 'y']]],
        TestChoiceController::map($conflicts, [['mode' => 'clients', 'all_target' => 'x', 'clients' => [1 => '', 2 => 'y']]]));
});
test('User-Agent contacts cannot disclose URL credentials', function () {
    issue('contact', fn() => CouncilSync::validateContact('https://user:password@example.com/contact'));
    issue('contact', fn() => CouncilSync::validateContact('https://user@example.com/contact'));
});

test('stable releases require this repository, complete version numbers and no prerelease or draft', function () {
    $model = database([]);
    $model->release('v2.3.4');
    same(['version' => '2.3.4', 'url' => 'https://github.com/trooptrailer/council-sync-blesta-plugin/releases/tag/v2.3.4'],
        CouncilSync::parseRelease($model->releaseResponse['body']));
    $base = json_decode($model->releaseResponse['body'], true);
    foreach ([
        ['draft' => true], ['prerelease' => true], ['tag_name' => '2.3'], ['tag_name' => 'v02.3.4'],
        ['tag_name' => 'v2.3.4-rc.1'], ['html_url' => 'https://example.com/release'],
        ['html_url' => 'javascript:alert(1)']
    ] as $invalid) {
        issue('release_response', fn() => CouncilSync::parseRelease(json_encode(array_merge($base, $invalid))));
    }
    issue('release_response', fn() => CouncilSync::parseRelease('not json'));
});

class ReleaseTransportProbe extends CouncilSync
{
    public array $request = [];
    public function __construct() {}
    protected function httpGet(string $url, string $agent, int $limit, array $headers = ['Accept: application/json']): array
    {
        $this->request = compact('url', 'agent', 'limit', 'headers');
        return ['status' => 404, 'body' => '{}'];
    }
    public function fetch(): array
    {
        return parent::updateRequest();
    }
}

test('release transport targets the intended public API without authentication', function () {
    $probe = new ReleaseTransportProbe();
    same(404, $probe->fetch()['status']);
    same('https://api.github.com/repos/trooptrailer/council-sync-blesta-plugin/releases/latest', $probe->request['url']);
    same(['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2026-03-10'], $probe->request['headers']);
    same(true, str_contains($probe->request['agent'], 'CouncilSync-Blesta/1.1.0'));
});

test('release checks compare numeric versions and distinguish package, registered and remote versions', function () {
    $model = database([]);
    plugin($model);
    same('unchecked', $model->versionStatus(1)['status']);
    $model->PluginManager->installedVersion = '1.9.0';
    $model->release('v1.10.0');
    $status = $model->checkForUpdates(1);
    same('outdated', $status['status']);
    same('1.9.0', $status['installed']);
    same('1.1.0', $status['package']);
    same('1.10.0', $status['latest']);
    same(true, $status['upgrade_required']);
    $model->PluginManager->installedVersion = CouncilSync::packageVersion();
    $model->release('v' . CouncilSync::packageVersion());
    same('current', $model->checkForUpdates(1)['status']);
    $model->PluginManager->installedVersion = '1.0.0';
    same('outdated', $model->versionStatus(1)['status']);
    same([], $model->history(1));
});

test('private, missing, rate-limited and invalid release responses remain explicitly unavailable', function () {
    $model = database([]);
    plugin($model);
    foreach ([404, 403, 429, 302] as $http) {
        $model->releaseResponse = ['status' => $http, 'body' => '{}'];
        $status = $model->checkForUpdates(1);
        same('unavailable', $status['status']);
        same('http_' . $http, $status['error_code']);
        same(null, $status['latest']);
        same(null, $status['last_success_at']);
    }
    $model->release('v2.0.0');
    $success = $model->checkForUpdates(1);
    $model->clock += 60;
    $model->releaseResponse = ['status' => 200, 'body' => 'invalid'];
    $failed = $model->checkForUpdates(1);
    same('unavailable', $failed['status']);
    same('release_response', $failed['error_code']);
    same('2.0.0', $failed['latest']);
    same($success['last_success_at'], $failed['last_success_at']);
    $model->releaseFailure = new CouncilSyncIssue('fetch', 'transport');
    same('transport', $model->checkForUpdates(1)['error_code']);
    same([], Loader::$models['Emails']->sent);
});

test('release cron sends both initial channels, then weekly email and only replaces a closed ticket when due', function () {
    $model = database([]);
    $instance = plugin($model, true);
    $model->release('v2.0.0');
    $instance->cron('updates');
    same(2, count(Loader::$models['Emails']->sent));
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    same(0, $model->requests);
    $body = Loader::$models['Emails']->sent[0]['body']['text'];
    same(true, str_contains($body, 'software notice'));
    same(true, str_contains(Loader::$models['Emails']->sent[0]['tags']['details'], '2.0.0'));
    $model->Record->query("UPDATE test_tickets SET status = 'closed'");
    $model->clock += 604799;
    $instance->cron('updates');
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    same(2, count(Loader::$models['Emails']->sent));
    $model->clock++;
    $instance->cron('updates');
    same(2, count(Loader::$models['SupportManagerTickets']->added));
    same(4, count(Loader::$models['Emails']->sent));
    $model->clock += 604800;
    $instance->cron('updates');
    same(2, count(Loader::$models['SupportManagerTickets']->added));
    same(6, count(Loader::$models['Emails']->sent));
    $model->release('v' . CouncilSync::packageVersion());
    $model->clock += 604800;
    $instance->cron('updates');
    same(6, count(Loader::$models['Emails']->sent));
    same([], $model->history(1));
});

test('new versions get new notices, independent of the selected council field', function () {
    $model = database([]);
    $instance = plugin($model, true);
    $model->release('v2.0.0');
    $instance->cron('updates');
    $model->Record->query('UPDATE council_sync_settings SET field_id = 2');
    $instance->cron('updates');
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    $model->release('v2.1.0');
    $instance->cron('updates');
    same(2, count(Loader::$models['SupportManagerTickets']->added));
    same(4, count(Loader::$models['Emails']->sent));
    same([0, 0], array_map('intval', $model->Record->query('SELECT field_id FROM council_sync_issues')->fetchAll(PDO::FETCH_COLUMN)));
});

test('unavailable release checks use the same weekly notification policy without claiming an update exists', function () {
    $model = database([]);
    $instance = plugin($model, true);
    $instance->cron('updates');
    $instance->cron('updates');
    same('unavailable', $model->versionStatus(1)['status']);
    same(2, count(Loader::$models['Emails']->sent));
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    same(true, str_contains(Loader::$models['Emails']->sent[0]['tags']['details'], 'private GitHub releases'));
    $model->clock += 604800;
    $instance->cron('updates');
    same(4, count(Loader::$models['Emails']->sent));
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    $model->Record->query('UPDATE council_sync_settings SET updates_enabled = 0');
    $requests = $model->releaseRequests;
    $instance->cron('updates');
    same($requests, $model->releaseRequests);
});

test('notification channel switches independently gate every type of notice', function () {
    foreach (['sync', 'updates'] as $cron) {
        foreach ([[1, 0], [0, 1], [0, 0], [1, 1]] as [$email, $ticket]) {
            $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
            $model->source([council('X', 3)]);
            $model->release('v2.0.0');
            $instance = plugin($model, true);
            $model->Record->query('UPDATE council_sync_settings SET email_enabled = ?, ticket_enabled = ?', [$email, $ticket]);
            $instance->cron($cron);
            same($email * 2, count(Loader::$models['Emails']->sent));
            same($ticket, count(Loader::$models['SupportManagerTickets']->added));
        }
    }
});

test('a ticket success survives email failure without duplicate tickets on retry', function () {
    $model = database([]);
    $instance = plugin($model, true);
    $model->release('v2.0.0');
    Loader::$models['Emails']->failRecipient = 'two@example.com';
    issue('email_failed', fn() => $instance->cron('updates'));
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    Loader::$models['Emails']->failRecipient = null;
    $instance->cron('updates');
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    same(['one@example.com', 'two@example.com', 'two@example.com'], array_column(Loader::$models['Emails']->sent, 'to'));
    $issue = $model->Record->query('SELECT * FROM council_sync_issues')->fetch();
    same(gmdate('Y-m-d H:i:s', $model->clock), $issue->email_notified_at);
    same(gmdate('Y-m-d H:i:s', $model->clock), $issue->ticket_notified_at);
});

test('clientless rejection never creates a dummy client or sends disabled fallback email', function () {
    $model = database([]);
    $instance = plugin($model, true);
    $model->release('v2.0.0');
    Loader::$models['SupportManagerTickets']->rejectClientless = true;
    $model->Record->query('UPDATE council_sync_settings SET email_enabled = 0');
    issue('ticket_failed', fn() => $instance->cron('updates'));
    same([], Loader::$models['Emails']->sent);
    same(null, Loader::$models['SupportManagerTickets']->added[0]['client_id']);
    same(0, (int) $model->Record->query('SELECT COUNT(*) FROM test_tickets')->fetchColumn());
    $model->Record->query('UPDATE council_sync_settings SET email_enabled = 1');
    $instance->cron('updates');
    same(2, count(Loader::$models['Emails']->sent));
});

test('missing metadata, missing tickets and cross-company tickets are not silently replaced', function () {
    $model = database([]);
    $instance = plugin($model, true);
    $model->release('v2.0.0');
    $instance->cron('updates');
    Loader::$models['SupportManagerTickets']->omitCompany = true;
    $model->clock += 604800;
    $instance->cron('updates');
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    same(4, count(Loader::$models['Emails']->sent));
    Loader::$models['SupportManagerTickets']->omitCompany = false;
    $model->Record->query('UPDATE test_tickets SET company_id = 2');
    $model->clock += 604800;
    $instance->cron('updates');
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    same(6, count(Loader::$models['Emails']->sent));
    $model->Record->query('DELETE FROM test_tickets');
    $model->clock += 604800;
    $instance->cron('updates');
    same(1, count(Loader::$models['SupportManagerTickets']->added));
    same(8, count(Loader::$models['Emails']->sent));
});

test('manual release checks require CSRF and never send email or tickets', function () {
    $model = database([]);
    $model->release('v2.0.0');
    $controller = controller($model);
    $model->Record->query('UPDATE council_sync_settings SET updates_enabled = 0');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    runAction($controller, 'checkupdates');
    same(0, $model->releaseRequests);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $controller->post = ['_csrf_token' => 'wrong'];
    runAction($controller, 'checkupdates');
    same(0, $model->releaseRequests);
    $controller->post = ['_csrf_token' => $controller->Form->token('/billing/admin/plugin/council_sync/admin_main/checkupdates/')];
    runAction($controller, 'checkupdates');
    same(1, $model->releaseRequests);
    same('outdated', $model->versionStatus(1)['status']);
    same([], Loader::$models['Emails']->sent);
    same([], Loader::$models['SupportManagerTickets']->added);
});

test('delivery settings persist switches, allow both disabled, and validate enabled-channel destinations', function () {
    $model = database([]);
    plugin($model);
    $input = ['field_id' => '1', 'contact' => 'support@example.com', 'max_drop' => '10', 'frequency' => '1440'];
    $model->saveSettings(1, $input);
    $settings = $model->settings(1);
    same(null, $settings['staff_group_id']);
    same(0, $settings['email_enabled']);
    same(0, $settings['ticket_enabled']);
    same(0, $settings['updates_enabled']);
    $input['email_enabled'] = '1';
    issue('staff_group', fn() => $model->saveSettings(1, $input));
    $input['email_enabled'] = '0';
    $input['ticket_enabled'] = '1';
    issue('department', fn() => $model->saveSettings(1, $input));
    $input['ticket_enabled'] = 'invalid';
    issue('settings', fn() => $model->saveSettings(1, $input));
});

test('weekly reminder times are UTC and do not depend on the PHP local time zone', function () {
    $model = database([]);
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Pacific/Honolulu');
    try {
        $last = gmdate('Y-m-d H:i:s', $model->clock - 604799);
        same(false, $model->noticeDue($last));
        $model->clock++;
        same(true, $model->noticeDue($last));
        issue('notice_state', fn() => $model->noticeDue('invalid'));
    } finally {
        date_default_timezone_set($timezone);
    }
});

test('upgrade preserves old notification history and settings, adds new state, and is repeatable', function () {
    $model = database([]);
    plugin($model);
    foreach (['email_enabled', 'ticket_enabled', 'updates_enabled'] as $column) {
        $model->Record->pdo->exec('ALTER TABLE council_sync_settings DROP COLUMN ' . $column);
    }
    $model->Record->pdo->exec('DROP TABLE council_sync_issues');
    $model->Record->pdo->exec('CREATE TABLE council_sync_issues (company_id INTEGER, field_id INTEGER, hash TEXT,
        kind TEXT, ticket_id INTEGER, notified_at TEXT NOT NULL, PRIMARY KEY (company_id,field_id,hash))');
    $last = gmdate('Y-m-d H:i:s', $model->clock - 100);
    foreach (['ticket' => 55, 'email' => null] as $hash => $ticket) {
        $model->Record->insert('council_sync_issues', [
            'company_id' => 1, 'field_id' => 1, 'hash' => $hash, 'kind' => 'conflicts', 'ticket_id' => $ticket, 'notified_at' => $last
        ]);
    }
    issue('schema_upgrade', fn() => $model->settings(1));
    $model->installTables(1);
    $model->installTables(1);
    same(1, $model->settings(1)['email_enabled']);
    same(1, $model->settings(1)['ticket_enabled']);
    same(1, $model->settings(1)['updates_enabled']);
    same('https://example.com/council-sync', $model->settings(1)['contact']);
    same($last, $model->issueRecord(1, 1, 'email')->email_notified_at);
    same($last, $model->issueRecord(1, 1, 'ticket')->ticket_notified_at);
    same(55, $model->issueRecord(1, 1, 'ticket')->ticket_id);
    same(null, $model->prepareNotice(1, 0, 'new', 'version')->notified_at);
    $model->installTables(2);
    same(null, $model->settings(2)['field_id']);
    same(1, $model->settings(1)['field_id']);
});

test('settings display reads cached versions without making a release request', function () {
    $model = database([]);
    $controller = controller($model);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    runAction($controller, 'index');
    same('unchecked', $controller->variables['version_status']['status']);
    same('1.1.0', $controller->variables['version_status']['package']);
    same(0, $model->releaseRequests);
    same([], Loader::$models['Emails']->sent);
});

class TestRenderer
{
    public string $base_uri = '/billing/admin/';
    public TestForm $Form;
    public TestHtml $Html;
    public function __construct() { $this->Form = new TestForm(); $this->Html = new TestHtml(); }
    public function _(string $key, bool $return = false): mixed { return Language::_($key, $return); }
    public function render(string $file, array $variables): string
    {
        extract($variables);
        ob_start();
        try {
            require dirname(__DIR__) . '/plugins/council_sync/views/default/' . $file . '.pdt';
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}

test('PDT forms use canonical action paths and escape all council and client output', function () {
    Language::loadLang('admin_main');
    $renderer = new TestRenderer();
    $base = '/billing/admin/plugin/council_sync/admin_main/';
    $model = database(['old][value' => '<script>old</script>'], [1 => 'old][value']);
    $model->source([council('X', 3)]);
    $preview = $model->preview(1);
    $html = $renderer->render('admin_resolve', [
        'sync_uri' => $base, 'nonce' => 'synthetic-nonce', 'preview' => $preview,
        'client_names' => [1 => '<img src=x onerror=alert(1)>']
    ]);
    same(false, str_contains($html, '<script>old</script>'));
    same(false, str_contains($html, '<img src=x'));
    same(true, str_contains($html, 'name="choices[0][mode]"'));
    same(true, str_contains($html, 'action="' . $base . 'resolve/"'));
    same(false, str_contains($html, '/../'));
    same(true, str_contains($html, $renderer->Form->token($base . 'resolve/')));
    $html = $renderer->render('admin_main', [
        'sync_uri' => $base, 'settings' => $model->settings(1), 'fields' => [$model->Clients->getCustomField(1)],
        'groups' => [], 'support_active' => false, 'departments' => [],
        'version_status' => [
            'installed' => '1.0.0', 'package' => '1.1.0', 'latest' => null, 'release_url' => null,
            'checked_at' => null, 'last_success_at' => null, 'status' => 'unchecked', 'upgrade_required' => true, 'error_code' => null
        ]
    ]);
    same(false, str_contains($html, 'name="department_id"'));
    same(true, str_contains($html, 'action="' . $base . 'index/"'));
    same(true, str_contains($html, 'action="' . $base . 'update/"'));
    same(true, str_contains($html, $renderer->Form->token($base . 'update/')));
    same(true, str_contains($html, 'action="' . $base . 'checkupdates/"'));
    foreach (['email_enabled', 'ticket_enabled', 'updates_enabled'] as $setting) {
        same(true, str_contains($html, 'name="' . $setting . '"'));
    }
});
