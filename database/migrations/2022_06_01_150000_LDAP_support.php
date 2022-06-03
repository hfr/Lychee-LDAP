<?php

use App\Models\Configs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;

class LDAPSupport extends Migration
{
	private const USERS = 'users';
	private const NAME = 'display_name';

	private string $driverName;
	private ConsoleOutput $output;
	private ConsoleSectionOutput $msgSection;

	public function __construct()
	{
		$connection = Schema::connection(null)->getConnection();
		$this->driverName = $connection->getDriverName();
		$this->output = new ConsoleOutput();
		$this->msgSection = $this->output->section();
	}

	/**
	 * We set up the configuration for the public LDAP Server
	 * See https://www.forumsys.com/2022/05/10/online-ldap-test-server/.
	 *
	 * After enabling the LDAP authentication with ldap_enabled set to 1
	 * username: gauss and password: password can be used.
	 *
	 * /**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up(): void
	{
		if (!Schema::hasColumn(self::USERS, self::NAME)) {
			Schema::table(self::USERS, function (Blueprint $table) {
				$table->string(self::NAME, 128)->after('password')->nullable();
			});
		}

		DB::table('configs')->insert([
			[
				'key' => 'ldap_enabled',
				'value' => '0',
				'cat' => 'LDAP',
				'type_range' => '0|1',
				'confidentiality' => '2',
				'description' => 'LDAP login provider enabled',
			],
			[
				'key' => 'ldap_server',
				'value' => 'ldap.forumsys.com',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP server name',
			],
			[
				'key' => 'ldap_port',
				'value' => '389',
				'cat' => 'LDAP',
				'type_range' => 'int',
				'confidentiality' => '2',
				'description' => 'LDAP server port',
			],
			[
				'key' => 'ldap_user_tree',
				'value' => 'dc=example,dc=com',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP user tree',
			],
			[
				'key' => 'ldap_user_filter',
				'value' => '(uid=%{user})',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP user filter',
			],
			[
				'key' => 'ldap_version',
				'value' => '3',
				'cat' => 'LDAP',
				'type_range' => 'int',
				'confidentiality' => '2',
				'description' => 'LDAP protocol version',
			],
			[
				'key' => 'ldap_bind_dn',
				'value' => 'cn=read-only-admin,dc=example,dc=com',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP bind dn',
			],
			[
				'key' => 'ldap_bind_pw',
				'value' => 'password',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP bind password',
			],
			[
				'key' => 'ldap_upload_filter',
				'value' => '',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP filter for the upload option',
			],
			[
				'key' => 'ldap_user_key',
				'value' => 'uid',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP user key',
			],
			[
				'key' => 'ldap_user_scope',
				'value' => 'sub',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP user scope',
			],
			[
				'key' => 'ldap_start_tls',
				'value' => '0',
				'cat' => 'LDAP',
				'type_range' => '0|1',
				'confidentiality' => '2',
				'description' => 'LDAP use STARTTLS protocol',
			],
			[
				'key' => 'ldap_referrals',
				'value' => '-1',
				'cat' => 'LDAP',
				'type_range' => 'signed_int',
				'confidentiality' => '2',
				'description' => 'LDAP option referrals',
			],
			[
				'key' => 'ldap_deref',
				'value' => '0',
				'cat' => 'LDAP',
				'type_range' => '0|1',
				'confidentiality' => '2',
				'description' => 'LDAP option deref',
			],
			[
				'key' => 'ldap_cn',
				'value' => 'cn',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP common name',
			],
			[
				'key' => 'ldap_mail',
				'value' => 'mail',
				'cat' => 'LDAP',
				'type_range' => 'string',
				'confidentiality' => '2',
				'description' => 'LDAP mail entry',
			],
			[
				'key' => 'ldap_timeout',
				'value' => '1',
				'cat' => 'LDAP',
				'type_range' => 'int',
				'confidentiality' => '2',
				'description' => 'LDAP enables purging of obsolete users in lychee',
			],
			[
				'key' => 'ldap_purge',
				'value' => '0',
				'cat' => 'LDAP',
				'type_range' => '0|1',
				'confidentiality' => '2',
				'description' => 'LDAP enables purging of obsolete users in lychee',
			],
			[
				'key' => 'ldap_update_users',
				'value' => '0',
				'cat' => 'LDAP',
				'type_range' => 'int',
				'confidentiality' => '2',
				'description' => 'LDAP schedule interval for automatic sync of users in minutes',
			],
		]);

		// The requirement for email adresses being unique has to be dropped in case
		// the users are managed externally e.g. by an ldap-server. The reason is an inherent
		// update problem.
		// Since the external server needs to take care of the uniqueness of the e-mail address,
		// nothing can be done in lychee in case if it is not unique.
		// If the uid of a user (his login name) gets changed, but his e-mail address does not then
		// the user cannot login any more if lychee uses LDAP. The reason is that lychee cannot update
		// the users table since the e-mail already exists and without a new entry in the users table
		// a login is not possible.
		// The only solution would be to develop a clever purge strategy that deletes non-existing users
		// first and call this strategy in case the users account cannot be created during login.
		// But if the external login provider delivers non unique email addresses, there will be no way out.
		// Since the uniqueness of the email address is not a data requirement, but just a measure to prevent
		// that people not get registered twice, I suggest to drop the unique requirement in the database.

		Schema::table('users', function (Blueprint $table) {
			$table->dropUnique(['email']);
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down(): void
	{
		Configs::query()->where('key', '=', 'ldap_enabled')->delete();
		Configs::query()->where('key', '=', 'ldap_server')->delete();
		Configs::query()->where('key', '=', 'ldap_port')->delete();
		Configs::query()->where('key', '=', 'ldap_user_tree')->delete();
		Configs::query()->where('key', '=', 'ldap_user_filter')->delete();
		Configs::query()->where('key', '=', 'ldap_upload_filter')->delete();
		Configs::query()->where('key', '=', 'ldap_version')->delete();
		Configs::query()->where('key', '=', 'ldap_bind_dn')->delete();
		Configs::query()->where('key', '=', 'ldap_bind_pw')->delete();
		Configs::query()->where('key', '=', 'ldap_user_key')->delete();
		Configs::query()->where('key', '=', 'ldap_user_scope')->delete();
		Configs::query()->where('key', '=', 'ldap_starttls')->delete();
		Configs::query()->where('key', '=', 'ldap_referrals')->delete();
		Configs::query()->where('key', '=', 'ldap_deref')->delete();
		Configs::query()->where('key', '=', 'ldap_cn')->delete();
		Configs::query()->where('key', '=', 'ldap_mail')->delete();
		Configs::query()->where('key', '=', 'ldap_purge')->delete();
		Configs::query()->where('key', '=', 'ldap_update_users')->delete();

		Schema::table('users', function (Blueprint $table) {
			$table->Unique(['email']);
		});
		switch ($this->driverName) {
			case 'sqlite':
				$this->msgSection->writeln(sprintf('<comment>Warning:</comment> %s not removed as it breaks in SQLite. Please do it manually', self::NAME));
				break;
			case 'mysql':
			case 'pgsql':
				Schema::table(self::USERS, function (Blueprint $table) {
					$table->dropColumn(self::NAME);
				});
				break;
			default:
				throw new RuntimeException('Unsupported DBMS');
		}
	}
}
