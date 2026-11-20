// Public website: tiny vanilla helpers (no Livewire/Alpine on public pages).

// Mobile menu
const toggle = document.querySelector('[data-menu-toggle]');
const menu = document.querySelector('[data-menu]');
toggle?.addEventListener('click', () => {
    const open = menu.classList.toggle('hidden') === false;
    toggle.setAttribute('aria-expanded', String(open));
});
menu?.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => {
    menu.classList.add('hidden');
    toggle?.setAttribute('aria-expanded', 'false');
}));

// Product gallery
document.querySelectorAll('[data-gallery]').forEach((gallery) => {
    const main = gallery.querySelector('[data-gallery-main]');
    gallery.querySelectorAll('[data-gallery-thumb]').forEach((thumb) => {
        thumb.addEventListener('click', () => {
            if (main) main.src = thumb.dataset.galleryThumb;
            gallery.querySelectorAll('[data-gallery-thumb]').forEach((t) => {
                t.classList.toggle('ring-brand-500', t === thumb);
                t.classList.toggle('ring-transparent', t !== thumb);
            });
        });
    });
});

// Filters are always open on large screens.
const filters = document.querySelector('[data-filters]');
if (filters && window.matchMedia('(min-width: 1024px)').matches) filters.open = true;

// Filter radios/selects apply immediately on large screens (mobile users press "Apply").
document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
    form.addEventListener('change', (e) => {
        if (window.matchMedia('(min-width: 1024px)').matches && e.target.matches('select, input[type=radio], input[type=checkbox]')) {
            form.submit();
        }
    });
});

// Inquiry form: the date field only matters for eye-exam bookings.
document.querySelectorAll('[data-type-form]').forEach((form) => {
    const sync = () => {
        const type = form.querySelector('input[name=type]:checked')?.value;
        form.querySelectorAll('[data-show-for]').forEach((el) => {
            el.hidden = type !== undefined && el.dataset.showFor !== type;
        });
    };
    form.addEventListener('change', sync);
    sync();
});
