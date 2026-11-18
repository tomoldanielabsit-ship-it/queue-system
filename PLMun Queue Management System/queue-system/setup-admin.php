<?php
declare(strict_types=1);

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
    exit('Admin setup is available only on this computer.');
}

date_default_timezone_set('Asia/Manila');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['admin_setup_token'])) {
    $_SESSION['admin_setup_token'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['token'] ?? '');
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');

    if (!hash_equals($_SESSION['admin_setup_token'], $token)) {
        http_response_code(403);
        $errorMessage = 'The setup form expired. Refresh the page and try again.';
    } elseif (!$email || strlen($email) > 254) {
        $errorMessage = 'Enter a valid email address.';
    } elseif (strlen($password) < 12 || strlen($password) > 1024) {
        $errorMessage = 'Choose a password between 12 and 1,024 characters.';
    } elseif (!hash_equals($password, $confirmation)) {
        $errorMessage = 'The passwords do not match.';
    } else {
        $config = loadLocalConfig();
        $config['admin_email'] = $email;
        $config['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        if (!saveLocalConfig($config)) {
            $errorMessage = 'Could not write config.local.php. Check the folder permissions.';
        } else {
            unset($_SESSION['admin_setup_token']);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set Admin Login | PLMun Queue System</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
  <main class="login-container">
    <img src="images/plmun-logo.jpg" alt="PLMun Logo" class="login-logo">
    <h1>Set Admin Login</h1>
    <p class="subtitle">Local setup only. Your password is saved as a secure hash.</p>

    <?php if ($success): ?>
      <p class="message" role="status">Admin login is set up. Sign in with the email address and password you entered.</p>
      <a class="setup-link" href="login.html">Go to Admin Login</a>
    <?php else: ?>
      <?php if ($errorMessage !== ''): ?>
        <p class="message error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['admin_setup_token'], ENT_QUOTES, 'UTF-8') ?>">
        <label for="email">Admin email</label>
        <input type="email" id="email" name="email" autocomplete="username" maxlength="254" required>
        <label for="password">New password (at least 12 characters)</label>
        <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" maxlength="1024" required>
        <label for="password_confirmation">Confirm new password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="1024" required>
        <button type="submit">Save Admin Login</button>
      </form>
      <a class="setup-link" href="login.html">Back to Admin Login</a>
    <?php endif; ?>
  </main>
  <script src="password-toggle.js"></script>
</body>
</html>
