<?php
/**
 * LWB IP — emergency unban.
 *
 * WHMCS sends banned visitors to banned.php before any page or hook runs, so
 * an admin banned by mistake cannot reach the admin area to lift the ban.
 * This page does not load WHMCS: it reads the database directly, checks the
 * secret key (stored hashed by the module) and lifts the bans that apply to
 * the visitor's own IP, and nothing else.
 *
 * @author    LEWEBMAX Admin
 * @license   MIT
 */

define('LWB_IP_STANDALONE', true);
require __DIR__ . '/lib/Ip.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

function lwb_ip_page($title, $text, $ok)
{
    $color = $ok ? '#067a06' : '#b42318';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . htmlspecialchars($title) . '</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6fb;font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#0d0d2b}'
        . '.c{max-width:440px;margin:16px;padding:32px;background:#fff;border:1px solid #e6e9f2;border-radius:20px;text-align:center;box-shadow:0 20px 50px rgba(13,13,43,.08)}'
        . '.i{width:56px;height:56px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;font-size:26px;color:#fff;background:' . $color . '}'
        . 'h1{font-size:21px;margin:0 0 8px}p{color:#575b78;margin:0 0 6px;line-height:1.5}a{display:inline-block;margin-top:16px;padding:11px 18px;border-radius:10px;background:#030673;color:#fff;text-decoration:none;font-weight:700}</style></head>'
        . '<body><div class="c"><div class="i">' . ($ok ? '&#10003;' : '!') . '</div><h1>' . htmlspecialchars($title) . '</h1>' . $text . '</div></body></html>';
    exit;
}

$key = (string) ($_GET['key'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $key)) {
    http_response_code(404);
    lwb_ip_page('Lien invalide', '<p>Ce lien de déblocage n\'est pas valide.</p>', false);
}

$root = dirname(__DIR__, 3);
if (!is_file($root . '/configuration.php')) {
    http_response_code(500);
    lwb_ip_page('Configuration introuvable', '<p>Le module n\'est pas installé au bon endroit.</p>', false);
}
$db_host = $db_port = $db_username = $db_password = $db_name = '';
include $root . '/configuration.php';

try {
    $dsn = 'mysql:host=' . $db_host . ($db_port ? ';port=' . $db_port : '') . ';dbname=' . $db_name . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db_username, $db_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (\Throwable $e) {
    http_response_code(500);
    lwb_ip_page('Base de données indisponible', '<p>Réessayez dans un instant.</p>', false);
}

$st = $pdo->prepare("SELECT value FROM tbladdonmodules WHERE module = 'lwb_ip' AND setting = 'unban_key_hash'");
$st->execute();
$hash = (string) $st->fetchColumn();
if ($hash === '' || !hash_equals($hash, hash('sha256', $key))) {
    sleep(2);
    http_response_code(404);
    lwb_ip_page('Lien invalide', '<p>Ce lien de déblocage n\'est pas valide ou a été remplacé par un nouveau.</p>', false);
}

$ip = \LwbIp\Ip::fromServer($_SERVER);
$now = date('Y-m-d H:i:s');
$bans = $pdo->query('SELECT id, ip FROM tblbannedips WHERE expires > ' . $pdo->quote($now))->fetchAll(PDO::FETCH_ASSOC);
$removed = [];
foreach ($bans as $ban) {
    if (\LwbIp\Ip::matches($ban['ip'], $ip)) {
        $pdo->prepare('DELETE FROM tblbannedips WHERE id = ?')->execute([(int) $ban['id']]);
        $removed[] = $ban['ip'];
    }
}

$detail = $removed ? 'Débannie via le lien d\'urgence (' . implode(', ', $removed) . ')' : 'Lien d\'urgence utilisé : aucune interdiction active';
try {
    $pdo->prepare('INSERT INTO mod_lwb_ip_events (action, ip, detail, actor, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute(['emergency', $ip, mb_substr($detail, 0, 255), 'Lien d\'urgence', $now]);
    $pdo->prepare("INSERT INTO tblactivitylog (date, description, user, userid, ipaddr) VALUES (?, ?, 'System', 0, ?)")
        ->execute([$now, 'LWB IP : ' . $detail . ' - IP ' . $ip, $ip]);
} catch (\Throwable $e) {
}

$admin = '';
$st = $pdo->prepare("SELECT value FROM tblconfiguration WHERE setting = 'SystemURL'");
$st->execute();
$base = rtrim((string) $st->fetchColumn(), '/');
if ($base !== '') {
    $folder = !empty($customadminpath) ? $customadminpath : 'admin';
    $admin = '<a href="' . htmlspecialchars($base . '/' . trim($folder, '/') . '/') . '">Ouvrir l\'administration</a>';
}

lwb_ip_page(
    $removed ? 'Votre IP est débannie' : 'Votre IP n\'est pas bannie',
    '<p>Adresse : <b>' . htmlspecialchars($ip) . '</b></p>'
    . ($removed ? '<p>Vous pouvez de nouveau vous connecter. Pensez à ajouter cette adresse à la liste blanche de LWB IP.</p>' : '<p>Aucune interdiction ne s\'applique à cette adresse.</p>')
    . $admin,
    true
);
