import io
import re
import unicodedata
from datetime import datetime

import pandas as pd


def _clean_column_name(col: str) -> str:
    col = unicodedata.normalize("NFKD", str(col))
    col = "".join(ch for ch in col if not unicodedata.combining(ch))
    return re.sub(r"\s+", " ", col).strip().lower()


def _convert_comma_to_dot(val):
    if isinstance(val, str):
        val = val.replace(",", ".")
    return pd.to_numeric(val, errors="coerce")


def _normalize_site_code(value) -> str:
    return str(value).strip().upper()


def _normalize_token(value: str) -> str:
    value = unicodedata.normalize("NFKD", str(value))
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    value = re.sub(r"[^A-Z0-9]+", "_", value.upper())
    return re.sub(r"_+", "_", value).strip("_")


def _normalize_type_hint(value: str) -> str:
    token = _normalize_token(value)
    if token in {"BH_TT", "BHTT", "TT"}:
        return "BH_TT"
    if token in {"BH_OO", "BHOO", "OO"}:
        return "BH_OO"
    if token.startswith("BH") or token.startswith("BACKHAUL") or token in {"BACKBONE", "BACK_HAUL"}:
        return "BACKBONE"
    if token in {"NON_DEFINI", "NONE", "N_A", "NA"}:
        return "NON_DEFINI"
    return "NON_DEFINI"


def _detect_backbone_type(sheet_name: str) -> str:
    return _normalize_type_hint(sheet_name)


def _is_valid_site(value) -> bool:
    if value is None or pd.isna(value):
        return False
    text = str(value).strip()
    return text not in {"", "-", "N/A", "NA", "NONE", "NON_DEFINI"}


def _extract_backbone_sites(row: pd.Series, df: pd.DataFrame) -> list[str]:
    ordered_columns = [
        "code otn",
        "nom site otn",
        "code oo",
        "nom site tt",
        "site",
        "site a",
        "site b",
    ]
    sites: list[str] = []

    for col in ordered_columns:
        if col in df.columns and _is_valid_site(row.get(col)):
            site = _normalize_site_code(row.get(col))
            if site not in sites:
                sites.append(site)

    if not sites:
        for col in df.columns:
            if any(token in col for token in ["site", "otn", "oo"]) and _is_valid_site(row.get(col)):
                site = _normalize_site_code(row.get(col))
                if site not in sites:
                    sites.append(site)

    return sites


def _parse_fo_sheet(df: pd.DataFrame):
    if "enodeb name" not in df.columns:
        return []

    print("✅ Type détecté: FO")
    col_site = "enodeb name"
    col_trafic = None
    col_capacite = None

    for col in df.columns:
        if "vs.fege.rxmaxspeed" in col or "rxmaxspeed" in col:
            col_trafic = col
        if "vs.fege.rxtotalbw" in col or "rxtotalbw" in col:
            col_capacite = col

    if col_trafic is None:
        print("   ⚠️ Colonne trafic non trouvée, fallback sur l'avant-dernière colonne")
        col_trafic = df.columns[-2] if len(df.columns) > 1 else df.columns[0]
    if col_capacite is None:
        col_capacite = df.columns[-1]

    print(f"   Site: {col_site}")
    print(f"   Trafic: {col_trafic}")
    print(f"   Capacité: {col_capacite}")

    df["_trafic"] = df[col_trafic].apply(_convert_comma_to_dot)
    df["_capacite"] = df[col_capacite].apply(_convert_comma_to_dot)

    sites_capacites = {}
    for site in df[col_site].unique():
        site_data = df[df[col_site] == site].copy()
        if "port no" in df.columns:
            site_data = site_data.sort_values("port no")

        active = site_data[site_data["_capacite"] > 0]
        selected = active.iloc[0] if not active.empty else site_data.iloc[0]

        site_name = _normalize_site_code(selected[col_site])
        cap = selected["_capacite"]
        if pd.notna(cap) and cap > 0:
            sites_capacites[site_name] = cap

    return [
        {"site": s, "capacite_mbps": float(c), "type_trans": "FO"}
        for s, c in sites_capacites.items()
    ]


def _parse_fh_sheet(df: pd.DataFrame):
    if "site a" not in df.columns:
        return []

    print("✅ Type détecté: FH")
    col_site = "site a"
    col_capacite = None

    for col in df.columns:
        if "capacite" in col and "actuelle" not in col:
            col_capacite = col
            break

    if col_capacite is None:
        col_capacite = df.columns[-1]

    print(f"   Site: {col_site} | Capacité: {col_capacite}")
    df["site"] = df[col_site].apply(_normalize_site_code)
    df["capacite_mbps"] = df[col_capacite].apply(_convert_comma_to_dot)
    df = df.dropna(subset=["site", "capacite_mbps"])
    df = df[df["capacite_mbps"] > 0]
    df = df.drop_duplicates(subset=["site"], keep="first")

    return [
        {"site": row["site"], "capacite_mbps": float(row["capacite_mbps"]), "type_trans": "FH"}
        for _, row in df.iterrows()
    ]


def _parse_backbone_sheet(df: pd.DataFrame, sheet_name: str):
    if not any(col in df.columns for col in ["code otn", "code oo", "nom site otn", "nom site tt", "site"]):
        return []

    backbone_type = _detect_backbone_type(sheet_name)

    col_capacite = None
    for col in df.columns:
        if "capacite actuelle" in col or "capacite" in col:
            col_capacite = col
            break
    if col_capacite is None:
        col_capacite = df.columns[-1]

    col_type = None
    for col in df.columns:
        if "type bh" in col or "type backhaul" in col or col == "type":
            col_type = col
            break

    print(f"✅ Type détecté: {backbone_type}")
    print(f"   Capacité: {col_capacite}")
    if col_type:
        print(f"   Type BH: {col_type}")

    rows = []
    for _, row in df.iterrows():
        capacity = _convert_comma_to_dot(row.get(col_capacite))
        if pd.isna(capacity) or capacity <= 0:
            continue

        row_type = _normalize_type_hint(row.get(col_type)) if col_type else "NON_DEFINI"
        if row_type == "NON_DEFINI":
            row_type = backbone_type

        for site in _extract_backbone_sites(row, df):
            rows.append(
                {
                    "site": site,
                    "capacite_mbps": float(capacity),
                    "type_trans": row_type,
                }
            )

    dedup = {}
    for row in rows:
        key = (str(row["site"]).strip(), str(row.get("type_trans", "BACKBONE")).strip().upper())
        dedup[key] = row

    return list(dedup.values())


def _parse_generic_sheet(df: pd.DataFrame):
    print("⚠️ Type non détecté, mode générique")
    col_site = df.columns[0]
    col_capacite = df.columns[-1]
    df["site"] = df[col_site].apply(_normalize_site_code)
    df["capacite_mbps"] = df[col_capacite].apply(_convert_comma_to_dot)
    df = df.dropna(subset=["site", "capacite_mbps"])
    df = df[df["capacite_mbps"] > 0]
    df = df.drop_duplicates(subset=["site"], keep="first")
    return [
        {"site": row["site"], "capacite_mbps": float(row["capacite_mbps"]), "type_trans": "BACKBONE"}
        for _, row in df.iterrows()
    ]


def traiter_capacite(file_content):
    try:
        workbook = pd.read_excel(io.BytesIO(file_content), sheet_name=None)

        print("=== DÉTECTION DU TYPE DE FICHIER ===")
        print(f"Onglets trouvés: {list(workbook.keys())}")

        resultats = []

        for sheet_name, df in workbook.items():
            df = df.copy()
            df.columns = [_clean_column_name(c) for c in df.columns]
            print(f"--- Onglet: {sheet_name} ---")
            print(f"Colonnes nettoyées: {df.columns.tolist()}")

            rows = _parse_fo_sheet(df)
            if not rows:
                rows = _parse_fh_sheet(df)
            if not rows:
                rows = _parse_backbone_sheet(df, sheet_name)
            if not rows and len(workbook) == 1:
                rows = _parse_generic_sheet(df)

            if rows:
                print(f"📊 Sites traités sur l'onglet {sheet_name}: {len(rows)}")
                resultats.extend(rows)

        dedup = {}
        for row in resultats:
            key = (str(row["site"]).strip(), str(row.get("type_trans", "BACKBONE")).strip().upper())
            dedup[key] = row
        resultats = list(dedup.values())

        print(f"✅ {len(resultats)} capacités prêtes à être importées")
        if not resultats:
            return {"status": "error", "message": "Aucune donnée valide trouvée."}

        return {
            "status": "success",
            "message": f"Fichier traité: {len(resultats)} sites",
            "data": {
                "capacites": resultats,
                "total": len(resultats),
                "date_import": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            },
        }
    except Exception as e:
        import traceback

        traceback.print_exc()
        return {"status": "error", "message": f"Erreur: {str(e)}"}


def traiter_capacite_fo(file_content):
    return traiter_capacite(file_content)


def traiter_capacite_fh(file_content):
    return traiter_capacite(file_content)


def traiter_capacite_backbone(file_content):
    return traiter_capacite(file_content)
