<?php

class AdminMain extends CouncilSyncController
{
    public function preAction()
    {
        parent::preAction();
        $this->uses(['CouncilSync.CouncilSync']);
        $this->structure->set('page_title', Language::_('AdminMain.title', true));
        $this->set('sync_uri', $this->base_uri . 'plugin/council_sync/admin_main/');
    }

    public function index()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                $this->requireSyncPost();
                $this->CouncilSync->saveSettings($this->syncCompany, $this->post);
                $this->Session->clear($this->previewKey());
                $this->setMessage('message', Language::_('AdminMain.saved', true));
            } catch (CouncilSyncIssue|PDOException|JsonException $error) {
                $this->syncError($error);
            }
        }
        try {
            $settings = $this->CouncilSync->settings($this->syncCompany);
            $fields = $this->CouncilSync->dropdownFields($this->syncCompany);
            $groups = $this->CouncilSync->StaffGroups->getAll($this->syncCompany);
            $support_active = $this->CouncilSync->supportActive($this->syncCompany);
            $departments = $this->CouncilSync->departments($this->syncCompany);
            $run = $this->CouncilSync->CronTasks->getTaskRunByKey('sync', 'council_sync');
            if (!$run || (int) $run->company_id !== $this->syncCompany) {
                throw new CouncilSyncIssue('settings', 'cron_missing');
            }
            $settings['frequency'] = (int) $run->interval;
            foreach (['field_id', 'staff_group_id', 'department_id', 'contact', 'max_drop', 'frequency', 'purge'] as $key) {
                if (array_key_exists($key, $this->post) && is_scalar($this->post[$key])) {
                    $settings[$key] = $this->post[$key];
                }
            }
            $this->set(compact('settings', 'fields', 'groups', 'support_active', 'departments'));
            $this->view->setView('admin_main', 'council_sync.default');
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
            $this->redirect($this->base_uri);
        }
    }

    public function update()
    {
        try {
            $this->requireSyncPost();
            $this->Session->clear($this->previewKey());
            $result = $this->CouncilSync->synchronize($this->syncCompany, 'manual', $this->syncStaff, true);
            if ($result['preview']['conflicts']) {
                $this->stage($result['preview']);
                $this->redirect($this->syncUri('resolve'));
                return;
            }
            $applied = $this->CouncilSync->apply($this->syncCompany, $result['preview'], [], 'manual', $this->syncStaff);
            $this->success($applied);
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
        }
        $this->redirect($this->syncUri());
    }

    public function resolve()
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $this->requireSyncPost();
                $pending = $this->Session->read($this->previewKey());
                if (
                    !is_array($pending) || !is_string($this->post['nonce'] ?? null)
                    || !hash_equals($pending['nonce'], $this->post['nonce'])
                ) {
                    throw new CouncilSyncIssue('storage', 'stale');
                }
                if (($this->post['refresh'] ?? null) === '1') {
                    $this->Session->clear($this->previewKey());
                    $this->redirect($this->syncUri('resolve'));
                    return;
                }
                if (($this->post['confirm_apply'] ?? null) !== '1') {
                    throw new CouncilSyncIssue('resolution', 'choice');
                }
                $choices = self::indexedChoices($pending['preview']['conflicts'], $this->post['choices'] ?? []);
                $result = $this->CouncilSync->apply($this->syncCompany, $pending['preview'], $choices, 'manual', $this->syncStaff);
                $this->Session->clear($this->previewKey());
                $this->success($result);
                $this->redirect($this->syncUri('history') . ($result['batch_id'] ?? ''));
                return;
            }
            $this->showResolution();
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
            if ($error instanceof CouncilSyncIssue && $error->kind === 'resolution') {
                $this->set('posted_choices', is_array($this->post['choices'] ?? null) ? $this->post['choices'] : []);
                $this->showResolution();
                return;
            }
            if ($error instanceof CouncilSyncIssue && $error->reason === 'stale') {
                $this->Session->clear($this->previewKey());
            }
            $this->redirect($this->syncUri());
        }
    }

    protected static function indexedChoices(array $conflicts, mixed $input): array
    {
        if (!is_array($input) || count($input) !== count($conflicts)) {
            throw new CouncilSyncIssue('resolution', 'choice');
        }
        $choices = [];
        foreach (array_keys($conflicts) as $index => $old) {
            if (!array_key_exists($index, $input) || !is_array($input[$index])) {
                throw new CouncilSyncIssue('resolution', 'choice');
            }
            if (($input[$index]['mode'] ?? null) === 'clients') {
                $overrides = $input[$index]['clients'] ?? null;
                if (
                    !is_array($overrides)
                    || array_diff(array_column($conflicts[$old]['clients'], 'id'), array_keys($overrides))
                ) {
                    throw new CouncilSyncIssue('resolution', 'form_incomplete');
                }
            }
            $choices[$old] = $input[$index];
        }
        return $choices;
    }

    private function showResolution(): void
    {
        $pending = $this->Session->read($this->previewKey());
        if (!is_array($pending) || time() - $pending['preview']['fetched_at'] > 3600) {
            $this->stage($this->CouncilSync->preview($this->syncCompany));
            $pending = $this->Session->read($this->previewKey());
        }
        $preview = $pending['preview'];
        $nonce = $pending['nonce'];
        $client_names = $this->CouncilSync->conflictClients($this->syncCompany, $preview);
        $this->set(compact('preview', 'nonce', 'client_names'));
        $this->view->setView('admin_resolve', 'council_sync.default');
    }

    private function previewKey(): string
    {
        return 'council_sync_preview_' . $this->syncCompany . '_' . $this->syncStaff;
    }

    private function stage(array $preview): void
    {
        $this->Session->write($this->previewKey(), ['nonce' => bin2hex(random_bytes(32)), 'preview' => $preview]);
    }

    private function success(array $result): void
    {
        $summary = $result['summary'];
        $this->setMessage('message', Language::_(
            'AdminMain.updated', true, $summary['added'], $summary['removed'], $summary['labels'],
            $summary['remapped'], $summary['accepted_orphans']
        ));
    }

    public function history()
    {
        try {
            $page = isset($this->get['page']) ? self::id($this->get['page']) : 1;
            $batches = $this->CouncilSync->history($this->syncCompany, $page);
            $latest = $this->CouncilSync->latestBatch($this->syncCompany);
            $selected = isset($this->get[0]) ? $this->CouncilSync->batch($this->syncCompany, self::id($this->get[0])) : null;
            $items = $selected ? $this->CouncilSync->batchItems($this->syncCompany, (int) $selected->id) : [];
            $this->set(compact('page', 'batches', 'latest', 'selected', 'items'));
            $this->view->setView('admin_history', 'council_sync.default');
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
            $this->redirect($this->syncUri());
        }
    }

    public function rollback()
    {
        try {
            $this->requireSyncPost();
            if (($this->post['confirm_rollback'] ?? null) !== '1') {
                throw new CouncilSyncIssue('rollback', 'rollback_confirmation');
            }
            $audit = $this->CouncilSync->rollback($this->syncCompany, self::id($this->post['batch_id'] ?? null), $this->syncStaff);
            $this->Session->clear($this->previewKey());
            $this->setMessage('message', Language::_('AdminMain.rolled_back', true, $audit));
            $this->redirect($this->syncUri('history') . $audit . '/');
            return;
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
        }
        $this->redirect($this->syncUri('history'));
    }

    private static function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            throw new CouncilSyncIssue('storage', 'batch');
        }
        return $id;
    }
}
