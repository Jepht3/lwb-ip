<?php

namespace LwbIp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Tables and queries of LWB IP.
 *
 * WHMCS keeps its bans in tblbannedips (ip, reason, expires); this module reads
 * and writes that table directly, so bans made here are enforced by WHMCS itself.
 */
class Store
{
    const MODULE = 'lwb_ip';

    const T_LOGINS = 'mod_lwb_ip_logins';
    const T_BLOCKS = 'mod_lwb_ip_blocks';
    const T_WHITELIST = 'mod_lwb_ip_whitelist';
    const T_GEO = 'mod_lwb_ip_geo';
    const T_EVENTS = 'mod_lwb_ip_events';

    /** Expiry used by WHMCS for permanent bans. */
    const PERMANENT = '2099-12-31 23:59:59';

    /** Durations offered in the admin, in minutes (0 = permanent). */
    const DURATIONS = [
        15 => '15 minutes', 60 => '1 heure', 1440 => '24 heures', 10080 => '7 jours', 43200 => '30 jours', 0 => 'Permanent',
    ];

    public static function setting($key, $default = null)
    {
        $value = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $key)->value('value');
        return ($value === null || $value === '') ? $default : $value;
    }

    public static function setSetting($key, $value)
    {
        Capsule::table('tbladdonmodules')->updateOrInsert(['module' => self::MODULE, 'setting' => $key], ['value' => (string) $value]);
    }

    public static function flag($key, $default = true)
    {
        $value = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $key)->value('value');
        return $value === null ? $default : ($value === 'on' || $value === 'yes' || $value === '1');
    }

    public static function ready()
    {
        return Capsule::schema()->hasTable(self::T_LOGINS) && Capsule::schema()->hasTable(self::T_EVENTS);
    }

    public static function install()
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::T_LOGINS)) {
            $schema->create(self::T_LOGINS, function ($t) {
                $t->increments('id');
                $t->integer('user_id')->unsigned()->index();
                $t->string('ip', 45)->index();
                $t->string('user_agent', 255)->default('');
                $t->dateTime('created_at')->index();
            });
        }
        if (!$schema->hasTable(self::T_BLOCKS)) {
            $schema->create(self::T_BLOCKS, function ($t) {
                $t->increments('id');
                $t->integer('user_id')->unsigned();
                $t->string('ip', 45);
                $t->dateTime('created_at');
                $t->unique(['user_id', 'ip']);
            });
        }
        if (!$schema->hasTable(self::T_WHITELIST)) {
            $schema->create(self::T_WHITELIST, function ($t) {
                $t->increments('id');
                $t->string('ip', 64)->unique();
                $t->string('note', 120)->default('');
                $t->dateTime('created_at');
            });
        }
        if (!$schema->hasTable(self::T_GEO)) {
            $schema->create(self::T_GEO, function ($t) {
                $t->string('ip', 45)->primary();
                $t->string('country', 80)->default('');
                $t->string('country_code', 2)->default('');
                $t->string('city', 80)->default('');
                $t->string('isp', 120)->default('');
                $t->dateTime('fetched_at');
            });
        }
        if (!$schema->hasTable(self::T_EVENTS)) {
            $schema->create(self::T_EVENTS, function ($t) {
                $t->increments('id');
                $t->string('action', 20)->index();
                $t->string('ip', 64)->default('');
                $t->string('detail', 255)->default('');
                $t->string('actor', 80)->default('');
                $t->dateTime('created_at')->index();
            });
        }
    }

    public static function uninstall()
    {
        foreach ([self::T_LOGINS, self::T_BLOCKS, self::T_WHITELIST, self::T_GEO, self::T_EVENTS] as $table) {
            Capsule::schema()->dropIfExists($table);
        }
    }

    /* ----------------------------- Events ----------------------------- */

    public static function event($action, $ip, $detail, $actor = '')
    {
        Capsule::table(self::T_EVENTS)->insert([
            'action' => $action,
            'ip' => mb_substr((string) $ip, 0, 64),
            'detail' => mb_substr((string) $detail, 0, 255),
            'actor' => mb_substr((string) $actor, 0, 80),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function events($limit = 30)
    {
        return Capsule::table(self::T_EVENTS)->orderBy('id', 'desc')->limit($limit)->get()->all();
    }

    /* ------------------------------ Bans ------------------------------ */

    /** Active bans (WHMCS ignores expired rows; they are listed separately). */
    public static function bans($active = true)
    {
        $q = Capsule::table('tblbannedips');
        $active ? $q->where('expires', '>', date('Y-m-d H:i:s')) : $q->where('expires', '<=', date('Y-m-d H:i:s'));
        return $q->orderBy('id', 'desc')->limit(500)->get()->all();
    }

    /** Active ban that applies to $ip, or null. */
    public static function banFor($ip)
    {
        foreach (self::bans() as $ban) {
            if (Ip::matches($ban->ip, $ip)) {
                return $ban;
            }
        }
        return null;
    }

    /**
     * @param int $minutes 0 = permanent
     */
    public static function ban($ip, $minutes, $reason, $actor)
    {
        $ip = Ip::normalise($ip);
        if ($ip === null) {
            throw new \InvalidArgumentException('Adresse IP invalide. Exemples acceptés : 41.243.12.7, 41.243.*, 2c0f:f5c0::1.');
        }
        if (self::whitelisted($ip)) {
            throw new \InvalidArgumentException($ip . ' est dans la liste blanche : retirez-la d\'abord.');
        }
        $expires = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : self::PERMANENT;
        $reason = mb_substr(trim((string) $reason), 0, 200) ?: 'Banni manuellement';
        $existing = Capsule::table('tblbannedips')->where('ip', $ip)->first();
        if ($existing) {
            Capsule::table('tblbannedips')->where('id', $existing->id)->update(['reason' => $reason, 'expires' => $expires]);
        } else {
            Capsule::table('tblbannedips')->insert(['ip' => $ip, 'reason' => $reason, 'expires' => $expires]);
        }
        self::event('ban', $ip, $reason . ' · ' . ($minutes > 0 ? self::DURATIONS[$minutes] ?? ($minutes . ' min') : 'permanent'), $actor);
        logActivity('LWB IP : ' . $ip . ' bannie (' . $reason . ')');
        return $ip;
    }

    public static function unban($id, $actor)
    {
        $ban = Capsule::table('tblbannedips')->where('id', (int) $id)->first();
        if (!$ban) {
            return null;
        }
        Capsule::table('tblbannedips')->where('id', (int) $id)->delete();
        self::event('unban', $ban->ip, 'Débannie · motif : ' . ($ban->reason ?: 'aucun'), $actor);
        logActivity('LWB IP : ' . $ban->ip . ' débannie');
        return $ban->ip;
    }

    /** Removes every ban that applies to $ip (exact or range). */
    public static function unbanIp($ip, $actor)
    {
        $n = 0;
        foreach (self::bans() as $ban) {
            if (Ip::matches($ban->ip, $ip)) {
                self::unban($ban->id, $actor);
                $n++;
            }
        }
        return $n;
    }

    /**
     * @param int $minutes 0 = permanent
     */
    public static function extend($id, $minutes, $actor)
    {
        $ban = Capsule::table('tblbannedips')->where('id', (int) $id)->first();
        if (!$ban) {
            return;
        }
        $base = max(time(), (int) strtotime($ban->expires));
        $expires = $minutes > 0 ? date('Y-m-d H:i:s', $base + $minutes * 60) : self::PERMANENT;
        Capsule::table('tblbannedips')->where('id', $ban->id)->update(['expires' => $expires]);
        self::event('extend', $ban->ip, $minutes > 0 ? 'Prolongée de ' . (self::DURATIONS[$minutes] ?? $minutes . ' min') : 'Rendue permanente', $actor);
    }

    /* --------------------------- Whitelist ---------------------------- */

    public static function whitelist()
    {
        return Capsule::table(self::T_WHITELIST)->orderBy('id', 'desc')->get()->all();
    }

    public static function whitelisted($ip)
    {
        foreach (self::whitelist() as $w) {
            if (Ip::matches($w->ip, $ip)) {
                return true;
            }
        }
        return false;
    }

    public static function addWhitelist($ip, $note, $actor)
    {
        $ip = Ip::normalise($ip);
        if ($ip === null) {
            throw new \InvalidArgumentException('Adresse IP invalide.');
        }
        Capsule::table(self::T_WHITELIST)->updateOrInsert(['ip' => $ip], ['note' => mb_substr(trim((string) $note), 0, 120), 'created_at' => date('Y-m-d H:i:s')]);
        self::event('whitelist', $ip, 'Ajoutée à la liste blanche' . ($note !== '' ? ' · ' . $note : ''), $actor);
        self::applyWhitelist($actor);
        return $ip;
    }

    public static function removeWhitelist($id, $actor)
    {
        $row = Capsule::table(self::T_WHITELIST)->where('id', (int) $id)->first();
        if ($row) {
            Capsule::table(self::T_WHITELIST)->where('id', $row->id)->delete();
            self::event('unwhitelist', $row->ip, 'Retirée de la liste blanche', $actor);
        }
    }

    /** Lifts every ban that hits a whitelisted IP (run by the hooks and the cron). */
    public static function applyWhitelist($actor = 'Liste blanche')
    {
        $list = self::whitelist();
        if (!$list) {
            return 0;
        }
        $n = 0;
        foreach (self::bans() as $ban) {
            foreach ($list as $w) {
                if (Ip::matches($w->ip, $ban->ip) || Ip::matches($ban->ip, $w->ip)) {
                    self::unban($ban->id, $actor);
                    $n++;
                    break;
                }
            }
        }
        return $n;
    }

    /* ------------------------- Failed logins -------------------------- */

    /**
     * Failed login attempts from the WHMCS activity log, grouped by IP.
     *
     * @return array<string, array{ip: string, count: int, last: string, targets: string[], admin: bool}>
     */
    public static function failures($hours = 24)
    {
        $rows = Capsule::table('tblactivitylog')
            ->where('date', '>=', date('Y-m-d H:i:s', time() - $hours * 3600))
            ->where(function ($q) {
                $q->where('description', 'like', 'Failed%Login%')->orWhere('description', 'like', '%Invalid Login%');
            })
            ->orderBy('id', 'desc')->limit(2000)->get(['date', 'description', 'ipaddr']);
        $out = [];
        foreach ($rows as $r) {
            $ip = trim((string) $r->ipaddr);
            if ($ip === '' && preg_match('/IP:\s*([0-9a-f:.]+)/i', $r->description, $m)) {
                $ip = $m[1];
            }
            $ip = $ip !== '' ? $ip : 'inconnue';
            if (!isset($out[$ip])) {
                $out[$ip] = ['ip' => $ip, 'count' => 0, 'last' => $r->date, 'targets' => [], 'admin' => false];
            }
            $out[$ip]['count']++;
            if (stripos($r->description, 'Admin') !== false) {
                $out[$ip]['admin'] = true;
            }
            if (preg_match('/(Username|Email|User ID):\s*([^\s-][^-]*)/i', $r->description, $m)) {
                $out[$ip]['targets'][trim($m[2])] = true;
            }
        }
        foreach ($out as &$o) {
            $o['targets'] = array_slice(array_keys($o['targets']), 0, 3);
        }
        unset($o);
        uasort($out, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        return $out;
    }

    /** Failed attempts per hour over the last 24 h (for the chart). */
    public static function failuresPerHour()
    {
        $rows = Capsule::table('tblactivitylog')
            ->where('date', '>=', date('Y-m-d H:00:00', strtotime('-23 hours')))
            ->where(function ($q) {
                $q->where('description', 'like', 'Failed%Login%')->orWhere('description', 'like', '%Invalid Login%');
            })
            ->selectRaw("DATE_FORMAT(date, '%Y-%m-%d %H') h, COUNT(*) n")->groupBy('h')->pluck('n', 'h')->all();
        $out = [];
        for ($i = 23; $i >= 0; $i--) {
            $h = date('Y-m-d H', strtotime("-$i hours"));
            $out[] = ['hour' => substr($h, 11) . ' h', 'count' => (int) ($rows[$h] ?? 0)];
        }
        return $out;
    }

    /* -------------------------- Client logins ------------------------- */

    public static function recordLogin($userId, $ip, $userAgent)
    {
        Capsule::table(self::T_LOGINS)->insert([
            'user_id' => (int) $userId,
            'ip' => mb_substr((string) $ip, 0, 45),
            'user_agent' => mb_substr((string) $userAgent, 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function logins($userId, $limit = 30)
    {
        return Capsule::table(self::T_LOGINS)->where('user_id', (int) $userId)->orderBy('id', 'desc')->limit($limit)->get()->all();
    }

    /** Latest logins of every client, with the user's name. */
    public static function allLogins($limit = 100)
    {
        return Capsule::table(self::T_LOGINS . ' as l')
            ->leftJoin('tblusers as u', 'u.id', '=', 'l.user_id')
            ->orderBy('l.id', 'desc')->limit($limit)
            ->get(['l.*', 'u.first_name', 'u.last_name', 'u.email'])->all();
    }

    /** IPs used by several accounts over 30 days (shared operator IPs or suspicious). */
    public static function sharedIps($minUsers = 3)
    {
        return Capsule::table(self::T_LOGINS)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->selectRaw('ip, COUNT(DISTINCT user_id) users, COUNT(*) logins, MAX(created_at) last')
            ->groupBy('ip')->havingRaw('COUNT(DISTINCT user_id) >= ?', [$minUsers])
            ->orderByDesc('users')->limit(20)->get()->all();
    }

    public static function loginsSince($hours)
    {
        return Capsule::table(self::T_LOGINS)->where('created_at', '>=', date('Y-m-d H:i:s', time() - $hours * 3600))->count();
    }

    public static function prune($days)
    {
        $days = max(7, (int) $days);
        Capsule::table(self::T_LOGINS)->where('created_at', '<', date('Y-m-d H:i:s', strtotime("-$days days")))->delete();
        Capsule::table(self::T_EVENTS)->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-365 days')))->delete();
        Capsule::table(self::T_GEO)->where('fetched_at', '<', date('Y-m-d H:i:s', strtotime('-60 days')))->delete();
    }

    /* ----------------------- Per-account blocks ----------------------- */

    public static function blocks($userId)
    {
        return Capsule::table(self::T_BLOCKS)->where('user_id', (int) $userId)->orderBy('id', 'desc')->get()->all();
    }

    public static function isBlocked($userId, $ip)
    {
        return Capsule::table(self::T_BLOCKS)->where('user_id', (int) $userId)->where('ip', (string) $ip)->exists();
    }

    public static function block($userId, $ip, $actor)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Adresse IP invalide.');
        }
        Capsule::table(self::T_BLOCKS)->updateOrInsert(['user_id' => (int) $userId, 'ip' => $ip], ['created_at' => date('Y-m-d H:i:s')]);
        self::event('block', $ip, 'Bloquée par le client sur son compte (utilisateur n° ' . (int) $userId . ')', $actor);
    }

    public static function unblock($userId, $id, $actor)
    {
        $row = Capsule::table(self::T_BLOCKS)->where('user_id', (int) $userId)->where('id', (int) $id)->first();
        if ($row) {
            Capsule::table(self::T_BLOCKS)->where('id', $row->id)->delete();
            self::event('unblock', $row->ip, 'Débloquée par le client (utilisateur n° ' . (int) $userId . ')', $actor);
        }
    }

    public static function blocksCount()
    {
        return Capsule::table(self::T_BLOCKS)->count();
    }
}
