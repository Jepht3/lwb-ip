<?php
/**
 * LWB IP — hooks (loaded by WHMCS only while the addon is active).
 *
 * @author    LEWEBMAX Admin
 * @license   MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Ip.php';
require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/Geo.php';
require_once __DIR__ . '/lib/Client.php';

use LwbIp\Client;
use LwbIp\Ip;
use LwbIp\Store;

/** Logs the user out and sends them back to the login page. */
function lwb_ip_kick($userId, $ip)
{
    Store::event('blocked', $ip, 'Connexion refusée : adresse bloquée par le client (utilisateur n° ' . (int) $userId . ')', 'Système');
    header('Location: ' . rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/logout.php');
    exit;
}

/**
 * Every successful client login is recorded (WHMCS only keeps the last one).
 * A login from an address the user blocked is closed right away.
 */
add_hook('UserLogin', 1, function ($vars) {
    try {
        if (!Store::ready() || empty($vars['user'])) {
            return;
        }
        $userId = (int) $vars['user']->id;
        $ip = Ip::current();
        Store::recordLogin($userId, $ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (Store::isBlocked($userId, $ip)) {
            lwb_ip_kick($userId, $ip);
        }
    } catch (\Throwable $e) {
        logActivity('LWB IP (connexion) : ' . $e->getMessage());
    }
});

/**
 * Client area: account blocks, whitelist, dashboard card ($lwbIp).
 */
add_hook('ClientAreaPage', 2, function ($vars) {
    try {
        if (!Store::ready()) {
            return [];
        }
        Store::applyWhitelist();
        $userId = Client::userId();
        if (!$userId) {
            return [];
        }
        $ip = Ip::current();
        if (Store::isBlocked($userId, $ip)) {
            lwb_ip_kick($userId, $ip);
        }
        $isHome = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'clientarea.php' && empty($_REQUEST['action']);
        if ($isHome && Store::flag('client_page')) {
            $card = Client::card($userId);
            return $card ? ['lwbIp' => $card] : [];
        }
    } catch (\Throwable $e) {
        logActivity('LWB IP (espace client) : ' . $e->getMessage());
    }
    return [];
});

/** "Connexions et sécurité" in the account menu of the client area. */
add_hook('ClientAreaSecondaryNavbar', 20, function ($navbar) {
    try {
        if (!Store::flag('client_page') || !Client::userId()) {
            return;
        }
        $account = $navbar->getChild('Account');
        if ($account) {
            $account->addChild('lwbIpSecurity', [
                'label' => 'Connexions et sécurité',
                'uri' => 'index.php?m=lwb_ip',
                'order' => 45,
                'icon' => 'fa-shield-alt',
            ]);
        }
    } catch (\Throwable $e) {
    }
});

/** Admin pages: lift bans that hit whitelisted addresses. */
add_hook('AdminAreaPage', 2, function ($vars) {
    try {
        if (Store::ready()) {
            Store::applyWhitelist();
        }
    } catch (\Throwable $e) {
    }
    return [];
});

/** Cron: whitelist and clean-up of old history. */
add_hook('AfterCronJob', 2, function () {
    try {
        if (Store::ready()) {
            Store::applyWhitelist('Cron (liste blanche)');
            Store::prune((int) Store::setting('retention', 90));
        }
    } catch (\Throwable $e) {
        logActivity('LWB IP (cron) : ' . $e->getMessage());
    }
});
