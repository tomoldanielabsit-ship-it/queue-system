<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/local-config.php';

$allowedAddresses = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$host = strtolower((string)parse_url(
    'http://' . ($_SERVER['HTTP_HOST'] ?? ''),
    PHP_URL_HOST
));

if (!in_array($remoteAddress, $allowedAddresses, true)
    || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    http_response_code(403);
    exit('Email settings are available only on this computer.');
}

date_default_timezone_set('Asia/Manila');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['smtp_settings_token'])) {
    $_SESSION['smtp_settings_token'] = bin2hex(random_bytes(32));
}

$config = loadLocalConfig();
$smtpConfigured = !empty($config['smtp_username']) && !empty($config['smtp_password']);
$senderEmail = (string)($config['smtp_username'] ?? '');
$recipientEmail = (string)($config['notification_email'] ?? $config['admin_email'] ?? '');
$errorMessage = '';
$successMessage = '';
$settingsSaved = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['token'] ?? '');
    $senderEmail = trim((string)($_POST['sender_email'] ?? ''));
    $validSenderEmail = filter_var($senderEmail, FILTER_VALIDATE_EMAIL);
    $recipientEmail = trim((string)($_POST['recipient_email'] ?? ''));
    $validRecipientEmail = filter_var($recipientEmail, FILTER_VALIDATE_EMAIL);
    $submittedPassword = preg_replace('/\s+/', '', (string)($_POST['app_password'] ?? ''));
    $existingPassword = (string)($config['smtp_password'] ?? '');

    if (!hash_equals($_SESSION['smtp_settings_token'], $token)) {
        http_response_code(403);
        $errorMessage = 'The settings form expired. Refresh the page and try again.';
    } elseif (!$validSenderEmail || !$validRecipientEmail) {
        $errorMessage = 'Enter valid sender and notification email addresses.';
    } elseif ($submittedPassword !== '' && !preg_match('/^[A-Za-z0-9]{16}$/', $submittedPassword)) {
        $errorMessage = 'Enter the 16-character Google App Password. Spaces are removed automatically.';
    } elseif ($submittedPassword === '' && ($existingPassword === ''
        || strtolower($senderEmail) !== strtolower((string)($config['smtp_username'] ?? '')))) {
        $errorMessage = 'A new App Password is required if none is saved or if you change the Gmail account.';
    } else {
        $config['smtp_host'] = 'smtp.gmail.com';
        $config['smtp_port'] = 587;
        $config['smtp_username'] = strtolower($senderEmail);
        $config['smtp_password'] = $submittedPassword !== '' ? $submittedPassword : $existingPassword;
        $config['notification_email'] = strtolower($recipientEmail);

        if (!saveLocalConfig($config)) {
            $errorMessage = 'Could not write config.local.php. Check the folder permissions.';
        } else {
            $settingsSaved = true;
            $smtpConfigured = true;
            unset($_SESSION['smtp_settings_token']);

            try {
                require_once __DIR__ . '/PHPMailer-master/src/Exception.php';
                require_once __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
                require_once __DIR__ . '/PHPMailer-master/src/SMTP.php';

                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = strtolower($senderEmail);
                $mail->Password = $config['smtp_password'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->Timeout = 10;
                $mail->CharSet = PHPMailer::CHARSET_UTF8;
                $mail->setFrom(strtolower($senderEmail), 'PLMun Queue System');
                $mail->addAddress(strtolower($recipientEmail));
                $mail->Subject = 'PLMun Queue System - SMTP test';
                $mail->Body = 'This is a test email. Gmail notifications are configured for the PLMun Queue System.';
                $mail->AltBody = 'This is a test email. Gmail notifications are configured for the PLMun Queue System.';
                $mail->send();
                $successMessage = 'Gmail settings were saved and the test email was sent to the notification address.';
            } catch (PHPMailer\Exception $mailError) {
                error_log('PLMun SMTP test: ' . $mailError->getMessage());
                $errorMessage = 'Settings were saved, but the test email could not be sent. Check the Gmail address, App Password, and 2-Step Verification.';
            }
        }
    }
}

if (empty($_SESSION['smtp_settings_token'])) {
    $_SESSION['smtp_settings_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gmail Notifications | PLMun Queue System</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
  <main class="login-container">
    <img src="images/plmun-logo.jpg" alt="PLMun Logo" class="login-logo">
    <h1>Configure Gmail Notifications</h1>
    <p class="subtitle">Local computer only. The App Password is stored in the protected local configuration and is never displayed.</p>

    <?php if ($successMessage !== ''): ?>
      <p class="message" role="status"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php elseif ($errorMessage !== ''): ?>
      <p class="message error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($settingsSaved && $errorMessage !== ''): ?>
      <p class="message" role="status">Settings have been saved. You can try the test email again after correcting the credentials.</p>
    <?php endif; ?>

    <?php if ($smtpConfigured): ?>
      <p class="privacy-note">Gmail SMTP settings are saved. Leave the App Password blank if you do not want to change it.</p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['smtp_settings_token'], ENT_QUOTES, 'UTF-8') ?>">
      <label for="sender_email">Gmail sender address</label>
      <input type="email" id="sender_email" name="sender_email" value="<?= htmlspecialchars($senderEmail, ENT_QUOTES, 'UTF-8') ?>" maxlength="254" autocomplete="username" required>
      <label for="app_password">Google App Password (16 characters)</label>
      <input type="password" id="app_password" name="app_password" maxlength="32" autocomplete="new-password" placeholder="<?= $smtpConfigured ? 'Leave blank to keep current App Password' : 'Paste the 16-character App Password' ?>" <?= $smtpConfigured ? '' : 'required' ?>>
      <label for="recipient_email">Registrar notification recipient</label>
      <input type="email" id="recipient_email" name="recipient_email" value="<?= htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8') ?>" maxlength="254" autocomplete="email" required>
      <button type="submit">Save and send test email</button>
    </form>

    <p class="footer-text">Create an App Password at <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">Google Account App Passwords</a>. Google 2-Step Verification must be on.</p>
    <a class="setup-link" href="login.html">Back to Admin Login</a>
  </main>
  <script src="password-toggle.js"></script>
</body>
</html>
