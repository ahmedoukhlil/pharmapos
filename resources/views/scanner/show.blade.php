<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#065f46">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" href="/images/icon-64.png">
    <link rel="apple-touch-icon" href="/images/icon-192.png">
    <title>Scanner - PharmaPOS</title>
    @vite(['resources/css/app.css', 'resources/js/barcode.js'])
</head>
<body class="min-h-screen bg-slate-900 text-white antialiased">
<main id="app" class="mx-auto flex min-h-screen max-w-md flex-col gap-3 p-3"
      data-session="{{ $session->id }}"
      data-state="{{ $state }}"
      data-status-url="{{ route('scanner.status', $session) }}"
      data-scan-url="{{ route('scanner.scan', $session) }}"
      data-lookup-url="{{ route('scanner.lookup', $session) }}"
      data-search-url="{{ route('scanner.search', $session) }}"
      data-link-url="{{ route('scanner.link', $session) }}"
      data-mode="{{ $session->mode }}"
      data-target="{{ \App\Services\Scanner\ScannerService::target($session) }}"
      data-last-sequence="{{ $session->last_sequence }}">

    <header class="flex items-center gap-2">
        <span class="font-semibold">{{ $title }}</span>
        <span id="badge" class="ms-auto rounded-full bg-slate-700 px-2 py-0.5 text-xs">…</span>
    </header>

    <section id="waiting" class="hidden rounded-lg bg-slate-800 p-4 text-center">
        <p class="text-lg">Confirmez l'association sur l'écran du PC.</p>
        <p class="mt-2 text-sm text-slate-400">Cette page se mettra à jour automatiquement.</p>
    </section>

    <section id="closed" class="hidden rounded-lg bg-red-900/60 p-4 text-center">
        <p class="text-lg">Scanner déconnecté.</p>
        <p class="mt-2 text-sm">Générez un nouveau QR sur le PC pour réassocier ce téléphone.</p>
    </section>

    <section id="scanner" class="hidden flex-col gap-3">
        <div id="frame" class="relative overflow-hidden rounded-lg border-4 border-slate-700 bg-black">
            <video id="video" class="aspect-[4/3] w-full object-cover" playsinline muted></video>
            <div class="pointer-events-none absolute inset-x-8 top-1/2 h-0.5 -translate-y-1/2 bg-red-500/80"></div>
            <p id="camera-msg" class="absolute inset-0 hidden items-center justify-center p-4 text-center text-sm"></p>
        </div>

        <div class="flex gap-2">
            <button id="torch" class="hidden rounded-lg bg-slate-700 px-3 py-3 text-sm">Flash</button>
            @if ($session->mode === 'pos')
                <button id="again" class="flex-1 rounded-lg bg-emerald-700 px-3 py-3 font-semibold disabled:opacity-40" disabled>+1 même produit</button>
            @endif
        </div>

        {{-- Modes inventaire et réception : fiche du produit scanné --}}
        <form id="entry" class="hidden flex-col gap-3 rounded-lg bg-slate-800 p-3">
            <div>
                <p id="entry-name" class="font-semibold"></p>
                <p id="entry-sub" class="text-xs text-slate-400"></p>
            </div>

            @if ($session->mode === 'inventory')
                <fieldset id="entry-lines" class="flex flex-col gap-2"></fieldset>
                <div class="grid grid-cols-2 gap-2">
                    <label class="text-sm">Boîtes pleines
                        <input id="entry-packs" type="number" min="0" inputmode="numeric" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-3 text-lg">
                    </label>
                    <label class="text-sm"><span id="entry-unit-label">Unités en vrac</span>
                        <input id="entry-units" type="number" min="0" inputmode="numeric" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-3 text-lg">
                    </label>
                </div>
                <p id="entry-total" class="text-sm text-slate-300"></p>
            @else
                <p id="entry-expected" class="text-xs text-amber-300"></p>
                <label class="text-sm">N° de lot
                    <input id="entry-lot" autocomplete="off" autocapitalize="characters" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-3 font-mono text-lg uppercase">
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="text-sm">Péremption (MM/AAAA)
                        <input id="entry-expiry" inputmode="numeric" placeholder="06/2028" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-3 text-lg">
                    </label>
                    <label class="text-sm">{{ $session->mode === 'stock' ? 'Boîtes' : 'Boîtes reçues' }}
                        <input id="entry-qty" type="number" min="1" inputmode="numeric" value="1" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-3 text-lg">
                    </label>
                </div>
                @if ($session->mode === 'stock')
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-sm">Emplacement
                            <select id="entry-location" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-3 text-base"></select>
                        </label>
                        <label class="text-sm">Prix d'achat / boîte
                            <input id="entry-cost" type="number" min="0" step="0.01" inputmode="decimal" class="mt-1 w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-3 text-lg">
                        </label>
                    </div>
                @endif
            @endif

            <p id="entry-error" class="hidden text-sm text-red-300"></p>
            <div class="flex gap-2">
                <button type="button" id="entry-cancel" class="rounded-lg bg-slate-700 px-4 py-3">Annuler</button>
                <button class="flex-1 rounded-lg bg-emerald-700 px-4 py-3 font-semibold">Enregistrer</button>
            </div>
        </form>

        @if ($session->mode === 'stock')
            <section id="link" class="hidden flex-col gap-2 rounded-lg bg-amber-900/40 p-3">
                <p class="text-sm">Code inconnu : <strong id="link-code" class="font-mono"></strong><br>Cherchez le produit par son nom pour lui associer ce code.</p>
                <input id="link-q" autocomplete="off" placeholder="ex. doliprane" class="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-3 text-lg">
                <ul id="link-results" class="flex flex-col gap-1"></ul>
                <p id="link-error" class="hidden text-sm text-red-300"></p>
                <button type="button" id="link-cancel" class="rounded-lg bg-slate-700 px-4 py-3">Annuler</button>
            </section>
        @endif

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
                // Le PC a ouvert un autre écran : la page du téléphone se recharge pour le suivre
                if (data.target && data.target !== app.dataset.target && state === 'active' && !entry) return location.reload();
                store.set('seq', Math.max(store.get('seq', 0), data.last_sequence));
                render();
            }
        } catch {}
        setTimeout(pollState, state === 'awaiting_confirmation' ? 1500 : 3000);
    }

    // --- File d'envoi (SCN-05) : identifiant unique, séquence, accusé, renvoi ----
    store.set('seq', Math.max(store.get('seq', 0), Number(app.dataset.lastSequence)));
    let queue = store.get('queue', []);
    let sending = false;

    function enqueue(barcode, payload = null) {
        const seq = store.get('seq', 0) + 1;
        store.set('seq', seq);
        queue.push({ id: crypto.randomUUID(), sequence: seq, barcode, payload, target: app.dataset.target, scanned_at: new Date().toISOString() });
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
            else if (r.status === 409) {
                queue = [];
                store.set('queue', queue);
                log(scan.barcode, { status: 'error', message: 'écran changé sur le PC, rescannez' });
                return setTimeout(() => location.reload(), 1200);
            }
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
        const detail = ack.payload ? ` - ${ack.payload.lot_number ? `lot ${ack.payload.lot_number}, ${ack.payload.quantity_packs} bte(s)` : `compté ${ack.payload.quantity}`}` : '';
        li.textContent = ok && mode === 'lookup' ? `✓ ${code} envoyé au PC` : ok ? `✓ ${ack.product ?? code}${detail}` :`✗ ${code} - ${ack.status === 'unknown_product' ? 'produit inconnu' : (ack.message ?? 'refusé')}`;
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
        if (mode === 'pos') {
            $('again').disabled = false;
            return enqueue(code);
        }
        if (mode === 'lookup') return enqueue(code);
        if (linking) return;   // recherche par nom en cours   // fiche produit : le code est envoyé au PC
        if (!entry) openEntry(code);   // lectures ignorées tant qu'une fiche est ouverte
    }

    $('again')?.addEventListener('click', () => lastCode && detected(lastCode, true));   // SCN-04

    // --- Modes inventaire (B) et réception (C) : fiche, lot, quantité -----------
    const mode = app.dataset.mode;
    let entry = null;   // { code, product, line_id?, batch_id? }

    async function openEntry(code) {
        entry = { code, product: null };
        try {
            const r = await fetch(`${app.dataset.lookupUrl}?code=${encodeURIComponent(code)}&target=${encodeURIComponent(app.dataset.target)}`, { headers: { Accept: 'application/json' } });
            const data = await r.json();
            if (r.status === 410) { entry = null; state = 'revoked'; return render(); }
            if (r.status === 409) return location.reload();
            if (!r.ok && mode === 'stock' && data.error === 'unknown_product') { entry = null; return openLink(code); }
            if (!r.ok) { entry = null; return log(code, { status: 'error', message: data.message }); }
            entry.product = data;
        } catch {
            entry = null;
            return log(code, { status: 'error', message: 'réseau indisponible' });
        }

        const p = entry.product;
        fillStock(p);
        $('entry-name').textContent = p.name;
        $('entry-sub').textContent = `${p.sku} · ${p.units_per_pack} ${p.base_unit}(s) par boîte`;
        $('entry-error').classList.add('hidden');

        if (mode === 'inventory') {
            const box = $('entry-lines');
            box.replaceChildren();
            const choices = [
                ...p.lines.map((l) => ({ key: `line:${l.line_id}`, text: `Lot ${l.lot} · ${l.expiry} · ${l.location}${l.counted !== null ? ` - compté : ${l.counted}` : ''}` })),
                ...p.other_batches.map((b) => ({ key: `batch:${b.batch_id}`, text: `Lot ${b.lot} · ${b.expiry} - trouvé hors liste` })),
            ];
            if (!choices.length) {
                entry = null;
                return log(code, { status: 'error', message: "aucun lot de ce produit dans cet inventaire : comptez-le sur le PC" });
            }
            choices.forEach((c, i) => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2 rounded-lg bg-slate-900 px-3 py-3 text-sm';
                const radio = Object.assign(document.createElement('input'), { type: 'radio', name: 'lot', value: c.key, checked: i === 0 });
                label.append(radio, document.createTextNode(c.text));
                box.append(label);
            });
            $('entry-unit-label').textContent = `${p.base_unit}s en vrac`;
            $('entry-packs').value = ''; $('entry-units').value = '';
            updateTotal();
            setTimeout(() => $('entry-packs').focus(), 50);
        } else {
            const todo = (p.expected ?? []).filter((l) => !l.lot);
            $('entry-expected').textContent = mode === 'stock' ? '' : p.expected.length
                ? `Attendu : ${p.expected.map((l) => `${l.packs} boîte(s)${l.lot ? ` (lot ${l.lot} déjà saisi)` : ''}`).join(', ')}`
                : 'Produit absent de cette réception : il sera ajouté.';
            $('entry-lot').value = ''; $('entry-expiry').value = '';
            $('entry-qty').value = mode === 'stock' ? 1 : (todo[0]?.packs ?? 1);
            setTimeout(() => $('entry-lot').focus(), 50);
        }
        $('entry').classList.replace('hidden', 'flex');
        feedback(true);
    }

    // --- Entrée de stock : code inconnu → recherche par nom → association ---------
    let linking = null, linkTimer;
    function fillStock(p) {
        if (mode !== 'stock') return;
        $('entry-location').replaceChildren(...p.locations.map((l) => Object.assign(document.createElement('option'), { value: l.id, textContent: l.name })));
        $('entry-cost').value = Number(p.purchase_price) > 0 ? p.purchase_price : '';
    }
    function openLink(code) {
        linking = code;
        $('link-code').textContent = code;
        $('link-q').value = '';
        $('link-results').replaceChildren();
        $('link-error').classList.add('hidden');
        $('link').classList.replace('hidden', 'flex');
        feedback(false);
        setTimeout(() => $('link-q').focus(), 50);
    }
    function closeLink() { linking = null; $('link')?.classList.replace('flex', 'hidden'); }
    $('link-cancel')?.addEventListener('click', closeLink);
    $('link-q')?.addEventListener('input', () => {
        clearTimeout(linkTimer);
        linkTimer = setTimeout(async () => {
            const q = $('link-q').value.trim();
            if (q.length < 2) return $('link-results').replaceChildren();
            const r = await fetch(`${app.dataset.searchUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } }).catch(() => null);
            if (!r || !r.ok) return;
            const items = await r.json();
            $('link-results').replaceChildren(...(items.length ? items.map((it) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'w-full rounded-lg bg-slate-800 px-3 py-3 text-start';
                b.innerHTML = '<span class="font-semibold"></span><span class="block text-xs text-slate-400"></span>';
                b.children[0].textContent = it.name; b.children[1].textContent = it.label;
                b.addEventListener('click', () => link(it.id));
                const li = document.createElement('li'); li.append(b); return li;
            }) : [Object.assign(document.createElement('li'), { className: 'text-sm text-slate-400', textContent: 'Aucun produit : créez-le dans le catalogue sur le PC.' })]));
        }, 300);
    });
    async function link(presentationId) {
        const code = linking;
        const r = await fetch(app.dataset.linkUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ barcode: code, presentation_id: presentationId }),
        }).catch(() => null);
        const data = r ? await r.json().catch(() => ({})) : { message: 'réseau indisponible' };
        if (!r || !r.ok) {
            $('link-error').textContent = data.message ?? 'Association impossible.';
            return $('link-error').classList.remove('hidden');
        }
        closeLink();
        log(code, { status: 'received', product: `${data.name} : code associé` });
        openEntry(code);
    }

    function units() {
        const per = entry?.product?.units_per_pack ?? 1;
        return (Number($('entry-packs').value) || 0) * per + (Number($('entry-units').value) || 0);
    }
    function updateTotal() {
        if ($('entry-total')) $('entry-total').textContent = `Total compté : ${units()} ${entry?.product?.base_unit ?? ''}(s)`;
    }
    $('entry-packs')?.addEventListener('input', updateTotal);
    $('entry-units')?.addEventListener('input', updateTotal);

    function closeEntry() {
        entry = null;
        $('entry').classList.replace('flex', 'hidden');
    }
    $('entry-cancel').addEventListener('click', closeEntry);

    function entryError(text) {
        $('entry-error').textContent = text;
        $('entry-error').classList.remove('hidden');
        feedback(false);
    }

    $('entry').addEventListener('submit', (e) => {
        e.preventDefault();
        let payload;
        if (mode === 'inventory') {
            const choice = document.querySelector('input[name=lot]:checked')?.value ?? '';
            const [kind, id] = choice.split(':');
            if ($('entry-packs').value === '' && $('entry-units').value === '') return entryError('Saisissez la quantité comptée (0 si aucune).');
            payload = { [kind === 'line' ? 'line_id' : 'batch_id']: id, quantity: units() };
        } else {
            payload = { lot_number: $('entry-lot').value.trim(), expiry: $('entry-expiry').value.trim(), quantity_packs: Number($('entry-qty').value) };
            if (mode === 'stock') Object.assign(payload, { location_id: $('entry-location').value, pack_cost: $('entry-cost').value });
            if (!payload.lot_number) return entryError('Le numéro de lot est obligatoire.');
            if (!/^\d{1,2}[\/.-]\d{2,4}$/.test(payload.expiry) && !/^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}$/.test(payload.expiry)) return entryError('Péremption au format MM/AAAA.');
            if (!(payload.quantity_packs >= 1)) return entryError('Nombre de boîtes invalide.');
        }
        enqueue(entry.code, payload);
        closeEntry();
    });
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
        // Le module resources/js/barcode.js (chargé en différé) fournit un lecteur sur tous les navigateurs
        for (let i = 0; !window.barcodeDetectorReady && i < 50; i++) await new Promise((r) => setTimeout(r, 100));
        const reader = await window.barcodeDetectorReady?.catch(() => null);
        if (!reader || !('BarcodeDetector' in window)) {
            timer = false;
            return cameraMessage("Lecteur de codes-barres indisponible sur ce navigateur : utilisez la saisie manuelle.");
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
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
        const detector = new BarcodeDetector({ formats: reader.formats });
        let busy = false;   // le décodeur WebAssembly peut dépasser 250 ms : pas d'appels empilés
        timer = setInterval(async () => {
            if (busy || $('video').readyState < 2) return;
            busy = true;
            try {
                const codes = await detector.detect($('video'));
                if (codes[0]?.rawValue) detected(codes[0].rawValue);
            } catch {} finally { busy = false; }
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
