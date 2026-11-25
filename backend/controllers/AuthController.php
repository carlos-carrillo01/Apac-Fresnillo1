<?php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../utils/Session.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../../includes/correo.php';
class AuthController {
    /**
     * Inicia sesión con correo y contraseña
     */
    public static function login($datos) {
        $correo = trim((string) ($datos['correo'] ?? ''));
        $clave = (string) ($datos['clave'] ?? '');

        if ($correo === '' || $clave === '') {
            Response::error('Por favor escribe tu correo y contraseña.');
        }

        $usuario = Usuario::buscarPorCorreo($correo);

        if (!$usuario || !Usuario::verificarClave($clave, $usuario['clave_hash'])) {
            Response::error('Correo o contraseña incorrectos.', 401);
        }

        Session::setUsuario($usuario);

        Response::exito([
            'usuario' => [
                'id' => $usuario['id'],
                'nombre' => $usuario['nombre'],
                'correo' => $usuario['correo']
            ],
            'token_csrf' => Session::tokenCsrf()
        ], 'Sesión iniciada correctamente.');
    }

    /**
     * Registra un nuevo usuario
     */
    public static function registro($datos) {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $correo = strtolower(trim((string) ($datos['correo'] ?? '')));
        $clave = (string) ($datos['clave'] ?? '');
        $confirmacion = (string) ($datos['confirmacion'] ?? '');

        if ($nombre === '' || mb_strlen($nombre) > 120) {
            Response::error('Escribe tu nombre (máximo 120 caracteres).');
        }

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 190) {
            Response::error('Escribe un correo electrónico válido.');
        }

        if (mb_strlen($clave) < 8) {
            Response::error('La contraseña debe tener al menos 8 caracteres.');
        }

        if ($clave !== $confirmacion) {
            Response::error('Las contraseñas no coinciden.');
        }

        // Validar si el correo ya existe
        if (Usuario::buscarPorCorreo($correo)) {
            Response::error('Ese correo ya tiene una cuenta registrada. Por favor inicia sesión.', 409);
        }

        try {
            $nuevoUsuario = Usuario::crear($nombre, $correo, $clave);
            Session::setUsuario($nuevoUsuario);

            $tokenCsrf = Session::tokenCsrf();
        } catch (mysqli_sql_exception $e) {
            error_log('[apac registro] ' . $e->getMessage());
            // Código 1062 = correo duplicado (ya lo comprobamos arriba, pero por seguridad)
            if ($e->getCode() === 1062) {
                Response::error('Ese correo ya tiene una cuenta registrada. Por favor inicia sesión.', 409);
            }
            Response::error('No se pudo crear la cuenta en este momento. Inténtalo más tarde.', 500, APAC_DEBUG ? $e->getMessage() : null);
        } catch (Throwable $e) {
            error_log('[apac registro] ' . $e->getMessage());
            Response::error('Error inesperado al crear la cuenta. Inténtalo más tarde.', 500, APAC_DEBUG ? $e->getMessage() : null);
        }

        // La cuenta ya existe: el correo de bienvenida nunca debe hacer fallar el registro
        self::responderYEnviarBienvenida([
            'ok'         => true,
            'mensaje'    => 'Cuenta creada exitosamente.',
            'usuario'    => $nuevoUsuario,
            'token_csrf' => $tokenCsrf
        ], $correo, $nombre);
    }

    /**
     * Responde al navegador y después envía el correo de bienvenida.
     * Si el servidor lo permite, la respuesta llega antes de que termine el envío.
     */
    private static function responderYEnviarBienvenida($respuesta, $correo, $nombre) {
        $enviarCorreo = function_exists('correo_smtp_configurado')
            && (correo_smtp_configurado() || correo_api_configurada());

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $json = json_encode($respuesta, JSON_UNESCAPED_UNICODE);
        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');

        if (!$enviarCorreo) {
            echo $json;
            exit;
        }

        // cierra la conexión con el navegador y sigue trabajando en segundo plano
        ignore_user_abort(true);
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        echo $json;
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        try {
            enviar_correo($correo, $nombre, 'Bienvenido a las guías de APAC', correo_bienvenida($nombre));
        } catch (Throwable $e) {
            error_log('[apac bienvenida] ' . $e->getMessage());
        }
        exit;
    }

    /**
     * Retorna el estado actual de la sesión del usuario
     */
    public static function estado() {
        $usuario = Session::usuario();
        $tokenCsrf = Session::tokenCsrf();

        if ($usuario) {
            Response::exito([
                'autenticado' => true,
                'usuario' => $usuario,
                'token_csrf' => $tokenCsrf
            ]);
        } else {
            Response::exito([
                'autenticado' => false,
                'usuario' => null,
                'token_csrf' => $tokenCsrf
            ]);
        }
    }

    /**
     * Cierra la sesión
     */
    public static function logout() {
        Session::cerrar();
        Response::exito([], 'Sesión finalizada.');
    }
}
