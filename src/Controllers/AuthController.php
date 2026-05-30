<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use Firebase\JWT\JWT;

class AuthController {

    public function login() {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['password']) || (empty($data['usuario']) && empty($data['email']))) {
            Response::error("Usuario y contraseña son obligatorios", 400);
            return;
        }

        $identifier = $data['usuario'] ?? $data['email'];

        $db   = Database::getConnection();
        $stmt = $db->prepare("SELECT id_usuario, usuario, email, password, rol, totp_enabled, totp_secret FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            Response::error("Usuario o contraseña incorrectos", 401);
            return;
        }

        $user = $result->fetch_assoc();

        if (!password_verify($data['password'], $user['password'])) {
            Response::error("Usuario o contraseña incorrectos", 401);
            return;
        }

        // ── 2FA check ───────────────────────────────────────────
        $totpEnabled = !empty($user['totp_enabled']) && !empty($user['totp_secret']);

        if ($totpEnabled) {
            if (empty($data['totp_code'])) {
                // First step: credentials OK, ask for TOTP code
                Response::json(['status' => 'totp_required']);
                return;
            }

            // Second step: verify TOTP code
            require_once __DIR__ . '/../Helpers/TotpHelper.php';
            if (!\App\Helpers\TotpHelper::verify($user['totp_secret'], $data['totp_code'])) {
                Response::error("Código 2FA incorrecto", 401);
                return;
            }
        }

        // ── Generate JWT ─────────────────────────────────────────
        $config = require __DIR__ . '/../../config.php';
        $secret = $config['jwt']['secret_key'] ?? '';

        if (empty($secret)) {
            Response::error("Error de configuración de JWT", 500);
            return;
        }

        $tokenPayload = [
            'id_usuario' => (int) $user['id_usuario'],
            'usuario'    => $user['usuario'],
            'email'      => $user['email'],
            'rol'        => $user['rol'],
            'iat'        => time(),
            'exp'        => time() + 3600 * 24,
        ];

        $token = JWT::encode($tokenPayload, $secret, 'HS256');

        Response::success([
            'token' => $token,
            'user'  => [
                'id_usuario' => (int) $user['id_usuario'],
                'usuario'    => $user['usuario'],
                'email'      => $user['email'],
                'rol'        => $user['rol'],
            ],
        ]);
    }

    public function register() {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['usuario']) || empty($data['email']) || empty($data['password'])) {
            Response::error("Usuario, email y contraseña son obligatorios", 400);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("SELECT id_usuario FROM usuarios WHERE usuario = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $data['usuario'], $data['email']);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            Response::error("El usuario o el email ya están registrados", 409);
            return;
        }

        $hash  = password_hash($data['password'], PASSWORD_BCRYPT);
        $saldo = 5000.00;

        // Try insert with saldo column; fall back without it if the column doesn't exist
        $stmt = $db->prepare("INSERT INTO usuarios (usuario, email, password, saldo) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssd", $data['usuario'], $data['email'], $hash, $saldo);
            if ($stmt->execute()) {
                Response::success(["message" => "Usuario registrado correctamente"], 201);
                return;
            }
        }

        // Fallback: insert without saldo
        $stmt2 = $db->prepare("INSERT INTO usuarios (usuario, email, password) VALUES (?, ?, ?)");
        $stmt2->bind_param("sss", $data['usuario'], $data['email'], $hash);

        if ($stmt2->execute()) {
            Response::success(["message" => "Usuario registrado correctamente"], 201);
        } else {
            Response::error("Error al registrar el usuario", 500);
        }
    }

    public function cambiarPassword($payload) {
        $data = json_decode(file_get_contents("php://input"), true);

        if (empty($data['new_password'])) {
            Response::error("Nueva contraseña requerida", 400);
            return;
        }

        $userId  = $payload['id_usuario'];
        $newHash = password_hash($data['new_password'], PASSWORD_BCRYPT);

        $db   = Database::getConnection();
        $stmt = $db->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");
        $stmt->bind_param("si", $newHash, $userId);

        if ($stmt->execute()) {
            Response::success(["message" => "Contraseña actualizada correctamente"]);
        } else {
            Response::error("Error al actualizar la contraseña", 500);
        }
    }
}
