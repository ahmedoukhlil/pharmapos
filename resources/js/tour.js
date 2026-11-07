/**
 * Visite guidée : chaque écran balise ses éléments avec
 *   data-tour="Texte de l'étape" data-tour-title="Titre" data-tour-order="10"
 * La visite démarre seule une seule fois, à la première connexion de l'utilisateur,
 * et se relance avec le bouton « ? » du menu (événement start-tour).
 * Sans bibliothèque : fonctionne hors connexion.
 */
const visible = (el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';

function steps() {
    return [...document.querySelectorAll('[data-tour]')]
        .filter(visible)
        .sort((a, b) => (+a.dataset.tourOrder || 50) - (+b.dataset.tourOrder || 50));
}

function storageKey() {
    // Une seule visite automatique par utilisateur (la toute première fois) ; ensuite, bouton « ? »
    return `tour:${document.body.dataset.user ?? ''}:seen`;
}

let ui = null;

function close() {
    ui?.root.remove();
    ui = null;
    window.removeEventListener('resize', place);
    window.removeEventListener('keydown', onKey);
}

function onKey(e) {
    if (!ui) return;
    if (e.key === 'Escape') close();
    if (e.key === 'ArrowRight' || e.key === 'Enter') { e.preventDefault(); go(ui.index + 1); }
    if (e.key === 'ArrowLeft') go(ui.index - 1);
}

function place() {
    if (!ui) return;
    const el = ui.list[ui.index];
    const r = el.getBoundingClientRect();
    const pad = 6;
    Object.assign(ui.spot.style, {
        top: `${r.top - pad}px`, left: `${r.left - pad}px`,
        width: `${r.width + pad * 2}px`, height: `${r.height + pad * 2}px`,
    });
    const box = ui.box.getBoundingClientRect();
    const vw = window.innerWidth, vh = window.innerHeight;
    let top = r.bottom + 12;
    if (top + box.height > vh - 8) top = Math.max(8, r.top - box.height - 12);
    if (top + box.height > vh - 8) top = Math.max(8, vh - box.height - 8);
    const left = Math.min(Math.max(8, r.left), vw - box.width - 8);
    Object.assign(ui.box.style, { top: `${top}px`, left: `${left}px` });
}

function go(i) {
    if (!ui) return;
    if (i >= ui.list.length) { close(); return; }
    ui.index = Math.max(0, i);
    const el = ui.list[ui.index];
    el.scrollIntoView({ block: 'center', behavior: 'instant' });
    ui.title.textContent = el.dataset.tourTitle || '';
    ui.title.hidden = !el.dataset.tourTitle;
    ui.text.textContent = el.dataset.tour;
    ui.count.textContent = `${ui.index + 1} / ${ui.list.length}`;
    ui.prev.disabled = ui.index === 0;
    ui.next.textContent = ui.index === ui.list.length - 1 ? 'Terminer' : 'Suivant';
    requestAnimationFrame(place);
}

export function startTour() {
    close();
    const list = steps();
    if (!list.length) return;
    localStorage.setItem(storageKey(), '1');

    const root = document.createElement('div');
    root.className = 'tour-root';
    root.innerHTML = `
        <div class="tour-spot"></div>
        <div class="tour-box" role="dialog" aria-live="polite">
            <p class="tour-title"></p>
            <p class="tour-text"></p>
            <div class="tour-actions">
                <span class="tour-count"></span>
                <button type="button" class="tour-skip">Passer</button>
                <button type="button" class="tour-prev">Précédent</button>
                <button type="button" class="tour-next">Suivant</button>
            </div>
        </div>`;
    document.body.appendChild(root);
    ui = {
        root, list, index: 0,
        spot: root.querySelector('.tour-spot'), box: root.querySelector('.tour-box'),
        title: root.querySelector('.tour-title'), text: root.querySelector('.tour-text'),
        count: root.querySelector('.tour-count'), prev: root.querySelector('.tour-prev'), next: root.querySelector('.tour-next'),
    };
    root.querySelector('.tour-skip').onclick = close;
    ui.prev.onclick = () => go(ui.index - 1);
    ui.next.onclick = () => go(ui.index + 1);
    window.addEventListener('resize', place);
    window.addEventListener('keydown', onKey);
    go(0);
}

window.addEventListener('start-tour', startTour);

// Toute première visite : démarrage automatique, une seule fois
function autoStart() {
    const user = document.body.dataset.user;
    if (!user || localStorage.getItem(storageKey())) return;
    // Visite déjà vue avec l'ancienne version (une clé par écran) : ne plus la lancer seule
    if (Object.keys(localStorage).some((k) => k.startsWith(`tour:${user}:/`))) {
        localStorage.setItem(storageKey(), '1');
        return;
    }
    setTimeout(() => { if (!ui && steps().length) startTour(); }, 600);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoStart); else autoStart();
document.addEventListener('livewire:navigated', autoStart);
