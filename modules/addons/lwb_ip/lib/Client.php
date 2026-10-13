<?php

namespace LwbIp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Client side: login history of the logged-in user and per-account IP blocks.
 *
 * A client never bans an IP for the whole server: mobile operators share their
 * addresses between many customers. A block only closes this user's account to
 * that IP.
 */
class Client
{
    /** Id of the logged-in user (WHMCS 8 users), or 0. */
    public static function userId()
    {
        try {
            $user = (new \WHMCS\Authentication\CurrentUser())->user();
            return $user ? (int) $user->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array[] ready for the templates
     */
    public static function loginRows($userId, $limit)
    {
        $me = Ip::current();
        $blocked = [];
        foreach (Store::blocks($userId) as $b) {
            $blocked[$b->ip] = (int) $b->id;
        }
        $rows = [];
        $currentSeen = false;
        foreach (Store::logins($userId, $limit) as $l) {
            $d = Ip::device($l->user_agent);
            $current = !$currentSeen && $l->ip === $me;
            $currentSeen = $currentSeen || $current;
            $rows[] = [
                'ip' => $l->ip,
                'ip_short' => Ip::short($l->ip),
                'place' => Geo::label($l->ip, false),
                'isp' => (Geo::find($l->ip)['isp'] ?? ''),
                'device' => $d['label'],
                'mobile' => $d['mobile'],
                'ago' => Ip::ago($l->created_at),
                'date' => date('d/m/Y H:i', strtotime($l->created_at)),
                'current' => $current,
                'same_ip' => $l->ip === $me,
                'blocked' => isset($blocked[$l->ip]),
            ];
        }
        return $rows;
    }

    /** Compact data for the dashboard card ($lwbIp). */
    public static function card($userId)
    {
        $rows = self::loginRows($userId, 10);
        if (!$rows) {
            return null;
        }
        // New place: the last login comes from a country not seen in the previous ones.
        $alert = false;
        $codes = [];
        foreach (array_slice(Store::logins($userId, 10), 1) as $l) {
            $g = Geo::find($l->ip);
            if ($g && $g['code']) {
                $codes[$g['code']] = true;
            }
        }
        $lastGeo = Geo::find($rows[0]['ip']);
        if ($codes && $lastGeo && $lastGeo['code'] && !isset($codes[$lastGeo['code']])) {
            $alert = true;
        }
        return [
            'logins' => array_slice($rows, 0, 3),
            'alert' => $alert,
            'blocked' => count(Store::blocks($userId)),
            'url' => 'index.php?m=lwb_ip',
        ];
    }

    /** index.php?m=lwb_ip */
    public function page()
    {
        $userId = self::userId();
        $flash = null;
        if ($userId && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_token('WHMCS.default');
            $flash = $this->handle($userId);
        }
        $me = Ip::current();
        $blocks = [];
        if ($userId) {
            foreach (Store::blocks($userId) as $b) {
                $blocks[] = ['id' => (int) $b->id, 'ip' => $b->ip, 'ip_short' => Ip::short($b->ip), 'place' => Geo::label($b->ip, false), 'since' => Ip::ago($b->created_at)];
            }
        }
        return [
            'pagetitle' => 'Connexions et sécurité',
            'breadcrumb' => ['index.php?m=lwb_ip' => 'Connexions et sécurité'],
            'templatefile' => 'security',
            'requirelogin' => true,
            'forcessl' => false,
            'vars' => [
                'lwbip' => [
                    'logins' => $userId ? self::loginRows($userId, 30) : [],
                    'blocks' => $blocks,
                    'me' => $me,
                    'me_short' => Ip::short($me),
                    'me_place' => Geo::label($me),
                    'flash' => $flash,
                    'retention' => (int) Store::setting('retention', 90),
                    'v' => (int) @filemtime(dirname(__DIR__) . '/assets/client.css'),
                ],
            ],
        ];
    }

    private function handle($userId)
    {
        $me = Ip::current();
        try {
            if (($_POST['lwbip'] ?? '') === 'block') {
                $ip = trim((string) ($_POST['ip'] ?? ''));
                if ($ip === $me) {
                    return ['danger', 'Vous ne pouvez pas bloquer l\'adresse que vous utilisez en ce moment.'];
                }
                // Only an address that really appears in this account's history can be blocked.
                $known = \WHMCS\Database\Capsule::table(Store::T_LOGINS)->where('user_id', $userId)->where('ip', $ip)->exists();
                if (!$known) {
                    return ['danger', 'Cette adresse n\'apparaît pas dans l\'historique de votre compte.'];
                }
                Store::block($userId, $ip, 'Client (utilisateur n° ' . $userId . ')');
                return ['success', 'L\'adresse ' . Ip::short($ip) . ' ne peut plus accéder à votre compte. Si ce n\'était pas vous, changez aussi votre mot de passe.'];
            }
            if (($_POST['lwbip'] ?? '') === 'unblock') {
                Store::unblock($userId, (int) ($_POST['id'] ?? 0), 'Client (utilisateur n° ' . $userId . ')');
                return ['success', 'Adresse débloquée.'];
            }
        } catch (\Throwable $e) {
            return ['danger', $e->getMessage()];
        }
        return null;
    }
}
