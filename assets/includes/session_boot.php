<?php
// session_boot.php — Arranca la sesión de PHP usando la base de datos como
// almacenamiento (ver db_session_handler.php), en vez de archivos locales.
//
// Reemplaza la llamada directa a session_start(): este archivo ya la hace al
// final, después de configurar el manejador. Debe incluirse ANTES de leer o
// escribir $_SESSION, igual que antes se requería llamar a session_start()
// como primera línea de cada página.

require_once __DIR__ . '/config/config.php'; // Trae $pdo
require_once __DIR__ . '/db_session_handler.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_save_handler(new DbSessionHandler($pdo), true);
    session_start();
}
