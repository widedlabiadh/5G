// public/js/navbar.js
(function () {
    const searchInput = document.getElementById('navbarSearchInput');
    const searchResults = document.getElementById('navbarSearchResults');
    const ticketNotifBtn = document.getElementById('ticketNotifBtn');
    const ticketNotifDropdown = document.getElementById('ticketNotifDropdown');
    const ticketNotifBadge = document.getElementById('ticketNotifBadge');
    const ticketNotifList = document.getElementById('ticketNotifList');
    const ticketNotifCountText = document.getElementById('ticketNotifCountText');
    const ticketNotifMarkRead = document.getElementById('ticketNotifMarkRead');
    const profileBtn = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    const profileCaret = document.getElementById('profileCaret');
    const overlay = document.getElementById('navbarOverlay');
    const apiDot = document.getElementById('apiDot');
    const apiLabel = document.getElementById('apiLabel');
    const SITES = Array.isArray(window.sitesData) ? window.sitesData : [];

    function escHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function closeAll() {
        ticketNotifDropdown?.classList.remove('open');
        ticketNotifBtn?.setAttribute('aria-expanded', 'false');
        profileDropdown?.classList.remove('open');
        profileCaret?.classList.remove('open');
        searchResults?.classList.remove('open');
        overlay?.classList.remove('open');
    }

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
            event.preventDefault();
            searchInput?.focus();
            searchInput?.select();
        }

        if (event.key === 'Escape') {
            closeAll();
            searchInput?.blur();
        }
    });

    function openTraficCourbe(siteName) {
        if (typeof window.ouvrirCourbe === 'function') {
            window.ouvrirCourbe(siteName);
            return;
        }

        window.location.href = `/courbe/trafic?site=${encodeURIComponent(siteName)}`;
    }

    if (searchInput && searchResults) {
        const handleSearch = function () {
            const term = this.value.trim().toLowerCase();
            if (term.length < 2) {
                searchResults.classList.remove('open');
                return;
            }

            const filtered = SITES.filter(function (site) {
                return (site.nom || site.code || '').toLowerCase().includes(term)
                    || (site.typeTrans || '').toLowerCase().includes(term);
            }).slice(0, 8);

            if (!filtered.length) {
                searchResults.innerHTML = `<div class="search-empty">Aucun site trouve pour "<strong>${escHtml(term)}</strong>"</div>`;
            } else {
                searchResults.innerHTML = filtered.map(function (site) {
                    const statut = site.statut || 'non_defini';
                    let dotClass = 'sri-dot-gray';
                    let badgeClass = 'sri-badge-ok';
                    let badgeText = 'OK';

                    if (statut === 'critique') {
                        dotClass = 'sri-dot-red';
                        badgeClass = 'sri-badge-crit';
                        badgeText = 'Critique';
                    } else if (statut === 'sous_observation') {
                        dotClass = 'sri-dot-orange';
                        badgeClass = 'sri-badge-obs';
                        badgeText = 'Obs.';
                    } else if (statut === 'securise') {
                        dotClass = 'sri-dot-green';
                        badgeText = 'Securise';
                    }

                    return `
                        <div class="search-result-item" data-site="${escHtml(site.nom || site.code || '')}">
                            <span class="sri-dot ${dotClass}"></span>
                            <span class="sri-name">${escHtml(site.nom || site.code || '-')}</span>
                            <span class="sri-meta">${site.nbOccurrences || 0} congest. - ${escHtml(site.typeTrans || '-')}</span>
                            <span class="sri-badge ${badgeClass}">${badgeText}</span>
                        </div>
                    `;
                }).join('');
            }

            searchResults.classList.add('open');
        };

        searchInput.addEventListener('input', handleSearch);
        searchInput.addEventListener('focus', function () {
            if (this.value.trim().length >= 2 && searchResults.innerHTML.trim() !== '') {
                searchResults.classList.add('open');
            }
        });

        searchResults.addEventListener('click', function (event) {
            const item = event.target.closest('.search-result-item');
            if (!item) {
                return;
            }

            openTraficCourbe(item.dataset.site || '');
            closeAll();
            searchInput.value = '';
        });
    }

    function renderTicketNotifications(payload) {
        if (!ticketNotifList || !ticketNotifBadge || !ticketNotifCountText) {
            return;
        }

        const count = Number(payload.count || 0);
        const notifications = Array.isArray(payload.notifications) ? payload.notifications : [];

        ticketNotifBadge.hidden = count <= 0;
        ticketNotifBadge.textContent = count > 99 ? '99+' : String(count);
        ticketNotifCountText.textContent = count > 0 ? `${count} non lue${count > 1 ? 's' : ''}` : 'Aucune non lue';

        if (ticketNotifMarkRead) {
            ticketNotifMarkRead.hidden = count <= 0;
            ticketNotifMarkRead.disabled = false;
        }

        if (!notifications.length) {
            ticketNotifList.innerHTML = '<div class="ticket-notif-empty">Aucune notification non lue.</div>';
            return;
        }

        ticketNotifList.innerHTML = notifications.map(function (notification) {
            const ticketUrl = notification.ticketUrl || `/workflow/show/${encodeURIComponent(notification.ticketId)}`;

            return `
                <a class="ticket-notif-item" href="${ticketUrl}">
                    <span class="ticket-notif-icon"><i class="fas fa-ticket"></i></span>
                    <span class="ticket-notif-body">
                        <strong>${escHtml(notification.title || 'Notification ticket')}</strong>
                        <span>${escHtml(notification.message || '')}</span>
                        <small>Ticket #${escHtml(notification.ticketId)} - ${escHtml(notification.createdAt || '')}</small>
                    </span>
                </a>
            `;
        }).join('');
    }

    function renderTicketNotificationError() {
        if (ticketNotifList) {
            ticketNotifList.innerHTML = '<div class="ticket-notif-empty">Notifications indisponibles.</div>';
        }
    }

    async function loadTicketNotifications() {
        if (!ticketNotifBtn) {
            return;
        }

        try {
            const response = await fetch(ticketNotifBtn.dataset.unreadUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            renderTicketNotifications(await response.json());
        } catch (error) {
            renderTicketNotificationError();
        }
    }

    if (ticketNotifBtn && ticketNotifDropdown) {
        ticketNotifBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            const isOpen = ticketNotifDropdown.classList.contains('open');
            closeAll();

            if (!isOpen) {
                ticketNotifDropdown.classList.add('open');
                ticketNotifBtn.setAttribute('aria-expanded', 'true');
                overlay?.classList.add('open');
                loadTicketNotifications();
            }
        });

        ticketNotifDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        ticketNotifMarkRead?.addEventListener('click', async function () {
            ticketNotifMarkRead.disabled = true;

            try {
                const response = await fetch(ticketNotifBtn.dataset.markReadUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                renderTicketNotifications({ count: 0, notifications: [] });
            } catch (error) {
                ticketNotifMarkRead.disabled = false;
                renderTicketNotificationError();
            }
        });

        loadTicketNotifications();
        setInterval(loadTicketNotifications, 30000);
    }

    if (profileBtn && profileDropdown && profileCaret) {
        profileBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            const isOpen = profileDropdown.classList.contains('open');
            closeAll();

            if (!isOpen) {
                profileDropdown.classList.add('open');
                profileCaret.classList.add('open');
                overlay?.classList.add('open');
            }
        });
    }

    overlay?.addEventListener('click', closeAll);
    document.addEventListener('click', closeAll);

    function checkApiStatus() {
        if (!apiDot || !apiLabel) {
            return;
        }

        fetch('/api/python/health', {
            signal: AbortSignal.timeout(4000),
            cache: 'no-store',
        })
            .then((response) => response.json())
            .then((data) => {
                const ok = data.status === 'ok';
                apiDot.className = 'api-dot ' + (ok ? 'ok' : 'error');
                apiLabel.textContent = ok ? 'API OK' : 'API HS';
                apiLabel.style.color = ok ? '#10b981' : '#ef4444';
            })
            .catch(() => {
                apiDot.className = 'api-dot error';
                apiLabel.textContent = 'API HS';
                apiLabel.style.color = '#ef4444';
            });
    }

    if (apiDot) {
        checkApiStatus();
        setInterval(checkApiStatus, 30000);
    }
})();
