/**
 * Find Tables (1.3.0): copy the Diagnostics report to the clipboard.
 *
 * @license GNU General Public License version 2 or later
 */
document.addEventListener('DOMContentLoaded', () => {
  const button = document.getElementById('bt-diag-copy');
  const report = document.getElementById('bt-diag-report');

  if (!button || !report) {
    return;
  }

  button.addEventListener('click', async () => {
    report.select();

    try {
      await navigator.clipboard.writeText(report.value);
    } catch (e) {
      document.execCommand('copy');
    }

    button.textContent = button.dataset.done;
  });
});
