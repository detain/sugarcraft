<?php

/**
 * Spanish translations for candy-mail.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Mailer.php
    'mailer.no_recipient' => 'El correo debe tener al menos un destinatario (to, cc o bcc)',
    'mailer.no_from' => 'El correo debe tener una dirección de remitente',

    // SmtpTransport.php
    'smtp.send_failed' => 'Envío SMTP fallido: {message}',
    'smtp.connect_failed' => 'No se puede conectar a {addr}: {errstr} ({errno})',
    'smtp.starttls_failed' => 'La negociación STARTTLS falló',
    'smtp.not_connected' => 'No conectado',
    'smtp.no_response' => 'El servidor no envió respuesta',
    'smtp.unexpected_response' => 'Respuesta SMTP inesperada: {response}',

    // ResendTransport.php
    'resend.network_error' => 'Error de red Resend: {error}',
    'resend.api_error' => 'Error de API Resend ({status}): {body}',

    // bin/candy-mail
    'cli.error' => 'Error: {message}',
    'cli.transport_error' => 'Error de transporte: {message}',
    'cli.send_failed' => 'Envío fallido: {message}',
    'cli.email_sent' => '✓ Correo enviado vía {transport}.',
    'cli.no_to_recipient' => 'No se especificó destinatario --to',
    'cli.attachment_not_found' => 'Archivo adjunto no encontrado: {file}',
    'cli.no_transport' => 'Sin transporte configurado. Establezca la variable de entorno RESEND_API_KEY o CANDY_MAIL_SMTP_HOST.',

    // Locale parity with en.php (docs-fix-1023 sweep)
    'mailer.send_cancelled' => 'Envío cancelado antes de comenzar',
    'smtp.tls_required' => 'Se requiere TLS pero no se pudo establecer: {host} no ofreció STARTTLS',
    'smtp.auth_insecure' => 'Se rechaza la autenticación por un canal SMTP inseguro (sin cifrar)',
    'smtp.empty_response' => 'El servidor envió una respuesta vacía',
    'smtp.send_cancelled' => 'Envío SMTP cancelado antes de comenzar',
    'smtp.write_error' => 'Error al escribir en el servidor SMTP',
    'smtp.incomplete_write' => 'Escritura incompleta en el servidor SMTP',
    'smtp.invalid_helo_host' => 'El host HELO {helo} no es un nombre de host válido (solo letras, dígitos, puntos y guiones)',
    'smtp.auth_unavailable' => 'El servidor SMTP {host} no anunció AUTH; se rechaza retransmitir sin autenticación cuando hay credenciales configuradas',
    'smtp.response_too_long' => 'La respuesta del servidor SMTP superó el límite de tamaño',
    'smtp.response_timeout' => 'La respuesta del servidor SMTP superó el presupuesto de tiempo',
    'transport.not_serializable' => 'Un transporte con credenciales no se puede serializar ni reimportar; constrúyalo donde se use',
    'email.crlf_in_address' => 'Se detectó inyección CRLF en la dirección de correo electrónico',
    'email.invalid_address' => 'Dirección de correo electrónico no válida: {addr}',
    'email.crlf_in_header' => 'Se detectó inyección CRLF en la cabecera {field}',
    'email.invalid_mime_type' => 'Tipo MIME de adjunto no válido (se esperaba tipo/subtipo): {mime}',
    'email.invalid_header_name' => "Nombre de encabezado no válido: {name} (solo letras, dígitos y los caracteres !#$%&'*+-.^_`|~)",
    'email.reserved_header' => 'El encabezado {field} pertenece al conjunto fijo y no puede establecerse con withHeader',
    'email.invalid_header_char' => 'La cabecera {field} contiene caracteres de control no permitidos (solo texto imprimible)',
    'resend.send_cancelled' => 'Envío de Resend cancelado antes de comenzar',
    'resend.curl_missing' => 'ext-curl es necesario para el transporte de Resend (el transporte SMTP no necesita extensioñes para SMTP sin cifrar; ext-openssl para TLS)',
    'resend.no_api_key' => 'El transporte de Resend no tiene clave de API: pase una al constructor o defina RESEND_API_KEY',
    'attachment.unreadable' => 'El archivo adjunto no se puede leer: {path}',
    'attachment.no_path' => 'Se debe indicar una ruta de archivo al adjuntar por nombre de archivo',
    'attachment.invalid_cid' => 'Formato de Content-ID no válido: {cid} (permitidos: msg-id RFC 2392 user@host, prefijo cid: opcional o identificador simple)',
    'attachment.not_a_file' => 'La ruta del adjunto {path} no es un archivo regular',
    'attachment.too_large' => 'El adjunto supera el límite de {limit} bytes',
    'cli.unknown_option' => 'Opción desconocida: {option}',
    'cli.did_you_mean' => '¿Quiso decir {suggestion}?',
    'cli.invalid_port' => 'Puerto SMTP no válido: {port} (debe estar entre 1 y 65535)',
    'cli.missing_value' => 'La opción {option} requiere un valor (para valores que empiezan con guion use --{option}=VALOR)',
    'cli.no_sender' => 'Sin dirección de remitente: pase --from=DIRECCIÓN o defina CANDY_MAIL_FROM',
    'cli.invalid_env_flag' => 'La variable de entorno {name} tiene un valor de indicador no válido {value}; use 1/true/yes/on o 0/false/no/off',
    'cli.ca_file_unreadable' => 'El archivo CA {file} no es un archivo regular legible',
    'cli.invalid_timeout' => 'El tiempo de espera {timeout} no es un número de segundos entre 1 y 3600',
    'cli.render_resend_divergence' => 'Nota: --render muestra una vista previa del sobre MIME SMTP; el transporte configurado es Resend, que envía una carga JSON. Los bytes siguientes no son lo que se enviará.',
    'cli.empty_message' => 'Nada que enviar: el mensaje no contiene cuerpo, ni parte HTML, ni adjuntos. Use --body, redirija la entrada o utilice --html.',
    'cli.deprecated_env' => '{old} está obsoleto; use {new} en su lugar.',
    'cli.resend_ignored_inputs' => 'Nota: se seleccionó el transporte Resend, por lo que las entradas propias de SMTP {inputs} se ignoran (Resend mantiene su presupuesto fijo de 10 s de conexión / 30 s de transferencia).',
    'cli.invalid_transport' => 'Valor --transport desconocido: {transport} (permitidos: smtp, resend)',
    'cli.resend_key_required' => '--transport=resend necesita una clave de API: establezca RESEND_API_KEY (el host SMTP se ignora con esta opción)',
    'cli.stdin_too_large' => 'La entrada de STDIN superó el límite de {limit} bytes; reduzca la entrada o adjúntela como archivo.',
    'smtp.invalid_host' => 'El host SMTP {host} no es un nombre de host ni una dirección válidos (solo letras, dígitos, puntos y guiones)',
    'smtp.invalid_port' => 'El puerto SMTP {port} está fuera de rango (debe estar entre 1 y 65535)',
    'smtp.invalid_timeout' => 'El tiempo de espera SMTP {timeout} no es un número de segundos entre 1 y 3600',    'resend.cid_unsupported' => 'La carga útil de la API de Resend no tiene campo Content-ID: el archivo {filename} es inline; las imágenes incrustadas solo viajan por SMTP.',
];
