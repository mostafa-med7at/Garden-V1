<?php
// config/db.php — Database connection (XAMPP)
define("DB_HOST", "localhost");
define("DB_USER", "root");
define("DB_PASS", "Aabdelhady2005"); // XAMPP default: empty password
define("DB_NAME", "garden_db");
define("DB_PORT", 3306);

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn =
            "mysql:host=" .
            DB_HOST .
            ";port=" .
            DB_PORT .
            ";dbname=" .
            DB_NAME .
            ";charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die(
                json_encode([
                    "error" =>
                        "Database connection failed: " . $e->getMessage(),
                ])
            );
        }
    }
    return $pdo;
}
