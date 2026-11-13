<?php

class CouncilSyncPlugin extends Plugin
{
    private const EMAIL_ACTION = 'CouncilSync.issue';
    private const VERSION_EMAIL_ACTION = 'CouncilSync.version';

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
            CouncilSyncSoftware::installControl($this->CouncilSync->Record);
            CouncilSyncSoftware::allowNativeUpgrade($this->CouncilSync->Record, $current_version, $this->getVersion());
            if (version_compare($current_version, $this->getVersion(), '>')) {
                return;
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
            CouncilSyncSoftware::assertIdle($this->CouncilSync->Record);
            $this->CouncilSync->locked($company, function () use ($company, $last_instance) {
                foreach (['sync', 'updates', 'software_cleanup'] as $key) {
                    $task = $this->CronTasks->getByKey($key, 'council_sync');
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
                }
                foreach ([self::EMAIL_ACTION, self::VERSION_EMAIL_ACTION] as $action) {
                    $group = $this->EmailGroups->getByAction($action);
                    if ($group) {
                        $this->Emails->deleteAll($group->id, $company);
                        if ($last_instance) {
                            $this->EmailGroups->delete($group->id);
                        }
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
                ['uri' => 'plugin/council_sync/admin_main/history/', 'name' => Language::_('CouncilSyncPlugin.history', true)],
                ['uri' => 'plugin/council_sync/admin_software/', 'name' => Language::_('CouncilSyncPlugin.software', true)]
            ]]
        ]];
    }

    public function getPermissionGroups()
    {
        return [
            ['name' => Language::_('CouncilSyncPlugin.name', true), 'level' => 'staff', 'alias' => 'council_sync'],
            ['name' => Language::_('CouncilSyncPlugin.software_permission', true), 'level' => 'staff', 'alias' => 'council_sync_software']
        ];
    }

    public function getPermissions()
    {
        return [[
            'group_alias' => 'council_sync', 'name' => Language::_('CouncilSyncPlugin.permission', true),
            'alias' => 'council_sync.admin_main', 'action' => '*'
        ], [
            'group_alias' => 'council_sync_software', 'name' => Language::_('CouncilSyncPlugin.software_permission', true),
            'alias' => 'council_sync.admin_software', 'action' => '*'
        ]];
    }

    public function getCronTaskDefinitions()
    {
        return [[
            'key' => 'sync', 'task_type' => 'plugin', 'dir' => 'council_sync',
            'name' => Language::_('CouncilSyncPlugin.cron_name', true),
            'description' => Language::_('CouncilSyncPlugin.cron_description', true),
            'type' => 'interval', 'type_value' => 1440, 'enabled' => 1
        ], [
            'key' => 'updates', 'task_type' => 'plugin', 'dir' => 'council_sync',
            'name' => Language::_('CouncilSyncPlugin.updates_cron_name', true),
            'description' => Language::_('CouncilSyncPlugin.updates_cron_description', true),
            'type' => 'interval', 'type_value' => 1440, 'enabled' => 1
        ], [
            'key' => 'software_cleanup', 'task_type' => 'plugin', 'dir' => 'council_sync',
            'name' => Language::_('CouncilSyncPlugin.cleanup_cron_name', true),
            'description' => Language::_('CouncilSyncPlugin.cleanup_cron_description', true),
            'type' => 'interval', 'type_value' => 5, 'enabled' => 1
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
        ], [
            'action' => self::VERSION_EMAIL_ACTION, 'type' => 'staff', 'plugin_dir' => 'council_sync',
            'tags' => 'issue_type,issue_hash,details,details_html', 'from' => 'noreply@mydomain.com',
            'from_name' => Language::_('CouncilSyncPlugin.name', true),
            'subject' => Language::_('CouncilSyncPlugin.email_subject', true),
            'text' => Language::_('CouncilSyncPlugin.version_email_text', true),
            'html' => Language::_('CouncilSyncPlugin.version_email_html', true)
        ]];
    }

    public function cron($key)
    {
        if ($key === 'software_cleanup') {
            Loader::loadModels($this, ['CouncilSync.CouncilSyncSoftware']);
            try {
                $this->CouncilSyncSoftware->cleanup();
            } catch (CouncilSyncIssue|PDOException $error) {
                error_log('COUNCIL_SYNC_SOFTWARE_CLEANUP_FAILED reason='
                    . ($error instanceof CouncilSyncIssue ? $error->reason : 'database'));
                throw $error;
            }
            return;
        }
        if (!in_array($key, ['sync', 'updates'], true)) {
            return;
        }
        Loader::loadModels($this, ['CouncilSync.CouncilSync']);
        $company = (int) Configure::get('Blesta.company_id');
        try {
            $this->CouncilSync->locked($company, function () use ($company, $key) {
                $settings = $this->CouncilSync->settings($company);
                if ($key === 'updates') {
                    if ($settings['updates_enabled']) {
                        $this->checkVersion($company, $settings);
                    }
                    return;
                }
                if (!$settings['field_id'] || $settings['contact'] === '') {
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

    private function checkVersion(int $company, array $settings): void
    {
        $status = $this->CouncilSync->checkForUpdates($company);
        if ($status['status'] === 'unavailable') {
            $details = Language::_('CouncilSyncPlugin.release_unavailable', true, $status['error_code'], CouncilSync::RELEASE_API);
            $this->notify($company, $settings, 'version_check', hash('sha256', 'version_check:' . $status['error_code']), $details);
        } elseif ($status['outdated']) {
            $details = Language::_(
                'CouncilSyncPlugin.version_available', true,
                $status['installed'], $status['package'], $status['latest'], $status['release_url']
            );
            $this->notify($company, $settings, 'version', hash('sha256', 'version:' . $status['latest']), $details);
        }
    }

    private function notify(int $company, array $settings, string $kind, string $hash, string $details): void
    {
        if (!$settings['email_enabled'] && !$settings['ticket_enabled']) {
            error_log('COUNCIL_SYNC_NOTICE_DISABLED company_id=' . $company . ' kind=' . $kind);
            return;
        }
        Loader::loadModels($this, ['Emails', 'Staff', 'StaffGroups']);
        $tags = [
            'issue_type' => Language::_('CouncilSyncPlugin.issue_' . $kind, true), 'issue_hash' => $hash,
            'details' => $details, 'details_html' => htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        ];
        $software = in_array($kind, ['version', 'version_check'], true);
        $action = $software ? self::VERSION_EMAIL_ACTION : self::EMAIL_ACTION;
        $field = $software ? 0 : $settings['field_id'];
        $existing = $this->CouncilSync->prepareNotice($company, $field, $hash, $kind);
        $ticketFailed = false;
        if ($settings['ticket_enabled'] && $this->CouncilSync->noticeDue($existing->ticket_notified_at)) {
            try {
                if (!$settings['department_id'] || !$this->CouncilSync->supportActive($company)) {
                    throw new CouncilSyncIssue('notification', 'department');
                }
                Loader::loadModels($this, ['SupportManager.SupportManagerTickets']);
                $ticket = $existing->ticket_id === null ? null : $this->SupportManagerTickets->get($existing->ticket_id, false);
                if (
                    $existing->ticket_id !== null
                    && (!$ticket || !isset($ticket->company_id, $ticket->status) || (int) $ticket->company_id !== $company
                        || !in_array($ticket->status, ['open', 'awaiting_reply', 'in_progress', 'on_hold', 'closed', 'trash'], true))
                ) {
                    throw new CouncilSyncIssue('notification', 'ticket_status');
                }
                if ($ticket && !in_array($ticket->status, ['closed', 'trash'], true)) {
                    $this->CouncilSync->recordTicketNotice($company, $field, $hash, (int) $ticket->id, false);
                } else {
                    $this->createTicket($company, $settings, $field, $hash, $tags, $action, $kind);
                }
            } catch (CouncilSyncIssue|PDOException $error) {
                $ticketFailed = true;
                error_log('COUNCIL_SYNC_TICKET_FAILED company_id=' . $company . ' field_id=' . $field
                    . ' reason=' . ($error instanceof CouncilSyncIssue ? $error->reason : 'database'));
                $tags['details'] .= "\n\n" . Language::_('CouncilSyncPlugin.ticket_fallback', true);
                $tags['details_html'] = htmlspecialchars($tags['details'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        if (!$settings['email_enabled']) {
            if ($ticketFailed) {
                throw new CouncilSyncIssue('notification', 'ticket_failed');
            }
            return;
        }
        if (!$this->CouncilSync->noticeDue($existing->email_notified_at)) {
            return;
        }
        if (!$settings['staff_group_id']) {
            throw new CouncilSyncIssue('notification', 'staff_group');
        }
        $group = $this->StaffGroups->get($settings['staff_group_id']);
        $members = $this->Staff->getAll($company, 'active', $settings['staff_group_id']);
        if (!$group || (int) $group->company_id !== $company || !$members) {
            throw new CouncilSyncIssue('notification', 'staff_group');
        }
        $template = $this->Emails->getByType($company, $action);
        if (!$template) {
            throw new CouncilSyncIssue('notification', 'email_template');
        }
        $failed = [];
        $deliveries = $this->CouncilSync->emailDeliveries($existing);
        foreach ($members as $member) {
            if (!$this->CouncilSync->noticeDue($deliveries[$member->id] ?? null)) {
                continue;
            }
            $sent = $this->Emails->sendCustom(
                $template->from, $template->from_name, $member->email, $template->subject,
                ['text' => $template->text, 'html' => $template->html], $tags, null, null, null,
                ['company_id' => $company, 'log' => true]
            );
            if (!$sent || $this->Emails->errors()) {
                $failed[] = (int) $member->id;
            } else {
                $this->CouncilSync->recordStaffEmail($company, $field, $hash, (int) $member->id);
            }
        }
        if ($failed) {
            throw new CouncilSyncIssue('notification', 'email_failed', ['staff_ids' => $failed]);
        }
        $this->CouncilSync->recordEmailNotice($company, $field, $hash);
    }

    private function createTicket(
        int $company, array $settings, int $field, string $hash, array $tags, string $action, string $kind
    ): void
    {
        Loader::loadModels($this, ['SupportManager.SupportManagerTickets', 'SupportManager.SupportManagerDepartments']);
        $department = $this->SupportManagerDepartments->get($settings['department_id']);
        if (!$department || (int) $department->company_id !== $company) {
            throw new CouncilSyncIssue('notification', 'department');
        }
        $email = $this->Emails->buildEmail($action, $company, null, $tags);
        if (!$email) {
            throw new CouncilSyncIssue('notification', 'email_template');
        }
        $systemStaff = Configure::get('SupportManager.system_staff_id');
        if (!is_int($systemStaff) && !(is_string($systemStaff) && ctype_digit($systemStaff))) {
            throw new CouncilSyncIssue('notification', 'ticket_failed');
        }
        $this->CouncilSync->bindCoreConnection($this->SupportManagerTickets);
        $this->CouncilSync->atomic(function () use ($company, $settings, $field, $kind, $hash, $email, $systemStaff) {
            $ticket = $this->SupportManagerTickets->add([
                'department_id' => $settings['department_id'], 'client_id' => null, 'email' => null,
                'summary' => Language::_('CouncilSyncPlugin.ticket_subject', true)
                    . ' - ' . Language::_('CouncilSyncPlugin.issue_' . $kind, true),
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
            $this->CouncilSync->recordTicketNotice($company, $field, $hash, (int) $ticket, true);
        });
    }
}
