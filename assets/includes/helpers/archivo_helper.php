<?php
/**
 * archivo_helper.php — Guarda y elimina archivos subidos por el usuario
 * (fotos de perfil, fotos de producto, adjuntos de exámenes).
 *
 * En Vercel el sistema de archivos del proyecto es de solo lectura (salvo
 * /tmp, que no persiste entre invocaciones), así que move_uploaded_file()
 * hacia una carpeta del proyecto no funciona en producción. Si existe la
 * variable de entorno BLOB_READ_WRITE_TOKEN, el archivo se sube a Vercel
 * Blob Storage y se guarda su URL pública; si no (desarrollo local con
 * Laragon), se guarda en disco como antes.
 */

require_once __DIR__ . '/../config/env_helper.php';

// Guarda un archivo subido (ruta temporal de $_FILES[...]['tmp_name']) y
// devuelve el valor que debe guardarse en la base de datos: una URL de
// Vercel Blob, o una ruta relativa local. Devuelve null si falló.
function guardar_archivo_subido(string $ruta_tmp, string $carpeta_relativa, string $nombre_archivo, string $mime): ?string {
    $carpeta_relativa = trim($carpeta_relativa, '/');
    $token = config_env('BLOB_READ_WRITE_TOKEN', '');

    if ($token !== '') {
        $contenido = file_get_contents($ruta_tmp);
        if ($contenido === false) {
            return null;
        }

        $destino = $carpeta_relativa . '/' . $nombre_archivo;
        $url = 'https://vercel.com/api/blob/?' . http_build_query(['pathname' => $destino]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $contenido,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'x-api-version: 12',
                'x-content-type: ' . $mime,
                'x-vercel-blob-access: public',
                'x-add-random-suffix: 0',
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $respuesta = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($codigo !== 200 || $respuesta === false) {
            return null;
        }

        $datos = json_decode($respuesta, true);
        return $datos['url'] ?? null;
    }

    // Sin token de Blob (desarrollo local): se guarda en disco, como antes.
    $carpeta_absoluta = __DIR__ . '/../../../' . $carpeta_relativa . '/';
    if (!is_dir($carpeta_absoluta)) {
        mkdir($carpeta_absoluta, 0755, true);
    }

    if (!move_uploaded_file($ruta_tmp, $carpeta_absoluta . $nombre_archivo)) {
        return null;
    }

    return $carpeta_relativa . '/' . $nombre_archivo;
}

// Elimina un archivo previamente guardado con guardar_archivo_subido()
// (detecta si es una URL de Blob o una ruta local relativa).
function eliminar_archivo_guardado(?string $valor_guardado): void {
    if (empty($valor_guardado)) {
        return;
    }

    if (str_starts_with($valor_guardado, 'http://') || str_starts_with($valor_guardado, 'https://')) {
        $token = config_env('BLOB_READ_WRITE_TOKEN', '');
        if ($token === '') {
            return;
        }

        $ch = curl_init('https://vercel.com/api/blob/delete');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode(['urls' => [$valor_guardado]]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'x-api-version: 12',
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        return;
    }

    $ruta_absoluta = __DIR__ . '/../../../' . $valor_guardado;
    if (is_file($ruta_absoluta)) {
        @unlink($ruta_absoluta);
    }
}

// Descarga el contenido de un archivo previamente guardado (URL de Blob o
// ruta local relativa), para poder retransmitirlo (proxy) a través de un
// script de acceso controlado, sin exponer la URL real de Blob al cliente.
// Devuelve null si no se pudo leer.
function leer_archivo_guardado(string $valor_guardado): ?string {
    if (str_starts_with($valor_guardado, 'http://') || str_starts_with($valor_guardado, 'https://')) {
        $ch = curl_init($valor_guardado);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $contenido = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ($codigo === 200 && $contenido !== false) ? $contenido : null;
    }

    $ruta_absoluta = __DIR__ . '/../../../' . $valor_guardado;
    if (!is_file($ruta_absoluta)) {
        return null;
    }

    $contenido = file_get_contents($ruta_absoluta);
    return $contenido !== false ? $contenido : null;
}
