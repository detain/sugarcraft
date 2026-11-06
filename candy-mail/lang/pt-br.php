<?php

/**
 * Brazilian Portuguese translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'mailer.no_recipient' => 'O e-mail deve ter pelo menos um destinatário (to, cc ou bcc)',
    'mailer.no_from' => 'O e-mail deve ter um endereço de remetente',
    'smtp.send_failed' => 'Envio SMTP falhou: {message}',
    'smtp.connect_failed' => 'Não é possível conectar a {addr}: {errstr} ({errno})',
    'smtp.starttls_failed' => 'A negociação STARTTLS falhou',
    'smtp.not_connected' => 'Não conectado',
    'smtp.no_response' => 'O servidor não enviou resposta',
    'smtp.unexpected_response' => 'Resposta SMTP inesperada: {response}',
    'resend.network_error' => 'Erro de rede Resend: {error}',
    'resend.api_error' => 'Erro de API Resend ({status}): {body}',
    'cli.error' => 'Erro: {message}',
    'cli.transport_error' => 'Erro de transporte: {message}',
    'cli.send_failed' => 'Envio falhou: {message}',
    'cli.email_sent' => '✓ E-mail enviado via {transport}.',
    'cli.no_to_recipient' => 'Nenhum destinatário --to especificado',
    'cli.attachment_not_found' => 'Arquivo de anexo não encontrado: {file}',
    'cli.no_transport' => 'Nenhum transporte configurado. Defina a variável de ambiente RESEND_API_KEY ou CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => 'Envio cancelado antes de começar',
    'smtp.tls_required' => 'TLS é obrigatório, mas não pôde ser estabelecido: {host} não ofereceu STARTTLS',
    'smtp.auth_insecure' => 'Autenticação recusada em um canal SMTP inseguro (sem criptografia)',
    'smtp.empty_response' => 'O servidor enviou uma resposta vazia',
    'smtp.send_cancelled' => 'Envio SMTP cancelado antes de começar',
    'smtp.write_error' => 'Falha ao escrever no servidor SMTP',
    'smtp.incomplete_write' => 'Escrita incompleta no servidor SMTP',
    'smtp.invalid_helo_host' => 'Host HELO {helo} nao e um nome de host valido (apenas letras, digitos, pontos e hifens)',
    'smtp.auth_unavailable' => 'O servidor SMTP {host} não anunciou AUTH; reencaminhar sem autenticação é recusado quando há credenciais configuradas',
    'smtp.response_too_long' => 'A resposta do servidor SMTP ultrapassou o limite de tamanho',
    'smtp.response_timeout' => 'A resposta do servidor SMTP ultrapassou o orçamento de tempo',
    'transport.not_serializable' => 'Um transporte com credenciais nao pode ser serializado nem reimportado; crie-o onde ele for usado',
    'email.crlf_in_address' => 'Injeção de CRLF detectada no endereço de e-mail',
    'email.invalid_address' => 'Endereço de e-mail inválido: {addr}',
    'email.crlf_in_header' => 'Injeção de CRLF detectada no cabeçalho {field}',
    'resend.send_cancelled' => 'Envio Resend cancelado antes de começar',
    'resend.curl_missing' => 'ext-curl é necessário para o transporte Resend (o transporte SMTP não precisa de extensões para SMTP simples; ext-openssl para TLS)',
    'resend.no_api_key' => 'O transporte Resend não tem chave de API: passe uma ao construtor ou defina RESEND_API_KEY',
    'attachment.unreadable' => 'O arquivo anexado não é legível: {path}',
    'attachment.no_path' => 'Um caminho de arquivo deve ser fornecido ao anexar por nome de arquivo',
    'attachment.invalid_cid' => 'Formato de Content-ID inválido: {cid} (a RFC 2392 exige o formato msg-id)',
    'attachment.not_a_file' => 'O caminho do anexo {path} não é um arquivo regular',
    'attachment.too_large' => 'O anexo excede o limite de {limit} bytes',
    'cli.unknown_option' => 'Opção desconhecida: {option}',
    'cli.did_you_mean' => 'Você quis dizer {suggestion}?',
    'cli.invalid_port' => 'Porta SMTP inválida: {port} (deve estar entre 1 e 65535)',
    'cli.missing_value' => 'A opção {option} requer um valor (para valores que começam com hífen use --{option}=VALOR)',
    'cli.no_sender' => 'Nenhum endereço de remetente: passe --from=ENDEREÇO ou defina CANDY_MAIL_FROM',
    'cli.invalid_env_flag' => 'A variável de ambiente {name} tem um valor de flag inválido {value}; use 1/true/yes/on ou 0/false/no/off',
    'cli.ca_file_unreadable' => 'O arquivo CA {file} não é um arquivo regular legível',
    'cli.invalid_timeout' => 'O tempo limite {timeout} não é um número de segundos entre 1 e 3600',
    'cli.render_resend_divergence' => 'Nota: --render mostra uma prévia do envelope SMTP MIME; o transporte configurado é o Resend, que envia um payload JSON. Os bytes abaixo não são o que será enviado.',
];
