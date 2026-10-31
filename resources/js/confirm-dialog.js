/**
 * Remplace la boîte native confirm() de wire:confirm par la fenêtre de l'application.
 * Le clic est intercepté avant Livewire ; si l'utilisateur confirme, il est rejoué
 * avec confirm() neutralisé le temps de ce seul clic.
 */
const DANGER = /^(annuler|supprimer|désactiver|archiver|vider)/i;

let replaying = false;

function ask(message) {
    const dialog = document.getElementById('confirm-dialog');
    if (!dialog) return Promise.resolve(window.confirm(message));

    const ok = dialog.querySelector('[data-confirm-ok]');
    dialog.querySelector('[data-confirm-message]').textContent = message;
    ok.classList.toggle('bg-red-600', DANGER.test(message));
    ok.classList.toggle('hover:bg-red-700', DANGER.test(message));
    ok.classList.toggle('bg-emerald-700', !DANGER.test(message));
    ok.classList.toggle('hover:bg-emerald-800', !DANGER.test(message));

    return new Promise((resolve) => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'ok'), { once: true });
        dialog.returnValue = '';
        dialog.showModal();
        ok.focus();
    });
}

document.addEventListener('click', async (event) => {
    if (replaying) return;
    const el = event.target.closest('[wire\\:confirm]');
    if (!el || el.disabled) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    const message = (el.getAttribute('wire:confirm') || 'Confirmer ?').replaceAll('\\n', '\n');
    if (!(await ask(message))) return;

    const nativeConfirm = window.confirm;
    window.confirm = () => true;
    replaying = true;
    try {
        el.click();
    } finally {
        replaying = false;
        window.confirm = nativeConfirm;
    }
}, true);
