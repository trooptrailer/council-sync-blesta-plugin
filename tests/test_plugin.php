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
    public function getByDir(string $dir, ?int $company = null): array
    {
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
        return (object) [
            'from' => 'noreply@example.com', 'from_name' => 'Council Sync', 'subject' => 'Council Sync',
            'text' => Language::_('CouncilSyncPlugin.email_text', true), 'html' => Language::_('CouncilSyncPlugin.email_html', true)
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
    public array $added = [];
    private array $errors = [];
    public function __construct(public TestRecord $Record) {}
    public function errors(): array { return $this->errors; }
    public function add(array $vars, bool $requireEmail = false): ?int
    {
        $this->added[] = $vars;
        $this->errors = [];
        if ($this->failAdd) {
            $this->errors = ['client' => 'Synthetic rejection'];
            return null;
        }
        $this->Record->insert('test_tickets', ['client_id' => $vars['client_id'], 'summary' => $vars['summary']]);
        return (int) $this->Record->lastInsertId();
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
    $model->Record->pdo->exec('CREATE TABLE test_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, summary TEXT)');
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
    same(CouncilSync::VERSION, $instance->getVersion());
    same(1440, $instance->getCronTaskDefinitions()[0]['type_value']);
    same('staff', $instance->getPermissionGroups()[0]['level']);
    same('council_sync.admin_main', $instance->getPermissions()[0]['alias']);
    same('CouncilSync.issue', $instance->getEmailTemplates()[0]['action']);
    same('nav_primary_staff', $instance->getActions()[0]['action']);
});
test('plugin lifecycle errors use an explicitly initialized Input component', function () {
    $instance = plugin(database([]));
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
test('email fallback notifies only the configured group on every blocked cron', function () {
    $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1', 2 => 'a-tx-1']);
    $model->source([council('X', 3)]);
    $instance = plugin($model);
    $instance->cron('sync');
    $instance->cron('sync');
    $sent = Loader::$models['Emails']->sent;
    same(['one@example.com', 'two@example.com', 'one@example.com', 'two@example.com'], array_column($sent, 'to'));
    same(null, $sent[0]['bcc']);
    same(true, str_contains($sent[0]['tags']['details'], 'Affected clients: 2'));
    same(true, str_contains($sent[0]['tags']['details'], 'a-tx-1'));
    same([], $model->history(1));
    same(null, $model->settings(1)['previous_count']);
});
test('ticket sets deduplicate permanently, and changed in-use counts open a new ticket', function () {
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
    same([], Loader::$models['Emails']->sent);
});
test('ticket and initial note failures roll back and email the group each run', function () {
    foreach (['failAdd', 'failReply'] as $failure) {
        $model = database(['a-tx-1' => 'A, TX (1)'], [1 => 'a-tx-1']);
        $model->source([council('X', 3)]);
        $instance = plugin($model, true);
        Loader::$models['SupportManagerTickets']->$failure = true;
        $instance->cron('sync');
        $instance->cron('sync');
        same(0, (int) $model->Record->query('SELECT COUNT(*) FROM test_tickets')->fetchColumn());
        same(0, (int) $model->Record->query('SELECT COUNT(*) FROM test_replies')->fetchColumn());
        same(4, count(Loader::$models['Emails']->sent));
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
    same(0, (int) $model->Record->query('SELECT COUNT(*) FROM council_sync_issues')->fetchColumn());
    Loader::$models['Emails']->missingTemplate = true;
    issue('email_template', fn() => $instance->cron('sync'));
});
test('settings validate groups, schedule changes, and destructive confirmation atomically', function () {
    $model = database([]);
    plugin($model);
    $input = ['field_id' => '1', 'staff_group_id' => '1', 'contact' => 'support@example.com', 'max_drop' => '10', 'frequency' => '60'];
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
        'groups' => [], 'support_active' => false, 'departments' => []
    ]);
    same(false, str_contains($html, 'name="department_id"'));
    same(true, str_contains($html, 'action="' . $base . 'index/"'));
    same(true, str_contains($html, 'action="' . $base . 'update/"'));
    same(true, str_contains($html, $renderer->Form->token($base . 'update/')));
});
