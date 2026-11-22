<?php

// Define (o cambia) la clave maestra del sitio y genera el código de recuperación.
// También borra las contraseñas anteriores de todos los usuarios: desde ahora se entra solo con la clave maestra.
//   docker compose exec app php scripts/clave-maestra.php
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/clave_maestra.php';

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

$db = obtenerConexion();
if (!idAdministrador($db)) {
    fwrite(STDERR, "No hay un usuario administrador activo. Crea uno con scripts/usuario.php <usuario> admin\n");
    exit(1);
}

$clave = pedirClave('Clave maestra nueva (minimo ' . LARGO_MINIMO_CLAVE . ' caracteres): ');
if (mb_strlen($clave) < LARGO_MINIMO_CLAVE) {
    fwrite(STDERR, 'La clave debe tener al menos ' . LARGO_MINIMO_CLAVE . " caracteres.\n");
    exit(1);
}
if ($clave !== pedirClave('Repite la clave: ')) {
    fwrite(STDERR, "Las claves no coinciden.\n");
    exit(1);
}

$codigo = guardarClaveMaestra($db, $clave);
$borradas = $db->exec('UPDATE usuarios SET contrasena_hash = NULL WHERE contrasena_hash IS NOT NULL');
$db->exec('DELETE FROM intentos_login');

echo "\nClave maestra guardada. Contraseñas anteriores eliminadas: $borradas.\n\n";
echo "CODIGO DE RECUPERACION (guardalo ahora, no se vuelve a mostrar):\n\n    $codigo\n\n";
echo "Sirve una sola vez para definir una clave nueva con \"Olvide mi clave\".\n";
