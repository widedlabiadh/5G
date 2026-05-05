// public/js/dashboard/map.js
// Version complète - Affiche TOUS les sites (avec ou sans GPS)

document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Carte en chargement...');
    
    // Vérifier les données
    if (typeof window.sitesData === 'undefined') {
        console.error('❌ Aucune donnée sitesData trouvée');
        return;
    }
    
    console.log('📊 Total sites reçus: ' + window.sitesData.length);
    
    // Compter les sites avec/sans GPS
    let avecGPS = 0;
    let sansGPS = 0;
    
    window.sitesData.forEach(site => {
        const lat = parseFloat(site.latitude);
        const lng = parseFloat(site.longitude);
        if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
            avecGPS++;
        } else {
            sansGPS++;
        }
    });
    
    console.log('📍 Sites avec GPS: ' + avecGPS);
    console.log('⚠️ Sites sans GPS (affichés au centre): ' + sansGPS);
    
    // ========================= INITIALISATION CARTE =========================
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
    
    // ========================= FONCTIONS =========================
    function getMarkerColor(statut) {
        switch (statut) {
            case 'critique': return '#ef4444';
            case 'sous_observation': return '#f59e0b';
            case 'securise': return '#10b981';
            default: return '#94a3b8';
        }
    }
    
    function getStatutLabel(statut) {
        switch (statut) {
            case 'critique': return '🔴 Critique';
            case 'sous_observation': return '🟠 Sous observation';
            case 'securise': return '🟢 Sécurisé';
            default: return '⚪ Non défini';
        }
    }
    
    function createMarker(statut, isDefaultPosition = false) {
        const color = getMarkerColor(statut);
        // Si c'est un site sans GPS, on met un contour en pointillés pour le distinguer
        const borderStyle = isDefaultPosition ? '2px dashed #666' : '2px solid white';
        return L.divIcon({
            className: '',
            html: `<div style="width:10px; height:10px; background:${color}; border:${borderStyle}; border-radius:50%; box-shadow:0 1px 3px rgba(0,0,0,0.3);"></div>`,
            iconSize: [10, 10],
            iconAnchor: [5, 5],
            popupAnchor: [0, -8]
        });
    }
    
    // ========================= GROUPES POUR FILTRES =========================
    const groupCritique = L.layerGroup();
    const groupSousObs = L.layerGroup();
    const groupSecurise = L.layerGroup();
    const groupAutre = L.layerGroup();
    
    // ========================= PARCOURS DES SITES =========================
    let sitesAjoutes = 0;
    
    window.sitesData.forEach(site => {
        let lat = parseFloat(site.latitude);
        let lng = parseFloat(site.longitude);
        let isDefaultPosition = false;
        
        // Vérifier si les coordonnées sont valides
        let hasValidGPS = !isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0 && 
                          lat >= 30.2 && lat <= 37.5 && lng >= 7.5 && lng <= 11.6;
        
        if (!hasValidGPS) {
            // Position par défaut : centre de la Tunisie
            lat = 33.8;
            lng = 9.5;
            isDefaultPosition = true;
        }
        
        const statut = site.statut || 'non_defini';
        const nomSite = site.nom || site.site || 'Sans nom';
        const typeTrans = site.typeTrans || site.type || '—';
        const nbOccurrences = site.nbOccurrences || site.nombreOccurrences || 0;
        
        // Créer le marqueur
        const marker = L.marker([lat, lng], { 
            icon: createMarker(statut, isDefaultPosition) 
        });
        
        // Ajouter un message dans le popup si c'est une position par défaut
        const defaultPositionWarning = isDefaultPosition ? 
            '<br><span style="color:#f59e0b;">⚠️ Position approximative (coordonnées GPS manquantes)</span>' : '';
        
        marker.bindPopup(`
            <div style="min-width:180px; font-family:'DM Sans',sans-serif;">
                <div style="font-weight:700; font-size:13px; margin-bottom:8px; color:#1e293b;">${escapeHtml(nomSite)}</div>
                <div style="font-size:11px; color:#64748b; line-height:1.7;">
                    📍 ${getStatutLabel(statut)}<br>
                    📡 Type : <strong>${escapeHtml(typeTrans)}</strong><br>
                    ⚠️ Congestions : <strong>${nbOccurrences}</strong>
                    ${defaultPositionWarning}
                </div>
            </div>
        `);
        
        // Ajouter au bon groupe
        if (statut === 'critique') {
            groupCritique.addLayer(marker);
        } else if (statut === 'sous_observation') {
            groupSousObs.addLayer(marker);
        } else if (statut === 'securise') {
            groupSecurise.addLayer(marker);
        } else {
            groupAutre.addLayer(marker);
        }
        
        sitesAjoutes++;
    });
    
    console.log('✅ Sites ajoutés à la carte: ' + sitesAjoutes);
    
    // Ajouter tous les groupes à la carte
    groupCritique.addTo(map);
    groupSousObs.addTo(map);
    groupSecurise.addTo(map);
    groupAutre.addTo(map);
    
    // ========================= GESTION DES FILTRES =========================
    const filterButtons = document.querySelectorAll('.filter-btn');
    
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            filterButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const filter = this.getAttribute('data-filter');
            
            // Retirer tous les groupes
            map.removeLayer(groupCritique);
            map.removeLayer(groupSousObs);
            map.removeLayer(groupSecurise);
            map.removeLayer(groupAutre);
            
            // Ajouter selon le filtre
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
    
    // ========================= GRAPHIQUE =========================
    const ctx = document.getElementById('congestionChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['FO', 'FH', 'Backbone'],
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
    
    // Fonction utilitaire pour échapper le HTML
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    
    console.log('🎉 Carte finalisée — ' + sitesAjoutes + ' sites affichés');
});