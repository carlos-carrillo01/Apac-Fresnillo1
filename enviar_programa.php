<?php
// punto de entrada antiguo; carga la configuracion y la sesion igual que api/correo.php
require_once __DIR__ . '/backend/bootstrap.php';
require_once __DIR__ . '/backend/controllers/CorreoController.php';

$slug = $_POST['programa'] ?? ($_GET['p'] ?? '');
CorreoController::enviarPrograma($slug);
