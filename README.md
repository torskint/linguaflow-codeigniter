# Linguaflow pour CodeIgniter 4

Client autonome du Translation Hub pour CodeIgniter 4 (aucune dépendance à Laravel ni à `torskint/linguaflow`).
Il parle le protocole v1 du hub (`X-LinguaFlow-Protocol: 1`, `framework: "codeigniter"`).

## Ce qu'il fait

1. Lit `app/Language/{langue}/**.php` (tableaux imbriqués, **lus sans être exécutés**) ; clé = chemin pointé (`welcome.title`).
2. Calcule le **delta** : pour chaque langue cible, les clés absentes ou vides.
3. Envoie une archive zip (fichiers source réduits aux clés demandées + `.linguaflow/manifest.json`) avec une `Idempotency-Key` ;
   la **référence** reçue est gardée dans `writable/linguaflow/jobs.sqlite`. Plusieurs envois peuvent tourner en parallèle.
4. Récupère le résultat : **sondage** (`php spark linguaflow:sync`, à planifier avec cron) ou **webhook signé** (`POST /linguaflow/webhook`).
5. Fusionne sans jamais rien écraser : une valeur non vide n'est pas touchée, une traduction dont les marqueurs (`{name}`, `{0}`, `%s`, balises)
   diffèrent de la source est refusée, l'écriture est atomique avec copie de l'ancien fichier (`writable/linguaflow/backups`). Rejouer la fusion ne change rien.
6. Confirme au hub (`ack`).

## Installation

```bash
composer require torskint/linguaflow-codeigniter
```

Le dépôt est privé : ajouter son adresse dans `repositories` de `composer.json` (type `vcs`).
Commandes spark et route du webhook sont découvertes automatiquement (découverte des paquets Composer de CodeIgniter 4).

Réglages dans le `.env` de l'application (jamais committé) :

```ini
linguaflow.hubUrl        = https://hub.example.com
linguaflow.apiKey        = <jeton du hub>
linguaflow.sourceLocale  = fr
linguaflow.targetLocales = en,de,es
linguaflow.delivery      = local        # ou webhook
linguaflow.webhookSecret = whsec_…      # seulement en mode webhook
```

Autres réglages (classe `Torskint\LinguaFlowCi\Config\LinguaFlow`) : `webhookUrl`, `langPath`, `archivePrefix`, `workPath`, `clientRef`,
`pollInterval` (300 s), `maxInterval` (3600 s), `maxAgeDays` (14). Pour les fixer en PHP, créer `app/Config/LinguaFlow.php` qui étend la classe du paquet.

## Commandes

| Commande | Rôle |
|---|---|
| `php spark linguaflow:doctor` | Vérifie réglages, fichiers de langue, dossier de travail et connexion au hub |
| `php spark linguaflow:send [--locales=en,de] [--full] [--dry-run]` | Envoie les textes manquants |
| `php spark linguaflow:sync [référence]` | Interroge le hub et fusionne les résultats prêts |
| `php spark linguaflow:status` | Liste les derniers envois |
| `php spark linguaflow:cancel <n°\|référence>` | Annule un envoi non terminé |

Cron (mode local, et filet de sécurité en mode webhook) :

```cron
*/5 * * * * cd /chemin/vers/app && php spark linguaflow:sync >> /dev/null 2>&1
```

## Mode webhook

Donner au hub l'adresse affichée par `linguaflow:doctor` (`https://…/linguaflow/webhook`) et saisir ici le secret `whsec_…` du client.
Chaque requête est vérifiée : signature HMAC-SHA256 (`X-Hub-Signature`), date à 5 minutes près, référence présente. Le résultat est ensuite
téléchargé et fusionné après l'envoi de la réponse (PHP-FPM) ; en cas d'échec, le sondage cron reprend l'envoi. Exclure la route du filtre CSRF
si l'application l'active globalement (`$globals['before']['csrf'] = ['except' => ['linguaflow/webhook']]`).

## Signaler une erreur du hub

Quand le hub refuse ou échoue une requête, le message affiché par les commandes (`linguaflow:doctor`, `linguaflow:send`, `linguaflow:sync`, `linguaflow:status`)
se termine par `[X-Request-Id : …]`. Citer cet identifiant au support : il retrouve l'appel exact dans l'**Activité API** du hub (recherche par identifiant
de requête). Une panne réseau n'a pas d'identifiant, puisque le hub n'a pas répondu.

## Limites connues (v1)

- Seul `app/Language` est lu (pas les dossiers de langue de modules ni de paquets).
- Les clés qui contiennent elles-mêmes un point sont signalées et non envoyées (le hub les refuse).
- Un fichier de langue cible est réécrit en entier (syntaxe courte, apostrophes, tabulations) : les commentaires du fichier cible ne sont pas conservés
  (l'ancien fichier est sauvegardé).

## Développement

```bash
composer install
vendor/bin/phpunit      # cœur du paquet, sans application CodeIgniter (hub simulé)
```
