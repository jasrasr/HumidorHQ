# Automatic Daily Login Backups

HumidorHQ creates one verified runtime backup after the first successful login of each local calendar day, regardless of which active user signs in.

## Behavior

- The trigger is any successful `POST /api/login` request.
- Failed or rate-limited logins never trigger a backup.
- A server-side exclusive lock prevents simultaneous logins from creating duplicate backups.
- The date boundary uses `HUMIDORHQ_TIMEZONE` and falls back to the application's existing default.
- Backup creation runs during PHP shutdown, after the successful login response has been generated.
- Backup failures are written to the PHP error log and never invalidate an otherwise successful login.
- The state marker is updated only after a bundle has been built, integrity-validated, and written atomically.
- Automatic backups retain the most recent 14 successful daily bundles.
- Manual and pre-restore backups are not deleted by automatic retention.

## Files

- `api/.user.ini` enables the PHP-FPM auto-prepend hook for API requests.
- `api/auto-backup-prepend.php` registers the successful-login shutdown callback.
- `backups/.automatic-daily-login-state.json` is generated at runtime and records the latest successful date and filename.
- `backups/.automatic-daily-login.lock` is generated at runtime and serializes competing login requests.

Automatic bundles retain the normal `manual` filename class for compatibility with the existing list, download, preview, and restore validation. They include this additional metadata:

```json
{
  "automation": {
    "trigger": "first-successful-login",
    "localDate": "2026-07-20",
    "timezone": "America/New_York"
  }
}
```

## Hostinger

Hostinger's PHP-FPM deployment reads `.user.ini` files. The `auto_prepend_file` value is relative to the API directory and loads `auto-backup-prepend.php` before `api/index.php`.

PHP caches `.user.ini` configuration. After deployment, the new setting may take several minutes to become active. Restarting PHP from hPanel, when available, applies it sooner.

## Verification

1. Confirm no automatic backup exists for today's local date.
2. Sign in with either configured user.
3. Confirm a new `humidorhq-manual-*.json` bundle appears under `backups/`.
4. Open the bundle and confirm `automation.trigger` is `first-successful-login`.
5. Sign out and sign in with the other user on the same day.
6. Confirm no second automatic bundle is created.
7. Change or remove the runtime state marker only in a test deployment, then submit two successful logins concurrently and confirm only one new bundle is produced.
8. Make the backup directory temporarily unwritable in a test deployment and confirm login still succeeds while PHP records an error.
