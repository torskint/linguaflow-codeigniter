<?php

namespace Torskint\LinguaFlowCi\Commands;

use CodeIgniter\CLI\CLI;
use Throwable;
use Torskint\LinguaFlowCi\Store\JobStore;

/**
 * Annule un envoi qui n'est pas terminé (côté hub et côté local).
 */
class CancelCommand extends LinguaflowCommand
{
	protected $name = 'linguaflow:cancel';

	protected $description = 'Annule un envoi non terminé.';

	protected $usage = 'linguaflow:cancel <numéro|référence>';

	protected $arguments = ['numéro|référence' => 'Numéro local (colonne #) ou référence du hub.'];

	/**
	 * Annule l'envoi demandé.
	 */
	public function run(array $params)
	{
		$hub = $this->hub();
		$store = $hub->store();
		$id = $params[0] ?? '';
		$job = ctype_digit((string) $id) ? $store->find((int) $id) : $store->findByReference((string) $id);

		if ($job === null) {
			CLI::error('Envoi introuvable.');

			return EXIT_ERROR;
		}

		if (in_array($job['status'], JobStore::FINAL, true)) {
			CLI::write('Cet envoi est déjà terminé ('.$job['status'].').');

			return EXIT_SUCCESS;
		}

		try {
			if ($job['reference']) {
				$hub->client()->cancel((string) $job['reference']);
			}

			$store->update($job['id'], ['status' => 'cancelled', 'error' => null]);
			$job['archive_path'] && @unlink((string) $job['archive_path']);
		} catch (Throwable $e) {
			CLI::error($e->getMessage());

			return EXIT_ERROR;
		}

		CLI::write('Envoi annulé.', 'green');

		return EXIT_SUCCESS;
	}
}
