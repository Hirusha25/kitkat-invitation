<?php
/*
 * KitKat invite API: guest activity + RSVPs in, dashboard data out.
 *
 * The invitation posts {type, guest, device, visit, link, ...} for each moment
 * (open, envelope, card, calendar, directions, rsvp) and the host page posts
 * {type: "invited", guests: [...]}. The dashboard signs in with
 * {type: "login", email, password}, then reads with {type: "dashboard", token}.
 *
 * Database credentials live OUTSIDE the web root in ~/config/kitkat.php
 * (see server/README.md). Nothing secret is kept in this repository.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok' => false, 'error' => 'method'], 405);

$configFile = dirname(__DIR__, 2) . '/config/kitkat.php';
if (!is_file($configFile)) out(['ok' => false, 'error' => 'setup']);
$cfg = require $configFile;

date_default_timezone_set($cfg['timezone'] ?? 'Asia/Colombo');

$raw = file_get_contents('php://input', false, null, 0, 65536);
$in = json_decode($raw ?: '', true);
if (!is_array($in)) out(['ok' => false, 'error' => 'bad-request'], 400);

try {
    $db = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec("SET time_zone = '" . date('P') . "'");
} catch (Throwable $e) {
    error_log('kitkat api db: ' . $e->getMessage());
    out(['ok' => false, 'error' => 'network'], 503);
}

/** Trim a client value to a safe length. */
function s($v, int $max = 200): string {
    if (!is_scalar($v)) return '';
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '');
    return mb_substr($v, 0, $max);
}
function ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45); }

const EVENTS = [
    'open'       => 'Opened link',
    'envelope'   => 'Opened envelope',
    'card'       => 'Viewed invitation',
    'calendar'   => 'Added to calendar',
    'directions' => 'Opened directions',
    'rsvp'       => 'RSVP',
];

/** Very light flood guard for the public endpoints: N writes per IP per minute. */
function flooding(PDO $db, int $limit): bool {
    $q = $db->prepare('SELECT COUNT(*) FROM activity WHERE ip = ? AND created_at > NOW() - INTERVAL 1 MINUTE');
    $q->execute([ip()]);
    return (int)$q->fetchColumn() >= $limit;
}

function addRow(PDO $db, array $r): void {
    $q = $db->prepare('INSERT INTO activity (created_at, event, guest, visit, device, link, response, name, mobile, email, message, invited_as, page, ip)
                       VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $q->execute([
        $r['event'], $r['guest'] ?? '', $r['visit'] ?? '', $r['device'] ?? '', $r['link'] ?? '',
        $r['response'] ?? '', $r['name'] ?? '', $r['mobile'] ?? '', $r['email'] ?? '', $r['message'] ?? '',
        $r['invited_as'] ?? '', $r['page'] ?? '', ip(),
    ]);
}

/** Returns the signed-in user for a token, or null. */
function userFor(PDO $db, string $token): ?array {
    if (strlen($token) < 32) return null;
    $q = $db->prepare('SELECT u.id, u.email FROM sessions s JOIN users u ON u.id = s.user_id
                       WHERE s.token_hash = ? AND s.expires_at > NOW()');
    $q->execute([hash('sha256', $token)]);
    return $q->fetch() ?: null;
}

$type = s($in['type'] ?? '', 20);

// ---- guest activity (public) ----
if (isset(EVENTS[$type])) {
    if (flooding($db, 60)) out(['ok' => false, 'error' => 'slow-down'], 429);
    $row = [
        'event'  => EVENTS[$type],
        'guest'  => s($in['guest'] ?? ''),
        'visit'  => s($in['visit'] ?? '', 20),
        'device' => s($in['device'] ?? '', 20),
        'link'   => s($in['link'] ?? '', 20),
    ];
    if ($type === 'rsvp') {
        $resp = s($in['response'] ?? '', 20);
        if (!in_array($resp, ['Attending', 'Not attending'], true)) out(['ok' => false, 'error' => 'bad-request'], 400);
        $name = s($in['name'] ?? '');
        if ($name === '') out(['ok' => false, 'error' => 'bad-request'], 400);
        $row += [
            'response'   => $resp,
            'name'       => $name,
            'mobile'     => s($in['mobile'] ?? '', 30),
            'email'      => s($in['email'] ?? '', 200),
            'message'    => s($in['message'] ?? '', 2000),
            'invited_as' => s($in['invitedAs'] ?? ''),
            'page'       => s($in['page'] ?? '', 300),
        ];
        if ($row['guest'] === '') $row['guest'] = $name;
    }
    addRow($db, $row);
    out(['ok' => true]);
}

// ---- guest list from the host page (public, like the link builder itself) ----
if ($type === 'invited') {
    if (flooding($db, 60)) out(['ok' => false, 'error' => 'slow-down'], 429);
    $names = array_slice(array_values(array_filter(array_map(fn($n) => s($n), (array)($in['guests'] ?? [])))), 0, 500);
    $have = $db->prepare("SELECT 1 FROM activity WHERE event = 'Invited' AND guest = ? LIMIT 1");
    foreach ($names as $n) {
        $have->execute([$n]);
        if (!$have->fetchColumn()) addRow($db, ['event' => 'Invited', 'guest' => $n, 'link' => 'Personal link']);
    }
    out(['ok' => true, 'added' => count($names)]);
}

// ---- dashboard sign-in ----
if ($type === 'login') {
    $email = strtolower(s($in['email'] ?? '', 200));
    $pass  = is_string($in['password'] ?? null) ? substr($in['password'], 0, 200) : '';
    if ($email === '' || $pass === '') out(['ok' => false, 'error' => 'wrong']);

    $q = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE (ip = ? OR email = ?) AND at > NOW() - INTERVAL 15 MINUTE');
    $q->execute([ip(), $email]);
    if ((int)$q->fetchColumn() >= 8) out(['ok' => false, 'error' => 'locked']);

    $q = $db->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $q->execute([$email]);
    $u = $q->fetch();
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        if (!$u) password_hash($pass, PASSWORD_DEFAULT); // same work as a real check, so timing doesn't reveal which emails exist
        $db->prepare('INSERT INTO login_attempts (ip, email, at) VALUES (?, ?, NOW())')->execute([ip(), $email]);
        out(['ok' => false, 'error' => 'wrong']);
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    $db->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
    $db->prepare('DELETE FROM sessions WHERE expires_at < NOW()')->execute();
    $token = bin2hex(random_bytes(32));
    $days = !empty($in['remember']) ? 30 : 1;
    $db->prepare("INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?, ?, NOW() + INTERVAL $days DAY)")
       ->execute([hash('sha256', $token), $u['id']]);
    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]);
    out(['ok' => true, 'token' => $token]);
}

if ($type === 'logout') {
    $db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', s($in['token'] ?? '', 100))]);
    out(['ok' => true]);
}

// ---- dashboard data ----
if ($type === 'dashboard') {
    $user = userFor($db, s($in['token'] ?? '', 100));
    if (!$user) out(['ok' => false, 'error' => 'expired']);
    $cols = ['Time', 'Event', 'Guest', 'Visit', 'Device', 'Link', 'Response', 'Name', 'Mobile', 'Email', 'Message'];
    $rows = $db->query("SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s'), event, guest, visit, device, link, response, name, mobile, email, message
                        FROM activity ORDER BY id")->fetchAll(PDO::FETCH_NUM);
    out(['ok' => true, 'at' => date('Y-m-d H:i:s'), 'user' => $user['email'], 'columns' => $cols, 'rows' => $rows]);
}

out(['ok' => false, 'error' => 'bad-request'], 400);
