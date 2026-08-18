<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f69039">
    <link rel="icon" type="image/png" href="/logo-se2026.png">
    <title>Kelola Pengguna - SE2026 Monitoring</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-page">
    <div id="admin-users"></div>
</body>
</html>
