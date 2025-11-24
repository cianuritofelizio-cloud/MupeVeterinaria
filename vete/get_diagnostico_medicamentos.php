<?php
// Devuelve JSON con los medicamentos asociados a un diagnóstico
session_start();
header('Content-Type: application/json; charset=utf-8');

// Permisos básicos
if (!isset($_SESSION['rol']) || $_SESSION['rol'] != 'Veterinario') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Forbidden']);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    echo json_encode(['ok'=>false,'msg'=>'ID inválido', 'medicamentos'=>[]]);
    exit;
}

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "veterinaria_mupe";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'msg'=>'DB error']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT dm.id, dm.id_inventario, dm.cantidad, i.nombre AS articulo_nombre
    FROM diagnostico_medicamentos dm
    LEFT JOIN inventario i ON dm.id_inventario = i.id
    WHERE dm.id_diagnostico = ?
");
$stmt->execute([$id]);
$meds = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok'=>true,'medicamentos'=>$meds]);
exit;