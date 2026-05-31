<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use App\Controllers\AuthController;
use App\Controllers\CarruselController;
use App\Controllers\CiudadesController;
use App\Controllers\CmsController;
use App\Controllers\ContactoController;
use App\Controllers\FavoriteController;
use App\Controllers\HotelController;
use App\Controllers\ReservaController;
use App\Controllers\ReviewController;
use App\Controllers\TwoFaController;
use App\Controllers\UserController;
use App\Middleware\JwtMiddleware;
use App\Helpers\Response;

// ── CORS ──────────────────────────────────────────────────────
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=utf-8");

// ── RUTA LIMPIA ───────────────────────────────────────────────
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$baseDir       = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

// Solo quitar el prefijo de directorio si NO es la raíz "/"
// (cuando $baseDir="/" str_replace eliminaría TODOS los slashes del path)
if ($baseDir !== '/') {
    $route = str_replace($baseDir, '', $requestUri);
} else {
    $route = $requestUri;
}
$route = str_replace('/index.php', '', $route);
$route = '/' . trim($route, '/');

// ── RUTAS  [controller, method, requiresAuth] ─────────────────
$routes = [
    'POST' => [
        '/login'                    => [AuthController::class,     'login',              false],
        '/register'                 => [AuthController::class,     'register',           false],
        '/change-password'          => [AuthController::class,     'cambiarPassword',    true],
        '/usuarios'                 => [AuthController::class,     'cambiarPassword',    true],
        '/user/update'              => [UserController::class,     'update',             true],
        '/update-preferences'       => [UserController::class,     'updatePreferences',  true],
        '/reservas'                 => [ReservaController::class,  'crearReserva',       true],
        '/reservas/cancelar'        => [ReservaController::class,  'cancelarReserva',    true],
        '/favoritos'                => [FavoriteController::class, 'toggleHotelFavorite',true],
        '/hoteles'                  => [HotelController::class,    'crearHotel',         true],
        '/hoteles/update'           => [HotelController::class,    'actualizarHotel',    true],
        '/habitaciones'             => [HotelController::class,    'crearHabitacion',    true],
        '/habitaciones/update'      => [HotelController::class,    'actualizarHabitacion',true],
        '/reviews'                  => [ReviewController::class,   'crearReview',        true],
        // 2FA
        '/2fa/setup'                => [TwoFaController::class,    'setup',              true],
        '/2fa/disable'              => [TwoFaController::class,    'disable',            true],
        // Contacto
        '/contacto'                 => [ContactoController::class, 'crearCanal',         true],
        '/contacto/mensaje'         => [ContactoController::class, 'enviarMensaje',      false],
        '/contacto/mensaje/leido'   => [ContactoController::class, 'marcarLeido',        true],
        // Carrusel y CMS (admin)
        '/carrusel'                 => [CarruselController::class, 'addToCarrusel',      true],
        '/cms'                      => [CmsController::class,      'saveCms',            true],
    ],
    'GET' => [
        '/me'                       => [UserController::class,     'me',                 true],
        '/usuarios'                 => [UserController::class,     'me',                 true],
        '/hoteles'                  => [HotelController::class,    'getHoteles',         false],
        '/hoteles/ciudad'           => [HotelController::class,    'getHotelesPorCiudad',false],
        '/habitaciones'             => [HotelController::class,    'getHabitacionesPorHotel', false],
        '/reservas'                 => [ReservaController::class,  'getReservasUsuario', true],
        '/favoritos'                => [FavoriteController::class, 'getHotelFavorites',  true],
        '/reviews'                  => [ReviewController::class,   'getReviews',         false],
        // 2FA
        '/2fa/status'               => [TwoFaController::class,    'getStatus',          true],
        // Contacto
        '/contacto'                 => [ContactoController::class, 'getCanales',         false],
        '/contacto/mensajes'        => [ContactoController::class, 'getMensajes',        true],
        // Ciudades, Carrusel, CMS
        '/ciudades'                 => [CiudadesController::class, 'getCiudades',        false],
        '/carrusel'                 => [CarruselController::class, 'getCarrusel',        false],
        '/cms'                      => [CmsController::class,      'getCms',             false],
    ],
    'PUT' => [
        '/reservas/cancelar'        => [ReservaController::class,  'cancelarReserva',    true],
        '/contacto'                 => [ContactoController::class, 'actualizarCanal',    true],
    ],
    'DELETE' => [
        '/delete-account'           => [UserController::class,     'deleteAccount',      true],
        '/hoteles'                  => [HotelController::class,    'eliminarHotel',      true],
        '/habitaciones'             => [HotelController::class,    'eliminarHabitacion', true],
        '/reviews'                  => [ReviewController::class,   'eliminarReview',     true],
        '/contacto'                 => [ContactoController::class, 'eliminarCanal',      true],
        '/contacto/mensaje'         => [ContactoController::class, 'eliminarMensaje',    true],
        '/carrusel'                 => [CarruselController::class, 'removeFromCarrusel', true],
    ],
];

// ── DESPACHO ──────────────────────────────────────────────────
if (isset($routes[$requestMethod][$route])) {
    [$controllerClass, $methodName, $requiresAuth] = $routes[$requestMethod][$route];

    try {
        $payload = null;
        if ($requiresAuth) {
            $payload = JwtMiddleware::handle();
        }

        $controller = new $controllerClass();

        if ($requiresAuth) {
            $controller->$methodName($payload, $_GET);
        } else {
            $controller->$methodName($_GET);
        }
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 500);
    }
} else {
    Response::error("Ruta no encontrada [$requestMethod]: $route", 404);
}
