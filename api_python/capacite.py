# api_python/capacite.py
import pandas as pd
import io
import re
from datetime import datetime

def _clean_column_name(col: str) -> str:
    """Nettoie un nom de colonne : supprime les retours à la ligne, réduit les espaces, met en minuscule."""
    return re.sub(r'\s+', ' ', col).strip().lower()

def _convert_comma_to_dot(val):
    """Convertit une chaîne avec virgule décimale en float."""
    if isinstance(val, str):
        val = val.replace(',', '.')
    return pd.to_numeric(val, errors='coerce')

def traiter_capacite(file_content):
    try:
        df = pd.read_excel(io.BytesIO(file_content))
        df.columns = [_clean_column_name(c) for c in df.columns]

        print("=== DÉTECTION DU TYPE DE FICHIER ===")
        print(f"Colonnes nettoyées: {df.columns.tolist()}")

        # ------------------- FO -------------------
        if 'enodeb name' in df.columns:
            print("✅ Type détecté: FO")
            col_site = 'enodeb name'
            
            # Identifier les colonnes
            col_trafic = None
            col_capacite = None
            for col in df.columns:
                if 'vs.fege.rxmaxspeed' in col or 'rxmaxspeed' in col:
                    col_trafic = col
                if 'vs.fege.rxtotalbw' in col or 'rxtotalbw' in col:
                    col_capacite = col
            
            if col_trafic is None:
                print("   ⚠️ Colonne trafic non trouvée, fallback sur dernière colonne")
                col_trafic = df.columns[-2] if len(df.columns) > 1 else df.columns[0]
            if col_capacite is None:
                col_capacite = df.columns[-1]
            
            print(f"   Site: {col_site}")
            print(f"   Trafic: {col_trafic}")
            print(f"   Capacité: {col_capacite}")
            
            # Convertir les colonnes avec gestion des virgules
            df['_trafic'] = df[col_trafic].apply(_convert_comma_to_dot)
            df['_capacite'] = df[col_capacite].apply(_convert_comma_to_dot)
            
            # Grouper par site
            sites_capacites = {}
            for site in df[col_site].unique():
                site_data = df[df[col_site] == site].copy()
                # Trier par port si disponible (colonne 'port no')
                if 'port no' in df.columns:
                    site_data = site_data.sort_values('port no')
                
                # Chercher une ligne avec trafic > 0
                active = site_data[site_data['_trafic'] > 0]
                if not active.empty:
                    selected = active.iloc[0]
                else:
                    selected = site_data.iloc[0]
                
                site_name = str(selected[col_site]).strip()
                cap = selected['_capacite']
                if pd.notna(cap) and cap > 0:
                    sites_capacites[site_name] = cap
            
            resultats = [{'site': s, 'capacite_mbps': float(c)} for s, c in sites_capacites.items()]
            print(f"📊 Sites FO traités: {len(resultats)}")

        # ------------------- FH -------------------
        elif 'site a' in df.columns:
            print("✅ Type détecté: FH")
            col_site = 'site a'
            col_capacite = None
            for col in df.columns:
                if 'capacité' in col and 'actuelle' not in col:
                    col_capacite = col
                    break
            if col_capacite is None:
                col_capacite = df.columns[-1]
            print(f"   Site: {col_site} | Capacité: {col_capacite}")
            df['site'] = df[col_site].astype(str).str.strip()
            df['capacite_mbps'] = df[col_capacite].apply(_convert_comma_to_dot)
            df = df.dropna(subset=['site', 'capacite_mbps'])
            df = df[df['capacite_mbps'] > 0]
            df = df.drop_duplicates(subset=['site'], keep='first')
            resultats = [{'site': row['site'], 'capacite_mbps': float(row['capacite_mbps'])} for _, row in df.iterrows()]
            print(f"📊 Sites FH traités: {len(resultats)}")

        # ------------------- BACKBONE -------------------
        elif 'code otn' in df.columns or 'code oo' in df.columns:
            print("✅ Type détecté: BACKBONE")
            col_site = 'code otn' if 'code otn' in df.columns else 'code oo'
            col_capacite = None
            for col in df.columns:
                if 'capacité actuelle' in col:
                    col_capacite = col
                    break
            if col_capacite is None:
                for col in df.columns:
                    if 'capacité' in col:
                        col_capacite = col
                        break
            if col_capacite is None:
                col_capacite = df.columns[-1]
            print(f"   Site: {col_site} | Capacité: {col_capacite}")
            df['site'] = df[col_site].astype(str).str.strip()
            df['capacite_mbps'] = df[col_capacite].apply(_convert_comma_to_dot)
            df = df.dropna(subset=['site', 'capacite_mbps'])
            df = df[df['capacite_mbps'] > 0]
            df = df.drop_duplicates(subset=['site'], keep='first')
            resultats = [{'site': row['site'], 'capacite_mbps': float(row['capacite_mbps'])} for _, row in df.iterrows()]
            print(f"📊 Sites BACKBONE traités: {len(resultats)}")

        # ------------------- Fallback -------------------
        else:
            print("⚠️ Type non détecté, mode générique")
            col_site = df.columns[0]
            col_capacite = df.columns[-1]
            df['site'] = df[col_site].astype(str).str.strip()
            df['capacite_mbps'] = df[col_capacite].apply(_convert_comma_to_dot)
            df = df.dropna(subset=['site', 'capacite_mbps'])
            df = df[df['capacite_mbps'] > 0]
            df = df.drop_duplicates(subset=['site'], keep='first')
            resultats = [{'site': row['site'], 'capacite_mbps': float(row['capacite_mbps'])} for _, row in df.iterrows()]

        print(f"✅ {len(resultats)} capacités prêtes à être importées")
        if not resultats:
            return {"status": "error", "message": "Aucune donnée valide trouvée."}
        return {
            "status": "success",
            "message": f"Fichier traité: {len(resultats)} sites",
            "data": {
                "capacites": resultats,
                "total": len(resultats),
                "date_import": datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            }
        }
    except Exception as e:
        import traceback
        traceback.print_exc()
        return {"status": "error", "message": f"Erreur: {str(e)}"}

# Compatibilité
def traiter_capacite_fo(file_content):
    return traiter_capacite(file_content)

def traiter_capacite_fh(file_content):
    return traiter_capacite(file_content)

def traiter_capacite_backbone(file_content):
    return traiter_capacite(file_content)