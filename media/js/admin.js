/**
 * Find Tables (1.3.0): copy the Diagnostics report to the clipboard. (Download report is a normal
 * form, sent by the server: it needs no script, 1.3.3.)
 *
 * 1.3.2: copying works however the page loads this script (before or after the page is ready),
 * and in every browser setting: the selected report is copied in the click itself; when the
 * browser refuses that, its Clipboard API is tried. The button says whether it worked; when it
 * did not, the report stays selected for Ctrl+C.
 *
 * @license GNU General Public License version 2 or later
 */
(() => {
  'use strict';

  const start = () => {
    const copy = document.getElementById('bt-diag-copy');
    const report = document.getElementById('bt-diag-report');
    const status = document.getElementById('bt-diag-status');

    if (!report || report.dataset.btReady === '1') {
      return;
    }

    report.dataset.btReady = '1';

    const say = (text, ok) => {
      if (status) {
        status.textContent = text;
        status.classList.toggle('text-success', ok);
        status.classList.toggle('text-danger', !ok);
      }
    };

    const selectAll = () => {
      report.focus();
      report.select();
      report.setSelectionRange(0, report.value.length);
    };

    if (copy) {
      copy.addEventListener('click', () => {
        selectAll();

        let copied = false;

        // In the click itself, with the report selected: works on http:// and https:// pages.
        try {
          copied = document.execCommand('copy');
        } catch (e) {
          copied = false;
        }

        if (copied) {
          say(copy.dataset.done, true);

          return;
        }

        // The browser refused it: its Clipboard API (https:// pages only).
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(report.value)
            .then(() => say(copy.dataset.done, true))
            .catch(() => {
              selectAll();
              say(copy.dataset.failed, false);
            });

          return;
        }

        say(copy.dataset.failed, false);
      });
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
