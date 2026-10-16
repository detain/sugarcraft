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
];
