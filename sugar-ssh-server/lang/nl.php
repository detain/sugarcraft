<?php

/**
 * Dutch translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'kan php://stderr niet openen',
    'logger.cannot_open_target'      => 'kan logbestemming niet openen: {target}',
    'logger.invalid_target'          => 'Loggerbestemming moet een pad, resource of null zijn',
    'bubbletea.bad_factory'          => 'BubbleTea-fabriek moet een object met run()-methode retourneren; gekregen: {got}',
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
    'ratelimit.exceeded'              => 'Snelheidslimiet overschreden. Probeer het later opnieuw.',
    'ratelimit.unavailable_denying'   => 'Snelheidsbegrenzer niet beschikbaar, verbinding geweigerd: {why}',
    'ratelimit.degraded_allowing'     => 'Snelheidsbegrenzer gedegradeerd, verbinding toegestaan (fail-open): {why}',
    'ratelimit.state_lost'            => 'Kon de staat van de snelheidsbegrenzer niet opslaan (deze update gaat verloren): {why}',
    'auth.unauthorized'               => 'Niet geautoriseerd. ({reason})',
    'passwordauth.permission_denied'  => 'Toegang geweigerd.',
    'certificateauth.required'        => 'Certificaat vereist maar niet aangeboden.',
    'certificateauth.rejected'        => 'Certificaat geweigerd.',
    'certificateauth.env_missing'     => 'Geen cliëntcertificaat in de omgeving (gezocht: {vars}); certificaatauthenticatie heeft niets gecontroleerd.',
    'keyboardinteractive.auth_failed' => 'Verificatie mislukt.',
    'keyboardinteractive.input_visible' => 'Deze terminal is niet gedempt: alles wat u hier typt wordt teruggegeven.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'Omgevingsvariabelen van de peer overschreden het geaccepteerde budget en zijn verworpen.',
    'transport.pty_outside_loop' => 'pty() aangeroepen buiten een actieve pump-loop',
    'keyboardinteractive.bad_challenge' => 'Challenge {index} moet een prompt-tekenreeks of een array met een tekenreeissleutel "prompt" zijn; gekregen: {got}.',
    'auth.reject_user' => 'gebruiker niet toegestaan: {name}',
    'auth.reject_key' => 'sleutel niet toegestaan: {fingerprint}',
    'auth.reject_unpaired' => 'sleutel niet gekoppeld aan de gevraagde gebruiker: {fingerprint} als {name}',
    'auth.reject_validation' => 'validatie van sleutel mislukt: {fingerprint}',
    'middleware.async_auth_failed' => 'authenticatie mislukt: {why}',
];
