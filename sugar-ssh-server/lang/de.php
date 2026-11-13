<?php

/**
 * German translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'php://stderr konnte nicht geöffnet werden',
    'logger.cannot_open_target'      => 'Log-Ziel konnte nicht geöffnet werden: {target}',
    'logger.invalid_target'          => 'Logger-Ziel muss Pfad, Ressource oder null sein',
    'bubbletea.bad_factory'          => 'BubbleTea-Fabrik muss ein Objekt mit einer run()-Methode zurückgeben; erhalten: {got}',
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
    'ratelimit.exceeded'              => 'Ratenlimit überschritten. Versuchen Sie es später erneut.',
    'ratelimit.unavailable_denying'   => 'Ratenbegrenzer nicht verfügbar, Verbindung wird abgelehnt: {why}',
    'ratelimit.degraded_allowing'     => 'Ratenbegrenzer degradiert, Verbindung wird zugelassen (fail-open): {why}',
    'ratelimit.state_lost'            => 'Zustand des Ratenbegrenzers konnte nicht gespeichert werden (diese Aktualisierung ist verloren): {why}',
    'auth.unauthorized'               => 'Nicht autorisiert. ({reason})',
    'passwordauth.permission_denied'  => 'Zugriff verweigert.',
    'certificateauth.required'        => 'Zertifikat erforderlich, aber keines vorgelegt.',
    'certificateauth.rejected'        => 'Zertifikat abgelehnt.',
    'certificateauth.env_missing'     => 'Kein Client-Zertifikat in der Umgebung (gesucht: {vars}); die Zertifikatsprüfung hat nichts kontrolliert.',
    'keyboardinteractive.auth_failed' => 'Authentifizierung fehlgeschlagen.',
    'keyboardinteractive.input_visible' => 'Dieses Terminal ist nicht stummgeschaltet: Alles, was Sie hier eingeben, wird zurückgegeben.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'Umgebungsvariablen vom Peer haben das akzeptierte Budget überschritten und wurden verworfen.',
    'transport.pty_outside_loop' => 'pty() wurde außerhalb einer aktiven Pump-Schleife aufgerufen',
    'keyboardinteractive.bad_challenge' => 'Challenge {index} muss eine Prompt-Zeichenkette oder ein Array mit einem Zeichenketten-Schlüssel "prompt" sein; erhalten: {got}.',
    'auth.reject_user' => 'Benutzer nicht erlaubt: {name}',
    'auth.reject_key' => 'Schlüssel nicht erlaubt: {fingerprint}',
    'auth.reject_unpaired' => 'Schlüssel nicht dem angeforderten Benutzer zugeordnet: {fingerprint} als {name}',
    'auth.reject_validation' => 'Schlüsselüberprüfung fehlgeschlagen: {fingerprint}',
    'middleware.async_auth_failed' => 'Authentifizierung fehlgeschlagen: {why}',
];
