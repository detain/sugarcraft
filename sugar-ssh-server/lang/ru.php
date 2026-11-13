<?php

/**
 * Russian translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'невозможно открыть php://stderr',
    'logger.cannot_open_target'      => 'невозможно открыть цель лога: {target}',
    'logger.invalid_target'          => 'Цель логгера должна быть путём, ресурсом или null',
    'bubbletea.bad_factory'          => 'Фабрика BubbleTea должна возвращать объект с методом run(); получено: {got}',
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
    'ratelimit.exceeded'              => 'Превышен лимит запросов. Повторите попытку позже.',
    'ratelimit.unavailable_denying'   => 'Ограничитель запросов недоступен, соединение отклонено: {why}',
    'ratelimit.degraded_allowing'     => 'Ограничитель запросов деградирован, соединение разрешено (fail-open): {why}',
    'ratelimit.state_lost'            => 'Не удалось сохранить состояние ограничителя (это обновление потеряно): {why}',
    'auth.unauthorized'               => 'Не авторизовано. ({reason})',
    'passwordauth.permission_denied'  => 'Доступ запрещён.',
    'certificateauth.required'        => 'Требуется сертификат, но он не был представлен.',
    'certificateauth.rejected'        => 'Сертификат отклонён.',
    'certificateauth.env_missing'     => 'В окружении нет сертификата клиента (искали: {vars}); аутентификация по сертификату ничего не проверила.',
    'keyboardinteractive.auth_failed' => 'Ошибка аутентификации.',
    'keyboardinteractive.input_visible' => 'Этот терминал не отключён: всё, что вы здесь наберёте, будет отображено.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'Переменные окружения от пира превысили допустимый лимит и были отброшены.',
    'transport.pty_outside_loop' => 'pty() вызван вне активного цикла накачки',
    'keyboardinteractive.bad_challenge' => 'Вызов {index} должен быть строкой запроса или массивом со строковым ключом "prompt"; получено: {got}.',
    'auth.reject_user' => 'пользователь не разрешён: {name}',
    'auth.reject_key' => 'ключ не разрешён: {fingerprint}',
    'auth.reject_unpaired' => 'ключ не связан с запрошенным пользователем: {fingerprint} как {name}',
    'auth.reject_validation' => 'проверка ключа не удалась: {fingerprint}',
    'auth.allowlist_unsupported' => 'Список разрешённых содержит запись неподдерживаемого типа: {type} — пропущена, никогда не сможет совпасть',
    'middleware.async_auth_failed' => 'ошибка аутентификации: {why}',
];
