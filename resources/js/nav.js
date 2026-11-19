// Sidebar menu: search, collapsible sections and per-user hidden pages.
// Used from layouts/app.blade.php as x-data="erpNav(config)". Hiding a page is cosmetic only;
// the server still checks permissions on every page.

const COLLAPSED_KEY = 'erp.nav.collapsed';

// Arabic-friendly matching: ignore case, diacritics and common letter variants (أ/إ/آ → ا, ة → ه, ى → ي).
const normalize = (s) => String(s || '')
    .toLowerCase()
    .replace(/[ً-ٰٟ]/g, '')
    .replace(/[أإآ]/g, 'ا')
    .replace(/ة/g, 'ه')
    .replace(/ى/g, 'ي')
    .trim();

const readCollapsed = () => {
    try {
        const v = JSON.parse(localStorage.getItem(COLLAPSED_KEY) || '[]');
        return Array.isArray(v) ? v : [];
    } catch {
        return [];
    }
};

window.erpNav = (config) => ({
    hidden: [...config.hidden],
    active: config.active,
    sections: config.sections,
    collapsed: readCollapsed(),
    editing: false,
    query: '',

    get items() {
        return Object.values(this.sections).flat();
    },

    item(key) {
        return this.items.find((i) => i.key === key);
    },

    isHidden(key) {
        return this.hidden.includes(key);
    },

    matches(key) {
        const q = normalize(this.query);
        if (!q) return true;
        const hay = normalize(this.item(key)?.search);
        return q.split(/\s+/).every((word) => hay.includes(word));
    },

    visible(key) {
        if (this.query) return this.matches(key);
        return this.editing || !this.isHidden(key) || key === this.active;
    },

    sectionVisible(section) {
        return (this.sections[section] || []).some((i) => this.visible(i.key));
    },

    sectionAllHidden(section) {
        return (this.sections[section] || []).every((i) => this.isHidden(i.key));
    },

    anyMatch() {
        return this.items.some((i) => this.matches(i.key));
    },

    isOpen(section) {
        // The open page's section always stays open, and searching/editing shows everything.
        if (this.query || this.editing) return true;
        if ((this.sections[section] || []).some((i) => i.key === this.active)) return true;
        return !this.collapsed.includes(section);
    },

    toggleSection(section) {
        this.collapsed = this.collapsed.includes(section)
            ? this.collapsed.filter((s) => s !== section)
            : [...this.collapsed, section];
        try { localStorage.setItem(COLLAPSED_KEY, JSON.stringify(this.collapsed)); } catch {}
    },

    startEditing() {
        this.query = '';
        this.editing = true;
    },

    openFirst() {
        const first = this.items.find((i) => this.visible(i.key));
        if (first) window.location.href = first.url;
    },

    shortcut(e) {
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;
        if ((e.key === '/' && !typing) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
            if (!this.$refs.search || this.editing) return;
            e.preventDefault();
            window.dispatchEvent(new CustomEvent('open-sidebar'));
            this.$nextTick(() => this.$refs.search.focus());
        }
    },

    toast(message, extra = {}) {
        window.dispatchEvent(new CustomEvent('toast', { detail: { message, ...extra } }));
    },

    async send(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body || {}),
        });
        if (!res.ok) throw new Error(String(res.status));
        return res.json();
    },

    // Optimistic update; rolls back if the server refuses.
    async setHidden(keys, hide, { quiet = false } = {}) {
        const before = [...this.hidden];
        this.hidden = hide
            ? [...new Set([...this.hidden, ...keys])]
            : this.hidden.filter((k) => !keys.includes(k));
        try {
            const data = await this.send(config.urls.pages, { pages: keys, hidden: hide });
            this.hidden = data.hidden;
        } catch {
            this.hidden = before;
            this.toast(config.text.error, { type: 'error' });
            return;
        }
        if (quiet || this.editing || keys.length !== 1) return;
        const label = this.item(keys[0])?.label || '';
        this.toast((hide ? config.text.hidden : config.text.shown).replace(':page', label), {
            actionLabel: config.text.undo,
            action: () => this.setHidden(keys, !hide, { quiet: true }),
        });
    },

    toggle(key) {
        this.setHidden([key], !this.isHidden(key));
    },

    toggleSectionPages(section) {
        const keys = (this.sections[section] || []).map((i) => i.key);
        this.setHidden(keys, !this.sectionAllHidden(section));
    },

    async showAll() {
        const before = [...this.hidden];
        this.hidden = [];
        try {
            await this.send(config.urls.showAll);
            this.toast(config.text.allShown);
        } catch {
            this.hidden = before;
            this.toast(config.text.error, { type: 'error' });
        }
    },
});
