(function () {
    const sessionKey = 'netwon_active_browser_session';
    const logoutPath = '/logout';
    const currentPath = window.location.pathname;

    function markBrowserSessionActive() {
        try {
            window.sessionStorage.setItem(sessionKey, '1');
        } catch (error) {
            // sessionStorage can be unavailable in strict browser privacy modes.
        }
    }

    function clearBrowserSession() {
        try {
            window.sessionStorage.removeItem(sessionKey);
        } catch (error) {
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const loginForm = document.querySelector('form[action$="/login"]');

        if (loginForm) {
            loginForm.addEventListener('submit', markBrowserSessionActive);
            return;
        }

        initNetwonUi();

        document.querySelectorAll('a[href$="/logout"]').forEach(function (link) {
            link.addEventListener('click', clearBrowserSession);
        });

        if (currentPath !== logoutPath) {
            markBrowserSessionActive();
        }
    });

    function initNetwonUi() {
        const sidebar = document.querySelector('.sidebar');
        const menuToggle = document.getElementById('mobileMenuToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const backToTop = document.getElementById('backToTop');
        const progress = document.getElementById('pageScrollProgress');
        const isMobile = function () {
            return window.matchMedia('(max-width: 768px)').matches;
        };

        try {
            if (window.localStorage.getItem('netwon_sidebar_collapsed') === '1' && !isMobile()) {
                document.body.classList.add('sidebar-collapsed');
                menuToggle?.setAttribute('aria-expanded', 'false');
            }
        } catch (error) {
        }

        function closeSidebar() {
            sidebar?.classList.remove('mobile-open');
            sidebarOverlay?.classList.remove('open');
            if (isMobile()) {
                menuToggle?.setAttribute('aria-expanded', 'false');
            }
        }

        menuToggle?.addEventListener('click', function () {
            if (isMobile()) {
                const isOpen = sidebar?.classList.toggle('mobile-open') || false;
                sidebarOverlay?.classList.toggle('open', isOpen);
                menuToggle.setAttribute('aria-expanded', String(isOpen));
                return;
            }

            const collapsed = document.body.classList.toggle('sidebar-collapsed');
            menuToggle.setAttribute('aria-expanded', String(!collapsed));
            try {
                window.localStorage.setItem('netwon_sidebar_collapsed', collapsed ? '1' : '0');
            } catch (error) {
            }
        });

        sidebarOverlay?.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });

        document.querySelectorAll('.sidebar .nav-item, .sidebar .logout-btn').forEach(function (link) {
            link.addEventListener('click', closeSidebar);
        });

        function updateScrollUi() {
            const scrollTop = window.scrollY || document.documentElement.scrollTop || 0;
            const scrollable = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
            const pct = Math.min(100, Math.max(0, (scrollTop / scrollable) * 100));

            if (progress) {
                progress.style.width = pct + '%';
            }

            backToTop?.classList.toggle('visible', scrollTop > 140);
        }

        backToTop?.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        window.addEventListener('scroll', updateScrollUi, { passive: true });
        window.addEventListener('resize', function () {
            if (isMobile()) {
                document.body.classList.remove('sidebar-collapsed');
            } else {
                closeSidebar();
            }
            updateScrollUi();
        });
        updateScrollUi();
    }
})();
