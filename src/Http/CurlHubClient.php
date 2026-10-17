<?php

namespace Torskint\LinguaFlowCi\Http;

use CURLFile;
use Torskint\LinguaFlowCi\Archive\ArchiveBuilder;
use Torskint\LinguaFlowCi\Exceptions\HubException;

/**
 * Client HTTP du hub avec l'extension curl : délais, version du protocole, erreurs du hub en exceptions.
 * Les nouveaux essais sont gérés par la file de synchronisation (délai qui s'allonge), pas ici.
 */
class CurlHubClient implements HubClient
{
	// Constructeur : adresse du hub, clé d'API et délai d'une requête (s).
	public function __construct(private string $baseUrl, private string $apiKey, private int $timeout = 60) {}

	/**
	 * {@inheritDoc}
	 */
	public function create(string $zipPath, string $idempotencyKey): array
	{
		$response = $this->send('POST', 'jobs', [
			CURLOPT_POSTFIELDS => ['archive' => new CURLFile($zipPath, 'application/zip', 'archive.zip')],
			CURLOPT_HTTPHEADER => ['Idempotency-Key: '.$idempotencyKey],
		]);

		return ['job' => (array) ($response['json']['data'] ?? []), 'created' => $response['status'] === 201];
	}

	/**
	 * {@inheritDoc}
	 */
	public function status(string $reference): array
	{
		return (array) ($this->send('GET', 'jobs/'.rawurlencode($reference))['json']['data'] ?? []);
	}

	/**
	 * {@inheritDoc}
	 */
	public function result(string $reference): ?array
	{
		$path = tempnam(sys_get_temp_dir(), 'lfcres');
		$response = $this->send('GET', 'jobs/'.rawurlencode($reference).'/result', [], false, $path);

		if ($response['status'] >= 200 && $response['status'] < 300) {
			return ['path' => $path, 'missing' => array_values(array_filter(explode(',', (string) ($response['headers']['x-missing-languages'] ?? ''))))];
		}

		$error = json_decode((string) @file_get_contents($path), true);
		@unlink($path);

		if ($response['status'] === 409 && ($error['error']['code'] ?? null) === 'run_not_ready') {
			return null;
		}

		throw HubException::fromHub($response['status'], $error['error']['code'] ?? null, (string) ($error['error']['message'] ?? ''), $this->requestId($response['headers'], $error));
	}

	/**
	 * {@inheritDoc}
	 */
	public function ack(string $reference): void
	{
		$this->send('POST', 'jobs/'.rawurlencode($reference).'/ack');
	}

	/**
	 * {@inheritDoc}
	 */
	public function cancel(string $reference): array
	{
		return (array) ($this->send('DELETE', 'jobs/'.rawurlencode($reference))['json']['data'] ?? []);
	}

	/**
	 * {@inheritDoc}
	 */
	public function health(): array
	{
		return (array) $this->send('GET', 'health')['json'];
	}

	/**
	 * Envoie une requête et renvoie statut, en-têtes et JSON décodé. Une réponse hors 2xx devient une HubException (sauf `$check` faux).
	 * Avec `$file`, le corps est écrit dans ce fichier au lieu d'être décodé.
	 *
	 * @return array{status: int, headers: array<string, string>, json: ?array}
	 */
	private function send(string $method, string $path, array $options = [], bool $check = true, ?string $file = null): array
	{
		$headers = [];
		$handle = $file !== null ? fopen($file, 'wb') : null;
		$extra = $options[CURLOPT_HTTPHEADER] ?? [];
		unset($options[CURLOPT_HTTPHEADER]);

		$curl = curl_init(rtrim($this->baseUrl, '/').'/api/v1/'.ltrim($path, '/'));
		curl_setopt_array($curl, $options + [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => array_merge([
				'Authorization: Bearer '.$this->apiKey,
				'Accept: application/json',
				'X-LinguaFlow-Protocol: '.ArchiveBuilder::PROTOCOL,
				'User-Agent: LinguaflowCodeIgniter/1',
			], $extra),
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_RETURNTRANSFER => $handle === null,
			CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers): int {
				if (str_contains($line, ':')) {
					[$name, $value] = explode(':', $line, 2);
					$headers[strtolower(trim($name))] = trim($value);
				}

				return strlen($line);
			},
		] + ($handle !== null ? [CURLOPT_FILE => $handle] : []));

		$body = curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$error = curl_error($curl);
		curl_close($curl);
		$handle !== null && fclose($handle);

		if ($body === false) {
			throw new HubException('Hub injoignable : '.$error);
		}

		$json = $handle === null ? json_decode((string) $body, true) : null;

		if ($check && ($status < 200 || $status >= 300)) {
			throw HubException::fromHub($status, is_array($json) ? ($json['error']['code'] ?? null) : null, is_array($json) ? (string) ($json['error']['message'] ?? '') : '', $this->requestId($headers, $json));
		}

		return ['status' => $status, 'headers' => $headers, 'json' => is_array($json) ? $json : null];
	}

	/**
	 * Identifiant de la requête donné par le hub : l'en-tête X-Request-Id, ou à défaut `error.request_id` du corps.
	 *
	 * @param  array<string, string>  $headers  en-têtes en minuscules
	 */
	private function requestId(array $headers, mixed $json): ?string
	{
		$id = $headers['x-request-id'] ?? (is_array($json) ? ($json['error']['request_id'] ?? null) : null);

		return is_string($id) && $id !== '' ? $id : null;
	}
}
