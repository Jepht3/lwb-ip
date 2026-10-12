<?php
/**
 * LWB IP — gestion des IP pour WHMCS.
 *
 * Administration : IP bannies (mot de passe incorrect ou autre) avec pays et
 * opérateur, débannir, prolonger, bannir une IP ou une plage, tentatives
 * échouées, connexions des clients, liste blanche et lien de déblocage
 * d'urgence (fonctionne même quand votre IP est bannie).
 *
 * Espace client : historique des connexions au compte (appareil, lieu) et
 * blocage d'une IP sur son propre compte : index.php?m=lwb_ip
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
require_once __DIR__ . '/lib/Admin.php';
require_once __DIR__ . '/lib/Client.php';

use LwbIp\Client;
use LwbIp\Store;

function lwb_ip_config()
{
    return [
        'name' => 'LWB IP',
        'description' => 'IP bannies, déblocage, tentatives échouées, connexions des clients et liste blanche. '
            . 'Les clients voient leurs dernières connexions et peuvent bloquer une IP sur leur compte : index.php?m=lwb_ip',
        'version' => '1.0.0',
        'author' => 'LEWEBMAX Admin',
        'language' => 'french',
        'fields' => [
            'geo' => [
                'FriendlyName' => 'Pays et opérateur des IP',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Affiche le pays, la ville et l\'opérateur (service ipwho.is, résultats gardés 30 jours)',
            ],
            'client_page' => [
                'FriendlyName' => 'Sécurité dans l\'espace client',
                'Type' => 'yesno',
                'Default' => 'yes',
                'Description' => 'Dernières connexions sur le tableau de bord et page « Connexions et sécurité »',
            ],
            'retention' => [
                'FriendlyName' => 'Historique des connexions',
                'Type' => 'dropdown',
                'Options' => ['30' => '30 jours', '90' => '90 jours', '180' => '180 jours', '365' => '1 an'],
                'Default' => '90',
            ],
        ],
    ];
}

function lwb_ip_activate()
{
    try {
        Store::install();
        return ['status' => 'success', 'description' => 'LWB IP est actif. Pensez à protéger votre IP et à générer le lien de déblocage d\'urgence (Addons > LWB IP).'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'LWB IP : ' . $e->getMessage()];
    }
}

function lwb_ip_deactivate()
{
    return ['status' => 'success', 'description' => 'LWB IP est désactivé. Les bans WHMCS et l\'historique sont conservés.'];
}

function lwb_ip_upgrade($vars)
{
    Store::install();
}

function lwb_ip_output($vars)
{
    Store::install();
    (new \LwbIp\Admin($vars['modulelink']))->dispatch();
}

function lwb_ip_clientarea($vars)
{
    Store::install();
    return (new Client())->page();
}
