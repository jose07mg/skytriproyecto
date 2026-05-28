<?php

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Helpers\Response;

class JwtMiddleware {

    public static function handle() {
        $config = require __DIR__ . '/../../config.php';
        $secret = $config['jwt']['secret_key'];

        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $authHeader = $headers['authorization'] ?? null;

        if (!$authHeader) {
            self::unauthorized();
        }

        $tokenParts = explode(" ", $authHeader);

        if (count($tokenParts) < 2 || strcasecmp($tokenParts[0], 'Bearer') !== 0) {
            self::unauthorized();
        }

        $token = $tokenParts[1];
        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            self::unauthorized();
        }
    }

    private static function unauthorized() {
        Response::error("No autorizado", 401);
    }
}