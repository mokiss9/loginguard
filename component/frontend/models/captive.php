<?php
/**
 * @package   AkeebaLoginGuard
 * @copyright Copyright (c)2006-2017 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

// Prevent direct access
defined('_JEXEC') or die;

class LoginGuardModelCaptive extends JModelLegacy
{
	/**
	 * Prevents Joomla from displaying any modules.
	 *
	 * This is implemented with a trick. If you use jdoc tags to load modules the JDocumentRendererHtmlModules
	 * uses JModuleHelper::getModules() to load the list of modules to render. This goes through JModuleHelper::load()
	 * which triggers the onAfterModuleList event after cleaning up the module list from duplicates. By resetting
	 * the list to an empty array we force Joomla to not display any modules.
	 *
	 * Similar code paths are followed by any canonical code which tries to load modules. So even if your template does
	 * not use jdoc tags this code will still work as expected.
	 */
	public function killAllModules()
	{
		$allowedPositions = $this->getAllowedModulePositions();

		$app = JFactory::getApplication();
		$app->registerEvent('onAfterModuleList', function (&$modules) use ($allowedPositions) {
			if (empty($modules))
			{
				return;
			}

			if (empty($allowedPositions))
			{
				$modules = array();

				return;
			}

			$filtered = array();

			foreach ($modules as $module)
			{
				if (in_array($module->position, $allowedPositions))
				{
					$filtered[] = $module;
				}
			}

			$modules = $filtered;
		});
	}

	/**
	 * Get a list of module positions we are allowed to display
	 *
	 * @return  array
	 */
	private function getAllowedModulePositions()
	{
		$res = array();

		// TODO Load the list of allowed module positions from the component's settings

		return $res;
	}

}