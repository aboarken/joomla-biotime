<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Service;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Component\Router\RouterView;
use Joomla\CMS\Component\Router\RouterViewConfiguration;
use Joomla\CMS\Component\Router\Rules\MenuRules;
use Joomla\CMS\Component\Router\Rules\NomenuRules;
use Joomla\CMS\Component\Router\Rules\StandardRules;
use Joomla\CMS\Menu\AbstractMenu;

/**
 * SEF router. The seven menu item types plus the day details page. No URL value ever selects the
 * employee: months and dates only narrow the logged-in employee's own data.
 */
final class Router extends RouterView
{
    public function __construct(SiteApplication $app, AbstractMenu $menu)
    {
        foreach (['dashboard', 'attendance', 'calendar', 'punches', 'manager', 'leaves', 'approvals'] as $view) {
            $this->registerView(new RouterViewConfiguration($view));
        }

        $day = new RouterViewConfiguration('day');
        $this->registerView($day);

        parent::__construct($app, $menu);

        $this->attachRule(new MenuRules($this));
        $this->attachRule(new StandardRules($this));
        $this->attachRule(new NomenuRules($this));
    }
}
