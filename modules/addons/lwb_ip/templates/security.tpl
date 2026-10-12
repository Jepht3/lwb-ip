{* LWB IP — connexions et sécurité du compte · LEWEBMAX Admin *}
<link rel="stylesheet" href="{$WEB_ROOT}/modules/addons/lwb_ip/assets/client.css">
<div class="lwbipc">

    {if $lwbip.flash}
        <div class="lwbipc-flash lwbipc-{$lwbip.flash[0]}">{$lwbip.flash[1]|escape}</div>
    {/if}

    <section class="lwbipc-hero">
        <div class="lwbipc-hero-ico"><i class="fas fa-shield-alt"></i></div>
        <div>
            <h2>Connexions à votre compte</h2>
            <p>Vérifiez que chaque connexion vient bien de vous. Si une connexion vous semble inconnue, bloquez l'adresse puis changez votre mot de passe.</p>
            <div class="lwbipc-now">
                <span class="lwbipc-dot"></span>
                Vous êtes connecté depuis <b>{$lwbip.me_short|escape}</b>{if $lwbip.me_place} · {$lwbip.me_place|escape}{/if}
            </div>
        </div>
        <div class="lwbipc-hero-actions">
            <a class="lwbipc-btn lwbipc-btn-primary" href="{$WEB_ROOT}/index.php?rp=/user/password"><i class="fas fa-key"></i> Changer mon mot de passe</a>
            <a class="lwbipc-btn" href="{$WEB_ROOT}/index.php?rp=/user/security"><i class="fas fa-mobile-alt"></i> Double authentification</a>
        </div>
    </section>

    <section class="lwbipc-card">
        <header>
            <h3>Dernières connexions</h3>
            <span>Conservées {$lwbip.retention} jours</span>
        </header>
        {if $lwbip.logins}
            <ul class="lwbipc-list">
                {foreach $lwbip.logins as $l}
                    <li class="{if $l.current}is-current{/if}{if $l.blocked} is-blocked{/if}">
                        <span class="lwbipc-dev"><i class="fas {if $l.mobile}fa-mobile-alt{else}fa-desktop{/if}"></i></span>
                        <div class="lwbipc-main">
                            <b>{$l.device|escape}</b>
                            <small>{if $l.place}{$l.place|escape} · {/if}<span title="{$l.ip|escape}">{$l.ip_short|escape}</span>{if $l.isp} · {$l.isp|escape}{/if}</small>
                        </div>
                        <div class="lwbipc-when" title="{$l.date}">{$l.ago}</div>
                        <div class="lwbipc-act">
                            {if $l.current}
                                <span class="lwbipc-pill lwbipc-pill-good">C'est vous</span>
                            {elseif $l.blocked}
                                <span class="lwbipc-pill lwbipc-pill-danger">Bloquée</span>
                            {elseif $l.same_ip}
                                <span class="lwbipc-pill">Votre adresse</span>
                            {else}
                                <form method="post" action="{$WEB_ROOT}/index.php?m=lwb_ip" onsubmit="return confirm('Bloquer cette adresse sur votre compte ? Toute personne qui l\'utilise ne pourra plus accéder à votre compte.')">
                                    <input type="hidden" name="token" value="{$token}">
                                    <input type="hidden" name="lwbip" value="block">
                                    <input type="hidden" name="ip" value="{$l.ip|escape}">
                                    <button type="submit" class="lwbipc-btn lwbipc-btn-sm">Ce n'était pas moi</button>
                                </form>
                            {/if}
                        </div>
                    </li>
                {/foreach}
            </ul>
        {else}
            <div class="lwbipc-empty"><i class="fas fa-history"></i><p>Vos prochaines connexions apparaîtront ici.</p></div>
        {/if}
    </section>

    <section class="lwbipc-card">
        <header>
            <h3>Adresses bloquées</h3>
            <span>Elles ne peuvent plus accéder à votre compte</span>
        </header>
        {if $lwbip.blocks}
            <ul class="lwbipc-list">
                {foreach $lwbip.blocks as $b}
                    <li>
                        <span class="lwbipc-dev lwbipc-dev-danger"><i class="fas fa-ban"></i></span>
                        <div class="lwbipc-main">
                            <b title="{$b.ip|escape}">{$b.ip_short|escape}</b>
                            <small>{if $b.place}{$b.place|escape} · {/if}bloquée {$b.since}</small>
                        </div>
                        <div class="lwbipc-act">
                            <form method="post" action="{$WEB_ROOT}/index.php?m=lwb_ip">
                                <input type="hidden" name="token" value="{$token}">
                                <input type="hidden" name="lwbip" value="unblock">
                                <input type="hidden" name="id" value="{$b.id}">
                                <button type="submit" class="lwbipc-btn lwbipc-btn-sm">Débloquer</button>
                            </form>
                        </div>
                    </li>
                {/foreach}
            </ul>
        {else}
            <div class="lwbipc-empty"><i class="fas fa-check-circle"></i><p>Aucune adresse bloquée.</p></div>
        {/if}
        <p class="lwbipc-hint"><i class="fas fa-info-circle"></i> Le blocage ne concerne que votre compte. Les opérateurs mobiles partagent parfois une même adresse entre plusieurs abonnés : en cas de doute, changez surtout votre mot de passe.</p>
    </section>
</div>
