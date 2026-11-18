<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/local-config.php';
require_once __DIR__ . '/holiday-calendar.php';

date_default_timezone_set('Asia/Manila');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const APPOINTMENT_SLOTS = [
    '9:00 AM' => '09:00',
    '9:30 AM' => '09:30',
    '10:00 AM' => '10:00',
    '10:30 AM' => '10:30',
    '11:00 AM' => '11:00',
    '11:30 AM' => '11:30',
    '1:00 PM' => '13:00',
    '1:30 PM' => '13:30',
    '2:00 PM' => '14:00',
    '2:30 PM' => '14:30',
    '3:00 PM' => '15:00',
    '3:30 PM' => '15:30',
    '4:00 PM' => '16:00',
];
const STUDENT_EMAIL_DOMAIN = '@plmun.edu.ph';
const DAILY_APPOINTMENT_LIMIT = 100;

function respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        respond(['error' => 'The request method is not supported.'], 405);
    }
}

function requireAdmin(): void
{
    if (empty($_SESSION['admin_email'])) {
        respond(['error' => 'Please sign in first.'], 401);
    }
}

function requireStudent(): void
{
    if (empty($_SESSION['student_id']) || empty($_SESSION['student_email'])) {
        respond(['error' => 'Please sign in with a student account first.'], 401);
    }
}

function requestBody(): array
{
    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded')) {
        return $_POST;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        respond(['error' => 'The request data is invalid.'], 400);
    }
    return $body;
}

function validDate(string $date): ?DateTimeImmutable
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
        || $parsed->format('Y-m-d') !== $date) {
        return null;
    }
    return $parsed;
}

function isWeekday(DateTimeImmutable $date): bool
{
    return (int)$date->format('N') <= 5;
}

function availableSlots(string $date): array
{
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $available = [];

    foreach (APPOINTMENT_SLOTS as $label => $time) {
        if ($date === $today->format('Y-m-d')
            && $time <= $now->format('H:i')) {
            continue;
        }
        $available[] = $label;
    }
    return $available;
}

function generateTrackingCode(PDO $pdo): string
{
    $check = $pdo->prepare(
        'SELECT 1 FROM appointments WHERE tracking_code = :code LIMIT 1'
    );
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $code = chr(random_int(65, 90))
            . chr(random_int(65, 90))
            . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $check->execute(['code' => $code]);
        if ($check->fetchColumn() === false) {
            return $code;
        }
    }

    throw new RuntimeException('Could not generate a unique tracking code.');
}

function isValidTrackingCode(string $trackingCode): bool
{
    return preg_match('/^(?:[A-F0-9]{32}|[A-Z]{2}[0-9]{6})$/', strtoupper($trackingCode)) === 1;
}

function csvSafeCell(string $value): string
{
    return preg_match('/^[\t\r ]*[=+\-@]/', $value) === 1
        ? "'" . $value
        : $value;
}

function countDailyAppointments(PDO $pdo, string $date): int
{
    $query = $pdo->prepare(
        "SELECT COUNT(*) FROM appointments
         WHERE appointment_date = :date AND status != 'cancelled'"
    );
    $query->execute(['date' => $date]);
    return (int)$query->fetchColumn();
}

function sendAppointmentEmails(
    string $name,
    string $email,
    string $date,
    string $time,
    string $serviceType,
    string $trackingCode,
    bool $rescheduled = false
): void
{
    require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
    require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';

    $smtp = smtpConfiguration();
    $host = $smtp['host'];
    $username = $smtp['username'];
    $password = $smtp['password'];
    $port = $smtp['port'];
    $adminEmail = $smtp['recipient'];
    if (!$host || !$username || !$password || !$port || !$adminEmail
        || !filter_var($username, FILTER_VALIDATE_EMAIL)
        || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP configuration is incomplete.');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = $port === 465
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $port;
    $mail->Timeout = 10;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->setFrom($username, 'PLMun Queue System');
    $mail->addAddress($email);
    if (strtolower($adminEmail) !== strtolower($email)) {
        $mail->addAddress($adminEmail);
    }

    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDate = htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTime = htmlspecialchars($time, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeService = htmlspecialchars($serviceType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars(strtoupper($trackingCode), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $mail->isHTML(true);
    $mail->Subject = $rescheduled
        ? 'Registrar Appointment Rescheduled - PLMun Queue'
        : 'Registrar Appointment Booked - PLMun Queue';
    $mail->Body = '<h2>Appointment ' . ($rescheduled ? 'rescheduled' : 'booked') . '</h2>'
        . '<p><b>Name:</b> ' . $safeName . '</p>'
        . '<p><b>Service:</b> ' . $safeService . '</p>'
        . '<p><b>Date:</b> ' . $safeDate . '</p>'
        . '<p><b>Time:</b> ' . $safeTime . '</p>'
        . '<p><b>Tracking code:</b> ' . $safeCode . '</p>'
        . '<p>Status: ' . ($rescheduled ? 'Rescheduled' : 'Booked') . '</p>';
    $mail->AltBody = 'Appointment ' . ($rescheduled ? 'rescheduled' : 'booked')
        . "\nName: {$name}\nService: {$serviceType}"
        . "\nDate: {$date}\nTime: {$time}\nTracking code: {$safeCode}"
        . "\nStatus: " . ($rescheduled ? 'Rescheduled' : 'Booked');
    $mail->send();
}

try {
    $action = $_GET['action'] ?? '';

    if ($action === 'session') {
        requireMethod('GET');
        respond([
            'authenticated' => !empty($_SESSION['admin_email']),
            'email' => $_SESSION['admin_email'] ?? null,
        ]);
    }

    if ($action === 'student_session') {
        requireMethod('GET');
        respond([
            'authenticated' => !empty($_SESSION['student_id']),
            'name' => $_SESSION['student_name'] ?? null,
            'email' => $_SESSION['student_email'] ?? null,
        ]);
    }

    if ($action === 'student_register') {
        requireMethod('POST');
        $body = requestBody();
        $name = trim((string)($body['name'] ?? ''));
        $email = filter_var(trim((string)($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($body['password'] ?? '');
        if ($name === '' || strlen($name) > 120 || !$email || strlen($email) > 254
            || !str_ends_with(strtolower($email), STUDENT_EMAIL_DOMAIN)) {
            respond(['error' => 'Enter a valid name and institutional email address ending in '
                . STUDENT_EMAIL_DOMAIN . '.'], 422);
        }
        if (strlen($password) < 12 || strlen($password) > 1024) {
            respond(['error' => 'Choose a password between 12 and 1,024 characters.'], 422);
        }

        try {
            $insert = database()->prepare(
                'INSERT INTO students (name, email, password_hash)
                 VALUES (:name, :email, :password_hash)'
            );
            $insert->execute([
                'name' => $name,
                'email' => strtolower($email),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $error) {
            if (isDuplicateKeyViolation($error)) {
                respond(['error' => 'An account already exists for this email. Please sign in instead.'], 409);
            }
            throw $error;
        }

        session_regenerate_id(true);
        $_SESSION['student_id'] = (int)database()->lastInsertId();
        $_SESSION['student_name'] = $name;
        $_SESSION['student_email'] = strtolower($email);
        respond(['authenticated' => true, 'name' => $name, 'email' => strtolower($email)], 201);
    }

    if ($action === 'student_login') {
        requireMethod('POST');
        $body = requestBody();
        $email = filter_var(trim((string)($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($body['password'] ?? '');
        if (!$email || !str_ends_with(strtolower($email), STUDENT_EMAIL_DOMAIN)) {
            respond(['error' => 'Use an institutional email address ending in '
                . STUDENT_EMAIL_DOMAIN . '.'], 422);
        }

        $query = database()->prepare(
            'SELECT id, name, email, password_hash FROM students
             WHERE email = :email'
        );
        $query->execute(['email' => $email]);
        $student = $query->fetch();
        if (!$student || !password_verify($password, $student['password_hash'])) {
            respond(['error' => 'The institutional email or password is incorrect.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['student_id'] = (int)$student['id'];
        $_SESSION['student_name'] = $student['name'];
        $_SESSION['student_email'] = $student['email'];
        respond(['authenticated' => true, 'name' => $student['name'], 'email' => $student['email']]);
    }

    if ($action === 'student_logout') {
        requireMethod('POST');
        unset($_SESSION['student_id'], $_SESSION['student_name'], $_SESSION['student_email']);
        session_regenerate_id(true);
        respond(['logged_out' => true]);
    }

    if ($action === 'login') {
        requireMethod('POST');
        $localConfig = loadLocalConfig();
        $adminEmail = $localConfig['admin_email'] ?? getenv('PLMUN_ADMIN_EMAIL');
        $passwordHash = $localConfig['admin_password_hash'] ?? getenv('PLMUN_ADMIN_PASSWORD_HASH');
        if (!is_string($adminEmail) || !is_string($passwordHash)
            || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
            || !password_get_info($passwordHash)['algo']) {
            respond([
                'error' => 'Admin login is not configured. Open setup-admin.php on localhost to set it up.',
            ], 503);
        }

        $body = requestBody();
        $email = filter_var(trim((string)($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($body['password'] ?? '');
        if (!$email || !hash_equals(strtolower($adminEmail), strtolower($email))
            || !password_verify($password, $passwordHash)) {
            respond(['error' => 'The email or password is incorrect.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['admin_email'] = $adminEmail;
        respond(['authenticated' => true]);
    }

    if ($action === 'logout') {
        requireMethod('POST');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
        respond(['logged_out' => true]);
    }

    if ($action === 'slots') {
        requireMethod('GET');
        $date = validDate((string)($_GET['date'] ?? ''));
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        if (!$date || !isWeekday($date) || $date < $today || $date > $today->modify('+90 days')) {
            respond(['error' => 'Choose a weekday (Monday through Friday), excluding holidays, within the next 90 days.'], 422);
        }
        $holidayName = nonWorkingHolidayName($date);
        if ($holidayName !== null) {
            respond(['error' => "Appointments are unavailable on {$holidayName}."], 422);
        }
        $dateValue = $date->format('Y-m-d');
        $appointmentCount = countDailyAppointments(database(), $dateValue);
        $limitReached = $appointmentCount >= DAILY_APPOINTMENT_LIMIT;
        respond([
            'available' => $limitReached ? [] : availableSlots($dateValue),
            'daily_limit' => DAILY_APPOINTMENT_LIMIT,
            'daily_limit_reached' => $limitReached,
        ]);
    }

    if ($action === 'calendar') {
        requireMethod('GET');
        $timezone = new DateTimeZone('Asia/Manila');
        $today = new DateTimeImmutable('today', $timezone);
        $lastDate = $today->modify('+90 days');
        $now = new DateTimeImmutable('now', $timezone);
        $query = database()->prepare(
            "SELECT appointment_date FROM appointments
             WHERE appointment_date >= :today AND appointment_date <= :last_date
               AND status != 'cancelled'"
        );
        $query->execute([
            'today' => $today->format('Y-m-d'),
            'last_date' => $lastDate->format('Y-m-d'),
        ]);
        $dailyCounts = [];
        foreach ($query->fetchAll() as $appointment) {
            $dateValue = $appointment['appointment_date'];
            $dailyCounts[$dateValue] = ($dailyCounts[$dateValue] ?? 0) + 1;
        }

        $dates = [];
        $endExclusive = $lastDate->modify('+1 day');
        foreach (new DatePeriod($today, new DateInterval('P1D'), $endExclusive) as $date) {
            $dateValue = $date->format('Y-m-d');
            if (nonWorkingHolidayName($date) !== null) {
                $dates[$dateValue] = 'holiday';
                continue;
            }
            if (!isWeekday($date)) {
                $dates[$dateValue] = 'closed';
                continue;
            }

            $availableCount = 0;
            foreach (APPOINTMENT_SLOTS as $time) {
                if ($dateValue === $today->format('Y-m-d') && $time <= $now->format('H:i')) {
                    continue;
                }
                $availableCount++;
            }
            $dates[$dateValue] = $availableCount > 0
                && ($dailyCounts[$dateValue] ?? 0) < DAILY_APPOINTMENT_LIMIT
                ? 'available'
                : 'unavailable';
        }

        respond([
            'start' => $today->format('Y-m-d'),
            'end' => $lastDate->format('Y-m-d'),
            'dates' => $dates,
        ]);
    }

    if ($action === 'track') {
        requireMethod('POST');
        requireStudent();
        $body = requestBody();
        $trackingCode = strtoupper(trim((string)($body['tracking_code'] ?? '')));
        if (!isValidTrackingCode($trackingCode)) {
            respond(['error' => 'Enter a valid tracking code.'], 422);
        }

        $query = database()->prepare(
            'SELECT id, tracking_code, name, email, service_type, appointment_date, appointment_time, status
             FROM appointments
             WHERE tracking_code = :code AND email = :email'
        );
        $query->execute(['code' => strtolower($trackingCode), 'email' => $_SESSION['student_email']]);
        $appointment = $query->fetch();
        if (!$appointment) {
            respond(['error' => 'Appointment not found. Check the tracking code and the email on your account.'], 404);
        }
        $appointmentTime = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $appointment['appointment_date'] . ' ' . $appointment['appointment_time'],
            new DateTimeZone('Asia/Manila')
        );
        respond([
            'queue_number' => 'Q-' . str_pad((string)$appointment['id'], 4, '0', STR_PAD_LEFT),
            'tracking_code' => strtoupper($appointment['tracking_code']),
            'name' => $appointment['name'],
            'service_type' => $appointment['service_type'],
            'date' => $appointment['appointment_date'],
            'time' => DateTimeImmutable::createFromFormat('!H:i', $appointment['appointment_time'])->format('g:i A'),
            'status' => $appointment['status'],
            'can_reschedule' => $appointment['status'] === 'booked'
                && $appointmentTime
                && $appointmentTime > new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')),
        ]);
    }

    if ($action === 'cancel_booking') {
        requireMethod('POST');
        requireStudent();
        $body = requestBody();
        $trackingCode = strtoupper(trim((string)($body['tracking_code'] ?? '')));
        if (!isValidTrackingCode($trackingCode)) {
            respond(['error' => 'Enter a valid tracking code.'], 422);
        }

        $pdo = database();
        $query = $pdo->prepare(
            'SELECT id, appointment_date, appointment_time, status
             FROM appointments
             WHERE tracking_code = :code AND email = :email'
        );
        $query->execute(['code' => strtolower($trackingCode), 'email' => $_SESSION['student_email']]);
        $appointment = $query->fetch();
        if (!$appointment) {
            respond(['error' => 'Appointment not found. Check the tracking code and the email on your account.'], 404);
        }
        if ($appointment['status'] !== 'booked') {
            respond(['error' => 'This appointment can no longer be cancelled.'], 409);
        }
        $appointmentDateTime = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $appointment['appointment_date'] . ' ' . $appointment['appointment_time'],
            new DateTimeZone('Asia/Manila')
        );
        if (!$appointmentDateTime || $appointmentDateTime <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
            respond(['error' => 'Past or ongoing appointments can no longer be cancelled.'], 409);
        }

        $update = $pdo->prepare(
            "UPDATE appointments SET status = 'cancelled'
             WHERE id = :id AND status = 'booked'"
        );
        $update->execute(['id' => $appointment['id']]);
        if ($update->rowCount() !== 1) {
            respond(['error' => 'The appointment was already updated. Refresh its status.'], 409);
        }
        respond(['cancelled' => true]);
    }

    if ($action === 'reschedule') {
        requireMethod('POST');
        requireStudent();
        $body = requestBody();
        $trackingCode = strtoupper(trim((string)($body['tracking_code'] ?? '')));
        if (!isValidTrackingCode($trackingCode)) {
            respond(['error' => 'Enter a valid tracking code.'], 422);
        }

        $date = validDate((string)($body['date'] ?? ''));
        $timeLabel = (string)($body['time'] ?? '');
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        if (!$date || !isWeekday($date) || $date < $today || $date > $today->modify('+90 days')) {
            respond(['error' => 'Choose a weekday (Monday through Friday), excluding holidays, within the next 90 days.'], 422);
        }
        $holidayName = nonWorkingHolidayName($date);
        if ($holidayName !== null) {
            respond(['error' => "Appointments are unavailable on {$holidayName}."], 422);
        }
        if (!isset(APPOINTMENT_SLOTS[$timeLabel])) {
            respond(['error' => 'The selected time is invalid.'], 422);
        }

        $pdo = database();
        try {
            $pdo->beginTransaction();
            $query = $pdo->prepare(
                'SELECT id, name, email, service_type, appointment_date, appointment_time, status
                 FROM appointments
                 WHERE tracking_code = :code AND email = :email
                 FOR UPDATE'
            );
            $query->execute([
                'code' => strtolower($trackingCode),
                'email' => $_SESSION['student_email'],
            ]);
            $appointment = $query->fetch();
            if (!$appointment) {
                $pdo->rollBack();
                respond(['error' => 'Appointment not found. Check the tracking code and the email on your account.'], 404);
            }
            if ($appointment['status'] !== 'booked') {
                $pdo->rollBack();
                respond(['error' => 'Only booked appointments can be rescheduled.'], 409);
            }

            $appointmentDateTime = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i',
                $appointment['appointment_date'] . ' ' . $appointment['appointment_time'],
                new DateTimeZone('Asia/Manila')
            );
            if (!$appointmentDateTime || $appointmentDateTime <= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
                $pdo->rollBack();
                respond(['error' => 'Past or ongoing appointments can no longer be rescheduled.'], 409);
            }

            $dateValue = $date->format('Y-m-d');
            $timeValue = APPOINTMENT_SLOTS[$timeLabel];
            if ($appointment['appointment_date'] === $dateValue
                && $appointment['appointment_time'] === $timeValue) {
                $pdo->rollBack();
                respond(['error' => 'Choose a different date or time to reschedule.'], 422);
            }

            $datesToLock = array_values(array_unique([$appointment['appointment_date'], $dateValue]));
            sort($datesToLock, SORT_STRING);
            $insertLock = $pdo->prepare(
                'INSERT IGNORE INTO appointment_date_locks (booking_date) VALUES (:date)'
            );
            $lockDate = $pdo->prepare(
                'SELECT booking_date FROM appointment_date_locks
                 WHERE booking_date = :date FOR UPDATE'
            );
            foreach ($datesToLock as $dateToLock) {
                $insertLock->execute(['date' => $dateToLock]);
                $lockDate->execute(['date' => $dateToLock]);
                $lockDate->fetch();
            }

            if (countDailyAppointments($pdo, $dateValue) >= DAILY_APPOINTMENT_LIMIT) {
                $pdo->rollBack();
                respond(['error' => 'The 100-appointment limit has been reached for this date.'], 429);
            }
            if (!in_array($timeLabel, availableSlots($dateValue), true)) {
                $pdo->rollBack();
                respond(['error' => 'The selected time is no longer available. Please choose another time.'], 409);
            }

            $update = $pdo->prepare(
                "UPDATE appointments
                 SET appointment_date = :date, appointment_time = :time
                 WHERE id = :id AND status = 'booked'"
            );
            $update->execute([
                'date' => $dateValue,
                'time' => $timeValue,
                'id' => $appointment['id'],
            ]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                respond(['error' => 'The appointment was already updated. Refresh its status.'], 409);
            }
            $pdo->commit();
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isDuplicateKeyViolation($error)) {
                respond(['error' => 'A duplicate record prevented this appointment from being rescheduled. Please refresh and try again.'], 409);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $smtp = smtpConfiguration();
        if ($smtp['host'] === '' && $smtp['username'] === '' && $smtp['password'] === '') {
            respond([
                'rescheduled' => true,
                'date' => $dateValue,
                'time' => $timeLabel,
                'email_sent' => false,
                'email_warning' => 'The appointment was rescheduled, but email notifications are not configured.',
            ]);
        }
        try {
            sendAppointmentEmails(
                $appointment['name'],
                $appointment['email'],
                $dateValue,
                $timeLabel,
                $appointment['service_type'],
                $trackingCode,
                true
            );
            respond([
                'rescheduled' => true,
                'date' => $dateValue,
                'time' => $timeLabel,
                'email_sent' => true,
            ]);
        } catch (Throwable $mailError) {
            error_log('PLMun appointment reschedule email: ' . $mailError->getMessage());
            respond([
                'rescheduled' => true,
                'date' => $dateValue,
                'time' => $timeLabel,
                'email_sent' => false,
                'email_warning' => 'The appointment was rescheduled, but the email could not be sent. Check the SMTP settings.',
            ]);
        }
    }

    if ($action === 'reissue_tracking_codes') {
        requireMethod('POST');
        requireAdmin();
        $body = requestBody();
        if (($body['confirm'] ?? false) !== true) {
            respond(['error' => 'Confirm the tracking code replacement first.'], 422);
        }

        $pdo = database();
        $convertedCount = 0;
        try {
            $pdo->beginTransaction();
            $appointments = $pdo->query(
                'SELECT id, tracking_code FROM appointments ORDER BY id FOR UPDATE'
            )->fetchAll();
            $update = $pdo->prepare(
                'UPDATE appointments SET tracking_code = :tracking_code WHERE id = :id'
            );
            foreach ($appointments as $appointment) {
                if (preg_match('/^[A-Z]{2}[0-9]{6}$/', strtoupper((string)$appointment['tracking_code'])) === 1) {
                    continue;
                }
                $trackingCode = generateTrackingCode($pdo);
                $update->execute([
                    'tracking_code' => $trackingCode,
                    'id' => $appointment['id'],
                ]);
                $convertedCount++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        respond([
            'converted_count' => $convertedCount,
            'manual_export_url' => 'api.php?action=export_tracking_codes',
        ]);
    }

    if ($action === 'export_tracking_codes') {
        requireMethod('GET');
        requireAdmin();
        $rows = database()->query(
            'SELECT id, name, email, service_type, appointment_date, appointment_time, status, tracking_code
             FROM appointments ORDER BY appointment_date, appointment_time, id'
        );

        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plmun-tracking-codes.csv"');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Could not create the tracking code export.');
        }
        fputcsv($output, [
            'Queue number',
            'Name',
            'Email',
            'Service',
            'Appointment date',
            'Appointment time',
            'Status',
            'Tracking code',
        ]);
        foreach ($rows as $row) {
            $time = DateTimeImmutable::createFromFormat('!H:i', $row['appointment_time']);
            fputcsv($output, array_map('csvSafeCell', [
                'Q-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT),
                $row['name'],
                $row['email'],
                $row['service_type'],
                $row['appointment_date'],
                $time ? $time->format('g:i A') : $row['appointment_time'],
                $row['status'],
                strtoupper((string)$row['tracking_code']),
            ]));
        }
        fclose($output);
        exit;
    }

    if ($action === 'book') {
        requireMethod('POST');
        $body = requestBody();
        $isStudentBooking = !empty($_SESSION['student_id']);
        if ($isStudentBooking) {
            requireStudent();
            $name = (string)$_SESSION['student_name'];
            $email = (string)$_SESSION['student_email'];
        } else {
            requireAdmin();
            $name = trim((string)($body['name'] ?? ''));
            $email = filter_var(trim((string)($body['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        }
        $serviceType = trim((string)($body['service_type'] ?? ''));
        $date = validDate((string)($body['date'] ?? ''));
        $timeLabel = (string)($body['time'] ?? '');
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        $serviceTypeLimit = $isStudentBooking ? 1000 : 100;

        if ($name === '' || strlen($name) > 120 || !$email || strlen($email) > 254
            || $serviceType === '' || strlen($serviceType) > $serviceTypeLimit) {
            respond(['error' => 'Enter a valid name, email address, and service.'], 422);
        }
        if (!$date || !isWeekday($date) || $date < $today || $date > $today->modify('+90 days')) {
            respond(['error' => 'Choose a weekday (Monday through Friday), excluding holidays, within the next 90 days.'], 422);
        }
        $holidayName = nonWorkingHolidayName($date);
        if ($holidayName !== null) {
            respond(['error' => "Appointments are unavailable on {$holidayName}."], 422);
        }
        if (!isset(APPOINTMENT_SLOTS[$timeLabel])) {
            respond(['error' => 'The selected time is invalid.'], 422);
        }
        $dateValue = $date->format('Y-m-d');
        $trackingCode = '';
        $pdo = database();
        try {
            $pdo->beginTransaction();
            $lockDate = $pdo->prepare(
                'INSERT IGNORE INTO appointment_date_locks (booking_date) VALUES (:date)'
            );
            $lockDate->execute(['date' => $dateValue]);
            $lockDate = $pdo->prepare(
                'SELECT booking_date FROM appointment_date_locks
                 WHERE booking_date = :date FOR UPDATE'
            );
            $lockDate->execute(['date' => $dateValue]);
            $lockDate->fetch();

            if (countDailyAppointments($pdo, $dateValue) >= DAILY_APPOINTMENT_LIMIT) {
                $pdo->rollBack();
                respond([
                    'error' => 'The 100-appointment limit has been reached for this date.',
                ], 429);
            }
            if (!in_array($timeLabel, availableSlots($dateValue), true)) {
                $pdo->rollBack();
                respond(['error' => 'The selected time is no longer available. Please choose another time.'], 409);
            }

            $trackingCode = generateTrackingCode($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO appointments
                    (name, email, appointment_date, appointment_time, service_type, tracking_code)
                 VALUES (:name, :email, :date, :time, :service_type, :tracking_code)'
            );
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'date' => $dateValue,
                'time' => APPOINTMENT_SLOTS[$timeLabel],
                'service_type' => $serviceType,
                'tracking_code' => $trackingCode,
            ]);
            $appointmentId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (isDuplicateKeyViolation($error)) {
                respond(['error' => 'A duplicate record prevented this appointment from being saved. Please try again.'], 409);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $smtp = smtpConfiguration();
        if ($smtp['host'] === '' && $smtp['username'] === '' && $smtp['password'] === '') {
            respond([
                'id' => $appointmentId,
                'tracking_code' => strtoupper($trackingCode),
                'email_sent' => false,
                'email_warning' => 'The appointment was recorded, but email notifications are not configured.',
            ], 201);
        }
        try {
            sendAppointmentEmails($name, (string)$email, $dateValue, $timeLabel, $serviceType, $trackingCode);
            respond([
                'id' => $appointmentId,
                'tracking_code' => strtoupper($trackingCode),
                'email_sent' => true,
            ], 201);
        } catch (Throwable $mailError) {
            error_log('PLMun appointment email: ' . $mailError->getMessage());
            respond([
                'id' => $appointmentId,
                'tracking_code' => strtoupper($trackingCode),
                'email_sent' => false,
                'email_warning' => 'The appointment was recorded, but the email could not be sent. Check the SMTP settings.',
            ], 201);
        }
    }

    if ($action === 'dashboard') {
        requireMethod('GET');
        requireAdmin();
        $pdo = database();
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $summary = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('booked', 'checked_in') THEN 1 ELSE 0 END) AS waiting,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_show
             FROM appointments WHERE appointment_date = :date"
        );
        $summary->execute(['date' => $today]);
        $counts = $summary->fetch();
        $rows = $pdo->prepare(
            "SELECT id, name, appointment_time AS time, office, service_type, status
             FROM appointments WHERE appointment_date = :date
             ORDER BY appointment_time, id"
        );
        $rows->execute(['date' => $today]);
        $appointments = array_map(static function (array $row): array {
            $row['time'] = DateTimeImmutable::createFromFormat('!H:i', $row['time'])->format('g:i A');
            return $row;
        }, $rows->fetchAll());
        $upcoming = $pdo->prepare(
            "SELECT id, name, appointment_date, appointment_time AS time, service_type, status
             FROM appointments
             WHERE appointment_date > :today
               AND appointment_date <= :last_date
               AND status = 'booked'
             ORDER BY appointment_date, appointment_time, id"
        );
        $upcoming->execute([
            'today' => $today,
            'last_date' => (new DateTimeImmutable($today, new DateTimeZone('Asia/Manila')))
                ->modify('+90 days')->format('Y-m-d'),
        ]);
        $upcomingAppointments = array_map(static function (array $row): array {
            $row['date'] = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $row['appointment_date'],
                new DateTimeZone('Asia/Manila')
            )->format('M j, Y');
            unset($row['appointment_date']);
            $row['time'] = DateTimeImmutable::createFromFormat('!H:i', $row['time'])->format('g:i A');
            return $row;
        }, $upcoming->fetchAll());
        respond([
            'total_today' => (int)$counts['total'],
            'waiting_today' => (int)$counts['waiting'],
            'completed_today' => (int)$counts['completed'],
            'cancelled_today' => (int)$counts['cancelled'],
            'no_show_today' => (int)$counts['no_show'],
            'appointments' => $appointments,
            'upcoming_appointments' => $upcomingAppointments,
        ]);
    }

    if ($action === 'update_status' || $action === 'complete') {
        requireMethod('POST');
        requireAdmin();
        $body = requestBody();
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            respond(['error' => 'The queue number is invalid.'], 422);
        }
        $newStatus = $action === 'complete' ? 'completed' : (string)($body['status'] ?? '');
        if (!in_array($newStatus, ['checked_in', 'completed', 'cancelled', 'no_show'], true)) {
            respond(['error' => 'The new status is invalid.'], 422);
        }
        $pdo = database();
        $existing = $pdo->prepare(
            'SELECT appointment_date, appointment_time, status FROM appointments WHERE id = :id'
        );
        $existing->execute(['id' => $id]);
        $appointment = $existing->fetch();
        if (!$appointment || !in_array($appointment['status'], ['booked', 'checked_in'], true)) {
            respond(['error' => 'This appointment can no longer be updated. Refresh the queue.'], 409);
        }
        if ($newStatus === 'checked_in') {
            $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
            if ($appointment['status'] !== 'booked' || $appointment['appointment_date'] !== $today->format('Y-m-d')) {
                respond(['error' => 'Only a booked appointment for today can be checked in.'], 409);
            }
        } elseif ($newStatus === 'no_show' && $appointment['status'] !== 'booked') {
            respond(['error' => 'A checked-in appointment cannot be marked as a no-show.'], 409);
        }
        if ($newStatus === 'no_show') {
            $appointmentDateTime = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i',
                $appointment['appointment_date'] . ' ' . $appointment['appointment_time'],
                new DateTimeZone('Asia/Manila')
            );
            if (!$appointmentDateTime || $appointmentDateTime > new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) {
                respond(['error' => 'An appointment can only be marked as a no-show after its scheduled time.'], 422);
            }
        }
        $update = database()->prepare(
            'UPDATE appointments SET status = :status
             WHERE id = :id AND status = :current_status'
        );
        $update->execute([
            'id' => $id,
            'status' => $newStatus,
            'current_status' => $appointment['status'],
        ]);
        if ($update->rowCount() !== 1) {
            respond(['error' => 'This appointment can no longer be updated. Refresh the queue.'], 409);
        }
        respond(['updated' => true, 'status' => $newStatus]);
    }

    if ($action === 'reports') {
        requireMethod('GET');
        requireAdmin();
        $startDate = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))
            ->modify('-6 days')->format('Y-m-d');
        $query = database()->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status IN ('booked', 'checked_in') THEN 1 ELSE 0 END) AS waiting,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_show
             FROM appointments
             WHERE appointment_date >= :start AND appointment_date <= :today"
        );
        $query->execute([
            'start' => $startDate,
            'today' => (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
        ]);
        $counts = $query->fetch();
        respond([
            'total' => (int)$counts['total'],
            'completed' => (int)$counts['completed'],
            'waiting' => (int)$counts['waiting'],
            'cancelled' => (int)$counts['cancelled'],
            'no_show' => (int)$counts['no_show'],
        ]);
    }

    respond(['error' => 'Unknown request.'], 404);
} catch (Throwable $error) {
    error_log('PLMun queue system: ' . $error->getMessage());
    respond(['error' => 'A server error occurred. Check the PHP error log.'], 500);
}
