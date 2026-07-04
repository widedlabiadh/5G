# api_python/traitement.py — VERSION FINALE COMPLÈTE v6
# Corrections :
# 1. extraire_prefixe gère _BH_XX, _BO_XX, _TR_XX etc. → bon regroupement
# 2. est_site_fdd détecte _BH/_BO/_TR/_OO avec suffixe géographique
# 3. Sites sans suffixe connu (ex: BEJ_0021) → classification '-'
# 4. type_trans = valeur BRUTE du fichier (BH TT, BH OO, TDD FO+FDD FH…) sans normalisation
# 5. NON_DEFINI = site absent du fichier type liaison
# 6. L2B supprimés uniquement du fichier trafic (pas de l'analyse globale)
# 7. Fallback vers table capacite_site si type_trans = 'NON_DEFINI'
# 8. AJOUT: Capacité extraite depuis port_data et stockée dans analyse_resultat
# 9. AJOUT GPS: Import des coordonnées depuis fichier Excel/CSV
# 10. AJOUT ALERTES: Calcul état CONGESTION/BRIDAGE/OK + insertion site_etat et site_alert
# 11. AJOUT HISTORIQUE: Insertion dans trafic_historique

import pandas as pd
import io
import re
import json
from datetime import datetime, timedelta
import psycopg2
from psycopg2.extras import execute_values
import os
import numpy as np
import traceback

# ========== CONFIG BDD ==========
DB_CONFIG = {
    "host":     os.getenv("DB_HOST",     "127.0.0.1"),
    "port":     int(os.getenv("DB_PORT", "5432")),
    "dbname":   os.getenv("DB_NAME",     "PFE_5G"),
    "user":     os.getenv("DB_USER",     "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

# ========== SEUILS POUR ALERTES ==========
SEUIL_CONGESTION = 0.90  # 90% capacité = congestion
SEUIL_OCCURRENCES_BRIDAGE = 50
SEUIL_OCCURRENCES_VERIFICATION_CAPACITE = 20
SEUIL_OCCURRENCES_RISQUE_CONGESTION = 57
SEUIL_PLATEAU = 0.05      # 5% variation max pour considérer "stable"
SEUIL_STABILITE_MONTANT = 0.05

# ========== DÉTECTION TYPES ==========

def est_site_tf(site: str) -> bool:
    return bool(re.search(r'_tf$', str(site), re.IGNORECASE))

def est_site_tdd(site: str) -> bool:
    return bool(re.search(r'_(TC|TD|TDD)$', str(site), re.IGNORECASE))

def est_site_fdd(site: str) -> bool:
    return bool(re.search(r'_(LM|LMB|LM2|BO|BH|TR|OO|OR|TT)(_.+)?$', str(site), re.IGNORECASE))

def a_suffixe_connu(site: str) -> bool:
    return est_site_tf(site) or est_site_tdd(site) or est_site_fdd(site)

# ========== UTILITAIRES ==========

def normaliser_type_trans(valeur: str) -> str:
    return str(valeur).strip()

def extraire_prefixe(site: str) -> str:
    if not site or (isinstance(site, float) and pd.isna(site)): return ''
    s = str(site).strip()
    for suf in ['_LMB', '_LM2', '_LM', '_TDD', '_TC', '_TD', '_TF', '_FDD', '_TT']:
        if s.upper().endswith(suf.upper()):
            return s[:len(s)-len(suf)].strip()
    m = re.search(r'_(BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$', s, re.IGNORECASE)
    if m:
        return s[:m.start()].strip()
    return s

def to_py(val):
    if val is None: return None
    try:
        if pd.isna(val): return None
    except Exception: pass
    if isinstance(val, (np.integer, np.floating)): return val.item()
    return val

def to_float(val) -> float:
    try:
        if val is None or pd.isna(val):
            return 0.0
    except Exception:
        if val is None:
            return 0.0
    try:
        return float(str(val).replace(',', '.').strip())
    except Exception:
        return 0.0

def max_dropcong(df: pd.DataFrame) -> int:
    if df is None or df.empty or 'DropCongDownBWNum' not in df.columns:
        return 0
    try:
        return int(float(df['DropCongDownBWNum'].max() or 0))
    except Exception:
        return 0

def calculer_nombre_occurrences(df: pd.DataFrame) -> int:
    if df is None or df.empty or 'MaxSpeed' not in df.columns:
        return 0
    max_val = float(df['MaxSpeed'].max())
    if max_val <= 0:
        return 0
    seuil = max_val * 0.9
    return int((df['MaxSpeed'] >= seuil).sum())

def get_type_trans_for_prefix(prefix, type_dict, capacite_dict=None):
    if prefix in type_dict:
        return type_dict[prefix]
    parts = prefix.split('_')
    for i in range(len(parts)-1, 0, -1):
        key = '_'.join(parts[:i])
        if key in type_dict:
            return type_dict[key]
    if capacite_dict:
        if prefix in capacite_dict:
            return capacite_dict[prefix]
        for i in range(len(parts)-1, 0, -1):
            key = '_'.join(parts[:i])
            if key in capacite_dict:
                return capacite_dict[key]
    return 'NON_DEFINI'

# ========== FONCTIONS POUR LES CAPACITÉS ==========

def get_capacite_from_port_data(nom_site: str, port_info: dict) -> float:
    if not nom_site or nom_site not in port_info:
        return 0.0
    ports = port_info[nom_site]
    if '_TF' in str(nom_site).upper():
        capacite = ports.get('port0', 0) + ports.get('port1', 0)
    else:
        capacite = max(ports.get('port0', 0), ports.get('port1', 0))
    return float(capacite)

def resolve_capacites_tdd_fdd(classification, site_tf, site_tdd, site_fdd, site_simple, prefix, capacites_from_ports):
  """Retourne (cap_tdd, cap_fdd) depuis les capacités port/import."""
  cap_tdd = 0.0
  cap_fdd = 0.0
  classification = (classification or '').upper().strip()

  if classification == 'TF':
    cap_fdd = _latest_capacity_for_sites(capacites_from_ports, [
      site_tf,
      f"{prefix}_TF" if prefix else None,
      prefix,
      site_simple,
      site_fdd,
    ])
    return 0.0, cap_fdd

  if site_tdd and site_tdd in capacites_from_ports:
    cap_tdd = _capacity_value(capacites_from_ports, site_tdd)
  if site_fdd and site_fdd in capacites_from_ports:
    cap_fdd = _capacity_value(capacites_from_ports, site_fdd)
  if site_simple and site_simple in capacites_from_ports:
    cap_fdd = max(cap_fdd, _capacity_value(capacites_from_ports, site_simple))
  if site_tf and site_tf in capacites_from_ports:
    cap_fdd = max(cap_fdd, _capacity_value(capacites_from_ports, site_tf))

  if cap_tdd <= 0 and cap_fdd <= 0 and prefix in capacites_from_ports:
    fallback = _capacity_value(capacites_from_ports, prefix)
    if (classification or '').upper() in ('TF', 'ONLY_FDD', 'FDD', '-'):
      cap_fdd = fallback
    else:
      cap_tdd = fallback

  return cap_tdd, cap_fdd


def effective_capacity_for_utilization(classification, cap_tdd, cap_fdd, fallback=0.0):
  """
  TF / ONLY_FDD / FDD → capacité FDD seule.
  Autres classifications → TDD + FDD.
  """
  classification = (classification or '').upper().strip()
  cap_tdd = float(cap_tdd or 0)
  cap_fdd = float(cap_fdd or 0)
  fallback = float(fallback or 0)

  if classification in ('TF', 'ONLY_FDD', 'FDD', '-'):
    if cap_fdd > 0:
      return cap_fdd
    return fallback

  total = cap_tdd + cap_fdd
  if total > 0:
    return total

  return fallback


def _parse_import_date(value):
    if not value:
        return datetime.min
    if isinstance(value, datetime):
        return value
    try:
        return datetime.fromisoformat(str(value).replace(' ', 'T'))
    except Exception:
        return datetime.min


def _capacity_value(capacites: dict, site: str) -> float:
    entry = capacites.get(site)
    if isinstance(entry, dict):
        return float(entry.get('value') or 0)
    return float(entry or 0)


def _capacity_date(capacites: dict, site: str):
    entry = capacites.get(site)
    if isinstance(entry, dict):
        return entry.get('date')
    return None


def _latest_capacity_for_sites(capacites: dict, sites) -> float:
    selected_value = 0.0
    selected_date = datetime.min

    for site in sites:
        if not site or site not in capacites:
            continue

        candidate_value = _capacity_value(capacites, site)
        candidate_date = _parse_import_date(_capacity_date(capacites, site))
        if candidate_value > 0 and candidate_date >= selected_date:
            selected_value = candidate_value
            selected_date = candidate_date

    return selected_value


def _set_latest_capacity(capacites: dict, site: str, value: float, date_import=None):
    site = str(site).strip()
    value = float(value or 0)
    if not site or value <= 0:
        return

    current = capacites.get(site)
    if current is None or _parse_import_date(date_import) >= _parse_import_date(_capacity_date(capacites, site)):
        capacites[site] = {'value': value, 'date': date_import}


def charger_capacites_from_port_data(port_info: dict) -> dict:
    capacites = {}
    prefix_caps = {}
    for site, ports in port_info.items():
        if '_TF' in str(site).upper():
            capa = ports.get('port0', 0) + ports.get('port1', 0)
        else:
            capa = max(ports.get('port0', 0), ports.get('port1', 0))
        date_import = ports.get('date')
        if capa > 0:
            _set_latest_capacity(capacites, site, capa, date_import)
            prefix = extraire_prefixe(site)
            if prefix and prefix != site:
                prefix_caps.setdefault(prefix, {
                    'tdd': 0.0, 'tdd_date': None,
                    'fdd': 0.0, 'fdd_date': None,
                    'other': 0.0, 'other_date': None,
                })
                if est_site_tdd(site):
                    if capa >= prefix_caps[prefix]['tdd']:
                        prefix_caps[prefix]['tdd'] = capa
                        prefix_caps[prefix]['tdd_date'] = date_import
                elif est_site_fdd(site):
                    if capa >= prefix_caps[prefix]['fdd']:
                        prefix_caps[prefix]['fdd'] = capa
                        prefix_caps[prefix]['fdd_date'] = date_import
                else:
                    if capa >= prefix_caps[prefix]['other']:
                        prefix_caps[prefix]['other'] = capa
                        prefix_caps[prefix]['other_date'] = date_import
    for prefix, caps in prefix_caps.items():
        total = caps['tdd'] + caps['fdd']
        capa = total if total > 0 else caps['other']
        date_import = max(
            _parse_import_date(caps.get('tdd_date')),
            _parse_import_date(caps.get('fdd_date')),
            _parse_import_date(caps.get('other_date')),
        )
        if capa > 0:
            _set_latest_capacity(capacites, prefix, capa, date_import)
    return capacites


def charger_capacites_from_capacite_site() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("""
            SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'capacite_site')
        """)
        if not cur.fetchone()[0]:
            cur.close()
            conn.close()
            return {}

        cur.execute("""
            SELECT site, capacite_mbps, date_mise_ajour
            FROM capacite_site
            WHERE capacite_mbps > 0
            ORDER BY date_mise_ajour DESC, id DESC
        """)
        rows = cur.fetchall()
        cur.close()
        conn.close()

        capacites = {}
        for site, capacity, date_import in rows:
            site_key = str(site).strip()
            _set_latest_capacity(capacites, site_key, float(capacity or 0), date_import)
            prefix = extraire_prefixe(site_key)
            if prefix and prefix != site_key:
                _set_latest_capacity(capacites, prefix, float(capacity or 0), date_import)

        print(f"📡 Capacités depuis capacite_site : {len(capacites)} sites")
        return capacites
    except Exception as e:
        print(f"⚠️ capacités capacite_site : {e}")
        return {}


def fusionner_capacites_dernier_import(*capacity_maps: dict) -> dict:
    merged = {}
    for capacity_map in capacity_maps:
        for site, entry in capacity_map.items():
            _set_latest_capacity(merged, site, _capacity_value(capacity_map, site), _capacity_date(capacity_map, site))
    return merged


def fusionner_capacites_priorite_import(port_capacity_map: dict, imported_capacity_map: dict) -> dict:
    merged = {}
    for site, entry in port_capacity_map.items():
        _set_latest_capacity(merged, site, _capacity_value(port_capacity_map, site), _capacity_date(port_capacity_map, site))
    for site, entry in imported_capacity_map.items():
        value = _capacity_value(imported_capacity_map, site)
        if value > 0:
            merged[site] = {'value': value, 'date': _capacity_date(imported_capacity_map, site)}
    return merged

# ========== CHARGEMENT GPS ==========

def charger_coordonnees_gps(file_content) -> dict:
    if not file_content:
        print("   📍 Aucun fichier GPS fourni")
        return {}
    try:
        if isinstance(file_content, bytes):
            df = pd.read_excel(io.BytesIO(file_content))
        else:
            df = pd.read_excel(file_content)
        print(f"   📍 Fichier GPS chargé: {len(df)} lignes")
        df.columns = df.columns.str.strip()
        site_col = None
        for col in df.columns:
            if 'site' in col.lower() or 'enodeb' in col.lower() or 'node' in col.lower():
                site_col = col
                break
        if not site_col:
            site_col = df.columns[0]
        lat_col = None
        lng_col = None
        for col in df.columns:
            if 'lat' in col.lower():
                lat_col = col
            elif 'lon' in col.lower() or 'long' in col.lower():
                lng_col = col
        if not lat_col or not lng_col:
            print("   ⚠️ Colonnes lat/long non trouvées")
            return {}
        coords_dict = {}
        for _, row in df.iterrows():
            site = str(row[site_col]).strip()
            if not site or site == 'nan':
                continue
            try:
                lat = float(row[lat_col])
                lng = float(row[lng_col])
                if lat != 0 and lng != 0:
                    coords_dict[site] = {'latitude': lat, 'longitude': lng}
            except (ValueError, TypeError):
                continue
        print(f"   ✅ GPS chargé: {len(coords_dict)} sites")
        return coords_dict
    except Exception as e:
        print(f"   ❌ Erreur chargement GPS: {e}")
        return {}

# ========== LECTURE TRAFIC ==========

def lire_excel_trafic_directement(file_content: bytes) -> pd.DataFrame:
    for engine in ['openpyxl', 'xlrd', None]:
        try:
            kw = {'engine': engine} if engine else {}
            df = pd.read_excel(io.BytesIO(file_content), **kw)
            break
        except: continue

    df.columns = df.columns.str.strip()
    rename_map = {}
    for col in df.columns:
        cl = col.lower()
        if 'enodeb' in cl or ('name' in cl and 'site' not in cl):
            rename_map[col] = 'eNodeB_Name'
        elif 'dropcong' in cl and 'downbwnum' in cl:
            rename_map[col] = 'DropCongDownBWNum'
        elif 'maxspeed' in cl or 'rxmaxspeed' in cl or 'mbit' in cl or 'rxmaxspeed_mbs' in cl:
            rename_map[col] = 'MaxSpeed'
    df = df.rename(columns=rename_map)

    time_col = next((c for c in df.columns if 'time' in c.lower() or 'date' in c.lower()), df.columns[0])
    df['DateTime'] = pd.to_datetime(df[time_col], dayfirst=True, errors='coerce')
    df = df.dropna(subset=['DateTime'])

    if 'MaxSpeed' not in df.columns:
        for col in df.columns:
            try:
                test = df[col].iloc[0]
                if isinstance(test, (int, float)) or str(test).replace(',', '').replace('.', '').isdigit():
                    df = df.rename(columns={col: 'MaxSpeed'})
                    break
            except:
                continue
    
    if 'MaxSpeed' not in df.columns:
        df['MaxSpeed'] = df.iloc[:, -1]
    
    df['MaxSpeed'] = pd.to_numeric(
        df['MaxSpeed'].astype(str).str.replace(',', '.', regex=False)
                                  .str.replace(r'[^\d.\-]', '', regex=True),
        errors='coerce'
    )
    if 'DropCongDownBWNum' not in df.columns:
        df['DropCongDownBWNum'] = 0
    else:
        df['DropCongDownBWNum'] = pd.to_numeric(
            df['DropCongDownBWNum'].astype(str).str.replace(',', '.', regex=False)
                                           .str.replace(r'[^\d.\-]', '', regex=True),
            errors='coerce'
        ).fillna(0)
    df = df.dropna(subset=['MaxSpeed'])
    df = df[df['MaxSpeed'] >= 0]

    avant = len(df)
    df = df[~df['eNodeB_Name'].str.contains('L2B', na=False, case=False)]
    print(f"🚫 Sites L2B supprimés : {avant - len(df)}")
    print(f"📊 Trafic chargé : {len(df)} mesures")
    return df

def calculer_duree_jours_trafic(df_trafic: pd.DataFrame) -> int:
    """Calcule la duree couverte par le fichier trafic a partir de DateTime."""
    if df_trafic.empty or 'DateTime' not in df_trafic.columns:
        return 7

    dates = pd.to_datetime(df_trafic['DateTime'], errors='coerce').dropna()
    if dates.empty:
        return 7

    min_date = dates.min()
    max_date = dates.max()
    if hasattr(min_date, 'to_pydatetime'):
        min_date = min_date.to_pydatetime()
    if hasattr(max_date, 'to_pydatetime'):
        max_date = max_date.to_pydatetime()

    return max(1, (max_date.date() - min_date.date()).days + 1)

# ========== CHARGEMENT BDD ==========

def charger_port_data_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        suffix_pattern = r'_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$'
        cur.execute("""
            SELECT site, port_no, trafic, date_import
            FROM (
                SELECT site, port_no, trafic, date_import,
                       MAX(date_import) OVER (PARTITION BY UPPER(REGEXP_REPLACE(site, %s, '', 'i'))) AS latest_site_date,
                       ROW_NUMBER() OVER (PARTITION BY UPPER(site), port_no, date_import ORDER BY id DESC) AS row_num
                FROM port_data
            ) latest_ports
            WHERE date_import = latest_site_date AND row_num = 1
            ORDER BY UPPER(site), port_no
        """, (suffix_pattern,))
        rows = cur.fetchall()
        cur.close()
        conn.close()
        port_info = {}
        for site, port_no, trafic, date_import in rows:
            s = str(site).strip()
            if s not in port_info:
                port_info[s] = {'port0': 0, 'port1': 0, 'date': None}
            port_info[s]['date'] = max(
                _parse_import_date(port_info[s].get('date')),
                _parse_import_date(date_import),
            )
            if port_no == 0:
                port_info[s]['port0'] = float(trafic) if trafic else 0
            elif port_no == 1:
                port_info[s]['port1'] = float(trafic) if trafic else 0
        print(f"📡 Port data : {len(port_info)} sites")
        return port_info
    except Exception as e:
        print(f"⚠️ port_data : {e}")
        return {}

def charger_type_liaison_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("""
            SELECT site, type_trans
            FROM type_liaison_data
            ORDER BY date_import DESC, id DESC
        """)
        rows = cur.fetchall()
        cur.close()
        conn.close()
        type_dict = {}
        for site, type_trans in rows:
            s = str(site).strip()
            val = normaliser_type_trans(type_trans)
            if s not in type_dict:
                type_dict[s] = val
            p = extraire_prefixe(s)
            if p and p != s and p not in type_dict:
                type_dict[p] = val
        print(f"📡 Type liaison : {len(type_dict)} entrées")
        return type_dict
    except Exception as e:
        print(f"⚠️ type_liaison : {e}")
        return {}

def charger_capacite_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("""
            SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'capacite_site')
        """)
        table_exists = cur.fetchone()[0]
        if not table_exists:
            cur.close()
            conn.close()
            return {}
        cur.execute("""
            SELECT site, type_trans
            FROM capacite_site
            WHERE type_trans IS NOT NULL
            ORDER BY date_mise_ajour DESC, id DESC
        """)
        rows = cur.fetchall()
        cur.close()
        conn.close()
        capacite_dict = {}
        for site, type_trans in rows:
            s = str(site).strip()
            if type_trans:
                if s not in capacite_dict:
                    capacite_dict[s] = normaliser_type_trans(str(type_trans))
                p = extraire_prefixe(s)
                if p and p != s and p not in capacite_dict:
                    capacite_dict[p] = normaliser_type_trans(str(type_trans))
        print(f"📡 Capacite_site : {len(capacite_dict)} entrées")
        return capacite_dict
    except Exception as e:
        print(f"⚠️ capacite_site : {e}")
        return {}

# ========== MAJ PORT / TYPE LIAISON ==========

def update_port_data(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        site_col = next((c for c in df.columns if 'enodeb' in c.lower() or 'name' in c.lower()), df.columns[0])
        port_col = next((c for c in df.columns if 'port' in c.lower()), None)
        rx_max_col = next((c for c in df.columns if 'rxmaxspeed' in c.lower()), None)
        total_bw_col = next((c for c in df.columns if 'rxtotalbw' in c.lower() or 'totalbw' in c.lower()), None)
        traf_col = next((c for c in df.columns if 'speed' in c.lower() or 'trafic' in c.lower() or 'mbit' in c.lower()), None)
        if not port_col or not ((rx_max_col and total_bw_col) or traf_col):
            print("⚠️ Colonnes port introuvables")
            return
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        n = 0
        import_date = datetime.now()
        for _, row in df.iterrows():
            site = str(row[site_col]).strip()
            if not site or site == 'nan':
                continue
            try:
                if rx_max_col and total_bw_col:
                    capacity = to_float(row[total_bw_col])
                else:
                    capacity = to_float(row[traf_col])
                cur.execute("""
                    INSERT INTO port_data (site, port_no, trafic, date_import)
                    VALUES (%s,%s,%s,%s)
                    ON CONFLICT (site, port_no) DO UPDATE SET trafic=EXCLUDED.trafic, date_import=EXCLUDED.date_import
                """, (site, int(float(row[port_col])),
                      capacity, import_date))
                n += 1
            except:
                continue
        conn.commit()
        cur.close()
        conn.close()
        print(f"   ✅ port_data MAJ : {n}")
    except Exception as e:
        print(f"   ❌ port_data: {e}")

def update_type_liaison_data(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        col_site = next((c for c in df.columns if 'site' in c.lower() or 'node' in c.lower()), df.columns[0])
        col_type = next((c for c in df.columns if 'type' in c.lower() or 'liaison' in c.lower()),
                        df.columns[1] if len(df.columns) > 1 else col_site)
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        n = 0
        for _, row in df.iterrows():
            site = str(row[col_site]).strip()
            if not site or site == 'nan':
                continue
            t = str(row[col_type]).strip()
            if not t:
                continue
            cur.execute("""
                UPDATE type_liaison_data
                SET type_trans = %s, date_import = %s
                WHERE site = %s
            """, (t, datetime.now(), site))
            if cur.rowcount == 0:
                cur.execute("""
                    INSERT INTO type_liaison_data (site, type_trans, date_import)
                    VALUES (%s, %s, %s)
                """, (site, t, datetime.now()))
            n += 1
        conn.commit()
        cur.close()
        conn.close()
        print(f"   ✅ type_liaison MAJ : {n}")
    except Exception as e:
        print(f"   ❌ type_liaison: {e}")

# ========== INSERTION ANALYSE ==========

def inserer_analyse_resultat(sites_data):
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        for col, defn in [
            ('stats_json', 'JSONB'),
            ('max_trafic_fdd', 'NUMERIC(15,4)'),
            ('max_trafic_tdd', 'NUMERIC(15,4)'),
            ('nombre_occurrences_tdd', 'INT DEFAULT 0'),
            ('nombre_occurrences_fdd', 'INT DEFAULT 0'),
            ('capacite_mbps', 'NUMERIC(15,4) DEFAULT 0'),
            ('duree_jours', 'INT DEFAULT 7'),
            ('dropcong_tdd', 'INT DEFAULT 0'),
            ('dropcong_fdd', 'INT DEFAULT 0'),
            ('dropcong_tf', 'INT DEFAULT 0'),
        ]:
            cur.execute(f"""
                SELECT column_name FROM information_schema.columns
                WHERE table_name='analyse_resultat' AND column_name='{col}'
            """)
            if not cur.fetchone():
                cur.execute(f"ALTER TABLE analyse_resultat ADD COLUMN {col} {defn}")
                conn.commit()
                print(f"✅ Colonne {col} ajoutée")

        date_analyse = datetime.now()
        count = 0
        for site in sites_data:
            cur.execute("""
                INSERT INTO analyse_resultat
                (site, classification, type_trans, max_trafic, seuil_critique,
                 nombre_occurrences, total_measures, date_max, site_tdd, site_fdd,
                 somme_fdd_tdd, date_analyse, latitude, longitude, duree_jours,
                 max_trafic_fdd, max_trafic_tdd, nombre_occurrences_tdd,
                 nombre_occurrences_fdd, capacite_mbps, dropcong_tdd,
                 dropcong_fdd, dropcong_tf)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """, (
                to_py(site.get('Site')),
                to_py(site.get('Classification')),
                to_py(site.get('Type_Trans')),
                to_py(site.get('Max_Trafic_Final', 0)),
                to_py(site.get('Seuil_Critique', 0)),
                to_py(site.get('Nombre_Occurrences', 0)),
                to_py(site.get('Total_Measures', 0)),
                to_py(site.get('Date_Max')),
                to_py(site.get('Site_TDD')),
                to_py(site.get('Site_FDD')),
                to_py(site.get('Somme_FDD_TDD')),
                date_analyse,
                to_py(site.get('Latitude')),
                to_py(site.get('Longitude')),
                to_py(site.get('Duree_Jours', 7)),
                to_py(site.get('Max_Trafic_FDD', 0)),
                to_py(site.get('Max_Trafic_TDD', 0)),
                to_py(site.get('Nombre_Occurrences_TDD', 0)),
                to_py(site.get('Nombre_Occurrences_FDD', 0)),
                to_py(site.get('Capacite_Mbps', 0)),
                to_py(site.get('DropCong_TDD', 0)),
                to_py(site.get('DropCong_FDD', 0)),
                to_py(site.get('DropCong_TF', 0)),
            ))
            count += 1
        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ {count} lignes insérées dans analyse_resultat")
        return count
    except Exception as e:
        print(f"⚠️ Insertion analyse_resultat : {e}")
        traceback.print_exc()
        return 0

# ========== INSERTION HISTORIQUE TRAFIC ==========

def inserer_trafic_historique(sites_data):
    """Insère le trafic journalier dans trafic_historique"""
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        date_jour = datetime.now().date()
        count = 0
        
        # Vérifier la structure de la table
        cur.execute("""
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'trafic_historique' AND column_name = 'date_jour'
        """)
        has_date_jour = cur.fetchone() is not None
        
        for site in sites_data:
            site_name = site.get('Site')
            max_trafic = site.get('Max_Trafic_Final', 0)
            capacite = site.get('Capacite_Mbps', 0)
            classification = site.get('Classification', '')
            type_trans = site.get('Type_Trans', '')
            
            if not site_name:
                continue
            
            if has_date_jour:
                cur.execute("""
                    INSERT INTO trafic_historique 
                        (site, date_jour, max_trafic, capacite_mbps, classification, type_trans)
                    VALUES (%s, %s, %s, %s, %s, %s)
                    ON CONFLICT (site, date_jour) DO UPDATE SET
                        max_trafic = EXCLUDED.max_trafic,
                        capacite_mbps = EXCLUDED.capacite_mbps,
                        classification = EXCLUDED.classification,
                        type_trans = EXCLUDED.type_trans
                """, (site_name, date_jour, float(max_trafic), float(capacite), classification, type_trans))
            else:
                # La table legacy sert aux courbes et contient les noms bruts du fichier trafic.
                # Ne pas y ajouter les prefixes agreges, sinon l'interface affiche des sites inexistants.
                continue
            
            count += 1
        
        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ Historique inséré : {count} sites")
        return count
    except Exception as e:
        print(f"⚠️ Erreur insertion historique : {e}")
        traceback.print_exc()
        return 0

def inserer_trafic_historique_brut(df_trafic: pd.DataFrame) -> int:
    """Insere les mesures brutes du fichier trafic pour les courbes."""
    try:
        if df_trafic.empty:
            return 0

        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        date_import = datetime.now()
        count = 0

        raw = df_trafic[['eNodeB_Name', 'DateTime', 'MaxSpeed']].copy()
        raw['eNodeB_Name'] = raw['eNodeB_Name'].astype(str).str.strip()
        raw = raw.dropna(subset=['DateTime', 'MaxSpeed'])
        raw = raw[raw['eNodeB_Name'] != '']
        raw = raw.drop_duplicates(subset=['eNodeB_Name', 'DateTime'], keep='last')

        sites = raw['eNodeB_Name'].dropna().unique().tolist()
        min_date = raw['DateTime'].min()
        max_date = raw['DateTime'].max()
        if hasattr(min_date, 'to_pydatetime'):
            min_date = min_date.to_pydatetime()
        if hasattr(max_date, 'to_pydatetime'):
            max_date = max_date.to_pydatetime()
        duree_jours = max(1, (max_date.date() - min_date.date()).days + 1) if min_date and max_date else 7

        if sites:
            cur.execute(
                """
                DELETE FROM trafic_historique
                WHERE site = ANY(%s)
                  AND date_heure BETWEEN %s AND %s
                """,
                (sites, min_date, max_date)
            )

        rows = []
        for row in raw.itertuples(index=False):
            date_heure = row.DateTime
            if hasattr(date_heure, 'to_pydatetime'):
                date_heure = date_heure.to_pydatetime()
            rows.append((str(row.eNodeB_Name).strip(), date_heure, float(row.MaxSpeed), date_import))

        if rows:
            execute_values(
                cur,
                """
                INSERT INTO trafic_historique
                    (site, date_heure, max_speed, date_importation)
                VALUES %s
                """,
                rows,
                page_size=10000
            )
        count = len(rows)

        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ Historique brut inséré : {count} mesures")
        return count
    except Exception as e:
        print(f"⚠️ Erreur insertion historique brut : {e}")
        traceback.print_exc()
        return 0

# ========== ANALYSE ÉTATS ET ALERTES ==========

def variation_pct(a: float, b: float) -> float:
    if a <= 0:
        return 0
    return abs(a - b) / a

def est_stable(a: float, b: float, seuil: float = SEUIL_STABILITE_MONTANT) -> bool:
    reference = max(a, b, 1.0)
    return abs(a - b) / reference <= seuil

def type_trans_backbone(type_trans: str) -> bool:
    valeur = str(type_trans or '').strip().upper()
    return valeur == 'BACKBONE' or valeur.startswith('BH')

def calculer_etat(trafic_j: float, trafic_j1: float, trafic_j7: float, capacite: float, occurrences: int = 0, type_trans: str = '') -> tuple:
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

def type_trans_manquant(type_trans: str) -> bool:
    if type_trans is None:
        return True
    valeur = str(type_trans).strip().upper()
    return valeur in ('', 'NON_DEFINI', 'NONE', 'N/A', 'NA', '-')

def construire_message_alerte(etat: str, nom: str, trafic_j: float, capacite: float, taux: float, type_trans: str) -> str:
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
    return f"🟠 BRIDAGE - {nom}: {trafic_j:.0f}/{capacite:.0f} Mbps ({taux:.1f}%, occurrences >= {SEUIL_OCCURRENCES_BRIDAGE})"

def analyser_etats_et_alertes(sites_data: list) -> int:
    """
    Analyse l'état de chaque site et insère les alertes dans site_alert
    Retourne le nombre d'alertes insérées
    """
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        
        date_j = datetime.now().date()
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
        
        alertes_count = 0
        
        for site in sites_data:
            nom = site.get('Site')
            trafic_j = float(site.get('Max_Trafic_Final', 0) or 0)
            capacite = float(site.get('Capacite_Mbps', 0) or 0)
            type_trans = site.get('Type_Trans', '')
            classification = site.get('Classification', '')
            occurrences = int(site.get('Nombre_Occurrences', 0) or 0)
            
            if not nom or trafic_j <= 0 or capacite <= 0:
                continue
            
            trafic_j1 = hist.get(nom, {}).get(date_j1, 0)
            trafic_j7 = hist.get(nom, {}).get(date_j7, 0)
            
            etat, taux, var_j1, var_j7 = calculer_etat(trafic_j, trafic_j1, trafic_j7, capacite, occurrences, type_trans)
            
            # Upsert dans site_etat
            cur.execute("""
                INSERT INTO site_etat 
                    (site, etat, trafic_j, trafic_j1, trafic_j7, capacite_mbps,
                     taux_utilisation, variation_j1, variation_j7, date_j)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (site) DO UPDATE SET
                    etat = EXCLUDED.etat,
                    trafic_j = EXCLUDED.trafic_j,
                    trafic_j1 = EXCLUDED.trafic_j1,
                    trafic_j7 = EXCLUDED.trafic_j7,
                    capacite_mbps = EXCLUDED.capacite_mbps,
                    taux_utilisation = EXCLUDED.taux_utilisation,
                    variation_j1 = EXCLUDED.variation_j1,
                    variation_j7 = EXCLUDED.variation_j7,
                    date_j = EXCLUDED.date_j,
                    date_calcul = NOW()
            """, (nom, etat, trafic_j, trafic_j1, trafic_j7, capacite,
                  round(taux, 2), round(var_j1, 2), round(var_j7, 2), date_j))
            
            # Créer alerte si état problématique ou type manquant.
            # La déduplication se fait par site + état pour permettre plusieurs alertes distinctes le même jour.
            alerte_a_creer = []
            if type_trans_manquant(type_trans):
                alerte_a_creer.append('SANS_TYPE')
            if etat in ('A_VERIFIER_CAPACITE', 'RISQUE_DE_CONGESTION', 'CONGESTIONNE', 'CONGESTION', 'BRIDE', 'BRIDAGE'):
                alerte_a_creer.append(etat)

            for etat_alerte in alerte_a_creer:
                cur.execute("""
                SELECT id FROM site_alert
                WHERE site = %s
                  AND etat = %s
                  AND date_alerte::date = %s
                LIMIT 1
            """, (nom, etat_alerte, date_j))
                if not cur.fetchone():
                    msg = construire_message_alerte(etat_alerte, nom, trafic_j, capacite, taux, type_trans)
                    
                    cur.execute("""
                        INSERT INTO site_alert 
                            (site, etat, trafic_j, capacite_mbps, taux_utilisation,
                             variation_j1, variation_j7, type_trans, classification, message)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """, (nom, etat_alerte, trafic_j, capacite, round(taux, 2),
                          round(var_j1, 2), round(var_j7, 2), type_trans, classification, msg))
                    alertes_count += 1
        
        conn.commit()
        cur.close()
        conn.close()
        
        print(f"   🔔 Alertes insérées: {alertes_count}")
        return alertes_count
        
    except Exception as e:
        print(f"⚠️ Erreur analyse états: {e}")
        traceback.print_exc()
        return 0

# ========== TRAITEMENT PRINCIPAL ==========

def traiter_fichiers(file_trafic_content, file_port_content=None,
                     file_type_content=None, file_gps_content=None):
    import time
    start_total = time.time()
    print("📂 Début du traitement...")

    try:
        # 1. Lire trafic
        start = time.time()
        df_trafic = lire_excel_trafic_directement(file_trafic_content)
        print(f"   ✅ Lecture trafic : {time.time()-start:.2f}s")
        if df_trafic.empty:
            return {"status": "error", "message": "Aucune donnée valide"}

        # 2. MAJ optionnelles
        duree_jours = calculer_duree_jours_trafic(df_trafic)
        print(f"   Duree analyse : {duree_jours} jour(s)")

        if file_port_content:
            start = time.time()
            update_port_data(file_port_content)
            print(f"   ✅ port_data : {time.time()-start:.2f}s")
        if file_type_content:
            start = time.time()
            update_type_liaison_data(file_type_content)
            print(f"   ✅ type_liaison : {time.time()-start:.2f}s")

        # 3. Charger BDD
        start = time.time()
        port_info = charger_port_data_as_dict()
        type_dict = charger_type_liaison_as_dict()
        capacite_dict = charger_capacite_as_dict()
        capacites_from_ports = charger_capacites_from_port_data(port_info)
        capacites_from_capacite_site = charger_capacites_from_capacite_site()
        capacites_from_ports = fusionner_capacites_priorite_import(
            capacites_from_ports,
            capacites_from_capacite_site,
        )
        print(f"   ✅ BDD chargée : {time.time()-start:.2f}s")
        print(f"   📡 Capacités depuis port_data : {len(capacites_from_ports)} sites")

        # Charger les coordonnées GPS
        coords_dict = charger_coordonnees_gps(file_gps_content) if file_gps_content else {}
        print(f"   📍 GPS chargé: {len(coords_dict)} sites")

        print(f"📄 Trafic : {len(df_trafic)} mesures")
        print("\n📊 CLASSIFICATION...")

        # Préparer l'index par préfixe
        df_trafic['Prefixe'] = df_trafic['eNodeB_Name'].apply(extraire_prefixe)
        empty_trafic = pd.DataFrame(columns=df_trafic.columns)
        trafic_by_site = {
            site: group
            for site, group in df_trafic.groupby('eNodeB_Name', sort=False)
        }
        trafic_names_by_prefix = {
            prefix: list(values)
            for prefix, values in df_trafic.groupby('Prefixe', sort=False)['eNodeB_Name'].unique().items()
        }
        port_names_by_prefix = {}
        for site in port_info.keys():
            port_names_by_prefix.setdefault(extraire_prefixe(site), []).append(site)

        prefixes_trafic = set(trafic_names_by_prefix.keys())
        prefixes_port = set(port_names_by_prefix.keys())
        prefixes_uniques = [p for p in (prefixes_trafic | prefixes_port) if p]
        print(f"📌 Préfixes uniques : {len(prefixes_uniques)}")

        resultats = {}

        for i, prefix in enumerate(prefixes_uniques):
            if (i + 1) % 500 == 0:
                print(f"   Traitement {i+1}/{len(prefixes_uniques)}...")

            noms_trafic = trafic_names_by_prefix.get(prefix, [])
            noms_port = port_names_by_prefix.get(prefix, [])
            tous_noms = list(set(noms_trafic + noms_port))

            if not tous_noms:
                continue

            site_fdd = None
            site_tdd = None
            site_tf = None
            site_simple = None
            has_known_suffix = False

            for nom in tous_noms:
                n = str(nom)
                if est_site_tf(n):
                    site_tf = n
                    has_known_suffix = True
                elif est_site_tdd(n):
                    site_tdd = n
                    has_known_suffix = True
                elif est_site_fdd(n):
                    site_fdd = n
                    has_known_suffix = True
                else:
                    site_simple = n

            if not has_known_suffix:
                classification = '-'
            elif site_tf:
                classification = 'TF'
            elif site_tdd:
                info = port_info.get(site_tdd, {'port0': 0, 'port1': 0})
                if info.get('port0', 0) > 0:
                    classification = 'COTRANS'
                elif info.get('port1', 0) > 0:
                    classification = 'NO_COTRANS'
                else:
                    classification = 'ONLY_FDD'
            else:
                classification = 'ONLY_FDD'

            type_trans = 'NON_DEFINI'
            for candidate in [site_fdd, site_tdd, site_tf, site_simple]:
                if candidate:
                    t = get_type_trans_for_prefix(extraire_prefixe(candidate), type_dict, capacite_dict)
                    if t != 'NON_DEFINI':
                        type_trans = t
                        break
            if type_trans == 'NON_DEFINI':
                type_trans = get_type_trans_for_prefix(prefix, type_dict, capacite_dict)

            fdd_data = trafic_by_site.get(site_fdd, empty_trafic) if site_fdd else empty_trafic
            tdd_data = trafic_by_site.get(site_tdd, empty_trafic) if site_tdd else empty_trafic
            tf_data = trafic_by_site.get(site_tf, empty_trafic) if site_tf else empty_trafic
            simple_data = trafic_by_site.get(site_simple, empty_trafic) if site_simple else empty_trafic
            dropcong_tdd = max_dropcong(tdd_data)
            dropcong_fdd = max_dropcong(fdd_data)
            dropcong_tf = max_dropcong(tf_data)
            if dropcong_fdd <= 0 and not simple_data.empty:
                dropcong_fdd = max_dropcong(simple_data)

            max_fdd = max_tdd = max_final = seuil = 0.0
            occ = total_measures = 0
            occ_tdd = calculer_nombre_occurrences(tdd_data)
            occ_fdd = calculer_nombre_occurrences(fdd_data)
            date_max = somme_fdd_tdd = None
            
            capacite_tdd_mbps, capacite_fdd_mbps = resolve_capacites_tdd_fdd(
                classification, site_tf, site_tdd, site_fdd, site_simple, prefix, capacites_from_ports
            )
            capacite_fallback = 0.0
            if site_tf and site_tf in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_tf)
            elif prefix in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, prefix)
            elif site_fdd and site_fdd in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_fdd)
            elif site_tdd and site_tdd in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_tdd)
            capacite_mbps = effective_capacity_for_utilization(
                classification, capacite_tdd_mbps, capacite_fdd_mbps, capacite_fallback
            )

            lat, lng = None, None
            if coords_dict:
                for nom_candidat in [site_tf, site_fdd, site_tdd, site_simple, prefix]:
                    if nom_candidat and nom_candidat in coords_dict:
                        lat = coords_dict[nom_candidat]['latitude']
                        lng = coords_dict[nom_candidat]['longitude']
                        break

            if classification == '-':
                ref = simple_data
                if not ref.empty:
                    max_val = float(ref['MaxSpeed'].max())
                    max_final = max_val
                    seuil = max_val * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    occ_fdd = occ
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)

            elif classification == 'TF' and not tf_data.empty:
                max_final = float(tf_data['MaxSpeed'].max())
                seuil = max_final * 0.9
                occ = int((tf_data['MaxSpeed'] >= seuil).sum())
                date_max = tf_data.loc[tf_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                total_measures = len(tf_data)

            elif classification == 'COTRANS':
                ref = fdd_data if not fdd_data.empty else tdd_data
                if not ref.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max()) if not fdd_data.empty else 0
                    max_tdd = float(tdd_data['MaxSpeed'].max()) if not tdd_data.empty else 0
                    max_final = max_fdd if not fdd_data.empty else max_tdd
                    seuil = max_final * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)

            elif classification == 'NO_COTRANS':
                if not fdd_data.empty and not tdd_data.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max())
                    max_tdd = float(tdd_data['MaxSpeed'].max())
                    fdd_h = fdd_data[['DateTime', 'MaxSpeed']].copy()
                    tdd_h = tdd_data[['DateTime', 'MaxSpeed']].copy()
                    fdd_h['H'] = fdd_h['DateTime'].dt.strftime('%Y-%m-%d %H:00:00')
                    tdd_h['H'] = tdd_h['DateTime'].dt.strftime('%Y-%m-%d %H:00:00')
                    fa = fdd_h.groupby('H')['MaxSpeed'].max().reset_index()
                    ta = tdd_h.groupby('H')['MaxSpeed'].max().reset_index()
                    mg = pd.merge(fa, ta, on='H', how='inner', suffixes=('_F', '_T'))
                    if not mg.empty:
                        mg['S'] = mg['MaxSpeed_F'] + mg['MaxSpeed_T']
                        mr = mg.loc[mg['S'].idxmax()]
                        max_final = float(mr['S'])
                        somme_fdd_tdd = max_final
                        date_max = mr['H']
                        seuil = max_final * 0.9
                        occ = int((mg['S'] >= seuil).sum())
                        total_measures = len(mg)
                    else:
                        max_final = max_fdd + max_tdd
                        somme_fdd_tdd = max_final
                        seuil = max_final * 0.9
                elif not fdd_data.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max())
                    max_final = max_fdd
                    seuil = max_fdd * 0.9
                    occ = int((fdd_data['MaxSpeed'] >= seuil).sum())
                    date_max = fdd_data.loc[fdd_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(fdd_data)
                elif not tdd_data.empty:
                    max_tdd = float(tdd_data['MaxSpeed'].max())
                    max_final = max_tdd
                    seuil = max_tdd * 0.9
                    occ = int((tdd_data['MaxSpeed'] >= seuil).sum())
                    date_max = tdd_data.loc[tdd_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(tdd_data)

            else:
                ref = fdd_data if not fdd_data.empty else (simple_data if not simple_data.empty else tdd_data)
                if not ref.empty:
                    max_val = float(ref['MaxSpeed'].max())
                    if not fdd_data.empty:
                        max_fdd = max_val
                    elif not tdd_data.empty:
                        max_tdd = max_val
                    max_final = max_val
                    seuil = max_val * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    if not fdd_data.empty:
                        occ_fdd = occ
                    elif not tdd_data.empty:
                        occ_tdd = occ
                    else:
                        occ_fdd = occ
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)

            if max_final <= 0:
                continue

            resultats[prefix] = {
                'Site': prefix,
                'Site_TDD': site_tdd,
                'Site_FDD': site_fdd or site_simple,
                'Classification': classification,
                'Type_Trans': type_trans,
                'Max_Trafic_Final': round(max_final, 4),
                'Max_Trafic_FDD': round(max_fdd, 4),
                'Max_Trafic_TDD': round(max_tdd, 4),
                'Somme_FDD_TDD': round(somme_fdd_tdd, 4) if somme_fdd_tdd else None,
                'Seuil_Critique': round(seuil, 4),
                'Nombre_Occurrences': int(occ),
                'Nombre_Occurrences_TDD': int(occ_tdd),
                'Nombre_Occurrences_FDD': int(occ_fdd),
                'Total_Measures': total_measures,
                'Date_Max': date_max,
                'Capacite_Mbps': float(capacite_mbps),
                'Capacite_TDD_Mbps': float(capacite_tdd_mbps),
                'Capacite_FDD_Mbps': float(capacite_fdd_mbps),
                'DropCong_TDD': int(dropcong_tdd),
                'DropCong_FDD': int(dropcong_fdd),
                'DropCong_TF': int(dropcong_tf),
                'Latitude': lat,
                'Longitude': lng,
                'Duree_Jours': int(duree_jours),
            }

        tous_les_sites = list(resultats.values())

        stats = {
            'total_sites': len(tous_les_sites),
            'sites_inconnus': sum(1 for s in tous_les_sites if s['Classification'] == '-'),
            'sites_tf': sum(1 for s in tous_les_sites if s['Classification'] == 'TF'),
            'sites_cotrans': sum(1 for s in tous_les_sites if s['Classification'] == 'COTRANS'),
            'sites_nocotrans': sum(1 for s in tous_les_sites if s['Classification'] == 'NO_COTRANS'),
            'sites_only_fdd': sum(1 for s in tous_les_sites if s['Classification'] in ('ONLY_FDD', 'FDD')),
            'date_analyse': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        }

        print(f"\n📊 STATISTIQUES FINALES:")
        print(f"   ✅ '-' (sans suffixe) : {stats['sites_inconnus']}")
        print(f"   ✅ TF                 : {stats['sites_tf']}")
        print(f"   ✅ COTRANS            : {stats['sites_cotrans']}")
        print(f"   ✅ NO_COTRANS         : {stats['sites_nocotrans']}")
        print(f"   ✅ ONLY_FDD           : {stats['sites_only_fdd']}")
        print(f"   📊 TOTAL              : {stats['total_sites']}")
        print(f"   📡 Avec capacité      : {sum(1 for s in tous_les_sites if s['Capacite_Mbps'] > 0)}")
        print(f"   📍 Avec GPS           : {sum(1 for s in tous_les_sites if s['Latitude'] is not None)}")

        # ========== INSERTIONS FINALES ==========
        
        # 1. Insertion dans analyse_resultat
        print("\n💾 Insertion dans analyse_resultat...")
        inserer_analyse_resultat(tous_les_sites)
        
        # 2. Insertion dans trafic_historique (mesures brutes pour les courbes)
        print("\n💾 Insertion dans trafic_historique brut...")
        inserer_trafic_historique_brut(df_trafic)

        # 3. Insertion dans trafic_historique (historique journalier si la table le supporte)
        print("\n💾 Insertion dans trafic_historique journalier...")
        inserer_trafic_historique(tous_les_sites)
        
        # 4. Analyse des états et création des alertes
        print("\n🔍 ANALYSE DES ÉTATS ET ALERTES...")
        alertes = analyser_etats_et_alertes(tous_les_sites)
        if alertes > 0:
            print(f"   📋 {alertes} alertes générées dans site_alert")
        else:
            print("   📋 Aucune nouvelle alerte")

        print(f"\n✅ Terminé en {time.time()-start_total:.1f}s")

        return {
            "status": "success",
            "message": "Traitement terminé avec succès",
            "data": {
                "tous_les_sites": tous_les_sites, 
                "stats": stats,
                "alertes": alertes
            }
        }

    except Exception as e:
        print(f"❌ ERREUR : {e}")
        traceback.print_exc()
        return {"status": "error", "message": str(e), "detail": traceback.format_exc()}
# api_python/traitement.py — VERSION FINALE COMPLÈTE v7
# Corrections :
# 1. extraire_prefixe gère _BH_XX, _BO_XX, _TR_XX etc. → bon regroupement
# 2. est_site_fdd détecte _BH/_BO/_TR/_OO avec suffixe géographique
# 3. Sites sans suffixe connu (ex: BEJ_0021) → classification '-'
# 4. type_trans = valeur BRUTE du fichier (BH TT, BH OO, TDD FO+FDD FH…) sans normalisation
# 5. NON_DEFINI = site absent du fichier type liaison
# 6. L2B supprimés uniquement du fichier trafic (pas de l'analyse globale)
# 7. Fallback vers table capacite_site si type_trans = 'NON_DEFINI'
# 8. AJOUT: Capacité extraite depuis port_data et stockée dans analyse_resultat
# 9. AJOUT GPS: Import des coordonnées depuis fichier Excel/CSV
# 10. AJOUT ALERTES: Calcul état CONGESTION/BRIDAGE/OK + insertion site_etat et site_alert
# 11. AJOUT HISTORIQUE: Insertion dans trafic_historique
# 12. AJOUT TAUX UTILISATION: Taux global, TDD et FDD dans les résultats

import pandas as pd
import io
import re
import json
from datetime import datetime, timedelta
import psycopg2
from psycopg2.extras import execute_values
import os
import numpy as np
import traceback

# ========== CONFIG BDD ==========
DB_CONFIG = {
    "host":     os.getenv("DB_HOST",     "127.0.0.1"),
    "port":     int(os.getenv("DB_PORT", "5432")),
    "dbname":   os.getenv("DB_NAME",     "PFE_5G"),
    "user":     os.getenv("DB_USER",     "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

# ========== SEUILS POUR ALERTES ==========
SEUIL_CONGESTION = 0.90  # 90% capacité = congestion
SEUIL_OCCURRENCES_BRIDAGE = 50
SEUIL_OCCURRENCES_VERIFICATION_CAPACITE = 20
SEUIL_OCCURRENCES_RISQUE_CONGESTION = 57
SEUIL_PLATEAU = 0.05      # 5% variation max pour considérer "stable"
SEUIL_STABILITE_MONTANT = 0.05
SEUIL_TAUX_CRITIQUE = 90.0   # Taux > 90% = critique
SEUIL_TAUX_ELEVE = 70.0      # Taux > 70% = élevé
CAPACITE_10G_MBPS = 10000.0  # 10G en Mbps — seuil de bridage

# ========== DÉTECTION TYPES ==========

def est_site_tf(site: str) -> bool:
    return bool(re.search(r'_tf$', str(site), re.IGNORECASE))

def est_site_tdd(site: str) -> bool:
    return bool(re.search(r'_(TC|TD|TDD)$', str(site), re.IGNORECASE))

def est_site_fdd(site: str) -> bool:
    return bool(re.search(r'_(LM|LMB|LM2|BO|BH|TR|OO|OR|TT)(_.+)?$', str(site), re.IGNORECASE))

def a_suffixe_connu(site: str) -> bool:
    return est_site_tf(site) or est_site_tdd(site) or est_site_fdd(site)

# ========== UTILITAIRES ==========

def normaliser_type_trans(valeur: str) -> str:
    return str(valeur).strip()

def extraire_prefixe(site: str) -> str:
    if not site or (isinstance(site, float) and pd.isna(site)): return ''
    s = str(site).strip()
    for suf in ['_LMB', '_LM2', '_LM', '_TDD', '_TC', '_TD', '_TF', '_FDD', '_TT']:
        if s.upper().endswith(suf.upper()):
            return s[:len(s)-len(suf)].strip()
    m = re.search(r'_(BH|BO|TR|OO|OR|TT)(_[A-Z0-9]+)*$', s, re.IGNORECASE)
    if m:
        return s[:m.start()].strip()
    return s

def to_py(val):
    if val is None: return None
    try:
        if pd.isna(val): return None
    except Exception: pass
    if isinstance(val, (np.integer, np.floating)): return val.item()
    return val

def to_float(val) -> float:
    try:
        if val is None or pd.isna(val):
            return 0.0
    except Exception:
        if val is None:
            return 0.0
    try:
        return float(str(val).replace(',', '.').strip())
    except Exception:
        return 0.0

def max_dropcong(df: pd.DataFrame) -> int:
    if df is None or df.empty or 'DropCongDownBWNum' not in df.columns:
        return 0
    try:
        return int(float(df['DropCongDownBWNum'].max() or 0))
    except Exception:
        return 0

def calculer_nombre_occurrences(df: pd.DataFrame) -> int:
    if df is None or df.empty or 'MaxSpeed' not in df.columns:
        return 0
    max_val = float(df['MaxSpeed'].max())
    if max_val <= 0:
        return 0
    seuil = max_val * 0.9
    return int((df['MaxSpeed'] >= seuil).sum())

def get_type_trans_for_prefix(prefix, type_dict, capacite_dict=None):
    if prefix in type_dict:
        return type_dict[prefix]
    parts = prefix.split('_')
    for i in range(len(parts)-1, 0, -1):
        key = '_'.join(parts[:i])
        if key in type_dict:
            return type_dict[key]
    if capacite_dict:
        if prefix in capacite_dict:
            return capacite_dict[prefix]
        for i in range(len(parts)-1, 0, -1):
            key = '_'.join(parts[:i])
            if key in capacite_dict:
                return capacite_dict[key]
    return 'NON_DEFINI'

# ========== FONCTIONS POUR LES CAPACITÉS ==========

def get_capacite_from_port_data(nom_site: str, port_info: dict) -> float:
    if not nom_site or nom_site not in port_info:
        return 0.0
    ports = port_info[nom_site]
    if '_TF' in str(nom_site).upper():
        capacite = ports.get('port0', 0) + ports.get('port1', 0)
    else:
        capacite = max(ports.get('port0', 0), ports.get('port1', 0))
    return float(capacite)

def resolve_capacites_tdd_fdd(classification, site_tf, site_tdd, site_fdd, site_simple, prefix, capacites_from_ports):
  """Retourne (cap_tdd, cap_fdd) depuis les capacités port/import."""
  cap_tdd = 0.0
  cap_fdd = 0.0
  classification = (classification or '').upper().strip()

  if classification == 'TF':
    cap_fdd = _latest_capacity_for_sites(capacites_from_ports, [
      site_tf,
      f"{prefix}_TF" if prefix else None,
      prefix,
      site_simple,
      site_fdd,
    ])
    return 0.0, cap_fdd

  if site_tdd and site_tdd in capacites_from_ports:
    cap_tdd = _capacity_value(capacites_from_ports, site_tdd)
  if site_fdd and site_fdd in capacites_from_ports:
    cap_fdd = _capacity_value(capacites_from_ports, site_fdd)
  if site_simple and site_simple in capacites_from_ports:
    cap_fdd = max(cap_fdd, _capacity_value(capacites_from_ports, site_simple))
  if site_tf and site_tf in capacites_from_ports:
    cap_fdd = max(cap_fdd, _capacity_value(capacites_from_ports, site_tf))

  if cap_tdd <= 0 and cap_fdd <= 0 and prefix in capacites_from_ports:
    fallback = _capacity_value(capacites_from_ports, prefix)
    if (classification or '').upper() in ('TF', 'ONLY_FDD', 'FDD', '-'):
      cap_fdd = fallback
    else:
      cap_tdd = fallback

  return cap_tdd, cap_fdd


def effective_capacity_for_utilization(classification, cap_tdd, cap_fdd, fallback=0.0):
  """
  TF / ONLY_FDD / FDD → capacité FDD seule.
  Autres classifications → TDD + FDD.
  """
  classification = (classification or '').upper().strip()
  cap_tdd = float(cap_tdd or 0)
  cap_fdd = float(cap_fdd or 0)
  fallback = float(fallback or 0)

  if classification in ('TF', 'ONLY_FDD', 'FDD', '-'):
    if cap_fdd > 0:
      return cap_fdd
    return fallback

  total = cap_tdd + cap_fdd
  if total > 0:
    return total

  return fallback


def calculer_taux_utilisation(max_trafic: float, capacite: float) -> float:
    """Calcule le taux d'utilisation en pourcentage"""
    if capacite <= 0 or max_trafic <= 0:
        return None
    return round((max_trafic / capacite) * 100, 2)


def calculer_taux_utilisation_complet(max_trafic: float, max_tdd: float, max_fdd: float,
                                       capacite_tdd: float, capacite_fdd: float, 
                                       classification: str, capacite_globale: float = 0) -> dict:
    """
    Calcule les trois taux d'utilisation:
    - taux_utilisation: global (selon règle métier)
    - taux_utilisation_tdd: TDD uniquement
    - taux_utilisation_fdd: FDD uniquement
    """
    classification = (classification or '').upper().strip()
    
    # Taux TDD
    taux_tdd = None
    if capacite_tdd > 0 and max_tdd > 0:
        taux_tdd = round((max_tdd / capacite_tdd) * 100, 2)
    
    # Taux FDD
    taux_fdd = None
    if capacite_fdd > 0 and max_fdd > 0:
        taux_fdd = round((max_fdd / capacite_fdd) * 100, 2)
    
    # COTRANS/NO_COTRANS : taux global vide — on n'affiche que taux TDD et FDD séparément
    if classification in ('COTRANS', 'NO_COTRANS'):
        return {
            'taux_utilisation': None,
            'taux_utilisation_tdd': taux_tdd,
            'taux_utilisation_fdd': taux_fdd
        }

    # Taux global (selon règle: TF/ONLY_FDD/FDD = capacité FDD seule, sinon TDD+FDD)
    taux_global = None
    if classification in ('TF', 'ONLY_FDD', 'FDD', '-'):
        if capacite_fdd > 0 and max_trafic > 0:
            taux_global = round((max_trafic / capacite_fdd) * 100, 2)
        elif capacite_globale > 0 and max_trafic > 0:
            taux_global = round((max_trafic / capacite_globale) * 100, 2)
    else:
        if capacite_tdd + capacite_fdd > 0 and max_trafic > 0:
            taux_global = round((max_trafic / (capacite_tdd + capacite_fdd)) * 100, 2)
        elif capacite_globale > 0 and max_trafic > 0:
            taux_global = round((max_trafic / capacite_globale) * 100, 2)

    return {
        'taux_utilisation': taux_global,
        'taux_utilisation_tdd': taux_tdd,
        'taux_utilisation_fdd': taux_fdd
    }


def evaluer_criticite_taux(taux: float) -> str:
    """Retourne le niveau de criticité d'un taux d'utilisation"""
    if taux is None:
        return 'inconnu'
    if taux >= SEUIL_TAUX_CRITIQUE:
        return 'critique'
    if taux >= SEUIL_TAUX_ELEVE:
        return 'eleve'
    return 'normal'


def _parse_import_date(value):
    if not value:
        return datetime.min
    if isinstance(value, datetime):
        return value
    try:
        return datetime.fromisoformat(str(value).replace(' ', 'T'))
    except Exception:
        return datetime.min


def _capacity_value(capacites: dict, site: str) -> float:
    entry = capacites.get(site)
    if isinstance(entry, dict):
        return float(entry.get('value') or 0)
    return float(entry or 0)


def _capacity_date(capacites: dict, site: str):
    entry = capacites.get(site)
    if isinstance(entry, dict):
        return entry.get('date')
    return None


def _latest_capacity_for_sites(capacites: dict, sites) -> float:
    selected_value = 0.0
    selected_date = datetime.min

    for site in sites:
        if not site or site not in capacites:
            continue

        candidate_value = _capacity_value(capacites, site)
        candidate_date = _parse_import_date(_capacity_date(capacites, site))
        if candidate_value > 0 and candidate_date >= selected_date:
            selected_value = candidate_value
            selected_date = candidate_date

    return selected_value


def _set_latest_capacity(capacites: dict, site: str, value: float, date_import=None):
    site = str(site).strip()
    value = float(value or 0)
    if not site or value <= 0:
        return

    current = capacites.get(site)
    if current is None or _parse_import_date(date_import) >= _parse_import_date(_capacity_date(capacites, site)):
        capacites[site] = {'value': value, 'date': date_import}


def charger_capacites_from_port_data(port_info: dict) -> dict:
    capacites = {}
    prefix_caps = {}
    for site, ports in port_info.items():
        if '_TF' in str(site).upper():
            capa = ports.get('port0', 0) + ports.get('port1', 0)
        else:
            capa = max(ports.get('port0', 0), ports.get('port1', 0))
        date_import = ports.get('date')
        if capa > 0:
            _set_latest_capacity(capacites, site, capa, date_import)
            prefix = extraire_prefixe(site)
            if prefix and prefix != site:
                prefix_caps.setdefault(prefix, {
                    'tdd': 0.0, 'tdd_date': None,
                    'fdd': 0.0, 'fdd_date': None,
                    'other': 0.0, 'other_date': None,
                })
                if est_site_tdd(site):
                    if capa >= prefix_caps[prefix]['tdd']:
                        prefix_caps[prefix]['tdd'] = capa
                        prefix_caps[prefix]['tdd_date'] = date_import
                elif est_site_fdd(site):
                    if capa >= prefix_caps[prefix]['fdd']:
                        prefix_caps[prefix]['fdd'] = capa
                        prefix_caps[prefix]['fdd_date'] = date_import
                else:
                    if capa >= prefix_caps[prefix]['other']:
                        prefix_caps[prefix]['other'] = capa
                        prefix_caps[prefix]['other_date'] = date_import
    for prefix, caps in prefix_caps.items():
        total = caps['tdd'] + caps['fdd']
        capa = total if total > 0 else caps['other']
        date_import = max(
            _parse_import_date(caps.get('tdd_date')),
            _parse_import_date(caps.get('fdd_date')),
            _parse_import_date(caps.get('other_date')),
        )
        if capa > 0:
            _set_latest_capacity(capacites, prefix, capa, date_import)
    return capacites


def charger_capacites_from_capacite_site() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("""
            SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'capacite_site')
        """)
        if not cur.fetchone()[0]:
            cur.close()
            conn.close()
            return {}

        cur.execute("""
            SELECT site, capacite_mbps, date_mise_ajour
            FROM capacite_site
            WHERE capacite_mbps > 0
            ORDER BY date_mise_ajour DESC, id DESC
        """)
        rows = cur.fetchall()
        cur.close()
        conn.close()

        capacites = {}
        for site, capacity, date_import in rows:
            site_key = str(site).strip()
            _set_latest_capacity(capacites, site_key, float(capacity or 0), date_import)
            prefix = extraire_prefixe(site_key)
            if prefix and prefix != site_key:
                _set_latest_capacity(capacites, prefix, float(capacity or 0), date_import)

        print(f"📡 Capacités depuis capacite_site : {len(capacites)} sites")
        return capacites
    except Exception as e:
        print(f"⚠️ capacités capacite_site : {e}")
        return {}


def fusionner_capacites_dernier_import(*capacity_maps: dict) -> dict:
    merged = {}
    for capacity_map in capacity_maps:
        for site, entry in capacity_map.items():
            _set_latest_capacity(merged, site, _capacity_value(capacity_map, site), _capacity_date(capacity_map, site))
    return merged


def fusionner_capacites_priorite_import(port_capacity_map: dict, imported_capacity_map: dict) -> dict:
    merged = {}
    for site, entry in port_capacity_map.items():
        _set_latest_capacity(merged, site, _capacity_value(port_capacity_map, site), _capacity_date(port_capacity_map, site))
    for site, entry in imported_capacity_map.items():
        value = _capacity_value(imported_capacity_map, site)
        if value > 0:
            merged[site] = {'value': value, 'date': _capacity_date(imported_capacity_map, site)}
    return merged

# ========== CHARGEMENT GPS ==========

def charger_coordonnees_gps(file_content) -> dict:
    if not file_content:
        print("   📍 Aucun fichier GPS fourni")
        return {}
    try:
        if isinstance(file_content, bytes):
            df = pd.read_excel(io.BytesIO(file_content))
        else:
            df = pd.read_excel(file_content)
        print(f"   📍 Fichier GPS chargé: {len(df)} lignes")
        df.columns = df.columns.str.strip()
        site_col = None
        for col in df.columns:
            if 'site' in col.lower() or 'enodeb' in col.lower() or 'node' in col.lower():
                site_col = col
                break
        if not site_col:
            site_col = df.columns[0]
        lat_col = None
        lng_col = None
        for col in df.columns:
            if 'lat' in col.lower():
                lat_col = col
            elif 'lon' in col.lower() or 'long' in col.lower():
                lng_col = col
        if not lat_col or not lng_col:
            print("   ⚠️ Colonnes lat/long non trouvées")
            return {}
        coords_dict = {}
        for _, row in df.iterrows():
            site = str(row[site_col]).strip()
            if not site or site == 'nan':
                continue
            try:
                lat = float(row[lat_col])
                lng = float(row[lng_col])
                if lat != 0 and lng != 0:
                    coords_dict[site] = {'latitude': lat, 'longitude': lng}
            except (ValueError, TypeError):
                continue
        print(f"   ✅ GPS chargé: {len(coords_dict)} sites")
        return coords_dict
    except Exception as e:
        print(f"   ❌ Erreur chargement GPS: {e}")
        return {}

# ========== LECTURE TRAFIC ==========

def lire_excel_trafic_directement(file_content: bytes) -> pd.DataFrame:
    for engine in ['openpyxl', 'xlrd', None]:
        try:
            kw = {'engine': engine} if engine else {}
            df = pd.read_excel(io.BytesIO(file_content), **kw)
            break
        except: continue

    df.columns = df.columns.str.strip()
    rename_map = {}
    for col in df.columns:
        cl = col.lower()
        if 'enodeb' in cl or ('name' in cl and 'site' not in cl):
            rename_map[col] = 'eNodeB_Name'
        elif 'dropcong' in cl and 'downbwnum' in cl:
            rename_map[col] = 'DropCongDownBWNum'
        elif 'maxspeed' in cl or 'rxmaxspeed' in cl or 'mbit' in cl or 'rxmaxspeed_mbs' in cl:
            rename_map[col] = 'MaxSpeed'
    df = df.rename(columns=rename_map)

    time_col = next((c for c in df.columns if 'time' in c.lower() or 'date' in c.lower()), df.columns[0])
    df['DateTime'] = pd.to_datetime(df[time_col], dayfirst=True, errors='coerce')
    df = df.dropna(subset=['DateTime'])

    if 'MaxSpeed' not in df.columns:
        for col in df.columns:
            try:
                test = df[col].iloc[0]
                if isinstance(test, (int, float)) or str(test).replace(',', '').replace('.', '').isdigit():
                    df = df.rename(columns={col: 'MaxSpeed'})
                    break
            except:
                continue
    
    if 'MaxSpeed' not in df.columns:
        df['MaxSpeed'] = df.iloc[:, -1]
    
    df['MaxSpeed'] = pd.to_numeric(
        df['MaxSpeed'].astype(str).str.replace(',', '.', regex=False)
                                  .str.replace(r'[^\d.\-]', '', regex=True),
        errors='coerce'
    )
    if 'DropCongDownBWNum' not in df.columns:
        df['DropCongDownBWNum'] = 0
    else:
        df['DropCongDownBWNum'] = pd.to_numeric(
            df['DropCongDownBWNum'].astype(str).str.replace(',', '.', regex=False)
                                           .str.replace(r'[^\d.\-]', '', regex=True),
            errors='coerce'
        ).fillna(0)
    df = df.dropna(subset=['MaxSpeed'])
    df = df[df['MaxSpeed'] >= 0]

    avant = len(df)
    df = df[~df['eNodeB_Name'].str.contains('L2B', na=False, case=False)]
    print(f"🚫 Sites L2B supprimés : {avant - len(df)}")
    print(f"📊 Trafic chargé : {len(df)} mesures")
    return df

def calculer_duree_jours_trafic(df_trafic: pd.DataFrame) -> int:
    """Calcule la duree couverte par le fichier trafic a partir de DateTime."""
    if df_trafic.empty or 'DateTime' not in df_trafic.columns:
        return 7

    dates = pd.to_datetime(df_trafic['DateTime'], errors='coerce').dropna()
    if dates.empty:
        return 7

    min_date = dates.min()
    max_date = dates.max()
    if hasattr(min_date, 'to_pydatetime'):
        min_date = min_date.to_pydatetime()
    if hasattr(max_date, 'to_pydatetime'):
        max_date = max_date.to_pydatetime()

    return max(1, (max_date.date() - min_date.date()).days + 1)

# ========== CHARGEMENT BDD ==========

def charger_port_data_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        suffix_pattern = r'_(LMB|LM2|LM|TDD|TC|TD|TF|FDD|TT|BH|BO|TR|OO|OR)(_[A-Z0-9]+)*$'
        cur.execute("""
            SELECT site, port_no, trafic, date_import
            FROM (
                SELECT site, port_no, trafic, date_import,
                       MAX(date_import) OVER (PARTITION BY UPPER(REGEXP_REPLACE(site, %s, '', 'i'))) AS latest_site_date,
                       ROW_NUMBER() OVER (PARTITION BY UPPER(site), port_no, date_import ORDER BY id DESC) AS row_num
                FROM port_data
            ) latest_ports
            WHERE date_import = latest_site_date AND row_num = 1
            ORDER BY UPPER(site), port_no
        """, (suffix_pattern,))
        rows = cur.fetchall()
        cur.close()
        conn.close()
        port_info = {}
        for site, port_no, trafic, date_import in rows:
            s = str(site).strip()
            if s not in port_info:
                port_info[s] = {'port0': 0, 'port1': 0, 'date': None}
            port_info[s]['date'] = max(
                _parse_import_date(port_info[s].get('date')),
                _parse_import_date(date_import),
            )
            if port_no == 0:
                port_info[s]['port0'] = float(trafic) if trafic else 0
            elif port_no == 1:
                port_info[s]['port1'] = float(trafic) if trafic else 0
        print(f"📡 Port data : {len(port_info)} sites")
        return port_info
    except Exception as e:
        print(f"⚠️ port_data : {e}")
        return {}

def charger_type_liaison_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("""
            SELECT site, type_trans
            FROM type_liaison_data
            ORDER BY date_import DESC, id DESC
        """)
        rows = cur.fetchall()
        cur.close()
        conn.close()
        type_dict = {}
        for site, type_trans in rows:
            s = str(site).strip()
            val = normaliser_type_trans(type_trans)
            if s not in type_dict:
                type_dict[s] = val
            p = extraire_prefixe(s)
            if p and p != s and p not in type_dict:
                type_dict[p] = val
        print(f"📡 Type liaison : {len(type_dict)} entrées")
        return type_dict
    except Exception as e:
        print(f"⚠️ type_liaison : {e}")
        return {}

def charger_capacite_as_dict() -> dict:
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        cur.execute("""
            SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'capacite_site')
        """)
        table_exists = cur.fetchone()[0]
        if not table_exists:
            cur.close()
            conn.close()
            return {}
        cur.execute("""
            SELECT site, type_trans
            FROM capacite_site
            WHERE type_trans IS NOT NULL
            ORDER BY date_mise_ajour DESC, id DESC
        """)
        rows = cur.fetchall()
        cur.close()
        conn.close()
        capacite_dict = {}
        for site, type_trans in rows:
            s = str(site).strip()
            if type_trans:
                if s not in capacite_dict:
                    capacite_dict[s] = normaliser_type_trans(str(type_trans))
                p = extraire_prefixe(s)
                if p and p != s and p not in capacite_dict:
                    capacite_dict[p] = normaliser_type_trans(str(type_trans))
        print(f"📡 Capacite_site : {len(capacite_dict)} entrées")
        return capacite_dict
    except Exception as e:
        print(f"⚠️ capacite_site : {e}")
        return {}

# ========== MAJ PORT / TYPE LIAISON ==========

def update_port_data(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        site_col = next((c for c in df.columns if 'enodeb' in c.lower() or 'name' in c.lower()), df.columns[0])
        port_col = next((c for c in df.columns if 'port' in c.lower()), None)
        rx_max_col = next((c for c in df.columns if 'rxmaxspeed' in c.lower()), None)
        total_bw_col = next((c for c in df.columns if 'rxtotalbw' in c.lower() or 'totalbw' in c.lower()), None)
        traf_col = next((c for c in df.columns if 'speed' in c.lower() or 'trafic' in c.lower() or 'mbit' in c.lower()), None)
        if not port_col or not ((rx_max_col and total_bw_col) or traf_col):
            print("⚠️ Colonnes port introuvables")
            return
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        n = 0
        import_date = datetime.now()
        for _, row in df.iterrows():
            site = str(row[site_col]).strip()
            if not site or site == 'nan':
                continue
            try:
                if rx_max_col and total_bw_col:
                    capacity = to_float(row[total_bw_col])
                else:
                    capacity = to_float(row[traf_col])
                cur.execute("""
                    INSERT INTO port_data (site, port_no, trafic, date_import)
                    VALUES (%s,%s,%s,%s)
                    ON CONFLICT (site, port_no) DO UPDATE SET trafic=EXCLUDED.trafic, date_import=EXCLUDED.date_import
                """, (site, int(float(row[port_col])),
                      capacity, import_date))
                n += 1
            except:
                continue
        conn.commit()
        cur.close()
        conn.close()
        print(f"   ✅ port_data MAJ : {n}")
    except Exception as e:
        print(f"   ❌ port_data: {e}")

def update_type_liaison_data(file_content: bytes):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = df.columns.str.strip()
        col_site = next((c for c in df.columns if 'site' in c.lower() or 'node' in c.lower()), df.columns[0])
        col_type = next((c for c in df.columns if 'type' in c.lower() or 'liaison' in c.lower()),
                        df.columns[1] if len(df.columns) > 1 else col_site)
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        n = 0
        for _, row in df.iterrows():
            site = str(row[col_site]).strip()
            if not site or site == 'nan':
                continue
            t = str(row[col_type]).strip()
            if not t:
                continue
            cur.execute("""
                UPDATE type_liaison_data
                SET type_trans = %s, date_import = %s
                WHERE site = %s
            """, (t, datetime.now(), site))
            if cur.rowcount == 0:
                cur.execute("""
                    INSERT INTO type_liaison_data (site, type_trans, date_import)
                    VALUES (%s, %s, %s)
                """, (site, t, datetime.now()))
            n += 1
        conn.commit()
        cur.close()
        conn.close()
        print(f"   ✅ type_liaison MAJ : {n}")
    except Exception as e:
        print(f"   ❌ type_liaison: {e}")

# ========== INSERTION ANALYSE ==========

def inserer_analyse_resultat(sites_data):
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        for col, defn in [
            ('stats_json', 'JSONB'),
            ('max_trafic_fdd', 'NUMERIC(15,4)'),
            ('max_trafic_tdd', 'NUMERIC(15,4)'),
            ('nombre_occurrences_tdd', 'INT DEFAULT 0'),
            ('nombre_occurrences_fdd', 'INT DEFAULT 0'),
            ('capacite_mbps', 'NUMERIC(15,4) DEFAULT 0'),
            ('duree_jours', 'INT DEFAULT 7'),
            ('dropcong_tdd', 'INT DEFAULT 0'),
            ('dropcong_fdd', 'INT DEFAULT 0'),
            ('dropcong_tf', 'INT DEFAULT 0'),
            ('taux_utilisation', 'NUMERIC(8,2)'),
            ('taux_utilisation_tdd', 'NUMERIC(8,2)'),
            ('taux_utilisation_fdd', 'NUMERIC(8,2)'),
        ]:
            cur.execute(f"""
                SELECT column_name FROM information_schema.columns
                WHERE table_name='analyse_resultat' AND column_name='{col}'
            """)
            if not cur.fetchone():
                cur.execute(f"ALTER TABLE analyse_resultat ADD COLUMN {col} {defn}")
                conn.commit()
                print(f"✅ Colonne {col} ajoutée")

        date_analyse = datetime.now()
        count = 0
        for site in sites_data:
            cur.execute("""
                INSERT INTO analyse_resultat
                (site, classification, type_trans, max_trafic, seuil_critique,
                 nombre_occurrences, total_measures, date_max, site_tdd, site_fdd,
                 somme_fdd_tdd, date_analyse, latitude, longitude, duree_jours,
                 max_trafic_fdd, max_trafic_tdd, nombre_occurrences_tdd,
                 nombre_occurrences_fdd, capacite_mbps, dropcong_tdd,
                 dropcong_fdd, dropcong_tf, taux_utilisation, taux_utilisation_tdd, taux_utilisation_fdd)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """, (
                to_py(site.get('Site')),
                to_py(site.get('Classification')),
                to_py(site.get('Type_Trans')),
                to_py(site.get('Max_Trafic_Final', 0)),
                to_py(site.get('Seuil_Critique', 0)),
                to_py(site.get('Nombre_Occurrences', 0)),
                to_py(site.get('Total_Measures', 0)),
                to_py(site.get('Date_Max')),
                to_py(site.get('Site_TDD')),
                to_py(site.get('Site_FDD')),
                to_py(site.get('Somme_FDD_TDD')),
                date_analyse,
                to_py(site.get('Latitude')),
                to_py(site.get('Longitude')),
                to_py(site.get('Duree_Jours', 7)),
                to_py(site.get('Max_Trafic_FDD', 0)),
                to_py(site.get('Max_Trafic_TDD', 0)),
                to_py(site.get('Nombre_Occurrences_TDD', 0)),
                to_py(site.get('Nombre_Occurrences_FDD', 0)),
                to_py(site.get('Capacite_Mbps', 0)),
                to_py(site.get('DropCong_TDD', 0)),
                to_py(site.get('DropCong_FDD', 0)),
                to_py(site.get('DropCong_TF', 0)),
                to_py(site.get('taux_utilisation')),
                to_py(site.get('taux_utilisation_tdd')),
                to_py(site.get('taux_utilisation_fdd')),
            ))
            count += 1
        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ {count} lignes insérées dans analyse_resultat")
        return count
    except Exception as e:
        print(f"⚠️ Insertion analyse_resultat : {e}")
        traceback.print_exc()
        return 0

# ========== INSERTION HISTORIQUE TRAFIC ==========

def inserer_trafic_historique(sites_data):
    """Insère le trafic journalier dans trafic_historique"""
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        date_jour = datetime.now().date()
        count = 0
        
        # Vérifier la structure de la table
        cur.execute("""
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'trafic_historique' AND column_name = 'date_jour'
        """)
        has_date_jour = cur.fetchone() is not None
        
        for site in sites_data:
            site_name = site.get('Site')
            max_trafic = site.get('Max_Trafic_Final', 0)
            capacite = site.get('Capacite_Mbps', 0)
            classification = site.get('Classification', '')
            type_trans = site.get('Type_Trans', '')
            taux_utilisation = site.get('taux_utilisation')
            taux_utilisation_tdd = site.get('taux_utilisation_tdd')
            taux_utilisation_fdd = site.get('taux_utilisation_fdd')
            
            if not site_name:
                continue
            
            if has_date_jour:
                cur.execute("""
                    INSERT INTO trafic_historique 
                        (site, date_jour, max_trafic, capacite_mbps, classification, type_trans,
                         taux_utilisation, taux_utilisation_tdd, taux_utilisation_fdd)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON CONFLICT (site, date_jour) DO UPDATE SET
                        max_trafic = EXCLUDED.max_trafic,
                        capacite_mbps = EXCLUDED.capacite_mbps,
                        classification = EXCLUDED.classification,
                        type_trans = EXCLUDED.type_trans,
                        taux_utilisation = EXCLUDED.taux_utilisation,
                        taux_utilisation_tdd = EXCLUDED.taux_utilisation_tdd,
                        taux_utilisation_fdd = EXCLUDED.taux_utilisation_fdd
                """, (site_name, date_jour, float(max_trafic), float(capacite), 
                      classification, type_trans, taux_utilisation, taux_utilisation_tdd, taux_utilisation_fdd))
            else:
                # La table legacy sert aux courbes et contient les noms bruts du fichier trafic.
                # Ne pas y ajouter les prefixes agreges, sinon l'interface affiche des sites inexistants.
                continue
            
            count += 1
        
        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ Historique inséré : {count} sites")
        return count
    except Exception as e:
        print(f"⚠️ Erreur insertion historique : {e}")
        traceback.print_exc()
        return 0

def inserer_trafic_historique_brut(df_trafic: pd.DataFrame) -> int:
    """Insere les mesures brutes du fichier trafic pour les courbes."""
    try:
        if df_trafic.empty:
            return 0

        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()
        date_import = datetime.now()
        count = 0

        raw = df_trafic[['eNodeB_Name', 'DateTime', 'MaxSpeed']].copy()
        raw['eNodeB_Name'] = raw['eNodeB_Name'].astype(str).str.strip()
        raw = raw.dropna(subset=['DateTime', 'MaxSpeed'])
        raw = raw[raw['eNodeB_Name'] != '']
        raw = raw.drop_duplicates(subset=['eNodeB_Name', 'DateTime'], keep='last')

        sites = raw['eNodeB_Name'].dropna().unique().tolist()
        min_date = raw['DateTime'].min()
        max_date = raw['DateTime'].max()
        if hasattr(min_date, 'to_pydatetime'):
            min_date = min_date.to_pydatetime()
        if hasattr(max_date, 'to_pydatetime'):
            max_date = max_date.to_pydatetime()
        duree_jours = max(1, (max_date.date() - min_date.date()).days + 1) if min_date and max_date else 7

        if sites:
            cur.execute(
                """
                DELETE FROM trafic_historique
                WHERE site = ANY(%s)
                  AND date_heure BETWEEN %s AND %s
                """,
                (sites, min_date, max_date)
            )

        rows = []
        for row in raw.itertuples(index=False):
            date_heure = row.DateTime
            if hasattr(date_heure, 'to_pydatetime'):
                date_heure = date_heure.to_pydatetime()
            rows.append((str(row.eNodeB_Name).strip(), date_heure, float(row.MaxSpeed), date_import))

        if rows:
            execute_values(
                cur,
                """
                INSERT INTO trafic_historique
                    (site, date_heure, max_speed, date_importation)
                VALUES %s
                """,
                rows,
                page_size=10000
            )
        count = len(rows)

        conn.commit()
        cur.close()
        conn.close()
        print(f"✅ Historique brut inséré : {count} mesures")
        return count
    except Exception as e:
        print(f"⚠️ Erreur insertion historique brut : {e}")
        traceback.print_exc()
        return 0

# ========== ANALYSE ÉTATS ET ALERTES ==========

def variation_pct(a: float, b: float) -> float:
    if a <= 0:
        return 0
    return abs(a - b) / a

def est_stable(a: float, b: float, seuil: float = SEUIL_STABILITE_MONTANT) -> bool:
    reference = max(a, b, 1.0)
    return abs(a - b) / reference <= seuil

def type_trans_backbone(type_trans: str) -> bool:
    valeur = str(type_trans or '').strip().upper()
    return valeur == 'BACKBONE' or valeur.startswith('BH')

def calculer_etat(trafic_j: float, trafic_j1: float, trafic_j7: float, capacite: float, occurrences: int = 0, type_trans: str = '') -> tuple:
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

def type_trans_manquant(type_trans: str) -> bool:
    if type_trans is None:
        return True
    valeur = str(type_trans).strip().upper()
    return valeur in ('', 'NON_DEFINI', 'NONE', 'N/A', 'NA', '-')

def construire_message_alerte(etat: str, nom: str, trafic_j: float, capacite: float, taux: float, type_trans: str) -> str:
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

def analyser_etats_et_alertes(sites_data: list) -> int:
    """
    Analyse l'état de chaque site et insère les alertes dans site_alert
    Retourne le nombre d'alertes insérées
    """
    try:
        conn = psycopg2.connect(**DB_CONFIG)
        cur = conn.cursor()

        # Chaque import remplace les alertes précédentes (hors IA) pour ne montrer que les résultats courants
        cur.execute("DELETE FROM site_alert WHERE type_trans != 'IA_ANOMALY'")

        date_j = datetime.now().date()
        date_j1 = date_j - timedelta(days=1)
        date_j7 = date_j - timedelta(days=7)
        date_j1_next = date_j1 + timedelta(days=1)
        date_j7_next = date_j7 + timedelta(days=1)

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

        alertes_count = 0

        for site in sites_data:
            nom = site.get('Site')
            trafic_j = float(site.get('Max_Trafic_Final', 0) or 0)
            capacite = float(site.get('Capacite_Mbps', 0) or 0)
            type_trans = site.get('Type_Trans', '')
            classification = site.get('Classification', '')
            occurrences = int(site.get('Nombre_Occurrences', 0) or 0)
            occ_tdd = int(site.get('Nombre_Occurrences_TDD', 0) or 0)
            occ_fdd = int(site.get('Nombre_Occurrences_FDD', 0) or 0)
            taux_tdd = site.get('taux_utilisation_tdd')
            taux_fdd = site.get('taux_utilisation_fdd')
            taux_global = site.get('taux_utilisation')

            if not nom or trafic_j <= 0 or capacite <= 0:
                continue

            trafic_j1 = hist.get(nom, {}).get(date_j1, 0)
            trafic_j7 = hist.get(nom, {}).get(date_j7, 0)

            etat, taux, var_j1, var_j7 = calculer_etat_avance(
                trafic_j, trafic_j1, trafic_j7, capacite, occurrences,
                classification, occ_tdd, occ_fdd, taux_tdd, taux_fdd
            )

            # Pour COTRANS/NO_COTRANS, taux_utilisation global stocké comme NULL
            taux_global_db = None if (classification or '').upper() in ('COTRANS', 'NO_COTRANS') else (round(taux, 2) if taux else None)

            cur.execute("""
                INSERT INTO site_etat
                    (site, etat, trafic_j, trafic_j1, trafic_j7, capacite_mbps,
                     taux_utilisation, variation_j1, variation_j7, date_j,
                     taux_utilisation_tdd, taux_utilisation_fdd)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (site) DO UPDATE SET
                    etat = EXCLUDED.etat,
                    trafic_j = EXCLUDED.trafic_j,
                    trafic_j1 = EXCLUDED.trafic_j1,
                    trafic_j7 = EXCLUDED.trafic_j7,
                    capacite_mbps = EXCLUDED.capacite_mbps,
                    taux_utilisation = EXCLUDED.taux_utilisation,
                    variation_j1 = EXCLUDED.variation_j1,
                    variation_j7 = EXCLUDED.variation_j7,
                    date_j = EXCLUDED.date_j,
                    taux_utilisation_tdd = EXCLUDED.taux_utilisation_tdd,
                    taux_utilisation_fdd = EXCLUDED.taux_utilisation_fdd,
                    date_calcul = NOW()
            """, (nom, etat, trafic_j, trafic_j1, trafic_j7, capacite,
                  taux_global_db, round(var_j1, 2), round(var_j7, 2), date_j,
                  taux_tdd, taux_fdd))

            alerte_a_creer = []
            if type_trans_manquant(type_trans):
                alerte_a_creer.append('SANS_TYPE')
            if etat in ('A_VERIFIER_CAPACITE', 'RISQUE_DE_CONGESTION', 'CONGESTIONNE', 'CONGESTION',
                        'CONGESTION(FDD)', 'CONGESTION(TDD)', 'BRIDE', 'BRIDAGE'):
                alerte_a_creer.append(etat)

            for etat_alerte in alerte_a_creer:
                cur.execute("""
                    SELECT id FROM site_alert
                    WHERE site = %s AND etat = %s AND date_alerte::date = %s
                    LIMIT 1
                """, (nom, etat_alerte, date_j))
                if not cur.fetchone():
                    msg = construire_message_alerte(etat_alerte, nom, trafic_j, capacite, taux, type_trans)
                    cur.execute("""
                        INSERT INTO site_alert
                            (site, etat, trafic_j, capacite_mbps, taux_utilisation,
                             variation_j1, variation_j7, type_trans, classification, message,
                             taux_utilisation_tdd, taux_utilisation_fdd)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """, (nom, etat_alerte, trafic_j, capacite, taux_global_db,
                          round(var_j1, 2), round(var_j7, 2), type_trans, classification, msg,
                          taux_tdd, taux_fdd))
                    alertes_count += 1

        conn.commit()
        cur.close()
        conn.close()

        print(f"   🔔 Alertes insérées: {alertes_count}")
        return alertes_count

    except Exception as e:
        print(f"⚠️ Erreur analyse états: {e}")
        traceback.print_exc()
        return 0

# ========== TRAITEMENT PRINCIPAL ==========

def traiter_fichiers(file_trafic_content, file_port_content=None,
                     file_type_content=None, file_gps_content=None):
    import time
    start_total = time.time()
    print("📂 Début du traitement...")

    try:
        # 1. Lire trafic
        start = time.time()
        df_trafic = lire_excel_trafic_directement(file_trafic_content)
        print(f"   ✅ Lecture trafic : {time.time()-start:.2f}s")
        if df_trafic.empty:
            return {"status": "error", "message": "Aucune donnée valide"}

        # 2. MAJ optionnelles
        duree_jours = calculer_duree_jours_trafic(df_trafic)
        print(f"   Duree analyse : {duree_jours} jour(s)")

        if file_port_content:
            start = time.time()
            update_port_data(file_port_content)
            print(f"   ✅ port_data : {time.time()-start:.2f}s")
        if file_type_content:
            start = time.time()
            update_type_liaison_data(file_type_content)
            print(f"   ✅ type_liaison : {time.time()-start:.2f}s")

        # 3. Charger BDD
        start = time.time()
        port_info = charger_port_data_as_dict()
        type_dict = charger_type_liaison_as_dict()
        capacite_dict = charger_capacite_as_dict()
        capacites_from_ports = charger_capacites_from_port_data(port_info)
        capacites_from_capacite_site = charger_capacites_from_capacite_site()
        capacites_from_ports = fusionner_capacites_priorite_import(
            capacites_from_ports,
            capacites_from_capacite_site,
        )
        print(f"   ✅ BDD chargée : {time.time()-start:.2f}s")
        print(f"   📡 Capacités depuis port_data : {len(capacites_from_ports)} sites")

        # Charger les coordonnées GPS
        coords_dict = charger_coordonnees_gps(file_gps_content) if file_gps_content else {}
        print(f"   📍 GPS chargé: {len(coords_dict)} sites")

        print(f"📄 Trafic : {len(df_trafic)} mesures")
        print("\n📊 CLASSIFICATION...")

        # Préparer l'index par préfixe
        df_trafic['Prefixe'] = df_trafic['eNodeB_Name'].apply(extraire_prefixe)
        empty_trafic = pd.DataFrame(columns=df_trafic.columns)
        trafic_by_site = {
            site: group
            for site, group in df_trafic.groupby('eNodeB_Name', sort=False)
        }
        trafic_names_by_prefix = {
            prefix: list(values)
            for prefix, values in df_trafic.groupby('Prefixe', sort=False)['eNodeB_Name'].unique().items()
        }
        port_names_by_prefix = {}
        for site in port_info.keys():
            port_names_by_prefix.setdefault(extraire_prefixe(site), []).append(site)

        prefixes_trafic = set(trafic_names_by_prefix.keys())
        prefixes_port = set(port_names_by_prefix.keys())
        prefixes_uniques = [p for p in (prefixes_trafic | prefixes_port) if p]
        print(f"📌 Préfixes uniques : {len(prefixes_uniques)}")

        resultats = {}

        for i, prefix in enumerate(prefixes_uniques):
            if (i + 1) % 500 == 0:
                print(f"   Traitement {i+1}/{len(prefixes_uniques)}...")

            noms_trafic = trafic_names_by_prefix.get(prefix, [])
            noms_port = port_names_by_prefix.get(prefix, [])
            tous_noms = list(set(noms_trafic + noms_port))

            if not tous_noms:
                continue

            site_fdd = None
            site_tdd = None
            site_tf = None
            site_simple = None
            has_known_suffix = False

            for nom in tous_noms:
                n = str(nom)
                if est_site_tf(n):
                    site_tf = n
                    has_known_suffix = True
                elif est_site_tdd(n):
                    site_tdd = n
                    has_known_suffix = True
                elif est_site_fdd(n):
                    site_fdd = n
                    has_known_suffix = True
                else:
                    site_simple = n

            if not has_known_suffix:
                classification = '-'
            elif site_tf:
                classification = 'TF'
            elif site_tdd:
                info = port_info.get(site_tdd, {'port0': 0, 'port1': 0})
                if info.get('port0', 0) > 0:
                    classification = 'COTRANS'
                elif info.get('port1', 0) > 0:
                    classification = 'NO_COTRANS'
                else:
                    classification = 'ONLY_FDD'
            else:
                classification = 'ONLY_FDD'

            type_trans = 'NON_DEFINI'
            for candidate in [site_fdd, site_tdd, site_tf, site_simple]:
                if candidate:
                    t = get_type_trans_for_prefix(extraire_prefixe(candidate), type_dict, capacite_dict)
                    if t != 'NON_DEFINI':
                        type_trans = t
                        break
            if type_trans == 'NON_DEFINI':
                type_trans = get_type_trans_for_prefix(prefix, type_dict, capacite_dict)

            fdd_data = trafic_by_site.get(site_fdd, empty_trafic) if site_fdd else empty_trafic
            tdd_data = trafic_by_site.get(site_tdd, empty_trafic) if site_tdd else empty_trafic
            tf_data = trafic_by_site.get(site_tf, empty_trafic) if site_tf else empty_trafic
            simple_data = trafic_by_site.get(site_simple, empty_trafic) if site_simple else empty_trafic
            dropcong_tdd = max_dropcong(tdd_data)
            dropcong_fdd = max_dropcong(fdd_data)
            dropcong_tf = max_dropcong(tf_data)
            if dropcong_fdd <= 0 and not simple_data.empty:
                dropcong_fdd = max_dropcong(simple_data)

            max_fdd = max_tdd = max_final = seuil = 0.0
            occ = total_measures = 0
            occ_tdd = calculer_nombre_occurrences(tdd_data)
            occ_fdd = calculer_nombre_occurrences(fdd_data)
            date_max = somme_fdd_tdd = None
            
            capacite_tdd_mbps, capacite_fdd_mbps = resolve_capacites_tdd_fdd(
                classification, site_tf, site_tdd, site_fdd, site_simple, prefix, capacites_from_ports
            )
            capacite_fallback = 0.0
            if site_tf and site_tf in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_tf)
            elif prefix in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, prefix)
            elif site_fdd and site_fdd in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_fdd)
            elif site_tdd and site_tdd in capacites_from_ports:
                capacite_fallback = _capacity_value(capacites_from_ports, site_tdd)
            capacite_mbps = effective_capacity_for_utilization(
                classification, capacite_tdd_mbps, capacite_fdd_mbps, capacite_fallback
            )

            lat, lng = None, None
            if coords_dict:
                for nom_candidat in [site_tf, site_fdd, site_tdd, site_simple, prefix]:
                    if nom_candidat and nom_candidat in coords_dict:
                        lat = coords_dict[nom_candidat]['latitude']
                        lng = coords_dict[nom_candidat]['longitude']
                        break

            if classification == '-':
                ref = simple_data
                if not ref.empty:
                    max_val = float(ref['MaxSpeed'].max())
                    max_final = max_val
                    seuil = max_val * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    occ_fdd = occ
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)

            elif classification == 'TF' and not tf_data.empty:
                max_final = float(tf_data['MaxSpeed'].max())
                seuil = max_final * 0.9
                occ = int((tf_data['MaxSpeed'] >= seuil).sum())
                date_max = tf_data.loc[tf_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                total_measures = len(tf_data)

            elif classification == 'COTRANS':
                ref = fdd_data if not fdd_data.empty else tdd_data
                if not ref.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max()) if not fdd_data.empty else 0
                    max_tdd = float(tdd_data['MaxSpeed'].max()) if not tdd_data.empty else 0
                    max_final = max_fdd if not fdd_data.empty else max_tdd
                    seuil = max_final * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)

            elif classification == 'NO_COTRANS':
                if not fdd_data.empty and not tdd_data.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max())
                    max_tdd = float(tdd_data['MaxSpeed'].max())
                    fdd_h = fdd_data[['DateTime', 'MaxSpeed']].copy()
                    tdd_h = tdd_data[['DateTime', 'MaxSpeed']].copy()
                    fdd_h['H'] = fdd_h['DateTime'].dt.strftime('%Y-%m-%d %H:00:00')
                    tdd_h['H'] = tdd_h['DateTime'].dt.strftime('%Y-%m-%d %H:00:00')
                    fa = fdd_h.groupby('H')['MaxSpeed'].max().reset_index()
                    ta = tdd_h.groupby('H')['MaxSpeed'].max().reset_index()
                    mg = pd.merge(fa, ta, on='H', how='inner', suffixes=('_F', '_T'))
                    if not mg.empty:
                        mg['S'] = mg['MaxSpeed_F'] + mg['MaxSpeed_T']
                        mr = mg.loc[mg['S'].idxmax()]
                        max_final = float(mr['S'])
                        somme_fdd_tdd = max_final
                        date_max = mr['H']
                        seuil = max_final * 0.9
                        occ = int((mg['S'] >= seuil).sum())
                        total_measures = len(mg)
                    else:
                        max_final = max_fdd + max_tdd
                        somme_fdd_tdd = max_final
                        seuil = max_final * 0.9
                elif not fdd_data.empty:
                    max_fdd = float(fdd_data['MaxSpeed'].max())
                    max_final = max_fdd
                    seuil = max_fdd * 0.9
                    occ = int((fdd_data['MaxSpeed'] >= seuil).sum())
                    date_max = fdd_data.loc[fdd_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(fdd_data)
                elif not tdd_data.empty:
                    max_tdd = float(tdd_data['MaxSpeed'].max())
                    max_final = max_tdd
                    seuil = max_tdd * 0.9
                    occ = int((tdd_data['MaxSpeed'] >= seuil).sum())
                    date_max = tdd_data.loc[tdd_data['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(tdd_data)

            else:
                ref = fdd_data if not fdd_data.empty else (simple_data if not simple_data.empty else tdd_data)
                if not ref.empty:
                    max_val = float(ref['MaxSpeed'].max())
                    if not fdd_data.empty:
                        max_fdd = max_val
                    elif not tdd_data.empty:
                        max_tdd = max_val
                    max_final = max_val
                    seuil = max_val * 0.9
                    occ = int((ref['MaxSpeed'] >= seuil).sum())
                    if not fdd_data.empty:
                        occ_fdd = occ
                    elif not tdd_data.empty:
                        occ_tdd = occ
                    else:
                        occ_fdd = occ
                    date_max = ref.loc[ref['MaxSpeed'].idxmax(), 'DateTime'].strftime('%Y-%m-%d %H:%M:%S')
                    total_measures = len(ref)

            if max_final <= 0:
                continue

            # ========== CALCUL DES TAUX D'UTILISATION ==========
            taux = calculer_taux_utilisation_complet(
                max_trafic=max_final,
                max_tdd=max_tdd,
                max_fdd=max_fdd,
                capacite_tdd=capacite_tdd_mbps,
                capacite_fdd=capacite_fdd_mbps,
                classification=classification,
                capacite_globale=capacite_mbps
            )

            resultats[prefix] = {
                'Site': prefix,
                'Site_TDD': site_tdd,
                'Site_FDD': site_fdd or site_simple,
                'Classification': classification,
                'Type_Trans': type_trans,
                'Max_Trafic_Final': round(max_final, 4),
                'Max_Trafic_FDD': round(max_fdd, 4),
                'Max_Trafic_TDD': round(max_tdd, 4),
                'Somme_FDD_TDD': round(somme_fdd_tdd, 4) if somme_fdd_tdd else None,
                'Seuil_Critique': round(seuil, 4),
                'Nombre_Occurrences': int(occ),
                'Nombre_Occurrences_TDD': int(occ_tdd),
                'Nombre_Occurrences_FDD': int(occ_fdd),
                'Total_Measures': total_measures,
                'Date_Max': date_max,
                'Capacite_Mbps': float(capacite_mbps),
                'Capacite_TDD_Mbps': float(capacite_tdd_mbps),
                'Capacite_FDD_Mbps': float(capacite_fdd_mbps),
                'taux_utilisation': taux['taux_utilisation'],
                'taux_utilisation_tdd': taux['taux_utilisation_tdd'],
                'taux_utilisation_fdd': taux['taux_utilisation_fdd'],
                'DropCong_TDD': int(dropcong_tdd),
                'DropCong_FDD': int(dropcong_fdd),
                'DropCong_TF': int(dropcong_tf),
                'Latitude': lat,
                'Longitude': lng,
                'Duree_Jours': int(duree_jours),
            }

        tous_les_sites = list(resultats.values())

        stats = {
            'total_sites': len(tous_les_sites),
            'sites_inconnus': sum(1 for s in tous_les_sites if s['Classification'] == '-'),
            'sites_tf': sum(1 for s in tous_les_sites if s['Classification'] == 'TF'),
            'sites_cotrans': sum(1 for s in tous_les_sites if s['Classification'] == 'COTRANS'),
            'sites_nocotrans': sum(1 for s in tous_les_sites if s['Classification'] == 'NO_COTRANS'),
            'sites_only_fdd': sum(1 for s in tous_les_sites if s['Classification'] in ('ONLY_FDD', 'FDD')),
            'date_analyse': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
        }

        print(f"\n📊 STATISTIQUES FINALES:")
        print(f"   ✅ '-' (sans suffixe) : {stats['sites_inconnus']}")
        print(f"   ✅ TF                 : {stats['sites_tf']}")
        print(f"   ✅ COTRANS            : {stats['sites_cotrans']}")
        print(f"   ✅ NO_COTRANS         : {stats['sites_nocotrans']}")
        print(f"   ✅ ONLY_FDD           : {stats['sites_only_fdd']}")
        print(f"   📊 TOTAL              : {stats['total_sites']}")
        print(f"   📡 Avec capacité      : {sum(1 for s in tous_les_sites if s['Capacite_Mbps'] > 0)}")
        print(f"   📍 Avec GPS           : {sum(1 for s in tous_les_sites if s['Latitude'] is not None)}")
        print(f"   📊 Taux globaux calculés: {sum(1 for s in tous_les_sites if s['taux_utilisation'] is not None)}")
        print(f"   📊 Taux TDD calculés   : {sum(1 for s in tous_les_sites if s['taux_utilisation_tdd'] is not None)}")
        print(f"   📊 Taux FDD calculés   : {sum(1 for s in tous_les_sites if s['taux_utilisation_fdd'] is not None)}")
        print(f"   📊 COTRANS/NO_COTRANS (taux global vide) : {sum(1 for s in tous_les_sites if (s.get('Classification','') or '').upper() in ('COTRANS','NO_COTRANS'))}")

        # ========== INSERTIONS FINALES ==========
        
        # 1. Insertion dans analyse_resultat
        print("\n💾 Insertion dans analyse_resultat...")
        inserer_analyse_resultat(tous_les_sites)
        
        # 2. Insertion dans trafic_historique (mesures brutes pour les courbes)
        print("\n💾 Insertion dans trafic_historique brut...")
        inserer_trafic_historique_brut(df_trafic)

        # 3. Insertion dans trafic_historique (historique journalier si la table le supporte)
        print("\n💾 Insertion dans trafic_historique journalier...")
        inserer_trafic_historique(tous_les_sites)
        
        # 4. Analyse des états et création des alertes
        print("\n🔍 ANALYSE DES ÉTATS ET ALERTES...")
        alertes = analyser_etats_et_alertes(tous_les_sites)
        if alertes > 0:
            print(f"   📋 {alertes} alertes générées dans site_alert")
        else:
            print("   📋 Aucune nouvelle alerte")

        print(f"\n✅ Terminé en {time.time()-start_total:.1f}s")

        return {
            "status": "success",
            "message": "Traitement terminé avec succès",
            "data": {
                "tous_les_sites": tous_les_sites, 
                "stats": stats,
                "alertes": alertes
            }
        }

    except Exception as e:
        print(f"❌ ERREUR : {e}")
        traceback.print_exc()
        return {"status": "error", "message": str(e), "detail": traceback.format_exc()}

def calculer_etat_avance(trafic_j, trafic_j1, trafic_j7,
                          capacite, occurrences, classification,
                          occ_tdd, occ_fdd, taux_tdd, taux_fdd):
    """
    Etat enrichi :
    - COTRANS/NO_COTRANS : CONGESTION(FDD) / CONGESTION(TDD) / CONGESTION
    - Non-TF, capacite=10G, taux>=90%, occ>=50 : BRIDAGE
    - Autres : CONGESTION / A_VERIFIER_CAPACITE / BRIDAGE / OK
    """
    classification = (classification or "").upper().strip()
    occurrences = int(occurrences or 0)
    occ_tdd = int(occ_tdd or 0)
    occ_fdd = int(occ_fdd or 0)
    taux_tdd_val = float(taux_tdd or 0)
    taux_fdd_val = float(taux_fdd or 0)

    taux = (trafic_j / capacite * 100) if capacite > 0 else 0
    var_j1 = variation_pct(trafic_j, trafic_j1) * 100
    var_j7 = variation_pct(trafic_j, trafic_j7) * 100

    if classification in ("COTRANS", "NO_COTRANS"):
        fdd_congest = taux_fdd_val >= SEUIL_CONGESTION * 100 and occ_fdd >= SEUIL_OCCURRENCES_RISQUE_CONGESTION
        tdd_congest = taux_tdd_val >= SEUIL_CONGESTION * 100 and occ_tdd >= SEUIL_OCCURRENCES_RISQUE_CONGESTION
        if fdd_congest and tdd_congest:
            return "CONGESTION", taux, var_j1, var_j7
        if fdd_congest:
            return "CONGESTION(FDD)", taux, var_j1, var_j7
        if tdd_congest:
            return "CONGESTION(TDD)", taux, var_j1, var_j7
        if ((taux_fdd_val >= SEUIL_CONGESTION * 100 and occ_fdd < SEUIL_OCCURRENCES_RISQUE_CONGESTION)
                or (taux_tdd_val >= SEUIL_CONGESTION * 100 and occ_tdd < SEUIL_OCCURRENCES_RISQUE_CONGESTION)):
            return "RISQUE_DE_CONGESTION", taux, var_j1, var_j7
        return "OK", taux, var_j1, var_j7

    if capacite <= 0:
        return "OK", taux, var_j1, var_j7

    if (classification != "TF"
            and abs(capacite - CAPACITE_10G_MBPS) < 1.0
            and taux >= SEUIL_CONGESTION * 100
            and occurrences >= SEUIL_OCCURRENCES_RISQUE_CONGESTION):
        return "BRIDAGE", taux, var_j1, var_j7

    if taux >= SEUIL_CONGESTION * 100 and occurrences >= SEUIL_OCCURRENCES_RISQUE_CONGESTION:
        return "CONGESTION", taux, var_j1, var_j7
    if taux >= SEUIL_CONGESTION * 100 and occurrences < SEUIL_OCCURRENCES_RISQUE_CONGESTION:
        return "RISQUE_DE_CONGESTION", taux, var_j1, var_j7
    if taux < SEUIL_CONGESTION * 100 and occurrences >= SEUIL_OCCURRENCES_BRIDAGE:
        return "BRIDAGE", taux, var_j1, var_j7
    return "OK", taux, var_j1, var_j7
