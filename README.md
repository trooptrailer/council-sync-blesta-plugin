# Council Sync for Blesta

Synchronizes one client dropdown with every row of Wikipedia's
[council table](https://en.wikipedia.org/wiki/List_of_councils_(Scouting_America)).
**Target: Blesta 6.0.4 / PHP 8.3** (owner, 2026-11-06). The plugin is built locally;
installation under Blesta has not been tested. It does not update the TroopTrailer API.

## Install

1. Start with a disposable Blesta test installation and synthetic clients.
2. Upload **only** [plugins/council_sync](./plugins/council_sync) to Blesta's
   `plugins` directory. Do not upload the release ZIP, SQL dump, tests or scratch files.
3. Install **Council Sync** under Settings > Company > Plugins. Blesta 6 registers
   its daily cron task and staff email template from the plugin declarations.
4. Grant **Manage Council Sync** to the appropriate staff group under
   Settings > System > Staff > Staff Groups.
5. Open Council Sync, select the field and notification destinations, and save.
   Confirm the sender in the Council Sync email template is permitted by your mail provider.
6. Run **Update now** and verify the installed behavior before enabling unattended use.
   Blesta's normal system cron must also be configured. PHP cURL and transactional
   MySQL/InnoDB tables are required.

## Settings

| Setting | Behavior |
|---|---|
| Target dropdown | Existing `select` client field in this company; its client group and encryption flag are preserved. |
| Staff group | Active members receive fallback email, without adding unrelated template BCC recipients. |
| Support department | Shown only when Support Manager is installed and enabled for the company; optional. |
| User-Agent contact | Required public HTTP/HTTPS URL or email; no credentials. Sent as `CouncilSync-Blesta/1.0.0 (<contact>) PHP/<runtime version>`. |
| Maximum row-count drop | Default 10%, configurable 0-99%; zero rows always fail. Compared with the last successful accepted list. |
| Cron interval | Positive whole minutes, default 1440. Saving preserves a disabled schedule; the page also reflects changes made in Blesta Automation. |
| Delete on final uninstall | Off by default. Requires typing `DELETE COUNCIL SYNC DATA`; authorizes deletion of plugin data for **all** companies only on the last uninstall. |

Fetches use MediaWiki `action=parse`, `prop=wikitext`, without authentication or
DOM/HTML table scraping. One request, 10-second connection/30-second total timeout,
4 MiB response limit; no immediate retries or redirects. HTTP failures, malformed
tables, collisions and sanity failures abort without changing the dropdown or clients.
Unrecognized headquarters locations or unsupported markup fail rather than being guessed.

## Conflicts and notifications

Additions, label-only edits and unused removals apply silently. A removed value in
use by **any** client blocks the entire cron update. A number change changes the slug.
All rows are included, including 800, 802, 803, 901-904 and 999.

With a configured active Support Manager department, the first successful
notification creates a client-less ticket with an internal system note. Each issue
hash receives at most one ticket, even if that same issue returns later. Conflicts
hash only the sorted old-value/count pairs; failures use their own stable hashes.
Ticket/note failure rolls back the incomplete ticket and falls back to email.
Email is sent on every blocked cron run when ticket delivery is unavailable.
Clean runs never notify.

**Manual actions never notify.** Update now applies safe changes immediately;
conflicts are staged only in the staff session. Resolve requires an explicit
decision for each old value: accept the warned orphan, remap everyone, or select
per-client targets. Remap-all supports explicit per-client overrides. Every target
must be in the newly fetched list. Previews expire after one hour; changed field,
client or settings state invalidates confirmation. Truncated per-client forms are
rejected; large splits may require increasing PHP `max_input_vars`.

## History and rollback

Four tables: `council_sync_settings`, `council_sync_batches`,
`council_sync_items`, `council_sync_issues`. Every applied option/client change
records before/after option snapshots, source, staff ID and client actions.
Audited old/new client values use Blesta's system encryption, not plaintext copies.
Removed defaults are cleared where needed and audited; empty unset defaults are preserved.

Apply and rollback share one PDO transaction with Blesta's client models and take
field/value range locks. Rollback is limited to the company's latest applied update,
rejects externally changed state or newly orphaned assignments, and creates its own
audit entry. Uninstall normally retains all plugin tables and never deletes the
client field or its stored values.

## Verification and assumptions

**2026-11-06:** 15 PHP/PDT files passed syntax checks and **44 tests passed on PHP
8.3.36**, offline with SQLite and synthetic Blesta adapters. The owner's original
240-entry **label=>slug** reference fixture matched the model's value=>label output
exactly after inversion. The Blesta storage adapter reverses that orientation.
Run locally with PHP 8.3 and PDO SQLite:

```powershell
php .\tests\check.php
```

Or use an isolated Docker runtime from this repository:

```powershell
$root = (Get-Location).Path
docker run --rm --network none --mount "type=bind,source=$root\plugins,target=/work/plugins,readonly" --mount "type=bind,source=$root\tests,target=/work/tests,readonly" --workdir /work php:8.3-cli php tests/check.php
```

**2026-11-09:** after removing the unused Blesta installation ZIP with owner approval,
all 15 PHP/PDT syntax checks and 44 tests passed again on PHP 8.3.36. The read-only,
network-disabled container mounted only the plugin, tests and council fixture.

**2026-11-10 (unrun):** removed the initial reference JSON and its two fixture-only
checks; it was not runtime input. The synthetic parser fixture and remaining 42 tests
stay. No tests or syntax checks were run for this cleanup, at the owner's request;
the runs recorded above predate it.

The supplied 6.0.4 ZIP and **schema definitions only** from the dump established:
`Clients::getCustomFieldValues()` decrypts; `setCustomField()` encrypts;
`editCustomField()` expects label=>value and returns no success value. Writes are
checked through errors and rereads. Support Manager permits a null client/email,
but a separate `addReply()` is needed. Blesta 6 declares cron/email registrations
through `getCronTaskDefinitions()` / `getEmailTemplates()`; `Plugin` requires its
Input component to be loaded explicitly.

**Still unverified:** real Blesta install/upgrade/uninstall, licensed parent classes
(`AppModel` and `AppController` are ionCube-protected), real encryption, MySQL
locking/concurrent core client saves, staff ACL/layout, Wikimedia requests from the
hosting account, cron, SMTP and ticket delivery. Notification URLs use Blesta's
`admin_uri` template tag and assume HTTPS. Verify the URL on the actual installation;
use a quiet/maintenance window for bulk remaps or rollback.

References: [plugin conventions](https://docs.blesta.com/developers/plugins/),
[Clients](https://source-docs.blesta.com/classes/Clients.html),
[SupportManagerTickets](https://source-docs.blesta.com/classes/SupportManagerTickets.html).

## Source control

**Workflow (owner, 2026-11-09):** keep the GitHub repository private, with `main` as the
default branch and project changes on `dev`. Create GitHub repositories empty, without a
README, license or gitignore. The owner handles commits and publication.

For this initially uncommitted checkout, with `dev` checked out and `origin` configured,
the owner first creates the shared empty baseline:

```powershell
git commit --allow-empty --only -m "Initialize repository"
git branch main
```

`--only` keeps staged project files out of the empty baseline. `main` points to that
baseline; the later project commit stays on `dev`. Run each step separately and stop
if it fails.

Never commit dumps, vendor source, configuration, credentials or client data.
Release ZIPs and source references are ignored. The unused installation ZIP was removed
with owner approval on 2026-11-09; it is not required by the plugin or tests. Stage only intended files,
review the staged changes, and, with pre-commit installed, run `pre-commit install`,
then `pre-commit run gitleaks --all-files`. The hook is not installed automatically.
Do not continue if the scan fails. Confirm the current branch is `dev`, then:

```powershell
git commit -m "Initial development version"
git push -u origin main
git push -u origin dev
```

Before pushing, confirm the repository is private in GitHub. Push `main` first and
confirm it is GitHub's default branch after publication; `dev` remains the local
development branch. Only committed changes are synchronized by a push.
