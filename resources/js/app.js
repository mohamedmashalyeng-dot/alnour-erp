import './nav';

// Registers the service worker (installable app + offline POS) and offers updates when a new
// version is deployed.
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((reg) => {
            reg.addEventListener('updatefound', () => {
                const worker = reg.installing;
                worker?.addEventListener('statechange', () => {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        const bar = document.createElement('div');
                        bar.className = 'no-print fixed inset-x-0 bottom-0 z-50 flex items-center justify-center gap-3 bg-ink-900 p-3 text-sm text-white';
                        bar.textContent = document.documentElement.lang === 'ar' ? 'يتوفر إصدار جديد من النظام.' : 'A new version is available.';
                        const btn = document.createElement('button');
                        btn.className = 'btn-primary btn-sm';
                        btn.textContent = document.documentElement.lang === 'ar' ? 'تحديث' : 'Update';
                        btn.onclick = () => worker.postMessage('SKIP_WAITING');
                        bar.appendChild(btn);
                        document.body.appendChild(bar);
                    }
                });
            });
        }).catch(() => {});
        let reloading = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (!reloading) {
                reloading = true;
                window.location.reload();
            }
        });
    });
}
