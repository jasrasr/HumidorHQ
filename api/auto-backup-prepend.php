<?php
declare(strict_types=1);
/*
 * Filename: auto-backup-prepend.php
 * Revision: 1.0.0
 * Description: Creates one verified runtime backup after the first successful login of each local day.
 * Modified Date: 2026-07-20 21:00 ET
 */

if (PHP_SAPI === 'cli') {
    return;
}

$humidorHqRequestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$humidorHqRequestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$humidorHqRequestPath = parse_url($humidorHqRequestUri, PHP_URL_PATH);
$humidorHqIsLoginRequest = $humidorHqRequestMethod === 'POST'
    && is_string($humidorHqRequestPath)
    && preg_match('#/api(?:/index\.php)?/login/?$#', $humidorHqRequestPath) === 1;

if (!$humidorHqIsLoginRequest) {
    return;
}

register_shutdown_function(static function (): void {
    try {
        $status = http_response_code();
        if ($status < 200 || $status >= 300) {
            return;
        }
        if (!function_exists('current_auth_user') || current_auth_user() === null) {
            return;
        }
        if (!function_exists('backup_directory')
            || !function_exists('backup_build_bundle')
            || !function_exists('backup_validate_bundle')
            || !function_exists('backup_write_bundle')
            || !function_exists('application_timezone')) {
            error_log('HumidorHQ automatic backup skipped because required services were unavailable.');
            return;
        }

        $directory = backup_directory();
        $lockPath = $directory . DIRECTORY_SEPARATOR . '.automatic-daily-login.lock';
        $statePath = $directory . DIRECTORY_SEPARATOR . '.automatic-daily-login-state.json';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to obtain the automatic backup lock.');
        }

        try {
            $today = (new DateTimeImmutable('now', application_timezone()))->format('Y-m-d');
            $state = [];
            if (is_file($statePath)) {
                $rawState = file_get_contents($statePath);
                $decodedState = is_string($rawState) ? json_decode($rawState, true) : null;
                if (is_array($decodedState)) {
                    $state = $decodedState;
                }
            }
            if (($state['lastSuccessDate'] ?? null) === $today) {
                return;
            }

            $bundle = backup_build_bundle('manual');
            $bundle['automation'] = [
                'trigger' => 'first-successful-login',
                'localDate' => $today,
                'timezone' => application_timezone()->getName(),
            ];
            backup_validate_bundle($bundle);
            $result = backup_write_bundle($bundle);

            $state = [
                'lastSuccessDate' => $today,
                'lastSuccessAtUtc' => (string) ($result['createdAtUtc'] ?? ''),
                'filename' => (string) ($result['filename'] ?? ''),
                'sourceFingerprint' => (string) ($result['sourceFingerprint'] ?? ''),
            ];
            $temporary = $statePath . '.tmp.' . bin2hex(random_bytes(6));
            $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)
                || file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false
                || !rename($temporary, $statePath)) {
                @unlink($temporary);
                throw new RuntimeException('Automatic backup state could not be written atomically.');
            }
            @chmod($statePath, 0600);

            $automaticBackups = [];
            foreach (glob($directory . DIRECTORY_SEPARATOR . 'humidorhq-manual-*.json') ?: [] as $path) {
                $raw = file_get_contents($path);
                $candidate = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($candidate)
                    && (($candidate['automation']['trigger'] ?? null) === 'first-successful-login')) {
                    $automaticBackups[] = [
                        'path' => $path,
                        'createdAtUtc' => (string) ($candidate['createdAtUtc'] ?? ''),
                    ];
                }
            }
            usort(
                $automaticBackups,
                static fn (array $left, array $right): int => strcmp($right['createdAtUtc'], $left['createdAtUtc'])
            );
            foreach (array_slice($automaticBackups, 14) as $expired) {
                @unlink((string) $expired['path']);
            }

            if (function_exists('audit_record')) {
                audit_record('Backup & Restore', 'automatic daily login backup', [
                    'filename' => $state['filename'],
                    'localDate' => $today,
                    'trigger' => 'first-successful-login',
                ]);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $error) {
        error_log('HumidorHQ automatic daily login backup failed: ' . $error->getMessage());
    }
});
