if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('./service-worker.js').catch(() => {});
  });
}

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-confirm]');
  if (!button) return;
  if (!window.confirm(button.dataset.confirm)) {
    event.preventDefault();
  }
});

document.addEventListener('click', (event) => {
  const toggle = event.target.closest('[data-menu-toggle]');
  const panel = document.querySelector('[data-menu-panel]');
  if (!panel) return;

  if (toggle) {
    const isOpen = panel.classList.toggle('open');
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    toggle.setAttribute('aria-label', isOpen ? 'Menü schließen' : 'Menü öffnen');
    return;
  }

  if (!event.target.closest('[data-menu-panel]')) {
    panel.classList.remove('open');
    document.querySelector('[data-menu-toggle]')?.setAttribute('aria-expanded', 'false');
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  const panel = document.querySelector('[data-menu-panel]');
  const toggle = document.querySelector('[data-menu-toggle]');
  panel?.classList.remove('open');
  toggle?.setAttribute('aria-expanded', 'false');
  toggle?.setAttribute('aria-label', 'Menü öffnen');
});
