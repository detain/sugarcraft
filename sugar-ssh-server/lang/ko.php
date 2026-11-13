<?php

/**
 * Korean translations for sugar-ssh-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'middleware.cannot_open_stderr' => 'php://stderr을(를) 열 수 없음',
    'logger.cannot_open_target'      => '로그 대상을 열 수 없음: {target}',
    'logger.invalid_target'          => '로거 대상은 경로, 리소스 또는 null이어야 합니다',
    'bubbletea.bad_factory'          => 'BubbleTea 팩터리는 run() 메서드가 있는 객체를 반환해야 합니다; 받음: {got}',
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
    'ratelimit.exceeded'              => '요청 제한을 초과했습니다. 나중에 다시 시도하세요.',
    'ratelimit.unavailable_denying'   => '속도 제한기를 사용할 수 없어 연결을 거부합니다: {why}',
    'ratelimit.degraded_allowing'     => '속도 제한기가 저하되었습니다. 연결을 허용합니다 (fail-open): {why}',
    'ratelimit.state_lost'            => '속도 제한기 상태를 저장할 수 없습니다 (이 업데이트는 손실됩니다): {why}',
    'auth.unauthorized'               => '권한이 없습니다. ({reason})',
    'passwordauth.permission_denied'  => '권한이 거부되었습니다.',
    'certificateauth.required'        => '증명서가 필요하지만 제시되지 않았습니다.',
    'certificateauth.rejected'        => '증명서가 거부되었습니다.',
    'certificateauth.env_missing'     => '환경에 클라이언트 인증서가 없습니다(검색함: {vars}); 인증서 인증이 무조건 검증하지 못했습니다.',
    'keyboardinteractive.auth_failed' => '인증에 실패했습니다.',
    'keyboardinteractive.input_visible' => '이 터미널은 음소거되지 않았습니다: 여기에 입력한 모든 내용이 표시됩니다.',
    'channel.exec_refused'            => 'Exec request refused: {program}',
    'channel.env_dropped' => '피어의 환경 변수가 허용 한도를 초과하여 제거되었습니다.',
    'transport.pty_outside_loop' => 'pty()가 활성 풀프 루프 외에서 호출되었습니다',
    'keyboardinteractive.bad_challenge' => '챌맹지 {index}는 프롬프트 문자열 또는 문자열 "prompt" 키가 있는 배열이어야 합니다; 받은 값: {got}.',
    'auth.reject_user' => '사용자가 허용되지 않았습니다: {name}',
    'auth.reject_key' => '키가 허용되지 않았습니다: {fingerprint}',
    'auth.reject_unpaired' => '키가 요청된 사용자와 항상이 아닙니다: {fingerprint} / {name}',
    'auth.reject_validation' => '키 검증 실패: {fingerprint}',
    'middleware.async_auth_failed' => '인증 실패: {why}',
];
