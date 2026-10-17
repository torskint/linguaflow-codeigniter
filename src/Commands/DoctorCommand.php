<?php

namespace Torskint\LinguaFlowCi\Commands;

use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Diagnostic de l'installation : réglages, fichiers de langue, dossier de travail, hub.
 */
class DoctorCommand extends LinguaflowCommand
{
	protected $name = 'linguaflow:doctor';

	protected $description = 'Vérifie les réglages, les fichiers de langue et la connexion au hub.';

	protected $usage = 'linguaflow:doctor';

	/**
	 * Déroule les contrôles et renvoie une erreur si l'un d'eux échoue.
	 */
	public function run(array $params)
	{
		$hub = $this->hub();
		$config = $hub->config();
		$ok = true;
		$check = function (bool $pass, string $label) use (&$ok): void {
			$ok = $ok && $pass;
			CLI::write(($pass ? '[ok] ' : '[!!] ').$label, $pass ? 'green' : 'red');
		};

		$check($config->hubUrl !== '' && $config->apiKey !== '', 'Adresse du hub et clé d\'API renseignées (.env)');
		$check($config->locales() !== [], 'Langues cibles renseignées (linguaflow.targetLocales)');
		$check($hub->language()->archivePaths() !== [], 'Fichiers de la langue source dans '.$config->archivePrefix.'/'.$config->sourceLocale);

		$work = $hub->workPath();
		is_dir($work) || @mkdir($work, 0775, true);
		$check(is_dir($work) && is_writable($work), 'Dossier de travail inscriptible : '.$work);

		if ($config->delivery === 'webhook') {
			$check($config->webhookSecret !== '', 'Secret du webhook renseigné (linguaflow.webhookSecret)');
			CLI::write('     Adresse du webhook à donner au hub : '.$hub->webhookUrl());
		}

		if ($config->hubUrl !== '' && $config->apiKey !== '') {
			try {
				$hub->client()->health();
				$check(true, 'Hub joignable et clé acceptée');
			} catch (Throwable $e) {
				$check(false, 'Hub : '.$e->getMessage());
			}
		}

		return $ok ? EXIT_SUCCESS : EXIT_ERROR;
	}
}
