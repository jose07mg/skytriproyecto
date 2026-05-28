<?php

namespace App\Config;

use mysqli;

class Database {

    private static $instance = null;
    private $connection;

    private function __construct() {
        $config = require __DIR__ . '/../../config.php';
        $dbConfig = $config['db'];

        $this->connection = new mysqli(
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database']
        );

        if ($this->connection->connect_error) {
            die("Error DB: " . $this->connection->connect_error);
        }

        $this->connection->set_charset($dbConfig['charset']);
    }

    public static function getConnection() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance->connection;
    }

    public static function getInstance() {
        return self::getConnection();
    }
}