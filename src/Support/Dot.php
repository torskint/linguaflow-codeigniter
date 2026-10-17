<?php

namespace Torskint\LinguaFlowCi\Support;

/**
 * Passage entre un tableau de traductions imbriqué et des clés « a.b.c », avec la même convention que le hub.
 */
class Dot
{
	/**
	 * Aplatit un tableau en clés pointées ; ne garde que les textes non vides, et signale à part les clés qui contiennent
	 * elles-mêmes un point (le hub les refuse).
	 *
	 * @return array{0: array<string, string>, 1: list<string>} [textes, clés ignorées]
	 */
	public static function flatten(array $data, string $prefix = ''): array
	{
		$texts = [];
		$skipped = [];

		foreach ($data as $key => $value) {
			$key = (string) $key;
			$path = $prefix === '' ? $key : $prefix.'.'.$key;

			if (str_contains($key, '.')) {
			    $skipped[] = $path;

			    continue;
			}

			if (is_array($value)) {
			    [$sub, $subSkipped] = self::flatten($value, $path);
			    $texts += $sub;
			    $skipped = array_merge($skipped, $subSkipped);
			} elseif (is_string($value) && trim($value) !== '' && preg_match('/\p{L}/u', $value)) {
			    $texts[$path] = $value;
			}
		}

		return [$texts, $skipped];
	}

	/**
	 * Valeur d'une clé pointée dans un tableau imbriqué, ou null.
	 */
	public static function get(array $data, string $path): mixed
	{
		$node = $data;

		foreach (explode('.', $path) as $segment) {
			if (! is_array($node) || ! array_key_exists($segment, $node)) {
			    return null;
			}

			$node = $node[$segment];
		}

		return $node;
	}

	/**
	 * Écrit une valeur à une clé pointée ; renvoie false si le chemin traverse une valeur qui n'est pas un tableau.
	 */
	public static function set(array &$data, string $path, mixed $value): bool
	{
		$segments = explode('.', $path);
		$last = array_pop($segments);
		$node = &$data;

		foreach ($segments as $segment) {
			if (! isset($node[$segment])) {
			    $node[$segment] = [];
			}

			if (! is_array($node[$segment])) {
			    return false;
			}

			$node = &$node[$segment];
		}

		$node[$last] = $value;

		return true;
	}
}
