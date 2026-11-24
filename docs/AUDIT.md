# Audit of com_biotime

The latest full audit covers version 1.3.6; its fixes are in 1.3.7. The earlier audit (1.0.17) is
kept at the end.

## 1. Executive summary

**What was done.** Four independent reviews covered the whole repository:
- the employee and manager pages (`site/`);
- the administrator side (settings, mappings, Find Tables, installer);
- the database layer and performance;
- Joomla conventions, build and CI.

Every finding was checked against the code before anything changed. One finding turned out to
be wrong and was left alone (C12 in §4). Then I ran the dependency audits, the full test suite
against a real PostgreSQL fixture, and the package build.

**Result.**
- **No Critical or High finding.** Every page requires login and its Joomla permission.
- **No request value can select another employee:** the employee is always the server-side
  mapped identity, and manager filters only narrow what ZKBioTime allows.
- **Every value is escaped where it is output.** All SQL is bound or uses quoted catalogue
  identifiers.
- **The ZKBioTime database connection is read-only and encrypted.** The stored password is
  AES-256-GCM.

That no finding was rated Critical or High does not mean the portal is free of vulnerabilities.
This was a code review, not a penetration test of a live site.

**Fixed in 1.3.7.**
- One Medium security issue: **Confirm** could turn a user-typed employee code into a mapping.
- Three Medium correctness or performance issues:
  - leave days shown as Absent;
  - raw language keys shown instead of database error messages;
  - hundreds of cache queries per Manager Dashboard page.
- One installer bug: the date format was reset on every update.
- Several Low items.
- CI now runs the PostgreSQL tests and a dependency audit.

## 2. Architecture

| Part | What it is |
|---|---|
| Platform | Joomla 6 component (`com_biotime`), PHP 8.3–8.5, namespaced MVC (`BiotimePortal\Component\Biotime`), service provider (`administrator/services/provider.php`), `MVCComponent` with a `RouterView` SEF router, web assets in `media/joomla.asset.json`. |
| Data source | ZKBioTime 9.5's PostgreSQL database, read with PDO by a dedicated read-only user (`default_transaction_read_only`, SELECT grants, a column grant on `auth_user`), over TLS. Nothing is written to ZKBioTime. |
| Identity | Joomla user → ZKBioTime employee mapping (`#__biotime_usermap`): by an admin-only profile field, by e-mail (waiting for approval by default), or by an administrator. |
| Pages | My Dashboard, My Attendance, Calendar, Day, My Punches, My Leaves, My Approvals, Manager Dashboard (team scope from ZKBioTime's user/department permissions), print page and server-side PDF (bundled mPDF 8.3.1). |
| Cache | Per user and employee in `#__biotime_cache` (plain JSON, no secrets). It may be served past its time for up to 7 days while the page reloads itself in the background (`refresh.js`); team scope and approver lists never are. |
| Administrator | Integration Settings (password encrypted with AES-256-GCM, key from `BIOTIME_PORTAL_SECRET` or Joomla's `secret` through HKDF), User Mappings, Find Tables (read-only schema explorer and Diagnostics). Super user only (`core.admin`). |
| Audit and limits | `#__biotime_audit` (identity events, admin actions, PDF exports), rate limits on Test Database, Find Tables and PDF exports. |

The design keeps the separation it had: readers (SQL) → `AttendanceService` (rules, cache) → models → views and templates. The audit added no new layers.

## 3. References used

- **Joomla 6.2.0 core source** was the authority for every API claim: MVC base classes,
  `BaseController::checkToken()`, `WebAssetManager`/`ScriptsRenderer` versioning,
  `Installer::update()` (no `flushAssets()`), `InstallerAdapter` (preflight before
  `storeExtension()`), and the `@deprecated` tags.
  - [Joomla Manual](https://manual.joomla.org/)
  - [Joomla coding standards](https://developer.joomla.org/coding-standards.html)
- **OWASP:**
  - [OWASP Top 10 (2021)](https://owasp.org/Top10/): A01 broken access control, A03 injection, A04 insecure design, A05 misconfiguration, A06 vulnerable components, A07 identification and authentication, A09 logging.
  - [OWASP ASVS 4.0.3](https://owasp.org/www-project-application-security-verification-standard/): V4 access control, V5 validation and encoding, V6 cryptography, V7 errors and logging, V13 API.
- **PostgreSQL:**
  - [GRANT](https://www.postgresql.org/docs/current/sql-grant.html) (column privileges)
  - [AT TIME ZONE](https://www.postgresql.org/docs/current/functions-datetime.html#FUNCTIONS-DATETIME-ZONECONVERT) (POSIX offsets read the other way round)
  - [libpq SSL modes](https://www.postgresql.org/docs/current/libpq-ssl.html)
- **PHP:**
  - [`#[\SensitiveParameter]`](https://www.php.net/manual/en/class.sensitiveparameter.php)
  - [DateTimeZone](https://www.php.net/manual/en/class.datetimezone.php)

## 4. Findings and what was done

The severity scale is Critical, High, Medium, Low and Informational. In the Status column, **Fixed**
means changed in 1.3.7, **Open** means a remaining risk (see §6), and **Invalid** means the finding
was checked and is not a problem.

### Security

| # | Severity | Finding | Affected | Status |
|---|---|---|---|---|
| S1 | Medium | **Confirm accepted a user-typed code.** When the employee code profile field is editable by users, matching refuses it but keeps the typed code on a "Not found" row. An administrator who ticked that row and clicked **Confirm** mapped the user to that employee and gave them the employee's attendance and leave (IDOR through the approval flow). | `Identity/MappingManager::confirm()` | **Fixed**: only *pending* or *suspended* rows with a found employee can be confirmed, and only for the same ZKBioTime employee (`COM_BIOTIME_MAPPING_ERR_CHANGED` otherwise). Tests added. |
| S2 | Medium (config) | **Approve automatically restores matching by e-mail.** With *New users → Approve automatically*, a user who sets their Joomla e-mail to a colleague's ZKBioTime e-mail before the colleague's first visit is matched at once. Joomla does not verify e-mail changes. | `IdentityResolver`, setting `auto_approve_matches` | **Open by design**: the default is *Wait for administrator approval*, and the setting's description warns about this. |
| S3 | Medium (needs DB write) | **The encrypted password is bound only to the context `db-password`, not to the server.** Someone who can write to `#__biotime_settings` (another extension's SQL injection, a restored backup) could point the host elsewhere and receive the password. The settings form already refuses this. | `Security/KeyVault`, `Settings::dbConfig()` | **Open**: binding the encryption to host, port, user and SSL mode needs a migration of stored passwords. Recommended for a later version. |
| S4 | Low | **PDF and print exports were reachable by a plain GET.** Another page could make a manager's browser request reports, using up their 30-per-hour limit and loading the server (CSRF on a costly action). | `DisplayController`, `tmpl/manager/default.php` | **Fixed**: the Export PDF links carry the form token, and the report needs it (403 `COM_BIOTIME_REPORT_EXPIRED` otherwise). |
| S5 | Low | **My Approvals could show the previous waiting list** (other employees' requests) for one visit after the approver's ZKBioTime role was removed, while the page updated. | `ApprovalsModel` | **Fixed**: that list is never taken from a copy older than the cache time. |
| S6 | Low | **The read-only SQL guard let through functions that change settings**: `set_config`, `pg_terminate_backend`, `lo_*`, `dblink`, `pg_read_file`. A persistent connection would keep such a change. | `Database/Query::rows()` | **Fixed**: these functions and SQL comments are refused. The database grant remains the real boundary. Tests added. |
| S7 | Low | **The no-JavaScript reload URL was trimmed of `/` but not `\`.** `/\host` can read as another site. | `DisplayController::updateInBackground()` | **Fixed**. |
| S8 | Low | **The password parameter could appear in exception traces** (`zend.exception_ignore_args=Off`). | `DbConnector` | **Fixed**: `#[\SensitiveParameter]`. |
| S9 | Low | **Settings changes were audited without detail.** Failed saves and failed Diagnostics were not recorded as such. | `SettingsController`, `TablesController` | **Fixed**: the names of changed settings are recorded (never their values); invalid saves are recorded as denied, failed Diagnostics as error, and downloads are recorded. |
| S10 | Low | **Super users can point Test Database or Find Tables at any host, and the CA file at any readable path.** Both need `core.admin` and a token and are rate limited. | `SettingsValidator`, `DbConfig` | **Open**: acceptable for super users; restrict if `core.admin` is delegated. |
| S11 | Low | **Rate limits count before they record**, so parallel requests can pass the limit. | `RateLimiter` | **Open**: low impact, admin and manager only. |
| S12 | Info | **A manager is linked to a ZKBioTime user by `emp_pin`, username or e-mail.** A shared mailbox or a username equal to an employee code can grant another account's departments. | `TeamReader::userScope()` | **Open**: site owner's decision (also noted in 1.0.17). |
| S13 | Info | **A raw report for an unmatched user gave a 500** ("view not found"). | `DisplayController` | **Fixed**: 403. |
| S14 | Info | **`sslmode=require` does not check the certificate.** | settings | **Open**: the default is *verify*; the README explains the modes. |

### Correctness and performance

| # | Severity | Finding | Affected | Status |
|---|---|---|---|---|
| C1 | Medium | **Leave days showed as Absent** when a leave pay code's name lacks the English word "leave" (custom or Arabic names). The older builds' `leave` flag was read but not used. | `AttendanceReader`, `AttendanceService::build()` | **Fixed**: any pay code that is not one of ZKBioTime's built-in codes (late, early, absence, holiday, weekend, regular) is leave, under its own name. The leave flag is used too. Cache version raised to 5. Tests added. |
| C2 | Medium | **Database errors on My Leaves, My Approvals and the Manager Dashboard showed raw keys** such as `COM_BIOTIME_ERROR_UNREACHABLE`. | 3 views | **Fixed**: mapped to the portal's messages (`SourceException::kindOf()`). |
| C3 | Medium | **The Manager Dashboard made one cache query per employee, month and type**: hundreds per page. | `ManagerModel`, `AttendanceService`, `DbUserCache` | **Fixed**: one query for the whole team (`getMany()`, chunks of 500). Tests added. |
| C4 | Medium | **Night shifts (end before start) are split at the wrong point.** | `AttendanceService`, `AttendanceReader` | **Open**: needs a design for shifts crossing midnight. The school has none today. |
| C5 | Low | **Every update reset the date format** YYYY-MM-DD to DD/MM/YYYY. | `script.php` | **Fixed**: only when updating from before 0.4.2 (the installed version is read in preflight). |
| C6 | Low | **The Day page accepted any year from 2000 to 2099** and cached months that cannot have data. | `DayModel` | **Fixed**: from the portal's first year to today. |
| C7 | Low | **A time zone alias (e.g. `Asia/Calcutta`) became UTC in the readers** but not in the service. | `provider.php` | **Fixed**: named zones are kept; only numeric offsets (POSIX sign) fall back to UTC. |
| C8 | Low | **A profile not found was cached for a day** (plus up to 7 days of stale copies). | `AttendanceService::profile()` | **Fixed**: kept only for the cache time; the same applies to an empty device list. |
| C9 | Low | **The attendance group was picked without ORDER BY** (two paths could differ). | `AttendanceReader` | **Fixed**: `ORDER BY id`. |
| C10 | Low | **Cache write race** (DELETE then INSERT), the user index unused, and the audit table never purged. | `DbUserCache`, `DbAuditLog` | **Open**: errors are logged and harmless; housekeeping for later. |
| C11 | Low | **Team list capped at 2000 employees without a notice.** | `TeamReader` | **Open**. |
| C12 | Info | **"Sort by status" in User Mappings does nothing.** | `UsermappingsModel` | **Invalid**: `m.status` is in `filter_fields`. |
| C13 | Info | **Duplicate assignment, orphan docblock, stale comments and menu description.** | various | **Fixed**. |

### Joomla conventions, build and CI

| # | Severity | Finding | Status |
|---|---|---|---|
| J1 | Medium | **The installer's delete lists were not checked against the package.** This is how `media/js/admin.js` was deleted after every update (1.3.0–1.3.3). | **Fixed**: `ObsoleteFilesTest` fails if a shipped file is on a delete list. |
| J2 | Medium | **CI never ran the queries against PostgreSQL** (about 10 tests skipped). | **Fixed**: CI starts the runner's PostgreSQL (with SSL), builds the fixture with `tests/Support/sql/setup-test-db.sh`, and runs the tests with `--fail-on-skipped`. |
| J3 | Medium | **No dependency audit in CI.** | **Fixed**: `composer audit` for the test tools and the bundled mPDF; Dependabot also watches the test tools. |
| J4 | Medium | **No static analysis.** | **Open**: PHPStan could not be run here (downloading and running it was not allowed in this environment). Recommended as a CI job with Joomla's libraries as scan paths. |
| J5 | Low | **The package could be released when PHP 8.4 or 8.5 failed**, and a manual run could release from any branch. | **Fixed**: `needs: php`; releases from `main` only. |
| J6 | Low | **The PDF temp folder was left after uninstall.** | **Fixed**. |
| J7 | Low | **README said Joomla 6.2, the installer checks 6.0.** | **Fixed**: the README says 6.0 or later, tested on 6.2. |
| J8 | Info | **`Factory::getApplication()` is used where `getCurrentUser()` or injection exist.** Duplicate helpers ("boot com_biotime", identity). `zkb.*` ACL prefix. The package zip is not reproducible. | **Open**: not deprecated in Joomla 6.2; a refactor without a functional gain. |
| J9 | Info (1.3.3) | **Joomla's extension update does not renew the media version.** | Fixed earlier: every asset carries the component version (`AssetVersionTest`). |

No deprecated Joomla 6.2 API is used. No implicit nullable parameter exists (PHP 8.4). The
`.sql` update files exist for every version in both drivers.

## 5. Tests performed (1.3.7)

| Check | Result |
|---|---|
| `vendor/bin/phpunit --display-deprecations --fail-on-deprecation --fail-on-skipped`, against a PostgreSQL 16 fixture (TLS 1.3, read-only user, column grant on `auth_user`) built by `setup-test-db.sh` | **137 tests, 942 assertions, all passed, none skipped** (PHP 8.3.6) |
| New tests | Confirm refuses a typed code or another employee; the SQL guard (functions, comments, words in literals); leave in any language and the leave flag; the batched cache read; the team-wide uncached lookup; the installer delete lists |
| `php -l` on every PHP file outside the bundled vendor folder | No errors (PHP 8.3) |
| `composer audit --locked`, root and `build/pdf` (mPDF 8.3.1) | No advisories |
| Template render harness (site and administrator pages) | All pages render; no missing language string; the export links carry the token |
| `build/build-package.sh` + `build/verify-package.py` | Package OK |
| PHP 8.4 / 8.5 | Run by CI only (not installed here) |
| PHPStan / PHPCS | **Not run** (see J4) |
| A live Joomla site | **Not available here.** Setting one up needed a new database account, which was not allowed. The TESTING checklist for 1.3.7 covers what to check on the real site. |

## 6. Remaining risks

- **S2:** *Approve automatically* trusts unverified Joomla e-mail changes. Keep *Wait for
  administrator approval*.
- **S3:** Bind the encrypted database password to the connection settings (with a migration),
  so someone with write access to the Joomla database cannot redirect it.
- **S12:** How managers are linked to ZKBioTime users (`emp_pin`, username or e-mail) is the
  weakest part of the Manager Dashboard's access model. Prefer `emp_pin`, and avoid shared
  mailboxes on ZKBioTime users.
- **C4:** Night shifts.
- **J4:** Static analysis in CI.
- **Leave statuses:** values other than 1 to 4 on the request (cancelled or withdrawn) are not
  verified against a live server. Find Tables' Diagnostics lists the values actually in use.

## 7. Recommendations

1. Add PHPStan (level 5 or higher, Joomla 6 libraries as scan paths) and PHPCS (Joomla standard)
   as CI jobs.
2. Bind the stored password to the connection settings (S3), with a one-time re-encryption.
3. Restrict the CA file to a folder, and hide driver error text from non-super-users, if
   `core.admin` is ever delegated (S10).
4. Make rate limits atomic (record first, then count) and purge old audit rows (S11, C10).
5. Use an upsert for cache writes, and delete a user's cache rows when the user is deleted (C10).
6. Design night-shift handling before a school with such shifts uses the portal (C4).

## Earlier audit: version 1.0.17, fixed in 1.0.18

Two independent reviews of the whole component, by reading the code (nothing was run against a
live site): one against the Joomla Manual and extension conventions, one for security. Every
finding was checked against the code before it was changed.

### Result

- **Security: no critical, high or medium finding.** No cross-site scripting, SQL injection,
  request forgery, secret leak, or way to see another employee's data was found.
- **Structure: no high finding.** The component follows Joomla's service-provider structure
  (namespaced MVC, `services/provider.php`, `InstallerScriptInterface`, a `RouterView` router,
  web assets through `joomla.asset.json`, language keys for every string).

### Fixed in 1.0.18

| # | Finding | Change |
|---|---|---|
| S1 | The PDF report reads the whole team (up to 300 employees × 31 days) without the cache and could be requested without limit. | At most 30 reports per manager and hour; each report is recorded in the audit log (`manager.pdf`). |
| S2 | A manager's departments and team were kept for the cache time (up to 60 minutes), so a permission removed in ZKBioTime kept showing that long. | Kept for at most 5 minutes. |
| S3 | Any `layout` could be asked for on any page (only an error page resulted). | Only the Manager Dashboard's `print` layout can be chosen; anything else is the page itself. |
| S4 | The stored logo path and colour were checked with `$`, which accepts a trailing line break. | Checked with `\z`. |
| J1 | The setting *Modules → Charts* did nothing since 0.9.0. | Removed (field, default, strings); its stored row is deleted on update. |
| J2 | `style=""` attributes (month arrows, chart legends and bars) need `'unsafe-inline'` under a strict Content Security Policy. | A CSS class and SVG attributes instead; no portal page has a `style` attribute. |
| J3 | The User Mappings filters used inline `onchange="this.form.submit();"`. | Joomla's `js-select-submit-on-change` class. |
| J4 | A session clean-up for versions before 0.4.6 still ran on every page. | Removed. |
| J5 | 17 language strings were no longer used. | Removed. |
| J6 | The release workflow used the runner's PHP, not the PHP 8.3 the component requires. | PHP 8.3 set up explicitly (action pinned to a commit). |
| J7 | Manifest and `access.xml` comments were misplaced or missing. | Corrected. |

### Not changed: decisions for the site owner

- **How a manager is linked to a ZKBioTime user.** The link uses the employee ID (`emp_pin` or
  username) or the e-mail of the employee record. Someone who can edit employee records in
  ZKBioTime, and who also has the Manager Dashboard permission, could set their own employee
  e-mail to an administrator's and see every department. Whether that matters depends on who may
  edit employees in ZKBioTime. Linking by `emp_pin` only would close it, but would break managers
  whose ZKBioTime user is linked by e-mail or username today, so it was left as it is.
- **Framing.** The component sets no `X-Frame-Options` itself; enable Joomla's *System – HTTP
  Headers* plugin for that. The portal has no state-changing action a frame could trigger.
- **Administrator-only inputs.** The CA file path and Test Database accept any path or host; both
  need Super User rights and a token, and Test Database is limited to 20 an hour.

### Noted, left for later (low)

- Portal CSS uses physical `left`/`right` in places and the month arrows are fixed: a right-to-left
  language would need logical properties.
- The date filter is always DD/MM/YYYY, whatever *Date format* is set to.
- The day details page has no clean URL of its own (`?view=day&date=…` under the menu item).
- Models and views call `Factory::getApplication()`; Joomla's `getCurrentUser()` is preferred.
- The Test Database summary for support requests is English only, on purpose.
