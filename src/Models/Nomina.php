<?php

namespace App\Models;

use App\Config\Database;

class Nomina
{
    public static function getNominas($idEmpleado)
    {
        $conn = Database::getInstance();

        $query = "SELECT * FROM gpersonal WHERE empleado = ?";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        }

        $stmt->bind_param("i", $idEmpleado);
        $stmt->execute();
        $result = $stmt->get_result();

        $nominas = [];
        while ($row = $result->fetch_assoc()) {
            $nominas[] = $row;
        }

        return $nominas;
    }
}
