<?php
require __DIR__ . '/app/bootstrap.php';

cerrar_sesion();
header('Location: login.php');
