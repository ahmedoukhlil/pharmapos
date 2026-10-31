<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/images/icon-64.png">
    <link rel="apple-touch-icon" href="/images/icon-192.png">
    <title>Scanner — PharmaPOS</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-900 p-4 text-white">
    <div class="max-w-sm rounded-lg bg-red-900/60 p-5 text-center">
        <p class="text-lg font-semibold">Association impossible</p>
        <p class="mt-2">{{ $message }}</p>
    </div>
</body>
</html>
