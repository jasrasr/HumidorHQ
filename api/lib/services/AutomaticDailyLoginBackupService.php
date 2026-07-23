<?php
declare(strict_types=1);
/*
 * Filename: AutomaticDailyLoginBackupService.php
 * Revision: 1.0.0
 * Description: Creates one validated runtime backup after the first successful login of each application day.
 * Modified Date: 2026-07-20 21:00 ET
 */

function automatic_login_backup_state_path(): string
{
    return DATA_ROOT . DIRECTORY_SEPARATOR . '.automatic-login-backup-state.json';
}

function automatic_login_backup_lock_path(): string
{
    return DATA_ROOT . DIRECTORY_SEPARATOR . '.automatic-login-backup.lock';
}

function automatic_login_backup_local_date(): string
{
    return (new DateTimeImmutable('now', application_timezone()))->format('Y-m-d');
}

function automatic_login_backup_load_state(): array
{
    $path = automatic_login_backup_state_path();
    if (!is_file($path)) {
        return [
            'lastAttemptAtUtc' => null,
            'lastSuccessAtUtc' => null,
            'lastSuccessDate' => null,
            'lastFilename' => null,
            'lastSha256' => null,
            'automaticBackups' => [],
        ];
    }

    $raw = file_get_contents($path);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state)) {
        throw new ApiError('AUTOMATIC_BACKUP_STATE_INVALID', 'Automatic backup state could not be read.', 500);
    }
    $state['automaticBackups'] = is_array($state['automaticBackups'] ?? null)
        ? array_values($state['automaticBackups'])
        : [];
    return $state;
}

function automatic_login_backup_save_state(array $state): void
{
    $path = automatic_login_backup_state_path();
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)
        || file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false
        || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new ApiError('AUTOMATIC_BACKUP_STATE_WRITE_FAILED', 'Automatic backup state could not be saved.', 500);
    }
    @chmod($path, 0600);
}

function automatic_login_backup_retention_days(): int
{
    $raw = trim((string) getenv('HUMIDORHQ_AUTOMATIC_BACKUP_RETENTION_DAYS'));
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) {
        return 30;
    }
    return max(7, min(365, (int) $raw));
}

function automatic_login_backup_apply_retention(array &$state): void
{
    $entries = array_values(array_filter(
        $state['automaticBackups'] ?? [],
        static fn (mixed $entry): bool => is_array($entry)
            && is_string($entry['filename'] ?? null)
            && is_string($entry['localDate'] ?? null)
    ));

    usort($entries, static fn (array $left, array $right): int => strcmp(
        (string) ($right['createdAtUtc'] ?? ''),
        (string) ($left['createdAtUtc'] ?? '')
    ));

    $keep = automatic_login_backup_retention_days();
    foreach (array_slice($entries, $keep) as $entry) {
        $filename = (string) $entry['filename'];
        try {
            $safe = backup_safe_filename($filename);
            $path = backup_directory() . DIRECTORY_SEPARATOR . $safe;
            if (is_file($path) && !unlink($path)) {
                error_log('HumidorHQ could not remove expired automatic backup: ' . $filename);
            }
        } catch (Throwable $error) {
            error_log('HumidorHQ ignored an invalid automatic backup retention entry: ' . $error->getMessage());
        }
    }

    $state['automaticBackups'] = array_slice($entries, 0, $keep);
}

function run_automatic_daily_login_backup(): ?array
{
    $today = automatic_login_backup_local_date();
    $state = automatic_login_backup_load_state();
    if (($state['lastSuccessDate'] ?? null) === $today) {
        return null;
    }

    $lock = fopen(automatic_login_backup_lock_path(), 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new ApiError('AUTOMATIC_BACKUP_LOCK_FAILED', 'Automatic backup could not obtain its lock.', 500);
    }

    try {
        $state = automatic_login_backup_load_state();
        if (($state['lastSuccessDate'] ?? null) === $today) {
            return null;
        }

        $state['lastAttemptAtUtc'] = now_iso();
        automatic_login_backup_save_state($state);

        // Keep the established, downloadable filename format. The state file identifies
        // which manual-format bundles were generated automatically and are retention-managed.
        $result = create_runtime_backup('manual');
        $file = backup_directory() . DIRECTORY_SEPARATOR . backup_safe_filename((string) $result['filename']);
        $sha256 = hash_file('sha256', $file);
        if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new ApiError('AUTOMATIC_BACKUP_HASH_FAILED', 'Automatic backup checksum could not be calculated.', 500);
        }

        $entry = [
            'localDate' => $today,
            'createdAtUtc' => (string) $result['createdAtUtc'],
            'filename' => (string) $result['filename'],
            'sha256' => $sha256,
            'sourceFingerprint' => (string) $result['sourceFingerprint'],
        ];
        $state['lastSuccessAtUtc'] = $entry['createdAtUtc'];
        $state['lastSuccessDate'] = $today;
        $state['lastFilename'] = $entry['filename'];
        $state['lastSha256'] = $sha256;
        $state['automaticBackups'][] = $entry;
        automatic_login_backup_apply_retention($state);
        automatic_login_backup_save_state($state);

        audit_record('Backup & Restore', 'automatic daily login backup completed', [
            'filename' => $entry['filename'],
            'localDate' => $today,
            'sha256' => $sha256,
        ]);
        return $entry;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function attempt_automatic_daily_login_backup(): void
{
    if (getenv('HUMIDORHQ_AUTOMATIC_LOGIN_BACKUP') === '0') {
        return;
    }
    try {
        run_automatic_daily_login_backup();
    } catch (Throwable $error) {
        error_log('HumidorHQ automatic daily login backup failed: ' . $error->getMessage());
        try {
            audit_record('Backup & Restore', 'automatic daily login backup failed', [
                'error' => $error instanceof ApiError ? $error->getMessage() : 'Unexpected backup failure',
            ]);
        } catch (Throwable) {
            // Login must remain available even when both backup and audit logging fail.
        }
    }
}
