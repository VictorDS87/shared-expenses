const $ = (selector, scope = document) => scope.querySelector(selector);
const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];

const sidebar = $('#sidebar');
const menuToggle = $('[data-sidebar-toggle]');
const mobileMedia = window.matchMedia('(max-width: 760px)');
function setMenu(open) {
    document.body.classList.toggle('sidebar-open', open);
    menuToggle?.setAttribute('aria-expanded', String(open));
    if (sidebar) sidebar.inert = mobileMedia.matches && !open;
}
menuToggle?.addEventListener('click', () => setMenu(!document.body.classList.contains('sidebar-open')));
$('[data-sidebar-close]')?.addEventListener('click', () => setMenu(false));
mobileMedia.addEventListener('change', () => setMenu(false));
setMenu(false);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
        setMenu(false);
        menuToggle?.focus();
    }
});
$$('[data-dismiss]').forEach((button) => button.addEventListener('click', () => button.closest('.notice')?.remove()));
$$('[data-open-dialog]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
$$('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
$$('dialog').forEach((dialog) => dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    }
}));
