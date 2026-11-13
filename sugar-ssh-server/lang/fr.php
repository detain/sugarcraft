<?php

/**
 * French translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'impossible d\'ouvrir php://stderr',
    'logger.cannot_open_target'      => 'impossible d\'ouvrir la cible du journal : {target}',
    'logger.invalid_target'          => 'La cible du logger doit être un chemin, une ressource ou null',
    'bubbletea.bad_factory'          => 'La fabrique BubbleTea doit retourner un objet avec une méthode run() ; reçu : {got}',
    'middleware.cannot_open_stream'   => 'cannot open {target}',
    'middleware.stream_not_resource'  => '{target} must be a resource',
    'bubbletea.requires_host_sshd'    => 'BubbleTea middleware only works under HostSshdTransport — InProcessTransport pumps bytes between supervisor stdio and a sugar-pty master, so mounting a Program inline collides with the pump. Either pass Server::withTransport(new HostSshdTransport()) to mount the Program on the supervisor stdio, or migrate to Spawn middleware with a wrapper script (see the BubbleTea class doc).',
    'transport.bad_stdin'             => 'InProcessTransport runChild() requires a valid stdin resource',
    'transport.bad_stdout'            => 'InProcessTransport runChild() requires a valid stdout resource',
    'spawn.no_transport'              => 'Spawn middleware requires an InProcessTransport — set Server::withTransport(new InProcessTransport()) or use BubbleTea under HostSshd',
    'spawn.bad_factory_return'        => 'Spawn factory must return an array with cmd + optional env keys; got {got}',
    'spawn.bad_cmd'                   => 'Spawn factory cmd must be a non-empty list of argv strings',
    'transport.async_timeout'         => 'Async operation timed out after {timeout} seconds',
    'keepalive.invalid_interval'      => 'Keepalive interval must be at least 1 second',
    'ratelimit.exceeded'              => 'Limite de requêtes dépassée. Réessayez plus tard.',
    'ratelimit.unavailable_denying'   => 'Limitateur de débit indisponible, connexion refusée : {why}',
    'ratelimit.degraded_allowing'     => 'Limitateur de débit dégradé, connexion autorisée (fail-open) : {why}',
    'ratelimit.state_lost'            => 'Impossible d\'enregistrer l\'état du limitateur (cette mise à jour est perdue) : {why}',
    'auth.unauthorized'               => 'Non autorisé. ({reason})',
    'passwordauth.permission_denied'  => 'Permission refusée.',
    'certificateauth.required'        => 'Certificat requis mais aucun présenté.',
    'certificateauth.rejected'        => 'Certificat refusé.',
    'certificateauth.env_missing'     => 'Aucun certificat client dans l’environnement (recherché : {vars}) ; l’authentification par certificat n’a rien vérifié.',
    'keyboardinteractive.auth_failed' => 'Échec de l’authentification.',
    'keyboardinteractive.input_visible' => 'Ce terminal n\'est pas masqué : tout ce que vous tapez ici est affiché.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'transport.pty_outside_loop' => 'pty() appelé en dehors de la boucle de pompage active',
    'keyboardinteractive.bad_challenge' => 'Le défi {index} doit être une chaîne de prompt ou un tableau avec une clé "prompt" de type chaîne ; obtenu : {got}.',
    'auth.reject_user' => 'utilisateur non autorisé : {name}',
    'auth.reject_key' => 'clé non autorisée : {fingerprint}',
    'auth.reject_unpaired' => 'clé non associée à l’utilisateur demandé : {fingerprint} comme {name}',
    'auth.reject_validation' => 'échec de la validation de la clé : {fingerprint}',
    'middleware.async_auth_failed' => 'échec d’authentification : {why}',
];
