<?php
// db_session_handler.php — Guarda las sesiones de PHP en la base de datos
// (tabla `sesiones`) en vez de en archivos locales.
//
// Necesario porque el runtime serverless de PHP en Vercel no garantiza que los
// archivos de sesión persistan entre una solicitud y la siguiente (cada una
// puede ejecutarse en una instancia distinta): el usuario iniciaba sesión
// correctamente, pero al navegar a la siguiente página ya no había sesión y
// lo regresaba al login. Guardar la sesión en la misma base de datos que ya
// usa el resto del sistema resuelve esto sin depender del disco local.

class DbSessionHandler implements SessionHandlerInterface {

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        $stmt = $this->pdo->prepare('SELECT datos FROM sesiones WHERE id = :id AND expira_en >= :ahora');
        $stmt->execute(['id' => $id, 'ahora' => time()]);
        $fila = $stmt->fetch();
        return $fila ? $fila['datos'] : '';
    }

    public function write(string $id, string $datos): bool {
        $expira_en = time() + (int) ini_get('session.gc_maxlifetime');

        $stmt = $this->pdo->prepare('
            INSERT INTO sesiones (id, datos, expira_en) VALUES (:id, :datos, :expira_en)
            ON DUPLICATE KEY UPDATE datos = VALUES(datos), expira_en = VALUES(expira_en)
        ');

        return $stmt->execute(['id' => $id, 'datos' => $datos, 'expira_en' => $expira_en]);
    }

    public function destroy(string $id): bool {
        $stmt = $this->pdo->prepare('DELETE FROM sesiones WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false {
        $stmt = $this->pdo->prepare('DELETE FROM sesiones WHERE expira_en < :ahora');
        $stmt->execute(['ahora' => time()]);
        return $stmt->rowCount();
    }
}
