<?php

// Faux hub pour les tests de CurlHubClient (serveur intégré de PHP) : la réponse dépend de l'adresse demandée.

$id = '0b9c5a3e-6f1d-4c7e-9a52-3d8f1e2b7a64';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$json = function (int $status, array $body, bool $header = true) use ($id): void {
	http_response_code($status);
	header('Content-Type: application/json');
	$header && header('X-Request-Id: '.$id);
	echo json_encode($body);
};

switch ($path) {
	case '/api/v1/health':
		$json(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Jeton absent ou refusé.']]);
		break;
	case '/api/v1/jobs/refbody':
		$json(500, ['error' => ['code' => 'server_error', 'message' => 'Erreur interne.', 'request_id' => $id]], false);
		break;
	case '/api/v1/jobs/refpending/result':
		$json(409, ['error' => ['code' => 'run_not_ready', 'message' => 'Pas prêt.']]);
		break;
	case '/api/v1/jobs/reforbidden/result':
		$json(403, ['error' => ['code' => 'forbidden', 'message' => 'Droit manquant.']]);
		break;
	case '/api/v1/jobs/refbad':
		header('X-Request-Id: <script>alert(1)</script>');
		$json(404, ['error' => ['code' => 'not_found', 'message' => 'Introuvable.']], false);
		break;
	case '/api/v1/jobs/refok':
		$json(200, ['data' => ['status' => 'completed']]);
		break;
	default:
		$json(404, ['error' => ['code' => 'not_found', 'message' => 'Inconnu.']], false);
}
