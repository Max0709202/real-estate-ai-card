<?php
/**
 * Simple test endpoint to verify connectivity
 */

// Send CORS headers immediately
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Simple test response
echo json_encode([
    'success' => true,
    'message' => 'Server is reachable',
    'method' => $_SERVER['REQUEST_METHOD'],
    'time' => date('Y-m-d H:i:s'),
    'session_active' => session_status() === PHP_SESSION_ACTIVE,
    'files_received' => !empty($_FILES),
    'post_data' => !empty($_POST)
]);
