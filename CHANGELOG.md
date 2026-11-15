# Changelog

Council Sync package changes, newest first. This history was backfilled on
2026-11-13 from local versioned source and dated development/verification records.
Dates identify development or versioned-source checkpoints, not GitHub release
publication dates. As of that date, the GitHub API listed no published releases.

See the [README](./README.md) for current installation, configuration, recovery
boundaries and [verification limits](./README.md#verification-status).

## 1.2.3 - 2026-11-13

### Added

- GitHub Actions secret scans, regression tests and release-readiness checks.
  Plugin-changing main PRs require an unpublished stable version; docs-only
  changes do not require a new version or publish a release.
- Main-push publication of `council-sync-X.Y.Z.zip` with only `council_sync/`
  at the top level, including README, LICENSE and CHANGELOG but no tests.
  Publication uploads a draft's complete ZIP before making the release public.

### Changed

- One-click installation uses the version-matched GitHub Release ZIP asset,
  checking its published size and SHA-256 instead of using a repository archive.
- Asset downloads allow only GitHub's HTTPS release-asset redirect; other
  redirects and unsafe archive paths/files are rejected.
- Added an explicit manifest-gated development bootstrap path for the approved
  1.2.3-beta.1 trial. Stable project version remains 1.2.3; normal packages stay
  Releases-only and release packaging rejects test flags.
- Development trials pin the chosen dev commit/manifest, require an additional
  confirmation, separate cached sources and skip scheduled dev checks/notices.
  Normal targets clear the flag and must support restoring beta backups.

### Validation

- Local validation on 2026-11-14 passed 108 PHP tests/23 syntax checks on
  Linux/PHP 8.3.36/SQLite, 13 Python release tests on Windows/Python 3.14 and
  actionlint 1.7.12.
- A generated ZIP with exactly 19 runtime files and three documents was accepted
  by the production extractor. A real loopback HTTP responder exercised asset
  response headers, redirect-body discard and duplicate-location rejection.
- Version 1.2.3 was explicitly approved. No automatic version bump or schema
  change. GitHub-hosted checks/publication/download and licensed install/rollback
  trials remain unrun.
- Following a reported manual reinstall on 2026-11-14, registered/on-disk 1.2.3,
  native settings save/persistence and main/empty-history/idle-software pages were
  verified. No sync, downloads, remaps, notices or software trials were performed.
- The later bootstrap follow-up passed 116 PHP tests/23 syntax checks and
  14 Python release tests. Development install/rollback is still unrun on the
  licensed host; no beta package or dev flag was committed to main or released.

## 1.2.2 - 2026-11-13

### Fixed

- Retained Blesta's core view root before framework processing so native feedback
  no longer looks for the core message template inside the plugin.
- Used native flashes for redirected success/error feedback while keeping inline
  validation messages on the current form.
- Measured the client-field option list using native PHP serialization rather
  than JSON, including the exact 65,535-byte TEXT boundary.
- Updated test adapters to reflect native option storage and feedback context.
  Plugin audit option snapshots remain JSON.

### Verified

- 102 tests and 23 PHP/PDT syntax checks passed on Linux/PHP 8.3.36/SQLite with
  read-only source mounts and networking disabled.
- Licensed one-company upgrade to 1.2.2, settings persistence, invalid-contact
  rejection without changing the saved contact, and inline/redirected feedback.
- A real hosting-account Wikipedia fetch matched all 240 existing nonempty,
  unique options in an unencrypted Council field. Zero changes correctly produced
  no audit batch.
- Main, no-conflict Resolve, empty History and idle Software updates pages, plus
  native dark/light widget, control and font inheritance. No software trial ran.
- Test-privacy audit: 31 publishable files and reachable history passed redacted
  secret scans; all 19 email-shaped fixture occurrences used reserved example
  domains. The 102-test offline run and deliberate invalid-DSN/connection-failure
  output contained no credential canaries or detected secrets. Test source was unchanged.

No schema or updater-protocol change. Native remaps, encrypted client values,
multiple companies/concurrency, delivery/tickets and actual software-update
trials remain unverified.

## 1.2.1 - 2026-11-13

### Fixed

- Renamed domain model operations to `rollbackBatch`, `rollbackUpdate` and
  `commitUpdate` to avoid PHP declaration collisions with Blesta's inherited
  database transaction methods. Controller `/rollback/` and `/commit/` URLs stayed
  unchanged.
- Made a normal native upgrade repair the partial installed-plugin state left
  when the earlier install hook failed after Blesta inserted its registry row.
- Added inherited transaction signatures to the test parent so the original
  declaration failure is caught during class loading.

### Verified

- Both models loaded against the original readable database parent.
- Native one-company repair upgrade, uninstall/reinstall with purge disabled,
  and registration of the three cron tasks and staff navigation succeeded.

No schema change. Existing staff groups may still need management access granted
after a partial installation. Native feedback and serialization fixes followed in 1.2.2.

## 1.2.0 - 2026-11-13

### Added

- Explicit software installation from a stable GitHub Release, pinned to its
  immutable commit archive instead of downloading a moving branch.
- Installation-wide software permissions and control state, private same-filesystem
  code/scoped-database backups, and guarded native upgrade continuation.
- A configurable rollback trial with a 48-hour default and countdown banner.
  Operators can preserve current data when the plugin schema is unchanged,
  restore scoped snapshots with explicit data-loss confirmation, or keep/purge.
- Independent five-minute cleanup: expired completed trials keep installed code
  and purge their backup; failed/incomplete recovery sets remain available.

### Changed

- All five management pages use native staff widgets, tabs, controls and feedback
  rendering rather than a separate plugin theme; the software page sets a native title.
- Software restoration is scoped to plugin tables and registration descriptors.
  It does not restore the whole database, client dropdowns/assignments, billing,
  support tickets or staff ACL grants.

### Security

- Bounded Wiki input by UTF-8 validity, field/row/response sizes, supported locations
  and ASCII numeric council IDs; rejected raw/encoded markup and control characters.
- Revalidated cached imported options before writes. Invalid required content aborts
  the entire import without partial option, client, audit or baseline changes.
- Reported fixed rejection metadata instead of echoing rejected source payloads,
  while retaining output escaping and bound SQL/Blesta-model writes.

### Validation and limitations

- The initial updater passed 86 tests/23 syntax checks in Windows/Linux SQLite
  and MySQL-backed updater configurations. Subsequent input/theme changes reached
  100 tests on Linux/SQLite; these were fixture/adapter checks, not native certification.
- A local vendor installation initialized its database, but rejected license
  activation before a licensed plugin session could be obtained.
- Native lifecycle/feedback defects in this development package were subsequently
  repaired in 1.2.1/1.2.2. Use 1.2.2 or newer for manual bootstrap.

## 1.1.0 - 2026-11-11

### Added

- Unauthenticated stable GitHub Release checking, a daily release-check cron,
  staff email template and cached registered/package/latest versions and timestamps.
- Explicit unavailable status for missing/private releases or failed requests;
  previously verified metadata is retained rather than treating failure as current.
- Release/notification tracking schema upgrades and repeatable legacy-state backfill.
- The 0BSD license for the plugin's original code and general-user documentation.

### Changed

- Email and ticket notifications became independently configurable.
- Both enabled channels receive initial notices; email reminders are weekly.
  Open tickets are not duplicated; closed/trashed tickets may be replaced when due.
- Successful email recipients and ticket deliveries are tracked independently
  so partial retries do not repeat successful delivery within the reminder window.
- Manual version checks never send notifications or install software.

Release checking did not include an installer; software installation followed
in 1.2.0. Client-less Support Manager behavior remained unverified.

## 1.0.0 - 2026-11-10

### Added

- Initial Council Sync plugin targeting Blesta 6.0.4/PHP 8.3, developed before
  this first versioned-source checkpoint.
- MediaWiki Action API council-table parsing without authentication or HTML
  scraping, including overseas councils, regions and national entries.
- Sorted council labels/slugs with USPS/country handling, unique-value checks
  and configurable row-count-drop protection.
- Company-scoped target dropdown, Wikimedia contact, notification destinations,
  daily synchronization and explicit final-uninstall data-deletion settings.
- Conflict detection for in-use removed/changed options. Safe unused changes
  apply automatically; any conflict blocks the entire run.
- Explicit orphan acceptance, whole-value remapping and per-client target overrides.
- Blesta-model client field handling, encrypted client-action audit copies,
  transactional batches and guarded latest-batch council-data rollback.
- Scheduled conflict/fetch/sanity/collision notices with issue hashes,
  Support Manager tickets and staff email fallback; manual actions remain silent.
- Synthetic Blesta/SQLite regression tests. Native integration was not established
  by those tests; later native repairs and limited verification are listed above.
