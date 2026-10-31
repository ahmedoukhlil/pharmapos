<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#065f46">
    <link rel="manifest" href="/manifest.webmanifest">
    <title>Scanner — PharmaPOS</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-900 text-white antialiased">
<main id="app" class="mx-auto flex min-h-screen max-w-md flex-col gap-3 p-3"
      data-session="{{ $session->id }}"
      data-state="{{ $state }}"
      data-status-url="{{ route('scanner.status', $session) }}"
      data-scan-url="{{ route('scanner.scan', $session) }}"
      data-last-sequence="{{ $session->last_sequence }}">

    <header class="flex items-center gap-2">
        <span class="font-semibold">Scanner · caisse {{ $session->register?->code }}</span>
        <span id="badge" class="ms-auto rounded-full bg-slate-700 px-2 py-0.5 text-xs">…</span>
    </header>

    <section id="waiting" class="hidden rounded-lg bg-slate-800 p-4 text-center">
        <p class="text-lg">Confirmez l'association sur l'écran de la caisse.</p>
        <p class="mt-2 text-sm text-slate-400">Cette page se mettra à jour automatiquement.</p>
    </section>

    <section id="closed" class="hidden rounded-lg bg-red-900/60 p-4 text-center">
        <p class="text-lg">Scanner déconnecté.</p>
        <p class="mt-2 text-sm">Générez un nouveau QR sur la caisse pour réassocier ce téléphone.</p>
    </section>

    <section id="scanner" class="hidden flex-col gap-3">
        <div id="frame" class="relative overflow-hidden rounded-lg border-4 border-slate-700 bg-black">
            <video id="video" class="aspect-[4/3] w-full object-cover" playsinline muted></video>
            <div class="pointer-events-none absolute inset-x-8 top-1/2 h-0.5 -translate-y-1/2 bg-red-500/80"></div>
            <p id="camera-msg" class="absolute inset-0 hidden items-center justify-center p-4 text-center text-sm"></p>
        </div>

        <div class="flex gap-2">
            <button id="torch" class="hidden rounded-lg bg-slate-700 px-3 py-3 text-sm">Flash</button>
            <button id="again" class="flex-1 rounded-lg bg-emerald-700 px-3 py-3 font-semibold disabled:opacity-40" disabled>+1 même produit</button>
        </div>

        <form id="manual" class="flex gap-2">
            <input id="manual-code" inputmode="numeric" autocomplete="off" placeholder="Saisie manuelle du code"
                   class="min-w-0 flex-1 rounded-lg border border-slate-600 bg-slate-800 px-3 py-3 font-mono text-white">
            <button class="rounded-lg bg-slate-700 px-4 py-3">Envoyer</button>
        </form>

        <p id="pending" class="hidden text-sm text-amber-300"></p>
        <ol id="log" class="space-y-1 text-sm"></ol>
    </section>
</main>

<script>
(() => {
    const app = document.getElementById('app');
    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const key = (k) => `pharmapos.scanner.${app.dataset.session}.${k}`;
    const store = {
        get: (k, d) => { try { return JSON.parse(localStorage.getItem(key(k))) ?? d; } catch { return d; } },
        set: (k, v) => { try { localStorage.setItem(key(k), JSON.stringify(v)); } catch {} },
    };

    // --- État de l'association -------------------------------------------------
    let state = app.dataset.state;
    function render() {
        $('badge').textContent = { active: 'Connecté', awaiting_confirmation: 'En attente', revoked: 'Déconnecté', expired: 'Expiré' }[state] ?? state;
        $('waiting').classList.toggle('hidden', state !== 'awaiting_confirmation');
        $('closed').classList.toggle('hidden', state === 'active' || state === 'awaiting_confirmation');
        $('scanner').classList.toggle('hidden', state !== 'active');
        $('scanner').classList.toggle('flex', state === 'active');
        if (state === 'active') startCamera();
        if (state !== 'active') stopCamera();
    }
    async function pollState() {
        try {
            const r = await fetch(app.dataset.statusUrl, { headers: { Accept: 'application/json' } });
            if (r.ok) {
                const data = await r.json();
                state = data.state;
                store.set('seq', Math.max(store.get('seq', 0), data.last_sequence));
                render();
            }
        } catch {}
        setTimeout(pollState, state === 'awaiting_confirmation' ? 1500 : 10000);
    }

    // --- File d'envoi (SCN-05) : identifiant unique, séquence, accusé, renvoi ----
    store.set('seq', Math.max(store.get('seq', 0), Number(app.dataset.lastSequence)));
    let queue = store.get('queue', []);
    let sending = false;

    function enqueue(barcode) {
        const seq = store.get('seq', 0) + 1;
        store.set('seq', seq);
        queue.push({ id: crypto.randomUUID(), sequence: seq, barcode, scanned_at: new Date().toISOString() });
        store.set('queue', queue);
        flush();
    }

    async function flush() {
        if (sending || !queue.length || state !== 'active') return showPending();
        sending = true;
        const scan = queue[0];
        try {
            const r = await fetch(app.dataset.scanUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(scan),
            });
            const data = await r.json().catch(() => ({}));
            if (r.status === 410) { state = 'revoked'; render(); }
            else if (r.ok || r.status === 422) {
                queue.shift();
                store.set('queue', queue);
                log(scan.barcode, r.ok ? data : { status: 'error', message: data.message });
            }
            else throw new Error(r.status);
        } catch {
            sending = false;
            showPending();
            return setTimeout(flush, 2000);   // réseau local indisponible : on réessaie
        }
        sending = false;
        flush();
    }

    function showPending() {
        $('pending').classList.toggle('hidden', queue.length === 0);
        $('pending').textContent = `${queue.length} scan(s) en attente d'envoi…`;
    }

    function log(code, ack) {
        const ok = ack.status === 'received';
        const li = document.createElement('li');
        li.className = `rounded px-2 py-1 ${ok ? 'bg-emerald-900/60' : 'bg-red-900/60'}`;
        li.textContent = ok ? `✓ ${ack.product ?? code}` : `✗ ${code} — ${ack.status === 'unknown_product' ? 'produit inconnu' : (ack.message ?? 'refusé')}`;
        $('log').prepend(li);
        while ($('log').children.length > 15) $('log').lastChild.remove();
        feedback(ok);
    }

    // --- Retour visuel et sonore (SCN-01) ---------------------------------------
    let audio;
    function feedback(ok) {
        try {
            audio ??= new AudioContext();
            const o = audio.createOscillator(), g = audio.createGain();
            o.frequency.value = ok ? 1200 : 300; g.gain.value = 0.1;
            o.connect(g).connect(audio.destination); o.start(); o.stop(audio.currentTime + (ok ? 0.08 : 0.3));
        } catch {}
        navigator.vibrate?.(ok ? 60 : [80, 60, 80]);
        $('frame').classList.replace('border-slate-700', ok ? 'border-emerald-500' : 'border-red-500');
        setTimeout(() => $('frame').classList.remove('border-emerald-500', 'border-red-500') || $('frame').classList.add('border-slate-700'), 400);
    }

    // --- Lecture : caméra (BarcodeDetector) ou saisie manuelle (SCN-06) ---------
    let lastCode = null, lastAt = 0;
    const DEBOUNCE_MS = 2000;   // SCN-03 : même code relu par la caméra ignoré pendant 2 s

    function detected(code, intentional = false) {
        const now = Date.now();
        if (!intentional && code === lastCode && now - lastAt < DEBOUNCE_MS) return;
        lastCode = code; lastAt = now;
        $('again').disabled = false;
        enqueue(code);
    }

    $('again').addEventListener('click', () => lastCode && detected(lastCode, true));   // SCN-04
    $('manual').addEventListener('submit', (e) => {
        e.preventDefault();
        const code = $('manual-code').value.trim();
        if (code) detected(code, true);
        $('manual-code').value = '';
    });

    let stream, timer, track;
    function cameraMessage(text) {
        $('camera-msg').textContent = text;
        $('camera-msg').classList.replace('hidden', 'flex');
    }
    async function startCamera() {
        if (stream || timer === false) return;
        if (!window.isSecureContext || !navigator.mediaDevices) {
            timer = false;
            return cameraMessage("Caméra indisponible : la page doit être ouverte en HTTPS. Utilisez la saisie manuelle.");
        }
        if (!('BarcodeDetector' in window)) {
            timer = false;
            return cameraMessage("Ce navigateur ne sait pas lire les codes-barres (utilisez Chrome sur Android) : saisie manuelle.");
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        } catch {
            timer = false;
            return cameraMessage("Accès à la caméra refusé : autorisez-le dans le navigateur ou utilisez la saisie manuelle.");
        }
        $('video').srcObject = stream;
        await $('video').play();
        track = stream.getVideoTracks()[0];
        if (track.getCapabilities?.().torch) {
            $('torch').classList.remove('hidden');
            let on = false;
            $('torch').onclick = () => track.applyConstraints({ advanced: [{ torch: (on = !on) }] });
        }
        const detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'code_128'] });
        timer = setInterval(async () => {
            try {
                const codes = await detector.detect($('video'));
                if (codes[0]?.rawValue) detected(codes[0].rawValue);
            } catch {}
        }, 250);
    }
    function stopCamera() {
        if (timer) clearInterval(timer);
        stream?.getTracks().forEach((t) => t.stop());
        stream = null; if (timer !== false) timer = undefined;
    }

    window.addEventListener('online', flush);
    render();
    pollState();
    flush();
})();
</script>
</body>
</html>
