<?php

// Route du webhook du hub (découverte automatiquement par CodeIgniter 4 pour un paquet Composer).
// Si la découverte des routes est désactivée, ajoutez la même ligne dans app/Config/Routes.php.

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->post('linguaflow/webhook', '\Torskint\LinguaFlowCi\Controllers\WebhookController::handle');
