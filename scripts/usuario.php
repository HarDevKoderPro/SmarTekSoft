<?php

// Crea un usuario o cambia su contraseña. La contraseña se pide por teclado (no se ve ni queda en el historial).
//   docker compose exec app php scripts/usuario.php <usuario> [rol] [correo] [nombre]
// Ejemplo: docker compose exec app php scripts/usuario.php sharky admin
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../api/config.php';

$usuario = trim($argv[1] ?? '');
if ($usuario === '' || !preg_match('/^[a-z0-9._-]{3,30}$/i', $usuario)) {
    fwrite(STDERR, "Uso: php scripts/usuario.php <usuario> [rol] [correo] [nombre]\nEl usuario: 3 a 30 caracteres (letras, numeros, punto, guion o guion bajo).\n");
    exit(1);
}
$rol = strtolower(trim($argv[2] ?? ''));

function pedirClave(string $mensaje): string
{
    fwrite(STDOUT, $mensaje);
    $sttyActivo = DIRECTORY_SEPARATOR === '/' && @shell_exec('stty -echo 2>/dev/null') !== null;
    $clave = rtrim((string) fgets(STDIN), "\r\n");
    if ($sttyActivo) {
        @shell_exec('stty echo 2>/dev/null');
    }
    fwrite(STDOUT, "\n");
    return $clave;
}

$clave = pedirClave('Contraseña nueva (minimo 10 caracteres): ');
if (mb_strlen($clave) < 10) {
    fwrite(STDERR, "La contraseña debe tener al menos 10 caracteres.\n");
    exit(1);
}
if ($clave !== pedirClave('Repite la contraseña: ')) {
    fwrite(STDERR, "Las contraseñas no coinciden.\n");
    exit(1);
}
$hash = password_hash($clave, PASSWORD_DEFAULT);

$db = obtenerConexion();
$stmt = $db->prepare('SELECT id FROM usuarios WHERE usuario = :u LIMIT 1');
$stmt->execute([':u' => $usuario]);
$id = $stmt->fetchColumn();

if ($id) {
    $stmt = $db->prepare('UPDATE usuarios SET contrasena_hash = :h, activo = 1 WHERE id = :id');
    $stmt->execute([':h' => $hash, ':id' => $id]);
    echo "Contraseña actualizada para $usuario.\n";
} else {
    $correo = trim($argv[3] ?? '') ?: ($usuario . '@local.invalid');
    $nombre = trim($argv[4] ?? '') ?: $usuario;
    $stmt = $db->prepare('INSERT INTO usuarios (usuario, correo, nombre_mostrado, contrasena_hash) VALUES (:u, :c, :n, :h)');
    $stmt->execute([':u' => $usuario, ':c' => $correo, ':n' => $nombre, ':h' => $hash]);
    $id = (int) $db->lastInsertId();
    echo "Usuario $usuario creado.\n";
}

if ($rol !== '') {
    $stmt = $db->prepare('SELECT id FROM roles WHERE nombre = :n LIMIT 1');
    $stmt->execute([':n' => $rol]);
    $rolId = $stmt->fetchColumn();
    if (!$rolId) {
        fwrite(STDERR, "El rol '$rol' no existe (admin, invitado, docente, estudiante).\n");
        exit(1);
    }
    $db->prepare('DELETE FROM usuarios_roles WHERE usuario_id = :id')->execute([':id' => $id]);
    $db->prepare('INSERT INTO usuarios_roles (usuario_id, rol_id) VALUES (:u, :r)')->execute([':u' => $id, ':r' => $rolId]);
    echo "Rol asignado: $rol.\n";
}
