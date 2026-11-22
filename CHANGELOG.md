# Changelog

All notable changes to `com_biotime` are documented in this file.
Versions follow the component manifest (`biotime.xml`) and use [Semantic Versioning](https://semver.org/).

## [1.3.2] - 2026-11-22

### Fixed
- **Find Tables → Diagnostics: "Copy report" did not copy.** The button only attached itself when its script ran before the page was ready; when Joomla loaded the script later, clicking did nothing. It now attaches however the script is loaded, copies the selected report in the click itself (on http:// and https:// pages), falls back to the browser's Clipboard API, and says whether it worked: *Copied to the clipboard.*, or, when the browser does not allow copying, leaves the report selected for Ctrl+C.

### Added
- **Download report**: saves the Diagnostics report as a text file (`biotime-diagnostics-<date>.txt`), which works even where the browser blocks the clipboard.

## [1.3.1] - 2026-11-22

### Fixed
- **Find Tables showed `auth_user` as "NOT READABLE"** although the portal reads it. On a correctly set-up server only eight of its columns are granted, never the password hash (docs/DATABASE.md); Find Tables only checked whether the whole table could be read. A table with some columns granted is now shown as **Some columns** (in the list, the table check and the Diagnostics report), and its granted columns are counted. A column that is not granted is marked *not granted* and is never asked for: the check reads the granted columns by name, never `*`.

## [1.3.0] - 2026-11-22

### Added
- **Find Tables** (Components → ZKBioTime → Find Tables, super users only): find the tables of ZKBioTime's database and what they hold, like *Find Endpoints* in the ServiceDesk Plus portal. Read only, with the portal's read-only database user.
  - **Diagnostics**: one click writes a report to copy into a support request: the portal's settings (no address, user or password), the connection (encrypted, read-only, tables it may change), each table the portal knows (found, readable, about how many rows), the codes this server stores for request and stage statuses, punch states, verification types and pay code types, how many days ZKBioTime calculated in the last 31 days, and the names of the other tables.
  - **1. Tables**: the 32 tables the portal knows, with what they hold and which page reads them (plus the workflow tables the planned Leave Request will need), and every other table the database user can see; readable or not, and about how many rows (the database's own estimate, no row read). Search filter.
  - **2. Check a table**: its columns and types (required or not), primary and foreign keys with a link to the table each key points to, the tables that point to it, how many rows it has, how often each column is filled (in up to 10,000 rows) and, for code columns only (status, type, state, flag…), which values are used and how often.
  - No personal data is shown: names of tables and columns, types and counts only; values only for code columns, never a name, employee code, date or text. Table names are checked against the database's own catalogue. At most 60 runs per administrator and hour; every run is in the audit log.

## [1.2.9] - 2026-11-22

### Changed
- **My Attendance: pages of days.** A long period (From – To up to a year) is no longer one very long table: it shows **31 days per page** with *Previous*, *Page 2 of 12* and *Next* under the table, as on the Manager Dashboard. A month always fits on one page. The cards and the day count above the table still count the whole period; choosing a card, month or date starts again at page 1.

## [1.2.8] - 2026-11-21

### Changed
- **Every portal page opens at once while it updates.** The *"Showing your data from … Updating…"* behaviour of 1.2.6 now also covers **My Leaves**, **My Approvals** and the **Manager Dashboard**.
  - My Leaves and My Approvals keep their lists for the cache time (they were read on every visit).
  - Manager Dashboard: each employee's attendance is kept in the manager's own cache (as on the employee pages), so a team page the manager has seen opens at once and updates in the background; only the employees not in the cache are read, together in a few queries. **Which employees a manager may see is never taken from an older copy.** The printed report and the PDF still read every employee from ZKBioTime.
  - The first visit of a large team takes slightly longer than before (each employee's month is stored once); later visits open at once.

## [1.2.7] - 2026-11-21

### Changed
- **Calendar legend:** *Rest Day* is listed only in a month that has one. A rest day is a weekday without punches on which the employee's ZKBioTime shift has no working hours (e.g. part-time staff); most staff never have one, so the legend no longer shows it for them.

## [1.2.6] - 2026-11-21

### Added
- **Pages open at once while they update.** My Dashboard, My Attendance, the Calendar, day details and My Punches no longer wait for ZKBioTime when the data they were last shown with is older than the cache time: they open at once with it, with the note *"Showing your data from Saturday, 21 November 2026 13:25. Updating…"* above the title, read current data in the background and replace their content (as in the ServiceDesk Plus portal).
  - If the user has started choosing a filter meanwhile, the page is not replaced: *Newer data is available. Reload the page* is shown.
  - If the update fails or ZKBioTime is unavailable, the page stays and the note says *Could not update just now*.
  - If the page is no longer the user's (signed out, no longer matched, no permission), it is loaded normally, so older data is never kept.
  - Data older than 7 days is never shown this way. The Manager Dashboard, My Leaves and My Approvals always read current data. Without JavaScript the page reloads at once with current data.

### Changed
- Cache entries are kept 7 days past their time (for the above) before they are deleted, and now store when they were read. Entries written by older versions are still used until their time ends.

## [1.2.5] - 2026-11-20

### Changed
- **My Punches:** the section titles *Punches per Month* and *Punches per Device* no longer end with the year (the year is chosen in the toolbar). When one month is chosen, *Punches per Device* names that month (e.g. "Punches per Device, November").

## [1.2.4] - 2026-11-20

### Changed
- **My Punches:** the cards (Total Punches, Fingerprint, Face, Devices Used, Days with punches) no longer show the year under the value; the year is chosen in the toolbar above them. When one month is chosen, its name is still shown.

## [1.2.3] - 2026-11-20

### Changed
- **My Dashboard charts: every month shows its year.** Under the columns of *Missing Punches per Month*, *Total Late In and Early Out per Month* and *Average Check-In and Check-Out per Month*, the year is written under each month's name (Aug / 2026 … Jan / 2027), since a school year runs over two years. Twelve months have no room for the year beside the name.

### Fixed
- **A month's column was not above its name** in *Total Late In and Early Out per Month* (and *Missing Punches per Month*) when the month had only one of the two values (for example late in but no early out): the column stood to the left of the name. A single column is now centred above its month; two stay side by side around it.
- *Attendance per Month* (and *Punches per Device*): a little more space above the legend (Below 70 %, 70 % to 94 %, 95 % and above), like the other cards.

## [1.2.2] - 2026-11-20

### Changed
- **PDF report:** Late In, Early Out and Total Exceptions (titles and values) are aligned to the left, like the date columns.

## [1.2.1] - 2026-11-19

### Fixed
- **PDF report: the last three columns (Late In, Early Out, Total Exceptions) were wider than the others.** The table now has fixed column widths: the employee column and the three totals take a set share of the page (A4: 14 % and 6.5 % each; A3: 10 % and 3.4 % each) and the days share the rest equally. Long names wrap inside their column; a date that does not fit on one line breaks after a separator (2026-10- / 24), never inside a number.

## [1.2.0] - 2026-11-19

### Changed
- **Export PDF (A4) and Export PDF (A3) download a finished PDF file**, made on the server: no print dialog, so no printer to choose. Printing through Windows' *Microsoft Print to PDF* turned the landscape report into portrait pages with the table sideways; the file is now always A4 or A3 **landscape**, on any browser and computer. It has the same content as before: letterhead (site name, logo and header lines), title band, filters, cards and table in the page's colours, the footer and the page number on every page, the header row repeated on each page. Names and texts in Arabic are shown correctly.
- The PDF is made by **mPDF 8.3** (GPL-2.0), bundled with the extension in `administrator/vendor` with only one font family (about 9 MB; `build/vendor-pdf.sh` updates it). The largest report (300 employees × 31 days on A3) takes about 10 seconds and 160 MB; the time and memory limits are raised for it, and PHP's text-matching limit for that report only.

## [1.1.2] - 2026-11-19

### Fixed
- **Export PDF (A4 and A3) printed the site's cookie notice and pop-ups, and hung before the print dialog.** The report opened inside the site template (`tmpl=component`), so the template, its modules and system plugins (cookie consent, pop-ups) and their scripts came with it. It now opens on a page of its own (`format=raw`) that holds **only the report**: the portal's stylesheet, the report and its print script. The print dialog opens as soon as the report and its fonts are ready (about a third of a second in tests), and the saved PDF has the report's information only.
- The note above the report mentions A4 or A3 landscape (it still said portrait or landscape).

### Security
- Only the Manager Dashboard's report can be asked for as such a page; any other `format` gives "not found". The page's small inline style (paper and colour) carries the Content Security Policy nonce when Joomla's HTTP Headers plugin sets one.

## [1.1.1] - 2026-11-19

### Fixed
- **My Leaves and My Approvals showed approved leave as Rejected.** ZKBioTime stores an approved request as 2 (checked on a real server), not 1: statuses are now 1 Pending, 2 Approved, 3 Rejected, 4 Revoked. The cards (Pending, Approved, Rejected, Approved Days, Approved/Rejected by You) count with the same numbers.
- A finished request (approved, rejected or revoked) never shows a stage as current or as waiting in My Approvals, even when ZKBioTime left that stage's row active; such an undecided stage shows as *Not decided* (it showed "Status 7").

## [1.1.0] - 2026-11-19

### Added
- **My Leaves** (menu item type, permission *View My Leaves*): the employee's own leave requests from ZKBioTime, newest first, with the leave type, dates, days, reason, the attachment's file name, the status and **every approval stage** (its status, who decided and when, the remark; the stage the request is at is marked). Cards: requests, pending, approved, rejected, approved days.
- **My Approvals** (menu item type, permission *View My Approvals*): for approvers, the leave requests **waiting at their stage** in ZKBioTime (the stage lists one of their ZKBioTime approver roles, or was sent to them; never their own), the longest waiting first, then the stages they **approved or rejected**. Cards: waiting, decided, approved, rejected.
- Both pages are **read only**: requests are made and approved in ZKBioTime, where the workflows, stages and approver roles are set up. A *Leave Request* page is planned.
- Test Database lists the leave and approval tables. **The portal's database user needs read access to six more tables** (docs/DATABASE.md); until then both pages say the requests cannot be shown yet.

## [1.0.20] - 2026-11-19

### Fixed
- **Integration Settings → New users**: "Wait for administrator approval" was too long for Joomla's switch, so its last word ran over the description below it. The switch stays; its text is kept on one line next to it (slightly smaller on very narrow screens).

## [1.0.19] - 2026-11-19

### Added
- **Integration Settings → Employee Mapping → New users**, as in the ServiceDesk Plus portal: **Wait for administrator approval** (the default, as before) or **Approve automatically**. With automatic approval a user found by e-mail can use the portal at once. Users waiting when it is switched to automatic are approved on their next visit, after a fresh check that the address still belongs to exactly one employee. Either way a later change of the Joomla e-mail suspends the mapping, and matches by the employee code field never wait.
- **User Mappings: status *Waiting for approval*** for users found by e-mail and waiting for an administrator (they were listed as *Suspended / waiting for approval*); *Suspended* is now only for mappings stopped by a change (e-mail, field, employee) or by an administrator. Users already waiting are moved to the new status on update.

## [1.0.18] - 2026-11-18

From an audit of the whole component against the Joomla Manual and for security (docs/AUDIT.md): no critical, high or medium security finding; the following was tightened or tidied.

### Security
- **PDF reports are limited to 30 per manager and hour** and each is recorded in the audit log (`manager.pdf`): a report reads the whole team without the cache.
- **A manager's departments and team are kept for at most 5 minutes** (was the cache time, up to 60 minutes), so a permission removed in ZKBioTime stops showing quickly.
- **Only the Manager Dashboard's `print` layout can be requested**; any other `layout` in a URL is ignored.
- The stored report logo path and colour no longer accept a trailing line break.

### Changed
- **No inline `style` attributes** on any portal page (month arrows, chart legends and bars now use a CSS class and SVG attributes), so the portal works under a strict Content Security Policy.
- User Mappings filters use Joomla's `js-select-submit-on-change` instead of inline `onchange`.
- The release workflow lints and tests with PHP 8.3 explicitly.

### Removed
- **Integration Settings → Modules → Charts**: the switch did nothing since 0.9.0; its stored value is deleted on update.
- A session clean-up for versions before 0.4.6, and 17 unused language strings.

## [1.0.17] - 2026-11-18

### Changed
- **Manager Dashboard export buttons**: *Export PDF (A4)* first, then *Export PDF (A3)*, A4 in the portal's blue and A3 in green; the note beside them says what each paper does (A4: up to 12 days per table, a longer period in parts; A3: the whole period in one table, one row per employee).

## [1.0.16] - 2026-11-18

### Added
- **Manager Dashboard: Export PDF (A3) and Export PDF (A4).** A3 landscape puts the whole period (up to 31 days) in **one table, so every employee is one row**; with more than 12 days its text is a little smaller. A4 landscape works as before (up to 12 days per table, a longer period in several parts). The two buttons replace the single *Export PDF*.

## [1.0.15] - 2026-11-18

### Added
- **My Dashboard: Missing Punches per Month** replaces *Status per Month* (second card, next to Total Attendance). It is drawn like *Total Late In and Early Out per Month*: two columns per month, the days with a **missing morning punch** (no check-in, teal) and with a **missing afternoon punch** (no check-out, indigo), with the school year's totals in the legend.

### Changed
- **My Dashboard: Employee Information** (department, position, employee ID, name, period) is now a panel like *Today*: a title, then each label in small capitals above its value (in the portal's blue), side by side (two per row on a phone), instead of a one-row table.

### Removed
- The *Status per Month* chart (present, absent, holiday and leave days per month); Total Attendance still shows the year's split.

## [1.0.14] - 2026-11-18

### Changed
- **Export PDF is always A4 landscape** (was portrait for up to 6 days). Periods longer than 12 days are still split into tables of at most 12 days.
- **The PDF shows the site name at the top** (Joomla's Global Configuration → Site Name) in every case, then the *Attendance Report* title band; a logo set in Report Letterhead, if any, goes above it. Nothing about a particular organisation is built in: the logo, header lines and footer stay empty until an administrator fills them in.
- **Cards: Total Late In and Total Early Out show only the time**, without the number of days, on My Dashboard, My Attendance, the Manager Dashboard and the PDF.

## [1.0.13] - 2026-11-18

### Changed
- **My Dashboard charts renamed**: Total Attendance (was Days so far), Status per Month (was Days per month), Total Late In and Early Out per Month, Average Check-In and Check-Out per Month, Attendance per Month (was Attendance rate per month), Punches per Device (was Your Devices). The *Statistics* heading above them is gone.
- **My Dashboard cards without notes under the numbers** (no "earliest/latest", hints or day counts below the value), so they are the same size as on My Attendance; Total Late In and Total Early Out show their days next to the time, as on My Attendance.

### Fixed
- **Charts on phones**: the axis text was about 5 px tall. On screens narrower than 600 px it is now drawn larger (about 11 px), every other month is named when more than 6 are shown, the rate and device bars put the name above the bar, and the cards use less padding.
- A chart axis no longer repeats the same value (e.g. 0:01, 0:01, 0:01) when the totals are very small: its steps are at least one minute or one day.

## [1.0.12] - 2026-11-18

### Changed
- **My Dashboard → Statistics: each chart is its own card**, as on the ServiceDesk Plus portal's dashboard: a white panel with a shadow, the chart's title in its header with a line under it, and the chart and legend below. The box around the whole Statistics section is gone; *Statistics* is a plain heading above the cards.

## [1.0.11] - 2026-11-18

### Added
- **Manager Dashboard: Export PDF.** A button under the table (left) opens the report in a new tab with the browser's print dialog ("Save as PDF"). The report has the organisation's letterhead (logo, a line of text on each side under it, footer lines on every page with links), a title band with the period, the filters used, who printed it and when, the same cards and table in the page's colours, page numbers, and **every employee**, not only the 50 of one page. **A4 portrait** when the dates fit (up to 6 days), **landscape** otherwise, with the dates in tables of at most 12 days (employee column repeated, totals in the last one); the header row repeats on every page and a row is never split.
- **Integration Settings → Report Letterhead**: logo (an image of the Media Manager, below `images/`), header text left and right (right-to-left text supported), up to 6 footer lines, main colour.

### Fixed
- **Manager Dashboard: white space under the site footer** once data showed. With templates that position the page's content, the hidden screen-reader labels of the times in rows scrolled out of the table's box made the page as tall as the whole table. The table's box now contains everything inside it.

### Changed
- README updated to the current features (cards, statistics, Manager Dashboard access by ZKBioTime users, Export PDF, approval of new matches, database grants, performance).

## [1.0.10] - 2026-11-18

### Security
From a security review of the whole component:
- **The Manager Dashboard no longer uses the Joomla profile e-mail** to find the manager's ZKBioTime user. A user can change that address themselves, so a manager could have chosen the address of a ZKBioTime administrator and seen every department. Only what ZKBioTime holds links them: the employee ID (`emp_pin` or username) and the e-mail of the employee record.
- **Database user: only the tables the portal reads** (docs/DATABASE.md), no longer every table: ZKBioTime's sessions, API tokens and settings stay out of reach; `auth_user` keeps its column-only grant.
- **Changing the database SSL mode or CA file asks for the database password again**, as changing the server does, so a weaker encryption cannot be set while the stored password is kept.
- **Portal log lines of database errors hold the error kind and SQLSTATE only**, not the driver's text (which can name the database user or server); Test Database still shows the full text to the administrator.
- **Only reading statements are sent to the database**, also inside a `WITH` (defence in depth: the session is read-only anyway).
- **Every new match by e-mail waits for an administrator.** Joomla lets users change their own e-mail without verifying it, so someone who had never opened the portal could set a colleague's address and be matched to that colleague. Now the employee found by e-mail is stored as *Suspended / waiting for approval*; the user sees "…waiting for an administrator to approve it"; an administrator approves it in User Mappings (select, **Confirm**; several at once). Employees already matched are not affected; matches by an employee-code field set by an administrator, and mappings an administrator makes, are not held.

### Changed
- **Faster pages: the connection to ZKBioTime's database stays open between pages** (a persistent PDO connection), so a page no longer waits for a new encrypted connection to the BioTime server. A connection found closed (e.g. after PostgreSQL restarted) is replaced once by a fresh one and the query runs again. Test Database still opens a new connection. On the BioTime server this keeps up to one idle connection per PHP worker of the Joomla site open.
- **Faster Manager Dashboard: the manager's departments and team list are kept for the cache time** (Integration Settings → Cache time) instead of being read from ZKBioTime on every page and every department chosen. A change of a manager's permissions in ZKBioTime shows within that time; "no access" is never kept.
- **"Total Exceptions"**: the column of late-in + early-out is named Total Exceptions on My Attendance (was Total Late) and on the Manager Dashboard (was Total).
- **Manager Dashboard: Workforce is the selected card by default** and clears the status filter; All Days is a plain count there. (My Dashboard and My Attendance keep All Days.)

## [1.0.9] - 2026-11-18

### Fixed
- **Managers whose ZKBioTime user logs in with an e-mail address saw "You don't have access to view departments"**: such users have the e-mail address as their username and an empty e-mail field. A ZKBioTime user now also matches when its **username** is the employee's or the Joomla account's e-mail address.
- **Manager Dashboard slow over two months**: when the period crossed a month (e.g. 25 October to 5 November), reading each employee replaced the team's read-ahead rows, so every employee was read again on their own (about fifteen queries each). The team's rows are now kept.

### Changed
- **Manager Dashboard without Joomla's cache**: the team is read in a few queries, so each employee's months are no longer written to and read from `#__biotime_cache` on every view (hundreds of queries for a large team).
- **Why a manager sees "You don't have access to view departments"** is written to `administrator/logs/com_biotime.php` (without names): no single ZKBioTime employee, no matching ZKBioTime user, a user without departments, or ZKBioTime's users not readable.

## [1.0.8] - 2026-11-18

### Changed
- **The Manager Dashboard follows ZKBioTime's user permissions**: a manager needs only *View Manager Dashboard*. The portal finds the manager's own ZKBioTime **user** account (ZKBioTime keeps users apart from employees): the active, not deleted user linked to the employee ID (`emp_pin`), whose username is the employee ID, or with the employee's e-mail (or the Joomla e-mail). A ZKBioTime **superuser** or a user who may **manage all departments** sees every department; any other user sees exactly the departments of their data permissions (`auth_user_auth_dept`). Departments ZKBioTime names them Department Manager of (with those below) still count too. Only `id, username, email, emp_pin, is_active, is_superuser, can_manage_all_dept, del_flag` of `auth_user` are read; see docs/DATABASE.md for the column-only grant (the password column stays unreadable). Without that access, a warning is logged and only Department Managers count.
- **The *View all departments* permission is removed**: Options → Permissions has only *View Manager Dashboard* for it. Who sees every department is decided in ZKBioTime (superusers and users who may manage all departments), also for Joomla Super Users.
- **Manager Dashboard**: Workforce is the first card, then All Days (still selected by default); the Employee ID field's example reads "e.g. EXXXX, then Enter".

## [1.0.7] - 2026-11-18

### Added
- **My Dashboard → All Days** card, first, as on My Attendance: present + absent + leaves + holidays + weekends of the school year so far. It opens My Attendance with every day of the period.
- **Manager Dashboard → All Days** card, first: the days of everyone shown; it clears the status filter (this was Workforce's job, which now only counts the employees).

### Changed
- **All Days is the selected card by default** on My Dashboard, My Attendance and the Manager Dashboard (highlighted until another card is chosen).

## [1.0.6] - 2026-11-18

### Changed
- **Cards in the same order on My Dashboard, My Attendance and the Manager Dashboard, grouped by what they count**: days (All Days or Workforce, Days Present, Days Absent, Leaves, Holidays, Weekends, Weekend Days Worked, Attendance Rate), punches (Missing Morning, Missing Afternoon, Total Morning and Afternoon Punches, Punches), times (Average Check-In, Average Check-Out), lateness (Total Late In, Total Early Out, Late In + Early Out) and work (Worked Time, Overtime). A page without a card skips it.

## [1.0.5] - 2026-11-18

### Changed
- **Every card has its own colour**, the same on My Attendance, My Dashboard and the Manager Dashboard (`Ui::cardTone()`); cards with 0 keep their colour instead of turning grey, and Attendance Rate has a fixed colour.
- **My Attendance → Total Late** column (late-in + early-out of the day) replaces Worked; Worked Time stays as a card and on the day details.
- **Manager Dashboard**: "All my departments" is now "All Departments".

## [1.0.4] - 2026-11-18

### Added
- **Manager Dashboard → Employee ID search**: type an ID (e.g. E993, or part of one) and press Enter; only the manager's own employees are searched.

### Fixed
- **"PHP regular expression limit reached (pcre.backtrack_limit)"** on the Manager Dashboard with many employees (e.g. View all departments): the page grew too large for Joomla's plugins, which scan the whole page. The table now shows 50 employees per page (Previous / Next; the cards still count everyone shown), and when more than 300 employees match, the page asks to choose a department or an employee, or to search an Employee ID, instead of reading them all.
- **Weekend schedules showed "00:00–—"**: ZKBioTime's weekend time cards hold only a midnight start. They now show "—", and a schedule is shown as times only when it has both a start and an end.

## [1.0.3] - 2026-11-18

### Added
- **Leaves** card (My Attendance, My Dashboard, Manager Dashboard) and Status filter: the leave days so far (leave from ZKBioTime's pay codes, e.g. Annual Leave). Clicking it shows those days.
- **Weekends** card (same pages) and filter: weekend and rest days without punches so far; with Present, Absent, Leaves and Holidays it adds up to All Days.
- **View all departments** permission (`zkb.manager.all`): with View Manager Dashboard, the Manager Dashboard shows every department, whoever is its Department Manager in ZKBioTime. Super Users always have it. (ZKBioTime's own administrator accounts cannot be used: they live in its login table, which the portal's database user is kept out of, and they are not linked to employees.)

### Changed
- **Days Present is present / (present + absent)**: leave days no longer count as working days, so they no longer lower Days Present or the Attendance Rate.
- **All Days** is the sum of the day categories so far: Present + Absent + Leaves + Holidays + Weekend.

## [1.0.2] - 2026-11-18

### Changed
- **Manager Dashboard follows ZKBioTime's Department Manager**: a manager sees only the departments ZKBioTime names them *Department Manager* of (`personnel_department.dept_manager_id`), with the departments below them, no longer simply their own department. Without any, the page says "You don't have access to view departments in the Attendance Management System. Please contact the System Administrator."
- **Manager Dashboard: Total Morning Punches and Total Afternoon Punches** cards (days with a check-in, days with a check-out).
- **Manager Dashboard: the table scrolls inside its own box** (at most 70 % of the screen tall), so the sideways scrollbar is always in view; the date row and the employee column stay put.
- **My Dashboard → Attendance rate per month**: a horizontal bar per month, like Your Devices: red below 70 %, yellow from 70 % to 94 %, green from 95 %.
- **Choosing another month, year, From or To date clears the chosen card** (Status), on My Attendance and the Manager Dashboard; the month arrows no longer keep it either.
- **Faster My Dashboard**: the months of the school year that are not in the cache are read together (about ten queries) instead of about fifteen queries per month.
- **The page's log line** (with Detailed logging on) now shows the time spent connecting to ZKBioTime's database, the number of queries and their time, and the page's total time, to see where a slow page spends it.

## [1.0.1] - 2026-11-18

### Changed
- **Manager Dashboard is much faster**: everyone shown is read from ZKBioTime's database in about ten queries for the whole page (punches, time cards, pay codes, worked hours, schedules), instead of about fifteen queries per employee and month; department and group shifts, shift details and holidays are read once per page. The figures are unchanged (a test compares both ways against the PostgreSQL fixture). No PostgreSQL setting needs changing for it.
- **Manager Dashboard: the dates are columns**: one row per employee, a column per date of the period with that day's check-in and check-out (or Absent, Weekend, Holiday, Leave), amber with the minutes when the day has late-in or early-out; then Late In, Early Out and Total for the period. The table scrolls sideways; the employee column stays in view.
- **My Dashboard → Late in and early out per month**: thicker bars, as wide as the check-in and check-out columns.
- **My Dashboard → Your Devices**: devices without punches in the school year are not listed.
- **My Dashboard → Period** shows 1 August to today (e.g. From 01/08/2026 to 18/11/2026); the school year still ends on 31 July.

## [1.0.0] - 2026-11-18

The first stable version: everything is read from ZKBioTime's database, and managers get their own dashboard.

### Added
- **Manager Dashboard** (new menu item type and permission *View Manager Dashboard*, `zkb.manager.view`): a manager sees every employee of their own ZKBioTime department and of every department below it (`personnel_department.parent_dept_id`), except those who have resigned (`personnel_resign`). Filters: department, employee, status, From and To (today by default, at most 31 days). Cards: **Workforce** and the figures of My Attendance for everyone shown; a card with a filter shows only the matching days. Below them one row per employee per day: **check-in and check-out in one column**, then **Late In**, **Early Out** and **Total**. A manager without a department in ZKBioTime sees a message instead.
- **My Dashboard → Attendance rate per month**: a small card per month with its rate (green from 90 %, amber from 75 %, red below) and "present of working days".
- **My Dashboard → Your Devices** (back, as a chart): every device the employee punches on or is registered on, with the school year's punches split by fingerprint and face.
- **My Attendance → All Days** card, first: shows every day again after choosing another card.

### Changed
- **The school year is always 1 August to 31 July** (e.g. 01/08/2026 to 31/07/2027; from 1 August 2027 automatically 01/08/2027 to 31/07/2028). My Dashboard counts its figures up to today.
- **Clicking a card** (My Dashboard, My Attendance, Manager Dashboard) jumps down to the matching records instead of the top of the page.
- **My Dashboard**: the circles chart (missing punches, weekend work, late and early days) is replaced by the two new blocks above.

### Removed
- **The ZKBioTime REST API integration.** The component reads only ZKBioTime's PostgreSQL database (read-only). Gone: the API connection, credentials, TLS pin and Daily Attendance Source settings, *Read attendance from*, **Test Connection**, **Read Certificate Pin**, **Find Endpoints** (page and submenu) and the API-only page variants. The update deletes the stored API settings, including the encrypted API password and cached token. **Test Database** and every database setting stay.
- **The explanatory note** under the portal pages (where the figures come from, how check-in and check-out are chosen, the weekend days) and the "Updated" time. Error messages stay.

## [0.10.0] - 2026-11-18

### Changed
- **My Dashboard → Statistics replaces Recent Activity**: charts of the cards' figures, drawn on the server as SVG (no script): *Days so far* (donut: present, absent, holiday, weekend, leave, with the attendance rate inside), *Days per month* (stacked columns), *Late in and early out per month* (grouped columns, database only), *Average check-in and check-out per month* (a floating column per month from the average check-in, teal, to the average check-out, indigo, with both times written) and *Missing punches, weekend work, late and early days* (circles: one coloured ring per figure, filled by its share of the working days, or of the weekend days for weekend work, with the number inside). Each mark shows its value on hover; legends name every colour; screen readers get the figures as text.
- **My Dashboard: the employee as a table row** under the heading: Department, Position, Employee ID, Name and Period (from 1 August to today). On phones, one line per column.
- **Plainer note under the pages**: "Your schedule, late in, early out, worked time and holidays come from the Attendance Management System; late in and early out appear once the system has processed the day. Check-in is your first punch of the day and check-out your last; a punch after your schedule's check-in window (after 12:00 on days without a schedule) counts as a check-out." and "Weekend days: Friday, Saturday. They show as Weekend unless you punched that day."
- **The portal pages name "the Attendance Management System" instead of "ZKBioTime"**: the note under the pages (schedule, late-in, early-out, worked time and holidays come from the Attendance Management System; late-in and early-out appear once the system has calculated the day), the error messages, the time zone note and the unmatched-account message. The administrator pages still name ZKBioTime.
- **My Attendance shows every day on one page**: a From – To range (up to a year) is no longer split into pages of 31 days.

## [0.9.0] - 2026-11-18

### Changed
- **My Dashboard shows the school year so far**: from 1 August (this year's, or last year's before August) to today. The month arrows and the month and year selection are gone; the range is shown under the heading, and every card opens My Attendance for the same range.
- **My Dashboard: no Monthly Overview and no Your Devices panel.** Your devices stay on My Punches.
- **My Dashboard cards, 5 per row**, with **Holidays** and **Weekend Days Worked** added (from the database: 15 cards — Present, Absent, Missing Morning, Missing Afternoon, Holidays / Weekend Days Worked, Rate, Average Check-In, Average Check-Out, Punches / Late In, Early Out, Late In + Early Out, Worked Time, Overtime). From the API: 10 cards (no holidays; the total time between check-in and check-out card is dropped, the average stays).
- **My Dashboard shows the employee ID and name** after the department and position under the heading (the name from ZKBioTime; with the API, the Joomla user's name).
- **My Attendance: From and To dates** below the month and year (up to 366 days). From alone shows that date to today. Choosing a month or year clears them. Replaces the single Date search; old links with ?date= still open that day.

## [0.8.0] - 2026-11-18

### Changed
- **My Dashboard → Your Devices (was Devices You Used) lists every device the employee uses**: every device they have punched on and every device they are registered on in ZKBioTime (`iclock_transaction`, `iclock_terminalemployee` + `iclock_terminal`), with the month's fingerprint, face and total punches; a device not used in the month shows 0. With the API, as before (the month's devices).
- **Schedules show only their times** (e.g. 07:30–15:30), without the timetable's name below them (e.g. "IN07:30-OUT15:30"), which repeated the same information; the name shows only when a day has no times.
- **Monthly Overview without the Missing Morning Punch and Missing Afternoon Punch tiles**: their days count as Present in the tiles and the bar; the two cards above stay.
- **No Missing Punch tag next to Present**: My Attendance, the day details, the calendar (tag, "!" marker and legend entry) no longer show it. A missing check-in or check-out still shows *Missing* in its column, and the Missing Morning Punch / Missing Afternoon Punch cards, filters and the Recent Activity note stay.
- **My Attendance without the Punches column**: each day's punches (time, state, verification, device) are on the day details page, opened from the date.

## [0.7.0] - 2026-11-18

Attendance from ZKBioTime's database, completed (0.5.0–0.6.3): schedules from the shifts, Late In and Early Out from ZKBioTime 9.5's pay codes, holidays, leave, missing morning and afternoon punches.

### Fixed
- **Past months showed no Late In and Early Out after updating to 0.6.3.** Past months are kept in the cache for a day, and the copies saved by 0.6.2 (before Late In and Early Out were read from the pay codes) were still shown. Database cache keys now carry a version, so an update always reads the months again.

### Changed
- **Device names without "Biometric - "**: e.g. "Biometric - Main Gate" is shown as "Main Gate" (punch details, Devices You Used, My Punches, day details).

## [0.6.3] - 2026-11-18

### Fixed
- **Late In and Early Out were empty with ZKBioTime 9.5.** ZKBioTime 9.5 keeps its calculation in the time cards and pay codes, not in `att_payloadbase` (empty there). They are now read from there:
  - **Late In** and **Early Out**: the minutes of the pay codes *Late In* (L) and *Early Out* (E) in `att_payloadpaycode`; nothing is calculated by the portal;
  - **Worked time**: the attendance code *Worked Hours* (`att_payloadattcode`);
  - **Schedule**: the timetable and scheduled times of the time card (`att_payloadtimecard`), with its check-in cut-off; days not calculated yet still come from the shifts;
  - **Absence**, **HOLIDAY** and **WEEKEND** pay codes; **leave** pay codes (e.g. *Annual Leave*) show the leave's name instead of Absent.
- Test Database → "Days calculated" counted only `att_payloadbase`; it now counts the time cards too.
- My Dashboard → Today no longer shows the schedule; it stays in Recent Activity, My Attendance and the day details.
- **Schedules showed 07:30–00:00** instead of 07:30–15:30: ZKBioTime stores 00:00 as a shift day's end when it is not set. An end that is not after the start is no longer used; the end is the start plus the timetable's length.

### Changed
- **Holidays are purple** (badge, Monthly Overview tile and bar, card, calendar, and the whole row in My Attendance and Recent Activity). Weekend Days Worked moves to sky blue so the two never look alike.

## [0.6.2] - 2026-11-18

### Changed
- **Schedules from the employee's shifts**: days ZKBioTime has not calculated yet (today and later) show their schedule too: a temporary schedule for the date, else the employee's shift, else the attendance group's or the department's (`att_tempschedule`, `att_temporaryschedule`, `att_attschedule`, `att_groupschedule`, `att_departmentschedule`, `att_attshift`, `att_shiftdetail`, `att_timeinterval`). A date in a shift without a timetable is a Rest Day.
- **Check-in and check-out by the time of day again (no first/last punch rule)**, with the cut-off from the day's timetable: the end of its check-in window (start + `in_above_margin`), else 12:00.
- **Late In and Early Out only from ZKBioTime's calculation** (`att_payloadbase`); nothing is calculated by the portal.
- **Colours:** weekends blue (rows, calendar, tile, badge); holidays sky blue, and a holiday's whole row in that colour like weekend rows.
- **Test Database → Shifts**: each shift's cycle and timetable per day number, and how many days ZKBioTime calculated in the last 31 days (settings and a count, no personal data), to check the schedules.

## [0.6.1] - 2026-11-18

### Changed
- **The database is the only source when attendance is read from it.** Joomla users are now also matched from ZKBioTime's database (`personnel_employee`): by the employee code field or by e-mail, the once-a-day re-check, and mapping by code in User Mappings. The employee ids are the same as the API's, so existing mappings stay valid. The API settings may be left empty; the API integration stays in the component (choose *Read attendance from: API* to use it). Attendance and employee lookups share one read-only connection per page load.

### Fixed
- **Test Database said "Read only by default: No" although it was set**, when the setting was made for the biotime database only (pgAdmin's Parameters tab with a database chosen, or `ALTER ROLE … IN DATABASE`). Such settings are now found as well.

## [0.6.0] - 2026-11-18

### Added
- **Attendance from ZKBioTime's database** (*Integration Settings → Database → Read attendance from: Database*). Every page then uses ZKBioTime's own calculation; the API stays configured and still matches employees.
  - **Schedule applied each day** (timetable name, start and end), **Late In** and **Early Out** per day, **Worked** time and overtime.
  - Monthly **Total Late In**, **Total Early Out**, **Late In + Early Out**, days late and early, worked time and overtime.
  - **Holidays** (for everyone, the employee's department or attendance group) and **days off** in the schedule are no longer shown as Absent.
  - Check-in and check-out are ZKBioTime's paired clock-in and clock-out. The 12:00 rule is no longer used with the database: a day ZKBioTime has not calculated yet (usually today) takes its first punch as the check-in and its last as the check-out. With the API the 12:00 rule stays (the API gives nothing else).
  - My Dashboard: today's schedule, late in and early out; 6 + 6 cards; Holiday in the Monthly Overview; Schedule, Late In and Early Out in Recent Activity; the employee's department and position.
  - My Attendance: Schedule, Late In, Early Out and Worked columns; 5 + 5 + 3 cards; filters Late In, Early Out and Holiday.
  - Attendance Calendar: late and early per day, Holiday and Rest Day in the legend. Day details: schedule, late in, early out, both together, worked, overtime.
  - Duration unit: Automatic (tells seconds from minutes per month), or set to minutes or seconds.
- **Missing Morning Punch** and **Missing Afternoon Punch** cards (My Dashboard and My Attendance, also with the API) instead of the one Missing Punch Days card: days present without a check-in, and without a check-out. Each opens My Attendance filtered to those days (new filters of the same names). Monthly Overview shows the same two tiles instead of one Missing Punch tile.
- **Clearer Monthly Overview colours:** Missing Morning Punch is orange and Missing Afternoon Punch pink, each with its own part of the bar in the same colour, so every tile has a colour of its own. On My Dashboard (database), Weekend Days Worked stays in the Monthly Overview, so the cards stay 6 + 6.
- docs/DATABASE.md: which table each value comes from.

### Notes
- Reading from the database needs PostgreSQL. Times stored with a time zone are shown in the ZKBioTime server's time zone.

## [0.5.1] - 2026-11-17

### Changed
- **Database (direct read): Server and Connect via, as for the API.** Host and port are now one field, *Server* (e.g. `zkb-server.example.local:7496`), like the API's Server URL. The new optional *Connect via* (PostgreSQL) is the IP address and port Joomla really connects to, e.g. the firewall's public IP, while the certificate is still checked against the *Server* name. Changing either asks for the database password again. Settings saved with 0.5.0 are kept.

## [0.5.0] - 2026-11-17

### Added
- **Direct read from ZKBioTime's database** (*Integration Settings → Database (direct read)*), for what the ZKBioTime 9.5 API does not give: schedules, calculated attendance, leave and holidays. This version adds the connection and **Test Database** only; the portal pages still read the API.
  - PostgreSQL (the database installed with ZKBioTime) and Microsoft SQL Server (UNVERIFIED).
  - Read only twice over: the database user should be read only (docs/DATABASE.md), and the component's connection is read only itself (PostgreSQL: `default_transaction_read_only`, query time limit; SQL Server: `ApplicationIntent=ReadOnly`). Only single SELECT statements can be sent.
  - Encryption: *Verify* (certificate checked, recommended), *Require* or *Off* (same network only).
  - The password is encrypted like the API password, never shown again, and must be typed again when the server, database or user changes. The `postgres` and `sa` administrator accounts are refused.
  - **Test Database** reads the database's catalogue only, never a row of data: server version, encryption, whether the user is an administrator or may change tables, the punch, employee and device tables, every attendance, personnel and device table with its column names, and a summary to copy. Limited to 20 tests per administrator and hour, and recorded in the audit log.
- docs/DATABASE.md: creating the read-only user in pgAdmin, network and SSL, the Joomla settings.

## [0.4.9] - 2026-11-17

### Fixed
- **Portal styles reached the rest of the page** on sites whose template puts the class `com-biotime` on the page `<body>` (e.g. Helix Ultimate and other templates that add the component name to the body): the portal's base styles (colours, word wrapping), the fallback heading and box-sizing rules and the pagination style applied to the whole page, including the site header, menus and modules. The portal content is now marked with its own `data-bt-root` attribute, and these styles are scoped to it.

## [0.4.8] - 2026-11-16

### Changed
- **My Dashboard → Monthly Overview:** the tiles and the colour bar now follow the same order: **Present, Absent, Weekend, Missing Punch** (then Weekend Worked). The amber Missing Punch part of the bar no longer sits next to the red Absent part.

## [0.4.7] - 2026-11-16

### Security
- **Administrator pages need Super User rights for every task.** A user with only *Access Administration Interface* (core.manage) on the component could open the User Mappings, Settings and Diagnostics pages through a task link. Every request to the component's administrator now needs core.admin, as the menu already did.
- **The employee code field must not be editable by the user.** If the field is shown on the user's own profile and they may edit it, the portal no longer trusts it: the user is *not found* and a warning is logged, so nobody can enter a colleague's code.
- **A match found only after an e-mail change waits for an administrator.** An unmatched user who changed their e-mail address and is then matched by it is now held as *suspended* until an administrator confirms the mapping.
- **Saved credentials never follow a new server address.** When the server URL or *Connect via* changes, the stored password or token must be typed again.

### Fixed
- My Attendance with an old link to the *Checked in* or *Holiday* filter showed an error page; those links now show all days.
- Months after the current one are no longer read from ZKBioTime (the → arrow stops at the current month, and a later month in a link shows the current month).

### Removed
- Unused code and strings: the "Incomplete" exception (replaced by Missing Punch in 0.4.3), the punch state code and device serial number (read but never shown), the "Punches" status fallback, the leave colour and symbol, unused figures in the monthly summary, unused helpers in the views, the identity resolver and the rate limiter, the cache *clear* method, and the session cache class (now only in the tests).
- Outdated text in the README, the API mapping, the testing checklist and the settings help (leave, devices list, schedules shown, "days attended").

## [0.4.6] - 2026-11-16

### Changed
- **Faster pages.**
  - **Past months are kept for 24 hours**: their punches no longer change, so after the first read of the day, moving to a past month or opening My Punches does not ask ZKBioTime again. The current month still refreshes after the *Cache time* (default 5 minutes), so new punches appear as quickly as before.
  - **Saved data in its own table** `#__biotime_cache` instead of the user's session: it survives logging out and is no longer loaded with the session on every page of the site. Expired entries are deleted automatically. Updating creates the table; the old session data is removed on each user's next visit.
  - **One connection per page load**: all requests of a page reuse one HTTPS connection to ZKBioTime (one handshake instead of one per request).

### Fixed
- **Every page slow when ZKBioTime did not answer the daily employee check.** The failed check was retried on every page load, and each attempt waited for ZKBioTime. It is now tried again after an hour.

## [0.4.5] - 2026-11-16

### Fixed
- **Date picker months**: clicking ← or → in the My Attendance date picker closed it, so it always reopened on the current month. It now stays open while you move between months, and opens on the month the page shows (e.g. October when October is selected) when no date is set.
- **"Unknown" removed from punch details**: devices record many punches with the state "Unknown" (state 255); that word is no longer shown in the punch list or the day details (the punch still counts as check-in or check-out by its time).

## [0.4.4] - 2026-11-16

### Added
- My Dashboard → **Recent Activity**: a **Note** column at the end shows *! Missing Punch* for days whose check-in or check-out is missing (the Status column keeps the status only).

### Removed
- **Leave Source** and the **Leave information** switch (*Integration Settings*), and *Save as Leave Source* in Find Endpoints: leave has not been shown since 0.4.2, but a configured leave source was still read from ZKBioTime on every page. Updating deletes the stored leave settings. "leave" is no longer a daily status or daily field.
- Code and strings left over from removed features: the leave record class, punch-state direction detection (the time of day decides check-in and check-out since 0.4.3), unused view helpers, the "Use for" list in Find Endpoints, unused styles (lists, pills, panel footers), the leave endpoint of the test server, and outdated items in the testing checklist.

## [0.4.3] - 2026-11-16

### Changed
- **Check-in before 12:00, check-out from 12:00.** The time of day now decides, whatever state the device recorded: the first punch before 12:00 is the check-in and the last punch from 12:00 on is the check-out. A day with only an afternoon punch shows it under Check-Out (check-in *Missing*), never under Check-In.
- **Checked Out**: today shows Checked In (↘) after the check-in and Checked Out (↗) after the check-out, with the same arrows as the times; both are in the calendar legend.
- **Date picker** on My Attendance: the date field opens a month calendar (in the portal's design, DD/MM/YYYY, weeks from Monday, keyboard friendly) instead of being typed into; choosing a day filters at once.
- **Page titles** always join the site name with " - " (as set in *Site Name in Page Titles*), never with "|".
- My Dashboard: **Devices You Used** shows fingerprint, face and total punches per device with a total row; **Recent Activity** has a Day column (e.g. Sunday).
- Day details: the punch table marks only the punches counted as check-in and check-out.

## [0.4.2] - 2026-11-16

### Changed
- **Missing Punch instead of Incomplete.** A day with any punch is **Present**. When its check-in or check-out is missing it also gets the amber **Missing Punch** tag, and the missing time shows *Missing*. The first punch of the day is the check-in even when it is the only one; a lone check-out punch stays a check-out (check-in *Missing*). The Monthly Overview and the cards count such a day as Present **and** as a Missing Punch day.
- **My Punches replaces Biometric Devices**: the logged-in employee's own punches for a year (or one month): totals by fingerprint and by face, per month with a total row, and per device. Updating turns Biometric Devices menu items into My Punches and moves the *View Biometric Devices* permission to *View My Punches*. The device list (`/iclock/api/terminals/`) is no longer read.
- My Dashboard: 10 summary cards (6, then 4), all the same size (Total Time In-Out has its own card); Devices You Used lists every device used in the selected month.
- My Attendance: 8 summary cards (5, then 3), all the same size.
- Dates are shown as **DD/MM/YYYY** by default (sites on the old default YYYY-MM-DD switch on update); the My Attendance date filter is typed as DD/MM/YYYY.
- Year lists start at 2025, and month navigation stops at January 2025.

### Removed
- The **Leave** tag, legend entry, filter and card. A working day without punches is Absent.

### Fixed
- Punches outside the month asked for (from a server that ignores the time filter) are skipped, so they are never counted twice.

## [0.4.1] - 2026-11-16

### Changed
- **New portal design**, the same as the ServiceDesk Plus portal: summary cards with a coloured edge, white panels with a title row, tables with small upper-case headings, coloured status pills (green present, amber incomplete, red absent, grey weekend, teal checked in, blue leave), a filter bar and month navigation (← month →) in the page heading. The portal styles everything itself, so the pages look the same on any site template.
- **Filters apply at once**: choosing a month, year, status, health or location (or a date) updates the page; there is no Show button (it is still there without JavaScript).
- My Dashboard: Monthly Overview as coloured tiles with a bar showing the month at a glance, Devices You Used beside it, and Recent Activity with a "View all" link. My Attendance: the summary cards filter the list. Biometric Devices: the health cards filter the list. The calendar colours each day by its status and, on phones, shows a status symbol explained by the legend.

## [0.4.0] - 2026-11-16

### Added
- **Biometric Devices** (new menu item type, permission *View Biometric Devices*): every ZKBioTime device from `/iclock/api/terminals/` with its name and model, location (area), **health** (Online: contact within 10 minutes; Delayed: within 24 hours; Offline: longer; Unknown), last activity, and the users, fingerprints, faces and punches stored on it. Counts per health (click to filter) and a location filter. The menu item option *Show technical details* adds serial number, IP address and firmware. Needs no employee mapping and shows no employee data.
- **Day statuses from the punches**: *Present* (check-in and check-out), *Checked In* (today), *Incomplete* (check-in or check-out missing), *Absent* (a past working day without any punch), *Weekend* (a weekend day without punches) and *Leave*. A weekend day with a check-in or check-out loses the weekend tag.
- **Weekend days** setting (*Integration Settings → System*), default Friday and Saturday.
- My Dashboard: a **Today** card (check-in, check-out, time between, status); days attended of the working days so far, days absent, incomplete days, attendance rate, average check-in (with the earliest) and check-out (with the latest), average and total time between check-in and check-out, weekend days worked, punches, and the devices used most.
- Every punch now also shows how the employee was verified (`verify_type_display`, e.g. Fingerprint, Face); the device serial number is read too.

### Changed
- **Check-in and check-out are clearer** on every page: each has its own arrow and colour, the device and verification it was recorded with, and *Missing* when the day has the other one only. The calendar shows both times in each day. The day details mark which punch counted as check-in and check-out.
- My Dashboard, My Attendance, the Attendance Calendar and day details show **only what the ZKBioTime 9.5 API provides**: the schedule, scheduled start and end, break, late-in, early-out, worked hours and overtime are no longer shown. My Attendance shows a whole month on one page.

### Removed
- **My Schedules**, **Attendance Reports** (with the PDF and Excel downloads) and **My Profile**: ZKBioTime 9.5 has no schedule or report API. Their menu items are moved to the trash on update, their files are deleted, and the permissions *View My Schedules*, *View Attendance Reports*, *Export Attendance Reports* and *View My Profile* are gone. The settings *Exports*, *Exports per user and hour* and *Longest report range* are removed. The bundled TCPDF library is no longer needed and is removed (smaller package).

## [0.3.4] - 2026-11-16

### Changed
- The GitHub repository was renamed to `aboarken/joomla-biotime`. The manifest's author URL, changelog URL and update server now use `https://github.com/aboarken/joomla-biotime/releases/latest/download/`. Sites on an older version keep working: GitHub redirects the old `aboarken/biotime` URLs, and installing this version stores the new update server address.

## [0.3.3] - 2026-11-16

### Added
- **Diagnostics** (*Find Endpoints*, at the top): one button runs every check from the Joomla server: the connection, the API list, the punch endpoint and the calculated-attendance endpoints (those in the API list plus `/att/api/totalTimeCardReport/`, from ZKTeco's BioTimeCloud API manual, unverified on 9.5). It shows one plain-text report with a **Copy report** button. The report holds only paths, results (HTTP status and ZKBioTime's message), row counts, field names, kinds of values and status texts: no credentials, tokens, names, employee codes or times. Super User only, POST with CSRF token, counted in the 60 checks per hour, audited.

### Changed
- When the API list holds no attendance, report or leave endpoints (as on the school's server, which lists only personnel endpoints), Find Endpoints says so instead of "listed first".

## [0.3.2] - 2026-11-16

### Fixed
- **Check-out missing with "Unknown" punches.** Terminals that record punches as *Check In* (state 0) and *Unknown* (state 255), as on the school's server, showed no check-out when calculated times were not available. Unknown punches are now used where no explicit punch exists: check-in = the first check-in punch (else the first unknown punch that has a later punch), check-out = the last check-out punch after it (else the last unknown punch after it). A lone check-out is still never shown as check-in; a lone unknown punch is shown under neither and listed as incomplete.

### Changed
- The punch list (`/iclock/api/transactions/`) and the employee list can no longer be saved as the Daily Attendance or Leave Source: the portal already reads them, and they hold no calculated attendance. Find Endpoints says so instead of offering to save them.
- `docs/API-MAPPING.md`: the transaction fields seen on the real server are recorded.

## [0.3.1] - 2026-11-15

### Fixed
- **Find Endpoints could not read the API list** ("ZKBioTime did not accept the request"): the server publishes it as CoreJSON (`/api/docs/?format=corejson`) and refuses the request with 406 when only plain JSON is accepted. The CoreJSON media type (`application/coreapi+json`) is now requested.

### Changed
- Find Endpoints shows ZKBioTime's own answer (HTTP status and message) when reading the list or checking an endpoint fails, and explains a web page returned instead of data: the path probably does not exist on that server.
- `docs/API-MAPPING.md`: login and the transaction list are verified on a real ZKBioTime server.

## [0.3.0] - 2026-11-15

### Added
- **Find Endpoints** (*Components → ZKBioTime → Find Endpoints*, and a button on Integration Settings): reads the list of API endpoints from your ZKBioTime (`/api/docs/`, `/api/personnel_docs/`), lists attendance, report and leave endpoints first, and checks the one you select with an employee code and dates. It shows the field names, the kind of value in each (date, time, number, …) and the status texts found, never names, codes or other personal values, then suggests the field and status lines. **Save as Daily Attendance Source** or **Save as Leave Source** stores them after you review them. Only reading (GET) requests are sent; the page needs Super User rights, every action is a POST with a CSRF token, limited to 60 per hour and audited.

### Fixed
- **A check-out was shown under Check-In** when ZKBioTime's calculated times were not available and a day had only a check-out punch. Check-in and check-out are now taken from the punches by their state (check-in, check-out, overtime in/out; break punches are ignored): the first check-in punch and the last check-out punch. A day with only a check-out shows Check-In "—" and the exception *Missing check-in*. A single punch of unknown state is shown under neither and listed as an incomplete punch record.
- A test that should catch missing language strings per page was not checking any file; it now does, and the two strings it found missing on Find Endpoints were added.

## [0.2.0] - 2026-11-15

### Added
- **Six portal pages** (menu item types under *Menus → Add New Menu Item → ZKBioTime*): **My Dashboard** (summary cards, Monthly Overview chart with the same figures as a table, Recent Activity), **My Attendance** (one row per day with the schedule applied on that date, scheduled start and end, check-in, check-out, late-in, early-out, worked hours, overtime, status, all punches and exceptions; month, status and date filters), **Attendance Calendar** (status as colour and text; keyboard and phone friendly), **My Schedules** (month and week), **Attendance Reports** (a month or a range of up to 93 days; late-in, early-out, absence, overtime, leave and exception reports; **PDF and Excel downloads**) and **My Profile** (read only, allow-listed fields). Day details open from these pages.
- **Employee mapping** (`#__biotime_usermap`): users are matched by the Joomla custom user field **employeeid** (= ZKBioTime emp_code) first, then by exact e-mail; one employee is never mapped to two users; a changed field or e-mail suspends the mapping. Unmatched users see a friendly "could not be matched" page.
- **User Mappings** page: search, status and source filters, *No mapping yet* report, New (map by employee code), Confirm, Suspend, Re-match; every change audited.
- **Daily Attendance Source** and **Leave Source** in Integration Settings: the administrator chooses the read-only ZKBioTime endpoint (or saved report) for calculated attendance, per-day schedules and leave, with parameter and field names and status names. No queries or SQL can be entered. Without it, those values show "—"; nothing is calculated by Joomla.
- Modules (charts, exports, leave), cache time per user (default 5 minutes, "Updated hh:mm" on pages), date and time formats, ZKBioTime server time zone (times are never shifted), longest report range and exports per user and hour.
- Ownership checks on every read: rows of other employees are dropped and logged; URL values never choose the employee.
- One log line per page load saying what was read, hidden or skipped.
- Bundled TCPDF (LGPL-3.0-or-later, DejaVu Sans with Arabic) for PDF exports.

### Changed
- **Server URL and port are one field**, e.g. `https://zkb.example.com:5555`. A port saved separately by 0.1.x is merged into the URL on update.

## [0.1.1] - 2026-11-15

### Added
- **Connect via** (*Integration Settings → Connection*), as in the ServiceDesk Plus component: for a ZKBioTime server whose own name (e.g. `zkb.example.com`, the name on its certificate) is only known inside the company network, while Joomla reaches it through a public address (e.g. `api.zkb.example.com`). Joomla opens the connection to the *Connect via* address (host, host:port or IP:port) but keeps the server URL's name for the certificate check and the Host header, so the existing certificate stays valid. *Test Connection* and *Read Certificate Pin* use the same path.

### Changed
- The source moved to the GitHub repository `aboarken/biotime`. Joomla now checks `https://github.com/aboarken/biotime/releases/latest/download/updates.xml` for updates and reads the changelog from the same release. The extension is still `com_biotime`.

## [0.1.0] - 2026-11-15

### Added
- **Stage 1: connection and authentication.** First version, for checking the connection to the real ZKBioTime 9.5 server. There are no portal pages yet.
- **Integration Settings** (*Components → ZKBioTime*): server URL and port, connection and total timeouts, JWT or General Auth Token, API username and password, optional token, TLS verification, CA bundle, certificate pin, detailed logging, API page size and safety maximum. Every value is validated on save with a clear message.
- **Encrypted credentials:** the API password and token are encrypted with AES-256-GCM (key from `BIOTIME_PORTAL_SECRET`, or Joomla's configuration secret) and never shown again; the page says *Stored (encrypted)* or *Not set* and which secret is in use.
- **Test Connection** logs in and reads one employee row: it reports the method, how many employees the API user can read and when the token is renewed, or names the problem and what to check. **Read Certificate Pin** shows the server's key for pinning. **Close** returns to the administrator dashboard and turns red on hover.
- **API client**, the only code that talks to ZKBioTime: token cached server-side and renewed before it expires, one renewal after "unauthorised", paging with a safety maximum, one retry of reads after network or server errors, nine error kinds with friendly messages naming ZKBioTime, strict id checks, and a log without credentials, tokens or personal data (`administrator/logs/com_biotime.php`).
- **Permissions** for the six portal pages and exports (not granted by default); administrator pages need *core.admin*.
- MySQL/MariaDB and PostgreSQL support; install script checks PHP 8.3, Joomla 6.0 and OpenSSL; update server and changelog for Joomla.
- `docs/API-MAPPING.md` (every endpoint, still UNVERIFIED) and `docs/TESTING.md` (automated tests and the live checklist).
