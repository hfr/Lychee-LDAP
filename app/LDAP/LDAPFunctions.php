<?php

namespace App\LDAP;

use App\Exceptions\Handler;
use App\Exceptions\LDAPException;
use App\Models\Configs;
use App\Models\Logs;

class LDAPFunctions
{
	public const SCOPE_BASE = 'base';
	public const SCOPE_ONE = 'one';
	public const SCOPE_SUB = 'sub';
	public const USER_ENTRIES = ['user', 'server', 'dn', 'display_name', 'email', 'may_upload'];
	protected const CONFIG_KEY_BIND_DN = 'ldap_bind_dn';
	protected const CONFIG_KEY_BIND_PW = 'ldap_bind_pw';
	protected const CONFIG_KEY_CN = 'ldap_cn';
	protected const CONFIG_KEY_DEREF = 'ldap_deref';
	protected const CONFIG_KEY_MAIL = 'ldap_mail';
	protected const CONFIG_KEY_PORT = 'ldap_port';
	protected const CONFIG_KEY_REFERRALS = 'ldap_referrals';
	protected const CONFIG_KEY_SERVER = 'ldap_server';
	protected const CONFIG_KEY_START_TLS = 'ldap_start_tls';
	protected const CONFIG_KEY_USER_KEY = 'ldap_user_key';
	protected const CONFIG_KEY_USER_TREE = 'ldap_user_tree';
	protected const CONFIG_KEY_USER_FILTER = 'ldap_user_filter';
	protected const CONFIG_KEY_USER_SCOPE = 'ldap_user_scope';
	protected const CONFIG_KEY_VERSION = 'ldap_version';
	protected const CONFIG_KEY_TIMEOUT = 'ldap_timeout';
	protected const CONFIG_KEY_UPLOAD_FILTER = 'ldap_upload_filter';
	protected const BIND_TYPE_UNBOUND = -1;
	protected const BIND_TYPE_ANONYMOUS = 0;
	protected const BIND_TYPE_USER = 1;
	protected const BIND_TYPE_SUPER_USER = 2;
	protected const LDAP_VERSION_UNKNOWN = 0;
	protected const LDAP_VERSION_2 = 2;
	protected const LDAP_VERSION_3 = 3;

	/**
	 * @var \LDAP\Connection|resource|null the LDAP connection
	 */
	protected $con = null;

	/**
	 * @var int reference count for open/close
	 */
	protected $open_ref_count = 0;

	/**
	 * @var string|null used server name
	 */
	protected ?string $ldap_server = null;

	/**
	 * Type of LDAP binding.
	 *
	 * Either
	 *
	 *  - {@link LDAPFunctions::BIND_TYPE_UNBOUND},
	 *  - {@link LDAPFunctions::BIND_TYPE_ANONYMOUS},
	 *  - {@link LDAPFunctions::BIND_TYPE_USER},
	 *  - {@link LDAPFunctions::BIND_TYPE_SUPER_USER}.
	 *
	 * @var int
	 */
	protected int $bound = self::BIND_TYPE_UNBOUND;

	/** @var FixedArray[] cashed results of user info previously queried from LDAP */
	protected array $cached_user_info = [];

	/** @var FixedArray[] cashed user list retrieved by get_user_list() */
	protected ?array $user_list = null;

	/**
	 * Check user+password.
	 *
	 * Checks if the given user exists and the given
	 * plaintext password is correct by trying to bind
	 * to the LDAP server
	 *
	 * @param string $user
	 * @param string $pass
	 * @param bool   $handleExceptions
	 *
	 * @return bool
	 *
	 * @throws LDAPException
	 */
	public function check_pass(string $user, string $pass): bool
	{
		$this->open_LDAP();
		try {
			// Option A: If we know how to bind a user, we try that directly
			if (strpos(Configs::get_value(self::CONFIG_KEY_USER_TREE), '%{user}')) {
				Logs::debug(__METHOD__, __LINE__, 'Option A: If we know how to bind a user, we try that directly');
				// direct user bind
				$dn = self::make_filter(
												Configs::get_value(self::CONFIG_KEY_USER_TREE),
												['user' => $user, 'server' => $this->ldap_server]
								);
				// User/Password bind
				if ($this->LDAP_bind($dn, $pass)) {
					return true;
				}
			}

			// Option B: We do not know how to bind a user, so we must first
			// search the directory
			// See if we can find the user
			Logs::debug(__METHOD__, __LINE__, 'Option B: We do not know how to bind a user, so we must first search the directory');
			$info = $this->get_user_data($user);
			if (is_null($info) || empty($info->dn)) {
				return false;
			}
			// Try to re-bind with the dn provided
			try {
				return $this->LDAP_bind($info->dn, $pass);
			} catch (LDAPException) {
				return false;
			}
		} catch (LDAPException $e) {
			throw new LDAPException('Exception in check_pass:', $e);
		} finally {
			$this->close_LDAP();
		}
	}

	/**
	 * Reads the user data from the LDAP server.
	 *
	 * @param string $username
	 *
	 * @return FixedArray contains null or the user data
	 *
	 * @throws LDAPException
	 */
	public function get_user_data(string $username): ?FixedArray
	{
		if (!empty($this->cached_user_info) && array_key_exists($username, $this->cached_user_info)) {
			Logs::notice(__METHOD__, __LINE__, sprintf('getUserData: Use cached info for %s', $username));

			return $this->cached_user_info[$username];
		}

		$this->open_LDAP();
		try {
			// get info for given user
			$user = ['user' => $username, 'server' => $this->ldap_server];
			$base = self::make_filter(Configs::get_value(self::CONFIG_KEY_USER_TREE), $user);
			Logs::notice(__METHOD__, __LINE__, sprintf('base filter: %s', $base));

			if (Configs::get_value(self::CONFIG_KEY_USER_FILTER)) {
				$filter = self::make_filter(Configs::get_value(self::CONFIG_KEY_USER_FILTER), $user);
			} else {
				$filter = '(ObjectClass=*)';
			}
			Logs::notice(__METHOD__, __LINE__, sprintf('filter: %s', $filter));

			$result = $this->LDAP_search($base, $filter, Configs::get_value(self::CONFIG_KEY_USER_SCOPE));

			// Only accept one response
			if ($result['count'] == 0) {
				return null;
			} elseif ($result['count'] > 1) {
				throw new LDAPException(sprintf('LDAP search returned %d results while it should return 1!', $result['count']));
			}

			$userData = $this->userdata_from_ldap_result($result[0]);
			$userData->user = $username;

			if (Configs::get_value(self::CONFIG_KEY_UPLOAD_FILTER)) {
				$uploadfilter = self::make_filter(Configs::get_value(self::CONFIG_KEY_UPLOAD_FILTER), $user);
				Logs::notice(__METHOD__, __LINE__, sprintf('uploadfilter: %s', $uploadfilter));
				$result = $this->LDAP_search($base, $uploadfilter, Configs::get_value(self::CONFIG_KEY_USER_SCOPE));
				if ($result['count'] > 0) {
					$userData->may_upload = true;
					Logs::notice(__METHOD__, __LINE__, 'may_uploadfilter: true');
				} else {
					$userData->may_upload = false;
				}
			}

			// cache the info for future use
			$this->cached_user_info[$username] = $userData;

			return $userData;
		} finally {
			$this->close_LDAP();
		}
	}

	/**
	 * Get a list of users from the LDAP server.
	 *
	 * @param bool $refresh
	 *
	 * @return array of FixedArray
	 *
	 * @throws LDAPException
	 */
	public function get_user_list(bool $refresh = false): array
	{
		if (is_null($this->user_list) || $refresh) {
			$this->open_LDAP();
			try {
				$this->user_list = [];
				// Perform the search and grab all their details
				if (Configs::get_value(self::CONFIG_KEY_USER_FILTER)) {
					$all_filter = str_replace('%{user}', '*', Configs::get_value(self::CONFIG_KEY_USER_FILTER));
				} else {
					$all_filter = '(ObjectClass=*)';
				}
				$entries = $this->LDAP_search(Configs::get_value(self::CONFIG_KEY_USER_TREE), $all_filter);
				$userkey = Configs::get_value(self::CONFIG_KEY_USER_KEY, 'uid');
				$extrated_entries = [];
				self::extract_user_data($userkey, $entries, $extrated_entries);
				$entries = $extrated_entries;
				for ($i = 0; $i < count($entries); $i++) {
					$this->user_list[$entries[$i][$userkey][0]] = $this->userdata_from_ldap_result($entries[$i]);
				}
			} finally {
				$this->close_LDAP();
			}
		}

		return $this->user_list;
	}

	/**
	 * Extract the user data from the reply of the LDAP server.
	 *
	 * @param string $userkey
	 * @param array  $entries
	 * @param array  &$result
	 *
	 * The user data are the sub arrays where the userkey is a valid key. They can be located anywhere
	 * in the tree given by the entries. The function will recursively scan the whole array structure to find them.
	 * If an array contains the userkey as a key, it is treated as a leaf. All leafs are added to the results.
	 * The result is an array structure, where every element has a userkey as a key.
	 */
	protected static function extract_user_data(string $userkey, array $entries, array &$result): void
	{
		if (array_key_exists($userkey, $entries)) {
			array_push($result, $entries);
		} else {
			for ($i = 0; $i < $entries['count']; $i++) {
				if (is_array($entries[$i])) {
					self::extract_user_data($userkey, $entries[$i], $result);
				}
			}
		}
	}

	/**
	 * Wraps around ldap_search, ldap_list or ldap_read depending on $scope.
	 *
	 * @param string $base_dn
	 * @param string $filter
	 * @param string $scope      either {@link LDAPFunctions::SCOPE_BASE}, {@link LDAPFunctions::SCOPE_ONE} or {@link LDAPFunctions::SCOPE_SUB}
	 * @param array  $attributes
	 * @param int    $attrsonly
	 * @param int    $sizelimit
	 *
	 * @return array
	 *
	 * @throws LDAPException on error or if the user is unknown
	 */
	protected function LDAP_search(
				string $base_dn,
				string $filter,
				string $scope = self::SCOPE_SUB,
				?array $attributes = null,
				int $attrsonly = 0,
				int $sizelimit = 0
		): array {
		$this->LDAP_check_bind();
		// By default only the attributes which are needed, will be queried
		if (is_null($attributes)) {
			$attributes = [];
			if (Configs::get_value(self::CONFIG_KEY_USER_KEY, 'uid')) {
				array_push($attributes, Configs::get_value(self::CONFIG_KEY_USER_KEY, 'uid'));
			}
			if (Configs::get_value(self::CONFIG_KEY_CN, 'cn')) {
				array_push($attributes, Configs::get_value(self::CONFIG_KEY_CN, 'cn'));
			}
			if (Configs::get_value(self::CONFIG_KEY_MAIL, 'mail')) {
				array_push($attributes, Configs::get_value(self::CONFIG_KEY_MAIL, 'mail'));
			}
		}
		try {
			$sr = match ($scope) {
				self::SCOPE_BASE => ldap_read(
										$this->con,
										$base_dn,
										$filter,
										$attributes,
										$attrsonly,
										$sizelimit
								),
								self::SCOPE_ONE => ldap_list(
										$this->con,
										$base_dn,
										$filter,
										$attributes,
										$attrsonly,
										$sizelimit
								),
								self::SCOPE_SUB => ldap_search(
										$this->con,
										$base_dn,
										$filter,
										$attributes,
										$attrsonly,
										$sizelimit
								)
			};
			$result = ldap_get_entries($this->con, $sr);
			ldap_free_result($sr);
			Logs::debug(__METHOD__, __LINE__, 'ldap_search returned ' . $result['count'] . ' values');

			return $result;
		} catch (\Throwable $e) {
			Logs::debug(__METHOD__, __LINE__, 'Exception: ' . $e->getMessage());
			throw new LDAPException($e->getMessage(), $e);
		}
	}

	/**
	 * Bind to the LDAP server.
	 *
	 * If credentials are provided, the given credentials are used.
	 * If no credentials are set, then the method checks whether
	 * system-wide "super-user" credentials are configured and use those
	 * to bind.
	 * In any other case, i.e. no explicit credentials are passed and no
	 * super-user credentials are set, the method tries an anonymous bind.
	 *
	 * @param string|null $bindDN
	 * @param string|null $bindPassword
	 *
	 * @return void
	 *
	 * @throws LDAPException
	 */
	protected function LDAP_bind(?string $bindDN = null, ?string $bindPassword = null): bool
	{
		try {
			if (empty($bindDN)) {
				$bindDN = (string) Configs::get_value(self::CONFIG_KEY_BIND_DN);
				$bindPassword = (string) Configs::get_value(self::CONFIG_KEY_BIND_PW);
				$this->bound = empty($bindDN) ? self::BIND_TYPE_ANONYMOUS : self::BIND_TYPE_SUPER_USER;
			} else {
				$this->bound = self::BIND_TYPE_USER;
			}
			Logs::debug(__METHOD__, __LINE__, 'LDAP bind with ' . $bindDN . ':' . $bindPassword . ' [bound = ' . $this->bound . ']');

			$is_bound = ldap_bind($this->con, $bindDN, $bindPassword);

			// @codeCoverageIgnoreStart
			if (!$is_bound) {
				$this->bound = self::BIND_TYPE_UNBOUND;
			}
			// @codeCoverageIgnoreEnd

			Logs::debug(__METHOD__, __LINE__, 'LDAP bind result: ' . $is_bound);

			return $is_bound;
		} catch (\Throwable $e) {
			Logs::debug(__METHOD__, __LINE__, 'Exception: ' . $e->getMessage());
			$this->bound = self::BIND_TYPE_UNBOUND;
			throw new LDAPException($e->getMessage(), $e);
		}
	}

	/**
	 * Check if the bound level is sufficient and bind if neccessary.
	 *
	 * @return void
	 *
	 * @throws LDAPException implicit via LDAP_bind()
	 */
	protected function LDAP_check_bind(): void
	{
		$req_level = (Configs::get_value(self::CONFIG_KEY_BIND_DN) && Configs::get_value(self::CONFIG_KEY_BIND_PW)) ? self::BIND_TYPE_SUPER_USER : self::BIND_TYPE_ANONYMOUS;
		Logs::debug(__METHOD__, __LINE__, 'LDAP_check_bind: ' . $this->bound . ' [required: ' . $req_level . ']');
		// force superuser or anonymous bind if the bound level is not sufficient yet
		if ($this->bound < $req_level) {
			Logs::debug(__METHOD__, __LINE__, 'New bind is required');
			// use anonymous or superuser credentials
			if (!$this->LDAP_bind()) {
				// @codeCoverageIgnoreStart
				$msg = 'Required bind was not possible';
				Logs::debug(__METHOD__, __LINE__, $msg);
				throw new LDAPException($msg);
				// @codeCoverageIgnoreEnd
			}
		}
	}

	/**
	 * Wraps around ldap_set_option.
	 *
	 * @param int    $opt
	 * @param string $value
	 *
	 * @return void
	 *
	 * @throws LDAPException
	 */
	protected function LDAP_set_option(int $opt, string $value): void
	{
		Logs::debug(__METHOD__, __LINE__, 'Set option ' . $opt . ' = ' . $value);
		try {
			if (!ldap_set_option($this->con, $opt, $value)) {
				throw new LDAPException('ldap_errno=' . ldap_errno($this->con));
			}
		} catch (\Throwable $e) {
			throw new LDAPException($e->getMessage(), $e);
		}
	}

	/**
	 * Wraps around ldap_get_option.
	 *
	 * @param int    $opt
	 * @param string $value
	 *
	 * @return void
	 *
	 * @throws LDAPException
	 */
	protected function LDAP_get_option(int $opt, array|string|int &$value = null): void
	{
		if (!ldap_get_option($this->con, $opt, $value)) {
			throw new LDAPException('Cannot get the value for option: ' . $opt);
		}
	}

	/**
	 * Warp around ldap_start_tls.
	 *
	 * @return void
	 *
	 * @throws LDAPException
	 */
	protected function LDAP_start_tls(): void
	{
		Logs::debug(__METHOD__, __LINE__, 'Set START TLS');
		try {
			if (!ldap_start_tls($this->con)) {
				// @codeCoverageIgnoreStart
				throw new LdapException('ldap_stat_tls failed');
				// @codeCoverageIgnoreEnd
			}
		} catch (\Throwable $e) {
			throw new LDAPException($e->getMessage(), $e);
		}
	}

	/**
	 * Converts a ldap user entry into a FixedArray.
	 *
	 * @param array $user_result
	 *
	 * @return LFixedArray contains the user data
	 */
	protected function userdata_from_ldap_result(array $user_result): FixedArray
	{
		$userData = new FixedArray(self::USER_ENTRIES);

		// general user info
		$userData->dn = $user_result['dn'];
		$userData->user = $user_result[Configs::get_value(self::CONFIG_KEY_USER_KEY, 'uid')][0];
		$userData->display_name = $user_result[Configs::get_value(self::CONFIG_KEY_CN, 'cn')][0];
		if (array_key_exists(Configs::get_value(self::CONFIG_KEY_MAIL, 'mail'), $user_result)) {
			$userData->email = $user_result[Configs::get_value(self::CONFIG_KEY_MAIL, 'mail')][0];
		} else {
			$userData->email = '';
		}
		$userData->server = $this->ldap_server;

		return $userData;
	}

	/**
	 * Escape a string to be used in an LDAP filter.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	protected static function filter_escape(string $string): string
	{
		// see https://github.com/adldap/adLDAP/issues/22
		return preg_replace_callback(
						'/([\x00-\x1F*()\\\\])/',
						function ($matches) {
							return '\\' . join('', unpack('H2', $matches[1]));
						},
						$string
				);
	}

	/**
	 * Make LDAP filter strings.
	 *
	 * @param string $filter       ldap search filter with placeholders
	 * @param array  $placeholders placeholders to fill in
	 *
	 * @return string
	 */
	protected static function make_filter(string $filter, array $placeholders): string
	{
		preg_match_all('/%{([^}]+)/', $filter, $matches, PREG_PATTERN_ORDER);
		// replace each match
		foreach ($matches[1] as $match) {
			// take first element if array
			if (is_array($placeholders[$match])) {
				$value = $placeholders[$match][0];
			} else {
				$value = $placeholders[$match];
			}
			$value = self::filter_escape($value);
			$filter = str_replace('%{' . $match . '}', $value, $filter);
		}

		return $filter;
	}

	/**
	 * Test if a server is available.
	 *
	 * This method connects to the server and returns the conection if possible.
	 */
	protected function connect(string $host, int $port = 389, $timeout = 1, $retry = 0)
	{
		for ($i = 0; $i < $retry + 1; $i++) {
			if (!$timeout) {
				try {
					$c = ldap_connect($host, $port);
					if ($c) {
						return $c;
					}
				} catch (\Throwable) {
					break;
				}
			} else {
				$chost = $host;
				$cport = $port;
				$sv = explode(':', $host);
				if (count($sv) > 1) {
					$chost = ltrim($sv[1], '/');
					$prot = reset($sv);
					if (count($sv) < 3) {
						$cport = match ($prot) {
							'ldap' => 389,
														'ldaps' => 636,
														default => 389
						};
					} else {
						$cport = (int) end($sv);
					}
				}
				if (empty($chost) || empty($cport)) {
					return false;
				}
				try {
					// The use of sockets is safe here because no data will be transferred.
					// The socket is only used to verify that the ldap server can be connected.
					$check_con = fsockopen($chost, $cport, $errno, $errstr, $timeout); // NOSONAR
				} catch (\ErrorException) {
					$check_con = false;
				}
				if ($check_con) {
					fclose($check_con); // explicitly close open socket connection

					$c = ldap_connect($host, $port);
					if ($c) {
						return $c;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Prepares/opens a connection to the configured LDAP server and sets the
	 * wanted option on the connection.
	 *
	 * This method binds to the server.
	 *
	 * @throws LDAPException
	 */
	protected function open_LDAP(): void
	{
		$this->open_ref_count++;
		if ($this->con) {
			return;
		} // connection already established
		// ldap extension is needed
		if (!extension_loaded('ldap')) {
			// @codeCoverageIgnoreStart
			throw new LDAPException('PHP LDAP extension not found.');
			// @codeCoverageIgnoreEnd
		}

		$this->bound = self::BIND_TYPE_UNBOUND;
		$port = (int) Configs::get_value(self::CONFIG_KEY_PORT);
		$servers = explode(',', Configs::get_value(self::CONFIG_KEY_SERVER));
		foreach ($servers as $server) {
			try {
				$server = trim($server);
				$this->con = $this->connect($server, $port, (int) Configs::get_value(self::CONFIG_KEY_TIMEOUT, '1'));
				$OK = ($this->con !== false);
				Logs::notice(__METHOD__, __LINE__, sprintf('Try to connect %s on port %s: %s', $server, $port, $OK ? 'OK' : 'NO'));
				if (!$OK) {
					continue;
				}
				/**
				 * We have acquired a connection \o/.
				 */
				/*
				 * When open_LDAP 2.x.x is used, ldap_connect() will always return a resource as it does
				 * not actually connect but just initializes the connecting parameters. The actual
				 * connect happens with the next calls to ldap_* functions, usually with ldap_bind().
				 *
				 * So we should try to bind to server in order to check its availability.
				 */

				// set protocol version
				$ldap_version = (int) Configs::get_value(self::CONFIG_KEY_VERSION, self::LDAP_VERSION_UNKNOWN);
				if ($ldap_version !== self::LDAP_VERSION_UNKNOWN) {
					$this->LDAP_set_option(LDAP_OPT_PROTOCOL_VERSION, $ldap_version);
					Logs::notice(__METHOD__, __LINE__, sprintf('Using protocol version %s', $ldap_version));
				}

				// Some options are only valid in combination with version 3
				if ($ldap_version === self::LDAP_VERSION_3) {
					// use TLS (needs version 3)
					if (Configs::get_value(self::CONFIG_KEY_START_TLS)) {
						$this->LDAP_start_tls();
					}

					$ldap_referals = Configs::get_value(self::CONFIG_KEY_REFERRALS);
					if ($ldap_referals > -1) {
						// @codeCoverageIgnoreStart
						$this->LDAP_set_option(LDAP_OPT_REFERRALS, $ldap_referals);
						// @codeCoverageIgnoreEnd
					}
				}

				// set deref mode
				$ldap_deref = Configs::get_value(self::CONFIG_KEY_DEREF);
				if ($ldap_deref) {
					// @codeCoverageIgnoreStart
					$this->LDAP_set_option(LDAP_OPT_DEREF, $ldap_deref);
					// @codeCoverageIgnoreEnd
				}
				$this->LDAP_set_option(LDAP_OPT_NETWORK_TIMEOUT, 1);
				$OK = $this->LDAP_bind();
				if ($OK) {
					$this->ldap_server = $server;

					return;
				}
			}
			// @codeCoverageIgnoreStart
			catch (\Throwable $e) {
				Logs::debug(__METHOD__, __LINE__, 'Exception: ' . $e->getMessage());
				Handler::reportSafely($e);
			}
			// @codeCoverageIgnoreEnd
		}
		$msg = 'No LDAP server available';
		Logs::debug(__METHOD__, __LINE__, $msg);
		throw new LDAPException($msg);
	}

	/**
	 * Closes the connection with the LDAP server.
	 *
	 * @throws LDAPException
	 */
	protected function close_LDAP(): void
	{
		if ($this->open_ref_count > 1) {
			$this->open_ref_count--;

			return;
		}
		if (!$this->con) {
			$this->open_ref_count = 0;

			return;
		} // connection has not been established
		Logs::debug(__METHOD__, __LINE__, 'close_LDAP()');
		try {
			$this->open_ref_count = 0;
			ldap_close($this->con);
			$this->con = null;
			$this->bound = self::BIND_TYPE_UNBOUND;
		}
		// @codeCoverageIgnoreStart
		catch (\Throwable $e) {
			Handler::reportSafely($e);
		}
		// @codeCoverageIgnoreEnd
	}
}
