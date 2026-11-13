<?php

/**
 * Polish translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'nie można otworzyć php://stderr',
    'logger.cannot_open_target'      => 'nie można otworzyć celu logowania: {target}',
    'logger.invalid_target'          => 'Cel loggera musi być ścieżką, zasobem lub null',
    'bubbletea.bad_factory'          => 'Fabryka BubbleTea musi zwracać obiekt z metodą run(); otrzymano: {got}',
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
    'ratelimit.exceeded'              => 'Przekroczono limit żądań. Spróbuj ponownie później.',
    'ratelimit.unavailable_denying'   => 'Ogranicznik żądań niedostępny, połączenie odrzucone: {why}',
    'ratelimit.degraded_allowing'     => 'Ogranicznik żądań w stanie degradacji, połączenie dozwolone (fail-open): {why}',
    'ratelimit.state_lost'            => 'Nie udało się zapisać stanu ogranicznika (ta aktualizacja jest utracona): {why}',
    'auth.unauthorized'               => 'Brak autoryzacji. ({reason})',
    'passwordauth.permission_denied'  => 'Odmowa dostępu.',
    'certificateauth.required'        => 'Wymagany certyfikat, ale nie został przedstawiony.',
    'certificateauth.rejected'        => 'Certyfikat odrzucony.',
    'certificateauth.env_missing'     => 'Brak certyfikatu klienta w środowisku (szukano: {vars}); uwierzytelnianie certyfikatem niczego nie zweryfikowało.',
    'keyboardinteractive.auth_failed' => 'Uwierzytelnianie nie powiodło się.',
    'keyboardinteractive.input_visible' => 'Ten terminal nie jest wyciszony: wszystko, co tutaj wpiszesz, zostanie wyświetlone.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'Zmienne środowiska od partnera przekroczyły akceptowany limit i zostały odrzucone.',
    'transport.pty_outside_loop' => 'pty() wywołane poza aktywną pętlą pompy',
    'keyboardinteractive.bad_challenge' => 'Wyzwanie {index} musi być ciągiem promptu lub tablicą z kluczem "prompt" typu string; otrzymano: {got}.',
    'auth.reject_user' => 'użytkownik niedozwolony: {name}',
    'auth.reject_key' => 'klucz niedozwolony: {fingerprint}',
    'auth.reject_unpaired' => 'klucz nie jest sparowany żadanym użytkownikiem: {fingerprint} jako {name}',
    'auth.reject_validation' => 'walidacja klucza nie powiodła się: {fingerprint}',
    'middleware.async_auth_failed' => 'uwierzytelnianie nie powiodło się: {why}',
];
