# Server side (not published to the website)

The site deploys from `main` to `kitkat-invite.martech.live`. This folder
is excluded from the web root; only `api/index.php` is served.

- `schema.sql`: tables for guest activity, dashboard users and sign-in sessions.
- `dashboard-user.php`: add, reset or remove dashboard sign-ins from the server's command line.

Database credentials live outside the web root in `~/config/kitkat.php` and
are never committed:

```php
<?php return [
  'dsn'      => 'mysql:host=localhost;dbname=kitkatinv_invite;charset=utf8mb4',
  'user'     => 'kitkatinv_app',
  'pass'     => '...',
  'timezone' => 'Asia/Colombo',
  'site'     => 'https://kitkat-invite.martech.live',
];
```

Dashboard users (run as the `kitkatinv` account):

```bash
/opt/cpanel/ea-php83/root/usr/bin/php ~/repo/server/dashboard-user.php add someone@example.com
/opt/cpanel/ea-php83/root/usr/bin/php ~/repo/server/dashboard-user.php list
```
