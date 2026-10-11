<?php
declare(strict_types=1);
/**
 * /api/v1/accounts and /api/v1/accounts/{id} — the records API's route for accounts (agents-k7-api.md "The API"): GET lists, GET one, POST creates, PATCH changes, as the key's person, through the screens' and actions' own code
 * (app/api/routes.php). Bearer = an API key; a lead-capture key reaches only POST /api/v1/leads.
 */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
api_route('accounts');
