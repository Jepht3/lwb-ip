<?php

namespace LwbIp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Country, city and operator of an IP (ipwho.is, HTTPS, no key), cached 30 days.
 * Can be switched off in the module settings: nothing is then sent outside.
 */
class Geo
{
    /** Maximum number of new lookups per page, to keep pages fast. */
    const MAX_LOOKUPS = 12;

    private static $lookups = 0;

    /**
     * @return array{country: string, code: string, city: string, isp: string, flag: string}|null
     */
    public static function find($ip)
    {
        $ip = (string) $ip;
        if (!Ip::isPublic($ip) || !Store::flag('geo')) {
            return null;
        }
        $row = Capsule::table(Store::T_GEO)->where('ip', $ip)
            ->where('fetched_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))->first();
        if (!$row) {
            if (self::$lookups >= self::MAX_LOOKUPS) {
                return null;
            }
            self::$lookups++;
            $row = self::fetch($ip);
            if (!$row) {
                return null;
            }
        }
        return [
            'country' => (string) $row->country,
            'code' => (string) $row->country_code,
            'city' => (string) $row->city,
            'isp' => (string) $row->isp,
            'flag' => Ip::flag($row->country_code),
        ];
    }

    private static function fetch($ip)
    {
        $ch = curl_init('https://ipwho.is/' . rawurlencode($ip) . '?lang=fr&fields=success,country,country_code,city,connection');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_USERAGENT => 'LWB-IP/1.0']);
        $data = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (empty($data['success'])) {
            return null;
        }
        $row = [
            'ip' => $ip,
            'country' => mb_substr((string) ($data['country'] ?? ''), 0, 80),
            'country_code' => strtoupper(mb_substr((string) ($data['country_code'] ?? ''), 0, 2)),
            'city' => mb_substr((string) ($data['city'] ?? ''), 0, 80),
            'isp' => mb_substr((string) ($data['connection']['isp'] ?? ($data['connection']['org'] ?? '')), 0, 120),
            'fetched_at' => date('Y-m-d H:i:s'),
        ];
        Capsule::table(Store::T_GEO)->updateOrInsert(['ip' => $ip], $row);
        return (object) $row;
    }

    /** "🇨🇩 Kinshasa, RD Congo · Vodacom". */
    public static function label($ip, $withIsp = true)
    {
        $g = self::find($ip);
        if (!$g) {
            if (Ip::isRange($ip)) {
                return 'Plage d\'adresses';
            }
            return Ip::isPublic($ip) ? '' : 'Réseau local';
        }
        $place = trim($g['city'] . ($g['city'] && $g['country'] ? ', ' : '') . $g['country']);
        return trim($g['flag'] . ' ' . $place . ($withIsp && $g['isp'] ? ' · ' . $g['isp'] : ''));
    }
}
