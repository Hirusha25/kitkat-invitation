<?php
/*
 * Manage dashboard sign-ins from the server's command line (never over the web).
 *
 *   php dashboard-user.php add <email>      create a user, prints a new password once
 *   php dashboard-user.php reset <email>    set a new password, prints it once, signs out everywhere
 *   php dashboard-user.php remove <email>   delete the user and their sessions
 *   php dashboard-user.php list             list users
 *
 * Uses ~/config/kitkat.php for the database. Run with the account's PHP:
 *   /opt/cpanel/ea-php83/root/usr/bin/php ~/repo/server/dashboard-user.php list
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$cfg = require (getenv('HOME') ?: '/home/kitkatinv') . '/config/kitkat.php';
date_default_timezone_set($cfg['timezone'] ?? 'Asia/Colombo');
$db = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '" . date('P') . "'");

[$cmd, $email] = [$argv[1] ?? '', strtolower(trim($argv[2] ?? ''))];
$fail = function (string $m): void { fwrite(STDERR, $m . "\n"); exit(1); };

function newPassword(): string {
    // 4 groups of 4 from an alphabet without look-alikes (no 0/O, 1/l/I)
    $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $g = [];
    for ($i = 0; $i < 4; $i++) { $s = ''; for ($j = 0; $j < 4; $j++) $s .= $abc[random_int(0, strlen($abc) - 1)]; $g[] = $s; }
    return implode('-', $g);
}

switch ($cmd) {
    case 'add':
    case 'reset':
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('Give a valid email address.');
        $q = $db->prepare('SELECT id FROM users WHERE email = ?'); $q->execute([$email]); $id = $q->fetchColumn();
        if ($cmd === 'add' && $id) $fail("$email already exists. Use reset to give them a new password.");
        if ($cmd === 'reset' && !$id) $fail("$email doesn't exist. Use add.");
        $pw = newPassword(); $hash = password_hash($pw, PASSWORD_DEFAULT);
        if ($id) {
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
            $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, NOW())')->execute([$email, $hash]);
        }
        $url = rtrim($cfg['site'] ?? '', '/') . '/dashboard/';
        echo "KitKat Invite dashboard\nSign in: $url\nEmail:    $email\nPassword: $pw\n";
        break;
    case 'remove':
        $n = $db->prepare('DELETE FROM users WHERE email = ?'); $n->execute([$email]);
        echo $n->rowCount() ? "Removed $email\n" : "No user $email\n";
        break;
    case 'list':
        foreach ($db->query('SELECT email, created_at, last_login FROM users ORDER BY id') as $u)
            printf("%-40s created %s  last sign-in %s\n", $u['email'], $u['created_at'], $u['last_login'] ?? 'never');
        break;
    default:
        $fail("Usage: php dashboard-user.php add|reset|remove <email> | list");
}
