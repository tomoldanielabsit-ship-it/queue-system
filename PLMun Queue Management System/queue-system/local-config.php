<?php
declare(strict_types=1);

function loadLocalConfig(): array
{
    $path = __DIR__ . '/config.local.php';
    if (!is_file($path)) {
        return [];
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Invalid config.local.php format.');
    }
    return $config;
}

function saveLocalConfig(array $config): bool
{
    $contents = "<?php\nreturn " . var_export($config, true) . ";\n";
    return file_put_contents(__DIR__ . '/config.local.php', $contents, LOCK_EX) !== false;
}

function smtpConfiguration(): array
{
    $config = loadLocalConfig();
    $host = $config['smtp_host'] ?? getenv('PLMUN_SMTP_HOST');
    $username = $config['smtp_username'] ?? getenv('PLMUN_SMTP_USERNAME');
    $password = $config['smtp_password'] ?? getenv('PLMUN_SMTP_PASSWORD');
    $portValue = $config['smtp_port'] ?? (getenv('PLMUN_SMTP_PORT') ?: '587');
    $recipient = $config['notification_email']
        ?? ($config['admin_email'] ?? getenv('PLMUN_ADMIN_EMAIL'));

    return [
        'host' => is_string($host) ? trim($host) : '',
        'username' => is_string($username) ? trim($username) : '',
        'password' => is_string($password) ? $password : '',
        'port' => filter_var($portValue, FILTER_VALIDATE_INT),
        'recipient' => is_string($recipient) ? trim($recipient) : '',
    ];
}
