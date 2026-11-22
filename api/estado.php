<?php

// Estado del sistema para el escritorio: datos reales del servidor y de las apps.
// Solo para usuarios con sesión iniciada.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sesion.php';

iniciarSesionSegura();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    responderJSON(405, false, null, 'Metodo no permitido.');
}
if (!obtenerUsuarioSesion()) {
    responderJSON(401, false, null, 'Debes iniciar sesion.');
}
// Libera el bloqueo de la sesión: las comprobaciones de abajo pueden tardar unos segundos.
session_write_close();

// Apps que se vigilan: se consultan por la red interna de Docker (sin salir a internet).
const APPS_VIGILADAS = [
    ['id' => 'sql', 'nombre' => 'Bases de datos', 'host' => 'sql.smarteksoft.com', 'url' => 'http://tutorialbd-web-1:80/'],
    ['id' => 'raee', 'nombre' => 'RAEE', 'host' => 'raee.smarteksoft.com', 'url' => 'http://raee-app-1:3100/'],
    ['id' => 'soporte', 'nombre' => 'Soporte', 'host' => 'soporte.smarteksoft.com', 'url' => 'http://soporte-app-1:3200/'],
    ['id' => 'tucultura', 'nombre' => 'Tu Cultura', 'host' => 'tucultura.smarteksoft.com', 'url' => 'http://tucultura-app-1:80/'],
];

function leerMemoria(): array
{
    $total = $disponible = 0;
    foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
        if (strpos($linea, 'MemTotal:') === 0) {
            $total = (int) filter_var($linea, FILTER_SANITIZE_NUMBER_INT) * 1024;
        } elseif (strpos($linea, 'MemAvailable:') === 0) {
            $disponible = (int) filter_var($linea, FILTER_SANITIZE_NUMBER_INT) * 1024;
        }
    }
    return ['total' => $total, 'usada' => max(0, $total - $disponible)];
}

function comprobarApps(): array
{
    $multi = curl_multi_init();
    $manejadores = [];
    foreach (APPS_VIGILADAS as $app) {
        $ch = curl_init($app['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'SmartekSoft-Estado/1.0',
        ]);
        curl_multi_add_handle($multi, $ch);
        $manejadores[$app['id']] = $ch;
    }
    do {
        $estado = curl_multi_exec($multi, $activos);
        if ($activos) {
            curl_multi_select($multi, 0.5);
        }
    } while ($activos && $estado === CURLM_OK);

    $resultado = [];
    foreach (APPS_VIGILADAS as $app) {
        $ch = $manejadores[$app['id']];
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $resultado[] = [
            'id' => $app['id'],
            'nombre' => $app['nombre'],
            'host' => $app['host'],
            'en_linea' => $codigo > 0 && $codigo < 500,
            'codigo' => $codigo,
            'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $resultado;
}

$uptime = (float) explode(' ', trim((string) @file_get_contents('/proc/uptime')))[0];
$carga = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];

$db = obtenerConexion();
$tips = (int) $db->query('SELECT COUNT(*) FROM tips')->fetchColumn();
$categorias = (int) $db->query('SELECT COUNT(*) FROM categorias')->fetchColumn();

$discoTotal = (float) @disk_total_space('/');
$discoLibre = (float) @disk_free_space('/');

responderJSON(200, true, [
    'uptime_s' => (int) $uptime,
    'carga' => array_map(fn($v) => round($v, 2), $carga),
    'nucleos' => max(1, substr_count((string) @file_get_contents('/proc/cpuinfo'), "\nprocessor") + 1),
    'memoria' => leerMemoria(),
    'disco' => ['total' => (int) $discoTotal, 'usado' => (int) max(0, $discoTotal - $discoLibre)],
    'tips' => $tips,
    'categorias' => $categorias,
    'apps' => comprobarApps(),
    'hora' => date('c'),
], 'Estado del sistema.');
