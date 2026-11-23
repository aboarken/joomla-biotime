# Joomla ZKBioTime (com_biotime)

Employees log in to Joomla and see their own attendance from ZKBioTime: each day's check-in and
check-out (with the device and how they were verified), late-in and early-out, whether the day
was Present, Absent, Leave, a Holiday or a Weekend, statistics for the school year, and how many
times they punched by fingerprint and face, per month and per device. Managers see their teams on
the **Manager Dashboard**, exactly the departments ZKBioTime lets them see, and can export it as a
**PDF on the organisation's letterhead**.

ZKBioTime is the single source of truth. Joomla only reads and displays: it never creates,
edits or corrects attendance data. Everything is read from **ZKBioTime's own PostgreSQL
database, read only**: the punches (with the device and verification of each), the employees,
each day's schedule and ZKBioTime's own calculation (late-in, early-out, worked time, overtime,
holidays, leave, days off). Since 1.0.0 the ZKBioTime REST API is no longer used.

Which table supplies each value is in [docs/DATABASE.md](docs/DATABASE.md).

## Features

### Portal pages (menu item types)

Create them under **Menus → Add New Menu Item → ZKBioTime**. The site's own menu is the
navigation; the pages have no navigation bar of their own. The menu item title is the page
heading and browser title, and *Page Heading*, *Browser Page Title* and *Show Page Heading*
are respected.

| Menu item type | What the user sees |
|---|---|
| **My Dashboard** | The school year so far: **from 1 August** (this year's, or last year's before August) **to today**. Under the heading, an **Employee Information** panel like *Today*: department, position, employee ID, name and the period shown. **Today**: check-in, check-out, late in, early out and the status. **Cards**, 5 per row, each in its own colour: *All Days* (selected by default), days present (of present + absent), days absent, leaves, holidays, weekends, weekend days worked, attendance rate, missing morning punch, missing afternoon punch, punches, average check-in, average check-out, total late in, total early out, late in + early out, worked time, overtime. A card with a filter opens My Attendance for the same days. **Charts** (drawn on the server, no script; each its own card, two per row, readable on phones): Total Attendance (donut), Missing Punches per Month (missing morning and afternoon punches, two columns per month), Total Late In and Early Out per Month (thick bars), Average Check-In and Check-Out per Month, Attendance per Month (rates as horizontal bars: red below 70 %, yellow to 95 %, green above) and Punches per Device (fingerprint and face; devices without punches are left out). |
| **My Attendance** | One row per day: date (link to the day's details), day, the schedule, **check-in** and **check-out** (each with its arrow, colour and device, or *Missing*), late in, early out, **Total Exceptions** (late in + early out) and the status. Weekend rows and today are shaded. Filters: month and year, **From – To** dates (31 days per page with Previous / Next for a long range, 1.2.9), and status. The same cards as My Dashboard (All Days by default); a card shows only its days. |
| **Attendance Calendar** | The month as a calendar; each day shows its status as colour **and** text, its check-in and check-out, and opens the day's details. Weekend days are shaded. Usable with keyboard and screen readers and on a 390 px phone. |
| **My Punches** | The employee's own punches for a year (from 2025), or one month of it: total punches, by **fingerprint** and by **face**, devices used and days with punches; a table **per month** with a total row (each month opens My Attendance), and a table **per device** (area, fingerprint, face, total, last used). Only the logged-in employee's punches. |
| **Manager Dashboard** | For managers (one permission, *View Manager Dashboard*). **ZKBioTime decides who they see**: the manager's own ZKBioTime *user* (the account they log in to ZKBioTime with, found by employee ID or by the employee record's e-mail; see *Manager Dashboard access* below) gives every department when it is a superuser or may manage all departments, otherwise exactly the departments of its data permissions; departments ZKBioTime names them *Department Manager* of count too, with those below them. Someone with neither sees a "no access" message. Filters: department (*All Departments*), employee, status, **Employee ID** search (e.g. `E993`, then Enter), From – To (today by default, **up to a year**, 1.3.6: up to 31 days 300 employees are shown at a time; a longer period fewer, e.g. 25 for a whole year, and the manager narrows the list with a department or an employee). Cards: **Workforce** (selected by default), All Days and the My Attendance figures for everyone shown, plus total morning and afternoon punches. The table: one row per employee, **a column per date** (check-in, check-out, the day's late-in + early-out; amber when there are any), then Late In, Early Out and **Total Exceptions**; 50 employees per page, the header row and the employee column stay in view while scrolling. **Export PDF (A4)** and **Export PDF (A3)** under the table: every employee (all pages) as a report, see below. |
| **My Leaves** | The logged-in employee's own leave requests from ZKBioTime (1.1.0), newest first: leave type, from and to, days, status (Pending, Approved, Rejected, Revoked), **every approval stage** in order (its status, who decided and when, the remark; the stage the request is at is marked), applied date, reason and the attachment's file name. Cards: requests, pending, approved, rejected, approved days. Read only: requests are made in ZKBioTime (a *Leave Request* page is planned). |
| **My Approvals** | For approvers (1.1.0): the leave requests **waiting at a stage they approve** in ZKBioTime, the longest waiting first: those whose current stage lists one of their ZKBioTime approver roles, or was sent to them; never their own. Then the stages they **approved or rejected**, with their remark and the request's status. Read only: requests are approved in ZKBioTime. The workflows, stages and approver roles are set up in ZKBioTime. |

Day details (one day: check-in, check-out, time between, status, every punch with how it was
counted, exceptions) open from the Dashboard, Attendance and Calendar pages; they are not a
menu item type.

**Export PDF (Manager Dashboard).** **Export PDF (A4)** and **Export PDF (A3)** under the table
download a finished **PDF file** made on the server (1.2.0): no print dialog, always landscape. It
has the **site name** at the top (Joomla's *Global Configuration → Site Name*) and an optional
logo and header lines (*Integration Settings → Report Letterhead*), an **Attendance Report** title
band with the period, the filters used, who printed it and when, the same cards and the table in
the page's own colours, the footer and the page number on every page, and the header row repeated
on each page; the days share the width equally and the totals take only the room their titles need (1.2.1). **A4** puts up to 12 days in a table, a longer period in several tables (the employee
column repeated, the totals in the last one); **A3** puts up to 31 days in one
table, one row per employee, and a longer period in several tables of at most 31 days (a year: 12 pages). The PDF is made by the bundled **mPDF** library (GPL-2.0,
`administrator/vendor`, one font family for Latin and Arabic text); nothing is installed on the
server.

**Design.** The pages use the same design as the ServiceDesk Plus portal: summary cards, panels,
tables and coloured status pills, month navigation in the heading, and filters that apply as soon
as an option is chosen. The component styles everything itself
(`media/com_biotime/css/portal.css`), so the pages look the same on any site template.

**Day statuses** (holidays, leave and days off from ZKBioTime's calculation; otherwise from the punches):

| Status | When |
|---|---|
| Present | any punch was recorded. When the check-in or the check-out is missing, the missing time shows *Missing*; it counts as Present and as a missing morning or afternoon punch. |
| Checked In / Checked Out | today, after the check-in (↘) / after the check-out (↗) |
| Absent | a past working day without any punch |
| Holiday | a holiday of ZKBioTime (for everyone, the department or the attendance group), without punches |
| Leave | leave as ZKBioTime calculated it (e.g. *Annual Leave*, shown by its name), without punches |
| Rest Day | a day off in the employee's schedule, without punches |
| Weekend | a weekend day without punches (Friday and Saturday by default, *Integration Settings → System → Weekend days*). A weekend day **with** a check-in or check-out loses the weekend tag and is shown like any other day. |

**Check-in** is the first punch **before the cut-off** and **check-out** the last punch **from the
cut-off on**: the end of the check-in window of the day's timetable, or 12:00 on a day without
one, whatever state the device recorded (a morning "Check Out" punch is a check-in, an afternoon
"Check In" punch a check-out). A day with punches only in the morning has no check-out, one with
punches only in the afternoon has no check-in: both show *Missing*.
Today shows **Checked In** (↘) after the check-in and **Checked Out** (↗) after the check-out.

**Dates** are shown as DD/MM/YYYY by default (*Integration Settings → System → Date format*), and
the date filter is a date picker that shows and sends DD/MM/YYYY. Year lists start at 2025.

**Exceptions** shown read-only (nothing is corrected): missing check-in, missing check-out,
incomplete punch record, duplicate punch.

**Times** are shown exactly as ZKBioTime recorded them, in the ZKBioTime server's time zone; a
page says so when the user's own Joomla time zone differs. Times are never shifted.

### Administrator pages

- **Integration Settings**: the connection to ZKBioTime's database (password encrypted), employee
  mapping, the report letterhead, system settings; **Test Database**, **Close**,
  **Options**.
- **User Mappings**: every Joomla user mapped to a ZKBioTime employee, with search, status and
  source filters, and pages. **New** (map by employee code), **Confirm**, **Suspend**,
  **Re-match**. The status filter *No mapping yet* lists Joomla users that are not matched, and
  *Waiting for approval* the new matches by e-mail an administrator still has to
  confirm (select several, **Confirm**).
- **Find Tables** (1.3.0): find the tables of ZKBioTime's database and what they hold, to check
  the portal's tables on a server or to identify the tables a new feature needs (like *Find
  Endpoints* in the ServiceDesk Plus portal). Read only, with the portal's read-only user.
  - **1. Tables**: the tables the portal knows, with what they hold and which page reads them,
    and every other table the database user can see; readable or not, about how many rows.
  - **2. Check a table**: its columns and types, primary and foreign keys (with links to the table
    each key points to), the tables that point to it, its row count, how often each column is
    filled and, for code columns only (status, type, flag…), which values are used and how often.
  - **Diagnostics** (at the end of the page): one click writes a report to download (a text file) and attach to a support request: settings (no
    address, user or password), connection (encrypted, read-only), each table the portal knows
    (found, readable, about how many rows), the codes the server stores for statuses and punch
    types, and how much ZKBioTime calculated recently.
  - Names, types and counts only: never a name, employee code, date or text of an employee.
    Super users only; at most 60 runs per hour; each run is in the audit log.

## Requirements

| Requirement | Version |
|---|---|
| Joomla | 6.0 or later (the installer checks it; developed and tested on 6.2) |
| PHP | 8.3 or later, with OpenSSL (AES-256-GCM) and pdo_pgsql |
| Database | MySQL / MariaDB or PostgreSQL |
| ZKBioTime | 9.5 on PostgreSQL, its database reachable from the Joomla web server (docs/DATABASE.md) |

The installer refuses to install with a clear message when PHP, Joomla or OpenSSL is too old.

## Installation and updates

1. Download `com_biotime.zip` from the latest release on the
   [Releases page](https://github.com/aboarken/joomla-biotime/releases).
2. Joomla Administrator → **System → Install → Extensions** → upload the package.
3. Open **Components → ZKBioTime → Integration Settings**.

Updates appear under **System → Update → Extensions** (update site "ZKBioTime"). Installing a
newer package over the existing one also works; settings and mappings are kept.

## Configuration

### 1. Read-only database user

Create a PostgreSQL user for the portal that may only read **the 23 tables it uses**, plus eight
non-secret columns of ZKBioTime's users table `auth_user` for the Manager Dashboard (never the
password); step by step, with the exact `GRANT` statements, in
[docs/DATABASE.md](docs/DATABASE.md). The portal reads through this one user and enforces on
the Joomla server that each employee only ever sees their own records.

### 2. Integration Settings

| Section | Setting | Notes |
|---|---|---|
| ZKBioTime Database | Database, Server, Connect via, Database name, User, Password, Encryption (SSL), CA file | See [docs/DATABASE.md](docs/DATABASE.md). The password is encrypted (AES-256-GCM) before it is stored and never shown again: the page only says *Stored (encrypted)* or *Not set*. The portal pages need PostgreSQL. |
| | Duration unit | Automatic by default. |
| | Connection timeout, Query timeout | Seconds; connection ≤ query. |
| Employee Mapping | Employee code user field | Default `employeeid`; see *Employee mapping* below. |
| | Match by e-mail when the field is empty | On by default. |
| | New users | **Wait for administrator approval** (default): a user found by e-mail waits (*Waiting for approval*) until you **Confirm** them in User Mappings; Joomla does not verify e-mail changes, so someone could enter a colleague's address before their first visit. **Approve automatically**: the match works at once. Users waiting are approved on their next visit once this is switched to automatic. Either way a later e-mail change suspends the mapping, and matches by the employee code field never wait. |
| Report Letterhead | Logo, Header text left / right, Footer, Main colour | The letterhead of **Export PDF** (Manager Dashboard). All optional and empty by default: the report always shows the site name at the top. Logo: an image from the Media Manager (PNG, JPG, GIF, WebP or SVG in `images/`), shown above the site name. Footer: up to 6 lines. |
| System | Detailed logging | See *Troubleshooting*. |
| | Cache time (minutes) | 0–60, default 5; see *Cache*. |
| | Date format, Time format | How the portal shows dates and times. |
| | ZKBioTime server time zone | Default: Joomla's server time zone. |
| | Weekend days | Default Friday and Saturday. A weekend day without punches is *Weekend*, not *Absent*; with a punch it is shown like any other day. |

Settings are stored in the component's own table `#__biotime_settings`, not in Joomla's
component options, because Joomla's Options page replaces every option on save and the database
password must be encrypted first. *Options* holds only the permissions.

**Encryption secret.** The database password is encrypted with a key derived from the
environment variable `BIOTIME_PORTAL_SECRET` (recommended, at least 32 random characters) or,
when that is not set, from the `secret` in Joomla's `configuration.php`. The page shows which one
is in use. If it changes, enter the database password again.

**Test Database** connects with the saved settings and reports the server version, whether the
connection is encrypted, whether the user can only read, and which tables it may read (names
only, never data). At most 20 tests per administrator and hour.

### 3. Employee mapping

Every Joomla user is mapped to exactly one ZKBioTime employee (`#__biotime_usermap`). On a
user's first visit:

1. **Employee code field (first choice).** If the Joomla user has the custom user field
   `employeeid` (Users → Fields; the name is a setting) filled in, it must equal exactly one
   employee's `emp_code`. If no employee has that code, the user is *not found*; e-mail is not
   tried, so a wrong code never maps someone else.
2. **E-mail (when the field is empty).** The one employee with exactly the same e-mail address
   (case-insensitive). Several matches are *ambiguous*, none is *not found*.

> **Important:** users must not be able to edit the `employeeid` field themselves. In the
> field's settings, allow editing only for administrators (*Users → Fields → employeeid →
> Permissions / Options*); otherwise a user could enter a colleague's code. Since 0.4.7 the
> portal checks this: while the field is shown and editable to a (non-administrator) user, that
> user is *not found* and a warning is logged. When the field (or, for an e-mail match, the
> e-mail address) changes, the mapping is **suspended** until an administrator confirms it. An
> unmatched user who changes their e-mail address and is then matched by it is also held as
> suspended for an administrator (0.4.7).

Unmatched users see "Your account could not be matched to a ZKBioTime employee — please contact
the System Administrator", not an error. In **User Mappings** an administrator can map them by
employee code, **Confirm** a suspended mapping, **Suspend** one, or **Re-match** (delete it so
matching runs again). One employee can never be mapped to two Joomla users. Mappings are
re-verified against ZKBioTime's database once a day. Every administrative change is recorded in
`#__biotime_audit`.

### 4. Calculated attendance (schedules, late-in, early-out, ...)

Each day's schedule, late-in, early-out, worked time, overtime, holidays, leave and days off are
ZKBioTime's own calculation, read from its database; Joomla never calculates them. A day shows
late-in and early-out once ZKBioTime has calculated it (Auto Calculate, e.g. nightly); today and
later days show their schedule from the employee's shift. Details:
[docs/DATABASE.md](docs/DATABASE.md).

### 5. Manager Dashboard access

Give the managers' Joomla group the permission *View Manager Dashboard*; ZKBioTime decides which
departments each manager sees (1.0.8). ZKBioTime has **employees** (the people who punch) and
**users** (the accounts that log in to ZKBioTime, `auth_user`). The portal finds the manager's
ZKBioTime user by, in order:

- the user's employee link (`emp_pin`) or **username** equal to the manager's **employee ID**;
- the user's **e-mail** or **username** equal to the **e-mail of the manager's employee record**
  in ZKBioTime (a username that is an e-mail address, with the e-mail field empty, also works).

Only active, not deleted users count. A **superuser** or a user that may manage all departments
sees every department; any other user exactly the departments of its *data permissions*
(`auth_user_auth_dept`). The Joomla profile e-mail is never used (users can change it). The
departments and team are kept for the cache time, so a change in ZKBioTime shows within it.
When a manager sees "no access", `administrator/logs/com_biotime.php` says why (no names).

### 6. Permissions

All administrator pages need **core.admin** (Super User level) on the component.

Each portal page has its own permission. **None is granted by default.** Under
**Components → ZKBioTime → Options → Permissions**, set them to *Allowed* for the **Registered**
group (or the group your employees are in). Without them users get "You are not authorised"
(HTTP 403).

| Permission | Page |
|---|---|
| `zkb.dashboard.view` | My Dashboard |
| `zkb.attendance.view` | My Attendance (and day details) |
| `zkb.calendar.view` | Attendance Calendar (and day details) |
| `zkb.punches.view` | My Punches |
| `zkb.manager.view` | Manager Dashboard (give it only to the managers' group) |
| `zkb.leaves.view` | My Leaves |
| `zkb.approvals.view` | My Approvals (give it to the approvers' group) |

My Dashboard also opens day details. Updating from 0.3.x moves menu items of the removed My
Schedules, Attendance Reports and My Profile pages to the trash; updating from 0.4.0 or 0.4.1 turns
Biometric Devices menu items into My Punches and moves its permission to *View My Punches*.

## Cache and performance

What the portal reads from ZKBioTime is kept per user in the component's own table
`#__biotime_cache`, so My Dashboard, My Attendance, the Calendar, day details and My Punches share
it, and it survives logging out. Each entry belongs to one Joomla user and one employee code.

- **The current month** is kept for the *Cache time* (default 5 minutes), so a new punch appears
  within that time.
- **Past months** are kept for **24 hours**: their punches no longer change. (Only while the cache
  is on; *Cache time* 0 turns everything off.)
- **Pages open at once (1.2.6; every portal page since 1.2.8).** When the data a page was last
  shown with is older than the cache time, the page opens at once with it and the note *"Showing
  your data from Saturday, 21 November 2026 13:25. Updating…"* above the title; it reads current
  data in the background and replaces itself. If the user has started choosing a filter meanwhile,
  a *Reload the page* link is offered instead; if the update fails, the note says so. Data older
  than **7 days** is never shown this way. Without JavaScript the page reloads at once with
  current data.
  - **My Leaves and My Approvals** keep their lists for the cache time (1.2.8).
  - **Manager Dashboard** (1.2.8): each employee's attendance is kept in the manager's cache, so
    a team page the manager has seen opens at once. *Which* employees a manager may see (their
    departments and team) is never taken from an older copy. The printed report and the PDF always
    read current data.
- Expired entries are deleted automatically 7 days after their time; the table holds only what was
  read recently (a few KB per employee and month, a few MB for a whole school).
- **One connection, kept open**: all queries of a page share one read-only connection to
  ZKBioTime's database, opened when first needed and kept open between pages (a persistent
  connection, 1.0.10), so a page does not wait for a new encrypted connection. A closed one is
  replaced once automatically.
- **Manager Dashboard**: the employees not in the cache are read together in a few queries (not
  per employee); the manager's departments and team list are kept for the cache time.
- **Daily employee check**: once a day each user's mapping is checked against ZKBioTime. If
  ZKBioTime does not answer, the check is tried again after an hour, not on every page.

With *Detailed logging* on, each page's log line ends with `database: connect X ms, N queries,
Y ms; page: Z ms`, to see where the time goes.

Database reads per page load, for one month (cold cache; a warm cache reads **nothing**):

| Page | Reads |
|---|---|
| Any page, first visit of a user | Employee code field: 1 query by code. E-mail: 1 query of the employees with an e-mail address. Then none while the mapping is active (1 per day to re-verify). |
| Dashboard, My Attendance, Calendar, day details | the punches and the day rows of each month shown. Past months: none for a day after the first read. |
| My Punches | each month of the year up to today on the first load of the day; then only the current month. |

## Security

The whole repository was audited again at 1.3.6 (architecture, Joomla conventions, security,
data layer, build and CI; fixes in 1.3.7), after a first audit at 1.0.17. The findings by
severity, what was changed, the tests run and the remaining risks are in
[docs/AUDIT.md](docs/AUDIT.md).

To report a security problem, see [SECURITY.md](SECURITY.md) (privately, through the repository's
**Security** tab; never in a public issue). The release workflow's actions are pinned to exact
commits and kept up to date by Dependabot (`.github/dependabot.yml`); changes to the workflow, the
build scripts and the bundled library are reviewed by the code owner (`.github/CODEOWNERS`).

- The administrator pages (settings, user mappings, diagnostics) need **Super User** rights
  (`core.admin` on the component), for every task, not only the page itself (0.4.7).
- When the database server, *Connect via*, database or user changes, the stored password must be
  typed again, so it is never sent to a new address.
- Every page needs a logged-in Joomla user and its own permission; every read takes the
  server-side mapping. Employee ids, codes or dates in a URL never choose whose data is shown;
  a record that is not the user's gives the same "not found" as a missing one.
- Every query filters by the one employee; punches of anyone else would still be dropped and a
  warning logged.
- The database password never reaches the browser (no JavaScript, HTML, session or cookie).
- Every state-changing action is a POST with a CSRF token and a permission check; database
  tests are limited per administrator and hour.
- Portal pages are `noindex, nofollow` and sent with `Cache-Control: no-store`.
- A manager may make at most 30 PDF reports an hour, each recorded in the audit log; a manager's
  departments are kept for at most 5 minutes, so a permission removed in ZKBioTime stops showing
  quickly. No portal page uses inline `style` attributes or scripts (strict CSP friendly).
- **New matches by e-mail wait for an administrator** unless *Integration Settings → New users* is
  set to *Approve automatically* (1.0.19; waiting by default since 1.0.10): Joomla lets users change
  their own e-mail without verifying it, so a match by e-mail is held as *Waiting for approval*
  until an administrator confirms it in User Mappings.
- The Manager Dashboard shows only what ZKBioTime grants the manager's own ZKBioTime user; filters
  in the URL can only narrow that list.
- The database user reads only the tables the portal needs, and of `auth_user` only eight
  non-secret columns; only reading statements are ever sent (also inside `WITH`).
- Input is checked against strict patterns and whitelists; all output is escaped.
- The integration is read-only: the database connection is opened read only, and the user should only be allowed to read (Test Database checks it).

## Troubleshooting

`administrator/logs/com_biotime.php` records one line per page load saying what was read, hidden
or skipped and why, and every problem in plain words (with the database server's message).
Passwords and personal data are never written to it. With *Detailed logging* off, only warnings and errors are written.

| Message | Check |
|---|---|
| is not set up yet | Integration Settings → ZKBioTime Database: PostgreSQL, server, user and password saved; the encryption secret unchanged |
| could not be reached | Server and *Connect via*, firewall, SSL settings, timeouts; **Test Database** names the problem and the log has the database server's message |
| did not accept the portal's credentials | The database user and password (and `pg_hba.conf`) |
| is not available right now | The database name, and that the read-only user may read the tables (**Test Database**, or **Find Tables → Diagnostics**: each table the portal reads, found and readable or not) |
| could not find what was asked for | No (or more than one) employee in ZKBioTime has the user's mapped employee code |
| You don't have access to view departments (Manager Dashboard) | The manager's ZKBioTime user (see *Manager Dashboard access*), its data permissions, and the `auth_user` grant (docs/DATABASE.md); the log line says which one is missing |
| Colours or the letterhead missing in the PDF | Turn on *Background graphics* in the print dialog; set the logo and texts in *Integration Settings → Report Letterhead* |
| Your account could not be matched | **User Mappings**: the user's status (Not found / Ambiguous / Suspended) and employee code field |
| a working day shows Absent although the person was there | No punch was recorded that day (check the device in ZKBioTime), or the day should be a weekend day (*Weekend days*). A holiday shows as Holiday only when it is set up in ZKBioTime. |

## Development

- Every change goes through a pull request; the maintainer merges.
- Tests: `composer install`, then `vendor/bin/phpunit` (no ZKBioTime server needed). The live
  checklist for the real server: [docs/TESTING.md](docs/TESTING.md).
- Each version: bump `<version>` in `biotime.xml` and **every** `"version"` in
  `media/joomla.asset.json` (the file's and each asset's: it is in each file's address, so
  browsers load the new files after an update; a test and `build/verify-package.py` check it), add
  `administrator/sql/updates/{mysql,postgresql}/<version>.sql` (a comment when nothing changes),
  add a `CHANGELOG.md` entry and `docs/release-notes/<version>.md`.

### Releases

The **Release package** workflow (`.github/workflows/release.yml`) lints PHP, runs the tests,
builds and checks `com_biotime.zip`, and writes `updates.xml` (with SHA-256, target platform
Joomla 6 and minimum PHP 8.3) and `changelog.xml` (from `CHANGELOG.md`). To publish:
*Actions → Release package → Run workflow* on `main` creates release `v<version>` with the three
files and the release notes.

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).
