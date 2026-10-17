<?php

namespace Torskint\LinguaFlowCi\Support;

use Torskint\LinguaFlowCi\Exceptions\HubException;

/**
 * Lit un fichier de langue PHP (`return [...]`) ou JSON sans jamais l'exécuter.
 * Seuls les littéraux sont acceptés : tableaux, chaînes, nombres, true/false/null.
 * Toute autre construction (variable, appel de fonction, concaténation) est refusée.
 */
class LangFileParser
{
	/** Jetons du fichier PHP, sans espaces ni commentaires. */
	private array $tokens = [];

	/** Position courante dans les jetons. */
	private int $pos = 0;

	/**
	 * Analyse un contenu selon son format (`php` ou `json`) et renvoie le tableau imbriqué.
	 */
	public function parse(string $content, string $format): array
	{
		return $format === 'json' ? $this->parseJson($content) : $this->parsePhp($content);
	}

	/**
	 * Analyse un contenu JSON objet.
	 */
	private function parseJson(string $content): array
	{
		$data = json_decode($content, true);

		if (! is_array($data)) {
			throw new HubException('JSON invalide ou non objet.');
		}

		return $data;
	}

	/**
	 * Analyse un fichier PHP de la forme `<?php return [...];` sans l'exécuter.
	 */
	private function parsePhp(string $content): array
	{
		$ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG];
		$this->tokens = array_values(array_filter(
			token_get_all($content),
			fn ($token) => ! (is_array($token) && in_array($token[0], $ignored, true))
		));
		$this->pos = 0;

		$this->skipDeclare();

		if (! $this->isToken(T_RETURN)) {
			throw new HubException('Le fichier PHP doit commencer par return.');
		}
		$this->pos++;

		$data = $this->parseValue();

		if ($this->isChar(';')) {
			$this->pos++;
		}

		if (isset($this->tokens[$this->pos]) && ! $this->isCloseTag()) {
			throw new HubException('Contenu inattendu après le tableau retourné.');
		}

		if (! is_array($data)) {
			throw new HubException('Le fichier PHP ne retourne pas un tableau.');
		}

		return $data;
	}

	/**
	 * Ignore une éventuelle instruction `declare(strict_types=1);` en tête de fichier.
	 */
	private function skipDeclare(): void
	{
		if (! $this->isToken(T_DECLARE)) {
			return;
		}

		while (isset($this->tokens[$this->pos]) && ! $this->isChar(';')) {
			$this->pos++;
		}
		$this->pos++;
	}

	/**
	 * Lit une valeur littérale : tableau, chaîne, nombre, booléen ou null.
	 */
	private function parseValue(): mixed
	{
		$token = $this->tokens[$this->pos] ?? null;

		if ($token === null) {
			throw new HubException('Fin de fichier inattendue.');
		}

		if ($token === '[') {
			$this->pos++;

			return $this->parseArray(']');
		}

		if (is_array($token) && $token[0] === T_ARRAY) {
			$this->pos++;
			if (! $this->isChar('(')) {
				throw new HubException('Syntaxe array() invalide.');
			}
			$this->pos++;

			return $this->parseArray(')');
		}

		if ($token === '-') {
			$this->pos++;
			$number = $this->parseValue();
			if (! is_int($number) && ! is_float($number)) {
				throw new HubException('Signe moins devant une valeur non numérique.');
			}

			return -$number;
		}

		if (is_array($token)) {
			$this->pos++;

			return $this->scalarFromToken($token);
		}

		throw new HubException('Construction non autorisée dans le fichier de langue.');
	}

	/**
	 * Lit les éléments d'un tableau jusqu'au caractère fermant donné.
	 */
	private function parseArray(string $close): array
	{
		$result = [];
		$nextIndex = 0;

		while (true) {
			if (! isset($this->tokens[$this->pos])) {
				throw new HubException('Tableau non fermé.');
			}

			if ($this->isChar($close)) {
				$this->pos++;

				return $result;
			}

			$first = $this->parseValue();

			if ($this->isToken(T_DOUBLE_ARROW)) {
				$this->pos++;
				if (! is_string($first) && ! is_int($first)) {
					throw new HubException('Clé de tableau non supportée.');
				}
				$result[$first] = $this->parseValue();
				if (is_int($first) && $first >= $nextIndex) {
					$nextIndex = $first + 1;
				}
			} else {
				$result[$nextIndex++] = $first;
			}

			if ($this->isChar(',')) {
				$this->pos++;
			} elseif (! $this->isChar($close)) {
				throw new HubException('Séparateur attendu dans le tableau.');
			}
		}
	}

	/**
	 * Convertit un jeton littéral en valeur PHP.
	 */
	private function scalarFromToken(array $token): mixed
	{
		[$id, $text] = $token;

		return match ($id) {
			T_CONSTANT_ENCAPSED_STRING => $this->decodeString($text),
			T_LNUMBER => (int) $text,
			T_DNUMBER => (float) $text,
			T_STRING => $this->decodeConstant($text),
			default => throw new HubException('Construction non autorisée : '.token_name($id).'.'),
		};
	}

	/**
	 * Décode un littéral de chaîne entre apostrophes ou guillemets.
	 */
	private function decodeString(string $literal): string
	{
		$quote = $literal[0];
		$body = substr($literal, 1, -1);

		if ($quote === "'") {
			return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
		}

		return preg_replace_callback(
			'/\\\\(u\{[0-9A-Fa-f]+\}|x[0-9A-Fa-f]{1,2}|[0-7]{1,3}|.)/s',
			fn ($m) => $this->decodeEscape($m[1]),
			$body
		);
	}

	/**
	 * Décode une séquence d'échappement d'une chaîne entre guillemets.
	 */
	private function decodeEscape(string $seq): string
	{
		$simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f",
			'\\' => '\\', '$' => '$', '"' => '"'];

		if (isset($simple[$seq])) {
			return $simple[$seq];
		}

		if ($seq[0] === 'u') {
			return mb_chr((int) hexdec(substr($seq, 2, -1)), 'UTF-8');
		}

		if ($seq[0] === 'x') {
			return chr((int) hexdec(substr($seq, 1)));
		}

		if (ctype_digit($seq[0])) {
			return chr(octdec($seq) & 0xFF);
		}

		return '\\'.$seq;
	}

	/**
	 * Décode les constantes littérales true, false et null.
	 */
	private function decodeConstant(string $name): mixed
	{
		return match (strtolower($name)) {
			'true' => true,
			'false' => false,
			'null' => null,
			default => throw new HubException('Constante non autorisée : '.$name.'.'),
		};
	}

	/**
	 * Teste si le jeton courant est du type donné.
	 */
	private function isToken(int $id): bool
	{
		$token = $this->tokens[$this->pos] ?? null;

		return is_array($token) && $token[0] === $id;
	}

	/**
	 * Teste si le jeton courant est le caractère donné.
	 */
	private function isChar(string $char): bool
	{
		return ($this->tokens[$this->pos] ?? null) === $char;
	}

	/**
	 * Teste si le jeton courant est une balise de fermeture PHP.
	 */
	private function isCloseTag(): bool
	{
		return $this->isToken(T_CLOSE_TAG);
	}
}
