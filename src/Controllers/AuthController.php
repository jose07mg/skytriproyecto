<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

class AuthController {

    public function login($params = []) {
        $db = Database::getConnection();
        $data = $this->getRequestData();
        $this->validateLoginData($data);

        $credential = $data['email'] ?? $data['usuario'];
        $stmt = $db->prepare("SELECT id_usuario, usuario, email, password FROM usuarios WHERE email = ? OR usuario = ?");
        $stmt->bind_param("ss", $credential, $credential);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            Response::error("Credenciales incorrectas", 401);
        }

        $user = $result->fetch_assoc();
        $storedPassword = $user['password'];
        unset($user['password']);

        $isHashed = password_get_info($storedPassword)['algo'] > 0;
        $isValidPassword = $isHashed
            ? password_verify($data['password'], $storedPassword)
            : hash_equals($storedPassword, $data['password']);

        if (!$isValidPassword) {
            Response::error("Credenciales incorrectas", 401);
        }

        if (!$isHashed) {
            $newHash = password_hash($data['password'], PASSWORD_DEFAULT);
            $update = $db->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");
            $update->bind_param("si", $newHash, $user['id_usuario']);
            $update->execute();
        }

        $role = $this->resolveRole($user);
        $user['role'] = $role;
        $user['rol'] = $role;

        $token = $this->generateToken([
            'id_usuario' => (int) $user['id_usuario'],
            'email' => $user['email'],
            'usuario' => $user['usuario'],
            'role' => $role,
            'rol' => $role,
            'iat' => time(),
            'exp' => time() + 3600,
        ]);

        Response::success([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function register($params = []) {
        $db = Database::getConnection();
        $data = $this->getRequestData();
        $this->validateRegisterData($data);

        $check = $db->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
        $check->bind_param("s", $data['email']);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            Response::error("El email ya esta en uso", 409);
        }

        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO usuarios(usuario, email, password) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $data['usuario'], $data['email'], $passwordHash);
        $stmt->execute();

        Response::success(["message" => "Usuario creado"], 201);
    }

    private function getRequestData() {
        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            Response::error("JSON invalido", 400);
        }
        return $data;
    }

    private function validateLoginData(array $data) {
        if ((empty($data['email']) && empty($data['usuario'])) || empty($data['password'])) {
            Response::error("Email/usuario y contrasena son obligatorios", 400);
        }
    }

    private function validateRegisterData(array $data) {
        if (empty($data['usuario']) || empty($data['email']) || empty($data['password'])) {
            Response::error("Usuario, email y contrasena son obligatorios", 400);
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Response::error("Email no valido", 400);
        }

        if (strlen($data['password']) < 6) {
            Response::error("La contrasena debe tener al menos 6 caracteres", 400);
        }
    }

    private function resolveRole(array $user) {
        $username = strtolower($user['usuario'] ?? '');
        $email = strtolower($user['email'] ?? '');
        return ($username === 'admin' || $email === 'admin@gmail.com') ? 'admin' : 'user';
    }

    private function generateToken(array $payload) {
        $config = require __DIR__ . '/../../config.php';
        $secret = $config['jwt']['secret_key'];
        return \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');
    }
}
