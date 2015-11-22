<?php
/**
*
* @package phpBB Extension - tas2580 PGP Mail
* @copyright (c) 2015 tas2580 (https://tas2580.net)
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace tas2580\pgpmail\migrations;

class initial_module extends \phpbb\db\migration\migration
{

	public function update_schema()
	{
		return array(
			'add_columns'	=> array(
				$this->table_prefix . 'users'	=> array(
					'pgp_public_key'	=> array('TEXT', ''),
				),
			),
		);
	}
	public function revert_schema()
	{
		return array(
			'drop_columns' => array(
				$this->table_prefix . 'users'	=> array(
					'pgp_public_key',
				),
			),
		);
	}
}
