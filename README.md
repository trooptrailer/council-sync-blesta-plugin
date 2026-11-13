# Council Sync for Blesta

Council Sync keeps an existing Blesta client dropdown synchronized with the
[list of Scouting America councils on Wikipedia](https://en.wikipedia.org/wiki/List_of_councils_(Scouting_America)).
It detects changes that would leave existing client values orphaned by the dropdown and provides
explicit remapping, notifications and an audit history.

**Experimental:** Use of this plugin comes at your own risk!

## Features

- Fetches the council table through the MediaWiki Action API (unauthenticated).
- Ingests every row, including overseas councils, regions and national entries.
- Generates sorted labels and unique values derived from council names, locations and numbers.
- Validates imported content as bounded plain text and rejects unsafe runs without partial changes.
- Reads and remaps encrypted client values through Blesta's models.
- Supports whole-council and per-client remapping, with transactional history and rollback.
- Provides independently configurable staff email, Support Manager tickets and release checks.
- Offers explicit software installation with private backups and a configurable, 48-hour rollback trial.

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

1. Copy [plugins/council_sync](./plugins/council_sync) into the Blesta installation's
   `plugins` directory. Deploy only the plugin directory, not the entire repository.
2. Install **Council Sync** under **Settings > Company > Plugins**.
3. Grant **Manage Council Sync** to the appropriate staff group under
   **Settings > System > Staff > Staff Groups**.
   Grant **Manage Council Sync software for ALL companies** separately, only to
   trusted operators allowed to deploy PHP code for the entire installation.
4. Open **Council Sync** in the staff navigation. Select an existing dropdown client
   field, configure notification destinations, enter a Wikimedia contact and save.
   Create a dropdown in Blesta's client group settings first if none is available.
5. Review the Council Sync email templates and ensure their sender is allowed by
   the configured mail provider.
6. Run **Update now** and review the result before relying on scheduled updates.

Installation registers daily council-sync and release-check tasks, a five-minute
update-backup cleanup task, and staff email templates. Blesta's system cron must
be running. Cleanup remains enabled independently of release checks.

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

## Council updates and conflicts

Labels use the council name, headquarters state or country, and number, such as
`Longhorn Council, TX (662)`. Values are lowercase hyphenated slugs, such as
`longhorn-council-tx-662`. Locations are omitted for rows marked `n/a`.

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
| Serialized Blesta label-to-value option list | 65,535 JSON bytes, matching its TEXT column ceiling |

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

### First installation of the updater or manual upgrades

Version 1.2.0 introduces the installer, its separate staff permission and cleanup
task. An older installation must receive this version manually first.

Take an operator-managed backup, deploy the complete replacement plugin directory, then use
Blesta's **Upgrade** action before running the plugin. Avoid mixing old and new
files; reload PHP or its opcode cache as required by the hosting environment.
Customized email templates and existing automation schedules are preserved.

### Installing a published release

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

Downloads are pinned to the published release tag's immutable commit, not `main`
or another moving branch. Only `plugins/council_sync` is installed. There is no
GitHub token or arbitrary download-URL setting. Missing releases, incomplete or
unsafe archives, unsupported schemas and failed checks stop the operation.

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

With PHP 8.3, PDO SQLite and ZipArchive available, run from the repository root:

```text
cd tests
php check.php
```

The suite checks PHP/PDT syntax and exercises parsing, conflict resolution,
transactions, notifications, release checks, update trials, scoped restore,
hostile-input validation, archive validation and forms with synthetic data, mocked HTTP and Blesta model
stand-ins. No running Blesta installation or live external service is required.

For optional native updater tests, set `COUNCIL_SOFTWARE_TEST_DSN` to
`mysql:host=127.0.0.1;port=PORT`, with `COUNCIL_SOFTWARE_TEST_USER` and
`COUNCIL_SOFTWARE_TEST_PASSWORD`, and enable PDO MySQL. Use only a disposable server:
the fixtures create and drop random test databases. Other tests still use SQLite.
Windows fixtures bypass the production installer's POSIX guard; file-permission
behavior must be tested on a POSIX filesystem.

The latest 2026-11-13 run passed **99 tests and 23 syntax checks** on Linux,
PHP 8.3.36 and SQLite with read-only source mounts and networking disabled.
The 13 added cases cover encoded/raw markup, controls, UTF-8, exact size/count
boundaries, safe punctuation, staged-preview validation, no-change failures and
payload-free notifications. Separately, one fetched public API response produced
the same 240 labels/slugs and output digest before and after validation changes.
That comparison is not a live Blesta or hosting-account integration test.

The earlier 1.2.0 updater run passed **86 tests and 23 syntax checks** in each of four
configurations: PHP 8.3.36 on Windows/Linux with SQLite or MySQL-backed updater
fixtures (MySQL 8.4.13). Linux exercised real POSIX permissions; native MySQL
exercised schema-changing restoration, scoped metadata and advisory locking.
Generated-banner browser checks covered the countdown, confirmations, schema
gating, expiry and removal. This is not live Blesta/PHP-FPM certification:
actual upgrades, system encryption, staff ACLs/theme, cron, SMTP, Support Manager,
opcode-cache reloads and hosting-account requests remain integration work.

Release authors must retain the updater protocol-1 entry points and declare their
runtime tables in `config.json`. Same-schema releases must retain compatible stored
data formats for preserve-data rollback; structural schema comparison cannot prove
semantic compatibility. Breaking this contract requires a manual upgrade.

See the [Blesta plugin documentation](https://docs.blesta.com/developers/plugins/)
for framework conventions. Keep credentials, client data, database dumps and
vendor distributions out of contributions.

## License

[BSD Zero Clause License (0BSD)](./LICENSE). Use, modify, redistribute or sell the
software without attribution or notice-retention requirements. The software is
provided without warranty.

This license covers the plugin's original code, not Blesta or third-party source data.
