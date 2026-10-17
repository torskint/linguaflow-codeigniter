<?php

namespace Torskint\LinguaFlowCi\Commands;

use CodeIgniter\CLI\CLI;

/**
 * Détecte les textes manquants dans les langues cibles et les envoie au hub.
 */
class SendCommand extends LinguaflowCommand
{
	protected $name = 'linguaflow:send';

	protected $description = 'Envoie au hub les textes manquants des langues cibles.';

	protected $usage = 'linguaflow:send [--locales=en,de] [--full] [--dry-run]';

	protected $options = [
		'--locales' => 'Langues cibles (par défaut : linguaflow.targetLocales).',
		'--full' => 'Envoie tout le contenu source, pas seulement les clés manquantes.',
		'--dry-run' => 'Affiche ce qui serait envoyé, sans rien envoyer.',
	];

	/**
	 * Lance l'envoi (ou son calcul seul avec --dry-run).
	 */
	public function run(array $params)
	{
		$hub = $this->hub();

		if (! $this->ready($hub)) {
			return EXIT_ERROR;
		}

		$option = CLI::getOption('locales');
		$locales = is_string($option) && $option !== '' ? array_values(array_filter(array_map('trim', explode(',', $option)))) : null;
		$full = CLI::getOption('full') !== null;
		$sender = $hub->sender();

		if ($sender->plan($locales, $full)->isEmpty() && $hub->language()->archivePaths() === []) {
			CLI::error('Aucun fichier de langue source trouvé dans '.$hub->config()->archivePrefix.'/'.$hub->config()->sourceLocale.'.');

			return EXIT_ERROR;
		}

		if (CLI::getOption('dry-run') !== null) {
			$delta = $sender->plan($locales, $full);
			CLI::write($delta->isEmpty() ? 'Rien à traduire.' : $delta->count().' texte(s) à traduire vers : '.implode(', ', $delta->locales()).'.');

			return EXIT_SUCCESS;
		}

		$sent = $sender->send($locales, $full);
		$reference = (string) ($sent['job']['reference'] ?? '');

		switch ($sent['outcome']) {
			case 'nothing':
				CLI::write('Rien à traduire.');

				return EXIT_SUCCESS;
			case 'duplicate':
				CLI::write('Un envoi identique est déjà en cours : '.$reference, 'yellow');

				return EXIT_SUCCESS;
			case 'queued':
				CLI::write('Hub injoignable, envoi mis en file : il repartira à la prochaine synchronisation ('.$sent['error'].').', 'yellow');

				return EXIT_SUCCESS;
		}

		CLI::write('Envoi créé : '.$reference.' ('.$sent['delta']->count().' texte(s), '.implode(', ', $sent['delta']->locales()).').', 'green');

		return EXIT_SUCCESS;
	}
}
