import io
import os
import re
import unicodedata
from datetime import datetime

import numpy as np
import pandas as pd
import psycopg2


DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

DROP_THRESHOLD = 500.0
IUB_KPI = "VS.IUB.FlowCtrol.DL.DropCong.DownBWNum.LgcPort1"
RSCGROUP_KPI = "VS.RscGroup.FlowCtrol.DL.DropCong.DownBWNum"


def _clean_column_name(value: str) -> str:
    value = unicodedata.normalize("NFKD", str(value))
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    return re.sub(r"\s+", " ", value).strip()


def _column_token(value: str) -> str:
    value = unicodedata.normalize("NFKD", str(value))
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    return re.sub(r"[^a-z0-9]+", "", value.lower())


def _clean_site_name(site: str) -> str:
    cleaned = str(site or "").strip().upper()
    if cleaned in {"", "NAN", "NONE", "NULL"}:
        return ""

    previous = None
    while cleaned and cleaned != previous:
        previous = cleaned
        cleaned = re.sub(r"_(LM2|LMB|LM|TC|TD|TDD|TF|FDD|00)$", "", cleaned, flags=re.IGNORECASE)
        cleaned = re.sub(r"_(BH|BO|TR|OO|OR|TT)(?:_[A-Z0-9]+)*$", "", cleaned, flags=re.IGNORECASE)
        cleaned = cleaned.strip()

    return cleaned


def _to_float(value) -> float:
    if value is None:
        return 0.0
    try:
        if pd.isna(value):
            return 0.0
    except Exception:
        pass
    if isinstance(value, (np.integer, np.floating)):
        return float(value)

    text = str(value).replace("\xa0", "").replace(" ", "").replace(",", ".").strip()
    text = re.sub(r"[^0-9.\-]", "", text)
    try:
        return float(text)
    except ValueError:
        return 0.0


def _find_site_column(columns: list[str]) -> str:
    preferred = ["nodeb", "enodeb", "node b", "site"]
    normalized = {col: _column_token(col) for col in columns}
    for wanted in preferred:
        wanted_token = _column_token(wanted)
        for col, token in normalized.items():
            if token == wanted_token or wanted_token in token:
                return col
    return columns[1] if len(columns) > 1 else columns[0]


def _find_kpi_columns(columns: list[str]) -> dict[str, list[str]]:
    iub_token = _column_token(IUB_KPI)
    rscgroup_token = _column_token(RSCGROUP_KPI)
    matches = {"iub": [], "rscgroup": []}
    fallback = []

    for col in columns:
        token = _column_token(col)
        if token == iub_token or ("iub" in token and "dropcong" in token and "downbwnum" in token):
            matches["iub"].append(col)
        elif token == rscgroup_token or ("rscgroup" in token and "dropcong" in token and "downbwnum" in token):
            matches["rscgroup"].append(col)
        elif "dropcong" in token and "downbwnum" in token:
            fallback.append(col)

    if not matches["iub"] and not matches["rscgroup"] and fallback:
        matches["rscgroup"] = fallback

    return matches


def _read_workbook(file_content: bytes) -> list[pd.DataFrame]:
    workbook = pd.read_excel(io.BytesIO(file_content), sheet_name=None)
    frames = []
    for _, df in workbook.items():
        if df.empty:
            continue
        df = df.copy()
        df.columns = [_clean_column_name(col) for col in df.columns]
        frames.append(df)
    return frames


def _extract_dropcong_flags(file_content: bytes) -> dict[str, dict]:
    flags: dict[str, dict] = {}

    for df in _read_workbook(file_content):
        columns = list(df.columns)
        if not columns:
            continue

        site_col = _find_site_column(columns)
        kpi_cols = _find_kpi_columns(columns)
        if not kpi_cols["iub"]:
            raise ValueError(
                f"Colonne KPI introuvable. Colonne attendue: {IUB_KPI}"
            )

        for _, row in df.iterrows():
            site = _clean_site_name(row.get(site_col))
            if not site:
                continue

            max_iub = max((_to_float(row.get(col)) for col in kpi_cols["iub"]), default=0.0)
            max_rscgroup = max((_to_float(row.get(col)) for col in kpi_cols["rscgroup"]), default=0.0)
            max_value = max(max_iub, max_rscgroup)
            current = flags.setdefault(site, {
                "site": site,
                "max_iub": 0.0,
                "max_rscgroup": 0.0,
                "max_value": 0.0,
                "dropcong_kpi": "non",
            })
            current["max_iub"] = max(float(current["max_iub"]), max_iub)
            current["max_rscgroup"] = max(float(current["max_rscgroup"]), max_rscgroup)
            current["max_value"] = max(float(current["max_value"]), max_value)
            if max_iub > DROP_THRESHOLD:
                current["dropcong_kpi"] = "oui"

    return flags


def _ensure_dropcong_column(cur) -> None:
    cur.execute(
        """
        ALTER TABLE analyse_resultat
        ADD COLUMN IF NOT EXISTS drop_cong_kpi BOOLEAN DEFAULT false
        """
    )


def _ensure_dropcong_result_table(cur) -> None:
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS dropcong_resultat (
            id SERIAL PRIMARY KEY,
            site VARCHAR(100) NOT NULL UNIQUE,
            max_iub NUMERIC(15,4) DEFAULT 0,
            max_rscgroup NUMERIC(15,4) DEFAULT 0,
            max_value NUMERIC(15,4) DEFAULT 0,
            dropcong BOOLEAN DEFAULT false,
            date_import TIMESTAMP NOT NULL DEFAULT NOW()
        )
        """
    )
    for column, definition in [
        ("max_iub", "NUMERIC(15,4) DEFAULT 0"),
        ("max_rscgroup", "NUMERIC(15,4) DEFAULT 0"),
        ("max_value", "NUMERIC(15,4) DEFAULT 0"),
        ("dropcong", "BOOLEAN DEFAULT false"),
        ("date_import", "TIMESTAMP NOT NULL DEFAULT NOW()"),
    ]:
        cur.execute(
            f"""
            ALTER TABLE dropcong_resultat
            ADD COLUMN IF NOT EXISTS {column} {definition}
            """
        )
    cur.execute("DELETE FROM dropcong_resultat a USING dropcong_resultat b WHERE a.id > b.id AND a.site = b.site")
    cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS dropcong_resultat_site_uidx ON dropcong_resultat(site)")


def _replace_dropcong_results(cur, flags: dict[str, dict]) -> None:
    _ensure_dropcong_result_table(cur)
    import_date = datetime.now()

    cur.execute("DELETE FROM dropcong_resultat")
    for site, data in flags.items():
        cur.execute(
            """
            INSERT INTO dropcong_resultat
                (site, max_iub, max_rscgroup, max_value, dropcong, date_import)
            VALUES (%s, %s, %s, %s, %s, %s)
            ON CONFLICT (site) DO UPDATE SET
                max_iub = EXCLUDED.max_iub,
                max_rscgroup = EXCLUDED.max_rscgroup,
                max_value = EXCLUDED.max_value,
                dropcong = EXCLUDED.dropcong,
                date_import = EXCLUDED.date_import
            """,
            (
                site,
                float(data.get("max_iub") or 0),
                float(data.get("max_rscgroup") or 0),
                float(data.get("max_value") or 0),
                data.get("dropcong_kpi") == "oui",
                import_date,
            ),
        )


def _update_latest_analysis(flags: dict[str, dict]) -> tuple[int, int]:
    conn = psycopg2.connect(**DB_CONFIG)
    try:
        cur = conn.cursor()
        _ensure_dropcong_column(cur)
        _replace_dropcong_results(cur, flags)

        cur.execute("SELECT MAX(date_analyse) FROM analyse_resultat")
        last_date = cur.fetchone()[0]
        if not last_date:
            conn.commit()
            return 0, 0

        cur.execute(
            """
            UPDATE analyse_resultat
            SET drop_cong_kpi = false
            WHERE date_analyse = %s
            """,
            (last_date,),
        )
        total_latest_rows = cur.rowcount

        updated_yes = 0
        for site, data in flags.items():
            if data["dropcong_kpi"] != "oui":
                continue

            cur.execute(
                """
                UPDATE analyse_resultat
                SET drop_cong_kpi = true
                WHERE date_analyse = %s
                  AND (
                    UPPER(site) = %s
                    OR UPPER(site_fdd) = %s
                    OR UPPER(site_tdd) = %s
                    OR regexp_replace(UPPER(site), '_(LM2|LMB|LM|TC|TD|TDD|TF|FDD|00)$', '') = %s
                    OR regexp_replace(UPPER(site_fdd), '_(LM2|LMB|LM|TC|TD|TDD|TF|FDD|00)$', '') = %s
                    OR regexp_replace(UPPER(site_tdd), '_(LM2|LMB|LM|TC|TD|TDD|TF|FDD|00)$', '') = %s
                  )
                """,
                (last_date, site, site, site, site, site, site),
            )
            updated_yes += cur.rowcount

        conn.commit()
        return total_latest_rows, updated_yes
    finally:
        conn.close()


def traiter_dropcong(*file_contents: bytes):
    try:
        flags: dict[str, dict] = {}
        valid_files = [content for content in file_contents if content]

        if not valid_files:
            return {"status": "error", "message": "Aucun fichier DropCong fourni."}

        for content in valid_files:
            for site, data in _extract_dropcong_flags(content).items():
                current = flags.setdefault(site, {
                    "site": site,
                    "max_iub": 0.0,
                    "max_rscgroup": 0.0,
                    "max_value": 0.0,
                    "dropcong_kpi": "non",
                })
                current["max_iub"] = max(float(current["max_iub"]), float(data.get("max_iub") or 0))
                current["max_rscgroup"] = max(float(current["max_rscgroup"]), float(data.get("max_rscgroup") or 0))
                current["max_value"] = max(float(current["max_value"]), float(data["max_value"]))
                if data["dropcong_kpi"] == "oui":
                    current["dropcong_kpi"] = "oui"

        total_latest_rows, updated_yes = _update_latest_analysis(flags)
        total_oui = sum(1 for row in flags.values() if row["dropcong_kpi"] == "oui")

        return {
            "status": "success",
            "message": (
                f"DropCong importe: {len(flags)} site(s) lus, "
                f"{total_oui} site(s) > {int(DROP_THRESHOLD)}, "
                f"{updated_yes} ligne(s) analyse_resultat marquee(s) oui."
            ),
            "data": {
                "total_sites_lus": len(flags),
                "total_oui": total_oui,
                "total_lignes_derniere_analyse": total_latest_rows,
                "lignes_maj_oui": updated_yes,
                "date_import": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "threshold": DROP_THRESHOLD,
                "kpis": [IUB_KPI, RSCGROUP_KPI],
            },
        }
    except Exception as exc:
        import traceback

        traceback.print_exc()
        return {"status": "error", "message": str(exc), "detail": traceback.format_exc()}
