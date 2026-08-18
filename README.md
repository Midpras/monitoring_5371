# SE2026 Monitoring

Dashboard publik dan halaman administrasi untuk memantau progres harian Sensus Ekonomi 2026.

Teknologi utama:

- Laravel 13
- PHP 8.4 pada Docker
- Svelte
- SQLite
- Docker dan Apache

Dashboard publik tidak memerlukan login. Login hanya diperlukan untuk upload snapshot, menghapus snapshot, dan mengelola akun admin.

## URL aplikasi

- / - dashboard publik
- /admin/login - login admin
- /admin - upload dan riwayat snapshot
- /admin/users - pengelolaan akun admin
- /healthz - health check aplikasi

## Konfigurasi admin

Akun admin pertama dibuat dari tiga environment variable berikut:

~~~env
ADMIN_NAME=Administrator
ADMIN_EMAIL=admin@example.org
ADMIN_PASSWORD=password-minimal-8-karakter
~~~

Atur nilainya di file .env lokal, environment variables Coolify, atau environment variables platform lain.

Perilaku bootstrap:

- Jika email belum ada, aplikasi membuat akun admin baru.
- Jika email sudah ada, password tidak ditimpa.
- Menjalankan seeder berulang kali aman untuk akun yang sudah ada.
- Semua akun yang dibuat dari halaman /admin/users memiliki role admin.
- Admin tidak dapat menghapus akun yang sedang digunakan.

Jangan commit file .env dan jangan menyimpan file tersebut di dalam document root publik. Untuk cPanel, file .env harus berada di root project Laravel, satu tingkat di atas folder public.

## Deployment dengan cPanel

Metode ini digunakan untuk hosting yang tidak menyediakan Docker. Pastikan paket hosting menyediakan Terminal atau SSH, Composer, dan PHP CLI.

### 1. Persyaratan server

Pastikan cPanel menyediakan:

- PHP 8.3 atau lebih baru
- Composer
- PHP CLI
- Ekstensi PHP Laravel dan PhpSpreadsheet, terutama mbstring, fileinfo, openssl, pdo, pdo_sqlite, xml, zip, dan gd
- Folder storage dan bootstrap/cache yang dapat ditulis oleh PHP

Pilih versi PHP dan ekstensi melalui MultiPHP atau EasyApache. Lihat dokumentasi [cPanel PHP](https://docs.cpanel.net/ea4/php/about-php/) dan [Composer di cPanel](https://docs.cpanel.net/knowledge-base/web-services/how-to-set-up-php-composer/).

Versi aplikasi saat ini menggunakan SQLite. Jika hosting tidak menyediakan pdo_sqlite, jangan langsung mengganti DB_CONNECTION ke MySQL karena migration saat ini menggunakan index partial SQLite. Dukungan MySQL perlu disiapkan melalui perubahan migration terlebih dahulu.

### 2. Upload source code

Upload repository ke home directory, bukan langsung ke public_html. Contoh:

~~~text
/home/USERNAME/se2026-monitoring/
~~~

Source Laravel berada di:

~~~text
/home/USERNAME/se2026-monitoring/app/
~~~

Atur document root domain atau subdomain ke folder berikut:

~~~text
/home/USERNAME/se2026-monitoring/app/public
~~~

Document root harus menunjuk ke app/public, bukan ke root repository dan bukan ke folder app secara langsung. cPanel menggunakan application source path relatif terhadap home directory akun. Lihat [cPanel Application Manager](https://docs.cpanel.net/cpanel/software/application-manager/).

### 3. Install dependency

Jika Composer dan Node.js tersedia di cPanel Terminal:

~~~bash
cd /home/USERNAME/se2026-monitoring/app
composer install --no-dev --optimize-autoloader
npm ci
npm run build
~~~

Jika Node.js tidak tersedia, jalankan build di komputer lokal:

~~~bash
cd app
npm ci
npm run build
composer install --no-dev --optimize-autoloader
~~~

Setelah itu upload folder public/build dan vendor bersama source code ke cPanel. Folder public/build tidak disimpan di Git karena dibuat saat proses build Docker.

### 4. Buat file .env

Salin app/.env.example menjadi app/.env, kemudian sesuaikan nilainya:

~~~env
APP_NAME="SE2026 Monitoring"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://monitoring.example.org
APP_KEY=base64:GENERATED_LARAVEL_KEY

APP_LOCALE=id
APP_FALLBACK_LOCALE=id

DB_CONNECTION=sqlite
DB_DATABASE=/home/USERNAME/se2026-monitoring/app/storage/app/database/progress.sqlite
DB_FOREIGN_KEYS=true
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=wal
DB_SYNCHRONOUS=normal

SESSION_DRIVER=file
SESSION_LIFETIME=120
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

ADMIN_NAME=Administrator
ADMIN_EMAIL=admin@example.org
ADMIN_PASSWORD=ganti-dengan-password-kuat
~~~

Path SQLite harus berupa path absolut dan folder induknya harus dapat ditulis:

~~~bash
mkdir -p storage/app/database
touch storage/app/database/progress.sqlite
chmod -R u+rwX storage bootstrap/cache
~~~

Jika APP_KEY belum ada, buat dengan:

~~~bash
php artisan key:generate --force
~~~

Simpan file .env dengan permission yang aman, misalnya 600, jika hosting mengizinkannya:

~~~bash
chmod 600 .env
~~~

### 5. Jalankan migration dan buat admin pertama

Jalankan dari root aplikasi Laravel:

~~~bash
cd /home/USERNAME/se2026-monitoring/app
php artisan migrate --force
php artisan db:seed --force
~~~

Seeder hanya membuat admin jika ADMIN_EMAIL dan ADMIN_PASSWORD terisi. Setelah berhasil login, password dapat diubah dari /admin/users.

Jika ingin menjadikan file .env hanya sebagai konfigurasi sementara, hapus atau kosongkan tiga variable berikut setelah seeding:

~~~env
ADMIN_NAME=
ADMIN_EMAIL=
ADMIN_PASSWORD=
~~~

Akun yang sudah dibuat tetap tersimpan di database. Mengubah ADMIN_PASSWORD kemudian menjalankan seeder tidak mereset password akun yang sudah ada.

### 6. Verifikasi

Buka URL berikut:

~~~text
https://monitoring.example.org/healthz
https://monitoring.example.org/
https://monitoring.example.org/admin/login
~~~

Health check harus mengembalikan:

~~~json
{"status":"ok"}
~~~

Login dengan email dan password dari file .env, lalu buka /admin/users untuk membuat akun admin tambahan.

## Deployment dengan Coolify dan Docker

### 1. Buat application

Di Coolify:

1. Buat Application dari repository Git ini.
2. Gunakan Dockerfile sebagai build pack.
3. Set exposed port ke 80.
4. Hubungkan domain.
5. Tambahkan persistent volume:

~~~text
Mount path: /var/www/html/storage
~~~

### 2. Tambahkan environment variables

~~~text
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
~~~

Entrypoint Docker menjalankan migration secara otomatis. Seeder dijalankan ketika ADMIN_EMAIL dan ADMIN_PASSWORD tersedia.

Gunakan recreate deployment karena SQLite berada di persistent volume. Jangan mengaktifkan rolling update untuk deployment ini.

Health check:

~~~text
Path: /healthz
~~~

Snapshot yang dihapus bersifat permanen. Upload yang ditolak akan dibersihkan setelah validasi, dan versi snapshot lama dibersihkan setelah tiga hari ketika halaman admin dibuka.

## Menjalankan Docker secara lokal

Build image:

~~~bash
docker build -t se2026-monitoring:test .
~~~

Buat volume untuk menyimpan database:

~~~bash
docker volume create se2026-local-storage
~~~

Jalankan container:

~~~bash
docker run -d \
  --name se2026-local \
  --restart unless-stopped \
  -p 8080:80 \
  -e APP_ENV=local \
  -e APP_DEBUG=true \
  -e APP_KEY=base64:replace-me \
  -e ADMIN_NAME=Local\ Admin \
  -e ADMIN_EMAIL=admin@local.test \
  -e ADMIN_PASSWORD=change-me \
  -v se2026-local-storage:/var/www/html/storage \
  se2026-monitoring:test
~~~

Buka:

~~~text
http://localhost:8080/
http://localhost:8080/admin/login
~~~

Perintah bantuan:

~~~bash
docker logs -f se2026-local
docker restart se2026-local
docker stop se2026-local
docker rm se2026-local
~~~

Jangan menghapus volume se2026-local-storage jika ingin mempertahankan database dan snapshot.

## Pengelolaan data

- Upload snapshot dilakukan dari /admin.
- Sebelum impor, file divalidasi.
- Baris invalid membuat upload ditolak dan ditampilkan kepada admin.
- Penghapusan snapshot menghapus data snapshot secara permanen.
- Dashboard publik menampilkan data tanpa login.
- User management hanya tersedia untuk admin.

## Troubleshooting

### Login admin gagal setelah deployment

Pastikan:

1. ADMIN_EMAIL dan ADMIN_PASSWORD terisi di file .env.
2. php artisan db:seed --force sudah dijalankan.
3. APP_KEY sudah terisi.
4. Database dan folder storage dapat ditulis.

Jika email admin sudah ada, seeder tidak mengganti passwordnya. Gunakan password lama atau ubah password dari halaman user management setelah login.

### Error could not find driver

Aktifkan pdo_sqlite pada PHP yang digunakan domain dan PHP CLI. Pastikan versi PHP untuk web dan Terminal sama.

### Halaman menampilkan 404 atau file CSS tidak ada

Pastikan:

- Document root mengarah ke app/public.
- Folder public/build sudah di-upload.
- Rewrite Apache aktif dan file app/public/.htaccess tidak terhapus.

### Database tidak tersimpan setelah restart Docker

Pastikan container memakai persistent volume berikut:

~~~text
/var/www/html/storage
~~~

Tanpa volume tersebut, SQLite dan file upload berada di dalam container dan dapat hilang saat container diganti.
