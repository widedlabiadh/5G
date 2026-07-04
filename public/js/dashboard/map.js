// public/js/dashboard/map.js
// Carte Tunisie avec filtres, selection par polygone et export Excel.

document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.sitesData === 'undefined') {
        console.error('Aucune donnee sitesData trouvee');
        return;
    }

    const tunisiaBounds = L.latLngBounds(
        L.latLng(30.2, 7.5),
        L.latLng(37.5, 11.6)
    );

    const map = L.map('map', {
        center: [33.8, 9.5],
        zoom: 7,
        minZoom: 6,
        maxZoom: 17,
        maxBounds: tunisiaBounds,
        maxBoundsViscosity: 1.0
    });

    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        attribution: '© CartoDB',
        subdomains: 'abcd',
        maxZoom: 19
    }).addTo(map);

    function getMarkerColor(statut) {
        switch (statut) {
            case 'critique':
                return '#ef4444';
            case 'sous_observation':
                return '#f59e0b';
            case 'securise':
                return '#10b981';
            default:
                return '#94a3b8';
        }
    }

    function getStatutLabel(statut) {
        switch (statut) {
            case 'critique':
                return 'Critique';
            case 'sous_observation':
                return 'Sous observation';
            case 'securise':
                return 'Securise';
            default:
                return 'Non defini';
        }
    }

    function createMarker(statut, isDefaultPosition = false, selected = false) {
        const color = getMarkerColor(statut);
        const borderStyle = selected
            ? '3px solid #111827'
            : (isDefaultPosition ? '2px dashed #666' : '2px solid white');
        const size = selected ? 16 : 10;
        const anchor = selected ? 8 : 5;

        return L.divIcon({
            className: '',
            html: `<div style="width:${size}px;height:${size}px;background:${color};border:${borderStyle};border-radius:50%;box-shadow:0 1px 5px rgba(0,0,0,0.35);"></div>`,
            iconSize: [size, size],
            iconAnchor: [anchor, anchor],
            popupAnchor: [0, -8]
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    const groupCritique = L.layerGroup();
    const groupSousObs = L.layerGroup();
    const groupSecurise = L.layerGroup();
    const groupAutre = L.layerGroup();
    const drawnItems = new L.FeatureGroup();
    const markerEntries = [];
    const selectedSites = new Set();

    let sitesAjoutes = 0;

    window.sitesData.forEach(function (site) {
        let lat = parseFloat(site.latitude);
        let lng = parseFloat(site.longitude);
        let isDefaultPosition = false;

        const hasValidGPS = !Number.isNaN(lat) && !Number.isNaN(lng) &&
            lat !== 0 && lng !== 0 &&
            lat >= 30.2 && lat <= 37.5 &&
            lng >= 7.5 && lng <= 11.6;

        if (!hasValidGPS) {
            lat = 33.8;
            lng = 9.5;
            isDefaultPosition = true;
        }

        const statut = site.statut || 'non_defini';
        const nomSite = site.nom || site.site || 'Sans nom';
        const typeTrans = site.typeTrans || site.type || '—';
        const nbOccurrences = site.nbOccurrences || site.nombreOccurrences || 0;

        const marker = L.marker([lat, lng], {
            icon: createMarker(statut, isDefaultPosition)
        });

        const defaultPositionWarning = isDefaultPosition
            ? '<br><span style="color:#f59e0b;">Position approximative: coordonnees GPS manquantes</span>'
            : '';

        marker.bindPopup(`
            <div style="min-width:180px;font-family:'DM Sans',sans-serif;">
                <div style="font-weight:700;font-size:13px;margin-bottom:8px;color:#1e293b;">${escapeHtml(nomSite)}</div>
                <div style="font-size:11px;color:#64748b;line-height:1.7;">
                    Statut : <strong>${getStatutLabel(statut)}</strong><br>
                    Type : <strong>${escapeHtml(typeTrans)}</strong><br>
                    Occurrences : <strong>${nbOccurrences}</strong>
                    ${defaultPositionWarning}
                </div>
            </div>
        `);

        if (statut === 'critique') {
            groupCritique.addLayer(marker);
        } else if (statut === 'sous_observation') {
            groupSousObs.addLayer(marker);
        } else if (statut === 'securise') {
            groupSecurise.addLayer(marker);
        } else {
            groupAutre.addLayer(marker);
        }

        markerEntries.push({
            marker,
            site,
            name: nomSite,
            lat,
            lng,
            isDefaultPosition,
            statut
        });
        sitesAjoutes++;
    });

    groupCritique.addTo(map);
    groupSousObs.addTo(map);
    groupSecurise.addTo(map);
    groupAutre.addTo(map);
    drawnItems.addTo(map);

    const selectionPanel = document.getElementById('mapSelectionPanel');
    const selectionCount = document.getElementById('mapSelectionCount');
    const selectionList = document.getElementById('mapSelectionList');
    const selectionExport = document.getElementById('mapSelectionExport');
    const selectionClear = document.getElementById('mapSelectionClear');
    const lassoStart = document.getElementById('mapLassoStart');
    const lassoFinish = document.getElementById('mapLassoFinish');
    let manualLassoActive = false;
    let manualLassoPoints = [];
    let manualLassoLayer = null;

    if (window.L && L.Control && L.Control.Draw) {
        const drawControl = new L.Control.Draw({
            position: 'topleft',
            draw: {
                marker: false,
                circle: false,
                circlemarker: false,
                polyline: false,
                polygon: {
                    allowIntersection: false,
                    showArea: true,
                    shapeOptions: { color: '#f97316', weight: 2, fillOpacity: 0.12 }
                },
                rectangle: {
                    shapeOptions: { color: '#f97316', weight: 2, fillOpacity: 0.12 }
                }
            },
            edit: {
                featureGroup: drawnItems,
                edit: false,
                remove: true
            }
        });

        map.addControl(drawControl);

        map.on(L.Draw.Event.CREATED, function (event) {
            drawnItems.clearLayers();
            drawnItems.addLayer(event.layer);
            selectMarkersInsideLayer(event.layer);
        });

        map.on(L.Draw.Event.DELETED, clearSelection);
    }

    if (lassoStart && lassoFinish) {
        lassoStart.addEventListener('click', startManualLasso);
        lassoFinish.addEventListener('click', finishManualLasso);
        map.on('click', function (event) {
            if (!manualLassoActive) {
                return;
            }

            manualLassoPoints.push(event.latlng);
            renderManualLasso();
        });
    }

    function startManualLasso() {
        clearSelection();
        manualLassoActive = true;
        manualLassoPoints = [];
        map.doubleClickZoom.disable();
        map.getContainer().style.cursor = 'crosshair';
        lassoStart.classList.add('active');
        lassoStart.textContent = 'Cliquez sur la carte';
        lassoFinish.disabled = true;
    }

    function renderManualLasso() {
        if (manualLassoLayer) {
            drawnItems.removeLayer(manualLassoLayer);
        }

        if (manualLassoPoints.length >= 3) {
            manualLassoLayer = L.polygon(manualLassoPoints, {
                color: '#f97316',
                weight: 2,
                fillOpacity: 0.12
            });
            lassoFinish.disabled = false;
        } else {
            manualLassoLayer = L.polyline(manualLassoPoints, {
                color: '#f97316',
                weight: 2,
                dashArray: '4 4'
            });
        }

        drawnItems.addLayer(manualLassoLayer);
    }

    function finishManualLasso() {
        if (!manualLassoActive || manualLassoPoints.length < 3) {
            return;
        }

        if (manualLassoLayer) {
            drawnItems.removeLayer(manualLassoLayer);
        }

        manualLassoLayer = L.polygon(manualLassoPoints, {
            color: '#f97316',
            weight: 2,
            fillOpacity: 0.12
        });
        drawnItems.addLayer(manualLassoLayer);

        manualLassoActive = false;
        map.doubleClickZoom.enable();
        map.getContainer().style.cursor = '';
        lassoStart.classList.remove('active');
        lassoStart.textContent = 'Selection zone';
        lassoFinish.disabled = true;

        selectMarkersInsideLayer(manualLassoLayer);
    }

    function selectMarkersInsideLayer(layer) {
        selectedSites.clear();

        markerEntries.forEach(function (entry) {
            const inside = isMarkerInsideLayer(entry, layer);
            if (inside) {
                selectedSites.add(entry.name);
            }

            entry.marker.setIcon(createMarker(entry.statut, entry.isDefaultPosition, inside));
        });

        updateSelectionPanel();
    }

    function isMarkerInsideLayer(entry, layer) {
        const point = L.latLng(entry.lat, entry.lng);

        if (layer instanceof L.Rectangle && typeof layer.getBounds === 'function') {
            return layer.getBounds().contains(point);
        }

        if (typeof layer.getLatLngs === 'function') {
            const latLngs = layer.getLatLngs();
            const polygon = Array.isArray(latLngs[0]) ? latLngs[0] : latLngs;
            return pointInPolygon(point, polygon);
        }

        return false;
    }

    function pointInPolygon(point, polygon) {
        let inside = false;
        const x = point.lng;
        const y = point.lat;

        for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
            const xi = polygon[i].lng;
            const yi = polygon[i].lat;
            const xj = polygon[j].lng;
            const yj = polygon[j].lat;
            const intersects = ((yi > y) !== (yj > y)) &&
                (x < ((xj - xi) * (y - yi)) / ((yj - yi) || 1e-12) + xi);

            if (intersects) inside = !inside;
        }

        return inside;
    }

    function updateSelectionPanel() {
        if (!selectionPanel || !selectionCount || !selectionList || !selectionExport) {
            return;
        }

        const sites = Array.from(selectedSites).sort(function (a, b) {
            return a.localeCompare(b);
        });

        selectionPanel.classList.toggle('open', sites.length > 0);
        selectionCount.textContent = String(sites.length);
        selectionList.textContent = sites.slice(0, 12).join(', ') + (sites.length > 12 ? '...' : '');
        selectionExport.disabled = sites.length === 0;
    }

    function clearSelection() {
        manualLassoActive = false;
        manualLassoPoints = [];
        manualLassoLayer = null;
        if (lassoStart) {
            lassoStart.classList.remove('active');
            lassoStart.textContent = 'Selection zone';
        }
        if (lassoFinish) {
            lassoFinish.disabled = true;
        }
        map.doubleClickZoom.enable();
        map.getContainer().style.cursor = '';
        selectedSites.clear();
        drawnItems.clearLayers();
        markerEntries.forEach(function (entry) {
            entry.marker.setIcon(createMarker(entry.statut, entry.isDefaultPosition, false));
        });
        updateSelectionPanel();
    }

    if (selectionClear) {
        selectionClear.addEventListener('click', clearSelection);
    }

    if (selectionExport) {
        selectionExport.addEventListener('click', function () {
            const sites = Array.from(selectedSites);
            if (!sites.length || !window.mapSelectionExportUrl) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.mapSelectionExportUrl;
            form.style.display = 'none';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'sites';
            input.value = JSON.stringify(sites);

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            form.remove();
        });
    }

    document.querySelectorAll('.filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.filter-btn').forEach(function (button) {
                button.classList.remove('active');
            });
            this.classList.add('active');

            const filter = this.getAttribute('data-filter');
            map.removeLayer(groupCritique);
            map.removeLayer(groupSousObs);
            map.removeLayer(groupSecurise);
            map.removeLayer(groupAutre);

            if (filter === 'all') {
                map.addLayer(groupCritique);
                map.addLayer(groupSousObs);
                map.addLayer(groupSecurise);
                map.addLayer(groupAutre);
            } else if (filter === 'critique') {
                map.addLayer(groupCritique);
            } else if (filter === 'sous_observation') {
                map.addLayer(groupSousObs);
            } else if (filter === 'securise') {
                map.addLayer(groupSecurise);
            }
        });
    });

    const ctx = document.getElementById('congestionChart');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['FO', 'FH', 'Backhaul Sharing'],
                datasets: [{
                    data: [
                        window.statsFO || 0,
                        window.statsFH || 0,
                        window.statsBackbone || 0
                    ],
                    backgroundColor: ['#f97316', '#38bdf8', '#94a3b8'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11, family: "'DM Sans', sans-serif" } }
                    }
                }
            }
        });
    }

    console.log('Carte finalisee - ' + sitesAjoutes + ' sites affiches');
});
