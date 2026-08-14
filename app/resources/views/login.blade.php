<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Upload - SE2026 Monitoring</title>
    @vite(['resources/css/app.css'])
</head>
<body class="auth-page">
    <main class="login-panel">
        <p class="eyebrow">SE2026 KUPANG</p>
        <h1>Admin upload data.</h1>
        <p class="muted">Masuk untuk memvalidasi dan mengaktifkan snapshot Excel terbaru.</p>
        <form method="post" action="{{ route('login') }}" class="login-form">
            @csrf
            <label>Email<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"></label>
            <label>Kata sandi<input type="password" name="password" required autocomplete="current-password"></label>
            <label class="check"><input type="checkbox" name="remember" value="1"> Ingat sesi ini</label>
            @error('email')<p class="form-error">{{ $message }}</p>@enderror
            <button type="submit" class="primary-button">Masuk admin</button>
        </form>
    </main>
</body>
</html>
