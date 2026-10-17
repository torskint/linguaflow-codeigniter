<?php

namespace Torskint\LinguaFlowCi\Support;

/**
 * Écrit un tableau de traductions en fichier de langue CodeIgniter : `return [...]` en syntaxe courte, chaînes entre
 * apostrophes, indentation à la tabulation (style de CodeIgniter 4).
 */
class PhpExporter
{
	/**
	 * Contenu complet du fichier.
	 */
	public function export(array $data): string
	{
		return "<?php\n\nreturn ".$this->array($data, 0).";\n";
	}

	/**
	 * Sérialise récursivement un tableau.
	 */
	private function array(array $data, int $depth): string
	{
		if ($data === []) {
			return '[]';
		}

		$indent = str_repeat("\t", $depth + 1);
		$list = array_is_list($data);
		$lines = ['['];

		foreach ($data as $key => $value) {
			$exported = is_array($value) ? $this->array($value, $depth + 1) : $this->scalar($value);
			$lines[] = $indent.($list ? '' : $this->scalar($key).' => ').$exported.',';
		}

		$lines[] = str_repeat("\t", $depth).']';

		return implode("\n", $lines);
	}

	/**
	 * Sérialise une valeur scalaire en conservant son type.
	 */
	private function scalar(mixed $value): string
	{
		return match (true) {
			is_null($value) => 'null',
			is_bool($value) => $value ? 'true' : 'false',
			is_int($value) => (string) $value,
			is_float($value) => var_export($value, true),
			default => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'",
		};
	}
}
