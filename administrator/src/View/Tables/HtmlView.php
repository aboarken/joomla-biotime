<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\View\Tables;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Controller\TablesController;
use BiotimePortal\Component\Biotime\Administrator\Database\TableCatalog;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Find Tables (1.3.0). Shows the tables the portal knows (and every other table found), the last
 * table check and the last Diagnostics report, all kept in the administrator's session only.
 */
final class HtmlView extends BaseHtmlView
{
    /** @var array{tables: list<array{name: string, readable: bool, partial?: bool, estimate: ?int}>, at: int}|null */
    public ?array $list = null;

    /** @var array{table: string, result: ?array<string, mixed>, at?: int}|null */
    public ?array $check = null;

    /** @var array{report: string, at: int}|null */
    public ?array $diagnostics = null;

    public string $filter = '';

    /** Table chosen with "Check" in the list (pre-fills step 2). */
    public string $selected = '';

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();

        foreach (['list' => TablesController::STATE_LIST, 'check' => TablesController::STATE_CHECK, 'diagnostics' => TablesController::STATE_DIAG] as $property => $key) {
            $value           = $app->getUserState($key);
            $this->$property = \is_array($value) ? $value : null;
        }

        $this->filter   = mb_substr(trim($app->getInput()->getString('filter', '')), 0, 60);
        $this->selected = TableCatalog::normalize($app->getInput()->getString('use', '')) ?? '';

        ToolbarHelper::title(Text::_('COM_BIOTIME_TABLES_TITLE'), 'search');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->linkButton('settings', 'COM_BIOTIME_SUBMENU_SETTINGS')
            ->url('index.php?option=com_biotime&view=integrationsettings')
            ->icon('icon-cog');
        $toolbar->linkButton('close', 'JTOOLBAR_CLOSE')
            ->url('index.php')
            ->icon('icon-times');
        $toolbar->preferences('com_biotime');

        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_biotime');
        $assets->useStyle('com_biotime.admin');
        $assets->useScript('com_biotime.admin');

        parent::display($tpl);
    }

    /**
     * The rows of step 1: the tables the portal knows first (found or not), then every other table
     * found by the last list, filtered by text.
     *
     * @return list<array{name: string, section: string, summary: string, portal: string, planned: bool, known: bool, found: ?bool, readable: ?bool, partial: bool, estimate: ?int}>
     */
    public function rows(): array
    {
        $found = [];

        foreach ($this->list['tables'] ?? [] as $t) {
            $found[$t['name']] = $t;
        }

        $rows = [];

        foreach (TableCatalog::all() as $t) {
            $f      = $found[$t['name']] ?? null;
            $rows[] = $t + [
                'known'    => true,
                'found'    => $this->list === null ? null : $f !== null,
                'readable' => $f['readable'] ?? null,
                'partial'  => (bool) ($f['partial'] ?? false),
                'estimate' => $f['estimate'] ?? null,
            ];
            unset($found[$t['name']]);
        }

        foreach ($found as $t) {
            $rows[] = ['name' => $t['name'], 'section' => '', 'summary' => '', 'portal' => '', 'planned' => false, 'known' => false, 'found' => true, 'readable' => $t['readable'], 'partial' => (bool) ($t['partial'] ?? false), 'estimate' => $t['estimate']];
        }

        $needle = mb_strtolower($this->filter);

        return array_values(array_filter($rows, static fn (array $r): bool => $needle === ''
            || str_contains(mb_strtolower($r['name'] . ' ' . $r['section'] . ' ' . $r['summary'] . ' ' . $r['portal']), $needle)));
    }
}
