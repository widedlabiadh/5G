# traitement.py - Version adaptée pour FastAPI
import pandas as pd
import numpy as np
import io

pd.set_option('display.float_format', '{:.15f}'.format)

# ============================================
# FONCTIONS UTILITAIRES
# ============================================

def extraire_tous_prefixes(site):
    if site is None or pd.isna(site):
        return []
    site_str = str(site)
    parts = site_str.split('_')
    prefixes = []
    for i in range(len(parts) - 1, 0, -1):
        prefixes.append('_'.join(parts[:i]))
    prefixes.append('_'.join(parts[:-1]))
    return list(dict.fromkeys(prefixes))

def get_type_trans(site, type_dict):
    if site is None or pd.isna(site):
        return ('NON_DEFINI', None)
    prefixes = extraire_tous_prefixes(site)
    prefixes.insert(0, str(site))
    for prefix in prefixes:
        if prefix in type_dict:
            return (type_dict[prefix], prefix)
    return ('NON_DEFINI', None)

def trouver_site_fdd(site_tdd, df_trafic):
    prefixes = extraire_tous_prefixes(site_tdd)
    for prefix in prefixes:
        fdd_candidates = df_trafic[df_trafic['eNodeB_Name'].str.startswith(prefix, na=False)]
        fdd_candidates = fdd_candidates[~fdd_candidates['eNodeB_Name'].str.contains('_TC$|_TD$|_TDD$|_tf$', na=False, regex=True)]
        if len(fdd_candidates) > 0:
            return fdd_candidates['eNodeB_Name'].iloc[0]
    return None

# ============================================
# FONCTION PRINCIPALE DE TRAITEMENT
# ============================================

def traiter_fichiers(file_trafic_content, file_port_content, file_type_content):
    """
    Fonction principale - adaptée de ton script Colab
    """
    print("📂 Début du traitement des fichiers...")
    
    # 1. Lire les fichiers Excel depuis les bytes
    df_trafic = pd.read_excel(io.BytesIO(file_trafic_content))
    df_port = pd.read_excel(io.BytesIO(file_port_content))
    df_type = pd.read_excel(io.BytesIO(file_type_content))
    
    print("✅ Fichiers chargés avec succès")
    
    # 2. Nettoyer fichier trafic
    df_trafic.columns = df_trafic.columns.str.strip()
    df_trafic = df_trafic.rename(columns={
        'eNodeB Name': 'eNodeB_Name',
        'VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)': 'MaxSpeed'
    })
    df_trafic['DateTime'] = pd.to_datetime(df_trafic['Time'])
    df_trafic['Date'] = df_trafic['DateTime'].dt.date
    
    # 3. Nettoyer fichier port
    df_port.columns = df_port.columns.str.strip()
    df_port = df_port.rename(columns={
        'eNodeB Name': 'eNodeB_Name',
        'Port No': 'Port_No',
        'VS.FEGE.RxMaxSpeed_Mbs(Mbit/s)': 'Trafic'
    })
    
    # 4. Nettoyer fichier type liaison
    df_type.columns = df_type.columns.str.strip()
    
    col_site = None
    col_type = None
    for col in df_type.columns:
        if 'site' in col.lower() or 'node' in col.lower() or 'eNodeB' in col.lower():
            col_site = col
        if 'type' in col.lower() or 'liaison' in col.lower() or 'trans' in col.lower():
            col_type = col
    
    if col_site is None:
        col_site = df_type.columns[0]
    if col_type is None:
        col_type = df_type.columns[1] if len(df_type.columns) > 1 else df_type.columns[0]
    
    df_type = df_type.rename(columns={col_site: 'Site', col_type: 'Type_Trans'})
    df_type['Site'] = df_type['Site'].astype(str).str.strip()
    df_type['Type_Trans'] = df_type['Type_Trans'].astype(str).str.strip()
    
    type_dict = {}
    for _, row in df_type.iterrows():
        type_dict[row['Site']] = row['Type_Trans']
    
    # 5. Classification des sites
    print("\n📊 Classification des sites...")
    
    # Sites TF
    sites_tf = df_port[df_port['eNodeB_Name'].str.contains('_tf$', na=False, case=False, regex=True)]
    sites_tf_list = sites_tf['eNodeB_Name'].unique().tolist()
    
    # Sites TD/TC/TDD
    sites_td = df_port[df_port['eNodeB_Name'].str.contains('_TC$|_TD$|_TDD$', na=False, case=False, regex=True)]
    sites_td_list = sites_td['eNodeB_Name'].unique().tolist()
    
    # Sites LM / BO_RO
    sites_lm = df_port[df_port['eNodeB_Name'].str.contains('_LM$|_BO_RO$', na=False, case=False, regex=True)]
    sites_lm_list = sites_lm['eNodeB_Name'].unique().tolist()
    
    prefixes_td = set()
    for site in sites_td_list:
        prefix = '_'.join(site.split('_')[:-1])
        prefixes_td.add(prefix)
    
    sites_non_tdd = []
    for site in sites_lm_list:
        prefix = '_'.join(site.split('_')[:-1])
        if prefix not in prefixes_td:
            sites_non_tdd.append(site)
    
    sites_cotrans = []
    sites_no_cotrans = []
    
    for site in sites_td_list:
        site_data = df_port[df_port['eNodeB_Name'] == site]
        port_0 = site_data[site_data['Port_No'] == 0]
        port_1 = site_data[site_data['Port_No'] == 1]
        trafic_0 = port_0['Trafic'].values[0] if len(port_0) > 0 else 0
        trafic_1 = port_1['Trafic'].values[0] if len(port_1) > 0 else 0
        if trafic_0 > 0:
            sites_cotrans.append(site)
        if trafic_1 > 0:
            sites_no_cotrans.append(site)
    
    print(f"   - Sites TF: {len(sites_tf_list)}")
    print(f"   - Sites COTRANS: {len(sites_cotrans)}")
    print(f"   - Sites NO_COTRANS: {len(sites_no_cotrans)}")
    print(f"   - Sites NON TDD: {len(sites_non_tdd)}")
    
    # 6. Traitement des sites TF
    print("\n📊 Traitement des sites TF...")
    resultats_tf = []
    for site in sites_tf_list:
        site_data = df_trafic[df_trafic['eNodeB_Name'] == site]
        type_trans, _ = get_type_trans(site, type_dict)
        if len(site_data) > 0:
            max_trafic = site_data['MaxSpeed'].max()
            date_max = site_data.loc[site_data['MaxSpeed'].idxmax(), 'DateTime']
            seuil = max_trafic * 0.9
            occurrences = len(site_data[site_data['MaxSpeed'] >= seuil])
            resultats_tf.append({
                'Site': site,
                'Classification': 'TF',
                'Type_Trans': type_trans,
                'Max_Trafic': float(max_trafic),
                'Date_Max': date_max.strftime('%Y-%m-%d %H:%M:%S'),
                'Seuil_Critique': float(seuil),
                'Nombre_Occurrences': int(occurrences),
                'Total_Measures': len(site_data)
            })
    
    # 7. Traitement des sites COTRANS
    print("📊 Traitement des sites COTRANS...")
    resultats_cotrans = []
    for site in sites_cotrans:
        site_fdd = trouver_site_fdd(site, df_trafic)
        if site_fdd is None:
            prefix = '_'.join(site.split('_')[:-1])
            site_fdd = f"{prefix}_LM"
        fdd_data = df_trafic[df_trafic['eNodeB_Name'] == site_fdd]
        type_trans, _ = get_type_trans(site_fdd, type_dict)
        if len(fdd_data) > 0:
            max_fdd = fdd_data['MaxSpeed'].max()
            date_max = fdd_data.loc[fdd_data['MaxSpeed'].idxmax(), 'DateTime']
            seuil = max_fdd * 0.9
            occurrences = len(fdd_data[fdd_data['MaxSpeed'] >= seuil])
            resultats_cotrans.append({
                'Site_TDD': site,
                'Site_FDD': site_fdd,
                'Classification': 'COTRANS',
                'Type_Trans': type_trans,
                'Max_Trafic_Final': float(max_fdd),
                'Date_Max': date_max.strftime('%Y-%m-%d %H:%M:%S'),
                'Seuil_Critique': float(seuil),
                'Nombre_Occurrences': int(occurrences),
                'Total_Measures': len(fdd_data)
            })
    
    # 8. Traitement des sites NO_COTRANS
    print("📊 Traitement des sites NO_COTRANS...")
    resultats_nocotrans = []
    for site in sites_no_cotrans:
        site_fdd = trouver_site_fdd(site, df_trafic)
        if site_fdd is None:
            prefix = '_'.join(site.split('_')[:-1])
            site_fdd = f"{prefix}_LM"
        type_trans, _ = get_type_trans(site_fdd, type_dict)
        fdd_data = df_trafic[df_trafic['eNodeB_Name'] == site_fdd].copy()
        tdd_data = df_trafic[df_trafic['eNodeB_Name'] == site].copy()
        
        if len(fdd_data) > 0 and len(tdd_data) > 0:
            fdd_data['Key'] = fdd_data['DateTime']
            tdd_data['Key'] = tdd_data['DateTime']
            merged = pd.merge(fdd_data[['Key', 'MaxSpeed']], 
                             tdd_data[['Key', 'MaxSpeed']], 
                             on='Key', 
                             how='inner',
                             suffixes=('_FDD', '_TDD'))
            if len(merged) > 0:
                merged['Somme'] = merged['MaxSpeed_FDD'] + merged['MaxSpeed_TDD']
                max_row = merged.loc[merged['Somme'].idxmax()]
                max_somme = max_row['Somme']
                date_max = max_row['Key']
                seuil = max_somme * 0.9
                occurrences = len(merged[merged['Somme'] >= seuil])
                resultats_nocotrans.append({
                    'Site_TDD': site,
                    'Site_FDD': site_fdd,
                    'Classification': 'NO_COTRANS',
                    'Type_Trans': type_trans,
                    'Max_Trafic': float(max_somme),
                    'Date_Max': date_max.strftime('%Y-%m-%d %H:%M:%S'),
                    'Seuil_Critique': float(seuil),
                    'Nombre_Occurrences': int(occurrences),
                    'Total_Measures': len(merged)
                })
    
    # 9. Traitement des sites NON TDD
    print("📊 Traitement des sites NON TDD...")
    resultats_nontdd = []
    for site in sites_non_tdd:
        site_data = df_trafic[df_trafic['eNodeB_Name'] == site]
        type_trans, _ = get_type_trans(site, type_dict)
        if len(site_data) > 0:
            max_trafic = site_data['MaxSpeed'].max()
            date_max = site_data.loc[site_data['MaxSpeed'].idxmax(), 'DateTime']
            seuil = max_trafic * 0.9
            occurrences = len(site_data[site_data['MaxSpeed'] >= seuil])
            resultats_nontdd.append({
                'Site': site,
                'Classification': 'NON_TDD',
                'Type_Trans': type_trans,
                'Max_Trafic': float(max_trafic),
                'Date_Max': date_max.strftime('%Y-%m-%d %H:%M:%S'),
                'Seuil_Critique': float(seuil),
                'Nombre_Occurrences': int(occurrences),
                'Total_Measures': len(site_data)
            })
    
    # 10. Statistiques
    stats = {
        'total_sites': len(resultats_tf) + len(resultats_cotrans) + len(resultats_nocotrans) + len(resultats_nontdd),
        'sites_tf': len(resultats_tf),
        'sites_cotrans': len(resultats_cotrans),
        'sites_nocotrans': len(resultats_nocotrans),
        'sites_nontdd': len(resultats_nontdd),
        'total_occurrences': sum(s['Nombre_Occurrences'] for s in resultats_tf) + 
                             sum(s['Nombre_Occurrences'] for s in resultats_cotrans) + 
                             sum(s['Nombre_Occurrences'] for s in resultats_nocotrans) +
                             sum(s['Nombre_Occurrences'] for s in resultats_nontdd)
    }
    
    print(f"\n✅ Traitement terminé !")
    print(f"   - Total sites: {stats['total_sites']}")
    
    return {
        
        "status": "success",
        "message": "Traitement terminé avec succès",
        "data": {
            "sites_tf": resultats_tf,
            "sites_cotrans": resultats_cotrans,
            "sites_nocotrans": resultats_nocotrans,
            "sites_nontdd": resultats_nontdd,
            "stats": stats
        }
    }