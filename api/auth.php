<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sesion.php';
require_once __DIR__ . '/clave_maestra.php';

// Freno a intentos de ingreso: 10 fallos por IP cada 15 minutos,
// y 20 por tipo de intento (clave maestra / recuperacion) para frenar ataques repartidos.
const MAX_FALLOS_IP = 10;
const MAX_FALLOS_USUARIO = 20;
const VENTANA_MINUTOS = 15;

iniciarSesionSegura();
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    if ($metodo === 'GET') {
        responderJSON(200, true, ['usuario' => obtenerUsuarioSesion()], 'Estado de sesion obtenido.');
    }

    if ($metodo !== 'POST') {
        responderJSON(405, false, null, 'Metodo no permitido.');
    }

    $datos = json_decode(file_get_contents('php://input'), true);
    if (!is_array($datos)) {
        responderJSON(400, false, null, 'El cuerpo de la peticion debe ser JSON valido.');
    }

    $accion = $datos['accion'] ?? '';
    if ($accion === 'login') {
        iniciarSesionConClave($datos);
    }
    if ($accion === 'recuperar') {
        recuperarClave($datos);
    }
    if ($accion === 'logout') {
        cerrarSesion();
    }
    responderJSON(400, false, null, 'La accion solicitada no es valida.');
} catch (PDOException $e) {
    error_log('SmartekSoft Auth DB Error: ' . $e->getMessage());
    responderJSON(500, false, null, 'Error interno del servidor.');
}

function ipCliente(): string
{
    // Caddy reemplaza X-Forwarded-For con la IP real; sin proxy se usa REMOTE_ADDR.
    $candidata = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]);
    if ($candidata !== '' && filter_var($candidata, FILTER_VALIDATE_IP)) {
        return $candidata;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function asegurarTablaIntentos(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS intentos_login (
        id BIGINT NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL,
        usuario VARCHAR(100) NOT NULL,
        creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_intentos_ip (ip, creado),
        KEY idx_intentos_usuario (usuario, creado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function demasiadosFallos(PDO $db, string $ip, string $usuario): bool
{
    $stmt = $db->prepare('SELECT
        SUM(ip = :ip) AS por_ip,
        SUM(usuario = :usuario) AS por_usuario
        FROM intentos_login WHERE creado > (NOW() - INTERVAL ' . VENTANA_MINUTOS . ' MINUTE)');
    $stmt->execute([':ip' => $ip, ':usuario' => $usuario]);
    $fila = $stmt->fetch();
    return (int) ($fila['por_ip'] ?? 0) >= MAX_FALLOS_IP || (int) ($fila['por_usuario'] ?? 0) >= MAX_FALLOS_USUARIO;
}

function registrarFallo(PDO $db, string $ip, string $usuario): void
{
    $stmt = $db->prepare('INSERT INTO intentos_login (ip, usuario) VALUES (:ip, :usuario)');
    $stmt->execute([':ip' => $ip, ':usuario' => mb_substr($usuario, 0, 100)]);
    // Limpieza ocasional de registros viejos.
    if (random_int(1, 20) === 1) {
        $db->exec('DELETE FROM intentos_login WHERE creado < (NOW() - INTERVAL 1 DAY)');
    }
}

function iniciarSesionConClave(array $datos): void
{
    $clave = (string) ($datos['clave'] ?? '');
    if ($clave === '' || strlen($clave) > 200) {
        responderJSON(400, false, null, 'Escribe la clave maestra.');
    }

    $db = obtenerConexion();
    asegurarTablaIntentos($db);
    $ip = ipCliente();

    if (demasiadosFallos($db, $ip, 'maestra')) {
        responderJSON(429, false, null, 'Demasiados intentos. Espera ' . VENTANA_MINUTOS . ' minutos e intenta de nuevo.');
    }

    $acceso = obtenerAccesoMaestro($db);
    if (!$acceso) {
        responderJSON(503, false, null, 'La clave maestra aun no esta configurada en el servidor.');
    }

    if (!password_verify($clave, $acceso['clave_hash'])) {
        registrarFallo($db, $ip, 'maestra');
        responderJSON(401, false, null, 'Clave incorrecta.');
    }

    $adminId = idAdministrador($db);
    if (!$adminId) {
        responderJSON(500, false, null, 'No hay un administrador activo para abrir la sesion.');
    }

    $stmt = $db->prepare('DELETE FROM intentos_login WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);

    guardarUsuarioSesion($adminId);
    responderJSON(200, true, obtenerUsuarioSesion(), 'Inicio de sesion realizado.');
}

// "Olvide mi clave": con el codigo de recuperacion se define una clave nueva y se entrega un codigo nuevo.
function recuperarClave(array $datos): void
{
    $codigo = normalizarCodigo((string) ($datos['codigo'] ?? ''));
    $nueva = (string) ($datos['clave_nueva'] ?? '');
    if ($codigo === '' || strlen($codigo) > 64) {
        responderJSON(400, false, null, 'Escribe tu codigo de recuperacion.');
    }
    if (mb_strlen($nueva) < LARGO_MINIMO_CLAVE || strlen($nueva) > 200) {
        responderJSON(400, false, null, 'La clave nueva debe tener al menos ' . LARGO_MINIMO_CLAVE . ' caracteres.');
    }

    $db = obtenerConexion();
    asegurarTablaIntentos($db);
    $ip = ipCliente();

    if (demasiadosFallos($db, $ip, 'recuperacion')) {
        responderJSON(429, false, null, 'Demasiados intentos. Espera ' . VENTANA_MINUTOS . ' minutos e intenta de nuevo.');
    }

    $acceso = obtenerAccesoMaestro($db);
    if (!$acceso || !password_verify($codigo, $acceso['recuperacion_hash'])) {
        registrarFallo($db, $ip, 'recuperacion');
        responderJSON(401, false, null, 'Codigo de recuperacion incorrecto.');
    }

    $adminId = idAdministrador($db);
    if (!$adminId) {
        responderJSON(500, false, null, 'No hay un administrador activo para abrir la sesion.');
    }

    $codigoNuevo = guardarClaveMaestra($db, $nueva);
    $stmt = $db->prepare('DELETE FROM intentos_login WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);

    guardarUsuarioSesion($adminId);
    responderJSON(200, true, ['usuario' => obtenerUsuarioSesion(), 'codigo_recuperacion' => $codigoNuevo], 'Clave restablecida.');
}

function cerrarSesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parametros = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $parametros['path'], $parametros['domain'], $parametros['secure'], $parametros['httponly']);
    }
    session_destroy();
    responderJSON(200, true, null, 'Sesion cerrada.');
}
