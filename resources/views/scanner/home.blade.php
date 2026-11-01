<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#065f46">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" href="/images/icon-64.png">
    <link rel="apple-touch-icon" href="/images/icon-192.png">
    <title>Scanner — PharmaPOS</title>
    @vite(['resources/css/app.css', 'resources/js/barcode.js'])
</head>
<body class="min-h-screen bg-slate-900 text-white antialiased">
<main class="mx-auto flex min-h-screen max-w-md flex-col gap-3 p-3">
    <header class="flex items-center gap-2">
        <img src="/images/icon-64.png" alt="" class="h-7 w-7 rounded">
        <span class="font-semibold">PharmaPOS Scanner</span>
    </header>

    <p class="text-sm text-slate-300">
        Sur le PC, ouvrez la caisse, l'inventaire ou la réception et cliquez sur « Scanner téléphone »,
        puis visez le QR code affiché.
    </p>

    <div class="relative overflow-hidden rounded-lg border-4 border-slate-700 bg-black">
        <video id="video" class="aspect-square w-full object-cover" playsinline muted></video>
        <p id="msg" class="absolute inset-0 hidden items-center justify-center p-4 text-center text-sm"></p>
    </div>

    <button id="start" class="rounded-lg bg-emerald-700 px-3 py-3 font-semibold">Scanner le QR code</button>

    <form id="manual" class="flex gap-2">
        <input id="link" type="url" inputmode="url" placeholder="…ou collez le lien du QR"
               class="min-w-0 flex-1 rounded-lg border-0 bg-slate-800 px-3 py-3 text-sm text-white placeholder:text-slate-500">
        <button class="rounded-lg bg-slate-700 px-3 py-3 text-sm">Ouvrir</button>
    </form>
</main>

<script>
(() => {
    const video = document.getElementById('video');
    const msg = (t) => { const m = document.getElementById('msg'); m.textContent = t; m.classList.replace('hidden', 'flex'); };

    // Seuls les liens d'appairage de ce serveur sont suivis
    function open(value) {
        try {
            const url = new URL(value, location.origin);
            if (url.origin === location.origin && url.pathname.startsWith('/scanner/appairer/')) {
                location.href = url.href;
                return true;
            }
        } catch {}
        return false;
    }

    document.getElementById('manual').onsubmit = (e) => {
        e.preventDefault();
        if (!open(document.getElementById('link').value.trim())) msg("Ce lien n'est pas un QR d'appairage PharmaPOS.");
    };

    let running = false;
    async function start() {
        if (running) return;
        running = true;
        document.getElementById('start').classList.add('hidden');
        if (!window.isSecureContext || !navigator.mediaDevices) return msg('Caméra indisponible : la page doit être ouverte en HTTPS.');
        for (let i = 0; !window.barcodeDetectorReady && i < 50; i++) await new Promise((r) => setTimeout(r, 100));
        if (!(await window.barcodeDetectorReady?.catch(() => null))) return msg('Lecteur indisponible sur ce navigateur : collez le lien.');
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        } catch {
            return msg("Accès à la caméra refusé : autorisez-le dans les réglages du téléphone.");
        }
        video.srcObject = stream;
        await video.play();
        const detector = new BarcodeDetector({ formats: ['qr_code'] });
        let busy = false;
        const timer = setInterval(async () => {
            if (busy || video.readyState < 2) return;
            busy = true;
            try {
                const codes = await detector.detect(video);
                for (const c of codes) {
                    if (open(c.rawValue)) { clearInterval(timer); stream.getTracks().forEach((t) => t.stop()); return; }
                }
                if (codes.length) msg("Ce QR code n'est pas un QR d'appairage PharmaPOS.");
            } catch {} finally { busy = false; }
        }, 250);
    }

    document.getElementById('start').onclick = start;
    start();   // l'autorisation caméra déjà accordée démarre directement ; sinon le bouton reste disponible
})();
</script>
</body>
</html>
