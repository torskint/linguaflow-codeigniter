<?php

namespace Torskint\LinguaFlowCi\Commands;

use CodeIgniter\CLI\CLI;

/**
 * Liste les derniers envois et leur état.
 */
class StatusCommand extends LinguaflowCommand
{
	protected $name = 'linguaflow:status';

	protected $description = 'Affiche les derniers envois au hub.';

	protected $usage = 'linguaflow:status';

	/**
	 * Tableau des envois, du plus récent au plus ancien.
	 */
	public function run(array $params)
	{
		$jobs = $this->hub()->store()->all(30);

		if ($jobs === []) {
			CLI::write('Aucun envoi pour le moment.');

			return EXIT_SUCCESS;
		}

		$rows = array_map(fn ($job) => [
			$job['id'],
			$job['reference'] ?? '—',
			$job['status'].($job['hub_status'] ? ' / '.$job['hub_status'] : ''),
			implode(',', $job['locales']),
			$job['keys_count'],
			$job['sent_at'] ? date('Y-m-d H:i', $job['sent_at']) : '—',
			(string) ($job['error'] ?? ''),
		], $jobs);

		CLI::table($rows, ['#', 'Référence', 'État', 'Langues', 'Textes', 'Envoyé le', 'Erreur']);

		return EXIT_SUCCESS;
	}
}
