<?php

namespace LwbIp;

if (!defined('WHMCS') && !defined('LWB_IP_STANDALONE')) {
    die('This file cannot be accessed directly');
}

/**
 * IP helpers: current visitor IP, validation, wildcard matching, devices.
 */
class Ip
{
    /** IP of the current visitor, as WHMCS sees it (proxy headers included). */
    public static function current()
    {
        if (class_exists('\WHMCS\Utility\Environment\CurrentRequest')) {
            try {
                $ip = (string) \WHMCS\Utility\Environment\CurrentRequest::getIP();
                if ($ip !== '') {
                    return $ip;
                }
            } catch (\Throwable $e) {
            }
        }
        return self::fromServer($_SERVER);
    }

    /** Visitor IP without WHMCS (emergency unban page behind Cloudflare). */
    public static function fromServer(array $server)
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $key) {
            if (!empty($server[$key]) && filter_var(trim($server[$key]), FILTER_VALIDATE_IP)) {
                return trim($server[$key]);
            }
        }
        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $server['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string) ($server['REMOTE_ADDR'] ?? '');
    }

    /**
     * Normalises an IP or a WHMCS wildcard range ("41.243.*", "41.243.12.*").
     * Returns null when the value is not acceptable.
     */
    public static function normalise($value)
    {
        $value = strtolower(trim((string) $value));
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
        // IPv4 wildcard: 1 to 3 fixed octets followed by "*".
        if (preg_match('/^(\d{1,3})(\.\d{1,3}){0,2}\.\*$/', $value)) {
            foreach (explode('.', rtrim($value, '.*')) as $octet) {
                if ((int) $octet > 255) {
                    return null;
                }
            }
            return $value;
        }
        return null;
    }

    public static function isRange($value)
    {
        return substr((string) $value, -1) === '*';
    }

    /** Does $ip fall into $pattern (exact IP or "a.b.*" wildcard)? */
    public static function matches($pattern, $ip)
    {
        $pattern = strtolower(trim((string) $pattern));
        $ip = strtolower(trim((string) $ip));
        if ($pattern === $ip) {
            return true;
        }
        if (self::isRange($pattern)) {
            return strpos($ip, rtrim($pattern, '*')) === 0;
        }
        return false;
    }

    public static function isPublic($ip)
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** Short display form: long IPv6 addresses keep their first and last groups. */
    public static function short($ip)
    {
        $ip = (string) $ip;
        if (strlen($ip) <= 24 || strpos($ip, ':') === false) {
            return $ip;
        }
        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 3)) . ':…:' . end($parts);
    }

    /** Country flag emoji from an ISO code ("CD" → 🇨🇩). */
    public static function flag($code)
    {
        $code = strtoupper((string) $code);
        if (!preg_match('/^[A-Z]{2}$/', $code)) {
            return '';
        }
        return mb_chr(0x1F1E6 + ord($code[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($code[1]) - 65, 'UTF-8');
    }

    /**
     * Readable device from a user agent.
     *
     * @return array{browser: string, os: string, mobile: bool, label: string}
     */
    public static function device($ua)
    {
        $ua = (string) $ua;
        $browser = 'Navigateur';
        foreach ([
            'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox',
            'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'FxiOS' => 'Firefox', 'Safari/' => 'Safari',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $browser = $name;
                break;
            }
        }
        $os = 'Système inconnu';
        foreach ([
            'Windows NT' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad',
            'Mac OS X' => 'macOS', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $os = $name;
                break;
            }
        }
        $mobile = (bool) preg_match('/Mobile|Android|iPhone|iPad/i', $ua);
        return ['browser' => $browser, 'os' => $os, 'mobile' => $mobile, 'label' => $browser . ' · ' . $os];
    }

    /** "il y a 5 min", "hier à 14:02", "12/10/2026 à 08:15". */
    public static function ago($datetime)
    {
        $ts = strtotime((string) $datetime);
        if (!$ts) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'à l\'instant';
        }
        if ($diff < 3600) {
            return 'il y a ' . floor($diff / 60) . ' min';
        }
        if (date('Y-m-d', $ts) === date('Y-m-d')) {
            return 'aujourd\'hui à ' . date('H:i', $ts);
        }
        if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) {
            return 'hier à ' . date('H:i', $ts);
        }
        return date('d/m/Y', $ts) . ' à ' . date('H:i', $ts);
    }

    /** "dans 12 min", "dans 3 j", "permanent". */
    public static function remaining($expires)
    {
        $ts = strtotime((string) $expires);
        if (!$ts || $ts > strtotime('+20 years')) {
            return 'permanent';
        }
        $diff = $ts - time();
        if ($diff <= 0) {
            return 'expiré';
        }
        if ($diff < 3600) {
            return 'encore ' . max(1, (int) ceil($diff / 60)) . ' min';
        }
        if ($diff < 86400) {
            return 'encore ' . (int) ceil($diff / 3600) . ' h';
        }
        return 'encore ' . (int) ceil($diff / 86400) . ' j';
    }
}
