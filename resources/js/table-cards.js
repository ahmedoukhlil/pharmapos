/**
 * Téléphone : les tableaux des écrans deviennent des cartes (CSS dans app.css, « main table »).
 * Chaque cellule reçoit le titre de sa colonne (data-label), affiché devant la valeur.
 * Relancé après chaque mise à jour Livewire (MutationObserver). Exclure un tableau : classe « no-cards ».
 */
function label() {
    document.querySelectorAll('main table:not(.no-cards)').forEach((table) => {
        const heads = [];
        table.querySelectorAll('thead tr:last-child th').forEach((th) => {
            for (let i = 0; i < (th.colSpan || 1); i++) heads.push(th.textContent.trim());
        });
        if (!heads.length) return;
        table.querySelectorAll('tbody tr, tfoot tr').forEach((tr) => {
            let col = 0;
            for (const td of tr.children) {
                const text = td.colSpan > 1 ? '' : heads[col] ?? '';
                if (td.dataset.label !== text) td.dataset.label = text;
                col += td.colSpan || 1;
            }
        });
    });
}

let queued = false;
const schedule = () => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; label(); });
};

document.addEventListener('DOMContentLoaded', () => {
    label();
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
});
