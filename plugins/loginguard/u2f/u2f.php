<?php
/**
 * @package   AkeebaLoginGuard
 * @copyright Copyright (c)2016-2017 Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

// Prevent direct access
defined('_JEXEC') or die;

/**
 * Akeeba LoginGuard Plugin for Two Step Verification method "Time-based One Time Password"
 *
 * Requires a 6-digit code generated by Google Authenticator or any compatible application. These codes change
 * automatically every 30 seconds.
 */
class PlgLoginguardU2f extends JPlugin
{
	/**
	 * The TFA method name handled by this plugin
	 *
	 * @var   string
	 */
	private $tfaMethodName = 'u2f';

	/**
	 * Should I report myself as enabled?
	 *
	 * @var   bool
	 */
	private $enabled = true;

	/**
	 * U2F Server Library instance
	 *
	 * @var   \u2flib_server\U2F
	 */
	protected $u2f = null;

	/**
	 * Constructor. Loads the language files as well.
	 *
	 * @param   object  &$subject  The object to observe
	 * @param   array   $config    An optional associative array of configuration settings.
	 *                             Recognized key values include 'name', 'group', 'params', 'language'
	 *                             (this list is not meant to be comprehensive).
	 */
	public function __construct($subject, array $config = array())
	{
		parent::__construct($subject, $config);

		// Load the language file
		$this->loadLanguage();

		// Try to load the U2F server library
		if (!class_exists('u2flib_server\\U2F'))
		{
			require_once __DIR__ . '/classes/u2f.php';
		}

		// Make sure I can load the server library
		$this->enabled = class_exists('u2flib_server\\U2F');

		if (!$this->enabled)
		{
			return;
		}

		// Check OpenSSL version
		$this->enabled = $this->isOpenSSL10OrNewer();

		if (!$this->enabled)
		{
			return;
		}

		// Try to create a server library object
		$jURI = JURI::getInstance();
		$appId = $jURI->toString(array('scheme', 'host', 'port'));

		try
		{
			$this->u2f = new u2flib_server\U2F($appId);
		}
		catch (\Exception $e)
		{
			$this->enabled = false;

			return;
		}

		// Finally, detect old Google Chrome versions and activate U2F support manually.
		$this->loadOldChromeJavascript();
	}

	/**
	 * Gets the identity of this TFA method
	 *
	 * @return  array
	 */
	public function onLoginGuardTfaGetMethod()
	{
		if (!$this->enabled)
		{
			return array();
		}

		$helpURL = $this->params->get('helpurl', 'https://github.com/akeeba/loginguard/wiki/U2F');

		return array(
			// Internal code of this TFA method
			'name'               => $this->tfaMethodName,
			// User-facing name for this TFA method
			'display'            => JText::_('PLG_LOGINGUARD_U2F_LBL_DISPLAYEDAS'),
			// Short description of this TFA method displayed to the user
			'shortinfo'          => JText::_('PLG_LOGINGUARD_U2F_LBL_SHORTINFO'),
			// URL to the logo image for this method
			'image'              => 'media/plg_loginguard_u2f/images/u2f.svg',
			// Are we allowed to disable it?
			'canDisable'         => true,
			// Are we allowed to have multiple instances of it per user?
			'allowMultiple'      => true,
			// URL for help content
			'help_url'           => $helpURL,
			// Allow authentication against all entries of this TFA method. Otherwise authentication takes place against a SPECIFIC entry at a time.
			'allowEntryBatching' => $this->params->get('allowEntryBatching', 1),
		);
	}

	/**
	 * Returns the information which allows LoginGuard to render the TFA setup page. This is the page which allows the
	 * user to add or modify a TFA method for their user account. If the record does not correspond to your plugin
	 * return an empty array.
	 *
	 * @param   stdClass  $record  The #__loginguard_tfa record currently selected by the user.
	 *
	 * @return  array
	 */
	public function onLoginGuardTfaGetSetup($record)
	{
		// Make sure we are enabled
		if (!$this->enabled)
		{
			return array();
		}

		// Make sure we are actually meant to handle this method
		if ($record->method != $this->tfaMethodName)
		{
			return array();
		}

		// Load the options from the record (if any)
		$options                    = $this->_decodeRecordOptions($record);
		$currentRecordRegistrations = isset($options['registrations']) ? $options['registrations'] : array();

		$registrations = $this->getRegistrationsFor($record->user_id);

		// Get some values assuming that we are NOT setting up U2F (the key is already registered)
		$submitOnClick = '';
		$preMessage    = JText::_('PLG_LOGINGUARD_U2F_LBL_CONFIGURED');
		$u2fRegData    = json_encode($this->u2f->getRegisterData($registrations));
		$type          = 'input';
		$html          = '';
		$helpURL       = $this->params->get('helpurl', 'https://github.com/akeeba/loginguard/wiki/U2F');

		/**
		 * If there are no security keys set up yet I need to show a different message and take a different action when
		 * my user clicks the submit button.
		 */
		if (empty($currentRecordRegistrations))
		{
			// Load Javascript
			JHtml::_('script', 'plg_loginguard_u2f/u2f-api.min.js', array(
				'version'     => 'auto',
				'relative'    => true,
				'detectDebug' => true,
			), true, false, false, true);

			JHtml::_('script', 'plg_loginguard_u2f/u2f.min.js', array(
				'version'     => 'auto',
				'relative'    => true,
				'detectDebug' => true,
			), true, false, false, true);

			$js = <<< JS
window.jQuery(document).ready(function() {
	akeeba.LoginGuard.u2f.regData = $u2fRegData;
});

JS;
			JFactory::getDocument()->addScriptDeclaration($js);

			$layoutPath = JPluginHelper::getLayoutPath('loginguard', 'u2f', 'register');
			ob_start();
			include $layoutPath;
			$html = ob_get_clean();
			$type = 'custom';

			// Load JS translations
			JText::script('PLG_LOGINGUARD_U2F_ERR_JS_OTHER');
			JText::script('PLG_LOGINGUARD_U2F_ERR_JS_CANNOTPROCESS');
			JText::script('PLG_LOGINGUARD_U2F_ERR_JS_CLIENTCONFIGNOTSUPPORTED');
			JText::script('PLG_LOGINGUARD_U2F_ERR_JS_INELIGIBLE');
			JText::script('PLG_LOGINGUARD_U2F_ERR_JS_TIMEOUT');

			// Save the U2F request to the session
			$session = JFactory::getSession();
			$session->set('u2f.request', $u2fRegData, 'com_loginguard');

			// Special button handling
			$submitOnClick = "akeeba.LoginGuard.u2f.setUp(); return false;";

			// Message to display
			$preMessage = JText::_('PLG_LOGINGUARD_U2F_LBL_INSTRUCTIONS');
		}

		return array(
			// Default title if you are setting up this TFA method for the first time
			'default_title'  => JText::_('PLG_LOGINGUARD_U2F_LBL_DISPLAYEDAS'),
			// Custom HTML to display above the TFA setup form
			'pre_message'    => $preMessage,
			// Heading for displayed tabular data. Typically used to display a list of fixed TFA codes, TOTP setup parameters etc
			'table_heading'  => '',
			// Any tabular data to display (label => custom HTML). See above
			'tabular_data'   => array(),
			// Hidden fields to include in the form (name => value)
			'hidden_data'    => array(
				'u2fregdata' => $u2fRegData,
			),
			// How to render the TFA setup code field. "input" (HTML input element) or "custom" (custom HTML)
			'field_type'     => $type,
			// The type attribute for the HTML input box. Typically "text" or "password". Use any HTML5 input type.
			'input_type'     => 'hidden',
			// Pre-filled value for the HTML input box. Typically used for fixed codes, the fixed YubiKey ID etc.
			'input_value'    => '',
			// Placeholder text for the HTML input box. Leave empty if you don't need it.
			'placeholder'    => '',
			// Label to show above the HTML input box. Leave empty if you don't need it.
			'label'          => '',
			// Custom HTML. Only used when field_type = custom.
			'html'           => $html,
			// Should I show the submit button (apply the TFA setup)? Only applies in the Add page.
			'show_submit'    => false,
			// onclick handler for the submit button (apply the TFA setup)?
			'submit_onclick' => $submitOnClick,
			// Custom HTML to display below the TFA setup form
			'post_message'   => '',
			// URL for help content
			'help_url'       => $helpURL,
		);
	}

	/**
	 * Parse the input from the TFA setup page and return the configuration information to be saved to the database. If
	 * the information is invalid throw a RuntimeException to signal the need to display the editor page again. The
	 * message of the exception will be displayed to the user. If the record does not correspond to your plugin return
	 * an empty array.
	 *
	 * @param   stdClass  $record  The #__loginguard_tfa record currently selected by the user.
	 * @param   JInput    $input   The user input you are going to take into account.
	 *
	 * @return  array  The configuration data to save to the database
	 *
	 * @throws  RuntimeException  In case the validation fails
	 */
	public function onLoginGuardTfaSaveSetup($record, JInput $input)
	{
		// Make sure we are enabled
		if (!$this->enabled)
		{
			return array();
		}

		// Make sure we are actually meant to handle this method
		if ($record->method != $this->tfaMethodName)
		{
			return array();
		}

		// Load the options from the record (if any)
		$options = $this->_decodeRecordOptions($record);

		if (!isset($options['registrations']))
		{
			$options['registrations'] = array();
		}

		// load the registration request from the session
		$session    = JFactory::getSession();
		$u2fRegData = $session->get('u2f.request', null, 'com_loginguard');
		$session->set('u2f.request', null, 'com_loginguard');
		$registrationRequest = json_decode($u2fRegData);

		// Load the registration response from the input
		$code             = $input->get('code', null, 'raw');
		$registerResponse = json_decode($code);

		// If there was no registration request BUT there is a registration response throw an error
		if (empty($registrationRequest) && !(empty($code) || empty($registerResponse)))
		{
			throw new RuntimeException(JText::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		// If there is no registration request (and there isn't a registration response) we are just saving the title.
		if (empty($registrationRequest))
		{
			return $options;
		}

		// In any other case try to authorize the registration
		try
		{
			$registration = $this->u2f->doRegister($registrationRequest[0], $registerResponse);
		}
		catch (\u2flib_server\Error $err)
		{
			throw new RuntimeException($err->getMessage(), 403);
		}

		// The code is valid. Unset the request data from the session and update the options
		$options['registrations'][] = $registration;

		// Return the configuration to be serialized
		return $options;
	}

	/**
	 * Returns the information which allows LoginGuard to render the captive TFA page. This is the page which appears
	 * right after you log in and asks you to validate your login with TFA.
	 *
	 * @param   stdClass  $record  The #__loginguard_tfa record currently selected by the user.
	 *
	 * @return  array
	 */
	public function onLoginGuardTfaCaptive($record)
	{
		// Make sure we are enabled
		if (!$this->enabled)
		{
			return array();
		}

		// Make sure we are actually meant to handle this method
		if ($record->method != $this->tfaMethodName)
		{
			return array();
		}

		// We are going to load a JS file and use custom on-load JS to intercept the loginguard-captive-button-submit button
		JHtml::_('script', 'plg_loginguard_u2f/u2f-api.min.js', array(
			'version'     => 'auto',
			'relative'    => true,
			'detectDebug' => true,
		), true, false, false, true);

		JHtml::_('script', 'plg_loginguard_u2f/u2f.min.js', array(
			'version'     => 'auto',
			'relative'    => true,
			'detectDebug' => true,
		), true, false, false, true);

		// Load JS translations
		JText::script('PLG_LOGINGUARD_U2F_ERR_JS_OTHER');
		JText::script('PLG_LOGINGUARD_U2F_ERR_JS_CANNOTPROCESS');
		JText::script('PLG_LOGINGUARD_U2F_ERR_JS_CLIENTCONFIGNOTSUPPORTED');
		JText::script('PLG_LOGINGUARD_U2F_ERR_JS_INELIGIBLE_SIGN');
		JText::script('PLG_LOGINGUARD_U2F_ERR_JS_TIMEOUT');

		// Load the options from the record (if any), or from the entire method if the allowEntryBatching flag is set.
		$registrations = $this->getRegistrations($record);

		// If "Validate against all registered keys" is enabled we need to load all keys, not just the current one.
		$u2fAuthData     = $this->u2f->getAuthenticateData($registrations);
		$u2fAuthDataJSON = json_encode($u2fAuthData);

		$session = JFactory::getSession();
		$session->set('u2f.authentication', serialize($u2fAuthData), 'com_loginguard');

		$js = <<< JS
window.jQuery(document).ready(function($) {
	akeeba.LoginGuard.u2f.authData = $u2fAuthDataJSON;
	
	$(document.getElementById('loginguard-captive-button-submit')).click(function() {
		akeeba.LoginGuard.u2f.validate();
		return false;
	})
});

JS;
		JFactory::getDocument()->addScriptDeclaration($js);

		$layoutPath = JPluginHelper::getLayoutPath('loginguard', 'u2f', 'validate');
		ob_start();
		include $layoutPath;
		$html = ob_get_clean();

		$helpURL = $this->params->get('helpurl', 'https://github.com/akeeba/loginguard/wiki/U2F');

		return array(
			// Custom HTML to display above the TFA form
			'pre_message'        => JText::_('PLG_LOGINGUARD_U2F_LBL_INSTRUCTIONS'),
			// How to render the TFA code field. "input" (HTML input element) or "custom" (custom HTML)
			'field_type'         => 'custom',
			// The type attribute for the HTML input box. Typically "text" or "password". Use any HTML5 input type.
			'input_type'         => '',
			// Placeholder text for the HTML input box. Leave empty if you don't need it.
			'placeholder'        => '',
			// Label to show above the HTML input box. Leave empty if you don't need it.
			'label'              => '',
			// Custom HTML. Only used when field_type = custom.
			'html'               => $html,
			// Custom HTML to display below the TFA form
			'post_message'       => '',
			// URL for help content
			'help_url'           => $helpURL,
			// Allow authentication against all entries of this TFA method. Otherwise authentication takes place against a SPECIFIC entry at a time.
			'allowEntryBatching' => $this->params->get('allowEntryBatching', 1),
		);
	}

	/**
	 * Validates the Two Factor Authentication code submitted by the user in the captive Two Step Verification page. If
	 * the record does not correspond to your plugin return FALSE.
	 *
	 * @param   stdClass  $record  The TFA method's record you're validatng against
	 * @param   JUser     $user    The user record
	 * @param   string    $code    The submitted code
	 *
	 * @return  bool
	 */
	public function onLoginGuardTfaValidate($record, JUser $user, $code)
	{
		// Make sure we are enabled
		if (!$this->enabled)
		{
			return false;
		}

		// Make sure we are actually meant to handle this method
		if ($record->method != $this->tfaMethodName)
		{
			return false;
		}

		// Double check the TFA method is for the correct user
		if ($user->id != $record->user_id)
		{
			return false;
		}

		// Load the options from the record (if any), or from the entire method if the allowEntryBatching flag is set.
		$registrations = $this->getRegistrations($record);

		// Get the authentication response
		$authenticateResponse = json_decode($code);

		if (empty($authenticateResponse))
		{
			// Invalid authentication signature response in request
			return false;
		}

		$session = JFactory::getSession();
		$authenticationRequest = $session->get('u2f.authentication', null, 'com_loginguard');
		$session->set('u2f.authentication', null, 'com_loginguard');

		if (empty($authenticationRequest))
		{
			// No authentication request in session; do not proceed
			return false;
		}

		$authenticationRequest = unserialize($authenticationRequest);

		if (empty($authenticationRequest))
		{
			// Invalid authentication request in session; do not proceed
			return false;
		}

		// Validate the U2F signature
		try
		{
			$registration = $this->u2f->doAuthenticate($authenticationRequest, $registrations, $authenticateResponse);
		}
		catch (Exception $e)
		{
			return false;
		}

		// The $registration contains the updated registration for the used security key. But WHICH one?
		$id = $record->id;

		/**
		 * Save the updated registration to the database.
		 *
		 * Why? Every time the security key signs a verification request it increases its internal counter monotonical-
		 * ly. Every subsequent signing request will have a counter larger than the previous one. If the library sees a
		 * counter that's lower than the last recorded one we know that we have a cloned security key and we have to
		 * reject it. This protection only works if we "remember" the last counter encountered, i.e. if we save the
		 * updated registration after validation.
		 */
		$update = (object)array(
			'id' => $id,
			'options' => json_encode(array('registrations' => array($registration)))
		);

		$db = JFactory::getDbo();
		$db->updateObject('#__loginguard_tfa', $update, array('id'));

		return true;
	}

	/**
	 * Decodes the options from a #__loginguard_tfa record into an options object.
	 *
	 * @param   stdClass|string  $record  The record object or just the JSON-encoded options
	 *
	 * @return  array
	 */
	private function _decodeRecordOptions($record)
	{
		$options = array(
			'registrations' => array()
		);

		$recordOptions = null;

		if (is_object($record))
		{
			$recordOptions = $record->options;
		}
		elseif (is_string($record))
		{
			$recordOptions = $record;
		}

		if (!empty($recordOptions))
		{
			if (is_string($recordOptions))
			{
				// We need to decode as object. This is required for the U2F library to work proparly.
				$recordOptions = json_decode($recordOptions);
			}

			/**
			 * However, $options is an array so I need to typecast the generated object to an array. The end result is:
			 * $recordOptions is an array with one key, 'registrations'
			 * $recordOptions['registrations'] is a simple (numerically indexed) array. Its contents are objects.
			 * That's exactly what I wanted.
			 */
			$recordOptions = (array)$recordOptions;

			$options = array_merge($options, $recordOptions);
		}

		return $options;
	}

	/**
	 * Checks if we have OpenSSL 1.0 or newer
	 *
	 * @return  bool
	 */
	private function isOpenSSL10OrNewer()
	{
		// No OpenSSL? No joy.
		if (!defined('OPENSSL_VERSION_TEXT'))
		{
			return false;
		}

		$parts = explode(' ', OPENSSL_VERSION_TEXT);

		// Not actually OpenSSL? No joy.
		if (strtoupper($parts[0]) != 'OPENSSL')
		{
			return false;
		}

		// We can't directly use version compare as it doesn't follow PHP version semantics
		$version = $parts[1];
		$parts = explode('.', $version, 4);
		$version = $parts[0] . '.' . $parts[1] . '.' . (int)$parts[2];

		return version_compare($version, '1.0.0', 'ge');
	}

	/**
	 * Old Google Chrome versions (38, 39 and 40) required a special extension to enable support for U2F. This extension
	 * would only load by default the U2F support on Google sites. Third party sites needed to load the extension's JS
	 * file directly. This method detects Google Chrome 38, 39 and 40 and loads the extension Javascript to activate U2F
	 * support. On newer versions of Google Chrome and browsers made by other vendors this method takes no action. U2F
	 * is either supported transparently either natively or through an add-on.
	 *
	 * NB: If you're still using a browser from late 2013 to late 2014 then being able to use a U2F security key is
	 *     probably the least of your worries. I wouldn't run such an old browser even at gunpoint!
	 *
	 * @see  http://stackoverflow.com/questions/27158182/u2f-support-without-the-u2f-chrome-extension
	 *
	 * @return  void
	 */
	private function loadOldChromeJavascript()
	{
		if (!class_exists('JBrowser'))
		{
			JLoader::import('joomla.environment.browser');
		}

		$jBrowser       = JBrowser::getInstance();
		$browserMake    = $jBrowser->getBrowser() == 'chrome';
		$browserVersion = $jBrowser->getVersion();
		$isOldChrome    = ($browserMake) && version_compare($browserVersion, '38.0', 'ge') && version_compare($browserVersion, '41.0', 'lt');

		if ($isOldChrome)
		{
			JFactory::getDocument()->addScript('chrome-extension://pfboblefjcgdjicmnffhdgionmgcdmne/u2f-api.js');
		}
	}

	/**
	 * Get all security key registrations for the specified user
	 *
	 * @param   int  $user_id  The user ID to look for. Leave empty for the current user.
	 *
	 * @return  array
	 */
	private function getRegistrationsFor($user_id = null)
	{
		if (empty($user_id))
		{
			$user_id = JFactory::getUser()->id;
		}

		$return = array();

		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select('*')
			->from($db->qn('#__loginguard_tfa'))
			->where($db->qn('user_id') . ' = ' . $db->q($user_id))
			->where($db->qn('method') . ' = ' . $db->q('u2f'));
		$results = $db->setQuery($query)->loadObjectList();

		if (empty($results))
		{
			return $return;
		}

		foreach ($results as $result)
		{
			$options = $this->_decodeRecordOptions($result);

			if (!isset($options['registrations']) || empty($options['registrations']))
			{
				continue;
			}

			$return[$result->id] = $options['registrations'][0];
		}

		return $return;
	}

	/**
	 * Get the security key registrations for a given record. If the allowEntryBatching flag is 0 (No) we only return
	 * the key registrations for the given record. If the allowEntryBatching flag is 1 (Yes) we return the combined key
	 * registrations for all security key records of the user ID found in the $record object.
	 *
	 * @param   stdClass  $record  The LoginGuard record
	 *
	 * @return  array  Security key registrations for use by the U2F library
	 */
	private function getRegistrations($record)
	{
		$options       = $this->_decodeRecordOptions($record);

		if ($this->params->get('allowEntryBatching', 1) == 0)
		{
			return isset($options['registrations']) ? $options['registrations'] : array();
		}

		$registrations = array();

		try
		{
			$db = JFactory::getDbo();
			$query = $db->getQuery(true)
			            ->select('*')
			            ->from($db->qn('#__loginguard_tfa'))
			            ->where($db->qn('user_id') . ' = ' . $db->q($record->user_id))
			            ->where($db->qn('method') . ' = ' . $db->q($record->method));
			$records = $db->setQuery($query)->loadObjectList();
		}
		catch (Exception $e)
		{
			$records = array();
		}

		// Loop all records, stop if at least one matches
		foreach ($records as $aRecord)
		{
			$recordOptions       = $this->_decodeRecordOptions($aRecord);
			$recordRegistrations = isset($recordOptions['registrations']) ? $recordOptions['registrations'] : array();
			$registrations       = array_merge($registrations, $recordRegistrations);
		}

		return $registrations;
	}
}