<?php
/**
*
* @package phpBB Extension - tas2580 PGP Mail
* @copyright (c) 2015 tas2580 (https://tas2580.net)
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

use \pgp_mail as pgp_mail;

function pgp_mail($to, $subject, $msg, $headers)
{
	global $table_prefix, $db;

	// get the clean email address from $to
	$mail = utf8_decode(imap_utf8($to));
	preg_match ('#<(.*)>#', $mail, $matches);

	// Get PGP Key form DB
	$sql = 'SELECT pgp_public_key
		FROM ' . $table_prefix . "users
		WHERE user_email = '" . $db->sql_escape($matches[1]) . "'";
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$public_key_ascii = $row['pgp_public_key'];

	// If we have a public key encrypt the message
	if (!empty($public_key_ascii))
	{
		if (function_exists('gnupg_init'))
		{
			putenv("GNUPGHOME=/tmp");
			$res = gnupg_init();
			$rtv = gnupg_import($res, $public_key_ascii);
			$rtv = gnupg_addencryptkey($res, $rtv['fingerprint']);
			$encrypted = gnupg_encrypt($res, $msg);
		}
		else
		{
			include_once ('class_pgp.php');
			$gpg = new GPG();
			$pub_key = new GPG_Public_Key($public_key_ascii);
			$encrypted = $gpg->encrypt($pub_key, $msg);
		}
	}
	else
	{
		// No PGP key do not encrypt
		$encrypted = $msg;
	}

	// Send the mail
	return mail ($to, $subject, $encrypted, $headers);
}