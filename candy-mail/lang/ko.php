<?php

/**
 * Korean translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => '이메일에는少なくとも 1명의 수신자가 필요합니다 (to, cc 또는 bcc)',
    'mailer.no_from'             => '이메일에는 발신자 주소가 필요합니다',
    'smtp.send_failed'           => 'SMTP 전송 실패: {message}',
    'smtp.connect_failed'        => '{addr}에 연결할 수 없음: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'STARTTLS 협상 실패',
    'smtp.not_connected'         => '연결되지 않음',
    'smtp.no_response'           => '서버가 응답하지 않음',
    'smtp.unexpected_response'   => '예기치 않은 SMTP 응답: {response}',
    'resend.network_error'       => 'Resend 네트워크 오류: {error}',
    'resend.api_error'           => 'Resend API 오류 ({status}): {body}',
    'cli.error'                  => '오류: {message}',
    'cli.transport_error'        => '전송 오류: {message}',
    'cli.send_failed'            => '전송 실패: {message}',
    'cli.email_sent'             => '✓ 이메일이 {transport}(으)로 전송되었습니다.',
    'cli.no_to_recipient'        => '--to 수신자가 지정되지 않음',
    'cli.attachment_not_found'   => '첨부 파일을 찾을 수 없음: {file}',
    'cli.no_transport'           => '전송이 구성되지 않았습니다. RESEND_API_KEY 또는 CANDY_MAIL_SMTP_HOST 환경 변수를 설정하세요.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => '전송 시작 전에 취소됨',
    'smtp.tls_required'            => 'TLS가 필요하지만 설정할 수 없음: {host}이(가) STARTTLS를 제공하지 않음',
    'smtp.auth_insecure'           => '안전하지 않은(암호화되지 않은) SMTP 채널에서의 인증 거부',
    'smtp.empty_response'          => '서버가 빈 응답을 보냄',
    'smtp.send_cancelled'          => 'SMTP 전송 시작 전에 취소됨',
    'smtp.write_error'             => 'SMTP 서버에 쓰기 실패',
    'smtp.incomplete_write'        => 'SMTP 서버에 불완전한 쓰기',
    'email.crlf_in_address'        => '전자우편 주소에서 CRLF 삽입 감지',
    'email.invalid_address'        => '잘못된 전자우편 주소: {addr}',
    'email.crlf_in_header'         => '{field} 헤더에서 CRLF 삽입 감지',
    'resend.send_cancelled'        => 'Resend 전송 시작 전에 취소됨',
    'resend.curl_missing'          => 'Resend 전송에는 ext-curl이 필요함 (SMTP 전송은 확장 불필요)',
    'attachment.unreadable'        => '첨부 파일을 읽을 수 없음: {path}',
    'attachment.no_path'           => '파일 이름으로 첨부할 때 파일 경로를 제공해야 함',
    'attachment.invalid_cid'       => '잘못된 Content-ID 형식: {cid} (RFC 2392는 msg-id 형식 필요)',
    'cli.unknown_option'           => '알 수 없는 옵션: {option}',
    'cli.invalid_port'             => '잘못된 SMTP 포트: {port} (1~65535 사이여야 함)',
];
