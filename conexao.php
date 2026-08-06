<?php
require_once __DIR__ . '/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Erro de conexão: " . $conn->connect_error);
}

// Garante que a CONEXÃO usa utf8mb4, o mesmo charset do banco (evita acentuação corrompida em nomes de matérias, cronogramas, etc.)
$conn->set_charset("utf8mb4");
?>