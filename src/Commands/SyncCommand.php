<?php

namespace Torskint\LinguaFlowCi\Commands;

use CodeIgniter\CLI\CLI;

/**
 * Interroge le hub pour les envois en cours, fusionne les résultats prêts. À planifier avec cron (toutes les 5 minutes).
 */
class SyncCommand extends LinguaflowCommand
{
	protected $name = 'linguaflow:sync';

	protected $description = 'Récupère et fusionne les traductions prêtes.';

	protected $usage = 'linguaflow:sync [référence]';

	protected $arguments = ['référence' => 'Traite seulement cet envoi, sans attendre son heure (facultatif).'];

	/**
	 * Traite les envois dont l'heure est venue.
	 */
	public function run(array $params)
	{
		$hub = $this->hub();

		if (! $this->ready($hub)) {
			return EXIT_ERROR;
		}

		$results = $hub->syncer()->run($params[0] ?? null);

		if ($results === []) {
			CLI::write('Aucun envoi à traiter.');

			return EXIT_SUCCESS;
		}

		$failed = false;

		foreach ($results as $result) {
			$failed = $failed || $result['state'] === 'error';
			CLI::write('#'.$result['id'].' '.($result['reference'] ?? '—').' : '.$result['state'].($result['message'] ? ' ('.$result['message'].')' : ''), $result['state'] === 'error' ? 'red' : null);
		}

		return $failed ? EXIT_ERROR : EXIT_SUCCESS;
	}
}
