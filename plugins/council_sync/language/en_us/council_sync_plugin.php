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
$lang['CouncilSyncPlugin.ticket_subject'] = 'Council Sync';
$lang['CouncilSyncPlugin.email_text'] = "Council Sync made no changes because of {issue_type}.\n\n{details}\n\nReview and resolve:\nhttps://{admin_uri}plugin/council_sync/admin_main/resolve/\n\nIssue hash: {issue_hash}";
$lang['CouncilSyncPlugin.email_html'] = '<p>Council Sync made no changes because of {issue_type}.</p><pre>{details_html}</pre><p><a href="https://{admin_uri}plugin/council_sync/admin_main/resolve/">Review and resolve</a></p><p>Issue hash: {issue_hash}</p>';
$lang['CouncilSyncPlugin.conflict_line'] = 'Old value: %1$s | Label: %2$s | Affected clients: %3$s';
$lang['CouncilSyncPlugin.issue_conflicts'] = 'in-use removed values';
$lang['CouncilSyncPlugin.issue_fetch'] = 'a Wikipedia fetch or parse failure';
$lang['CouncilSyncPlugin.issue_sanity'] = 'a row-count sanity check failure';
$lang['CouncilSyncPlugin.issue_collision'] = 'duplicate option values';
$lang['CouncilSyncPlugin.ticket_fallback'] = 'Support Manager could not create a complete ticket. This email is the fallback notification.';
$lang['CouncilSyncPlugin.updates_cron_name'] = 'Council Sync release check';
$lang['CouncilSyncPlugin.updates_cron_description'] = 'Check stable GitHub releases and report an outdated plugin or unavailable release metadata.';
$lang['CouncilSyncPlugin.issue_version'] = 'a newer plugin release';
$lang['CouncilSyncPlugin.issue_version_check'] = 'an unavailable plugin release check';
$lang['CouncilSyncPlugin.version_email_text'] = "Council Sync software notice: {issue_type}.\n\n{details}\n\nPlugin settings:\nhttps://{admin_uri}plugin/council_sync/admin_main/\n\nIssue hash: {issue_hash}";
$lang['CouncilSyncPlugin.version_email_html'] = '<p>Council Sync software notice: {issue_type}.</p><pre>{details_html}</pre><p><a href="https://{admin_uri}plugin/council_sync/admin_main/">Plugin settings</a></p><p>Issue hash: {issue_hash}</p>';
$lang['CouncilSyncPlugin.version_available'] = "A newer stable Council Sync release is available.\nBlesta registered version: %1\$s\nPackage version: %2\$s\nLatest stable version: %3\$s\nRelease: %4\$s\n\nInstall the release manually, complete the Blesta upgrade action, and reload PHP. The plugin never installs updates automatically.";
$lang['CouncilSyncPlugin.release_unavailable'] = "The latest Council Sync release could not be verified (%1\$s).\nSource: %2\$s\n\nThis unauthenticated checker cannot read private GitHub releases. HTTP 404 can also mean that no release exists. No up-to-date status is assumed, no automatic retry is made, and council synchronization is unaffected.";
