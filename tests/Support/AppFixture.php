<?php

namespace Torskint\LinguaFlowCi\Tests\Support;

use Torskint\LinguaFlowCi\Archive\ArchiveBuilder;
use Torskint\LinguaFlowCi\Delta\DeltaDetector;
use Torskint\LinguaFlowCi\Merge\ResultMerger;
use Torskint\LinguaFlowCi\Source\LanguageSource;
use Torskint\LinguaFlowCi\Support\FileWriter;

/**
 * Application CodeIgniter de test dans un dossier temporaire : app/Language/fr avec deux fichiers et les objets du package câblés dessus.
 */
trait AppFixture
{
	/** Racine du dossier temporaire. */
	protected string $root;

	/** Chemin d'archive du fichier principal de la langue source. */
	protected string $file = 'app/Language/fr/App.php';

	/**
	 * Crée l'application de test et renvoie la source de langue.
	 */
	protected function makeApp(): LanguageSource
	{
		$this->root = sys_get_temp_dir().'/lfc'.bin2hex(random_bytes(4));
		mkdir($this->root.'/app/Language/fr/Admin', 0775, true);

		file_put_contents($this->root.'/app/Language/fr/App.php', <<<'PHP'
<?php

// Textes de l'application
return [
	'welcome' => [
		'title' => 'Bienvenue',
		'hello' => 'Bonjour {name}',
	],
	'logout' => 'Déconnexion',
	'version' => '1.0',
	'with.dot' => 'Clé à point',
];
PHP);
		file_put_contents($this->root.'/app/Language/fr/Admin/Users.php', "<?php\n\nreturn [\n\t'list' => 'Liste des utilisateurs',\n];\n");

		return $this->language();
	}

	/**
	 * Source de langue sur le dossier de test.
	 */
	protected function language(): LanguageSource
	{
		return new LanguageSource($this->root.'/app/Language', 'app/Language', 'fr', new FileWriter($this->root.'/backups'));
	}

	/**
	 * Détecteur de delta.
	 */
	protected function detector(LanguageSource $language): DeltaDetector
	{
		return new DeltaDetector($language, 'fr');
	}

	/**
	 * Fusionneur de résultat.
	 */
	protected function merger(LanguageSource $language): ResultMerger
	{
		return new ResultMerger($language);
	}

	/**
	 * Constructeur d'archive.
	 */
	protected function archive(LanguageSource $language): ArchiveBuilder
	{
		return new ArchiveBuilder($language);
	}

	/**
	 * Supprime le dossier de test.
	 */
	protected function cleanApp(): void
	{
		if (! isset($this->root) || ! is_dir($this->root)) {
			return;
		}

		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}

		rmdir($this->root);
	}
}
