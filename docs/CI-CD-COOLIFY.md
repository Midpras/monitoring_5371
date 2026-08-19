# CI/CD GitHub Actions dan Coolify

Dokumen ini menjelaskan alur deployment aplikasi monitoring melalui satu environment Coolify.

## Alur deployment

- `dev`: menjalankan pemeriksaan CI, tanpa deployment.
- Pull request ke `main`: menjalankan pemeriksaan CI.
- Push ke `main`: menjalankan CI, build Docker production, lalu meminta Coolify melakukan deployment.
- Coolify tetap mengambil source dari GitHub dan membangun `Dockerfile` di repository.

Workflow ada di [.github/workflows/ci-cd.yml](../.github/workflows/ci-cd.yml).

GitHub Actions hanya memastikan request deployment diterima Coolify. Status build, health check, dan rollback harus diperiksa di halaman deployment Coolify.

## 1. Konfigurasi application di Coolify

Buat satu Application dari repository GitHub ini, lalu gunakan pengaturan berikut:

| Pengaturan | Nilai |
| --- | --- |
| Branch | `main` |
| Build Pack | Dockerfile |
| Base Directory | `/` |
| Dockerfile Location | `/Dockerfile` |
| Port | `80` |
| Health Check Path | `/healthz` |
| Health Check Port | `80` |
| Replicas | `1` |

Tambahkan persistent volume berikut:

```text
Mount path: /var/www/html/storage
```

Volume ini wajib agar database SQLite, file snapshot, dan log tidak hilang saat container dibuat ulang. Karena menggunakan SQLite, gunakan satu replica dan matikan rolling update untuk application ini.

Matikan Auto Deploy di Coolify. Deployment production hanya dipicu oleh workflow setelah CI dan Docker build berhasil.

## 2. Environment variables Coolify

Masukkan variable berikut pada runtime environment Coolify. Jangan memasukkan password production ke repository.

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://monitoring.example.org
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
ADMIN_PASSWORD=<password minimal 8 karakter>
```

`ADMIN_EMAIL` dan `ADMIN_PASSWORD` hanya digunakan entrypoint untuk membuat atau memperbarui admin default saat container mulai. Setelah login, kelola akun dari halaman admin.

## 3. GitHub Actions secrets

Di GitHub buka `Settings > Secrets and variables > Actions`, lalu buat repository secrets:

| Secret | Isi |
| --- | --- |
| `COOLIFY_DEPLOY_WEBHOOK` | Deploy webhook application dari Coolify |
| `COOLIFY_API_TOKEN` | API token Coolify dengan izin deploy |

Di Coolify, aktifkan API access, buat token deploy, lalu salin deploy webhook dari halaman Configuration application. Simpan kedua nilai hanya sebagai GitHub Secrets.

Jangan menyimpan `APP_KEY`, `ADMIN_PASSWORD`, atau variable runtime lain di workflow. Variable tersebut tetap berada di Coolify.

## 4. Cara kerja workflow

Job `verify` menjalankan:

1. PHPUnit/Laravel test dengan PHP 8.4 dan SQLite.
2. Instalasi dependency frontend dengan `npm ci`.
3. Build frontend dengan `npm run build`.

Pada push ke `main`, job `docker-build` membangun Dockerfile production. Jika berhasil, job `deploy` memanggil deploy webhook Coolify menggunakan bearer token.

Jika salah satu job gagal, job berikutnya tidak berjalan. Tidak ada image yang dipush ke registry; Coolify membangun image dari commit `main` setelah menerima webhook.

## 5. Alur kerja harian

```text
Perubahan -> push dev -> CI
         -> pull request main -> CI + review
         -> merge main -> CI -> Docker build -> webhook Coolify -> deployment
```

Untuk mencegah perubahan langsung masuk production, aktifkan branch protection pada `main` dan wajibkan pull request serta status check `Verify application`.

Tidak perlu approval manual tambahan: merge ke `main` adalah persetujuan deployment sesuai alur satu-environment yang dipilih.

## 6. Pengujian pertama

1. Simpan environment variables dan persistent volume di Coolify.
2. Pastikan health check `/healthz` menghasilkan HTTP 200.
3. Buat dua GitHub Secrets yang diperlukan.
4. Push perubahan ke `dev` dan pastikan job `Verify application` berhasil.
5. Buat pull request ke `main`, lalu merge setelah check berhasil.
6. Buka GitHub Actions dan pastikan urutannya `verify -> docker-build -> deploy`.
7. Buka deployment logs di Coolify dan tunggu status healthy.
8. Uji login admin dan upload snapshot Excel.

Jika deploy webhook gagal, periksa URL webhook, API token, izin token, dan konektivitas Coolify. Jika webhook berhasil tetapi deployment gagal, periksa build/runtime logs di Coolify.

## 7. Operasional dan batasan

- Push ke `dev` tidak mengubah aplikasi production.
- Hanya push ke `main` yang memicu deployment.
- Database dan upload tetap berada di volume `/var/www/html/storage`.
- Gunakan satu replica karena aplikasi menggunakan SQLite.
- Perubahan `APP_KEY` dapat membuat session atau data terenkripsi lama tidak dapat dibaca; jangan menggantinya tanpa alasan.
- Perubahan password admin setelah deployment dilakukan melalui halaman admin.

## Referensi resmi

- [Coolify Dockerfile build pack](https://coolify.io/docs/applications/build-packs/dockerfile)
- [Coolify health checks](https://coolify.io/docs/knowledge-base/health-checks)
- [Coolify persistent storage](https://coolify.io/docs/knowledge-base/persistent-storage)
- [Coolify GitHub Actions](https://coolify.io/docs/applications/ci-cd/github/actions/)
- [Coolify manual deploy webhooks](https://next.coolify.io/docs/applications/deployments/manual-webhooks)
