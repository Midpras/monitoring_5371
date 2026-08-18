<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f69039">
    <link rel="icon" type="image/png" href="/logo-se2026.png">
    <title>Admin Upload - SE2026 Monitoring</title>
    @vite(['resources/css/app.css'])
</head>
<body class="auth-page">
    <main class="login-shell">
        <section class="login-welcome">
            <img class="login-logo" src="/logo-se2026.png" alt="Logo Sensus Ekonomi 2026">
            <p class="eyebrow">SE2026 KUPANG</p>
            <h1>Data lapangan, siap ditindaklanjuti.</h1>
            <p>Kelola snapshot capaian secara teratur, mulai dari validasi hingga publikasi dashboard.</p>
            <span class="login-context">Area administrator</span>
        </section>
        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-heading">
                <p class="eyebrow">MASUK KE SISTEM</p>
                <h2 id="login-title">Upload snapshot.</h2>
                <p class="muted">Gunakan akun admin untuk memvalidasi dan mengaktifkan data terbaru.</p>
            </div>
            <form method="post" action="{{ route('login') }}" class="login-form">
                @csrf
                <label>Email<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"></label>
                @error('email')<p class="form-error">{{ $message }}</p>@enderror
                <label>Kata sandi<input type="password" name="password" required autocomplete="current-password"></label>
                @error('password')<p class="form-error">{{ $message }}</p>@enderror
                <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))><span>Ingat sesi ini di perangkat ini</span></label>
                <button type="submit" class="accent-button">Masuk admin</button>
            </form>
            <p class="login-note">Sesi admin tetap aktif selama perangkat ini mengingat akun Anda.</p>
        </section>
    </main>
</body>
</html>
