<?php

require_once __DIR__ . '/../models/Catalogo.php';
require_once __DIR__ . '/../utils/Session.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../../includes/correo.php';

class CorreoController {
    /**
     * Envía la guía completa del programa por correo al usuario en sesión
     */
    public static function enviarPrograma($slugPrograma) {
        $usuario = Session::exigirAcceso();
        Session::validarCsrf();

        $slug = trim((string) $slugPrograma);

        if ($slug === '') {
            Response::error('No se especificó el programa a enviar.', 400);
        }

        $programa = Catalogo::buscarPrograma($slug);

        if (!$programa) {
            Response::error('No encontramos ese programa en el catálogo.', 404);
        }

        // Verificar que haya al menos un método de envío y un remitente
        if ((!correo_api_configurada() && !correo_smtp_configurado()) || correo_remitente() === '') {
            $detalle = correo_remitente() === ''
                ? 'Falta smtp_usuario (tu correo de Gmail) en config.local.php.'
                : 'Faltan smtp_usuario y smtp_clave (contraseña de aplicación de Gmail) en config.local.php.';
            error_log('[apac correo] ' . $detalle);
            Response::error(
                'El correo no está configurado en el servidor. Contacta al administrador para habilitar el envío de guías por email.',
                503,
                APAC_DEBUG ? $detalle : null
            );
        }

        try {
            $asunto    = 'Tu guía para practicar en casa: ' . $programa['nombre'];
            $contenido = correo_programa($usuario['nombre'], $programa);
            $enviado   = enviar_correo($usuario['correo'], $usuario['nombre'], $asunto, $contenido);
        } catch (Throwable $e) {
            error_log('[apac correo] ' . $e->getMessage());
            Response::error('Ocurrió un error al preparar el correo.', 500, APAC_DEBUG ? $e->getMessage() : null);
        }

        if ($enviado) {
            Response::exito([], 'Enviamos el programa a ' . $usuario['correo'] . '. Revisa tu bandeja de entrada (y la carpeta de spam).');
        }

        $tecnico = correo_error();
        Response::error(correo_error_amigable($tecnico), 502, APAC_DEBUG ? $tecnico : null);
    }
}
