<?php

/**
 * Spanish translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Mailer.php
    'mailer.no_recipient'        => 'El correo debe tener al menos un destinatario (to, cc o bcc)',
    'mailer.no_from'             => 'El correo debe tener una dirección de remitente',

    // SmtpTransport.php
    'smtp.send_failed'           => 'Envío SMTP fallido: {message}',
    'smtp.connect_failed'        => 'No se puede conectar a {addr}: {errstr} ({errno})',
    'smtp.starttls_failed'       => 'La negociación STARTTLS falló',
    'smtp.not_connected'         => 'No conectado',
    'smtp.no_response'           => 'El servidor no envió respuesta',
    'smtp.unexpected_response'   => 'Respuesta SMTP inesperada: {response}',

    // ResendTransport.php
    'resend.network_error'       => 'Error de red Resend: {error}',
    'resend.api_error'           => 'Error de API Resend ({status}): {body}',

    // bin/candy-mail
    'cli.error'                  => 'Error: {message}',
    'cli.transport_error'        => 'Error de transporte: {message}',
    'cli.send_failed'            => 'Envío fallido: {message}',
    'cli.email_sent'             => '✓ Correo enviado vía {transport}.',
    'cli.no_to_recipient'        => 'No se especificó destinatario --to',
    'cli.attachment_not_found'   => 'Archivo adjunto no encontrado: {file}',
    'cli.no_transport'           => 'Sin transporte configurado. Establezca la variable de entorno RESEND_API_KEY o CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled'        => 'Envío cancelado antes de comenzar',
    'smtp.tls_required'            => 'Se requiere TLS pero no se pudo establecer: {host} no ofreció STARTTLS',
    'smtp.auth_insecure'           => 'Se rechaza la autenticación por un canal SMTP inseguro (sin cifrar)',
    'smtp.empty_response'          => 'El servidor envió una respuesta vacía',
    'smtp.send_cancelled'          => 'Envío SMTP cancelado antes de comenzar',
    'smtp.write_error'             => 'Error al escribir en el servidor SMTP',
    'smtp.incomplete_write'        => 'Escritura incompleta en el servidor SMTP',
    'email.crlf_in_address'        => 'Se detectó inyección CRLF en la dirección de correo electrónico',
    'email.invalid_address'        => 'Dirección de correo electrónico no válida: {addr}',
    'email.crlf_in_header'         => 'Se detectó inyección CRLF en la cabecera {field}',
    'resend.send_cancelled'        => 'Envío de Resend cancelado antes de comenzar',
    'resend.curl_missing'          => 'ext-curl es necesario para el transporte de Resend (el transporte SMTP no necesita extensiones)',
    'attachment.unreadable'        => 'El archivo adjunto no se puede leer: {path}',
    'attachment.no_path'           => 'Se debe indicar una ruta de archivo al adjuntar por nombre de archivo',
    'attachment.invalid_cid'       => 'Formato de Content-ID no válido: {cid} (RFC 2392 exige el formato msg-id)',
    'cli.unknown_option'           => 'Opción desconocida: {option}',
    'cli.invalid_port'             => 'Puerto SMTP no válido: {port} (debe estar entre 1 y 65535)',
];
