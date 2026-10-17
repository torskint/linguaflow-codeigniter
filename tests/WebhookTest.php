<?php

namespace Torskint\LinguaFlowCi\Tests;

use PHPUnit\Framework\TestCase;
use Torskint\LinguaFlowCi\Webhook\WebhookVerifier;

/**
 * Webhook signé : contrôle de la signature, de la date et de la référence.
 */
class WebhookTest extends TestCase
{
	/**
	 * Un webhook signé et récent donne la référence ; signature fausse, requête ancienne, référence absente ou secret vide : refus.
	 */
	public function test_webhook_verification(): void
	{
		$secret = 'whsec_test';
		$verifier = new WebhookVerifier($secret);
		$now = 1_800_000_000;
		$sign = fn (string $body, string $key = 'whsec_test') => 'sha256='.hash_hmac('sha256', $body, $key);

		$body = json_encode(['created_at' => gmdate('c', $now), 'data' => ['reference' => 'ref12345']]);
		$ok = $verifier->check($body, $sign($body), $now);
		$this->assertTrue($ok['ok']);
		$this->assertSame('ref12345', $ok['reference']);

		$this->assertSame(401, $verifier->check($body, $sign($body, 'mauvais'), $now)['status']);

		$old = json_encode(['created_at' => gmdate('c', $now - 3600), 'data' => ['reference' => 'ref12345']]);
		$this->assertSame('stale_request', $verifier->check($old, $sign($old), $now)['error']);

		$empty = json_encode(['created_at' => gmdate('c', $now), 'data' => []]);
		$this->assertSame(422, $verifier->check($empty, $sign($empty), $now)['status']);

		$this->assertSame(503, (new WebhookVerifier(''))->check($body, $sign($body), $now)['status']);
	}
}
