<?php

namespace Torskint\LinguaFlowCi\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Réglages de Linguaflow. Chaque valeur peut être donnée dans le fichier .env de l'application, sous la forme
 * `linguaflow.hubUrl = https://…`, ou en copiant cette classe dans `app/Config/LinguaFlow.php` (qui étend celle-ci).
 */
class LinguaFlow extends BaseConfig
{
	/** Adresse du hub (https obligatoire hors machine locale). */
	public string $hubUrl = '';

	/** Clé d'API du hub (jeton). À garder dans .env, jamais dans le dépôt. */
	public string $apiKey = '';

	/** Secret du webhook (whsec_…), propre à ce client. À garder dans .env. */
	public string $webhookSecret = '';

	/** Mode de livraison du résultat : « local » (sondage par `spark linguaflow:sync`) ou « webhook » (le hub notifie). */
	public string $delivery = 'local';

	/** Adresse publique du webhook donnée au hub ; vide = site_url('linguaflow/webhook'). */
	public string $webhookUrl = '';

	/** Langue source (nom du dossier dans app/Language). */
	public string $sourceLocale = 'fr';

	/** Langues cibles, séparées par des virgules (« en,de,es »). */
	public string $targetLocales = '';

	/** Dossier des langues de l'application ; vide = APPPATH.'Language'. */
	public string $langPath = '';

	/** Chemin de ce dossier dans l'archive envoyée au hub. */
	public string $archivePrefix = 'app/Language';

	/** Dossier de travail (base SQLite, boîte d'envoi, sauvegardes) ; vide = WRITEPATH.'linguaflow'. */
	public string $workPath = '';

	/** Nom de ce client dans le hub (référence d'envoi) ; vide = hôte de l'application. */
	public string $clientRef = '';

	/** Délai entre deux interrogations du hub (secondes). */
	public int $pollInterval = 300;

	/** Délai maximal entre deux interrogations quand le hub tarde ou échoue (secondes). */
	public int $maxInterval = 3600;

	/** Au bout de ce nombre de jours sans résultat, l'envoi est abandonné. */
	public int $maxAgeDays = 14;

	/**
	 * Langues cibles sous forme de liste.
	 *
	 * @return list<string>
	 */
	public function locales(): array
	{
		return array_values(array_filter(array_map('trim', explode(',', $this->targetLocales))));
	}
}
