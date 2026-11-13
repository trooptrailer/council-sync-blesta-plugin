<?php

class AdminSoftware extends CouncilSyncController
{
    public function preAction()
    {
        parent::preAction();
        $this->uses(['CouncilSync.CouncilSyncSoftware', 'CouncilSync.CouncilSync']);
        Language::loadLang('admin_software', null, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR);
        if (!$this->CouncilSyncSoftware->authorized($this->syncStaff, $this->syncCompany)) {
            throw new CouncilSyncIssue('software', 'software_permission');
        }
        $this->set('software_uri', $this->base_uri . 'plugin/council_sync/admin_software/');
        $this->set('sync_uri', $this->syncUri());
        $this->set('software_allowed', true);
    }

    public function index()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                $this->requireSyncPost();
                $this->CouncilSyncSoftware->saveConfiguration($this->syncStaff, $this->syncCompany, $this->post);
                $this->setMessage('message', Language::_('AdminSoftware.saved', true));
            } catch (CouncilSyncIssue|PDOException|JsonException $error) {
                $this->syncError($error);
            }
        }
        try {
            $configuration = $this->CouncilSyncSoftware->configuration();
            $software_trial = $this->CouncilSyncSoftware->banner();
            $version_status = $this->CouncilSync->versionStatus($this->syncCompany);
            $install_supported = DIRECTORY_SEPARATOR !== '\\' && class_exists('ZipArchive');
            $this->set(compact('configuration', 'software_trial', 'version_status', 'install_supported'));
            $this->view->setView('admin_software', 'council_sync.default');
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
            $this->redirect($this->syncUri());
        }
    }

    public function checkupdates()
    {
        try {
            $this->requireSyncPost();
            $this->CouncilSync->checkForUpdates($this->syncCompany, true);
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
        }
        $this->redirect($this->base_uri . 'plugin/council_sync/admin_software/');
    }

    public function install()
    {
        try {
            $this->requireSyncPost();
            $this->confirm('confirm_install');
            if (!is_string($this->post['version'] ?? null)) {
                throw new CouncilSyncIssue('software', 'software_version');
            }
            $this->CouncilSyncSoftware->install($this->syncStaff, $this->syncCompany, $this->post['version']);
            $this->redirect($this->base_uri . 'plugin/council_sync/admin_software/finish/');
            return;
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
        }
        $this->redirect($this->base_uri . 'plugin/council_sync/admin_software/');
    }

    public function finish()
    {
        try {
            $software_trial = $this->CouncilSyncSoftware->banner();
            if (!$software_trial || !$software_trial['can_finish']) {
                throw new CouncilSyncIssue('software', 'software_state');
            }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                $this->requireSyncPost();
                $this->CouncilSyncSoftware->finish($this->syncStaff, $this->syncCompany, $this->id());
                $this->redirect($this->syncUri());
                return;
            }
            $this->set('software_trial', $software_trial);
            $this->view->setView('admin_software_finish', 'council_sync.default');
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
            $this->redirect($this->base_uri . 'plugin/council_sync/admin_software/');
        }
    }

    public function rollback()
    {
        try {
            $this->requireSyncPost();
            $this->confirm('confirm_rollback');
            $mode = $this->post['mode'] ?? null;
            if (!is_string($mode) || ($mode === 'restore' && ($this->post['confirm_data_loss'] ?? null) !== '1')) {
                throw new CouncilSyncIssue('software', 'software_choice');
            }
            $this->CouncilSyncSoftware->rollback($this->syncStaff, $this->syncCompany, $this->id(), $mode);
            $this->redirect($this->base_uri . 'plugin/council_sync/admin_software/finish/');
            return;
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
        }
        $this->redirect($this->base_uri . 'plugin/council_sync/admin_software/');
    }

    public function commit()
    {
        try {
            $this->requireSyncPost();
            $this->confirm('confirm_commit');
            $this->CouncilSyncSoftware->commit($this->syncStaff, $this->syncCompany, $this->id());
            $this->redirect($this->syncUri());
            return;
        } catch (CouncilSyncIssue|PDOException|JsonException $error) {
            $this->syncError($error);
        }
        $this->redirect($this->base_uri . 'plugin/council_sync/admin_software/');
    }

    private function confirm(string $key): void
    {
        if (($this->post[$key] ?? null) !== '1') {
            throw new CouncilSyncIssue('software', 'software_choice');
        }
    }

    private function id(): string
    {
        if (!is_string($this->post['id'] ?? null) || !preg_match('~^[a-f0-9]{32}$~D', $this->post['id'])) {
            throw new CouncilSyncIssue('software', 'software_state');
        }
        return $this->post['id'];
    }
}
