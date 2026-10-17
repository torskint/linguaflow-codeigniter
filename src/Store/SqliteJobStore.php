<?php

namespace Torskint\LinguaFlowCi\Store;

use PDO;

/**
 * Suivi local des travaux envoyés au hub, dans une base SQLite dédiée (jamais la base de l'application).
 * Un travail = une ligne : référence du hub, empreinte, état, prochaine interrogation.
 */
class SqliteJobStore implements JobStore
{
	/** Connexion, créée au premier accès. */
	private ?PDO $pdo = null;

	// Constructeur : chemin du fichier SQLite.
	public function __construct(private string $file) {}

	/**
	 * {@inheritDoc}
	 */
	public function create(string $checksum, array $locales, int $keys, string $archivePath): int
	{
		$now = time();

		$this->run('INSERT INTO linguaflow_jobs (uuid, checksum, locales, keys_count, archive_path, status, attempts, next_poll_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)', [
			bin2hex(random_bytes(16)), $checksum, json_encode($locales), $keys, $archivePath, 'new', $now, $now, $now,
		]);

		return (int) $this->pdo()->lastInsertId();
	}

	/**
	 * {@inheritDoc}
	 */
	public function find(int $id): ?array
	{
		return $this->one('SELECT * FROM linguaflow_jobs WHERE id = ?', [$id]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function findByReference(string $reference): ?array
	{
		return $this->one('SELECT * FROM linguaflow_jobs WHERE reference = ?', [$reference]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function activeByChecksum(string $checksum): ?array
	{
		return $this->one("SELECT * FROM linguaflow_jobs WHERE checksum = ? AND status NOT IN ('merged','failed','cancelled') LIMIT 1", [$checksum]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function update(int $id, array $values): void
	{
		if (isset($values['report']) && is_array($values['report'])) {
			$values['report'] = json_encode($values['report']);
		}

		$values['updated_at'] ??= time();
		$columns = implode(', ', array_map(fn ($c) => $c.' = ?', array_keys($values)));

		$this->run('UPDATE linguaflow_jobs SET '.$columns.' WHERE id = ?', [...array_values($values), $id]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function due(?int $now = null): array
	{
		return $this->many("SELECT * FROM linguaflow_jobs WHERE status NOT IN ('merged','failed','cancelled') AND next_poll_at <= ? ORDER BY id", [$now ?? time()]);
	}

	/**
	 * {@inheritDoc}
	 */
	public function all(int $limit = 50): array
	{
		return $this->many('SELECT * FROM linguaflow_jobs ORDER BY id DESC LIMIT '.(int) $limit);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prune(int $days): int
	{
		$statement = $this->run("DELETE FROM linguaflow_jobs WHERE status IN ('merged','failed','cancelled') AND updated_at < ?", [time() - $days * 86400]);

		return $statement->rowCount();
	}

	/**
	 * Exécute une requête préparée.
	 */
	private function run(string $sql, array $params = []): \PDOStatement
	{
		$statement = $this->pdo()->prepare($sql);
		$statement->execute($params);

		return $statement;
	}

	/**
	 * Une ligne, ou null.
	 */
	private function one(string $sql, array $params): ?array
	{
		$row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $this->decode($row);
	}

	/**
	 * Toutes les lignes d'une requête.
	 *
	 * @return list<array>
	 */
	private function many(string $sql, array $params = []): array
	{
		return array_map(fn ($row) => $this->decode($row), $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * Ligne sous forme de tableau, avec les colonnes JSON décodées et les entiers typés.
	 */
	private function decode(array $row): array
	{
		foreach (['id', 'keys_count', 'attempts', 'next_poll_at', 'created_at', 'updated_at'] as $column) {
			$row[$column] = (int) $row[$column];
		}

		$row['locales'] = (array) json_decode((string) $row['locales'], true);
		$row['report'] = $row['report'] !== null ? json_decode((string) $row['report'], true) : null;

		return $row;
	}

	/**
	 * Connexion SQLite ; le dossier et la table sont créés au premier accès.
	 */
	private function pdo(): PDO
	{
		if ($this->pdo !== null) {
			return $this->pdo;
		}

		is_dir(dirname($this->file)) || @mkdir(dirname($this->file), 0775, true);

		$pdo = new PDO('sqlite:'.$this->file);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('PRAGMA busy_timeout = 5000');
		$pdo->exec('CREATE TABLE IF NOT EXISTS linguaflow_jobs (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			uuid TEXT NOT NULL UNIQUE,
			reference TEXT UNIQUE,
			checksum TEXT NOT NULL,
			locales TEXT NOT NULL,
			keys_count INTEGER NOT NULL DEFAULT 0,
			archive_path TEXT,
			status TEXT NOT NULL DEFAULT \'new\',
			hub_status TEXT,
			attempts INTEGER NOT NULL DEFAULT 0,
			next_poll_at INTEGER NOT NULL DEFAULT 0,
			sent_at INTEGER,
			merged_at INTEGER,
			error TEXT,
			report TEXT,
			created_at INTEGER NOT NULL,
			updated_at INTEGER NOT NULL
		)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS linguaflow_jobs_checksum ON linguaflow_jobs (checksum)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS linguaflow_jobs_status ON linguaflow_jobs (status)');

		return $this->pdo = $pdo;
	}
}
