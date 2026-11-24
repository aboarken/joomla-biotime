<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\FormModel;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapping;

final class UsermapModel extends FormModel
{
    public const STATE_KEY = 'com_biotime.edit.usermap.data';

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_biotime.usermap', 'usermap', ['control' => 'jform', 'load_data' => $loadData]);

        if (!$form) {
            return false;
        }

        if ($this->requestedUserId() > 0) {
            $form->setFieldAttribute('joomla_user_id', 'readonly', 'true');
        }

        return $form;
    }

    public function getMapping(): ?UserMapping
    {
        $id = $this->requestedUserId();

        return $id > 0 ? $this->component()->getUserMapRepository()->find($id) : null;
    }

    protected function loadFormData()
    {
        $state = Factory::getApplication()->getUserState(self::STATE_KEY, []);

        // The values of a failed save, only for the same Joomla user (1.3.8): opening another user
        // after a failed save showed, and would have saved, the first user's id.
        if (\is_array($state) && $state !== [] && (int) ($state['joomla_user_id'] ?? 0) === $this->requestedUserId()) {
            return $state;
        }

        $mapping = $this->getMapping();

        return [
            'joomla_user_id' => $this->requestedUserId() ?: '',
            'emp_code'       => $mapping?->empCode ?? '',
        ];
    }

    private function requestedUserId(): int
    {
        return max(0, Factory::getApplication()->getInput()->getInt('joomla_user_id', 0));
    }

    private function component(): BiotimeComponent
    {
        $component = Factory::getApplication()->bootComponent('com_biotime');

        if (!$component instanceof BiotimeComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
