/**
 * Caisse ouverte directement sur un téléphone : bouton caméra à côté de la recherche.
 * Chaque code lu est envoyé à addFromSearch(), comme une douchette.
 * Le lecteur (BarcodeDetector natif ou ZXing) vient de resources/js/barcode.js.
 */
const DEBOUNCE_MS = 2000; // même code relu par la caméra ignoré pendant 2 s

document.addEventListener('alpine:init', () => {
    window.Alpine.data('cameraScan', ($wire) => ({
        available: window.matchMedia('(pointer: coarse)').matches && !!navigator.mediaDevices,
        active: false,
        message: '',
        last: '',
        flash: false,
        stream: null,
        timer: null,
        lastCode: null,
        lastAt: 0,

        async open() {
            this.active = true;
            this.message = '';
            this.last = '';
            if (!window.isSecureContext) return (this.message = 'Caméra indisponible : la page doit être ouverte en HTTPS.');
            for (let i = 0; !window.barcodeDetectorReady && i < 50; i++) await new Promise((r) => setTimeout(r, 100));
            const reader = await window.barcodeDetectorReady?.catch(() => null);
            if (!reader || !('BarcodeDetector' in window)) return (this.message = 'Lecteur de codes-barres indisponible sur ce navigateur.');
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: false,
                });
            } catch {
                return (this.message = "Accès à la caméra refusé : autorisez-le dans le navigateur.");
            }
            if (!this.active) return this.stop();
            const video = this.$refs.video;
            video.srcObject = this.stream;
            await video.play().catch(() => {});
            const detector = new window.BarcodeDetector({ formats: reader.formats });
            let busy = false;
            this.timer = setInterval(async () => {
                if (busy || video.readyState < 2) return;
                busy = true;
                try {
                    const code = (await detector.detect(video))[0]?.rawValue;
                    if (code) this.found(code);
                } catch {} finally { busy = false; }
            }, 250);
        },

        found(code) {
            const now = Date.now();
            if (code === this.lastCode && now - this.lastAt < DEBOUNCE_MS) return;
            this.lastCode = code;
            this.lastAt = now;
            this.last = code;
            navigator.vibrate?.(60);
            this.flash = true;
            setTimeout(() => (this.flash = false), 400);
            $wire.addFromSearch(code);
        },

        stop() {
            clearInterval(this.timer);
            this.timer = null;
            this.stream?.getTracks().forEach((t) => t.stop());
            this.stream = null;
        },

        close() {
            this.stop();
            this.active = false;
        },
    }));
});
