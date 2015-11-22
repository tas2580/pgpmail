<?php
/**
 * Based on php-gpg from Jason Hinkle
 * https://github.com/jasonhinkle/php-gpg
 *
 * @package php-gpg::Encryption
 * @link http://www.verysimple.com/
 * @copyright 1997-2012 VerySimple, Inc.
 * @license http://www.gnu.org/licenses/gpl.html  GPL
 */


class GPG
{
	private $width = 16;
	private $el = array(3, 5, 9, 17, 513, 1025, 2049, 4097);
	private $version = "1.4.7";

	private function gpg_encrypt($key, $text)
	{
		$i = 0;
		$i = 0;
		$len = strlen($text);
		$len = strlen($text);
		$iblock = array_fill(0, $this->width, 0);
		$rblock = array_fill(0, $this->width, 0);
		$ct = array_fill(0, $this->width + 2, 0);

		$cipher = "";
		if ($len % $this->width)
		{
			for($i = ($len % $this->width); $i < $this->width; $i++) $text .= "\0";
		}

		$ekey = new Expanded_Key($key);
		for ($i = 0; $i < $this->width; $i++)
		{
			$iblock[$i] = 0;
			$rblock[$i] = GPG_Utility::c_random();
		}
		$iblock = GPG_AES::encrypt($iblock, $ekey);
		for ($i = 0; $i < $this->width; $i++)
		{
			$ct[$i] = ($iblock[$i] ^= $rblock[$i]);
		}
		$iblock = GPG_AES::encrypt($iblock, $ekey);
		$ct[$this->width]   = ($iblock[0] ^ $rblock[$this->width - 2]);
		$ct[$this->width + 1] = ($iblock[1] ^ $rblock[$this->width - 1]);

		for ($i = 0; $i < $this->width + 2; $i++)
		{
			$cipher .= chr($ct[$i]);
		}
		$iblock = array_slice($ct, 2, $this->width + 2);
		for ($n = 0; $n < strlen($text); $n += $this->width)
		{
			$iblock = GPG_AES::encrypt($iblock, $ekey);
			for ($i = 0; $i < $this->width; $i++)
			{
				$iblock[$i] ^= ord($text[$n + $i]);
				$cipher .= chr($iblock[$i]);
			}
		}

		return substr($cipher, 0, $len + $this->width + 2);
	}

	private function gpg_header($tag, $len)
	{
		if ($len > 0xff) $tag += 1;
		$h = chr($tag);
		if ($len > 0xff) $h .= chr($len / 0x100);
		$h .= chr($len % 0x100);
		return $h;
	}
	private function gpg_session($key_id, $key_type, $session_key, $public_key)
	{
		$mod = array();
		$exp = array();
		$enc = "";

		$s = base64_decode($public_key);
		$l = floor((ord($s[0]) * 256 + ord($s[1]) + 7) / 8);
		$mod = mpi2b(substr($s, 0, $l + 2));
		if ($key_type)
		{
			$grp = array();
			$y = array();
			$B = array();
			$C = array();
			$l2 = floor((ord($s[$l + 2]) * 256 + ord($s[$l + 3]) + 7) / 8) + 2;
			$grp = mpi2b(substr($s, $l + 2, $l2));
			$y = mpi2b(substr($s, $l + 2 + $l2));
			$exp[0] = $this->el[GPG_Utility::c_random() & 7];
			$B = bmodexp($grp, $exp, $mod);
			$C = bmodexp($y, $exp, $mod);
		}
		else
		{
			$exp = mpi2b(substr($s, $l + 2));
		}
		$c = 0;
		$lsk = strlen($session_key);
		for($i = 0; $i < $lsk; $i++) $c += ord($session_key[$i]);
		$c &= 0xffff;
		$lm = ($l - 2) * 8 + 2;
		$m = chr($lm / 256) . chr($lm % 256) .
			chr(2) . GPG_Utility::s_random($l - $lsk - 6, 1) . "\0" .
			chr(7) . $session_key .
			chr($c / 256) . chr($c & 0xff);
		if($key_type) {
			$enc = b2mpi($B) . b2mpi(bmod(bmul(mpi2b($m), $C), $mod));
			return $this->gpg_header(0x84,strlen($enc) + 10) .
				chr(3) . $key_id . chr(16) . $enc;
		} else {
			$enc = b2mpi(bmodexp(mpi2b($m), $exp, $mod));
			return $this->gpg_header(0x84, strlen($enc) + 10) .
				chr(3) . $key_id . chr(1) . $enc;
		}
	}
	private function gpg_literal($text)
	{
		if (strpos($text, "\r\n") === false)
			$text = str_replace("\n", "\r\n", $text);
		return
		$this->gpg_header(0xac, strlen($text) + 10) . "t" .
			chr(4) . "file\0\0\0\0" . $text;
	}
	private function gpg_data($key, $text)
	{
		$enc = $this->gpg_encrypt($key, $this->gpg_literal($text));
		return $this->gpg_header(0xa4, strlen($enc)) . $enc;
	}
	/**
	 * GPG Encypts a message to the provided public key
	 *
	 * @param GPG_Public_Key $pk
	 * @param string $plaintext
	 * @return string encrypted text
	 */
	function encrypt($pk, $plaintext)
	{
		// normalize the public key
		$key_id = $pk->GetKeyId();
		$key_type = $pk->GetKeyType();
		$public_key = $pk->GetPublicKey();
		$session_key = GPG_Utility::s_random($this->width, 0);
		$key_id = GPG_Utility::hex2bin($key_id);
		$cp = $this->gpg_session($key_id, $key_type, $session_key, $public_key) .
			$this->gpg_data($session_key, $plaintext);
		$code = base64_encode($cp);
		$code = wordwrap($code, 60, "\n", 1);
		return
			"-----BEGIN PGP MESSAGE-----\nVersion: VerySimple PHP-GPG v".$this->version."\n\n" .
			$code . "\n=" . base64_encode(GPG_Utility::crc24($cp)) .
			"\n-----END PGP MESSAGE-----\n";
	}
}

/**
 * @package    php-gpg::GPG
 */
class GPG_AES
{
	static function encrypt($block, $ctx)
	{
		$RCON = GPG_Cipher::$RCON;
		$S = GPG_Cipher::$S;

		$T1 = GPG_Cipher::$T1;
		$T2 = GPG_Cipher::$T2;
		$T3 = GPG_Cipher::$T3;
		$T4 = GPG_Cipher::$T4;

		$r = 0;
		$t0 = 0;
		$t1 = 0;
		$t2 = 0;
		$t3 = 0;

		$b = GPG_Utility::pack_octets($block);
		$rounds = $ctx->rounds;
		$b0 = $b[0];
		$b1 = $b[1];
		$b2 = $b[2];
		$b3 = $b[3];

		for($r = 0; $r < $rounds - 1; $r++) {
			$t0 = $b0 ^ $ctx->rk[$r][0];
			$t1 = $b1 ^ $ctx->rk[$r][1];
			$t2 = $b2 ^ $ctx->rk[$r][2];
			$t3 = $b3 ^ $ctx->rk[$r][3];

			$b0 = $T1[$t0 & 255] ^ $T2[($t1 >> 8) & 255] ^ $T3[($t2 >> 16) & 255] ^ $T4[GPG_Utility::zshift($t3, 24)];
			$b1 = $T1[$t1 & 255] ^ $T2[($t2 >> 8) & 255] ^ $T3[($t3 >> 16) & 255] ^ $T4[GPG_Utility::zshift($t0, 24)];
			$b2 = $T1[$t2 & 255] ^ $T2[($t3 >> 8) & 255] ^ $T3[($t0 >> 16) & 255] ^ $T4[GPG_Utility::zshift($t1, 24)];
			$b3 = $T1[$t3 & 255] ^ $T2[($t0 >> 8) & 255] ^ $T3[($t1 >> 16) & 255] ^ $T4[GPG_Utility::zshift($t2, 24)];
		}

		$r = $rounds - 1;

		$t0 = $b0 ^ $ctx->rk[$r][0];
		$t1 = $b1 ^ $ctx->rk[$r][1];
		$t2 = $b2 ^ $ctx->rk[$r][2];
		$t3 = $b3 ^ $ctx->rk[$r][3];

		$b[0] = GPG_Cipher::F1($t0, $t1, $t2, $t3) ^ $ctx->rk[$rounds][0];
		$b[1] = GPG_Cipher::F1($t1, $t2, $t3, $t0) ^ $ctx->rk[$rounds][1];
		$b[2] = GPG_Cipher::F1($t2, $t3, $t0, $t1) ^ $ctx->rk[$rounds][2];
		$b[3] = GPG_Cipher::F1($t3, $t0, $t1, $t2) ^ $ctx->rk[$rounds][3];

		return GPG_Utility::unpack_octets($b);
	}
}

/**
 * @package    php-gpg::GPG
 */
class GPG_Cipher
{
	/*
	global $RCON;
	global $S;
	global $T1;
	global $T2;
	global $T3;
	global $T4;
	global $maxkc;
	global $maxrk;
	*/

	static $maxkc = 8;
	static $maxrk = 14;
	static $RCON = array(
			0x01, 0x02, 0x04, 0x08, 0x10, 0x20,
			0x40, 0x80, 0x1b, 0x36, 0x6c, 0xd8,
			0xab, 0x4d, 0x9a, 0x2f, 0x5e, 0xbc,
			0x63, 0xc6, 0x97, 0x35, 0x6a, 0xd4,
			0xb3, 0x7d, 0xfa, 0xef, 0xc5, 0x91
			);
	static $S = array(
			99, 124, 119, 123, 242, 107, 111, 197,  48,   1, 103,  43, 254, 215, 171,
			118, 202, 130, 201, 125, 250,  89,  71, 240, 173, 212, 162, 175, 156, 164,
			114, 192, 183, 253, 147,  38,  54,  63, 247, 204,  52, 165, 229, 241, 113,
			216,  49,  21,   4, 199,  35, 195,  24, 150,   5, 154,   7,  18, 128, 226,
			235,  39, 178, 117,   9, 131,  44,  26,  27, 110,  90, 160,  82,  59, 214,
			179,  41, 227,  47, 132,  83, 209,   0, 237,  32, 252, 177,  91, 106, 203,
			190,  57,  74,  76,  88, 207, 208, 239, 170, 251,  67,  77,  51, 133,  69,
			249,   2, 127,  80,  60, 159, 168,  81, 163,  64, 143, 146, 157,  56, 245,
			188, 182, 218,  33,  16, 255, 243, 210, 205,  12,  19, 236,  95, 151,  68,
			23,  196, 167, 126,  61, 100,  93,  25, 115,  96, 129,  79, 220,  34,  42,
			144, 136,  70, 238, 184,  20, 222,  94,  11, 219, 224,  50,  58,  10,  73,
			6,  36,  92, 194, 211, 172,  98, 145, 149, 228, 121, 231, 200,  55, 109,
			141, 213,  78, 169, 108,  86, 244, 234, 101, 122, 174,   8, 186, 120,  37,
			46,  28, 166, 180, 198, 232, 221, 116,  31,  75, 189, 139, 138, 112,  62,
			181, 102,  72,   3, 246,  14,  97,  53,  87, 185, 134, 193,  29, 158, 225,
			248, 152,  17, 105, 217, 142, 148, 155,  30, 135, 233, 206,  85,  40, 223,
			140, 161, 137,  13, 191, 230,  66, 104,  65, 153,  45,  15, 176,  84, 187,
			22
			);
	static $T1 = array(
			0xa56363c6, 0x847c7cf8, 0x997777ee, 0x8d7b7bf6,
			0x0df2f2ff, 0xbd6b6bd6, 0xb16f6fde, 0x54c5c591,
			0x50303060, 0x03010102, 0xa96767ce, 0x7d2b2b56,
			0x19fefee7, 0x62d7d7b5, 0xe6abab4d, 0x9a7676ec,
			0x45caca8f, 0x9d82821f, 0x40c9c989, 0x877d7dfa,
			0x15fafaef, 0xeb5959b2, 0xc947478e, 0x0bf0f0fb,
			0xecadad41, 0x67d4d4b3, 0xfda2a25f, 0xeaafaf45,
			0xbf9c9c23, 0xf7a4a453, 0x967272e4, 0x5bc0c09b,
			0xc2b7b775, 0x1cfdfde1, 0xae93933d, 0x6a26264c,
			0x5a36366c, 0x413f3f7e, 0x02f7f7f5, 0x4fcccc83,
			0x5c343468, 0xf4a5a551, 0x34e5e5d1, 0x08f1f1f9,
			0x937171e2, 0x73d8d8ab, 0x53313162, 0x3f15152a,
			0x0c040408, 0x52c7c795, 0x65232346, 0x5ec3c39d,
			0x28181830, 0xa1969637, 0x0f05050a, 0xb59a9a2f,
			0x0907070e, 0x36121224, 0x9b80801b, 0x3de2e2df,
			0x26ebebcd, 0x6927274e, 0xcdb2b27f, 0x9f7575ea,
			0x1b090912, 0x9e83831d, 0x742c2c58, 0x2e1a1a34,
			0x2d1b1b36, 0xb26e6edc, 0xee5a5ab4, 0xfba0a05b,
			0xf65252a4, 0x4d3b3b76, 0x61d6d6b7, 0xceb3b37d,
			0x7b292952, 0x3ee3e3dd, 0x712f2f5e, 0x97848413,
			0xf55353a6, 0x68d1d1b9, 0x00000000, 0x2cededc1,
			0x60202040, 0x1ffcfce3, 0xc8b1b179, 0xed5b5bb6,
			0xbe6a6ad4, 0x46cbcb8d, 0xd9bebe67, 0x4b393972,
			0xde4a4a94, 0xd44c4c98, 0xe85858b0, 0x4acfcf85,
			0x6bd0d0bb, 0x2aefefc5, 0xe5aaaa4f, 0x16fbfbed,
			0xc5434386, 0xd74d4d9a, 0x55333366, 0x94858511,
			0xcf45458a, 0x10f9f9e9, 0x06020204, 0x817f7ffe,
			0xf05050a0, 0x443c3c78, 0xba9f9f25, 0xe3a8a84b,
			0xf35151a2, 0xfea3a35d, 0xc0404080, 0x8a8f8f05,
			0xad92923f, 0xbc9d9d21, 0x48383870, 0x04f5f5f1,
			0xdfbcbc63, 0xc1b6b677, 0x75dadaaf, 0x63212142,
			0x30101020, 0x1affffe5, 0x0ef3f3fd, 0x6dd2d2bf,
			0x4ccdcd81, 0x140c0c18, 0x35131326, 0x2fececc3,
			0xe15f5fbe, 0xa2979735, 0xcc444488, 0x3917172e,
			0x57c4c493, 0xf2a7a755, 0x827e7efc, 0x473d3d7a,
			0xac6464c8, 0xe75d5dba, 0x2b191932, 0x957373e6,
			0xa06060c0, 0x98818119, 0xd14f4f9e, 0x7fdcdca3,
			0x66222244, 0x7e2a2a54, 0xab90903b, 0x8388880b,
			0xca46468c, 0x29eeeec7, 0xd3b8b86b, 0x3c141428,
			0x79dedea7, 0xe25e5ebc, 0x1d0b0b16, 0x76dbdbad,
			0x3be0e0db, 0x56323264, 0x4e3a3a74, 0x1e0a0a14,
			0xdb494992, 0x0a06060c, 0x6c242448, 0xe45c5cb8,
			0x5dc2c29f, 0x6ed3d3bd, 0xefacac43, 0xa66262c4,
			0xa8919139, 0xa4959531, 0x37e4e4d3, 0x8b7979f2,
			0x32e7e7d5, 0x43c8c88b, 0x5937376e, 0xb76d6dda,
			0x8c8d8d01, 0x64d5d5b1, 0xd24e4e9c, 0xe0a9a949,
			0xb46c6cd8, 0xfa5656ac, 0x07f4f4f3, 0x25eaeacf,
			0xaf6565ca, 0x8e7a7af4, 0xe9aeae47, 0x18080810,
			0xd5baba6f, 0x887878f0, 0x6f25254a, 0x722e2e5c,
			0x241c1c38, 0xf1a6a657, 0xc7b4b473, 0x51c6c697,
			0x23e8e8cb, 0x7cdddda1, 0x9c7474e8, 0x211f1f3e,
			0xdd4b4b96, 0xdcbdbd61, 0x868b8b0d, 0x858a8a0f,
			0x907070e0, 0x423e3e7c, 0xc4b5b571, 0xaa6666cc,
			0xd8484890, 0x05030306, 0x01f6f6f7, 0x120e0e1c,
			0xa36161c2, 0x5f35356a, 0xf95757ae, 0xd0b9b969,
			0x91868617, 0x58c1c199, 0x271d1d3a, 0xb99e9e27,
			0x38e1e1d9, 0x13f8f8eb, 0xb398982b, 0x33111122,
			0xbb6969d2, 0x70d9d9a9, 0x898e8e07, 0xa7949433,
			0xb69b9b2d, 0x221e1e3c, 0x92878715, 0x20e9e9c9,
			0x49cece87, 0xff5555aa, 0x78282850, 0x7adfdfa5,
			0x8f8c8c03, 0xf8a1a159, 0x80898909, 0x170d0d1a,
			0xdabfbf65, 0x31e6e6d7, 0xc6424284, 0xb86868d0,
			0xc3414182, 0xb0999929, 0x772d2d5a, 0x110f0f1e,
			0xcbb0b07b, 0xfc5454a8, 0xd6bbbb6d, 0x3a16162c
			);
	static $T2 = array(
			0x6363c6a5, 0x7c7cf884, 0x7777ee99, 0x7b7bf68d,
			0xf2f2ff0d, 0x6b6bd6bd, 0x6f6fdeb1, 0xc5c59154,
			0x30306050, 0x01010203, 0x6767cea9, 0x2b2b567d,
			0xfefee719, 0xd7d7b562, 0xabab4de6, 0x7676ec9a,
			0xcaca8f45, 0x82821f9d, 0xc9c98940, 0x7d7dfa87,
			0xfafaef15, 0x5959b2eb, 0x47478ec9, 0xf0f0fb0b,
			0xadad41ec, 0xd4d4b367, 0xa2a25ffd, 0xafaf45ea,
			0x9c9c23bf, 0xa4a453f7, 0x7272e496, 0xc0c09b5b,
			0xb7b775c2, 0xfdfde11c, 0x93933dae, 0x26264c6a,
			0x36366c5a, 0x3f3f7e41, 0xf7f7f502, 0xcccc834f,
			0x3434685c, 0xa5a551f4, 0xe5e5d134, 0xf1f1f908,
			0x7171e293, 0xd8d8ab73, 0x31316253, 0x15152a3f,
			0x0404080c, 0xc7c79552, 0x23234665, 0xc3c39d5e,
			0x18183028, 0x969637a1, 0x05050a0f, 0x9a9a2fb5,
			0x07070e09, 0x12122436, 0x80801b9b, 0xe2e2df3d,
			0xebebcd26, 0x27274e69, 0xb2b27fcd, 0x7575ea9f,
			0x0909121b, 0x83831d9e, 0x2c2c5874, 0x1a1a342e,
			0x1b1b362d, 0x6e6edcb2, 0x5a5ab4ee, 0xa0a05bfb,
			0x5252a4f6, 0x3b3b764d, 0xd6d6b761, 0xb3b37dce,
			0x2929527b, 0xe3e3dd3e, 0x2f2f5e71, 0x84841397,
			0x5353a6f5, 0xd1d1b968, 0x00000000, 0xededc12c,
			0x20204060, 0xfcfce31f, 0xb1b179c8, 0x5b5bb6ed,
			0x6a6ad4be, 0xcbcb8d46, 0xbebe67d9, 0x3939724b,
			0x4a4a94de, 0x4c4c98d4, 0x5858b0e8, 0xcfcf854a,
			0xd0d0bb6b, 0xefefc52a, 0xaaaa4fe5, 0xfbfbed16,
			0x434386c5, 0x4d4d9ad7, 0x33336655, 0x85851194,
			0x45458acf, 0xf9f9e910, 0x02020406, 0x7f7ffe81,
			0x5050a0f0, 0x3c3c7844, 0x9f9f25ba, 0xa8a84be3,
			0x5151a2f3, 0xa3a35dfe, 0x404080c0, 0x8f8f058a,
			0x92923fad, 0x9d9d21bc, 0x38387048, 0xf5f5f104,
			0xbcbc63df, 0xb6b677c1, 0xdadaaf75, 0x21214263,
			0x10102030, 0xffffe51a, 0xf3f3fd0e, 0xd2d2bf6d,
			0xcdcd814c, 0x0c0c1814, 0x13132635, 0xececc32f,
			0x5f5fbee1, 0x979735a2, 0x444488cc, 0x17172e39,
			0xc4c49357, 0xa7a755f2, 0x7e7efc82, 0x3d3d7a47,
			0x6464c8ac, 0x5d5dbae7, 0x1919322b, 0x7373e695,
			0x6060c0a0, 0x81811998, 0x4f4f9ed1, 0xdcdca37f,
			0x22224466, 0x2a2a547e, 0x90903bab, 0x88880b83,
			0x46468cca, 0xeeeec729, 0xb8b86bd3, 0x1414283c,
			0xdedea779, 0x5e5ebce2, 0x0b0b161d, 0xdbdbad76,
			0xe0e0db3b, 0x32326456, 0x3a3a744e, 0x0a0a141e,
			0x494992db, 0x06060c0a, 0x2424486c, 0x5c5cb8e4,
			0xc2c29f5d, 0xd3d3bd6e, 0xacac43ef, 0x6262c4a6,
			0x919139a8, 0x959531a4, 0xe4e4d337, 0x7979f28b,
			0xe7e7d532, 0xc8c88b43, 0x37376e59, 0x6d6ddab7,
			0x8d8d018c, 0xd5d5b164, 0x4e4e9cd2, 0xa9a949e0,
			0x6c6cd8b4, 0x5656acfa, 0xf4f4f307, 0xeaeacf25,
			0x6565caaf, 0x7a7af48e, 0xaeae47e9, 0x08081018,
			0xbaba6fd5, 0x7878f088, 0x25254a6f, 0x2e2e5c72,
			0x1c1c3824, 0xa6a657f1, 0xb4b473c7, 0xc6c69751,
			0xe8e8cb23, 0xdddda17c, 0x7474e89c, 0x1f1f3e21,
			0x4b4b96dd, 0xbdbd61dc, 0x8b8b0d86, 0x8a8a0f85,
			0x7070e090, 0x3e3e7c42, 0xb5b571c4, 0x6666ccaa,
			0x484890d8, 0x03030605, 0xf6f6f701, 0x0e0e1c12,
			0x6161c2a3, 0x35356a5f, 0x5757aef9, 0xb9b969d0,
			0x86861791, 0xc1c19958, 0x1d1d3a27, 0x9e9e27b9,
			0xe1e1d938, 0xf8f8eb13, 0x98982bb3, 0x11112233,
			0x6969d2bb, 0xd9d9a970, 0x8e8e0789, 0x949433a7,
			0x9b9b2db6, 0x1e1e3c22, 0x87871592, 0xe9e9c920,
			0xcece8749, 0x5555aaff, 0x28285078, 0xdfdfa57a,
			0x8c8c038f, 0xa1a159f8, 0x89890980, 0x0d0d1a17,
			0xbfbf65da, 0xe6e6d731, 0x424284c6, 0x6868d0b8,
			0x414182c3, 0x999929b0, 0x2d2d5a77, 0x0f0f1e11,
			0xb0b07bcb, 0x5454a8fc, 0xbbbb6dd6, 0x16162c3a
			);
	static $T3 = array(
			0x63c6a563, 0x7cf8847c, 0x77ee9977, 0x7bf68d7b,
			0xf2ff0df2, 0x6bd6bd6b, 0x6fdeb16f, 0xc59154c5,
			0x30605030, 0x01020301, 0x67cea967, 0x2b567d2b,
			0xfee719fe, 0xd7b562d7, 0xab4de6ab, 0x76ec9a76,
			0xca8f45ca, 0x821f9d82, 0xc98940c9, 0x7dfa877d,
			0xfaef15fa, 0x59b2eb59, 0x478ec947, 0xf0fb0bf0,
			0xad41ecad, 0xd4b367d4, 0xa25ffda2, 0xaf45eaaf,
			0x9c23bf9c, 0xa453f7a4, 0x72e49672, 0xc09b5bc0,
			0xb775c2b7, 0xfde11cfd, 0x933dae93, 0x264c6a26,
			0x366c5a36, 0x3f7e413f, 0xf7f502f7, 0xcc834fcc,
			0x34685c34, 0xa551f4a5, 0xe5d134e5, 0xf1f908f1,
			0x71e29371, 0xd8ab73d8, 0x31625331, 0x152a3f15,
			0x04080c04, 0xc79552c7, 0x23466523, 0xc39d5ec3,
			0x18302818, 0x9637a196, 0x050a0f05, 0x9a2fb59a,
			0x070e0907, 0x12243612, 0x801b9b80, 0xe2df3de2,
			0xebcd26eb, 0x274e6927, 0xb27fcdb2, 0x75ea9f75,
			0x09121b09, 0x831d9e83, 0x2c58742c, 0x1a342e1a,
			0x1b362d1b, 0x6edcb26e, 0x5ab4ee5a, 0xa05bfba0,
			0x52a4f652, 0x3b764d3b, 0xd6b761d6, 0xb37dceb3,
			0x29527b29, 0xe3dd3ee3, 0x2f5e712f, 0x84139784,
			0x53a6f553, 0xd1b968d1, 0x00000000, 0xedc12ced,
			0x20406020, 0xfce31ffc, 0xb179c8b1, 0x5bb6ed5b,
			0x6ad4be6a, 0xcb8d46cb, 0xbe67d9be, 0x39724b39,
			0x4a94de4a, 0x4c98d44c, 0x58b0e858, 0xcf854acf,
			0xd0bb6bd0, 0xefc52aef, 0xaa4fe5aa, 0xfbed16fb,
			0x4386c543, 0x4d9ad74d, 0x33665533, 0x85119485,
			0x458acf45, 0xf9e910f9, 0x02040602, 0x7ffe817f,
			0x50a0f050, 0x3c78443c, 0x9f25ba9f, 0xa84be3a8,
			0x51a2f351, 0xa35dfea3, 0x4080c040, 0x8f058a8f,
			0x923fad92, 0x9d21bc9d, 0x38704838, 0xf5f104f5,
			0xbc63dfbc, 0xb677c1b6, 0xdaaf75da, 0x21426321,
			0x10203010, 0xffe51aff, 0xf3fd0ef3, 0xd2bf6dd2,
			0xcd814ccd, 0x0c18140c, 0x13263513, 0xecc32fec,
			0x5fbee15f, 0x9735a297, 0x4488cc44, 0x172e3917,
			0xc49357c4, 0xa755f2a7, 0x7efc827e, 0x3d7a473d,
			0x64c8ac64, 0x5dbae75d, 0x19322b19, 0x73e69573,
			0x60c0a060, 0x81199881, 0x4f9ed14f, 0xdca37fdc,
			0x22446622, 0x2a547e2a, 0x903bab90, 0x880b8388,
			0x468cca46, 0xeec729ee, 0xb86bd3b8, 0x14283c14,
			0xdea779de, 0x5ebce25e, 0x0b161d0b, 0xdbad76db,
			0xe0db3be0, 0x32645632, 0x3a744e3a, 0x0a141e0a,
			0x4992db49, 0x060c0a06, 0x24486c24, 0x5cb8e45c,
			0xc29f5dc2, 0xd3bd6ed3, 0xac43efac, 0x62c4a662,
			0x9139a891, 0x9531a495, 0xe4d337e4, 0x79f28b79,
			0xe7d532e7, 0xc88b43c8, 0x376e5937, 0x6ddab76d,
			0x8d018c8d, 0xd5b164d5, 0x4e9cd24e, 0xa949e0a9,
			0x6cd8b46c, 0x56acfa56, 0xf4f307f4, 0xeacf25ea,
			0x65caaf65, 0x7af48e7a, 0xae47e9ae, 0x08101808,
			0xba6fd5ba, 0x78f08878, 0x254a6f25, 0x2e5c722e,
			0x1c38241c, 0xa657f1a6, 0xb473c7b4, 0xc69751c6,
			0xe8cb23e8, 0xdda17cdd, 0x74e89c74, 0x1f3e211f,
			0x4b96dd4b, 0xbd61dcbd, 0x8b0d868b, 0x8a0f858a,
			0x70e09070, 0x3e7c423e, 0xb571c4b5, 0x66ccaa66,
			0x4890d848, 0x03060503, 0xf6f701f6, 0x0e1c120e,
			0x61c2a361, 0x356a5f35, 0x57aef957, 0xb969d0b9,
			0x86179186, 0xc19958c1, 0x1d3a271d, 0x9e27b99e,
			0xe1d938e1, 0xf8eb13f8, 0x982bb398, 0x11223311,
			0x69d2bb69, 0xd9a970d9, 0x8e07898e, 0x9433a794,
			0x9b2db69b, 0x1e3c221e, 0x87159287, 0xe9c920e9,
			0xce8749ce, 0x55aaff55, 0x28507828, 0xdfa57adf,
			0x8c038f8c, 0xa159f8a1, 0x89098089, 0x0d1a170d,
			0xbf65dabf, 0xe6d731e6, 0x4284c642, 0x68d0b868,
			0x4182c341, 0x9929b099, 0x2d5a772d, 0x0f1e110f,
			0xb07bcbb0, 0x54a8fc54, 0xbb6dd6bb, 0x162c3a16
			);
	static $T4 = array(
			0xc6a56363, 0xf8847c7c, 0xee997777, 0xf68d7b7b,
			0xff0df2f2, 0xd6bd6b6b, 0xdeb16f6f, 0x9154c5c5,
			0x60503030, 0x02030101, 0xcea96767, 0x567d2b2b,
			0xe719fefe, 0xb562d7d7, 0x4de6abab, 0xec9a7676,
			0x8f45caca, 0x1f9d8282, 0x8940c9c9, 0xfa877d7d,
			0xef15fafa, 0xb2eb5959, 0x8ec94747, 0xfb0bf0f0,
			0x41ecadad, 0xb367d4d4, 0x5ffda2a2, 0x45eaafaf,
			0x23bf9c9c, 0x53f7a4a4, 0xe4967272, 0x9b5bc0c0,
			0x75c2b7b7, 0xe11cfdfd, 0x3dae9393, 0x4c6a2626,
			0x6c5a3636, 0x7e413f3f, 0xf502f7f7, 0x834fcccc,
			0x685c3434, 0x51f4a5a5, 0xd134e5e5, 0xf908f1f1,
			0xe2937171, 0xab73d8d8, 0x62533131, 0x2a3f1515,
			0x080c0404, 0x9552c7c7, 0x46652323, 0x9d5ec3c3,
			0x30281818, 0x37a19696, 0x0a0f0505, 0x2fb59a9a,
			0x0e090707, 0x24361212, 0x1b9b8080, 0xdf3de2e2,
			0xcd26ebeb, 0x4e692727, 0x7fcdb2b2, 0xea9f7575,
			0x121b0909, 0x1d9e8383, 0x58742c2c, 0x342e1a1a,
			0x362d1b1b, 0xdcb26e6e, 0xb4ee5a5a, 0x5bfba0a0,
			0xa4f65252, 0x764d3b3b, 0xb761d6d6, 0x7dceb3b3,
			0x527b2929, 0xdd3ee3e3, 0x5e712f2f, 0x13978484,
			0xa6f55353, 0xb968d1d1, 0x00000000, 0xc12ceded,
			0x40602020, 0xe31ffcfc, 0x79c8b1b1, 0xb6ed5b5b,
			0xd4be6a6a, 0x8d46cbcb, 0x67d9bebe, 0x724b3939,
			0x94de4a4a, 0x98d44c4c, 0xb0e85858, 0x854acfcf,
			0xbb6bd0d0, 0xc52aefef, 0x4fe5aaaa, 0xed16fbfb,
			0x86c54343, 0x9ad74d4d, 0x66553333, 0x11948585,
			0x8acf4545, 0xe910f9f9, 0x04060202, 0xfe817f7f,
			0xa0f05050, 0x78443c3c, 0x25ba9f9f, 0x4be3a8a8,
			0xa2f35151, 0x5dfea3a3, 0x80c04040, 0x058a8f8f,
			0x3fad9292, 0x21bc9d9d, 0x70483838, 0xf104f5f5,
			0x63dfbcbc, 0x77c1b6b6, 0xaf75dada, 0x42632121,
			0x20301010, 0xe51affff, 0xfd0ef3f3, 0xbf6dd2d2,
			0x814ccdcd, 0x18140c0c, 0x26351313, 0xc32fecec,
			0xbee15f5f, 0x35a29797, 0x88cc4444, 0x2e391717,
			0x9357c4c4, 0x55f2a7a7, 0xfc827e7e, 0x7a473d3d,
			0xc8ac6464, 0xbae75d5d, 0x322b1919, 0xe6957373,
			0xc0a06060, 0x19988181, 0x9ed14f4f, 0xa37fdcdc,
			0x44662222, 0x547e2a2a, 0x3bab9090, 0x0b838888,
			0x8cca4646, 0xc729eeee, 0x6bd3b8b8, 0x283c1414,
			0xa779dede, 0xbce25e5e, 0x161d0b0b, 0xad76dbdb,
			0xdb3be0e0, 0x64563232, 0x744e3a3a, 0x141e0a0a,
			0x92db4949, 0x0c0a0606, 0x486c2424, 0xb8e45c5c,
			0x9f5dc2c2, 0xbd6ed3d3, 0x43efacac, 0xc4a66262,
			0x39a89191, 0x31a49595, 0xd337e4e4, 0xf28b7979,
			0xd532e7e7, 0x8b43c8c8, 0x6e593737, 0xdab76d6d,
			0x018c8d8d, 0xb164d5d5, 0x9cd24e4e, 0x49e0a9a9,
			0xd8b46c6c, 0xacfa5656, 0xf307f4f4, 0xcf25eaea,
			0xcaaf6565, 0xf48e7a7a, 0x47e9aeae, 0x10180808,
			0x6fd5baba, 0xf0887878, 0x4a6f2525, 0x5c722e2e,
			0x38241c1c, 0x57f1a6a6, 0x73c7b4b4, 0x9751c6c6,
			0xcb23e8e8, 0xa17cdddd, 0xe89c7474, 0x3e211f1f,
			0x96dd4b4b, 0x61dcbdbd, 0x0d868b8b, 0x0f858a8a,
			0xe0907070, 0x7c423e3e, 0x71c4b5b5, 0xccaa6666,
			0x90d84848, 0x06050303, 0xf701f6f6, 0x1c120e0e,
			0xc2a36161, 0x6a5f3535, 0xaef95757, 0x69d0b9b9,
			0x17918686, 0x9958c1c1, 0x3a271d1d, 0x27b99e9e,
			0xd938e1e1, 0xeb13f8f8, 0x2bb39898, 0x22331111,
			0xd2bb6969, 0xa970d9d9, 0x07898e8e, 0x33a79494,
			0x2db69b9b, 0x3c221e1e, 0x15928787, 0xc920e9e9,
			0x8749cece, 0xaaff5555, 0x50782828, 0xa57adfdf,
			0x038f8c8c, 0x59f8a1a1, 0x09808989, 0x1a170d0d,
			0x65dabfbf, 0xd731e6e6, 0x84c64242, 0xd0b86868,
			0x82c34141, 0x29b09999, 0x5a772d2d, 0x1e110f0f,
			0x7bcbb0b0, 0xa8fc5454, 0x6dd6bbbb, 0x2c3a1616
			);
	static function F1($x0, $x1, $x2, $x3)
	{
		$T1 = GPG_Cipher::$T1;

		return
		GPG_Utility::B1($T1[$x0 & 0xff]) | (GPG_Utility::B1($T1[($x1 >> 0x8) & 0xff]) << 0x8) |
			(GPG_Utility::B1($T1[($x2 >> 0x10) & 0xff]) << 0x10) | (GPG_Utility::B1($T1[GPG_Utility::zshift($x3, 0x18)]) << 0x18);
	}
}

/**
 * @package    php-gpg::GPG
 */
class Expanded_Key {
    var $rounds;
    var $rk;
	function Expanded_Key($key) {
        $RCON = GPG_Cipher::$RCON;
        $S = GPG_Cipher::$S;
		$maxkc = GPG_Cipher::$maxkc;
		$maxrk = GPG_Cipher::$maxrk;
        $kc = 0;
        $i = 0;
        $j = 0;
        $r = 0;
        $t = 0;
        $rounds = 0;
        $keySched = array_fill(0, $maxrk + 1, 0);
        $keylen = strlen($key);
        $k = array_fill(0, $maxkc, 0);
        $tk = array_fill(0, $maxkc, 0);
        $rconpointer = 0;
        if ($keylen == 16) {
            $rounds = 10;
            $kc = 4;
        } else if ($keylen == 24) {
            $rounds = 12;
            $kc = 6;
        } else if ($keylen == 32) {
            $rounds = 14;
            $kc = 8;
        } else {
            return;
        }
        for($i = 0; $i < $maxrk + 1; $i++) $keySched[$i] = array_fill(0, 4, 0);
        for($i = 0, $j = 0; $j < $keylen; $j++, $i += 4) {
                if ($i < $keylen) {
                    $k[$j] = ord($key[$i]) | (ord($key[$i + 1]) << 0x8) |
                        (ord($key[$i + 2]) << 0x10) | (ord($key[$i + 3]) << 0x18);
                } else {
                    $k[$j] = 0;
                }
        }
        for($j = $kc - 1; $j >= 0; $j--) $tk[$j] = $k[$j];
        $r = 0;
        $t = 0;
        for($j = 0; ($j < $kc) && ($r < $rounds + 1); ) {
            for(; ($j < $kc) && ($t < 4); $j++, $t++) {
                $keySched[$r][$t] = $tk[$j];
            }
            if($t == 4) {
                $r++;
                $t = 0;
            }
        }
        while($r < $rounds + 1) {
            $temp = $tk[$kc - 1];
			$tk[0] ^= $S[GPG_Utility::B1($temp)] | ($S[GPG_Utility::B2($temp)] << 0x8) |
				($S[GPG_Utility::B3($temp)] << 0x10) | ($S[GPG_Utility::B0($temp)] << 0x18);
            $tk[0] ^= $RCON[$rconpointer++];
            if ($kc != 8) {
                for($j = 1; $j < $kc; $j++) $tk[$j] ^= $tk[$j - 1];
            } else {
                for($j = 1; $j < $kc / 2; $j++) $tk[$j] ^= $tk[$j - 1];

                $temp = $tk[$kc / 2 - 1];
				$tk[$kc / 2] ^= $S[GPG_Utility::B0($temp)] | ($S[GPG_Utility::B1($temp)] << 0x8) |
					($S[GPG_Utility::B2($temp)] << 0x10) | ($S[GPG_Utility::B3($temp)] << 0x18);
                for($j = $kc / 2 + 1; $j < $kc; $j++) $tk[$j] ^= $tk[$j - 1];
            }
            for($j = 0; ($j < $kc) && ($r < $rounds + 1); ) {
                for(; ($j < $kc) && ($t < 4); $j++, $t++) {
                    $keySched[$r][$t] = $tk[$j];
                }
                if($t == 4) {
                    $r++;
                    $t = 0;
                }
            }
        }

        $this->rounds = $rounds;
        $this->rk = $keySched;
        return $this;
    }
}

define("PK_TYPE_ELGAMAL", 1);
define("PK_TYPE_RSA", 0);
define("PK_TYPE_UNKNOWN", -1);
/**
 * Pure PHP implementation of PHP/GPG public key
 *
 * @package php-gpg::GPG
 * @link http://www.verysimple.com/
 * @copyright  1997-2011 VerySimple, Inc.
 * @license    http://www.gnu.org/licenses/lgpl.html  LGPL
 * @todo implement decryption
 * @version 1.0
 */
class GPG_Public_Key {
    var $version;
	var $fp;
	var $key_id;
	var $user;
	var $public_key;
	var $type;

	function IsValid()
	{
		return $this->version != -1 && $this->GetKeyType() != PK_TYPE_UNKNOWN;
	}

	function GetKeyType()
	{
		if (!strcmp($this->type, "ELGAMAL")) return PK_TYPE_ELGAMAL;
		if (!strcmp($this->type, "RSA")) return PK_TYPE_RSA;
		return PK_TYPE_UNKNOWN;
	}
	function GetFingerprint()
	{
		return strtoupper( trim(chunk_split($this->fp, 4, ' ')) );
	}

	function GetKeyId()
	{
		return (strlen($this->key_id) == 16) ? strtoupper($this->key_id) : '0000000000000000';
	}

	function GetPublicKey()
	{
		return str_replace("\n", "", $this->public_key);
	}

	function GPG_Public_Key($asc) {
		$found = 0;

		// normalize line breaks
		$asc = str_replace("\r\n", "\n", $asc);

		if (strpos($asc, "-----BEGIN PGP PUBLIC KEY BLOCK-----\n") === false)
			throw new Exception("Missing header block in Public Key");
		if (strpos($asc, "\n\n") === false)
			throw new Exception("Missing body delimiter in Public Key");

		if (strpos($asc, "\n-----END PGP PUBLIC KEY BLOCK-----") === false)
			throw new Exception("Missing footer block in Public Key");

		// get rid of everything except the base64 encoded key
		$headerbody = explode("\n\n", str_replace("\n-----END PGP PUBLIC KEY BLOCK-----", "", $asc), 2);
		$asc = trim($headerbody[1]);


		$len = 0;
		$s =  base64_decode($asc);
		$sa = str_split($s);

		for($i = 0; $i < strlen($s);) {
			$tag = ord($sa[$i++]);

			// echo 'TAG=' . $tag . '/';

			if(($tag & 128) == 0) break;

			if($tag & 64) {
				$tag &= 63;
				$len = ord($sa[$i++]);
				if ($len > 191 && $len < 224) $len = (($len - 192) << 8) + ord($sa[$i++]);
				else if ($len == 255) $len = (ord($sa[$i++]) << 24) + (ord($sa[$i++]) << 16) + (ord($sa[$i++]) << 8) + ord($sa[$i++]);
					else if ($len > 223 && len < 255) $len = (1 << ($len & 0x1f));
			} else {
				$len = $tag & 3;
				$tag = ($tag >> 2) & 15;
				if ($len == 0) $len = ord($sa[$i++]);
				else if($len == 1) $len = (ord($sa[$i++]) << 8) + ord($sa[$i++]);
					else if($len == 2) $len = (ord($sa[$i++]) << 24) + (ord($sa[$i++]) << 16) + (ord($sa[$i++]) << 8) + ord($sa[$i++]);
						else $len = strlen($s) - 1;
			}

			// echo $tag . ' ';

			if ($tag == 6 || $tag == 14) {
				$k = $i;
				$version = ord($sa[$i++]);
				$found = 1;
				$this->version = $version;

				$time = (ord($sa[$i++]) << 24) + (ord($sa[$i++]) << 16) + (ord($sa[$i++]) << 8) + ord($sa[$i++]);

				if($version == 2 || $version == 3) $valid = ord($sa[$i++]) << 8 + ord($sa[$i++]);

				$algo = ord($sa[$i++]);

				if($algo == 1 || $algo == 2) {
					$m = $i;
					$lm = floor((ord($sa[$i]) * 256 + ord($sa[$i + 1]) + 7) / 8);
					$i += $lm + 2;

					$mod = substr($s, $m, $lm + 2);
					$le = floor((ord($sa[$i]) * 256 + ord($sa[$i+1]) + 7) / 8);
					$i += $le + 2;

					$this->public_key = base64_encode(substr($s, $m, $lm + $le + 4));
					$this->type = "RSA";

					if ($version == 3) {
						$this->fp = '';
						$this->key_id = bin2hex(substr($mod, strlen($mod) - 8, 8));
					} else if($version == 4) {

						// https://tools.ietf.org/html/rfc4880#section-12
						$headerPos = strpos($s, chr(0x04));  // TODO: is this always the correct starting point for the pulic key packet 'version' field?
						$delim = chr(0x01) . chr(0x00);  // TODO: is this the correct delimiter for the end of the public key packet?
						$delimPos = strpos($s, $delim) + (3-$headerPos);

						// echo "POSITION: $delimPos\n";

						$pkt = chr(0x99) . chr($delimPos >> 8) . chr($delimPos & 255) . substr($s, $headerPos, $delimPos);

						// this is the original signing string which seems to have only worked for key lengths of 1024 or less
						//$pkt = chr(0x99) . chr($len >> 8) . chr($len & 255) . substr($s, $k, $len);

						$fp = sha1($pkt);
						$this->fp = $fp;
						$this->key_id = substr($fp, strlen($fp) - 16, 16);

						// uncomment to debug the start point for the signing string
// 						for ($ii = 5; $ii > -1; $ii--) {
// 							$pkt = chr(0x99) . chr($ii >> 8) . chr($ii & 255) . substr($s, $headerPos, $ii);
// 							$fp = sha1($pkt);
// 							echo "LENGTH=" . $headerPos . '->' . $ii . " CHR(" . ord(substr($s,$ii, 1)) . ") = " . substr($fp, strlen($fp) - 16, 16) . "\n";
// 						}
// 						echo "\n";

						// uncomment to debug the end point for the signing string
// 						for ($ii = strlen($s); $ii > 1; $ii--) {
// 							$pkt = chr(0x99) . chr($ii >> 8) . chr($ii & 255) . substr($s, $headerPos, $ii);
// 							$fp = sha1($pkt);
// 							echo "LENGTH=" . $headerPos . '->' . $ii . " CHR(" . ord(substr($s,$ii, 1)) . ") = " . substr($fp, strlen($fp) - 16, 16) . "\n";
// 						}
					} else {
						throw new Exception('GPG Key Version ' . $version . ' is not supported');
					}
					$found = 2;
				} else if(($algo == 16 || $algo == 20) && $version == 4) {
						$m = $i;

						$lp = floor((ord($sa[$i]) * 256 + ord($sa[$i +1]) + 7) / 8);
						$i += $lp + 2;

						$lg = floor((ord($sa[$i]) * 256 + ord($sa[$i + 1]) + 7) / 8);
						$i += $lg + 2;

						$ly = floor((ord($sa[$i]) * 256 + ord($sa[$i + 1]) + 7)/8);
						$i += $ly + 2;

						$this->public_key = base64_encode(substr($s, $m, $lp + $lg + $ly + 6));

						// TODO: should this be adjusted as it was for RSA (above)..?

						$pkt = chr(0x99) . chr($len >> 8) . chr($len & 255) . substr($s, $k, $len);
						$fp = sha1($pkt);
						$this->fp = $fp;
						$this->key_id = substr($fp, strlen($fp) - 16, 16);
						$this->type = "ELGAMAL";
						$found = 3;
					} else {
						$i = $k + $len;
					}
			} else if ($tag == 13) {
					$this->user = substr($s, $i, $len);
					$i += $len;
				} else {
					$i += $len;
				}
		}

		if($found < 2) {

			throw new Exception("Unable to parse Public Key");
// 			$this->version = "";
// 			$this->fp = "";
// 			$this->key_id = "";
// 			$this->user = "";
// 			$this->public_key = "";
		}
	}

	function GetExpandedKey()
	{
		$ek = new Expanded_Key($this->public_key);
	}
}

/** seed rand */
list($gpg_usec, $gpg_sec) = explode(' ', microtime());
srand((float) $gpg_sec + ((float) $gpg_usec * 100000));
/**
 * @package    php-gpg::GPG
 */
class GPG_Utility
{

	static function starts_with($haystack, $needle)
	{
		return $needle === "" || strpos($haystack, $needle) === 0;
	}

	static function B0($x) {
		return ($x & 0xff);
	}

	static function B1($x) {
		return (($x >> 0x8) & 0xff);
	}

	static function B2($x) {
		return (($x >> 0x10) & 0xff);
	}

	static function B3($x) {
		return (($x >> 0x18) & 0xff);
	}

	static function zshift($x, $s) {
		$res = $x >> $s;

		$pad = 0;
		for ($i = 0; $i < 32 - $s; $i++) $pad += (1 << $i);

		return $res & $pad;
	}

	static function pack_octets($octets)
	{
		$i = 0;
		$j = 0;
		$len = count($octets);
		$b = array_fill(0, $len / 4, 0);

		if (!$octets || $len % 4) return;

		for ($i = 0, $j = 0; $j < $len; $j += 4) {
			$b[$i++] = $octets[$j] | ($octets[$j + 1] << 0x8) | ($octets[$j + 2] << 0x10) | ($octets[$j + 3] << 0x18);

		}

		return $b;
	}

	static function unpack_octets($packed)
	{
		$j = 0;
		$i = 0;
		$l = count($packed);
		$r = array_fill(0, $l * 4, 0);

		for ($j = 0; $j < $l; $j++) {
			$r[$i++] = GPG_Utility::B0($packed[$j]);
			$r[$i++] = GPG_Utility::B1($packed[$j]);
			$r[$i++] = GPG_Utility::B2($packed[$j]);
			$r[$i++] = GPG_Utility::B3($packed[$j]);
		}

		return $r;
	}
	static function hex2bin($h)
	{
		if(strlen($h) % 2) $h += "0";
		$r = "";
		for($i = 0; $i < strlen($h); $i += 2) {
			$r .= chr(intval($h[$i], 16) * 16 + intval($h[$i + 1], 16));
		}
		return $r;
	}
	static function crc24($data)
	{
		$crc = 0xb704ce;
		for($n = 0; $n < strlen($data); $n++) {
			$crc ^= (ord($data[$n]) & 0xff) << 0x10;
			for($i = 0; $i < 8; $i++) {
				$crc <<= 1;
				if($crc & 0x1000000) $crc ^= 0x1864cfb;
			}
		}

		return
			chr(($crc >> 0x10) & 0xff) .
			chr(($crc >> 0x8) & 0xff) .
			chr($crc & 0xff);
	}
	static function s_random($len, $textmode)
	{
		$r = "";
		for($i = 0; $i < $len;)
		{
			$t = rand(0, 0xff);
			if($t == 0 && $textmode) continue;
			$i++;
			$r .= chr($t);
		}
		return $r;
	}
	static function c_random() {
		return round(rand(0, 0xff));
	}
}

/** assign globals */
global $bs;
global $bx2;
global $bm;
global $bx;
global $bd;
global $bdm;
$bs = 28;
$bx2 = 1 << $bs;
$bm = $bx2 - 1;
$bx = $bx2 >> 1;
$bd = $bs >> 1;
$bdm = (1 << $bd) - 1;
/**
 */
function mpi2b($s)
{
    global $bs;
    global $bx2;
    global $bm;
    global $bx;
    global $bd;
    global $bdm;
    $bn = 1;
    $r = array(0);
    $rn = 0;
    $sb = 256;
    $c = 0;
    $sn = strlen($s);
    if($sn < 2) {
        echo("string too short, not a MPI");
        return 0;
    }
    $len = ($sn - 2) * 8;
    $bits = ord($s[0]) * 256 + ord($s[1]);
    if ($bits > $len || $bits < $len - 8) {
        echo("not a MPI, bits = $bits, len = $len");
        return 0;
    }
    for ($n = 0; $n < $len; $n++) {
        if (($sb <<= 1) > 255) {
            $sb = 1; $c = ord($s[--$sn]);
        }
        if ($bn > $bm) {
            $bn = 1;
            $r[++$rn]=0;
        }
        if ($c & $sb) $r[$rn] |= $bn;
        $bn <<= 1;
    }
    return $r;
}
/**
 */
function b2mpi($b)
{
    global $bs;
    global $bx2;
    global $bm;
    global $bx;
    global $bd;
    global $bdm;
    $bn = 1;
    $bc = 0;
    $r = array(0);
    $rb = 1;
    $rn = 0;
    $bits = count($b) * $bs;
    $n = 0;
    $rr = "";
    for ($n = 0; $n < $bits; $n++) {
        if ($b[$bc] & $bn) $r[$rn] |= $rb;
        if(($rb <<= 1) > 255) {
            $rb = 1; $r[++$rn]=0;
        }
        if (($bn <<= 1) > $bm) {
            $bn=1; $bc++;
        }
    }
    while ($rn && $r[$rn]==0) $rn--;
    $bn=256;
    for($bits = 8; $bits > 0; $bits--) if ($r[$rn] & ($bn >>= 1)) break;
    $bits += $rn * 8;
    $rr .= chr($bits / 256 ) . chr($bits % 256);
    if ($bits) for($n = $rn; $n >= 0; $n--) $rr .= chr($r[$n]);
    return $rr;
}
/**
 */
function bmodexp($xx, $y, $m) {
    global $bs;
    global $bx2;
    global $bm;
    global $bx;
    global $bd;
    global $bdm;
    $r = array(1);
    $an = 0;
    $a = 0;
    $x = array_merge((array)$xx);
    $n = count($m) * 2;
    $mu = array_fill(0, $n + 1, 0);
    $mu[$n--] = 1;
    for(; $n >= 0; $n--) $mu[$n] = 0;
    $dd = new bdiv($mu, $m);
    $mu = $dd->q;
    for($n = 0; $n < count($y); $n++) {
        for ($a = 1, $an = 0; $an < $bs; $an++, $a <<= 1) {
            if ($y[$n] & $a) $r = bmod2(bmul($r, $x), $m, $mu);
            $x = bmod2(bmul($x, $x), $m, $mu);
        }
    }
    return $r;
}
/**
 */
function simplemod($i, $m) // returns the mod where m < 2^bd
{
    $c = 0;
    $v = 0;
    for ($n = count($i) - 1; $n >= 0; $n--)
    {
        $v = $i[$n];
        $c = (($v >> $bd) + ($c << $bd)) % $m;
        $c = (($v & $bdm) + ($c << $bd)) % $m;
    }
    return $c;
}
/**
 */
function bmod($p, $m) // binary modulo
{
    global $bdm;
    if (count($m) == 1) {
        if(count($p) == 1) return array($p[0] % $m[0]);
        if($m[0] < $bdm) return array(simplemod($p, $m[0]));
    }
    $r = new bdiv($p, $m);
    return $r->mod;
}
/**
 */
function bmod2($x, $m, $mu) {
    $xl = count($x) - (count($m) << 1);
    if ($xl > 0) return bmod2(array_concat(array_slice($x, 0, $xl), bmod2(array_slice($x, $xl), $m, $mu)), $m, $mu);
    $ml1 = count($m) + 1;
    $ml2 = count($m) - 1;
    $rr = 0;
    $q3 = array_slice(bmul(array_slice($x, $ml2), $mu), $ml1);
    $r1 = array_slice($x, 0, $ml1);
    $r2 = array_slice(bmul($q3, $m), 0, $ml1);
    $r = bsub($r1, $r2);
    if (count($r) == 0) {
        $r1[$ml1] = 1;
        $r = bsub($r1, $r2);
    }
    for ($n = 0;; $n++) {
        $rr = bsub($r, $m);
        if(count($rr) == 0) break;
        $r = $rr;
        if($n >= 3) return bmod2($r, $m, $mu);
    }
    return $r;
}
/**
 */
function toppart($x, $start, $len) {
    global $bx2;
    $n = 0;
    while ($start >= 0 && $len-- > 0) $n = $n * $bx2 + $x[$start--];
    return $n;
}
/**
 */
function zeros($n) {
    $r = array_fill(0, $n, 0);
    while ($n-- > 0) $r[$n] = 0;
    return $r;
}
/**
 * @package    verysimple::Encryption
 */
class bdiv {
	var $q;
	var $mod;
	function bdiv($x, $y)
	{
		global $bs;
		global $bx2;
		global $bm;
		global $bx;
		global $bd;
		global $bdm;
		$n = count($x) - 1;
		$t = count($y) - 1;
		$nmt = $n - $t;
		if ($n < $t || $n == $t && ($x[$n] < $y[$n] || $n > 0 && $x[$n] == $y[$n] && $x[$n - 1] < $y[$n - 1])) {
			$this->q = array(0);
			$this->mod = array($x);
			return;
		}
		if ($n == $t && toppart($x, $t, 2) / toppart($y, $t, 2) < 4) {
			$qq = 0;
			$xx = 0;
			for(;;) {
				$xx = bsub($x, $y);
				if(count($xx) == 0) break;
				$x = $xx; $qq++;
			}
			$this->q = array($qq);
			$this->mod = $x;
			return;
		}
		$shift2 = floor(log($y[$t]) / M_LN2) + 1;
		$shift = $bs - $shift2;
		if ($shift) {
			$x = array_merge((array)$x); $y = array_merge((array)$y);
			for($i = $t; $i > 0; $i--) $y[$i] = (($y[$i] << $shift) & $bm) | ($y[$i - 1] >> $shift2);
			$y[0] = ($y[0] << $shift) & $bm;
			if($x[$n] & (($bm << $shift2) & $bm)) {
				$x[++$n] = 0; $nmt++;
			}
			for($i = $n; $i > 0; $i--) $x[$i] = (($x[$i] << $shift) & $bm) | ($x[$i - 1] >> $shift2);
			$x[0] = ($x[0] << $shift) & $bm;
		}
		$i = 0;
		$j = 0;
		$x2 = 0;
		$q = zeros($nmt + 1);
		$y2 = array_merge(zeros($nmt), (array)$y);
		for (;;) {
			$x2 = bsub($x, $y2);
			if(count($x2) == 0) break;
			$q[$nmt]++;
			$x = $x2;
		}
		$yt = $y[$t];
		$top =toppart($y, $t, 2);
		for ($i = $n; $i > $t; $i--) {
			$m = $i - $t - 1;
			if ($i >= count($x)) $q[$m] = 1;
			else if($x[$i] == $yt) $q[$m] = $bm;
			else $q[$m] = floor(toppart($x, $i, 2) / $yt);
			$topx = toppart($x, $i, 3);
			while ($q[$m] * $top > $topx) $q[$m]--;
			$y2 = array_slice($y2, 1);
			$x2 = bsub($x, bmul(array($q[$m]), $y2));
			if (count($x2) == 0) {
				$q[$m]--;
				$x2 =bsub($x, bmul(array($q[m]), $y2));
			}
			$x = $x2;
		}
		if ($shift) {
			for($i = 0; $i < count($x) - 1; $i++) $x[$i] = ($x[$i] >> $shift) | (($x[$i + 1] << $shift2) & $bm);
			$x[count($x) - 1] >>= $shift;
		}
		$n = count($q);
		while ($n > 1 && $q[$n - 1] == 0) $n--;
		$this->q = array_slice($q, 0, $n);
		$n = count($x);
		while ($n > 1 && $x[$n - 1] == 0) $n--;
		$this->mod = array_slice($x, 0, $n);
	}
}
/**
 */
function bsub($a, $b) {
    global $bs;
    global $bx2;
    global $bm;
    global $bx;
    global $bd;
    global $bdm;
    $al = count($a);
    $bl = count($b);
    if ($bl > $al) return array();
    if ($bl == $al) {
        if($b[$bl - 1] > $a[$bl - 1]) return array();
        if($bl == 1) return array($a[0] - $b[0]);
    }
    $r = array_fill(0, $al, 0);
    $c = 0;
    for ($n = 0; $n < $bl; $n++) {
        $c += $a[$n] - $b[$n];
        $r[$n] = $c & $bm;
        $c >>= $bs;
    }
    for (; $n < $al; $n++) {
        $c += $a[$n];
        $r[$n] = $c & $bm;
        $c >>= $bs;
    }
    if ($c) return array();
    if ($r[$n - 1]) return $r;
    while ($n > 1 && $r[$n - 1] == 0) $n--;
    return array_slice($r, 0, $n);
}
/**
 */
function bmul($a, $b) {
    global $bs;
    global $bx2;
    global $bm;
    global $bx;
    global $bd;
    global $bdm;
    $b = array_merge((array)$b, array(0));
    $al = count($a);
    $bl = count($b);
    $n = 0;
    $nn = 0;
    $aa = 0;
    $c = 0;
    $m = 0;
    $g = 0;
    $gg = 0;
    $h = 0;
    $hh = 0;
    $ghh = 0;
    $ghhb = 0;
    $r = zeros($al + $bl + 1);
    for ($n = 0; $n < $al; $n++) {
        $aa = $a[$n];
        if ($aa) {
            $c = 0;
            $hh = $aa >> $bd; $h = $aa & $bdm;
            $m = $n;
            for ($nn = 0; $nn < $bl; $nn++, $m++) {
                $g = $b[$nn]; $gg = $g >> $bd; $g = $g & $bdm;
                $ghh = $g * $hh + $h * $gg;
                $ghhb = $ghh >> $bd; $ghh &= $bdm;
                $c += $r[$m] + $h * $g + ($ghh << $bd);
                $r[$m] = $c & $bm;
                $c = ($c >> $bs) + $gg * $hh + $ghhb;
            }
        }
    }
    $n = count($r);
    if ($r[$n - 1]) return $r;
    while ($n > 1 && $r[$n - 1] == 0) $n--;
    return array_slice($r, 0, $n);
}