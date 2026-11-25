<?php

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('e')) {
    function e($valor) {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

// guarda el motivo del ultimo fallo para mostrarlo o registrarlo
function correo_error($mensaje = null)
{
    static $ultimo = '';
    if ($mensaje !== null) {
        $ultimo = (string) $mensaje;
        error_log('[apac correo] ' . $ultimo);
    }
    return $ultimo;
}

// correo remitente: el configurado o, si no hay, la misma cuenta SMTP (Gmail)
function correo_remitente()
{
    if (defined('CORREO_REMITENTE') && CORREO_REMITENTE !== '') {
        return CORREO_REMITENTE;
    }
    if (defined('BREVO_REMITENTE') && BREVO_REMITENTE !== '') {
        return BREVO_REMITENTE;
    }
    if (defined('SMTP_USUARIO') && SMTP_USUARIO !== 'tucorreo@gmail.com' && filter_var(SMTP_USUARIO, FILTER_VALIDATE_EMAIL)) {
        return SMTP_USUARIO;
    }
    return '';
}

function correo_api_configurada()
{
    return defined('BREVO_API_KEY') && BREVO_API_KEY !== '';
}

function correo_smtp_configurado()
{
    if (!defined('SMTP_USUARIO') || !defined('SMTP_CLAVE') || SMTP_USUARIO === '' || SMTP_CLAVE === '') {
        return false;
    }
    // ignora los valores de ejemplo de la plantilla
    return SMTP_USUARIO !== 'tucorreo@gmail.com' && SMTP_CLAVE !== 'xxxxxxxxxxxxxxxx';
}

// envia un correo por la API HTTP de Brevo (puerto 443, recomendado para InfinityFree)
function enviar_correo_brevo($para, $nombre, $asunto, $html)
{
    if (!correo_api_configurada()) {
        correo_error('Falta brevo_api_key.');
        return false;
    }

    $remitente = correo_remitente();
    if ($remitente === '') {
        correo_error('Falta correo_remitente o smtp_usuario con un correo valido.');
        return false;
    }

    $payload = json_encode([
        'sender' => [
            'name' => defined('CORREO_NOMBRE') && CORREO_NOMBRE !== '' ? CORREO_NOMBRE : 'APAC Fresnillo',
            'email' => $remitente
        ],
        'to' => [['email' => $para, 'name' => $nombre]],
        'subject' => $asunto,
        'htmlContent' => $html
    ], JSON_UNESCAPED_UNICODE);

    $cabeceras = [
        'accept: application/json',
        'api-key: ' . BREVO_API_KEY,
        'content-type: application/json'
    ];
    $codigo = 0;
    $respuesta = false;

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20
        ]);
        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($respuesta === false) {
            correo_error('No se pudo conectar con la API de Brevo: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }
        curl_close($ch);
    } else {
        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $cabeceras) . "\r\n",
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true
        ]]);
        $respuesta = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $contexto);
        foreach (($http_response_header ?? []) as $linea) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $linea, $m)) {
                $codigo = (int) $m[1];
            }
        }
        if ($respuesta === false && $codigo === 0) {
            correo_error('No se pudo conectar con la API de Brevo (sin cURL ni allow_url_fopen).');
            return false;
        }
    }

    if ($codigo >= 200 && $codigo < 300) {
        return true;
    }

    $detalle = json_decode((string) $respuesta, true);
    $mensaje = is_array($detalle) && isset($detalle['message']) ? $detalle['message'] : substr((string) $respuesta, 0, 300);
    correo_error('Brevo API respondio ' . $codigo . ': ' . $mensaje);
    return false;
}

// envia un correo por SMTP con PHPMailer (Gmail; en InfinityFree solo funciona el puerto 587)
function enviar_correo_smtp($para, $nombre, $asunto, $html)
{
    if (!correo_smtp_configurado()) {
        correo_error('Faltan smtp_usuario o smtp_clave.');
        return false;
    }

    $remitente = correo_remitente();
    if ($remitente === '') {
        correo_error('Falta correo_remitente o smtp_usuario con un correo valido.');
        return false;
    }

    $correo = new PHPMailer(true);
    try {
        $correo->isSMTP();
        $correo->Host = SMTP_HOST;
        $correo->SMTPAuth = true;
        $correo->Username = SMTP_USUARIO;
        $correo->Password = SMTP_CLAVE;
        $correo->Port = SMTP_PUERTO;
        $correo->SMTPSecure = SMTP_PUERTO === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        // el valor por defecto (300 s) supera el tiempo maximo de PHP en hostings gratuitos
        $correo->Timeout = 10;
        // PHPMailer tiene otro limite propio de 300 s al leer respuestas; tambien se acorta
        $correo->getSMTPInstance()->Timelimit = 15;
        $correo->CharSet = 'UTF-8';
        // Gmail solo permite enviar como tu propia cuenta (o un alias verificado en Gmail)
        $correo->setFrom($remitente, CORREO_NOMBRE);
        if (strcasecmp($remitente, SMTP_USUARIO) !== 0 && filter_var(SMTP_USUARIO, FILTER_VALIDATE_EMAIL)) {
            $correo->Sender = SMTP_USUARIO;
        }
        $correo->addAddress($para, $nombre);
        $correo->isHTML(true);
        $correo->Subject = $asunto;
        $correo->Body = $html;
        $correo->AltBody = trim(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>'], "\n", $html)));
        $correo->send();
        return true;
    } catch (Throwable $error) {
        $motivo = $correo->ErrorInfo !== '' ? $correo->ErrorInfo : $error->getMessage();
        correo_error('SMTP: ' . $motivo);
        return false;
    }
}

// envia el correo por Gmail (SMTP). Si ademas hay clave de Brevo, la intenta primero.
function enviar_correo($para, $nombre, $asunto, $html)
{
    $errores = [];

    if (correo_api_configurada()) {
        if (enviar_correo_brevo($para, $nombre, $asunto, $html)) {
            return true;
        }
        $errores[] = correo_error();
    }

    if (correo_smtp_configurado()) {
        if (enviar_correo_smtp($para, $nombre, $asunto, $html)) {
            return true;
        }
        $errores[] = correo_error();
    }

    if (!$errores) {
        $errores[] = 'No hay ningun metodo de envio configurado en config.local.php.';
    }

    correo_error(implode(' | ', $errores));
    return false;
}

// traduce el error tecnico a un mensaje util para quien administra el sitio
function correo_error_amigable($tecnico)
{
    $t = strtolower($tecnico);

    if (strpos($t, 'unauthorized ip') !== false || strpos($t, 'unrecognised ip') !== false || strpos($t, 'unrecognized ip') !== false) {
        return 'Brevo bloqueo la IP del servidor. En Brevo ve a Seguridad > IPs autorizadas y desactiva el bloqueo de IPs desconocidas.';
    }
    if (strpos($t, 'sender') !== false && (strpos($t, 'not valid') !== false || strpos($t, 'invalid') !== false || strpos($t, 'not verified') !== false)) {
        return 'El correo remitente no esta verificado en Brevo. Verificalo en Brevo > Remitentes y dominios.';
    }
    if (strpos($t, 'authenticate') !== false || strpos($t, '535') !== false || strpos($t, 'username and password not accepted') !== false) {
        return 'Gmail rechazo el usuario o la contrasena. Usa una contrasena de aplicacion de Google (16 letras), no tu contrasena normal, y revisa smtp_usuario en config.local.php.';
    }
    if (strpos($t, '534') !== false || strpos($t, 'application-specific password') !== false) {
        return 'Gmail pide una contrasena de aplicacion. Activa la verificacion en dos pasos y crea una en Cuenta de Google > Seguridad > Contrasenas de aplicaciones.';
    }
    if (strpos($t, '401') !== false || strpos($t, 'key not found') !== false) {
        return 'Brevo rechazo la clave API. Revisa brevo_api_key en config.local.php o dejala vacia para usar solo Gmail.';
    }
    if (strpos($t, 'daily user sending limit') !== false || strpos($t, '550 5.4.5') !== false) {
        return 'Se alcanzo el limite diario de envios de Gmail. Intenta de nuevo manana.';
    }
    if (strpos($t, 'connect') !== false || strpos($t, 'timed out') !== false) {
        return 'No se pudo conectar con el servidor de correo. Revisa que smtp_host sea smtp.gmail.com y smtp_puerto sea 587.';
    }
    if (strpos($t, 'falta') !== false || strpos($t, 'no hay ningun metodo') !== false) {
        return 'El correo no esta configurado en el servidor. Revisa config.local.php.';
    }
    return 'El correo no pudo enviarse. Intenta de nuevo mas tarde.';
}

// agrega un marco sencillo al contenido del correo
function correo_marco($contenido)
{
    return '<div style="background:#f4f8f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#263b35">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #d3e4dc">'
        . '<div style="background:#24594f;color:#fff;padding:18px 24px;font-size:20px"><strong>apac</strong> fresnillo</div>'
        . '<div style="padding:24px;line-height:1.6;font-size:16px">' . $contenido . '</div>'
        . '<div style="background:#f4f8f6;padding:16px 24px;font-size:13px;color:#63756f">'
        . e(APAC_HORARIO) . '<br>tel. ' . e(APAC_TEL_1) . ' y ' . e(APAC_TEL_2) . '<br>' . e(APAC_DIRECCION)
        . '<br><br>estas guias son ideas de apoyo y no sustituyen la valoracion de profesionales de salud.</div>'
        . '</div></div>';
}

// envia un mensaje de bienvenida al registrar una cuenta
function correo_bienvenida($nombre)
{
    $html = '<h2 style="color:#24594f;margin-top:0">hola, ' . e($nombre) . '</h2>'
        . '<p>tu cuenta esta lista. puedes consultar las guias y registrar tus actividades.</p>'
        . '<p>elige una guia que sea adecuada para tu situacion. si algo te preocupa, pide orientacion profesional.</p>';

    return correo_marco($html);
}

// convierte las actividades fijas del catalogo a contenido de correo
function correo_actividad_html($actividad)
{
    $titulo = 'style="color:#24594f"';
    $html = '<h3 ' . $titulo . '>' . e($actividad['nombre']) . '</h3>'
        . '<p><em>' . e($actividad['duracion']) . ' | ' . e($actividad['objetivo']) . '</em></p>'
        . '<h4>materiales</h4><ul><li>' . implode('</li><li>', array_map('e', $actividad['materiales'])) . '</li></ul>'
        . '<h4>pasos</h4><ol><li>' . implode('</li><li>', array_map('e', $actividad['pasos'])) . '</li></ol>'
        . '<p><strong>mas sencillo:</strong> ' . e($actividad['mas_facil']) . '</p>';

    if ($actividad['cuidado'] !== '') {
        $html .= '<p><strong>cuidado:</strong> ' . e($actividad['cuidado']) . '</p>';
    }

    return $html;
}

// prepara el programa completo para enviarlo por correo
function correo_programa($nombre, $programa)
{
    $titulo = 'style="color:#24594f"';
    $html = '<h2 style="color:#24594f;margin-top:0">hola, ' . e($nombre) . '</h2>'
        . '<p>esta es la guia: <strong>' . e($programa['nombre']) . '</strong>.</p>'
        . '<p>' . e($programa['resumen']) . '</p>'
        . '<h3 ' . $titulo . '>materiales</h3><ul><li>' . implode('</li><li>', array_map('e', $programa['materiales'])) . '</li></ul>'
        . '<h3 ' . $titulo . '>antes de empezar</h3><ul><li>' . implode('</li><li>', array_map('e', $programa['antes_de_empezar'])) . '</li></ul>'
        . '<p><strong>detente y busca orientacion si:</strong></p><ul><li>' . implode('</li><li>', array_map('e', $programa['detente_si'])) . '</li></ul>'
        . '<p><strong>frecuencia sugerida:</strong> ' . e($programa['frecuencia']) . '</p>';

    $actividades = array_map('correo_actividad_html', array_values($programa['actividades']));
    $html .= implode('', $actividades);
    $html .= '<p>si tienes dudas, contacta a apac o al equipo profesional que acompana a tu familia.</p>';

    return correo_marco($html);
}
