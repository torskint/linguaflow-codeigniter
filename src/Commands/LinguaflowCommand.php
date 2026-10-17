<?php

namespace Torskint\LinguaFlowCi\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Torskint\LinguaFlowCi\Hub;

/**
 * Base des commandes spark de Linguaflow : groupe commun et accès aux réglages.
 */
abstract class LinguaflowCommand extends BaseCommand
{
	/** Groupe affiché par `php spark list`. */
	protected $group = 'Linguaflow';

	/**
	 * Hub assemblé avec les réglages de l'application.
	 */
	protected function hub(): Hub
	{
		return Hub::make();
	}

	/**
	 * Refuse de continuer tant que l'adresse du hub et la clé d'API manquent. Renvoie vrai si tout est prêt.
	 */
	protected function ready(Hub $hub): bool
	{
		if ($hub->configured()) {
			return true;
		}

		CLI::error('Adresse du hub ou clé d\'API absente : renseignez linguaflow.hubUrl et linguaflow.apiKey dans le fichier .env.');

		return false;
	}
}
