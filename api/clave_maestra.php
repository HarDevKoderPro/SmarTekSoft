<?php

// Clave maestra del sitio: una sola clave (guardada como hash bcrypt) que abre la sesión del administrador.
// El código de recuperación sirve para definir una clave nueva si se olvida; es de un solo uso.

const LARGO_MINIMO_CLAVE = 10;
// Alfabeto sin 0/O/1/I/L para que el código se pueda copiar a mano sin confusiones.
const ALFABETO_CODIGO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

function asegurarTablaMaestra(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS acceso_maestro (
        id TINYINT NOT NULL,
        clave_hash VARCHAR(255) NOT NULL,
        recuperacion_hash VARCHAR(255) NOT NULL,
        actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function obtenerAccesoMaestro(PDO $db): ?array
{
    asegurarTablaMaestra($db);
    $fila = $db->query('SELECT clave_hash, recuperacion_hash FROM acceso_maestro WHERE id = 1')->fetch();
    return $fila ?: null;
}

function generarCodigoRecuperacion(): string
{
    $texto = '';
    for ($i = 0; $i < 20; $i++) {
        $texto .= ALFABETO_CODIGO[random_int(0, strlen(ALFABETO_CODIGO) - 1)];
    }
    return implode('-', str_split($texto, 5));
}

// El código se compara sin guiones, sin espacios y en mayúsculas.
function normalizarCodigo(string $codigo): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
}

// Guarda la clave nueva y devuelve el código de recuperación nuevo (se muestra una sola vez).
function guardarClaveMaestra(PDO $db, string $clave): string
{
    asegurarTablaMaestra($db);
    $codigo = generarCodigoRecuperacion();
    $stmt = $db->prepare('REPLACE INTO acceso_maestro (id, clave_hash, recuperacion_hash) VALUES (1, :clave, :codigo)');
    $stmt->execute([
        ':clave' => password_hash($clave, PASSWORD_DEFAULT),
        ':codigo' => password_hash(normalizarCodigo($codigo), PASSWORD_DEFAULT),
    ]);
    return $codigo;
}

// Usuario que se abre al entrar con la clave maestra: el administrador activo más antiguo.
function idAdministrador(PDO $db): ?int
{
    $id = $db->query("SELECT u.id FROM usuarios u
        JOIN usuarios_roles ur ON ur.usuario_id = u.id
        JOIN roles r ON r.id = ur.rol_id
        WHERE r.nombre = 'admin' AND u.activo = 1
        ORDER BY u.id ASC LIMIT 1")->fetchColumn();
    return $id ? (int) $id : null;
}
