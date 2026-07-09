<?php
/**
 * Database Configuration
 */
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset = 'utf8mb4';
    public $conn;

    public function __construct(?array $config = null) {
        $config = $config ?? self::loadConfig();

        foreach (['db_host', 'db_name', 'db_user', 'db_pass'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new Exception("Missing database config value: {$key}");
            }
        }

        $this->host = $config['db_host'];
        $this->db_name = $config['db_name'];
        $this->username = $config['db_user'];
        $this->password = $config['db_pass'];
    }

    private static function loadConfig(): array {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host);
        $env = getenv('APP_ENV') ?: '';

        $configName = ($env === 'staging' || $host === 'staging.example.com' || $host === 'staging.ai-fcard.com')
            ? 'config.staging.php'
            : 'config.production.php';

        $paths = [
            dirname(__DIR__, 3) . '/' . $configName,
            __DIR__ . '/' . $configName,
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $config = require $path;
                if (!is_array($config)) {
                    throw new Exception("Config file must return an array: {$path}");
                }

                return $config;
            }
        }

        throw new Exception("Database config file not found: {$configName}");
    }

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
