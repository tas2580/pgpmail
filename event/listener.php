<?php
/**
*
* @package phpBB Extension - tas2580 PGP Mail
* @copyright (c) 2015 tas2580 (https://tas2580.net)
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace tas2580\pgpmail\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class listener implements EventSubscriberInterface
{

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var string phpbb_root_path */
	protected $phpbb_root_path;

	/** @var string php_ext */
	protected $php_ext;

	/** @var string */
	protected $phpbb_extension_manager;

	/**
	* Constructor
	*
	* @param \phpbb\config\config				$config				Config Object
	* @param \phpbb\template\template			$template				Template object
	* @param \phpbb\user						$user				User object
	* @param \phpbb\request\request				$request				Request object
	* @param \phpbb\cache\driver\driver_interface	$cache				Cache driver interface
	* @param string							$phpbb_root_path		phpbb_root_path
	* @access public
	*/
	public function __construct(\phpbb\user $user, \phpbb\template\template $template, \phpbb\request\request $request, $phpbb_root_path, $php_ext, $phpbb_extension_manager)
	{
		$this->user = $user;
		$this->template = $template;
		$this->request = $request;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->phpbb_extension_manager = $phpbb_extension_manager;
	}

	/**
	* Assign functions defined in this class to event listeners in the core
	*
	* @return array
	* @static
	* @access public
	*/
	static public function getSubscribedEvents()
	{
		return array(
		//	'core.notification_manager_add_notifications'	=> 'add_mail_function',
			'core.common'							=> 'add_mail_function',
		//	'core.acp_board_config_edit_add'			=> 'add_mail_function',
			'core.ucp_profile_modify_profile_info'			=> 'ucp_profile_modify_profile_info',
			'core.ucp_profile_info_modify_sql_ary'		=> 'ucp_profile_info_modify_sql_ary',
		);
	}

	/**
	* Add a new data field to the UCP
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function ucp_profile_modify_profile_info($event)
	{
		$event['data'] = array_merge($event['data'], array(
			'pgp_public_key'	=> $this->request->variable('pgp_public_key', ''),
		));

		$this->template->assign_vars(array(
			'PGP_PUBLIC_KEY'		=> $this->user->data['pgp_public_key'],
		));
	}

	/**
	* User has changed his whatsapp number, update the database
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function ucp_profile_info_modify_sql_ary($event)
	{
		$event['sql_ary'] = array_merge($event['sql_ary'], array(
			'pgp_public_key' => $event['data']['pgp_public_key'],
		));
	}

	/**
	* Include the PGP mail class
	*
	* @param object $event The event object
	* @return null
	* @access public
	*/
	public function add_mail_function($event)
	{
		include_once($this->phpbb_root_path . $this->phpbb_extension_manager->get_extension_path('tas2580/pgpmail', false) . 'pgp_mail.' . $this->php_ext);
	}
}
