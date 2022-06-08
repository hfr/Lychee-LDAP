
## LDAP interface for Lychee

### Installation

To use the the LDAP functionality it needs to enabled after installation.

Follow the standard method to install lychee (see [Installation](https://lycheeorg.github.io/docs/installation.html)). Once lychee is running it 
can be configured via the settings dialog, which is available for the admin user.

*Warning: After intsallation a puplic LDAP test-server is configured but not enabled. It can be enabled to test the installation but it should be changed after all tests are complete.*

### Configuration

To use LDAP as the login provider for lychee an LDAP server needs be configured in lychee. See section LDAP in the administrator settings.

### Settings

Setting the basic settings should be enough to enable the LDAP interface for Lychee. If needed advance options 
are available with the advanced settings.

#### Basic Settings 

| Setting            | Description                                                   | Type       | Default Value                 |
|--------------------|---------------------------------------------------------------|:----------:|-------------------------------|
| ldap_enabled       | LDAP login provider enabled                                   | 0/1        | 0                             |
| ldap_server        | LDAP server name                                              | string     |                               |
| ldap_port          | LDAP server port                                              | int        | 389                           |
| ldap_bind_dn       | LDAP bind dn                                                  | string     |                               |
| ldap_bind_pw       | LDAP bind password                                            | string     |                               |
| ldap_user_tree     | LDAP user tree                                                | string     |                               |
| ldap_user_filter   | LDAP user filter                                              | string     | (uid=%{user})                 |
| ldap_upload_filter | LDAP filter for the upload option                             | string     |                               |

The LDAP login provider is only used if ldap_enabled is set to 1.

In ldap_server the LDAP server name need to be set. For redundant installations a list of servers separated by comma can be used.

The bind dn might should be set if the LDAP server does not support annonymous binding together with the password of the binding account.

In ldap_user_tree the base dn need to be set, where the users can be found. This dn is used in all LDAP searches.

In ldap_user_filter the filter for selecting valid lychee users need to be set. %{user} will be replaced by the login name.

In ldap_upload_filter a filter for selecting lychee users which are allowed to upload files to lychee. This option needs to be set if the option should be managed by the LDAP server. Usually the LDAP server manages this option by a group membership and then the a string to filter for this group membership need to be set in ldap_upload_filter.

#### Advanced Settings

| Setting           | Description                                                   | Type       | Default Value                 |
|-------------------|---------------------------------------------------------------|:----------:|-------------------------------|
| ldap_version      | LDAP protocol version                                         | int        | 3                             |
| ldap_user_key     | LDAP user key                                                 | string     | uid                           |
| ldap_user_scope   | LDAP user scope                                               | string     | sub                           |
| ldap_start_tls    | LDAP use STARTTLS protocol                                    | 0/1        | 0                             |
| ldap_referrals    | LDAP option referrals                                         | signed_int | -1                            |
| ldap_deref        | LDAP option deref                                             | 0/1        | 0                             |
| ldap_cn           | LDAP common name                                              | string     | cn                            |
| ldap_mail         | LDAP mail entry                                               | string     | mail                          |
| ldap_timeout      | LDAP connect timeout                                          | int        | 1                             |

#### Database Update Settings

| Setting           | Description                                                   | Type       | Default Value                 |
|-------------------|---------------------------------------------------------------|:----------:|-------------------------------|
| ldap_purge        | LDAP enables purging of obsolete users in lychee              | 0/1        | 0                             |
| ldap_update_users | LDAP schedule interval for automatic sync of users in minutes | int        | 0                             |

### Testing the LDAP Interface for Lychee

The LDAP interface for lychee can be tested using the public LDAP server from [Forum Systems](https://www.forumsys.com/2022/05/10/online-ldap-test-server/) 
with the follwing configuration:

| Setting           | Description                                                   | Value                                |
|-------------------|---------------------------------------------------------------|--------------------------------------|
| ldap_enabled      | LDAP login provider enabled                                   | 1                                    |
| ldap_server       | LDAP server name                                              | ldap.forumsys.com                    |
| ldap_port         | LDAP server port                                              | 389                                  |
| ldap_user_tree    | LDAP user tree                                                | dc=example,dc=com                    |
| ldap_user_filter  | LDAP user filter                                              | (uid=%{user})                        |
| ldap_bind_dn      | LDAP bind dn                                                  | cn=read-only-admin,dc=example,dc=com |
| ldap_bind_pw      | LDAP bind password                                            | password                             |

Valid usernames and passwords for this server are: riemann:password, gauss:password, euler:password, euclid:password.

### Synchronizing Lychee with the LDAP Server

Lychee always relies on the LDAP server for the decission if a user can login to lychee or not. So only users which can be validated against the LADP server can login.

In addition users can share pictures and albums between them and therefore the list of users needs to be kept up to date in lychee.

The LDAP interface for Lychee support the synchonization with the following command:

`php artisan lychee:LDAP_update_all_users`

By default obsolete users are purged from the list of lychee users. If the users should be kept in the database even if the 
LDAP server do not know them any more, the following entry in the settings needs to be set to zero: `ldap_purge = 0`.

The synchronization can be automated, by configuring a cron-job to execute `/path-to-php/php artisan schedule:run 2>&1 >/dev/null` every minute. Based on this
 lychee runs its own scheduler to execute its jobs.

The frequency to run the snchonization between lyche and the LADP server can be controlled in the administration settings with 
the entry `ldap_update_users`. A typical value for the update frequency is 5 minutes. Then this value needs to be set to 5. The default value of zero
switches the automatic update off. If `ldap_enable = 1` the synchronisation can be performed by executing the `php artisan lychee:LDAP_update_all_users` command.

### Synchronizing Lychee by Monitoring the LDAP Database

For the synchronization of the lychee users table the script ``/usr/local/sbin/update-lychee`` might be used:

```
#!/bin/bash
#
LYCHEE_USER="www-data"

LDAP_UPDATE='/usr/bin/php $HOME/Lychee-LDAP-dev/artisan lychee:LDAP_update_all_users'

if [ "$USER" == "$LYCHEE_USER" ]; then 
  /bin/sh -c '/usr/bin/php $HOME/Lychee-LDAP-dev/artisan lychee:LDAP_update_all_users'
else
  /usr/bin/su www-data -s /bin/sh -c '/usr/bin/php $HOME/Lychee-LDAP-dev/artisan lychee:LDAP_update_all_users'
fi
```
The script ensures that artisan is run as ``LYCHEE_USER``.

If the content of the LDAP server has changed the LDAP database file will be changed, too. By monitoring the database file a call of ``LDAP_update_all_users`` can be initiated. On Debian based systems you can run the following command on the LDAP server to find the the directory where the LDAP database is stored:

```
sudo ldapsearch -Q -LLL -Y EXTERNAL -H ldapi:/// -b cn=config | grep 'olcDbDirectory:'
```

Usually this directory is ``/var/lib/ldap``. The database file (usually ``data.mdb``) in this directory can be monitored using the following  script in ``/usr/local/sbin/ldap-auto-lychee``:

```
#!/bin/bash
#
while true; do
  /usr/bin/inotifywait -e modify /var/lib/ldap/data.mdb
  /usr/local/sbin/update-lychee 
done
```

This script need to be started when the LDAP server is started and will then loop forever. If systemd is used on the LDAP server a service to start the script can be configured with the following configuration in ``/etc/systemd/system/lychee-update.service``:

```
[Unit]
Description="Automatic Lychee artisan execution after ldap database change"
Requires=slapd.service
After=slapd.service

[Service]
User=root
ExecStart=/usr/local/sbin/ldap-auto-lychee
ExecStop=/usr/bin/killall ldap-auto-lychee
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
```

The service need to be started and installed using the following commands:

```
sudo systemctl start lychee-update.service
sudo systemctl enable lychee-update.service
```

In addition the following crontab entry might be used to force an update of the lychee users table at least once a day:

```
0 0 * * * /usr/sbin/service lychee-update stop && /usr/local/sbin/update-lychee && /usr/sbin/service lychee-update start
```

The ``lychee-update`` service needs to run either as root or as ``LYCHEE_USER``.

### Monitoring the LDAP Database on a remote server

If the LDAP server is not running on the same server as lychee then the LDAP database file cannot be monitored directly. But it is still possible to monitor the database file on the server where the LDAP database is located. The monitoring service and the scripts to monitor the LDAP server need to be installed on the remote LDAP server. The script ``/usr/local/sbin/update-lychee`` on the remote LDAP server needs to be modified because the remote server cannot call lychee artisan directly. Different communication methods between the systems could be used to allow the remote server to start the artisan command on the lychee server. The following script uses ssh to allow remote access of the LDAP server to the lychee server and replaces ``/usr/local/sbin/update-lychee`` on the LDAP server:

```
#!/bin/bash
#
/usr/bin/ssh lychee /usr/local/sbin/update-lychee
```

This requires that a ssh configuration on the LDAP server for lychee exists, allowing a public key login on the lychee server for the user running the monitoring service. In addition the script ``/usr/local/sbin/update-lychee`` from above needs to be installed on the lychee server.

### Therory of Operation

The LDAP for lychee interface provides the following public functions.

| Function                                                    | Description                                        | Return Value |
|-------------------------------------------------------------|----------------------------------------------------|--------------|
| check_pass(string $user, string $pass): bool                | Checks if the user has an account.                 | True if the the credentials are valid |
| get_user_data(string $username): FixedArray or null         | Reads some addition data from the LDAP server.     | The data of the user |
| get_user_list(bool $refresh = false): array of FixedArray   | Reads the data for a list of users from the LDAP server. | A list of user data |

If the user types in his credentials lychee calls `check_pass()`. The function connects with the LDAP server and 
checks if the user has an account. If an
account could be found and the password could be verified, `get_user_data()` gets called to read the common name 
and the email address will be requested from the 
LDAP server and stored in the lychee users table. An entry will be created in this table if no entry with this 
username exists yet. The user can then use lychee.

After logout the user data is kept in the users table of the lychee database as long as the user has 
an account with the LDAP server.

If the account gets deleted at the LDAP server, the automatic update and purging mechanism deletes the user data in the 
users table. If the user is not known to lychee any more he cannot login and use the system.

The `get_user_list()` function is used to synchronize the users table in the lychee database with the LDAP server.

The administrator account with an user id equal to zero will always be stored in the lychee database. Even if the 
same username is present at the LDAP server, which is very unlikely, this user cannot login at the lychee sysmen using 
his LDAP account. 

### Troubleshooting

In case of problems with the communication between lychee and the LDAP server the deubg logging should be activated in the `.env`-file:

| .env Entry        | Description                   | Default Value          | Recomended Value for Debugging       |
|-------------------|-------------------------------|------------------------|--------------------------------------|
| APP_LOG_LEVEL     | Application minimum log level | error                  | debug                                |

Then the protocol of the communication between lynchee and the LDAP server can be found in the administrator menue 
(Show Logs).
