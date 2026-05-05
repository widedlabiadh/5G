# api_python/etat_sites.py
import os
import psycopg2
from datetime import datetime, date, timedelta

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

SEUIL_CONGESTION = 0.85  # 85%
SEUIL_PLATEAU = 0.05      # 5%

def variation_pct(a, b):
    if a <= 0:
        return 0
    return abs(a - b) / a

def calculer_etat(trafic_j, trafic_j1, trafic_j7, capacite):
    taux = (trafic_j / capacite * 100) if capacite > 0 else 0
    var_j1 = variation_pct(trafic_j, trafic_j1) * 100
    var_j7 = variation_pct(trafic_j, trafic_j7) * 100

    if capacite <= 0:
        return 'OK', taux, var_j1, var_j7
    if trafic_j >= capacite * SEUIL_CONGESTION:
        return 'CONGESTIONNE', taux, var_j1, var_j7
    if trafic_j1 > 0 and trafic_j7 > 0:
        if var_j1 <= SEUIL_PLATEAU * 100 and var_j7 <= SEUIL_PLATEAU * 100:
            return 'BRIDE', taux, var_j1, var_j7
    return 'OK', taux, var_j1, var_j7

def analyser_et_sauvegarder(sites_data):
    conn = psycopg2.connect(**DB_CONFIG)
    cur = conn.cursor()
    date_j = date.today()
    date_j1 = date_j - timedelta(days=1)
    date_j7 = date_j - timedelta(days=7)

    # Récupérer historique J-1 et J-7
    cur.execute("SELECT site, date_jour, max_trafic FROM trafic_historique WHERE date_jour IN (%s, %s)", (date_j1, date_j7))
    hist = {}
    for site, dj, mt in cur.fetchall():
        hist.setdefault(site, {})[dj] = float(mt or 0)

    alertes = 0
    for site in sites_data:
        nom = site.get('Site')
        trafic_j = float(site.get('Max_Trafic_Final', 0) or 0)
        capacite = float(site.get('Capacite_Mbps', 0) or 0)

        if not nom or trafic_j <= 0 or capacite <= 0:
            continue

        trafic_j1 = hist.get(nom, {}).get(date_j1, 0)
        trafic_j7 = hist.get(nom, {}).get(date_j7, 0)
        etat, taux, var_j1, var_j7 = calculer_etat(trafic_j, trafic_j1, trafic_j7, capacite)

        # Upsert site_etat
        cur.execute("""
            INSERT INTO site_etat (site, etat, trafic_j, trafic_j1, trafic_j7, capacite_mbps,
                                   taux_utilisation, variation_j1, variation_j7, date_j)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON CONFLICT (site) DO UPDATE SET
                etat=EXCLUDED.etat, trafic_j=EXCLUDED.trafic_j, trafic_j1=EXCLUDED.trafic_j1,
                trafic_j7=EXCLUDED.trafic_j7, capacite_mbps=EXCLUDED.capacite_mbps,
                taux_utilisation=EXCLUDED.taux_utilisation, variation_j1=EXCLUDED.variation_j1,
                variation_j7=EXCLUDED.variation_j7, date_j=EXCLUDED.date_j, date_calcul=NOW()
        """, (nom, etat, trafic_j, trafic_j1, trafic_j7, capacite, taux, var_j1, var_j7, date_j))

        # Alerte si état != OK
        if etat in ('CONGESTIONNE', 'BRIDE'):
            cur.execute("SELECT id FROM site_alert WHERE site=%s AND date_alerte >= NOW() - INTERVAL '1 day'", (nom,))
            if not cur.fetchone():
                msg = f"{'🔴 CONGESTION' if etat=='CONGESTIONNE' else '🟠 BRIDAGE'} - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%)"
                cur.execute("""
                    INSERT INTO site_alert (site, etat, trafic_j, capacite_mbps, taux_utilisation,
                                            variation_j1, variation_j7, type_trans, classification, message)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                """, (nom, etat, trafic_j, capacite, taux, var_j1, var_j7,
                      site.get('Type_Trans', ''), site.get('Classification', ''), msg))
                alertes += 1

    conn.commit()
    cur.close()
    conn.close()
    print(f"✅ Alertes insérées: {alertes}")
    return alertes