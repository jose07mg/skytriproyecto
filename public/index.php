<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use App\Controllers\AuthController;
use App\Controllers\HotelController;
use App\Controllers\ReservaController;
use App\Controllers\UserController;
use App\Middleware\JwtMiddleware;
use App\Helpers\Response;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=utf-8");

$appUrl = $_ENV['APP_URL'] ?? 'http://localhost/skytrip_api/public';
$basePath = rtrim(parse_url($appUrl, PHP_URL_PATH) ?? '', '/');
$requestMethod = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = '/' . trim(str_replace($basePath, '', $uri), '/');

$routeParts = explode('/', trim($route, '/'));
if (count($routeParts) === 2 && $routeParts[0] === 'hoteles' && ctype_digit($routeParts[1])) {
    $_GET['id_hotel'] = (int) $routeParts[1];
    $route = '/hoteles';
}
if (count($routeParts) === 2 && $routeParts[0] === 'habitaciones' && ctype_digit($routeParts[1])) {
    $_GET['id_habitacion'] = (int) $routeParts[1];
    $route = '/habitaciones';
}

$routes = [
    'POST' => [
        '/login' => [AuthController::class, 'login', false],
        '/register' => [AuthController::class, 'register', false],
        '/reservas' => [ReservaController::class, 'crearReserva', true],
        '/reservas/cancelar' => [ReservaController::class, 'cancelarReserva', true],
        '/hoteles' => [HotelController::class, 'crearHotel', true],
        '/habitaciones' => [HotelController::class, 'crearHabitacion', true],
        '/user/update' => [UserController::class, 'update', true],
        '/user/password' => [UserController::class, 'changePassword', true],
    ],
    'GET' => [
        '/hoteles' => [HotelController::class, 'getHoteles', false],
        '/hoteles/ciudad' => [HotelController::class, 'getHotelesPorCiudad', false],
        '/habitaciones' => [HotelController::class, 'getHabitaciones', false],
        '/reservas' => [ReservaController::class, 'getReservasUsuario', true],
        '/me' => [UserController::class, 'me', true],
    ],
    'PUT' => [
        '/hoteles' => [HotelController::class, 'actualizarHotel', true],
        '/habitaciones' => [HotelController::class, 'actualizarHabitacion', true],
    ],
    'DELETE' => [
        '/hoteles' => [HotelController::class, 'eliminarHotel', true],
        '/habitaciones' => [HotelController::class, 'eliminarHabitacion', true],
    ],
];

if (!isset($routes[$requestMethod][$route])) {
    Response::error("Ruta no encontrada: " . $route, 404);
}

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
} catch (Exception $e) {
    Response::error($e->getMessage(), 500);
}
