<?php

$lang['CouncilSyncPlugin.name'] = 'Council Sync';
$lang['CouncilSyncPlugin.description'] = 'Synchronizes a council dropdown from Wikipedia with explicit conflict resolution and audited rollback.';
$lang['CouncilSyncPlugin.settings'] = 'Settings';
$lang['CouncilSyncPlugin.resolve'] = 'Resolve';
$lang['CouncilSyncPlugin.history'] = 'History';
$lang['CouncilSyncPlugin.permission'] = 'Manage Council Sync, resolve conflicts and roll back updates';
$lang['CouncilSyncPlugin.cron_name'] = 'Council Sync update';
$lang['CouncilSyncPlugin.cron_description'] = 'Fetch the council list, apply safe changes, and report blocked updates.';
$lang['CouncilSyncPlugin.email_subject'] = 'Council Sync: {issue_type}';
$lang['CouncilSyncPlugin.ticket_subject'] = 'Council Sync: update blocked';
$lang['CouncilSyncPlugin.email_text'] = "Council Sync made no changes because of {issue_type}.\n\n{details}\n\nReview and resolve:\nhttps://{admin_uri}plugin/council_sync/admin_main/resolve/\n\nIssue hash: {issue_hash}";
$lang['CouncilSyncPlugin.email_html'] = '<p>Council Sync made no changes because of {issue_type}.</p><pre>{details_html}</pre><p><a href="https://{admin_uri}plugin/council_sync/admin_main/resolve/">Review and resolve</a></p><p>Issue hash: {issue_hash}</p>';
$lang['CouncilSyncPlugin.conflict_line'] = 'Old value: %1$s | Label: %2$s | Affected clients: %3$s';
$lang['CouncilSyncPlugin.issue_conflicts'] = 'in-use removed values';
$lang['CouncilSyncPlugin.issue_fetch'] = 'a Wikipedia fetch or parse failure';
$lang['CouncilSyncPlugin.issue_sanity'] = 'a row-count sanity check failure';
$lang['CouncilSyncPlugin.issue_collision'] = 'duplicate option values';
$lang['CouncilSyncPlugin.ticket_fallback'] = 'Support Manager could not create a complete ticket. This email is the fallback notification.';
