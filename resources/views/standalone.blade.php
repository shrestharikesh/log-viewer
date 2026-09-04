@php
    /** @var array $config */
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full" x-data="{ dark: matchMedia('(prefers-color-scheme: dark)').matches }" :class="{ 'dark': dark }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $config['title'] ?? 'Log Explorer' }}</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    <link rel="stylesheet" href="{{ asset('vendor/log-explorer/log-explorer.css') }}">
</head>
<body class="h-full bg-gray-100 dark:bg-gray-950 text-gray-900 dark:text-gray-100 antialiased">
    <div class="h-full p-4">
        <x-log-explorer height="calc(100vh - 2rem)" />
    </div>
</body>
</html>
