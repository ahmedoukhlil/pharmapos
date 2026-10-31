<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="/images/icon-64.png">
    <link rel="apple-touch-icon" href="/images/icon-192.png">
    <title>{{ $title ?? 'PharmaPOS' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
    {{ $slot }}

    {{-- Fenêtre de confirmation qui remplace confirm() pour wire:confirm (resources/js/confirm-dialog.js) --}}
    <dialog id="confirm-dialog" class="m-auto w-full max-w-md rounded-xl p-0 shadow-2xl backdrop:bg-slate-900/50">
        <form method="dialog" class="space-y-5 p-5">
            <p data-confirm-message class="whitespace-pre-line text-slate-800"></p>
            <div class="flex justify-end gap-2">
                <button value="cancel" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Retour</button>
                <button value="ok" data-confirm-ok class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Confirmer</button>
            </div>
        </form>
    </dialog>
</body>
</html>
