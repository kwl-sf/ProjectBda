<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'planning_examens_dep');


function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    
    if ($conn->connect_error) {
        die("   erreur connected: " . $conn->connect_error);
    }
    
  
    $conn->set_charset("utf8mb4");
    
    return $conn;
}


function executeQuery($sql, $params = [], $types = "") {
    $conn = getDBConnection();
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        die("  Erreur " . $conn->error);
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stmt->close();
    $conn->close();
    
    return $result;
}

