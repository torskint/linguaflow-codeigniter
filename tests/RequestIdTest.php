<?php

namespace Torskint\LinguaFlowCi\Tests;

use PHPUnit\Framework\TestCase;
use Torskint\LinguaFlowCi\Exceptions\HubException;
use Torskint\LinguaFlowCi\Http\CurlHubClient;

/**
 * Identifiant de requête (X-Request-Id) : il suit l'erreur du hub jusqu'au message affiché. Le vrai client curl parle à un faux hub local.
 */
class RequestIdTest extends TestCase
{
	/** Identifiant donné par le faux hub. */
	private const ID = '0b9c5a3e-6f1d-4c7e-9a52-3d8f1e2b7a64';

	/** Processus du serveur de test. @var resource|null */
	private static $server = null;

	/** Adresse du serveur de test. */
	private static string $url = '';

	/**
	 * Démarre le faux hub sur un port libre et attend qu'il réponde.
	 */
	public static function setUpBeforeClass(): void
	{
		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
		fclose($probe);

		self::$url = 'http://127.0.0.1:'.$port;
		self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/Support/hub-router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

		for ($i = 0; $i < 50; $i++) {
			if (@fsockopen('127.0.0.1', $port)) {
				return;
			}

			usleep(100000);
		}

		self::fail('Le faux hub ne démarre pas.');
	}

	/**
	 * Arrête le faux hub.
	 */
	public static function tearDownAfterClass(): void
	{
		if (is_resource(self::$server)) {
			proc_terminate(self::$server);
			proc_close(self::$server);
		}
	}

	/**
	 * Client sur le faux hub.
	 */
	private function client(): CurlHubClient
	{
		return new CurlHubClient(self::$url, 'jeton', 5);
	}

	/**
	 * L'identifiant de l'en-tête termine le message et reste disponible sur l'exception.
	 */
	public function test_request_id_from_header_is_in_the_message(): void
	{
		try {
			$this->client()->health();
			$this->fail('Une réponse 401 doit lever une exception.');
		} catch (HubException $e) {
			$this->assertSame(401, $e->status);
			$this->assertSame('unauthenticated', $e->errorCode);
			$this->assertSame(self::ID, $e->requestId);
			$this->assertSame('Jeton absent ou refusé. [X-Request-Id : '.self::ID.']', $e->getMessage());
		}
	}

	/**
	 * Sans en-tête, l'identifiant du corps JSON est utilisé.
	 */
	public function test_request_id_falls_back_to_the_body(): void
	{
		try {
			$this->client()->status('refbody');
			$this->fail();
		} catch (HubException $e) {
			$this->assertSame(self::ID, $e->requestId);
			$this->assertStringEndsWith('[X-Request-Id : '.self::ID.']', $e->getMessage());
		}
	}

	/**
	 * Le téléchargement du résultat porte aussi l'identifiant ; « pas prêt » (409 run_not_ready) reste un simple null.
	 */
	public function test_result_errors_carry_the_id_and_not_ready_is_null(): void
	{
		$this->assertNull($this->client()->result('refpending'));

		try {
			$this->client()->result('reforbidden');
			$this->fail();
		} catch (HubException $e) {
			$this->assertSame(403, $e->status);
			$this->assertSame(self::ID, $e->requestId);
		}
	}

	/**
	 * Un identifiant douteux est ignoré ; une réponse réussie ne lève rien ; une panne réseau n'a pas d'identifiant.
	 */
	public function test_doubtful_id_is_ignored_success_passes_and_network_failure_has_no_id(): void
	{
		try {
			$this->client()->status('refbad');
			$this->fail();
		} catch (HubException $e) {
			$this->assertNull($e->requestId);
			$this->assertSame('Introuvable.', $e->getMessage());
		}

		$this->assertSame(['status' => 'completed'], $this->client()->status('refok'));

		try {
			(new CurlHubClient('http://127.0.0.1:1', 'jeton', 2))->health();
			$this->fail();
		} catch (HubException $e) {
			$this->assertNull($e->requestId);
			$this->assertStringContainsString('Hub injoignable', $e->getMessage());
		}
	}

	/**
	 * Un message vide du hub est remplacé par un texte par défaut, avec l'identifiant.
	 */
	public function test_empty_hub_message_gets_a_default_text(): void
	{
		$this->assertSame('Réponse inattendue du hub (502). [X-Request-Id : '.self::ID.']', HubException::fromHub(502, null, '', self::ID)->getMessage());
		$this->assertSame('Erreur.', HubException::fromHub(500, null, 'Erreur.')->getMessage());
	}
}
