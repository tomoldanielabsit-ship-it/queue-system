<?php
declare(strict_types=1);

require_once __DIR__ . '/local-config.php';

function isDuplicateKeyViolation(PDOException $error): bool
{
    return isset($error->errorInfo[1]) && (int)$error->errorInfo[1] === 1062;
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = loadLocalConfig();
    $host = $config['db_host'] ?? (getenv('PLMUN_DB_HOST') ?: '127.0.0.1');
    $port = $config['db_port'] ?? (getenv('PLMUN_DB_PORT') ?: '3306');
    $name = $config['db_name'] ?? (getenv('PLMUN_DB_NAME') ?: 'plmun_queue');
    $username = $config['db_user'] ?? (getenv('PLMUN_DB_USER') ?: 'root');
    $password = $config['db_password'] ?? (getenv('PLMUN_DB_PASSWORD') ?: '');

    if (!is_string($host) || !preg_match('/^[A-Za-z0-9.:-]+$/', $host)
        || !is_string($name) || !preg_match('/^[A-Za-z0-9_]+$/', $name)
        || !is_string($username) || !is_string($password)
        || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
        throw new RuntimeException('The MariaDB connection settings are invalid.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS appointments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(254) NOT NULL,
            appointment_date DATE NOT NULL,
            appointment_time CHAR(5) NOT NULL,
            office VARCHAR(120) NOT NULL DEFAULT 'Registrar',
            service_type VARCHAR(1000) NOT NULL DEFAULT 'Registrar service',
            tracking_code CHAR(32) NULL,
            status ENUM('booked', 'checked_in', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'booked',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_appointment_tracking_code (tracking_code),
            KEY idx_appointments_date_time (appointment_date, appointment_time)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $slotIndex = $pdo->query(
        "SHOW INDEX FROM appointments WHERE Key_name = 'unique_active_appointment_slot'"
    )->fetch();
    if ($slotIndex) {
        $pdo->exec('ALTER TABLE appointments DROP INDEX unique_active_appointment_slot');
    }
    $activeSlot = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'active_slot'")->fetch();
    if ($activeSlot) {
        $pdo->exec('ALTER TABLE appointments DROP COLUMN active_slot');
    }
    $statusColumn = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'status'")->fetch();
    if (!$statusColumn || strpos($statusColumn['Type'], "'checked_in'") === false) {
        $pdo->exec(
            "ALTER TABLE appointments
             MODIFY status ENUM('booked', 'checked_in', 'completed', 'cancelled', 'no_show')
             NOT NULL DEFAULT 'booked'"
        );
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS students (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(254) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_student_email (email)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS appointment_date_locks (
            booking_date DATE NOT NULL,
            PRIMARY KEY (booking_date)
        ) ENGINE=InnoDB"
    );

    return $pdo;
}
