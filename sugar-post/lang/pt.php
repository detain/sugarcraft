<?php

/**
 * Portuguese translations for sugar-post.
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
    'cli.no_transport'           => 'Nenhum transporte configurado. Defina a variável de ambiente RESEND_API_KEY ou POP_SMTP_HOST.',
];
