<?php
/**
 * Database Configuration
 */
class Database {
    private $host = 'localhost';
    private $db_name = 'xs013436_realestatecard';
    private $username = 'xs013436_nishio';
    private $password = 'renewal4329';
    private $charset = 'utf8mb4';
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch(PDOException $e) {
            error_log("Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }

        return $this->conn;
    }

    public static function bindValues(PDOStatement $stmt, array $params) {
        foreach ($params as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : (strpos($key, ':') === 0 ? $key : ':' . $key);

            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            } else {
                $type = PDO::PARAM_STR;
            }

            $stmt->bindValue($parameter, $value, $type);
        }
    }
}