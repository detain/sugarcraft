<?php

/**
 * Czech translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'nelze otevřít php://stderr',
    'logger.cannot_open_target'      => 'nelze otevřít cíl logu: {target}',
    'logger.invalid_target'          => 'Cíl loggeru musí být cesta, prostředek nebo null',
    'bubbletea.bad_factory'          => 'BubbleTea factory musí vracet objekt s metodou run(); obdrženo: {got}',
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
    'ratelimit.exceeded'              => 'Překročen limit požadavků. Zkuste to znovu později.',
    'ratelimit.unavailable_denying'   => 'Omezovačí požadavků není dostupný, spojení je odmítnuto: {why}',
    'ratelimit.degraded_allowing'     => 'Omezovačí požadavků je degradováno, spojení je povoleno (fail-open): {why}',
    'ratelimit.state_lost'            => 'Stav omezovačí požadavků se nepodařilo uložit (tato změna je ztracena): {why}',
    'auth.unauthorized'               => 'Neoprávněno. ({reason})',
    'passwordauth.permission_denied'  => 'Oprávnění zamítnuto.',
    'certificateauth.required'        => 'Vyžadován certifikát, ale nebyl předložen.',
    'certificateauth.rejected'        => 'Certifikát zamítnut.',
    'certificateauth.env_missing'     => 'V prostředí není žádný klientský certifikát (hledáno: {vars}); ověření certifikátem nic nezkontrolovalo.',
    'keyboardinteractive.auth_failed' => 'Ověření selhalo.',
    'keyboardinteractive.input_visible' => 'Tento terminál není ztišen: vše, co zde napíšete, se zobrazí zpět.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'transport.pty_outside_loop' => 'pty() zavoláno mimo aktivní pumpovací smyčku',
    'keyboardinteractive.bad_challenge' => 'Výzva {index} musí být řetězec promptu nebo pole s řetězcovým klíčem "prompt"; získáno: {got}.',
    'auth.reject_user' => 'uživatel není povolen: {name}',
    'auth.reject_key' => 'klíč není povolen: {fingerprint}',
    'auth.reject_unpaired' => 'klíč nenále¹í požadovanému uživateli: {fingerprint} jako {name}',
    'auth.reject_validation' => 'ověření klíče selhalo: {fingerprint}',
    'middleware.async_auth_failed' => 'ověřené selhalo: {why}',
];
