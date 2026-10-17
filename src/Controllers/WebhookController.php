<?php

namespace Torskint\LinguaFlowCi\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
use Torskint\LinguaFlowCi\Hub;

/**
 * Reçoit les webhooks du hub (mode « webhook ») : signature HMAC-SHA256 et date vérifiées, puis récupération du résultat
 * après l'envoi de la réponse. Le corps ne porte que la référence ; en cas d'échec, `spark linguaflow:sync` reprend l'envoi.
 */
class WebhookController extends Controller
{
	/**
	 * Valide la requête, répond 202 et traite l'envoi annoncé en fin de requête.
	 */
	public function handle(): ResponseInterface
	{
		$hub = Hub::make();
		$check = $hub->verifier()->check((string) $this->request->getBody(), (string) $this->request->getHeaderLine('X-Hub-Signature'));

		if (! $check['ok']) {
			return $this->response->setStatusCode($check['status'])->setJSON(['error' => $check['error']]);
		}

		$reference = (string) $check['reference'];

		register_shutdown_function(static function () use ($hub, $reference): void {
			// La réponse part avant le traitement (PHP-FPM) ; ailleurs, le traitement a lieu à la fin de la requête
			function_exists('fastcgi_finish_request') && fastcgi_finish_request();

			try {
				$hub->syncer()->run($reference);
			} catch (Throwable $e) {
				log_message('error', '[linguaflow] '.$e->getMessage());
			}
		});

		return $this->response->setStatusCode(202)->setJSON(['accepted' => true]);
	}
}
