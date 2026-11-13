<?php

/**
 * Turkish translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'php://stderr açılamadı',
    'logger.cannot_open_target'      => 'günlük hedefi açılamadı: {target}',
    'logger.invalid_target'          => 'Günlükçü hedefi bir yol, kaynak veya null olmalıdır',
    'bubbletea.bad_factory'          => 'BubbleTea fabrikası run() metodu olan bir nesne döndürmelidir; alınan: {got}',
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
    'ratelimit.exceeded'              => 'İstek sınırı aşıldı. Daha sonra tekrar deneyin.',
    'ratelimit.unavailable_denying'   => 'Hız sınırlayıcı kullanılamıyor, bağlantı reddediliyor: {why}',
    'ratelimit.degraded_allowing'     => 'Hız sınırlayıcı bozulmuş durumda, bağlantıya izin veriliyor (fail-open): {why}',
    'ratelimit.state_lost'            => 'Hız sınırlayıcı durumu kaydedilemedi (bu güncelleme kaybedildi): {why}',
    'auth.unauthorized'               => 'Yetkisiz. ({reason})',
    'passwordauth.permission_denied'  => 'İzin reddedildi.',
    'certificateauth.required'        => 'Sertifika gerekiyor ancak sunulmadı.',
    'certificateauth.rejected'        => 'Sertifika reddedildi.',
    'certificateauth.env_missing'     => 'Ortamda istemci sertifikası yok (aranan: {vars}); sertifika ile kimlik doğrulama hiçbir şey denetlemedi.',
    'keyboardinteractive.auth_failed' => 'Kimlik doğrulama başarısız.',
    'keyboardinteractive.input_visible' => 'Bu uçbirim sessize alınmadı: buraya yazdığınız her şey geri yansıtılır.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'transport.pty_outside_loop' => 'pty() etkin pompalama döngüsü dışında çağrıldı',
    'keyboardinteractive.bad_challenge' => 'Challenge {index} bir istem dizisi veya "prompt" anahtarınlı bir dizi olmalı; alınan: {got}.',
    'auth.reject_user' => 'kullanıcıya izin yok: {name}',
    'auth.reject_key' => 'anahtara izin yok: {fingerprint}',
    'auth.reject_unpaired' => 'anahtar istenen kullanıcıyla eşleşmiyor: {fingerprint} / {name}',
    'auth.reject_validation' => 'anahtar doğrulaması başarısız: {fingerprint}',
    'middleware.async_auth_failed' => 'kimlik doğrulama başarısız: {why}',
];
