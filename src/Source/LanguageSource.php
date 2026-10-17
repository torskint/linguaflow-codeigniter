<?php

namespace Torskint\LinguaFlowCi\Source;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use Torskint\LinguaFlowCi\Exceptions\HubException;
use Torskint\LinguaFlowCi\Merge\MergeReport;
use Torskint\LinguaFlowCi\Support\Dot;
use Torskint\LinguaFlowCi\Support\FileWriter;
use Torskint\LinguaFlowCi\Support\LangFileParser;
use Torskint\LinguaFlowCi\Support\Placeholders;
use Torskint\LinguaFlowCi\Support\PhpExporter;

/**
 * Fichiers de langue de CodeIgniter 4 : `app/Language/{langue}/**.php` (tableaux imbriqués, lus sans être exécutés).
 * Un fichier est identifié par son chemin dans l'archive, par exemple « app/Language/fr/App.php » ; le fichier d'une langue
 * cible est le même chemin avec la langue source remplacée par la cible. Clé = chemin pointé (« welcome.title »).
 */
class LanguageSource
{
	/** Forme d'un code de langue accepté pour écrire un fichier (évite toute sortie du dossier de langue). */
	private const LOCALE = '/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,2}$/';

	/** Clés ignorées lors de la dernière lecture de la langue source. @var list<string> */
	private array $skipped = [];

	/**
	 * @param  string  $langPath  dossier des langues de l'application (APPPATH.'Language')
	 * @param  string  $prefix  chemin de ce dossier dans l'archive (« app/Language »)
	 */
	public function __construct(
		private string $langPath,
		private string $prefix,
		private string $source,
		private FileWriter $writer,
		private LangFileParser $parser = new LangFileParser,
		private PhpExporter $exporter = new PhpExporter,
	) {}

	/**
	 * Chemins d'archive des fichiers de la langue source.
	 *
	 * @return list<string>
	 */
	public function archivePaths(): array
	{
		$dir = $this->dir($this->source);

		if (! is_dir($dir)) {
			return [];
		}

		$files = [];

		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
			if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
				$files[] = $this->prefix.'/'.$this->source.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
			}
		}

		sort($files);

		return $files;
	}

	/**
	 * Vrai si le chemin d'archive désigne un fichier de la langue source.
	 */
	public function knows(string $archivePath): bool
	{
		return in_array($archivePath, $this->archivePaths(), true);
	}

	/**
	 * Clés de la langue source que le hub ne sait pas traiter (clé qui contient un point), vues par entries().
	 *
	 * @return list<string>
	 */
	public function skipped(): array
	{
		return array_values(array_unique($this->skipped));
	}

	/**
	 * Textes d'un fichier pour une langue : clé pointée => texte (vide si le fichier de la langue n'existe pas encore).
	 *
	 * @return array<string, string>
	 */
	public function entries(string $archivePath, string $locale): array
	{
		$path = $this->path($archivePath, $locale);

		if ($path === null || ! is_file($path)) {
			return [];
		}

		try {
			[$texts, $skipped] = Dot::flatten($this->read($path));
		} catch (Throwable) {
			return [];
		}

		if ($locale === $this->source) {
			$this->skipped = array_merge($this->skipped, array_map(fn ($k) => $archivePath.' : '.$k, $skipped));
		}

		return $texts;
	}

	/**
	 * Contenu du fichier source réduit aux clés demandées, prêt à être joint à l'archive.
	 *
	 * @param  list<string>  $keys
	 */
	public function export(string $archivePath, array $keys): string
	{
		[$texts] = Dot::flatten($this->read((string) $this->path($archivePath, $this->source)));
		$tree = [];

		foreach ($keys as $key) {
			if (isset($texts[$key])) {
				Dot::set($tree, $key, $texts[$key]);
			}
		}

		return $this->exporter->export($tree);
	}

	/**
	 * Fusionne des traductions dans le fichier de la langue cible, sans jamais écraser une valeur non vide.
	 *
	 * @param  array<string, string>  $texts  clé pointée => traduction
	 */
	public function merge(string $archivePath, string $locale, array $texts): MergeReport
	{
		$report = new MergeReport;
		$path = $this->path($archivePath, $locale);
		$sourcePath = $this->path($archivePath, $this->source);

		if ($path === null || $sourcePath === null || ! $this->knows($archivePath)) {
			$report->errors[] = 'Fichier ou langue refusés : '.$archivePath.' / '.$locale;

			return $report;
		}

		try {
			[$sourceTexts] = Dot::flatten($this->read($sourcePath));
			$existing = is_file($path) ? $this->read($path) : [];
		} catch (Throwable $e) {
			$report->errors[] = $path.' : '.$e->getMessage();

			return $report;
		}

		foreach ($texts as $key => $text) {
			$key = (string) $key;
			$text = (string) $text;
			$current = Dot::get($existing, $key);

			if (! isset($sourceTexts[$key])) {
				$report->rejected[] = [$path, $key, 'clé inconnue de la source'];
			} elseif (is_string($current) && trim($current) !== '') {
				$report->kept++;
			} elseif (is_array($current)) {
				$report->rejected[] = [$path, $key, 'la clé est un groupe dans le fichier cible'];
			} elseif (trim($text) === '') {
				$report->rejected[] = [$path, $key, 'traduction vide'];
			} elseif (! Placeholders::same($sourceTexts[$key], $text)) {
				$report->rejected[] = [$path, $key, 'marqueurs différents de la source'];
			} elseif (! Dot::set($existing, $key, $text)) {
				$report->rejected[] = [$path, $key, 'conflit avec une valeur existante'];
			} else {
				$report->merged++;
			}
		}

		if ($report->merged > 0) {
			try {
				$this->writer->write($path, $this->exporter->export($existing));
				$report->written[] = $path;
			} catch (HubException $e) {
				$report->errors[] = $e->getMessage();
				$report->merged = 0;
			}
		}

		return $report;
	}

	/**
	 * Dossier absolu d'une langue.
	 */
	private function dir(string $locale): string
	{
		return rtrim($this->langPath, '/\\').DIRECTORY_SEPARATOR.$locale;
	}

	/**
	 * Chemin absolu du fichier d'une langue, ou null si le chemin d'archive n'est pas sous la langue source ou si la langue est refusée.
	 */
	private function path(string $archivePath, string $locale): ?string
	{
		$start = $this->prefix.'/'.$this->source.'/';

		if (preg_match(self::LOCALE, $locale) !== 1 || ! str_starts_with($archivePath, $start)) {
			return null;
		}

		$relative = substr($archivePath, strlen($start));

		if ($relative === '' || in_array('..', explode('/', $relative), true) || str_contains($relative, "\0") || str_contains($relative, '\\')) {
			return null;
		}

		return $this->dir($locale).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
	}

	/**
	 * Lit un fichier de langue sans l'exécuter.
	 */
	private function read(string $path): array
	{
		if (! is_file($path)) {
			throw new HubException('Fichier absent : '.$path);
		}

		return $this->parser->parse((string) file_get_contents($path), 'php');
	}
}
