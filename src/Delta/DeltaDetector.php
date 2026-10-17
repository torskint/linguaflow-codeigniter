<?php

namespace Torskint\LinguaFlowCi\Delta;

use Torskint\LinguaFlowCi\Source\LanguageSource;

/**
 * Compare la langue source à chaque langue cible et relève les clés manquantes (absentes ou vides).
 */
class DeltaDetector
{
	// Constructeur : fichiers de langue de l'application et langue source.
	public function __construct(private LanguageSource $language, private string $source) {}

	/**
	 * Clés à traduire pour les langues données ; `$full` demande tout le contenu source quelle que soit la cible.
	 *
	 * @param  list<string>  $locales
	 */
	public function detect(array $locales, bool $full = false): Delta
	{
		$delta = new Delta;

		foreach (array_values(array_diff($locales, [$this->source])) as $locale) {
			foreach ($this->language->archivePaths() as $file) {
				$source = $this->language->entries($file, $this->source);
				$target = $this->language->entries($file, $locale);
				$missing = [];

				foreach ($source as $key => $text) {
					if ($full || trim((string) ($target[$key] ?? '')) === '') {
						$missing[] = (string) $key;
					}
				}

				$delta->add($file, $locale, $missing);
			}
		}

		return $delta;
	}
}
