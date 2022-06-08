<?php

namespace Tests\Feature;

use App\Facades\AccessControl;
use App\LDAP\FixedArray;
use App\LDAP\LDAPActions;
use App\LDAP\LDAPFunctions;
use App\Models\Configs;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\LDAPTestCase;

class LDAPActionsTest extends LDAPTestCase
{
	public function testLDAPActions()
	{
		$ldap = $this->get_ldap();
		if (!$ldap) {
			return;
		}
		try {
			AccessControl::log_as_id(0);
			try {
				$user_list = $ldap->get_user_list(true);
				$this->assertIsArray($user_list, 'The user list should be an array');
				$this->assertTrue(count($user_list) > 1, 'The user list should contain more than one entry');
				$dbuser = User::query()->where('id', '>', '0')->first();
				if (!empty($dbuser)) {
					$dbuser->delete();
				}
				LDAPActions::update_users($user_list, false);
				$user_data = ['user' => '!__not_existent__!', 'server' => 'unknown',
					'dn' => 'cn=not0exist', 'display_name' => 'Do not know', 'email' => 'no@mail', ];
				$user = new FixedArray(LDAPFunctions::USER_ENTRIES);
				$user->fromArray($user_data);
				LDAPActions::create_user_not_exist($user->user, $user);
				LDAPActions::update_user($user->user, $user);
				$dbuser2 = User::query()->where('username', '=', $user->user)->first();
				$this->assertTrue(!empty($dbuser2));
				$this->assertTrue($dbuser2->may_upload == false);

				$user->may_upload = true;
				LDAPActions::update_user($user->user, $user);
				$dbuser3 = User::query()->where('username', '=', $user->user)->first();
				$this->assertTrue(!empty($dbuser3));
				$this->assertTrue($dbuser3->may_upload == true);

				LDAPActions::update_users($user_list, true);
			} finally {
				AccessControl::logout();
			}
			$purge = Configs::get_value('ldap_purge', '0');
			$enabled = Configs::get_value('ldap_enabled', '0');
			try {
				Configs::set('ldap_purge', '1');
				Configs::set('ldap_enabled', '1');
				$exitCode = Artisan::call('lychee:LDAP_update_all_users');
				$this->assertTrue($exitCode == 0);
			} finally {
				Configs::set('ldap_purge', $purge);
				Configs::set('ldap_enabled', $enabled);
			}
		} finally {
			$this->done_ldap();
		}
	}

	public function testLDAPSchedule()
	{
		$ldap = $this->get_ldap();
		if (!$ldap) {
			return;
		}
		try {
			$purge = Configs::get_value('ldap_purge', '0');
			$enabled = Configs::get_value('ldap_enabled', '0');
			$schedule = Configs::get_value('ldap_update_users', '0');
			$bind_dn = Configs::get_value('ldap_bind_dn', '0');
			$caught = false;
			try {
				try {
					Configs::set('ldap_purge', '1');
					Configs::set('ldap_enabled', '1');
					Configs::set('ldap_update_users', '1');
					$exitCode = Artisan::call('schedule:run');
					$this->assertTrue($exitCode == 0);
					Configs::set('ldap_enabled', '0');
					$this->expectOutputString('LDAP is not enabled!' . PHP_EOL);
					$exitCode = Artisan::call('lychee:LDAP_update_all_users');
					$this->assertTrue($exitCode == 1);
				} catch (Exception $e) {
					$caught = $e;
				}
				$this->assertFalse($caught);
				Configs::set('ldap_enabled', '1');
				Configs::set('ldap_bind_dn', '');
				$this->expectOutputRegex('/Exception: App.Exceptions.LDAPException: No LDAP server available.*/');
				$exitCode = Artisan::call('lychee:LDAP_update_all_users');
				$this->assertTrue($exitCode == 2);
			} finally {
				Configs::set('ldap_purge', $purge);
				Configs::set('ldap_enabled', $enabled);
				Configs::set('ldap_update_users', $schedule);
				Configs::set('ldap_bind_dn', $bind_dn);
			}
		} finally {
			$this->done_ldap();
		}
	}
}
