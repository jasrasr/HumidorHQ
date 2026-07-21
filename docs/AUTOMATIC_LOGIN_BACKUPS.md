# Automatic Daily Login Backups

HumidorHQ creates one runtime backup after the first successful authenticated login of each application-local calendar day. Any active user may trigger the backup. The behavior is not tied to a username or role.

## Behavior

- Invalid and rate-limited login attempts never trigger a backup.
- The successful login audit event invokes the automatic backup service after the authenticated session is established.
- A server-side lock and a second date check prevent duplicate backups when users log in simultaneously.
- Backup failures are logged but never block a valid login.
- A failed attempt leaves the last-success date unchanged, so the next successful login retries the backup.
- The date boundary uses `HUMIDORHQ_TIMEZONE` through the application's existing timezone helper.

## Storage and state

Automatic backups use the existing validated runtime bundle implementation and remain in `backups/`. They retain the established `humidorhq-manual-*` filename format so existing listing, validation, download, preview, and restore routes continue to accept them.

The runtime-only state file is:

```text
data/.automatic-login-backup-state.json
```

It records the last attempt, last success, local backup date, filename, SHA-256 checksum, source fingerprint, and the automatic-backup retention list. The state file is not included in portable backup bundles.

The runtime-only lock file is:

```text
data/.automatic-login-backup.lock
```

When `HUMIDORHQ_DATA_ROOT` is set, both files are stored in that external runtime directory instead.

## Configuration

Automatic login backups are enabled by default.

Disable them with:

```text
HUMIDORHQ_AUTOMATIC_LOGIN_BACKUP=0
```

The default retention is 30 automatic backups. Set a value from 7 through 365 with:

```text
HUMIDORHQ_AUTOMATIC_BACKUP_RETENTION_DAYS=30
```

Retention deletes only filenames recorded by the automatic-backup state file. Manually created backups and pre-restore safety backups are not deleted by this process.

## Operational limitation

This is usage-triggered scheduling. A day with no successful login produces no backup. These files also remain on the same hosting account, so a separate encrypted off-server copy is still required for disaster recovery.
