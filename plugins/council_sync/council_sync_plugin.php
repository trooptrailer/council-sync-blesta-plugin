<?php

class CouncilSyncPlugin extends Plugin
{
    private const EMAIL_ACTION = 'CouncilSync.issue';

    public function __construct()
    {
        Language::loadLang(['council_sync_plugin', 'council_sync'], null, __DIR__ . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR);
        $this->loadConfig(__DIR__ . DIRECTORY_SEPARATOR . 'config.json');
        Loader::loadComponents($this, ['Input']);
    }

    public function install($plugin_id)
    {
        Loader::loadModels($this, ['CouncilSync.CouncilSync']);
        try {
            $this->CouncilSync->installTables((int) Configure::get('Blesta.company_id'));
        } catch (CouncilSyncIssue|PDOException $error) {
            $this->lifecycleError($error);
        }
    }

    public function upgrade($current_version, $plugin_id)
    {
        Loader::loadModels($this, ['CouncilSync.CouncilSync', 'PluginManager']);
        try {
            if (version_compare($current_version, $this->getVersion(), '>')) {
                throw new CouncilSyncIssue('storage', 'downgrade');
            }
            foreach ($this->PluginManager->getByDir('council_sync') as $instance) {
                $this->CouncilSync->installTables((int) $instance->company_id);
            }
        } catch (CouncilSyncIssue|PDOException $error) {
            $this->lifecycleError($error);
        }
    }

    public function uninstall($plugin_id, $last_instance)
    {
        Loader::loadModels($this, ['CouncilSync.CouncilSync', 'PluginManager', 'CronTasks', 'Emails', 'EmailGroups']);
        try {
            $instance = $this->PluginManager->get($plugin_id);
            if (!$instance) {
                throw new CouncilSyncIssue('settings', 'not_installed');
            }
            $company = (int) $instance->company_id;
            $this->CouncilSync->locked($company, function () use ($company, $last_instance) {
                $task = $this->CronTasks->getByKey('sync', 'council_sync');
                if ($task) {
                    $runs = $this->CouncilSync->Record->select('id')->from('cron_task_runs')
                        ->where('company_id', '=', $company)->where('task_id', '=', $task->id)->fetchAll();
                    foreach ($runs as $run) {
                        $this->CronTasks->deleteTaskRun($run->id);
                    }
                    if ($last_instance) {
                        $this->CronTasks->deleteTask($task->id, 'plugin', 'council_sync');
                    }
                }
                $group = $this->EmailGroups->getByAction(self::EMAIL_ACTION);
                if ($group) {
                    $this->Emails->deleteAll($group->id, $company);
                    if ($last_instance) {
                        $this->EmailGroups->delete($group->id);
                    }
                }
                if ($this->CronTasks->errors() || $this->Emails->errors() || $this->EmailGroups->errors()) {
                    throw new CouncilSyncIssue('storage', 'uninstall');
                }
                $this->CouncilSync->uninstallTables($company, (bool) $last_instance);
            });
        } catch (CouncilSyncIssue|PDOException $error) {
            $this->lifecycleError($error);
        }
    }

    private function lifecycleError(CouncilSyncIssue|PDOException $error): void
    {
        $message = $error instanceof CouncilSyncIssue
            ? $error->description() : Language::_('CouncilSync.!error.database', true);
        error_log('COUNCIL_SYNC_LIFECYCLE_FAILED reason=' . ($error instanceof CouncilSyncIssue ? $error->reason : 'database'));
        $this->Input->setErrors(['council_sync' => ['operation' => $message]]);
    }

    public function getActions()
    {
        return [[
            'action' => 'nav_primary_staff', 'uri' => 'plugin/council_sync/admin_main/',
            'name' => Language::_('CouncilSyncPlugin.name', true), 'icon' => 'bi-arrow-repeat',
            'enabled' => 1, 'options' => ['sub' => [
                ['uri' => 'plugin/council_sync/admin_main/', 'name' => Language::_('CouncilSyncPlugin.settings', true)],
                ['uri' => 'plugin/council_sync/admin_main/resolve/', 'name' => Language::_('CouncilSyncPlugin.resolve', true)],
                ['uri' => 'plugin/council_sync/admin_main/history/', 'name' => Language::_('CouncilSyncPlugin.history', true)]
            ]]
        ]];
    }

    public function getPermissionGroups()
    {
        return [['name' => Language::_('CouncilSyncPlugin.name', true), 'level' => 'staff', 'alias' => 'council_sync']];
    }

    public function getPermissions()
    {
        return [[
            'group_alias' => 'council_sync', 'name' => Language::_('CouncilSyncPlugin.permission', true),
            'alias' => 'council_sync.admin_main', 'action' => '*'
        ]];
    }

    public function getCronTaskDefinitions()
    {
        return [[
            'key' => 'sync', 'task_type' => 'plugin', 'dir' => 'council_sync',
            'name' => Language::_('CouncilSyncPlugin.cron_name', true),
            'description' => Language::_('CouncilSyncPlugin.cron_description', true),
            'type' => 'interval', 'type_value' => 1440, 'enabled' => 1
        ]];
    }

    public function getEmailTemplates()
    {
        return [[
            'action' => self::EMAIL_ACTION, 'type' => 'staff', 'plugin_dir' => 'council_sync',
            'tags' => 'issue_type,issue_hash,details,details_html', 'from' => 'noreply@mydomain.com',
            'from_name' => Language::_('CouncilSyncPlugin.name', true),
            'subject' => Language::_('CouncilSyncPlugin.email_subject', true),
            'text' => Language::_('CouncilSyncPlugin.email_text', true),
            'html' => Language::_('CouncilSyncPlugin.email_html', true)
        ]];
    }

    public function cron($key)
    {
        if ($key !== 'sync') {
            return;
        }
        Loader::loadModels($this, ['CouncilSync.CouncilSync']);
        $company = (int) Configure::get('Blesta.company_id');
        try {
            $this->CouncilSync->locked($company, function () use ($company) {
                $settings = $this->CouncilSync->settings($company);
                if (!$settings['field_id'] || !$settings['staff_group_id'] || $settings['contact'] === '') {
                    error_log('COUNCIL_SYNC_NOT_CONFIGURED company_id=' . $company);
                    return;
                }
                try {
                    $result = $this->CouncilSync->synchronize($company, 'cron', null);
                } catch (CouncilSyncIssue $error) {
                    if (!in_array($error->kind, ['fetch', 'sanity', 'collision'], true)) {
                        throw $error;
                    }
                    $this->notify($company, $settings, $error->kind, $error->issueHash(), $error->description());
                    return;
                }
                if ($result['status'] === 'conflicts') {
                    $conflicts = $result['preview']['conflicts'];
                    $lines = [];
                    foreach ($conflicts as $value => $conflict) {
                        $lines[] = Language::_('CouncilSyncPlugin.conflict_line', true, $value, $conflict['label'], $conflict['count']);
                    }
                    $this->notify($company, $settings, 'conflicts', CouncilSync::conflictHash($conflicts), implode("\n", $lines));
                } else {
                    error_log('COUNCIL_SYNC_' . strtoupper($result['status']) . ' company_id=' . $company
                        . ' field_id=' . $settings['field_id'] . ' batch_id=' . ($result['batch_id'] ?? 'none')
                        . ' summary=' . CouncilSync::json($result['summary']));
                }
            });
        } catch (CouncilSyncIssue $error) {
            error_log('COUNCIL_SYNC_FAILED company_id=' . $company . ' kind=' . $error->kind . ' reason=' . $error->reason);
            throw $error;
        } catch (PDOException $error) {
            error_log('COUNCIL_SYNC_FAILED company_id=' . $company . ' reason=database sqlstate=' . $error->getCode());
            throw new CouncilSyncIssue('storage', 'database');
        }
    }

    private function notify(int $company, array $settings, string $kind, string $hash, string $details): void
    {
        Loader::loadModels($this, ['Emails', 'Staff', 'StaffGroups']);
        $tags = [
            'issue_type' => Language::_('CouncilSyncPlugin.issue_' . $kind, true), 'issue_hash' => $hash,
            'details' => $details, 'details_html' => htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        ];
        $field = $settings['field_id'];
        $existing = $this->CouncilSync->issueRecord($company, $field, $hash);
        if ($settings['department_id'] && $this->CouncilSync->supportActive($company)) {
            if ($existing && $existing->ticket_id !== null) {
                return;
            }
            try {
                $this->createTicket($company, $settings, $kind, $hash, $tags);
                return;
            } catch (CouncilSyncIssue|PDOException $error) {
                error_log('COUNCIL_SYNC_TICKET_FAILED company_id=' . $company . ' field_id=' . $field
                    . ' reason=' . ($error instanceof CouncilSyncIssue ? $error->reason : 'database'));
                $tags['details'] .= "\n\n" . Language::_('CouncilSyncPlugin.ticket_fallback', true);
                $tags['details_html'] = htmlspecialchars($tags['details'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        $group = $this->StaffGroups->get($settings['staff_group_id']);
        $members = $this->Staff->getAll($company, 'active', $settings['staff_group_id']);
        if (!$group || (int) $group->company_id !== $company || !$members) {
            throw new CouncilSyncIssue('notification', 'staff_group');
        }
        $template = $this->Emails->getByType($company, self::EMAIL_ACTION);
        if (!$template) {
            throw new CouncilSyncIssue('notification', 'email_template');
        }
        $failed = [];
        foreach ($members as $member) {
            $sent = $this->Emails->sendCustom(
                $template->from, $template->from_name, $member->email, $template->subject,
                ['text' => $template->text, 'html' => $template->html], $tags, null, null, null,
                ['company_id' => $company, 'log' => true]
            );
            if (!$sent || $this->Emails->errors()) {
                $failed[] = (int) $member->id;
            }
        }
        if ($failed) {
            throw new CouncilSyncIssue('notification', 'email_failed', ['staff_ids' => $failed]);
        }
        $this->CouncilSync->rememberNotification($company, $field, $hash, $kind, null);
    }

    private function createTicket(int $company, array $settings, string $kind, string $hash, array $tags): void
    {
        Loader::loadModels($this, ['SupportManager.SupportManagerTickets', 'SupportManager.SupportManagerDepartments']);
        $department = $this->SupportManagerDepartments->get($settings['department_id']);
        if (!$department || (int) $department->company_id !== $company) {
            throw new CouncilSyncIssue('notification', 'department');
        }
        $email = $this->Emails->buildEmail(self::EMAIL_ACTION, $company, null, $tags);
        if (!$email) {
            throw new CouncilSyncIssue('notification', 'email_template');
        }
        $systemStaff = Configure::get('SupportManager.system_staff_id');
        if (!is_int($systemStaff) && !(is_string($systemStaff) && ctype_digit($systemStaff))) {
            throw new CouncilSyncIssue('notification', 'ticket_failed');
        }
        $this->CouncilSync->bindCoreConnection($this->SupportManagerTickets);
        $this->CouncilSync->atomic(function () use ($company, $settings, $kind, $hash, $email, $systemStaff) {
            $ticket = $this->SupportManagerTickets->add([
                'department_id' => $settings['department_id'], 'client_id' => null, 'email' => null,
                'summary' => Language::_('CouncilSyncPlugin.ticket_subject', true),
                'status' => 'open', 'request_feedback' => 0
            ], false);
            if (!$ticket || $this->SupportManagerTickets->errors()) {
                throw new CouncilSyncIssue('notification', 'ticket_failed');
            }
            $reply = $this->SupportManagerTickets->addReply($ticket, [
                'staff_id' => (int) $systemStaff, 'type' => 'note',
                'details' => $email->html ?: nl2br(htmlspecialchars($email->text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            ], null, true);
            if (!$reply || $this->SupportManagerTickets->errors()) {
                throw new CouncilSyncIssue('notification', 'ticket_failed');
            }
            $this->CouncilSync->rememberNotification($company, $settings['field_id'], $hash, $kind, (int) $ticket);
        });
    }
}
