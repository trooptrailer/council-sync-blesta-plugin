# Council Sync for Blesta

Council Sync keeps an existing Blesta client dropdown synchronized with the
[list of Scouting America councils on Wikipedia](https://en.wikipedia.org/wiki/List_of_councils_(Scouting_America)).
It detects changes that would leave existing client values orphaned by the dropdown and provides
explicit remapping, notifications and an audit history.

**Experimental:** Use of this plugin comes at your own risk!

**Current package version: 1.2.3.** See the [changelog](./CHANGELOG.md) for
backfilled version history. Development packages are not automatically published
GitHub Releases.

## Features

- Fetches the council table through the MediaWiki Action API (unauthenticated).
- Ingests every row, including overseas councils, regions and national entries.
- Generates sorted labels and unique values derived from council names, locations and numbers.
- Validates imported content as bounded plain text and rejects unsafe runs without partial changes.
- Reads and remaps encrypted client values through Blesta's models.
- Supports whole-council and per-client remapping, with transactional history and rollback.
- Provides independently configurable staff email, Support Manager tickets and release checks.
- Offers explicit software installation with private backups and a configurable, 48-hour rollback trial.
- Uses Blesta's native staff widgets, navigation tabs and form styling, without bundling a separate theme.
- Checks PRs for secrets, tests and release readiness; publishes installable ZIPs from approved main plugin changes.

## Requirements

- Blesta 6.0.4 and PHP 8.3 are the development targets; other versions are unverified.
- PHP cURL, a transactional MySQL/InnoDB database, and configured Blesta cron.
- Outbound HTTPS access to Wikipedia and, if release checks are enabled, GitHub.
- Working Blesta email delivery for email notifications.
- Support Manager installed and enabled for ticket notifications; otherwise it is optional.
- The software installer additionally requires a POSIX host, PHP ZipArchive, writable
  plugin files and parent directory, private storage on the same filesystem, and
  database privileges to create, rename and drop plugin tables. If opcode caching
  is enabled, PHP must permit cache invalidation. Manual deployment remains available
  on unsupported hosts.

## Installation

1. Download the versioned `council-sync-X.Y.Z.zip` asset from
   [GitHub Releases](https://github.com/trooptrailer/council-sync-blesta-plugin/releases),
   not GitHub's automatically generated source-code ZIP. Extract it into Blesta's
   `plugins` directory so the result is `plugins/council_sync/config.json`.
   The ZIP has one `council_sync/` folder, including README, LICENSE and CHANGELOG;
   it contains no tests or CI files. From a source checkout, alternatively copy
   [plugins/council_sync](./plugins/council_sync) there.
2. Install **Council Sync** under **Settings > Company > Plugins**.
3. Grant **Manage Council Sync** to the appropriate staff group under
   **Settings > System > Staff > Staff Groups**.
   Grant **Manage Council Sync software for ALL companies** separately, only to
   trusted operators allowed to deploy PHP code for the entire installation.
4. Open **Council Sync** in the staff navigation. Select an existing dropdown client
   field, configure notification destinations, enter a Wikimedia contact and save.
   Create a dropdown in Blesta's client group settings first if none is available.
5. Review the two Council Sync staff email templates, replace their placeholder
   sender with an address allowed by the configured mail provider, and verify delivery.
6. Run **Update now** and review the result before relying on scheduled updates.

Installation registers daily council-sync and release-check tasks, a five-minute
update-backup cleanup task, and staff email templates. Blesta's system cron must
be running. Cleanup remains enabled independently of release checks.

Blesta normally grants the installing staff group both plugin permissions.
Review those grants if that group should not deploy software. The generic plugin
**Settings** button shows framework Automation/Actions/Events registrations, not
the Council Sync configuration form. An empty Events tab is expected: this plugin
does not declare event listeners.

## Settings

Council and notification settings are scoped to the current Blesta company.
Software settings and update trials are installation-wide.

| Setting | Behavior |
|---|---|
| Target client dropdown | An existing dropdown in this company. Its client group and encryption flag are preserved. |
| Email notifications | Enabled by default; sends to active members of the selected staff group. |
| Ticket notifications | Independently enabled by default; opens internal Support Manager tickets. |
| Staff group | Required when email is enabled. |
| Support department | Available when Support Manager is active. Ticket-only delivery requires an active department. |
| Wikimedia contact | Required public email address or HTTP/HTTPS contact URL for the User-Agent. Do not include credentials. |
| Maximum row-count drop | Default 10%, configurable from 0 to 99%. Empty lists are always rejected. |
| Council-sync interval | Positive whole minutes; default 1440. Also configurable through Blesta Automation. |
| Daily release checks | Enabled by default, independently of council synchronization. |
| Delete data on final uninstall | Disabled by default; requires explicit confirmation. See [Uninstalling](#uninstalling). |

Wikimedia requests identify the plugin as
`CouncilSync-Blesta/<plugin version> (<contact>) PHP/<PHP version>`. They verify
TLS, refuse redirects, use a 10-second connection timeout and 30-second request
timeout, and cap the response at 4 MiB. HTTP 403/429 and other fetch failures abort
without applying changes; there are no immediate automatic fetch retries.

## Council updates and conflicts

Labels use the council name, headquarters state or country, and number, such as
`Longhorn Council, TX (662)`. Values are lowercase hyphenated slugs, such as
`longhorn-council-tx-662`; apostrophes are removed before non-alphanumeric runs
collapse into hyphens. US locations use USPS abbreviations, including PR.
Overseas locations retain the country, for example `Far East Council, Japan (803)`
and `far-east-council-japan-803`. Locations are omitted for rows marked `n/a`,
for example `Northeast Region (901)` and `northeast-region-901`.
Options sort case-insensitively by council name, then numerically by council number.

Added options, label-only edits and unused removals apply automatically. If a
removed or changed value is still assigned to any client, the entire scheduled
update is blocked. A council number change also changes its value.

Fetch failures, unsupported table content, duplicate generated values and excessive
row-count drops abort without changing dropdown options or client assignments.
The row-count baseline is the last successfully accepted list.

**Update now** applies changes immediately when there are no conflicts. Otherwise,
it opens a preview that requires an explicit decision for every conflicting value:

- Keep the old client values and explicitly accept that they will be outside the dropdown.
- Remap all affected clients to one option from the newly fetched list.
- Select targets per client, with a remap-all default and individual overrides.

Targets must exist in the new list. Previews expire after one hour or become invalid
when the field, clients or settings change. Large per-client forms may require a
higher PHP `max_input_vars` limit.

**Manual actions never send notifications or open tickets.**

## Untrusted-source validation

Wikipedia is not a trusted HTML or code source. Only the four required columns
are imported. Supported Wiki links/templates, references and line breaks are
reduced to text; entities are decoded before final validation. Remaining markup,
encoded-markup prefixes, malformed UTF-8, control/format characters and unsupported
punctuation are rejected rather than silently removed from council names.

Names and cities allow Unicode letters/marks/numbers, spaces and limited
punctuation: quotes, apostrophes, ampersands, commas, periods, parentheses, slashes
and dashes. States/countries must also match the supported location list.
The fixed safety ceilings are:

| Input | Limit |
|---|---|
| Wikitext | 4 MiB and 20,000 newline characters |
| Required source cell, before normalization | 8,192 bytes |
| Council name / headquarters city | 200 Unicode characters each |
| Headquarters state/country | 64 Unicode characters |
| Council number | 1-4 ASCII digits, numeric value 1-9999; leading zeros normalize as before |
| Council rows | 1,000 |
| Imported option label / slug | 250 Unicode characters / 240 ASCII bytes |
| Serialized Blesta label-to-value option list | 65,535 PHP-serialized bytes, matching its TEXT column ceiling |

Any invalid required value aborts the whole run: no skipped councils, option
changes, remaps, audit batches or row-count-baseline updates. Scheduled failures
use the existing notification policy. Safety errors report fixed field/reason/row
metadata, not rejected payloads. Cached import previews are validated again before
applying, so an older staged preview cannot bypass the checks.

Stored values remain plain text, not pre-escaped HTML. Context-specific output
escaping and bound SQL/Blesta-model writes remain mandatory; legitimate apostrophes
and SQL words are data, not commands. Existing field/history records are not
rewritten by this validation. It also cannot identify plausible but false council
information; existing conflict and row-count checks still apply.

## Notifications

Scheduled notices cover council conflicts, fetch/parse failures, row-count failures,
slug collisions, available software releases and failed release checks.

- Each enabled channel sends an initial notification. Enabling both means email
  **and** a ticket, not one or the other.
- Email reminders are weekly while the issue persists.
- Open tickets are not duplicated. On the weekly reminder cadence, a closed or
  trashed ticket can be replaced. Tickets are not automatically closed when an issue resolves.
- Failed delivery can retry on the next scheduled run. Successful staff emails
  are not repeated within the week, and successful tickets are not duplicated.
- Ticket creation failures use fallback email only when email is enabled.
  Disabling both channels leaves logging and manual review available.
- Successful runs without an issue do not notify.

Tickets contain a system-authored internal note with no client attached.
**Client-less tickets remain unverified in Blesta**, including departments with
**Clients Only** enabled, staff visibility and subsequent replies. Validate the
intended department configuration before relying on ticket delivery.

## History and rollback

Every applied change records the previous and new option lists, source, staff ID
for manual actions, and affected client actions. Copies of old and new client
values in the audit history use Blesta's system encryption.

A successful run with no option or client changes creates no audit batch.

Only the company's latest applied update can be rolled back. Rollback is blocked
if the field or affected clients changed afterward, or if restoring the old options
would orphan another assignment. Applying changes and rolling them back use
transactions; the rollback itself creates an audit entry.

This restores council data, not an earlier version of the plugin software.

## Plugin updates

The package version is declared in [config.json](./plugins/council_sync/config.json)
and follows semantic versioning. The release checker compares the version registered
in Blesta and the package on disk with the latest stable
[GitHub Release](https://github.com/trooptrailer/council-sync-blesta-plugin/releases).
It accepts release tags in `X.Y.Z` or `vX.Y.Z` format; a tag alone is not a release.

Checks default to daily. **Check for updates now** performs a manual check without
notifications. The management page shows cached versions and check timestamps.
Checks never install code; installation requires a separate, explicitly confirmed
**Install plugin update** action.

The checker uses GitHub's unauthenticated API and requires publicly accessible
release metadata. Private repositories, missing releases and request failures
produce an **unavailable** status, not an assumption that the plugin is current.
Previously verified metadata is retained with its timestamp after a failed check.
Scheduled release checks can be disabled independently.

Until a compatible stable GitHub Release is published, an **unavailable** check
is expected; it does not mean the installed package is confirmed current.
A populated branch or tag alone does not enable software installation.

### CI, release readiness and repository settings

The [CI workflow](./.github/workflows/ci.yml) runs on PRs, main pushes and merge-queue
checks. Its required-check-ready jobs are **Secret scan**, **Tests** and
**Release readiness**. Main-bound packages are also built and validated before merge.

- Changes under `plugins/council_sync/` require an unpublished stable `X.Y.Z`
  manifest version, current README version and matching changelog entry.
  Approve version changes manually; nothing increments the number automatically.
- Docs/tests/CI-only changes do not require another version or publish a release.
- Plugin-changing main pushes publish `council-sync-X.Y.Z.zip` at tag `vX.Y.Z`
  only after all checks pass. Tag creation refuses an existing ref, then the ZIP
  uploads to a draft before publication. Existing versions are never overwritten;
  rerunning an already-published commit is a no-op. Failed drafts/tags require
  operator review rather than automatic deletion.
- Beta/build identifiers are not published or allowed as main release versions.
  Only the canonical repository's main-push job receives publication permission.

The [release helper](./.github/scripts/release.py) builds one installable folder
and adjusts bundled documentation links. PHP source/PDT/config files and the
three root documents are allowed; unexpected private, test or unsupported asset
files are rejected, not silently copied.

Configure these GitHub settings as a repository administrator; workflow files
alone do not enforce branch protection:

1. Under **Settings > Advanced Security**, enable **Secret Protection** and
   **Push protection**. See GitHub's
   [enablement instructions](https://docs.github.com/en/code-security/how-tos/secure-your-secrets/prevent-future-leaks/enable-push-protection).
2. In the existing main branch protection/ruleset, require **Secret scan**,
   **Tests** and **Release readiness**. Require branches to be up to date before
   merging, or use the supported merge queue, so stale checks cannot merge two
   plugin PRs using the same version. Check names become selectable after a run.
3. Review changes to workflow and security configuration before approving them.

The [pre-commit configuration](./.pre-commit-config.yaml) scans locally only after
`pre-commit install`; it does not create GitHub checks. Both it and CI use
[Gitleaks rules](./.gitleaks.toml). CI uses a checksum-pinned free CLI with full
redaction, not the organization-licensed Gitleaks Action; no `GITLEAKS_LICENSE`
or application credentials are needed. PR checks happen after a push, whereas
GitHub push protection and local hooks can prevent supported secrets reaching
the remote. If a real secret was pushed, rotate it even when CI blocks the merge.

### First installation of the updater or manual upgrades

Version 1.2.0 introduced the installer, its separate staff permission and cleanup
task. Bootstrap an older installation with **1.2.3 or newer** manually first.

Take an operator-managed backup, deploy the complete replacement plugin directory, then use
Blesta's **Upgrade** action before running the plugin. Avoid mixing old and new
files; reload PHP or its opcode cache as required by the hosting environment.
Customized email templates and existing automation schedules are preserved.

### Repairing a partial installation or blank feedback

Version 1.2.0 could return HTTP 500 after Blesta had inserted its installed-plugin
row: plugin model method names collided with inherited database transaction
methods. Version 1.2.1 fixes those declarations; 1.2.2 also fixes native message
view resolution, feedback across redirects and the option-list byte calculation.

Deploy the complete current plugin directory and use Blesta's normal
**Upgrade** action on the existing installed entry. Do not remove plugin tables
to repair the registry row. If navigation or access is missing, grant **Manage
Council Sync** to the intended staff group. Open its staff-navigation page and
use **Update now** for a council import; **Install plugin update** is a separate
software operation. Blesta's **Tools > Logs > System** shows native exceptions;
redact credentials and account details before sharing logs.

### Installing a published release

The installer and rollback trials are implemented and fixture-tested, but actual
licensed Blesta/PHP-FPM installation and recovery trials remain unverified.
Review the [verification status](#verification-status) before relying on them.

1. Open **Software updates** with the separate software permission.
2. Provide an existing, empty, dedicated private directory outside **every**
   web/document root, on the same filesystem as the plugin. Confirm that no other
   domain serves it. PHP must own or be able to restrict it to private access.
3. Set the default rollback window in whole hours, at least one; the default is
   **48 hours**. Longer windows increase snapshot-restoration data loss.
4. Check for a newer stable release, review the version, and confirm installation.
   The plugin backs up its files and scoped database state, downloads and validates
   the release, replaces the plugin directory, then completes Blesta's native upgrade
   in a fresh request. Use **Finish software changes** if automatic continuation
   is unavailable.

The installer selects exactly `council-sync-X.Y.Z.zip` from the stable release's
uploaded assets, verifies GitHub's published SHA-256 and size, then checks the
archive's manifest version and updater protocol. It accepts a direct API download
or one HTTPS redirect to `release-assets.githubusercontent.com`; unknown hosts,
credentials in redirect URLs and further redirects are refused.

Only `council_sync/` is installed, including its three documents. The installer
does not use a moving branch or the generated source-code archive. There is no
GitHub token or arbitrary download-URL setting. Missing/digest-less assets,
incomplete or unsafe archives, unsupported schemas and failed checks stop the
operation while retaining incomplete recovery state.

### The trial banner

The management screen displays a countdown only after installation and the native
upgrade succeed. The deadline is fixed for that trial; its settings cannot change
while an operation is pending. The separate software permission is required for:

- **Roll back, preserving data:** restores the previous software while retaining
  current plugin data. Available only before expiry with an unchanged plugin data schema.
- **Roll back, restoring the snapshot:** restores previous software, plugin tables
  and scoped registration metadata. Explicit data-loss confirmation is required:
  newer Council Sync settings, audit history, notification state and registration
  changes across all companies are discarded.
- **Keep version and purge backup:** keeps the installed code and current data,
  permanently removes this recovery backup, and ends the trial.

Successful rollback or keep/purge removes the banner and its controls. At expiry,
rollback is refused immediately; cleanup keeps the installed version and purges the
completed trial backup. Physical deletion waits for the cleanup cron if it has not
run yet. Failed or incomplete installations do **not** expire automatically.

### Development-only bootstrap trials

Normal packages check stable Releases only. A deliberately generated test bootstrap
can set `software_update.development_branch` to exactly `dev`, with
`development_protocol: 1`, and use a beta identifier. This flag is not a settings
option and is absent from the normal manifest. Release packaging rejects it.

The approved development bootstrap is **1.2.3-beta.1**, for testing an update to
normal **1.2.3** without publishing a release. Before such a trial:

- Take a fresh operator-managed site backup; retain plugin data when manually
  reinstalling the bootstrap and configure dedicated private update storage.
- Push the current support code to dev first. The target must declare
  `development_protocol: 1` so that its installed code can restore beta backups.
- Run a manual check, review the pinned commit and explicitly confirm development
  installation. A changed commit requires a fresh check; it is never silently substituted.

The dev downloader uses that commit's HTTPS source archive, verifies its pinned
manifest and extracts only allowlisted plugin files plus the three documents.
It does not have a published Release-asset digest and does not certify the
production asset-publication path. Scheduled dev checks/notices are skipped.
Installing the normal target removes the test flag; rollback can restore the beta
bootstrap and flag. Dev cache entries are never displayed as stable releases.
Keep beta packages/flags out of main and published releases.

### Backup boundary and recovery limits

Snapshots contain the five runtime tables `council_sync_settings`,
`council_sync_batches`, `council_sync_items`, `council_sync_issues` and
`council_sync_updates`, plus only Council Sync's Blesta plugin/permission descriptors,
navigation, cron and email registrations. The separate `council_sync_software`
control table is retained for recovery, not restored from the snapshot.
Staff ACL grants are not rewound.

**Neither rollback mode restores client assignments, client dropdown options,
invoices, payments or support tickets.** Software installation does not synchronize
councils. Council-data rollback remains a separate, guarded history action.

The recovery journal uses Blesta system encryption. Database exports and code
backups are not wholly encrypted, so private filesystem access is essential;
directories use 0700 and backup files 0600. Keep Blesta's encryption key available.
Snapshot creation rejects generated columns, triggers and CHECK constraints in the
captured tables. Archive and snapshot size limits also apply.

Only one software operation can run installation-wide. Do not manually deploy other
files or install/uninstall company instances during a pending trial. Incomplete
operations pause Council Sync mutations and retain recovery state. Use the banner's
finish or snapshot-restore action when available; purge retries can finish without
recreating already deleted files. If the interface cannot recover, retain the
private directory and database state for operator-assisted recovery rather than
clearing them or restoring the whole Blesta database.

Filesystem replacement, MySQL DDL and the native Blesta upgrade are not one atomic
transaction. This scoped recovery feature is not a substitute for verified site backups.

## Uninstalling

Uninstalling does not remove the client field or its values. Plugin settings,
history and notification records are retained by default.
Finish any active software trial before uninstalling.

To authorize permanent removal of all plugin tables on the final uninstall, enable
the deletion setting and enter `DELETE COUNCIL SYNC DATA`. This deletes plugin data
for **all companies**, not just the current company.

## Development and testing

With PHP CLI 8.3, cURL, PDO SQLite, ZipArchive and `proc_open` enabled, plus Python
3.9 or newer for release tooling, run from the source repository root:

```text
cd tests
php check.php
cd ..
python -m unittest discover -s tests -p test_release.py
```

The suite checks PHP/PDT syntax and exercises parsing, conflict resolution,
transactions, notifications, release checks, update trials, scoped restore,
hostile-input validation, archive validation and forms with synthetic data,
mocked HTTP/Blesta models and a loopback asset-transport server. Python checks
release gates, workflow outputs, draft publication order and exact ZIP structure.
No running Blesta installation or live external service is required.

For optional MySQL-backed updater fixtures, set `COUNCIL_SOFTWARE_TEST_DSN` to
`mysql:host=127.0.0.1;port=PORT`, with `COUNCIL_SOFTWARE_TEST_USER` and
`COUNCIL_SOFTWARE_TEST_PASSWORD`, and enable PDO MySQL. Use only a disposable server:
the fixtures create and drop random test databases. Other tests still use SQLite.
Windows fixtures bypass the production installer's POSIX guard; file-permission
behavior must be tested on a POSIX filesystem.

### Test data safety

The bundled tests use synthetic records and reserved example email domains, not
live client data or service credentials. MySQL fixtures accept only an explicitly
configured disposable loopback server and create random test databases there.
Use dedicated test credentials; never supply production credentials or customer
data. Customized fixtures and third-party diagnostic messages should still be
treated as potentially private rather than assuming every possible log is redacted.

On 2026-11-13, all 31 publishable files, reachable Git history and captured output
from the normal suite plus invalid-DSN/connection-failure probes passed redacted
secret scans. Synthetic credential canaries did not appear in those outputs,
even with PHP exception arguments enabled. Tests ran with networking disabled,
read-only source and ephemeral scratch storage. This checks those paths, not
arbitrary future fixtures or every database-driver error.

### Verification status

The 1.2.3 validation on 2026-11-14 passed **108 PHP tests and 23 syntax checks**
on Linux/PHP 8.3.36/SQLite with read-only source mounts and external networking
disabled. **13 Python release tests** passed on Windows/Python 3.14, and actionlint
1.7.12 accepted the workflow. The real generated ZIP contained exactly 19 runtime
files plus three documents; the production extractor accepted it as version 1.2.3.
Asset metadata, SHA-256/size mismatches, redirect restrictions, archive shape and
actual loopback HTTP header/body handling are covered.

GitHub-hosted workflow execution, real publication/download and licensed 1.2.3
software-update/rollback trials remain unrun. The local suite does not certify them.

The development-bootstrap follow-up on 2026-11-14 passed **116 PHP tests and
23 syntax checks** on Linux/PHP 8.3.36/SQLite and **14 Python release tests**.
Coverage includes fixed-dev/beta gating, commit/manifest refusal, source cache
separation, explicit confirmation, skipped scheduled dev notices, rollback to the
beta and rejection of test flags in normal release installs/packages.
The live development trial remains unrun; this is still fixture coverage.

After a reported manual reinstall on 2026-11-14, a licensed session confirmed
both registered and on-disk versions as 1.2.3. The main, empty History and idle
Software updates pages returned HTTP 200. Restored test settings produced visible
native save feedback and persisted on a fresh request; no private backup directory
or software trial was configured. These checks did not synchronize councils,
remap clients, send notifications or download/install software.

The earlier 1.2.2 run on 2026-11-13 passed 102 tests/23 syntax checks.
The sanitation cases cover encoded/raw markup, controls, UTF-8, exact size/count
boundaries, safe punctuation, staged-preview validation, no-change failures and
payload-free notifications, including the exact 65,535/65,536-byte native
PHP-serialization boundary. The view regressions check all five management
screens use the staff widget helper, retain the core feedback view context and
preserve success/error flashes across redirects. Both model declarations also
loaded against the original readable database parent; the test parent now exposes
its inherited transaction signatures to catch the original lifecycle failure.
Separately, one fetched public API response produced
the same 240 labels/slugs and output digest before and after validation changes.
That comparison is not a live Blesta or hosting-account integration test.

A licensed remote session on 2026-11-13 verified, under one company:

- Repair upgrade to 1.2.1, uninstall/reinstall with purge disabled, and upgrade to
  1.2.2; the three cron tasks and staff navigation registered.
- Settings persistence, inline rejection of an invalid contact without changing
  the saved value, redirected success feedback and a safe no-operation error flash.
- A real hosting-account Wikipedia fetch. The existing unencrypted Council field
  already matched all **240 nonempty, unique options**; the zero-change result
  correctly created no audit batch.
- Main, no-conflict Resolve, empty History and idle Software updates pages.
  The software page showed the 48-hour default without creating a trial.
- Native dark/light theme inheritance for widgets, controls and fonts; the
  original theme preference was restored.

Those earlier licensed checks predate the limited manual 1.2.3 verification above.

This was Blesta 6.0.4 with owner-reported PHP 8.3, not verification of the remote
PHP patch version. It did not exercise applied option changes, native remaps/data
rollback, encrypted client values, multiple companies, concurrent writes,
notification delivery, Support Manager tickets or actual software update trials.
No synthetic clients, staff or tickets were created.

The management pages now use Blesta's `Widget` helper for their shell/navigation
and its existing Bootstrap controls and messages. Colors, fonts and dark/light
presentation are inherited, not overridden by plugin styles. The native check
above covers the installed staff theme, not every third-party theme or mobile size.

The earlier 1.2.0 updater run passed **86 tests and 23 syntax checks** in each of four
configurations: PHP 8.3.36 on Windows/Linux with SQLite or MySQL-backed updater
fixtures (MySQL 8.4.13). Linux exercised real POSIX permissions; native MySQL
exercised schema-changing restoration, scoped metadata and advisory locking.
Generated-banner browser checks covered the countdown, confirmations, schema
gating, expiry and removal. This is not live Blesta/PHP-FPM certification:
software update trials, system encryption, comprehensive staff ACLs, scheduled
execution, SMTP, Support Manager and opcode-cache reloads remain integration work.
Those four-configuration results predate 1.2.1/1.2.2; Windows/MySQL suites were
not rerun for these patches. Earlier milestones are recorded in the
[changelog](./CHANGELOG.md).

### Contributor compatibility

Release authors must retain the updater protocol-1 entry points and declare their
runtime tables in `config.json`. Same-schema releases must retain compatible stored
data formats for preserve-data rollback; structural schema comparison cannot prove
semantic compatibility. Breaking this contract requires a manual upgrade.
`development_protocol: 1` is a capability declaration, not an enabled channel:
targets declaring it must retain beta-backup recovery support. Only generated
test bootstraps carry `development_branch: "dev"`.

Blesta's model parent inherits database transaction methods. Keep domain actions
named `rollbackBatch`, `rollbackUpdate` and `commitUpdate`, not `rollback` or
`commit`. Retain the core view context until framework feedback is prepared;
select plugin PDTs for the final render and use flashes before redirects.
Blesta's client-field model stores options using PHP serialization; plugin audit
snapshots intentionally use JSON.

See the [Blesta plugin documentation](https://docs.blesta.com/developers/plugins/)
for framework conventions. Keep credentials, client data, database dumps and
vendor distributions out of contributions.

## License

[BSD Zero Clause License (0BSD)](./LICENSE). Use, modify, redistribute or sell the
software without attribution or notice-retention requirements. The software is
provided without warranty.

This license covers the plugin's original code, not Blesta or third-party source data.
