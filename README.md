# Council Sync for Blesta

Council Sync keeps an existing Blesta client dropdown synchronized with the
[list of Scouting America councils on Wikipedia](https://en.wikipedia.org/wiki/List_of_councils_(Scouting_America)).
It detects changes that would leave client values outside the dropdown and provides
explicit remapping, notifications and an audit history.

**Experimental:** development targets Blesta 6.0.4 and PHP 8.3. Automated tests use
stand-ins; the plugin has not yet been validated in a running Blesta installation.
Test on a non-production installation and back up the database before use.

## Features

- Fetches the council table through the MediaWiki Action API without an API key.
- Includes every row, including overseas councils, regions and national entries.
- Generates sorted labels and unique values from council names, locations and numbers.
- Reads and remaps encrypted client values through Blesta's models.
- Supports whole-council and per-client remapping, with transactional history and rollback.
- Provides independently configurable staff email, Support Manager tickets and release checks.

## Requirements

- Blesta 6.0.4 and PHP 8.3 are the development targets; other versions are unverified.
- PHP cURL, a transactional MySQL/InnoDB database, and configured Blesta cron.
- Outbound HTTPS access to Wikipedia and, if release checks are enabled, GitHub.
- Working Blesta email delivery for email notifications.
- Support Manager installed and enabled for ticket notifications; otherwise it is optional.

## Installation

1. Copy [plugins/council_sync](./plugins/council_sync) into the Blesta installation's
   `plugins` directory. Deploy only the plugin directory, not the entire repository.
2. Install **Council Sync** under **Settings > Company > Plugins**.
3. Grant **Manage Council Sync** to the appropriate staff group under
   **Settings > System > Staff > Staff Groups**.
4. Open **Council Sync** in the staff navigation. Select an existing dropdown client
   field, configure notification destinations, enter a Wikimedia contact and save.
   Create a dropdown in Blesta's client group settings first if none is available.
5. Review the Council Sync email templates and ensure their sender is allowed by
   the configured mail provider.
6. Run **Update now** and review the result before relying on scheduled updates.

Installation registers separate council-sync and release-check cron tasks, each
daily by default, plus staff email templates. Blesta's system cron must be running.

## Settings

Settings are scoped to the current Blesta company.

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
notifications. The settings page shows cached versions and check timestamps.
The plugin never downloads or installs software updates automatically.

The checker uses GitHub's unauthenticated API and requires publicly accessible
release metadata. Private repositories, missing releases and request failures
produce an **unavailable** status, not an assumption that the plugin is current.
Previously verified metadata is retained with its timestamp after a failed check.
Scheduled release checks can be disabled independently.

### Upgrading

Back up the database, deploy the complete replacement plugin directory, then use
Blesta's **Upgrade** action before running the plugin. Avoid mixing old and new
files; reload PHP or its opcode cache as required by the hosting environment.
Customized email templates and existing automation schedules are preserved.

The 1.1.0 upgrade adds notification controls, delivery tracking and release-check
state without discarding existing council history.

## Uninstalling

Uninstalling does not remove the client field or its values. Plugin settings,
history and notification records are retained by default.

To authorize permanent removal of all plugin tables on the final uninstall, enable
the deletion setting and enter `DELETE COUNCIL SYNC DATA`. This deletes plugin data
for **all companies**, not just the current company.

## Development and testing

With PHP 8.3 and PDO SQLite available, run from the repository root:

```text
cd tests
php check.php
```

The suite checks PHP/PDT syntax and exercises parsing, conflict resolution,
transactions, notifications, release checks and form handling with synthetic data,
mocked HTTP responses and Blesta model stand-ins. No running Blesta installation
or live external service is required.

The last recorded run, on 2026-11-11, passed **58 tests and 15 syntax checks** on
PHP 8.3.36. This is not a live integration certification. Blesta installation and
upgrades, actual encryption, MySQL locking, staff permissions and layout, cron,
SMTP, Support Manager and live external requests still require integration testing.

See the [Blesta plugin documentation](https://docs.blesta.com/developers/plugins/)
for framework conventions. Keep credentials, client data, database dumps and
vendor distributions out of contributions.

## License

[BSD Zero Clause License (0BSD)](./LICENSE). Use, modify, redistribute or sell the
software without attribution or notice-retention requirements. The software is
provided without warranty.

This license covers the plugin's original code, not Blesta or third-party source data.
