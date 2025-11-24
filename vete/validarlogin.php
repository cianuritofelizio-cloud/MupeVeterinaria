<?php
session_start();

// Simulación temporal de usuarios
$usuarios = [
  'admin' => ['password' => '1234', 'rol' => 'Administrador'],
  'vet' => ['password' => '1234', 'rol' => 'Veterinario'],
  'recep' => ['password' => '1234', 'rol' => 'Recepcionista'],
  'cliente' => ['password' => '1234', 'rol' => 'Cliente'],
];

$usuario = $_POST['usuario'] ?? '';
$password = $_POST['password'] ?? '';

if (isset($usuarios[$usuario]) && $usuarios[$usuario]['password'] === $password) {
  $_SESSION['usuario'] = $usuario;
  $_SESSION['rol'] = $usuarios[$usuario]['rol'];

  switch ($_SESSION['rol']) {
    case 'Administrador': header('Location: admin/dashboard.php'); exit;
    case 'Veterinario': header('Location: veterinario/dashboard.php'); exit;
    case 'Recepcionista': header('Location: recepcionista/dashboard.php'); exit;
    case 'Cliente': header('Location: cliente/dashboard.php'); exit;
  }
} else {
  echo "<script>alert('Usuario o contraseña incorrectos'); window.location='login.php';</script>";
}
