<?php
/**
 * @package   AkeebaLoginGuard
 * @copyright Copyright (c)2016-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\LoginGuard\Admin\Model;

// Protect from unauthorized access
defined('_JEXEC') or die();

use AkeebaUsagestats;
use FOF30\Database\Installer;
use FOF30\Model\Model;
use JCrypt;
use JUri;

/**
 * Usage statistics collection model. Implements the anonymous collection of PHP, MySQL and Joomla! version information
 * which help us decide on the end of support for obsolete versions of said third party software.
 */
class UsageStatistics extends Model
{
	/**
	 * Make sure the #__akeeba_common table exists or create it from scratch
	 *
	 * @return  $this
	 */
	public function checkAndFixCommonTables()
	{
		// Install or update database
		try
		{

		}
		catch (\Exception $e)
		{
			$container   = $this->container;
			$dbInstaller = new Installer($container->db, $container->backEndPath . '/sql/common');
			$dbInstaller->updateSchema();
		}

		return $this;
	}

	/**
	 * Get an existing unique site ID or create a new one
	 *
	 * @return  string
	 */
	public function getSiteId()
	{
		// Can I load a site ID from the database?
		$siteId = $this->getCommonVariable('stats_siteid', null);

		// Can I load the site Url from the database?
		$siteUrl = $this->getCommonVariable('stats_siteurl', null);

		// No id or the saved URL is not the same as the current one (ie site restored to a new url)?
		// Create a new, random site ID and save it to the database
		if (empty($siteId) || (md5(JUri::base()) != $siteUrl))
		{
			$siteUrl = md5(JUri::base());
			$this->setCommonVariable('stats_siteurl', $siteUrl);

			$randomData = JCrypt::genRandomBytes(120);
			$siteId     = sha1($randomData);

			$this->setCommonVariable('stats_siteid', $siteId);
		}

		return $siteId;
	}

	/**
	 * Load a variable from the common variables table. If it doesn't exist it returns $default
	 *
	 * @param   string  $key      The key to load
	 * @param   mixed   $default  The default value if the key doesn't exist
	 *
	 * @return  mixed  The contents of the key or null if it's not present
	 */
	public function getCommonVariable($key, $default = null)
	{
		$db    = $this->container->db;
		$query = $db->getQuery(true)
					->select($db->qn('value'))
					->from($db->qn('#__akeeba_common'))
					->where($db->qn('key') . ' = ' . $db->q($key));

		try
		{
			$db->setQuery($query);
			$result = $db->loadResult();
		}
		catch (\Exception $e)
		{
			$result = $default;
		}

		return $result;
	}

	/**
	 * Set a variable to the common variables table.
	 *
	 * @param   string  $key    The key to save
	 * @param   mixed   $value  The value to save
	 *
	 * @return  void
	 */
	public function setCommonVariable($key, $value)
	{
		$db    = $this->container->db;
		$query = $db->getQuery(true)
					->select('COUNT(*)')
					->from($db->qn('#__akeeba_common'))
					->where($db->qn('key') . ' = ' . $db->q($key));

		try
		{
			$db->setQuery($query);
			$count = $db->loadResult();
		}
		catch (\Exception $e)
		{
			return;
		}

		try
		{
			if (!$count)
			{
				$insertObject = (object)array(
					'key'   => $key,
					'value' => $value,
				);
				$db->insertObject('#__akeeba_common', $insertObject);
			}
			else
			{
				$keyName = version_compare(JVERSION, '1.7.0', 'lt') ? $db->qn('key') : 'key';

				$insertObject = (object)array(
					$keyName => $key,
					'value'  => $value,
				);

				$db->updateObject('#__akeeba_common', $insertObject, $keyName);
			}
		}
		catch (\Exception $e)
		{
		}
	}
}
