<?php

/**
 * Portuguese translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient'        => 'O e-mail tem de ter pelo menos um destinatário (to, cc ou bcc)',
    'mailer.no_from'             => 'O e-mail tem de ter um endereço de remetente',
    'smtp.send_failed'           => 'Envio SMTP falhou: {message}',
    'smtp.connect_failed'        => 'Não é possível conectar a {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'A negociação STARTTLS falhou',
    'smtp.not_connected'         => 'Não conectado',
    'smtp.no_response'           => 'O servidor não enviou resposta',
    'smtp.unexpected_response'   => 'Resposta SMTP inesperada: {response}',
    'resend.network_error'       => 'Erro de rede Resend: {error}',
    'resend.api_error'           => 'Erro de API Resend ({status}): {body}',
    'cli.error'                  => 'Erro: {message}',
    'cli.transport_error'        => 'Erro de transporte: {message}',
    'cli.send_failed'            => 'Envio falhou: {message}',
    'cli.email_sent'             => '✓ E-mail enviado via {transport}.',
    'cli.no_to_recipient'        => 'Nenhum destinatário --to especificado',
    'cli.attachment_not_found'   => 'Ficheiro de anexo não encontrado: {file}',
    'cli.no_transport'           => 'Nenhum transporte configurado. Defina a variável de ambiente RESEND_API_KEY ou CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'Envio cancelado antes de iniciar',
    'smtp.tls_required'            => 'TLS é obrigatório mas não foi possível estabelecê-lo: {host} não ofereceu STARTTLS',
    'smtp.auth_insecure'           => 'Autenticação recusada num canal SMTP inseguro (não cifrado)',
    'smtp.empty_response'          => 'O servidor enviou uma resposta vazia',
    'smtp.send_cancelled'          => 'Envio SMTP cancelado antes de iniciar',
    'smtp.write_error'             => 'Falha ao escrever no servidor SMTP',
    'smtp.incomplete_write'        => 'Escrita incompleta no servidor SMTP',
    'email.crlf_in_address'        => 'Injeção de CRLF detetada no endereço de correio eletrónico',
    'email.invalid_address'        => 'Endereço de correio eletrónico inválido: {addr}',
    'email.crlf_in_header'         => 'Injeção de CRLF detetada no cabeçalho {field}',
    'resend.send_cancelled'        => 'Envio Resend cancelado antes de iniciar',
    'resend.curl_missing'          => 'ext-curl é necessário para o transporte Resend (o transporte SMTP não precisa de extensões)',
    'attachment.unreadable'        => 'O ficheiro anexado não é legível: {path}',
    'attachment.no_path'           => 'Deve ser indicado um caminho de ficheiro ao anexar por nome de ficheiro',
    'attachment.invalid_cid'       => 'Formato de Content-ID inválido: {cid} (a RFC 2392 exige o formato msg-id)',
    'cli.unknown_option'           => 'Opção desconhecida: {option}',
    'cli.invalid_port'             => 'Porta SMTP inválida: {port} (deve estar entre 1 e 65535)',
];
