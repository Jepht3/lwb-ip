# LWB IP — gestion des IP pour WHMCS

Module complémentaire WHMCS (`lwb_ip`) pour voir, débannir et bannir les adresses IP, suivre les
tentatives de connexion échouées et donner aux clients l'historique des connexions à leur compte.
Open source, compatible avec n'importe quel WHMCS 8 (PHP 7.4 à 8.3).

## Administration (Addons › LWB IP)

| Onglet | Contenu |
|---|---|
| **Vue d'ensemble** | Votre IP et son état (bannie, protégée), IP bannies, tentatives échouées et connexions des 24 dernières heures, graphique par heure, IP les plus actives, journal des actions |
| **IP bannies** | Motif (mot de passe incorrect, manuel…), pays, ville et opérateur, nombre d'échecs sur 7 jours, temps restant ; débannir, prolonger de 24 h ou 7 jours, rendre permanent, « débannir et protéger » ; sélection multiple ; filtre instantané |
| **Bannir une IP** | IP exacte, IPv6 ou plage (`41.243.*`), durée de 15 minutes à permanent, motif. Votre propre IP ne peut pas être bannie |
| **Tentatives échouées** | Échecs de connexion admin et client regroupés par IP (24 h ou 7 jours), compte visé, état, bannir en un clic |
| **Connexions clients** | 100 dernières connexions (client, IP, lieu, appareil) et IP partagées par plusieurs comptes |
| **Liste blanche** | Ces IP ne restent jamais bannies : un ban qui les touche est levé à la page suivante ou au prochain cron |
| **Déblocage d'urgence** | Lien secret qui débannit l'IP de la personne qui l'ouvre, **même quand WHMCS l'a bannie** |

Les bans sont ceux de WHMCS (table `tblbannedips`) : un ban posé ici est appliqué par WHMCS lui-même,
et les bans automatiques de WHMCS (« 3 Invalid Login Attempts ») apparaissent ici.

## Espace client

- **Carte « Sécurité du compte »** sur le tableau de bord (thème LWB Theme) : les 3 dernières connexions
  (appareil, navigateur, lieu, « C'est vous »), avec une alerte quand la dernière connexion vient d'un
  pays inhabituel.
- **Page « Connexions et sécurité »** (`index.php?m=lwb_ip`, aussi dans le menu du compte) : historique
  complet et bouton **« Ce n'était pas moi »** qui bloque l'adresse **sur ce compte uniquement**.
  Une adresse bloquée est déconnectée immédiatement et ne peut plus se reconnecter à ce compte.

Un client ne peut jamais bannir une adresse pour tout le serveur : les opérateurs mobiles partagent une même
adresse entre de nombreux abonnés. Il ne peut pas non plus bloquer l'adresse qu'il utilise en ce moment.

WHMCS ne garde que la dernière connexion de chaque utilisateur : l'historique commence à l'activation du module.

## Installation

1. Copier `modules/addons/lwb_ip/` dans `modules/addons/` de WHMCS.
2. Configuration › Modules complémentaires : activer **LWB IP** et donner l'accès aux rôles administrateurs.
3. Ouvrir Addons › LWB IP, puis :
   - **Protéger mon IP** (bouton en haut à droite) pour ajouter votre adresse à la liste blanche ;
   - onglet **Déblocage d'urgence** : générer le lien et le garder en lieu sûr.
4. Avec LWB Theme, la carte du tableau de bord s'affiche toute seule. Avec un autre thème, ajoutez dans
   `clientareahome.tpl` l'affichage de la variable `$lwbIp` (voir `lwb-theme/templates/lwb-theme/includes/lwb-ip-card.tpl`).

## Réglages

| Réglage | Par défaut | Rôle |
|---|---|---|
| Pays et opérateur des IP | oui | interroge ipwho.is (HTTPS, sans clé) et garde le résultat 30 jours ; désactivé, aucune adresse n'est envoyée à l'extérieur |
| Sécurité dans l'espace client | oui | carte du tableau de bord, page et lien du menu |
| Historique des connexions | 90 jours | durée de conservation |

## Lien de déblocage d'urgence

WHMCS redirige un visiteur banni vers `banned.php` avant tout le reste : un administrateur banni par erreur
ne peut plus ouvrir l'administration. Le fichier `unban.php` ne charge pas WHMCS ; il lit la base, vérifie
la clé (seule son empreinte SHA-256 est enregistrée) et ne lève que les bans qui touchent l'adresse du
visiteur. Chaque utilisation est notée dans le journal du module et dans le journal d'activité de WHMCS.
Générer un nouveau lien désactive l'ancien.

## Tables créées

`mod_lwb_ip_logins` (connexions), `mod_lwb_ip_blocks` (blocages par compte), `mod_lwb_ip_whitelist`,
`mod_lwb_ip_geo` (cache des lieux), `mod_lwb_ip_events` (journal). Désactiver le module les conserve.

## Licence

MIT — LEWEBMAX Admin.
