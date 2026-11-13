<?php

/**
 * Brazilian Portuguese translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'não é possível abrir php://stderr',
    'logger.cannot_open_target'      => 'não é possível abrir o destino do log: {target}',
    'logger.invalid_target'          => 'O destino do logger deve ser um caminho, recurso ou null',
    'bubbletea.bad_factory'          => 'A fábrica de BubbleTea deve retornar um objeto com um método run(); obtido: {got}',
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
    'ratelimit.exceeded'              => 'Limite de requisições excedido. Tente novamente mais tarde.',
    'ratelimit.unavailable_denying'   => 'Limitador de taxa indisponível, conexão recusada: {why}',
    'ratelimit.degraded_allowing'     => 'Limitador de taxa degradado, conexão permitida (falha aberta): {why}',
    'ratelimit.state_lost'            => 'Não foi possível salvar o estado do limitador (esta atualização será perdida): {why}',
    'auth.unauthorized'               => 'Não autorizado. ({reason})',
    'passwordauth.permission_denied'  => 'Permissão negada.',
    'certificateauth.required'        => 'Certificado necessário, mas nenhum apresentado.',
    'certificateauth.rejected'        => 'Certificado rejeitado.',
    'certificateauth.env_missing'     => 'Nenhum certificado de cliente no ambiente (procurado: {vars}); a autenticação por certificado não verificou nada.',
    'keyboardinteractive.auth_failed' => 'Falha na autenticação.',
    'keyboardinteractive.input_visible' => 'Este terminal não está silenciado: tudo o que você digitar aqui será exibido.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => 'As variáveis de ambiente do par excederam o orçamento aceito e foram descartadas.',
    'transport.pty_outside_loop' => 'pty() chamado fora do ciclo de bombeamento ativo',
    'keyboardinteractive.bad_challenge' => 'O desafio {index} deve ser uma string de prompt ou um array com uma chave "prompt" de string; obtido: {got}.',
    'auth.reject_user' => 'usuário não permitido: {name}',
    'auth.reject_key' => 'chave não permitida: {fingerprint}',
    'auth.reject_unpaired' => 'chave não associada ao usuário solicitado: {fingerprint} como {name}',
    'auth.reject_validation' => 'falha na validação da chave: {fingerprint}',
    'auth.allowlist_unsupported' => 'A lista permitida contém uma entrada de um tipo não suportado: {type} — ignorada, nunca poderá corresponder',
    'middleware.async_auth_failed' => 'autenticação falhou: {why}',
];
