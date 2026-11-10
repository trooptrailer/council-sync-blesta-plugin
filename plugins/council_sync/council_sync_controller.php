<?php

class CouncilSyncController extends AppController
{
    protected int $syncCompany;
    protected int $syncStaff;

    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();
        $this->portal = 'admin';
        $this->requireLogin();
        $this->syncCompany = (int) Configure::get('Blesta.company_id');
        $this->syncStaff = (int) $this->Session->read('blesta_staff_id');
        Language::loadLang(['admin_main', 'council_sync'], null, __DIR__ . DIRECTORY_SEPARATOR . 'language' . DIRECTORY_SEPARATOR);
        $this->view->setView(null, 'council_sync.default');
        $this->helpers(['Form', 'Html']);
    }

    protected function syncUri(string $action = 'index'): string
    {
        return $this->base_uri . 'plugin/council_sync/admin_main/' . $action . '/';
    }

    protected function requireSyncPost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new CouncilSyncIssue('request', 'post');
        }
        if (!$this->Form->verifyCsrfToken(null, $this->post['_csrf_token'] ?? null)) {
            throw new CouncilSyncIssue('request', 'csrf');
        }
    }

    protected function syncError(CouncilSyncIssue|PDOException|JsonException $error): void
    {
        $text = $error instanceof CouncilSyncIssue ? $error->description() : Language::_('CouncilSync.!error.database', true);
        $this->setMessage('error', $this->Html->safe($text));
        if (!($error instanceof CouncilSyncIssue)) {
            error_log('COUNCIL_SYNC_ADMIN_FAILED company_id=' . $this->syncCompany . ' reason=database');
        }
    }
}
