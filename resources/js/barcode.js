/**
 * Lecture des codes-barres par la caméra du téléphone sur tous les navigateurs.
 * Chrome Android fournit BarcodeDetector ; Safari (iPhone), Firefox et d'autres non :
 * on charge alors un équivalent ZXing compilé en WebAssembly, servi par l'application
 * elle-même (aucun accès Internet requis sur le réseau de l'officine).
 * La page attend window.barcodeDetectorReady avant de démarrer la caméra.
 */
import { BarcodeDetector as Polyfill, setZXingModuleOverrides } from 'barcode-detector/pure';
import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url';

const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'code_128'];
// QR d'appairage, lu depuis l'application installée (page /scanner)
const ALL = [...FORMATS, 'qr_code'];

setZXingModuleOverrides({
    locateFile: (path, prefix) => (path.endsWith('.wasm') ? wasmUrl : prefix + path),
});

async function nativeIsUsable() {
    if (!('BarcodeDetector' in window)) return false;
    try {
        const supported = await window.BarcodeDetector.getSupportedFormats();
        return ALL.every((f) => supported.includes(f));
    } catch {
        return false;
    }
}

window.barcodeDetectorReady = nativeIsUsable().then((ok) => {
    if (!ok) window.BarcodeDetector = Polyfill;

    return { formats: FORMATS, native: ok };
});
