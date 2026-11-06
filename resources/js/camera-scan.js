/**
 * Écran ouvert directement sur un téléphone (caisse, inventaire) : bouton caméra.
 * Chaque code lu est envoyé à la méthode Livewire indiquée, comme une douchette.
 * Le lecteur (BarcodeDetector natif ou ZXing) vient de resources/js/barcode.js.
 *
 * Pensé pour une main : barre du haut (lampe, fermer), aperçu compact avec cadre de visée,
 * liste des derniers codes avec leur résultat, saisie manuelle, gros bouton Terminer
 * au-dessus de la barre de Safari (100dvh + zone sûre).
 */
const DEBOUNCE_MS = 2000; // même code relu par la caméra ignoré pendant 2 s

document.addEventListener('alpine:init', () => {
    window.Alpine.data('cameraScan', ($wire, method = 'addFromSearch', once = false) => ({
        available: window.matchMedia('(pointer: coarse)').matches && !!navigator.mediaDevices,
        active: false,
        starting: false,
        message: '',
        flash: '',
        torch: false,
        torchOn: false,
        manual: '',
        /** @type {{code: string, ok: boolean, text: string}[]} */
        history: [],
        count: 0,
        stream: null,
        track: null,
        timer: null,
        audio: null,
        lastCode: null,
        lastAt: 0,
        /** Lecteur utilisé et état, affichés en petit : aide au diagnostic sur le téléphone. */
        engine: '',

        async open() {
            this.active = true;
            this.message = '';
            this.history = [];
            this.count = 0;
            this.engine = '';
            this.starting = true;
            document.documentElement.style.overflow = 'hidden';
            // Le son doit être débloqué pendant le geste de l'utilisateur (iPhone)
            try { this.audio ??= new (window.AudioContext || window.webkitAudioContext)(); this.audio.resume(); } catch {}

            if (!window.isSecureContext) return this.fail('Caméra indisponible : la page doit être ouverte en HTTPS.');
            for (let i = 0; !window.barcodeDetectorReady && i < 50; i++) await new Promise((r) => setTimeout(r, 100));
            const reader = await window.barcodeDetectorReady?.catch(() => null);
            if (!reader || !('BarcodeDetector' in window)) return this.fail('Lecteur indisponible sur ce navigateur : saisissez le code ci-dessous.');
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
                    audio: false,
                });
            } catch {
                return this.fail("Accès à la caméra refusé : autorisez-le dans les réglages du navigateur, ou saisissez le code ci-dessous.");
            }
            if (!this.active) return this.stop();

            const video = this.$refs.video;
            video.srcObject = this.stream;
            await video.play().catch(() => {});
            this.track = this.stream.getVideoTracks()[0];
            this.tune();
            this.starting = false;

            let detector;
            try {
                detector = new window.BarcodeDetector({ formats: reader.formats });
            } catch (e) {
                return this.fail('Lecteur de codes-barres non chargé (' + (e?.message ?? e) + ') : saisissez le code ci-dessous.');
            }
            this.engine = reader.native ? 'lecteur natif' : 'lecteur ZXing';
            let failures = 0;
            let busy = false;
            this.timer = setInterval(async () => {
                if (busy || video.readyState < 2) return;
                busy = true;
                try {
                    const code = (await detector.detect(video))[0]?.rawValue;
                    failures = 0;
                    if (code) this.found(code);
                } catch (e) {
                    // Une erreur à chaque image : le décodeur ne fonctionne pas (fichier .wasm non servi…)
                    if (++failures === 15) this.engine += ' — erreur : ' + (e?.message ?? e);
                } finally { busy = false; }
            }, 200);
        },

        /** Mise au point continue et léger zoom : un code-barres se lit de plus loin, donc plus net. */
        tune() {
            const caps = this.track?.getCapabilities?.() ?? {};
            const advanced = [];
            if (caps.focusMode?.includes('continuous')) advanced.push({ focusMode: 'continuous' });
            if (caps.zoom) advanced.push({ zoom: Math.min(caps.zoom.max, Math.max(caps.zoom.min, 2)) });
            if (advanced.length) this.track.applyConstraints({ advanced }).catch(() => {});
            this.torch = !!caps.torch;
        },

        toggleTorch() {
            this.torchOn = !this.torchOn;
            this.track?.applyConstraints({ advanced: [{ torch: this.torchOn }] }).catch(() => (this.torch = false));
        },

        fail(text) {
            this.starting = false;
            this.message = text;
        },

        submitManual() {
            const code = this.manual.trim();
            this.manual = '';
            if (code) this.found(code, true);
        },

        async found(code, intentional = false) {
            const now = Date.now();
            if (!intentional && code === this.lastCode && now - this.lastAt < DEBOUNCE_MS) return;
            this.lastCode = code;
            this.lastAt = now;

            const entry = { code, ok: true, text: '…' };
            this.history.unshift(entry);
            this.history = this.history.slice(0, 5);
            this.feedback('ok');

            await $wire.call(method, code);
            // Les écrans exposent leur message d'erreur dans $wire.error (produit inconnu…)
            const error = $wire.error;
            const item = this.history.find((h) => h === entry) ?? entry;
            item.ok = !error;
            item.text = error || (once ? 'Lu' : 'Ajouté');
            if (error) this.feedback('error'); else this.count++;
            this.history = [...this.history];

            if (once && !error) { this.stop(); setTimeout(() => this.close(), 350); }
        },

        feedback(kind) {
            this.flash = kind;
            setTimeout(() => (this.flash = ''), 450);
            navigator.vibrate?.(kind === 'ok' ? 60 : [80, 60, 80]);
            try {
                const o = this.audio.createOscillator(), g = this.audio.createGain();
                o.frequency.value = kind === 'ok' ? 1250 : 320;
                g.gain.value = 0.12;
                o.connect(g).connect(this.audio.destination);
                o.start();
                o.stop(this.audio.currentTime + (kind === 'ok' ? 0.08 : 0.3));
            } catch {}
        },

        stop() {
            clearInterval(this.timer);
            this.timer = null;
            this.stream?.getTracks().forEach((t) => t.stop());
            this.stream = null;
            this.track = null;
            this.torch = this.torchOn = false;
        },

        close() {
            this.stop();
            this.active = false;
            document.documentElement.style.overflow = '';
        },
    }));
});
