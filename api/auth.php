<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sesion.php';

// Freno a intentos de ingreso (igual que el curso de bases de datos):
// 10 fallos por IP cada 15 minutos, y 20 por usuario para frenar ataques repartidos.
const MAX_FALLOS_IP = 10;
const MAX_FALLOS_USUARIO = 20;
const VENTANA_MINUTOS = 15;

// Hash bcrypt bien formado que no corresponde a ninguna contraseña: se verifica
// cuando el usuario no existe para que la respuesta tarde lo mismo.
const HASH_FALSO = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

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
    $usuario = trim((string) ($datos['usuario'] ?? ''));
    $clave = (string) ($datos['contrasena'] ?? '');
    if ($usuario === '' || $clave === '' || mb_strlen($usuario) > 255 || strlen($clave) > 200) {
        responderJSON(400, false, null, 'Escribe tu usuario y tu contraseña.');
    }

    $db = obtenerConexion();
    asegurarTablaIntentos($db);
    $ip = ipCliente();

    if (demasiadosFallos($db, $ip, mb_strtolower($usuario))) {
        responderJSON(429, false, null, 'Demasiados intentos. Espera ' . VENTANA_MINUTOS . ' minutos e intenta de nuevo.');
    }

    $stmt = $db->prepare('SELECT id, contrasena_hash FROM usuarios WHERE (usuario = :u OR correo = :u) AND activo = 1 LIMIT 1');
    $stmt->execute([':u' => $usuario]);
    $fila = $stmt->fetch();

    $hash = ($fila && is_string($fila['contrasena_hash']) && $fila['contrasena_hash'] !== '') ? $fila['contrasena_hash'] : HASH_FALSO;
    $valida = password_verify($clave, $hash);

    if (!$fila || $hash === HASH_FALSO || !$valida) {
        registrarFallo($db, $ip, mb_strtolower($usuario));
        responderJSON(401, false, null, 'Usuario o contraseña incorrectos.');
    }

    $stmt = $db->prepare('DELETE FROM intentos_login WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);

    if (password_needs_rehash($fila['contrasena_hash'], PASSWORD_DEFAULT)) {
        $stmt = $db->prepare('UPDATE usuarios SET contrasena_hash = :h WHERE id = :id');
        $stmt->execute([':h' => password_hash($clave, PASSWORD_DEFAULT), ':id' => $fila['id']]);
    }

    guardarUsuarioSesion((int) $fila['id']);
    responderJSON(200, true, obtenerUsuarioSesion(), 'Inicio de sesion realizado.');
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
