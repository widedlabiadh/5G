# api_python/etat_sites.py
import os
import psycopg2
import re
from datetime import datetime, date, timedelta

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

SEUIL_CONGESTION = 0.90  # 90%
SEUIL_OCCURRENCES_BRIDAGE = 50
SEUIL_OCCURRENCES_VERIFICATION_CAPACITE = 20
SEUIL_OCCURRENCES_RISQUE_CONGESTION = 57
SEUIL_PLATEAU = 0.05      # 5%
SEUIL_STABILITE_MONTANT = 0.05
CAPACITE_10G_MBPS = 10000.0  # 10G en Mbps — seuil de bridage

def extraire_prefixe(site):
    if not site:
        return ''
    s = str(site).strip()
    for suf in ['_LMB', '_LM2', '_LM', '_TDD', '_TC', '_TD', '_TF', '_FDD', '_TT']:
        if s.upper().endswith(suf):
            return s[:len(s)-len(suf)].strip()
    m = re.search(r'_(BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$', s, re.IGNORECASE)
    if m:
        return s[:m.start()].strip()
    return s

def variation_pct(a, b):
    if a <= 0:
        return 0
    return abs(a - b) / a

def est_stable(a, b, seuil=SEUIL_STABILITE_MONTANT):
    reference = max(a, b, 1.0)
    return abs(a - b) / reference <= seuil

def type_trans_backbone(type_trans):
    valeur = str(type_trans or '').strip().upper()
    return valeur == 'BACKBONE' or valeur.startswith('BH')

def calculer_etat(trafic_j, trafic_j1, trafic_j7, capacite, occurrences=0, type_trans=''):
    taux = (trafic_j / capacite * 100) if capacite > 0 else 0
    var_j1 = variation_pct(trafic_j, trafic_j1) * 100
    var_j7 = variation_pct(trafic_j, trafic_j7) * 100

    if capacite <= 0:
        return 'OK', taux, var_j1, var_j7
    occurrences = int(occurrences or 0)
    if taux >= SEUIL_CONGESTION * 100 and occurrences >= SEUIL_OCCURRENCES_RISQUE_CONGESTION:
        return 'CONGESTION', taux, var_j1, var_j7
    if taux >= SEUIL_CONGESTION * 100 and occurrences < SEUIL_OCCURRENCES_RISQUE_CONGESTION:
        return 'RISQUE_DE_CONGESTION', taux, var_j1, var_j7
    if taux < SEUIL_CONGESTION * 100 and occurrences >= SEUIL_OCCURRENCES_BRIDAGE:
        return 'BRIDAGE', taux, var_j1, var_j7
    return 'OK', taux, var_j1, var_j7


def calculer_etat_avance(trafic_j, trafic_j1, trafic_j7,
                          capacite, occurrences, classification,
                          occ_tdd=0, occ_fdd=0, taux_tdd=None, taux_fdd=None):
    """
    Etat enrichi :
    - COTRANS/NO_COTRANS : CONGESTION(FDD) / CONGESTION(TDD) / CONGESTION
    - Non-TF, capacite=10G, taux>=90%, occ>=50 : BRIDAGE
    - Autres : logique standard
    """
    classification = (classification or '').upper().strip()
    occurrences = int(occurrences or 0)
    occ_tdd = int(occ_tdd or 0)
    occ_fdd = int(occ_fdd or 0)
    taux_tdd_val = float(taux_tdd or 0)
    taux_fdd_val = float(taux_fdd or 0)

    taux = (trafic_j / capacite * 100) if capacite > 0 else 0
    var_j1 = variation_pct(trafic_j, trafic_j1) * 100
    var_j7 = variation_pct(trafic_j, trafic_j7) * 100

    if classification in ('COTRANS', 'NO_COTRANS'):
        fdd_congest = taux_fdd_val >= SEUIL_CONGESTION * 100 and occ_fdd >= SEUIL_OCCURRENCES_RISQUE_CONGESTION
        tdd_congest = taux_tdd_val >= SEUIL_CONGESTION * 100 and occ_tdd >= SEUIL_OCCURRENCES_RISQUE_CONGESTION
        if fdd_congest and tdd_congest:
            return 'CONGESTION', taux, var_j1, var_j7
        if fdd_congest:
            return 'CONGESTION(FDD)', taux, var_j1, var_j7
        if tdd_congest:
            return 'CONGESTION(TDD)', taux, var_j1, var_j7
        if ((taux_fdd_val >= SEUIL_CONGESTION * 100 and occ_fdd < SEUIL_OCCURRENCES_RISQUE_CONGESTION)
                or (taux_tdd_val >= SEUIL_CONGESTION * 100 and occ_tdd < SEUIL_OCCURRENCES_RISQUE_CONGESTION)):
            return 'RISQUE_DE_CONGESTION', taux, var_j1, var_j7
        return 'OK', taux, var_j1, var_j7

    if capacite <= 0:
        return 'OK', taux, var_j1, var_j7

    if (classification != 'TF'
            and abs(capacite - CAPACITE_10G_MBPS) < 1.0
            and taux >= SEUIL_CONGESTION * 100
            and occurrences >= SEUIL_OCCURRENCES_RISQUE_CONGESTION):
        return 'BRIDAGE', taux, var_j1, var_j7

    if taux >= SEUIL_CONGESTION * 100 and occurrences >= SEUIL_OCCURRENCES_RISQUE_CONGESTION:
        return 'CONGESTION', taux, var_j1, var_j7
    if taux >= SEUIL_CONGESTION * 100 and occurrences < SEUIL_OCCURRENCES_RISQUE_CONGESTION:
        return 'RISQUE_DE_CONGESTION', taux, var_j1, var_j7
    if taux < SEUIL_CONGESTION * 100 and occurrences >= SEUIL_OCCURRENCES_BRIDAGE:
        return 'BRIDAGE', taux, var_j1, var_j7
    return 'OK', taux, var_j1, var_j7

def type_trans_manquant(type_trans):
    if type_trans is None:
        return True
    valeur = str(type_trans).strip().upper()
    return valeur in ('', 'NON_DEFINI', 'NONE', 'N/A', 'NA', '-')

def construire_message_alerte(etat, nom, trafic_j, capacite, taux, type_trans):
    if etat == 'SANS_TYPE':
        detail_type = 'type de liaison non defini'
        if type_trans and str(type_trans).strip():
            detail_type = f'type de liaison manquant ({str(type_trans).strip()})'
        return f"⚪ SITE SANS TYPE - {nom}: {detail_type}"
    if etat in ('A_VERIFIER_CAPACITE', 'CAPACITE_A_VERIFIER'):
        return f"🔴 CAPACITE A VERIFIER - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%)"
    if etat == 'RISQUE_DE_CONGESTION':
        return f"🟠 RISQUE DE CONGESTION - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%, occurrences < {SEUIL_OCCURRENCES_RISQUE_CONGESTION})"
    if etat in ('CONGESTIONNE', 'CONGESTION'):
        return f"🔴 CONGESTION - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%)"
    if etat == 'CONGESTION(FDD)':
        return f"🔴 CONGESTION FDD - {nom}: taux FDD {taux:.1f}%"
    if etat == 'CONGESTION(TDD)':
        return f"🔴 CONGESTION TDD - {nom}: taux TDD {taux:.1f}%"
    return f"🟠 BRIDAGE - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%, occurrences >= {SEUIL_OCCURRENCES_BRIDAGE})"

def analyser_et_sauvegarder(sites_data):
    conn = psycopg2.connect(**DB_CONFIG)
    cur = conn.cursor()

    cur.execute("DELETE FROM site_alert WHERE type_trans != 'IA_ANOMALY'")

    date_j = date.today()
    date_j1 = date_j - timedelta(days=1)
    date_j7 = date_j - timedelta(days=7)
    date_j1_next = date_j1 + timedelta(days=1)
    date_j7_next = date_j7 + timedelta(days=1)

    # Recuperer l'historique depuis la table brute des courbes.
    cur.execute("""
        SELECT site, date_heure::date AS date_jour, MAX(max_speed) AS max_trafic
        FROM trafic_historique
        WHERE (date_heure >= %s AND date_heure < %s)
           OR (date_heure >= %s AND date_heure < %s)
        GROUP BY site, date_heure::date
    """, (date_j1, date_j1_next, date_j7, date_j7_next))
    hist = {}
    for site, dj, mt in cur.fetchall():
        trafic = float(mt or 0)
        for key in {site, extraire_prefixe(site)}:
            if not key:
                continue
            hist.setdefault(key, {})
            hist[key][dj] = max(hist[key].get(dj, 0), trafic)

    alertes = 0
    for site in sites_data:
        nom = site.get('Site')
        trafic_j = float(site.get('Max_Trafic_Final', 0) or 0)
        capacite = float(site.get('Capacite_Mbps', 0) or 0)
        occurrences = int(site.get('Nombre_Occurrences', 0) or 0)

        if not nom or trafic_j <= 0 or capacite <= 0:
            continue

        trafic_j1 = hist.get(nom, {}).get(date_j1, 0)
        trafic_j7 = hist.get(nom, {}).get(date_j7, 0)
        type_trans = site.get('Type_Trans', '')
        classification = site.get('Classification', '')
        occ_tdd = int(site.get('Nombre_Occurrences_TDD', 0) or 0)
        occ_fdd = int(site.get('Nombre_Occurrences_FDD', 0) or 0)
        taux_tdd = site.get('taux_utilisation_tdd')
        taux_fdd = site.get('taux_utilisation_fdd')

        etat, taux, var_j1, var_j7 = calculer_etat_avance(
            trafic_j, trafic_j1, trafic_j7, capacite, occurrences,
            classification, occ_tdd, occ_fdd, taux_tdd, taux_fdd
        )

        # Pour COTRANS/NO_COTRANS, taux_utilisation global = NULL
        cls_up = (classification or '').upper()
        taux_global_db = None if cls_up in ('COTRANS', 'NO_COTRANS') else taux

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
        """, (nom, etat, trafic_j, trafic_j1, trafic_j7, capacite, taux_global_db, var_j1, var_j7, date_j))

        alertes_a_creer = []
        if type_trans_manquant(type_trans):
            alertes_a_creer.append('SANS_TYPE')
        if etat in ('A_VERIFIER_CAPACITE', 'RISQUE_DE_CONGESTION', 'CONGESTIONNE', 'CONGESTION',
                    'CONGESTION(FDD)', 'CONGESTION(TDD)', 'BRIDE', 'BRIDAGE'):
            alertes_a_creer.append(etat)

        for etat_alerte in alertes_a_creer:
            cur.execute("""
                SELECT id FROM site_alert
                WHERE site=%s AND etat=%s AND date_alerte::date = %s
            """, (nom, etat_alerte, date_j))
            if not cur.fetchone():
                msg = construire_message_alerte(etat_alerte, nom, trafic_j, capacite, taux, type_trans)
                cur.execute("""
                    INSERT INTO site_alert (site, etat, trafic_j, capacite_mbps, taux_utilisation,
                                            variation_j1, variation_j7, type_trans, classification, message)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                """, (nom, etat_alerte, trafic_j, capacite, taux, var_j1, var_j7,
                      type_trans, classification, msg))
                alertes += 1

    conn.commit()
    cur.close()
    conn.close()
    print(f"✅ Alertes insérées: {alertes}")
    return alertes
