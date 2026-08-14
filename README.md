# SE2026 Monitoring

Laravel 13, PHP 8.4, and Svelte dashboard for versioned daily SE2026 progress snapshots.

## Coolify deployment

Create an **Application** from this Git repository with the **Dockerfile** build pack. Set the exposed port to `80`, assign the domain in Coolify, and add one persistent volume mounted at `/var/www/html/storage`.

Set these Coolify environment variables:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain
APP_KEY=<generated Laravel app key>
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/storage/app/database/progress.sqlite
DB_FOREIGN_KEYS=true
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=wal
DB_SYNCHRONOUS=normal
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
ADMIN_NAME=Administrator
ADMIN_EMAIL=admin@example.org
ADMIN_PASSWORD=<strong unique password>
```

Set a `768MiB` memory limit, configure `/healthz` as the health check, and use recreate deployments. Do not enable rolling updates because SQLite lives on the mounted volume.

Add a Coolify daily scheduled task that runs:

```text
/usr/local/bin/backup-sqlite
```

It retains seven native SQLite backups in the persistent volume. Keep provider-level VPS snapshots enabled for off-server recovery.

## Local run

```text
docker build -t se2026-monitoring .
docker run --rm -p 8080:80 -e APP_KEY=base64:replace-me -e ADMIN_EMAIL=admin@example.org -e ADMIN_PASSWORD=change-me se2026-monitoring
```

The first container start creates the SQLite database, runs migrations, and creates the Admin only when both admin credentials are supplied.
