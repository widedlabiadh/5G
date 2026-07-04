(() => {
    // ── Dictionnaire de traductions (sidebar + panneau paramètres) ────────────
    const TRANSLATIONS = {
        fr: {
            network:          'Réseau',
            dashboard:        'Dashboard',
            traffic_charts:   'Courbes Trafic',
            ia_prediction:    'IA Prédiction',
            interfaces:       'Interfaces',
            fiber:            'Fibre Optique',
            microwave:        'Faisceaux Hertziens',
            backhaul:         'Backhaul Sharing',
            all_sites:        'Tous les sites',
            dropcong:         'DropCong',
            site_mgmt:        'Gestion des sites',
            import_section:   'Import',
            plan_data:        'Plan Data',
            import_capacity:  'Import Capacité',
            import_umpt:      'Import UMPT',
            workflow_section: 'Workflow',
            tickets_tasks:    'Tickets & Tâches',
            admin_section:    'Administration',
            user_mgmt:        'Gestion Utilisateurs',
            import_excel:     'Import Excel',
            support_section:  'Support',
            my_tickets:       'Mes Tickets',
            my_tasks_section: 'Mes tâches',
            assigned_tickets: 'Tickets Assignés',
            logout:           'Déconnexion',
            settings:         'Paramètres',
            theme_label:      'Thème',
            theme_light:      'Clair',
            theme_dark:       'Sombre',
            lang_label:       'Langue',
        },
        en: {
            network:          'Network',
            dashboard:        'Dashboard',
            traffic_charts:   'Traffic Charts',
            ia_prediction:    'AI Prediction',
            interfaces:       'Interfaces',
            fiber:            'Fiber Optic',
            microwave:        'Microwave Links',
            backhaul:         'Backhaul Sharing',
            all_sites:        'All Sites',
            dropcong:         'Drop & Congestion',
            site_mgmt:        'Site Management',
            import_section:   'Import',
            plan_data:        'Plan Data',
            import_capacity:  'Import Capacity',
            import_umpt:      'Import UMPT',
            workflow_section: 'Workflow',
            tickets_tasks:    'Tickets & Tasks',
            admin_section:    'Administration',
            user_mgmt:        'User Management',
            import_excel:     'Import Excel',
            support_section:  'Support',
            my_tickets:       'My Tickets',
            my_tasks_section: 'My Tasks',
            assigned_tickets: 'Assigned Tickets',
            logout:           'Logout',
            settings:         'Settings',
            theme_label:      'Theme',
            theme_light:      'Light',
            theme_dark:       'Dark',
            lang_label:       'Language',
        },
    };

    // ── État courant ──────────────────────────────────────────────────────────
    let currentTheme = localStorage.getItem('netwon_theme') || 'light';
    let currentLang  = localStorage.getItem('netwon_lang')  || 'fr';

    // ── Appliquer le thème ────────────────────────────────────────────────────
    function applyTheme(theme) {
        currentTheme = theme;
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('netwon_theme', theme);
        updateThemeButtons();
    }

    // ── Appliquer la langue ───────────────────────────────────────────────────
    function applyLang(lang) {
        currentLang = lang;
        document.documentElement.setAttribute('lang', lang);
        localStorage.setItem('netwon_lang', lang);
        translatePage(lang);
        updateLangButtons();
    }

    function translatePage(lang) {
        const dict = TRANSLATIONS[lang] || TRANSLATIONS.fr;
        document.querySelectorAll('[data-i18n]').forEach((el) => {
            const key = el.dataset.i18n;
            if (dict[key]) el.textContent = dict[key];
        });
    }

    // ── Mettre à jour l'état actif des boutons ────────────────────────────────
    function updateThemeButtons() {
        document.querySelectorAll('[data-theme-btn]').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.themeBtn === currentTheme);
        });
    }

    function updateLangButtons() {
        document.querySelectorAll('[data-lang-btn]').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.langBtn === currentLang);
        });
    }

    // ── Panneau paramètres ────────────────────────────────────────────────────
    function initSettingsPanel() {
        const trigger = document.getElementById('settingsTrigger');
        const panel   = document.getElementById('settingsPanel');
        if (!trigger || !panel) return;

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = !panel.hidden;
            panel.hidden = open;
            trigger.setAttribute('aria-expanded', String(!open));
        });

        // Fermer si clic en dehors
        document.addEventListener('click', (e) => {
            if (!panel.hidden && !panel.contains(e.target) && e.target !== trigger) {
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
            }
        });

        // Boutons thème
        document.querySelectorAll('[data-theme-btn]').forEach((btn) => {
            btn.addEventListener('click', () => applyTheme(btn.dataset.themeBtn));
        });

        // Boutons langue
        document.querySelectorAll('[data-lang-btn]').forEach((btn) => {
            btn.addEventListener('click', () => applyLang(btn.dataset.langBtn));
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', () => {
        initSettingsPanel();
        applyTheme(currentTheme);   // sync boutons
        applyLang(currentLang);     // sync boutons + traductions
    });
})();
