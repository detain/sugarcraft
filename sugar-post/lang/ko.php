<?php

/**
 * Korean translations for sugar-post.
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
    'cli.no_transport'           => '전송이 구성되지 않았습니다. RESEND_API_KEY 또는 POP_SMTP_HOST 환경 변수를 설정하세요.',
];
