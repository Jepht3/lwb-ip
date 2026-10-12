<?php

namespace LwbIp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Admin page: Addons > LWB IP.
 */
class Admin
{
    const TABS = [
        'overview' => ['Vue d\'ensemble', 'fa-shield-alt'],
        'bans' => ['IP bannies', 'fa-ban'],
        'attempts' => ['Tentatives échouées', 'fa-user-lock'],
        'logins' => ['Connexions clients', 'fa-sign-in-alt'],
        'whitelist' => ['Liste blanche', 'fa-check-circle'],
        'emergency' => ['Déblocage d\'urgence', 'fa-life-ring'],
    ];

    private $link;
    private $flash = [];
    private $me;
    private $actor;

    public function __construct($modulelink)
    {
        $this->link = $modulelink;
        $this->me = Ip::current();
        $this->actor = 'Admin ' . (\WHMCS\Session::get('adminid') ?: '');
        try {
            $this->actor = 'Admin ' . (string) \WHMCS\Database\Capsule::table('tbladmins')->where('id', (int) \WHMCS\Session::get('adminid'))->value('username');
        } catch (\Throwable $e) {
        }
    }

    public function dispatch()
    {
        $tab = preg_replace('/[^a-z]/', '', (string) ($_REQUEST['tab'] ?? 'overview'));
        $tab = isset(self::TABS[$tab]) ? $tab : 'overview';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_token('WHMCS.admin.default');
            try {
                $this->handlePost((string) ($_POST['do'] ?? ''));
            } catch (\Throwable $e) {
                $this->flash[] = ['danger', $e->getMessage()];
            }
        }
        echo '<style>' . file_get_contents(dirname(__DIR__) . '/assets/admin.css') . '</style>';
        echo '<div class="lwbip">';
        echo '<div class="lwbip-head"><div><p class="lwbip-eyebrow">Sécurité</p><h1>LWB IP</h1></div>' . $this->meBox() . '</div>';
        echo '<nav class="lwbip-tabs">';
        foreach (self::TABS as $key => [$label, $icon]) {
            $badge = '';
            if ($key === 'bans') {
                $badge = '<b>' . count(Store::bans()) . '</b>';
            }
            echo '<a class="' . ($key === $tab ? 'on' : '') . '" href="' . $this->url(['tab' => $key]) . '"><i class="fas ' . $icon . '"></i>' . $label . $badge . '</a>';
        }
        echo '</nav>';
        foreach ($this->flash as [$type, $msg]) {
            echo '<div class="lwbip-flash lwbip-' . $type . '">' . $msg . '</div>';
        }
        $this->{'tab' . ucfirst($tab)}();
        echo '</div>';
        echo '<script>' . file_get_contents(dirname(__DIR__) . '/assets/admin.js') . '</script>';
    }

    /* ----------------------------- Actions ---------------------------- */

    private function handlePost($do)
    {
        switch ($do) {
            case 'unban':
                $ip = Store::unban((int) $_POST['id'], $this->actor);
                $this->flash[] = ['success', $ip ? '<b>' . $this->e($ip) . '</b> est débannie : elle peut de nouveau se connecter.' : 'Ce ban n\'existe plus.'];
                break;
            case 'unban_many':
                $n = 0;
                foreach ((array) ($_POST['ids'] ?? []) as $id) {
                    $n += Store::unban((int) $id, $this->actor) ? 1 : 0;
                }
                $this->flash[] = ['success', $n . ' IP débannie(s).'];
                break;
            case 'unban_me':
                $n = Store::unbanIp($this->me, $this->actor);
                $this->flash[] = ['success', $n ? 'Votre IP est débannie.' : 'Votre IP n\'était pas bannie.'];
                break;
            case 'ban':
                $minutes = (int) ($_POST['minutes'] ?? 1440);
                $minutes = array_key_exists($minutes, Store::DURATIONS) ? $minutes : 1440;
                $target = (string) ($_POST['ip'] ?? '');
                if (Ip::matches(trim($target), $this->me) || Ip::matches($this->me, trim($target))) {
                    throw new \InvalidArgumentException('Cette règle bannirait votre propre IP (' . $this->me . ') : action refusée.');
                }
                $ip = Store::ban($target, $minutes, (string) ($_POST['reason'] ?? ''), $this->actor);
                $this->flash[] = ['success', '<b>' . $this->e($ip) . '</b> est bannie (' . Store::DURATIONS[$minutes] . ').'];
                break;
            case 'extend':
                $minutes = (int) ($_POST['minutes'] ?? 1440);
                Store::extend((int) $_POST['id'], array_key_exists($minutes, Store::DURATIONS) ? $minutes : 1440, $this->actor);
                $this->flash[] = ['success', 'Ban mis à jour.'];
                break;
            case 'whitelist':
                $ip = Store::addWhitelist((string) $_POST['ip'], (string) ($_POST['note'] ?? ''), $this->actor);
                $this->flash[] = ['success', '<b>' . $this->e($ip) . '</b> est dans la liste blanche : elle ne restera jamais bannie.'];
                break;
            case 'unwhitelist':
                Store::removeWhitelist((int) $_POST['id'], $this->actor);
                $this->flash[] = ['success', 'IP retirée de la liste blanche.'];
                break;
            case 'emergency_key':
                $key = bin2hex(random_bytes(24));
                Store::setSetting('unban_key_hash', hash('sha256', $key));
                Store::event('key', '', 'Nouveau lien de déblocage d\'urgence généré', $this->actor);
                $url = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/modules/addons/lwb_ip/unban.php?key=' . $key;
                $this->flash[] = ['success', 'Nouveau lien créé. <b>Copiez-le maintenant et gardez-le en lieu sûr</b> (favori, gestionnaire de mots de passe) : il ne sera plus affiché.'
                    . '<div class="lwbip-secret"><input readonly value="' . $this->e($url) . '" onclick="this.select()"><button type="button" data-copy="' . $this->e($url) . '">Copier</button></div>'
                    . 'L\'ancien lien ne fonctionne plus.'];
                break;
        }
    }

    /* ------------------------------- Tabs ----------------------------- */

    private function tabOverview()
    {
        $bans = Store::bans();
        $auto = count(array_filter($bans, function ($b) {
            return stripos((string) $b->reason, 'Invalid Login') !== false;
        }));
        $fail24 = Store::failures(24);
        $attempts24 = array_sum(array_column($fail24, 'count'));
        echo '<div class="lwbip-kpis">'
            . $this->kpi('fa-ban', 'IP bannies', count($bans), $auto . ' pour mot de passe incorrect · ' . (count($bans) - $auto) . ' autres', 'bans', count($bans) ? 'warn' : '')
            . $this->kpi('fa-user-lock', 'Tentatives échouées · 24 h', $attempts24, count($fail24) . ' IP différente(s)', 'attempts', $attempts24 >= 10 ? 'danger' : '')
            . $this->kpi('fa-sign-in-alt', 'Connexions clients · 24 h', Store::loginsSince(24), Store::loginsSince(24 * 7) . ' sur 7 jours', 'logins')
            . $this->kpi('fa-check-circle', 'Liste blanche', count(Store::whitelist()), Store::blocksCount() . ' IP bloquée(s) par des clients', 'whitelist')
            . '</div>';

        echo '<div class="lwbip-grid"><section class="lwbip-card span-8"><header><h2>Tentatives échouées par heure</h2><span class="lwbip-muted">24 dernières heures</span></header>' . $this->chart(Store::failuresPerHour()) . '</section>';
        echo '<section class="lwbip-card span-4"><header><h2>IP les plus actives</h2><a href="' . $this->url(['tab' => 'attempts']) . '">Tout voir →</a></header>';
        $top = array_slice($fail24, 0, 6, true);
        if (!$top) {
            echo $this->empty('Aucune tentative échouée en 24 h.', 'fa-check');
        } else {
            echo '<ul class="lwbip-list">';
            foreach ($top as $f) {
                echo '<li><div><b>' . $this->e(Ip::short($f['ip'])) . '</b><small>' . $this->e(Geo::label($f['ip'], false) ?: '—') . '</small></div><span class="lwbip-pill ' . ($f['count'] >= 5 ? 'danger' : 'warn') . '">' . $f['count'] . ' échec(s)</span></li>';
            }
            echo '</ul>';
        }
        echo '</section></div>';

        echo '<section class="lwbip-card"><header><h2>Journal</h2><span class="lwbip-muted">Bans, débans, liste blanche et blocages clients</span></header>';
        $events = Store::events(15);
        if (!$events) {
            echo $this->empty('Aucune action pour le moment.', 'fa-history');
        } else {
            $labels = ['ban' => ['Bannie', 'danger'], 'unban' => ['Débannie', 'good'], 'extend' => ['Prolongée', 'warn'], 'whitelist' => ['Liste blanche', 'good'], 'unwhitelist' => ['Retirée', 'muted'], 'block' => ['Bloquée (client)', 'warn'], 'unblock' => ['Débloquée (client)', 'good'], 'key' => ['Lien d\'urgence', 'info'], 'emergency' => ['Déblocage d\'urgence', 'info'], 'blocked' => ['Connexion refusée', 'danger']];
            echo '<table class="lwbip-table"><thead><tr><th>Quand</th><th>Action</th><th>IP</th><th>Détail</th><th>Par</th></tr></thead><tbody>';
            foreach ($events as $ev) {
                [$label, $tone] = $labels[$ev->action] ?? [$ev->action, 'muted'];
                echo '<tr><td class="nowrap">' . $this->e(Ip::ago($ev->created_at)) . '</td><td><span class="lwbip-pill ' . $tone . '">' . $this->e($label) . '</span></td><td class="mono">' . $this->e(Ip::short($ev->ip)) . '</td><td>' . $this->e($ev->detail) . '</td><td class="lwbip-muted">' . $this->e($ev->actor) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    private function tabBans()
    {
        $fail = Store::failures(24 * 7);
        echo '<div class="lwbip-grid"><section class="lwbip-card span-8"><header><h2>IP bannies</h2><input class="lwbip-search" type="search" placeholder="Filtrer : IP, pays, motif…" data-filter="#lwbip-bans"></header>';
        $bans = Store::bans();
        if (!$bans) {
            echo $this->empty('Aucune IP bannie en ce moment.', 'fa-check');
        } else {
            echo '<form method="post" action="' . $this->url(['tab' => 'bans']) . '" id="lwbip-bulk" onsubmit="return confirm(\'Débannir les IP sélectionnées ?\')">' . $this->token() . '<input type="hidden" name="do" value="unban_many"></form>';
            echo '<div class="lwbip-scroll"><table class="lwbip-table" id="lwbip-bans"><thead><tr><th><input type="checkbox" data-all="ids[]"></th><th>IP</th><th>Origine</th><th>Motif</th><th>Expiration</th><th></th></tr></thead><tbody>';
            foreach ($bans as $b) {
                $auto = stripos((string) $b->reason, 'Invalid Login') !== false;
                $attempts = $fail[$b->ip]['count'] ?? 0;
                $reason = $auto ? 'Mot de passe incorrect' : ($b->reason !== '' ? $b->reason : 'Manuel');
                echo '<tr><td><input type="checkbox" name="ids[]" value="' . (int) $b->id . '" form="lwbip-bulk"></td>'
                    . '<td><b class="mono">' . $this->e($b->ip) . '</b>' . (Ip::isRange($b->ip) ? '<small>Plage d\'adresses</small>' : '') . ($b->ip === $this->me ? '<small class="lwbip-txt-danger">C\'est votre IP</small>' : '') . '</td>'
                    . '<td>' . $this->e(Ip::isRange($b->ip) ? '—' : (Geo::label($b->ip) ?: '—')) . '</td>'
                    . '<td><span class="lwbip-pill ' . ($auto ? 'warn' : 'danger') . '">' . $this->e($reason) . '</span>' . ($attempts ? '<small>' . $attempts . ' échec(s) sur 7 j</small>' : '') . '</td>'
                    . '<td class="nowrap">' . $this->e(Ip::remaining($b->expires)) . '<small>' . $this->e(strtotime($b->expires) > strtotime('+20 years') ? '' : date('d/m H:i', strtotime($b->expires))) . '</small></td>'
                    . '<td class="lwbip-actions">'
                    . $this->button('unban', ['id' => $b->id], 'Débannir', 'primary')
                    . '<details class="lwbip-more"><summary title="Plus d\'actions"><i class="fas fa-ellipsis-h"></i></summary><div>'
                    . $this->button('extend', ['id' => $b->id, 'minutes' => 1440], '+ 24 heures')
                    . $this->button('extend', ['id' => $b->id, 'minutes' => 10080], '+ 7 jours')
                    . $this->button('extend', ['id' => $b->id, 'minutes' => 0], 'Rendre permanent', '', 'Bannir définitivement ' . $b->ip . ' ?')
                    . (!Ip::isRange($b->ip) ? $this->button('whitelist', ['ip' => $b->ip, 'note' => 'Depuis la liste des bans'], 'Débannir et protéger') : '')
                    . '</div></details></td></tr>';
            }
            echo '</tbody></table></div><div class="lwbip-bulkbar"><button class="lwbip-btn" type="submit" form="lwbip-bulk"><i class="fas fa-unlock"></i> Débannir la sélection</button></div>';
        }
        echo '</section>';

        echo '<section class="lwbip-card span-4"><header><h2>Bannir une IP</h2></header>'
            . '<form method="post" action="' . $this->url(['tab' => 'bans']) . '" class="lwbip-form">' . $this->token() . '<input type="hidden" name="do" value="ban">'
            . '<label>Adresse IP ou plage<input name="ip" required placeholder="41.243.12.7 ou 41.243.*" value="' . $this->e($_GET['ip'] ?? '') . '"></label>'
            . '<label>Durée<select name="minutes">';
        foreach (Store::DURATIONS as $m => $label) {
            echo '<option value="' . $m . '"' . ($m === 1440 ? ' selected' : '') . '>' . $label . '</option>';
        }
        echo '</select></label><label>Motif<input name="reason" maxlength="200" placeholder="Attaque par force brute, spam…"></label>'
            . '<button class="lwbip-btn primary" type="submit"><i class="fas fa-ban"></i> Bannir</button>'
            . '<p class="lwbip-hint">Une plage (<code>41.243.*</code>) bloque toutes les adresses qui commencent ainsi : attention aux opérateurs mobiles, qui partagent leurs adresses entre de nombreux clients. Votre propre IP ne peut pas être bannie.</p></form></section></div>';

        $expired = array_slice(Store::bans(false), 0, 10);
        if ($expired) {
            echo '<section class="lwbip-card"><header><h2>Bans expirés récents</h2><span class="lwbip-muted">WHMCS ne les applique plus</span></header><ul class="lwbip-list">';
            foreach ($expired as $b) {
                echo '<li><div><b class="mono">' . $this->e($b->ip) . '</b><small>' . $this->e($b->reason ?: 'Manuel') . ' · expiré le ' . date('d/m/Y H:i', strtotime($b->expires)) . '</small></div>'
                    . $this->button('ban', ['ip' => $b->ip, 'minutes' => 1440, 'reason' => 'Rebanni : ' . $b->reason], 'Rebannir 24 h') . '</li>';
            }
            echo '</ul></section>';
        }
    }

    private function tabAttempts()
    {
        $hours = (int) ($_GET['h'] ?? 24) === 168 ? 168 : 24;
        $fail = Store::failures($hours);
        echo '<section class="lwbip-card"><header><h2>Tentatives de connexion échouées</h2><nav class="lwbip-seg">'
            . '<a class="' . ($hours === 24 ? 'on' : '') . '" href="' . $this->url(['tab' => 'attempts', 'h' => 24]) . '">24 h</a>'
            . '<a class="' . ($hours === 168 ? 'on' : '') . '" href="' . $this->url(['tab' => 'attempts', 'h' => 168]) . '">7 jours</a></nav></header>';
        if (!$fail) {
            echo $this->empty('Aucune tentative échouée sur cette période.', 'fa-check');
        } else {
            echo '<div class="lwbip-scroll"><table class="lwbip-table"><thead><tr><th>IP</th><th>Origine</th><th>Échecs</th><th>Compte visé</th><th>Dernière</th><th>État</th><th></th></tr></thead><tbody>';
            foreach ($fail as $f) {
                $ban = $f['ip'] !== 'inconnue' ? Store::banFor($f['ip']) : null;
                $white = $f['ip'] !== 'inconnue' && Store::whitelisted($f['ip']);
                $state = $ban ? '<span class="lwbip-pill danger">Bannie · ' . $this->e(Ip::remaining($ban->expires)) . '</span>' : ($white ? '<span class="lwbip-pill good">Liste blanche</span>' : '<span class="lwbip-pill muted">Autorisée</span>');
                echo '<tr><td><b class="mono">' . $this->e(Ip::short($f['ip'])) . '</b>' . ($f['ip'] === $this->me ? '<small class="lwbip-txt-danger">C\'est vous</small>' : '') . '</td>'
                    . '<td>' . $this->e(Geo::label($f['ip']) ?: '—') . '</td>'
                    . '<td><b>' . $f['count'] . '</b>' . ($f['admin'] ? '<small>admin</small>' : '<small>client</small>') . '</td>'
                    . '<td>' . $this->e(implode(', ', $f['targets']) ?: '—') . '</td>'
                    . '<td class="nowrap">' . $this->e(Ip::ago($f['last'])) . '</td><td>' . $state . '</td><td class="lwbip-actions">'
                    . ($f['ip'] === 'inconnue' ? '' : ($ban ? $this->button('unban', ['id' => $ban->id], 'Débannir') : ($f['ip'] !== $this->me && !$white ? $this->button('ban', ['ip' => $f['ip'], 'minutes' => 1440, 'reason' => $f['count'] . ' tentatives échouées'], 'Bannir 24 h', 'danger') : '')))
                    . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '<p class="lwbip-hint">WHMCS bannit automatiquement une IP pendant ' . (int) \WHMCS\Config\Setting::getValue('InvalidLoginBanLength') . ' minutes après 3 mots de passe incorrects (Configuration › Paramètres généraux › Sécurité).</p></section>';
    }

    private function tabLogins()
    {
        echo '<div class="lwbip-grid"><section class="lwbip-card span-8"><header><h2>Dernières connexions des clients</h2><input class="lwbip-search" type="search" placeholder="Filtrer : client, IP, pays…" data-filter="#lwbip-logins"></header>';
        $logins = Store::allLogins(100);
        if (!$logins) {
            echo $this->empty('Les connexions s\'afficheront ici dès les prochaines connexions de vos clients.', 'fa-sign-in-alt');
        } else {
            echo '<div class="lwbip-scroll"><table class="lwbip-table" id="lwbip-logins"><thead><tr><th>Client</th><th>IP</th><th>Origine</th><th>Appareil</th><th>Quand</th></tr></thead><tbody>';
            foreach ($logins as $l) {
                $d = Ip::device($l->user_agent);
                $name = trim($l->first_name . ' ' . $l->last_name) ?: 'Utilisateur n° ' . $l->user_id;
                echo '<tr><td><b>' . $this->e($name) . '</b><small>' . $this->e($l->email) . '</small></td><td class="mono">' . $this->e(Ip::short($l->ip)) . '</td>'
                    . '<td>' . $this->e(Geo::label($l->ip) ?: '—') . '</td>'
                    . '<td><i class="fas ' . ($d['mobile'] ? 'fa-mobile-alt' : 'fa-desktop') . ' lwbip-muted"></i> ' . $this->e($d['label']) . '</td>'
                    . '<td class="nowrap">' . $this->e(Ip::ago($l->created_at)) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section><section class="lwbip-card span-4"><header><h2>IP partagées</h2><span class="lwbip-muted">30 jours</span></header>';
        $shared = Store::sharedIps(3);
        if (!$shared) {
            echo $this->empty('Aucune IP utilisée par 3 comptes ou plus.', 'fa-users');
        } else {
            echo '<ul class="lwbip-list">';
            foreach ($shared as $s) {
                echo '<li><div><b class="mono">' . $this->e(Ip::short($s->ip)) . '</b><small>' . $this->e(Geo::label($s->ip) ?: '—') . '</small></div><span class="lwbip-pill info">' . (int) $s->users . ' comptes</span></li>';
            }
            echo '</ul>';
        }
        echo '<p class="lwbip-hint">Une même IP pour plusieurs comptes est normale chez les opérateurs mobiles (Vodacom, Orange, Airtel). Elle devient suspecte si les comptes n\'ont aucun lien entre eux.</p></section></div>';
    }

    private function tabWhitelist()
    {
        echo '<div class="lwbip-grid"><section class="lwbip-card span-8"><header><h2>Liste blanche</h2><span class="lwbip-muted">Ces IP ne restent jamais bannies</span></header>';
        $list = Store::whitelist();
        if (!$list) {
            echo $this->empty('Liste vide. Ajoutez au moins l\'IP de votre bureau.', 'fa-check-circle');
        } else {
            echo '<ul class="lwbip-list">';
            foreach ($list as $w) {
                echo '<li><div><b class="mono">' . $this->e($w->ip) . '</b><small>' . $this->e(($w->note !== '' ? $w->note . ' · ' : '') . 'ajoutée ' . Ip::ago($w->created_at)) . '</small></div>'
                    . $this->button('unwhitelist', ['id' => $w->id], 'Retirer', '', 'Retirer ' . $w->ip . ' de la liste blanche ?') . '</li>';
            }
            echo '</ul>';
        }
        echo '</section><section class="lwbip-card span-4"><header><h2>Ajouter une IP</h2></header>'
            . '<form method="post" action="' . $this->url(['tab' => 'whitelist']) . '" class="lwbip-form">' . $this->token() . '<input type="hidden" name="do" value="whitelist">'
            . '<label>Adresse IP ou plage<input name="ip" required value="' . $this->e($this->me) . '"></label>'
            . '<label>Note<input name="note" maxlength="120" placeholder="Bureau Kinshasa, maison…"></label>'
            . '<button class="lwbip-btn primary" type="submit"><i class="fas fa-plus"></i> Ajouter</button>'
            . '<p class="lwbip-hint">Si une IP de la liste est bannie (par exemple après 3 mots de passe incorrects), le ban est levé automatiquement à la page suivante ou au prochain cron.</p></form></section></div>';
    }

    private function tabEmergency()
    {
        $has = (bool) Store::setting('unban_key_hash');
        echo '<section class="lwbip-card lwbip-emergency"><div class="lwbip-emergency-ico"><i class="fas fa-life-ring"></i></div><div>'
            . '<h2>Lien de déblocage d\'urgence</h2>'
            . '<p>Quand WHMCS vous bannit, vous ne pouvez plus ouvrir l\'administration pour vous débannir. Ce lien secret fonctionne <b>même quand votre IP est bannie</b> : ouvrez-le et votre IP actuelle est débannie immédiatement.</p>'
            . '<ul><li>Il ne débannit que l\'IP de la personne qui l\'ouvre.</li><li>Chaque utilisation est notée dans le journal et dans le journal d\'activité de WHMCS.</li><li>Gardez-le privé (favori, gestionnaire de mots de passe). En générer un nouveau désactive l\'ancien.</li></ul>'
            . '<p><span class="lwbip-pill ' . ($has ? 'good' : 'warn') . '">' . ($has ? 'Un lien est actif' : 'Aucun lien pour le moment') . '</span></p>'
            . $this->button('emergency_key', [], $has ? 'Générer un nouveau lien' : 'Générer le lien', 'primary', $has ? 'Le lien actuel ne fonctionnera plus. Continuer ?' : '')
            . '</div></section>';
    }

    /* ----------------------------- Helpers ---------------------------- */

    private function meBox()
    {
        $ban = Store::banFor($this->me);
        $white = Store::whitelisted($this->me);
        $state = $ban ? '<span class="lwbip-pill danger">Bannie</span>' : ($white ? '<span class="lwbip-pill good">Protégée</span>' : '<span class="lwbip-pill muted">Non protégée</span>');
        $action = $ban ? $this->button('unban_me', [], 'Me débannir', 'primary') : (!$white ? $this->button('whitelist', ['ip' => $this->me, 'note' => 'Mon IP (' . $this->actor . ')'], 'Protéger mon IP') : '');
        return '<div class="lwbip-me"><span class="lwbip-muted">Votre IP</span><b class="mono" title="' . $this->e($this->me) . '">' . $this->e(Ip::short($this->me)) . '</b>' . $state . $action . '</div>';
    }

    private function kpi($icon, $label, $value, $sub, $tab, $tone = '')
    {
        return '<a class="lwbip-kpi ' . $tone . '" href="' . $this->url(['tab' => $tab]) . '"><span class="lwbip-kpi-ico"><i class="fas ' . $icon . '"></i></span><span class="lwbip-kpi-label">' . $this->e($label) . '</span><b>' . (int) $value . '</b><small>' . $this->e($sub) . '</small></a>';
    }

    /** Bar chart of failed attempts per hour (inline SVG, no library). */
    private function chart(array $series)
    {
        $max = max(1, max(array_column($series, 'count')));
        $w = 720;
        $h = 180;
        $bw = $w / count($series);
        $svg = '<svg class="lwbip-chart" viewBox="0 0 ' . $w . ' ' . ($h + 22) . '" role="img" aria-label="Tentatives échouées par heure">';
        foreach ($series as $i => $p) {
            $bh = $p['count'] ? max(3, round($p['count'] / $max * ($h - 18))) : 0;
            $x = round($i * $bw + $bw * 0.18, 1);
            $svg .= '<g><title>' . $this->e($p['hour'] . ' : ' . $p['count'] . ' tentative(s)') . '</title>'
                . '<rect class="lwbip-bar-bg" x="' . $x . '" y="0" width="' . round($bw * 0.64, 1) . '" height="' . $h . '" rx="4"/>'
                . ($bh ? '<rect class="lwbip-bar' . ($p['count'] >= 5 ? ' hot' : '') . '" x="' . $x . '" y="' . ($h - $bh) . '" width="' . round($bw * 0.64, 1) . '" height="' . $bh . '" rx="4"/>' : '')
                . ($i % 3 === 0 ? '<text x="' . round($x + $bw * 0.32, 1) . '" y="' . ($h + 16) . '" text-anchor="middle">' . $this->e($p['hour']) . '</text>' : '')
                . '</g>';
        }
        return $svg . '</svg>';
    }

    private function empty($text, $icon)
    {
        return '<div class="lwbip-empty"><i class="fas ' . $icon . '"></i><p>' . $this->e($text) . '</p></div>';
    }

    private function button($do, array $fields, $label, $class = '', $confirm = '')
    {
        $html = '<form method="post" action="' . $this->url(['tab' => preg_replace('/[^a-z]/', '', (string) ($_REQUEST['tab'] ?? 'overview'))]) . '" class="lwbip-inline"'
            . ($confirm !== '' ? ' onsubmit="return confirm(\'' . $this->e(addslashes($confirm)) . '\')"' : '') . '>' . $this->token()
            . '<input type="hidden" name="do" value="' . $this->e($do) . '">';
        foreach ($fields as $k => $v) {
            $html .= '<input type="hidden" name="' . $this->e($k) . '" value="' . $this->e($v) . '">';
        }
        return $html . '<button type="submit" class="lwbip-btn ' . $class . '">' . $this->e($label) . '</button></form>';
    }

    private function token()
    {
        return '<input type="hidden" name="token" value="' . $this->e(generate_token('plain')) . '">';
    }

    private function url(array $params)
    {
        return $this->e($this->link . '&' . http_build_query($params));
    }

    private function e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
