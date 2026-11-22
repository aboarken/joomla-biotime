# Security policy

## Supported versions

Only the latest release of `com_biotime` receives security fixes. Update from
**System → Update → Extensions** in Joomla, or install the package from the
[latest release](https://github.com/aboarken/joomla-biotime/releases/latest).

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub: on this repository, open the **Security** tab and choose
**Report a vulnerability**. Include:

- the version of `com_biotime`, Joomla and PHP;
- what an attacker can do, and the steps to reproduce it;
- whether it needs a logged-in user, a manager or an administrator.

Please do not include real employee data, passwords or database credentials in the report.

You will get an answer within a few days. Once a fix is released, the report is published as a
security advisory with credit to the reporter, unless you prefer otherwise.

## Scope

- The component reads ZKBioTime's database with a **read-only** database user; it never writes to
  it. A way to make it write, or to read another employee's data, is in scope.
- Joomla itself and ZKBioTime are out of scope; report those to their own projects.
