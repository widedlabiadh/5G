"""
Module de prédiction IA - NetWoNC
Trois modèles ML comparés : Random Forest, Gradient Boosting, Extra Trees.
Deux cibles : état (congestion/bridage/OK…) + statut (critique/sous_observation/OK).
Projection à J+7 via extrapolation des tendances de trafic.
Règles métier alignées sur SiteStatusDecisionService.php.
"""

import json
import os
import time
from datetime import datetime
from math import ceil
from pathlib import Path
from typing import Any, Dict, List, Optional

import numpy as np
import pandas as pd
import psycopg2
from psycopg2.extras import Json

try:
    import joblib
    from sklearn.ensemble import (
        ExtraTreesClassifier,
        GradientBoostingClassifier,
        RandomForestClassifier,
    )
    from sklearn.metrics import (
        accuracy_score,
        classification_report,
        confusion_matrix,
        f1_score,
    )
    from sklearn.model_selection import train_test_split
    from sklearn.preprocessing import LabelEncoder, StandardScaler

    SKLEARN_OK = True
except Exception:
    joblib = None
    ExtraTreesClassifier = GradientBoostingClassifier = RandomForestClassifier = None
    accuracy_score = classification_report = confusion_matrix = f1_score = None
    train_test_split = LabelEncoder = StandardScaler = None
    SKLEARN_OK = False


# ── Constantes alignées sur SiteStatusDecisionService.php ──────────────────────
CONGESTION_RATE_THRESHOLD = 90.0
CAPACITY_CHECK_RATE_THRESHOLD = 105.0
CRITICAL_OCCURRENCES_PER_WEEK = 50
WATCH_OCCURRENCES_PER_WEEK = 20
RISK_CONGESTION_OCCURRENCES_LIMIT = 57
CAPACITE_10G_MBPS = 10_000.0

BASE_DIR = Path(__file__).resolve().parent
MODEL_DIR = BASE_DIR / "models"
MODEL_DIR.mkdir(exist_ok=True)

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

# Features pour la prédiction de l'état actuel
FEATURES_ETAT = [
    "taux_utilisation",
    "taux_utilisation_tdd",
    "taux_utilisation_fdd",
    "taux_max",           # max(taux, taux_tdd, taux_fdd)
    "nombre_occurrences",
    "nombre_occurrences_tdd",
    "nombre_occurrences_fdd",
    "occ_norm_j7",        # occurrences normalisées sur 7 jours
    "occ_tdd_norm_j7",
    "occ_fdd_norm_j7",
    "variation_j1",
    "variation_j7",
    "max_trafic",
    "max_trafic_tdd",
    "max_trafic_fdd",
    "capacite_mbps",
    "capacite_tdd_mbps",
    "capacite_fdd_mbps",
    "type_code",
    "classification_code",
]

# Features projetées à J+7 (mêmes colonnes mais avec valeurs projetées)
FEATURES_WEEK = [
    "taux_proj_j7",
    "taux_tdd_proj_j7",
    "taux_fdd_proj_j7",
    "taux_max_proj_j7",
    "occ_proj_j7",
    "occ_tdd_proj_j7",
    "occ_fdd_proj_j7",
    "occ_norm_proj_j7",
    "variation_j7",
    "max_trafic_proj_j7",
    "capacite_mbps",
    "capacite_tdd_mbps",
    "capacite_fdd_mbps",
    "type_code",
    "classification_code",
]

# Définitions des 3 modèles à comparer
MODEL_CONFIGS: Dict[str, Any] = {
    "random_forest": {
        "label": "Random Forest",
        "needs_scaling": True,
    },
    "gradient_boosting": {
        "label": "Gradient Boosting",
        "needs_scaling": False,  # GBT insensible à l'échelle
    },
    "extra_trees": {
        "label": "Extra Trees",
        "needs_scaling": False,
    },
}


def _build_model(name: str) -> Any:
    if not SKLEARN_OK:
        return None
    if name == "random_forest":
        return RandomForestClassifier(
            n_estimators=220,
            max_depth=14,
            min_samples_split=6,
            min_samples_leaf=3,
            class_weight="balanced",
            random_state=42,
            n_jobs=-1,
        )
    if name == "gradient_boosting":
        return GradientBoostingClassifier(
            n_estimators=180,
            max_depth=5,
            learning_rate=0.08,
            subsample=0.85,
            min_samples_split=6,
            min_samples_leaf=3,
            random_state=42,
        )
    if name == "extra_trees":
        return ExtraTreesClassifier(
            n_estimators=200,
            min_samples_split=4,
            min_samples_leaf=2,
            class_weight="balanced",
            random_state=42,
            n_jobs=-1,
        )
    return None


# ── Connexion DB ────────────────────────────────────────────────────────────────

def connect():
    return psycopg2.connect(**DB_CONFIG)


def ensure_prediction_table() -> None:
    with connect() as conn:
        with conn.cursor() as cur:
            cur.execute("""
                CREATE TABLE IF NOT EXISTS predictions_ia (
                    id SERIAL PRIMARY KEY,
                    site VARCHAR(100) NOT NULL,
                    date_prediction TIMESTAMP DEFAULT NOW(),
                    etat_predit VARCHAR(40),
                    statut_predit VARCHAR(40),
                    etat_predit_semaine VARCHAR(40),
                    statut_predit_semaine VARCHAR(40),
                    niveau_risque VARCHAR(30),
                    cause_probable VARCHAR(80),
                    niveau_confiance DECIMAL(5,4),
                    confiance_etat DECIMAL(5,4),
                    confiance_statut DECIMAL(5,4),
                    confiance_semaine DECIMAL(5,4),
                    modele_utilise VARCHAR(30),
                    action_recommandee TEXT,
                    probabilites JSONB,
                    probabilites_statut JSONB,
                    entree JSONB,
                    prediction_semaine JSONB,
                    temps_calcul_ms INT
                )
            """)
            for idx in ["site", "date_prediction DESC"]:
                col = idx.split()[0]
                cur.execute(
                    f"CREATE INDEX IF NOT EXISTS idx_predictions_ia_{col} ON predictions_ia({idx})"
                )
        conn.commit()


# ── Règles métier alignées sur SiteStatusDecisionService.php ────────────────────

def _is_unknown_type(type_trans: str) -> bool:
    return type_trans in {"", "NON_DEFINI", "NONE", "N_A", "NA", "-", "INCONNU"}


def _is_bh_type(type_trans: str) -> bool:
    return type_trans.startswith("BH") or type_trans == "BACKBONE"


def business_etat(row: Dict[str, Any]) -> str:
    """Calcule l'état métier. Aligné sur SiteStatusDecisionService::decideEtat()."""
    type_trans = str(row.get("type_trans") or "").upper().strip()

    if _is_unknown_type(type_trans):
        return "A_VERIFIER_CAPACITE"

    classification = str(row.get("classification") or "").upper().replace(" ", "")
    capacite = float(row.get("capacite_mbps") or 0)
    occ = int(row.get("nombre_occurrences") or 0)
    taux = float(row.get("taux_utilisation") or 0)
    taux_tdd = float(row.get("taux_utilisation_tdd") or 0)
    taux_fdd = float(row.get("taux_utilisation_fdd") or 0)
    taux_max = max(taux, taux_tdd, taux_fdd)
    est_10g = capacite > 0 and abs(capacite - CAPACITE_10G_MBPS) < 1.0

    # TF + 10G : toujours OK (occurrences non significatives à cette capacité)
    if classification == "TF" and est_10g:
        return "OK"

    if "COTRANS" in classification:
        return _business_etat_cotrans(row, est_10g, taux, taux_tdd, taux_fdd, occ)

    if taux >= CONGESTION_RATE_THRESHOLD and occ < RISK_CONGESTION_OCCURRENCES_LIMIT:
        return "RISQUE_DE_CONGESTION"

    # Non-TF, capacité 10G → BRIDAGE si conditions réunies
    if "TF" not in classification and est_10g and taux >= CONGESTION_RATE_THRESHOLD and occ >= RISK_CONGESTION_OCCURRENCES_LIMIT:
        return "BRIDAGE"

    if taux >= CONGESTION_RATE_THRESHOLD and occ >= RISK_CONGESTION_OCCURRENCES_LIMIT:
        return "CONGESTION"

    if taux < CONGESTION_RATE_THRESHOLD and occ >= CRITICAL_OCCURRENCES_PER_WEEK:
        return "BRIDAGE"

    if not _is_bh_type(type_trans) and taux_max >= CAPACITY_CHECK_RATE_THRESHOLD:
        return "A_VERIFIER_CAPACITE"

    return "OK"


def _business_etat_cotrans(
    row: Dict[str, Any], est_10g: bool,
    taux: float, taux_tdd: float, taux_fdd: float, occ: int
) -> str:
    occ_tdd = int(row.get("nombre_occurrences_tdd") or 0)
    occ_fdd = int(row.get("nombre_occurrences_fdd") or 0)
    if taux >= CONGESTION_RATE_THRESHOLD and occ >= RISK_CONGESTION_OCCURRENCES_LIMIT:
        return "CONGESTION"
    fdd_crit = taux_fdd >= CONGESTION_RATE_THRESHOLD and occ_fdd >= RISK_CONGESTION_OCCURRENCES_LIMIT
    tdd_crit = taux_tdd >= CONGESTION_RATE_THRESHOLD and occ_tdd >= RISK_CONGESTION_OCCURRENCES_LIMIT
    if est_10g:
        if fdd_crit or tdd_crit:
            return "BRIDAGE"
    else:
        if fdd_crit and tdd_crit:
            return "CONGESTION"
        if fdd_crit:
            return "CONGESTION"
        if tdd_crit:
            return "CONGESTION"
    if ((taux >= CONGESTION_RATE_THRESHOLD and occ < RISK_CONGESTION_OCCURRENCES_LIMIT)
            or (taux_fdd >= CONGESTION_RATE_THRESHOLD and occ_fdd < RISK_CONGESTION_OCCURRENCES_LIMIT)
            or (taux_tdd >= CONGESTION_RATE_THRESHOLD and occ_tdd < RISK_CONGESTION_OCCURRENCES_LIMIT)):
        return "RISQUE_DE_CONGESTION"
    return "OK"


def business_statut(row: Dict[str, Any], duree_jours: int = 7) -> str:
    """Calcule le statut métier. Aligné sur SiteStatusDecisionService::decide()."""
    if business_etat(row) == "RISQUE_DE_CONGESTION":
        return "SOUS_OBSERVATION"

    # TF + 10G : statut toujours OK
    classification = str(row.get("classification") or "").upper().replace(" ", "")
    capacite = float(row.get("capacite_mbps") or 0)
    est_10g = capacite > 0 and abs(capacite - CAPACITE_10G_MBPS) < 1.0
    if classification == "TF" and est_10g:
        return "OK"

    duree = max(1, duree_jours)
    seuil_critique = max(1, ceil(CRITICAL_OCCURRENCES_PER_WEEK * duree / 7))
    seuil_obs = max(1, ceil(WATCH_OCCURRENCES_PER_WEEK * duree / 7))

    occ = int(row.get("nombre_occurrences") or 0)
    occ_tdd = int(row.get("nombre_occurrences_tdd") or 0)
    occ_fdd = int(row.get("nombre_occurrences_fdd") or 0)

    if occ_tdd >= seuil_critique or occ_fdd >= seuil_critique:
        return "CRITIQUE"
    if occ >= seuil_critique:
        return "CRITIQUE"
    if occ_tdd >= seuil_obs or occ_fdd >= seuil_obs:
        return "SOUS_OBSERVATION"
    if occ >= seuil_obs:
        return "SOUS_OBSERVATION"
    return "OK"


# ── Feature engineering ─────────────────────────────────────────────────────────

def _add_engineered_features(df: pd.DataFrame) -> pd.DataFrame:
    """Ajoute taux_max, occurrences normalisées."""
    df["taux_max"] = df[["taux_utilisation", "taux_utilisation_tdd", "taux_utilisation_fdd"]].max(axis=1)
    duree = df.get("duree_jours", 7).fillna(7).clip(lower=1)
    df["occ_norm_j7"] = (df["nombre_occurrences"] / duree * 7).round(2)
    df["occ_tdd_norm_j7"] = (df["nombre_occurrences_tdd"] / duree * 7).round(2)
    df["occ_fdd_norm_j7"] = (df["nombre_occurrences_fdd"] / duree * 7).round(2)
    return df


def _add_projected_features(df: pd.DataFrame) -> pd.DataFrame:
    """Projette les features à J+7 en utilisant le taux de croissance variation_j7."""
    growth = (df["variation_j7"].fillna(0) / 100).clip(-0.5, 1.0)
    mult = 1 + growth
    duree = df.get("duree_jours", pd.Series([7] * len(df))).fillna(7).clip(lower=1)

    df["taux_proj_j7"] = (df["taux_utilisation"] * mult).clip(0, 300)
    df["taux_tdd_proj_j7"] = (df["taux_utilisation_tdd"] * mult).clip(0, 300)
    df["taux_fdd_proj_j7"] = (df["taux_utilisation_fdd"] * mult).clip(0, 300)
    df["taux_max_proj_j7"] = df[["taux_proj_j7", "taux_tdd_proj_j7", "taux_fdd_proj_j7"]].max(axis=1)
    df["max_trafic_proj_j7"] = (df["max_trafic"] * mult).clip(lower=0)

    # Occurrences projetées : ajout de 7 jours au rythme actuel
    occ_rate = df["nombre_occurrences"] / duree
    occ_tdd_rate = df["nombre_occurrences_tdd"] / duree
    occ_fdd_rate = df["nombre_occurrences_fdd"] / duree
    df["occ_proj_j7"] = (df["nombre_occurrences"] + occ_rate * 7).round(0).astype(int)
    df["occ_tdd_proj_j7"] = (df["nombre_occurrences_tdd"] + occ_tdd_rate * 7).round(0).astype(int)
    df["occ_fdd_proj_j7"] = (df["nombre_occurrences_fdd"] + occ_fdd_rate * 7).round(0).astype(int)
    df["occ_norm_proj_j7"] = (df["occ_proj_j7"] / 7 * 7).round(2)  # normalisé sur 7 j

    return df


def _safe_encode_labels(encoder, series) -> np.ndarray:
    """Encode une série de labels, mappe les valeurs inconnues sur 0."""
    return np.array([
        int(encoder.transform([v])[0]) if v in encoder.classes_ else 0
        for v in series
    ])


def _encode_categoricals(df: pd.DataFrame, encoders: Optional[Dict] = None) -> tuple:
    data = df.copy()
    data["type_trans"] = data["type_trans"].fillna("INCONNU").astype(str)
    data["classification"] = data["classification"].fillna("STANDARD").astype(str)

    if encoders is None:
        type_enc = LabelEncoder().fit(data["type_trans"])
        cls_enc = LabelEncoder().fit(data["classification"])
        encoders = {"type": type_enc, "classification": cls_enc}
    else:
        type_enc = encoders["type"]
        cls_enc = encoders["classification"]

    data["type_code"] = data["type_trans"].map(lambda v: _safe_transform(type_enc, v))
    data["classification_code"] = data["classification"].map(lambda v: _safe_transform(cls_enc, v))
    return data, encoders


def _safe_transform(encoder, value: str) -> int:
    value = str(value)
    return int(encoder.transform([value])[0]) if value in encoder.classes_ else 0


def _prepare_X(df: pd.DataFrame, features: List[str]) -> pd.DataFrame:
    for f in features:
        if f not in df.columns:
            df[f] = 0
    return df[features].fillna(0)


# ── Chargement des données ───────────────────────────────────────────────────────

def load_training_dataset() -> pd.DataFrame:
    """Charge tout l'historique trié par date croissante pour le split temporel."""
    sql = """
        SELECT
            a.site,
            COALESCE(NULLIF(a.type_trans, ''), 'INCONNU')         AS type_trans,
            COALESCE(NULLIF(a.classification, ''), 'STANDARD')    AS classification,
            COALESCE(a.taux_utilisation,
                CASE WHEN COALESCE(a.capacite_mbps, 0) > 0
                     THEN ROUND((a.max_trafic / a.capacite_mbps) * 100, 2)
                     ELSE 0 END)                                   AS taux_utilisation,
            COALESCE(a.taux_utilisation_tdd, 0)                   AS taux_utilisation_tdd,
            COALESCE(a.taux_utilisation_fdd, 0)                   AS taux_utilisation_fdd,
            COALESCE(a.nombre_occurrences, 0)                     AS nombre_occurrences,
            COALESCE(a.nombre_occurrences_tdd, 0)                 AS nombre_occurrences_tdd,
            COALESCE(a.nombre_occurrences_fdd, 0)                 AS nombre_occurrences_fdd,
            COALESCE(NULLIF(a.duree_jours, 0), 7)                 AS duree_jours,
            COALESCE(se.variation_j1, 0)                          AS variation_j1,
            COALESCE(se.variation_j7, 0)                          AS variation_j7,
            COALESCE(a.max_trafic, 0)                             AS max_trafic,
            COALESCE(a.max_trafic_tdd, 0)                         AS max_trafic_tdd,
            COALESCE(a.max_trafic_fdd, 0)                         AS max_trafic_fdd,
            COALESCE(a.capacite_mbps, 0)                          AS capacite_mbps,
            COALESCE(a.capacite_tdd_mbps, 0)                      AS capacite_tdd_mbps,
            COALESCE(a.capacite_fdd_mbps, 0)                      AS capacite_fdd_mbps,
            a.date_analyse
        FROM analyse_resultat a
        LEFT JOIN site_etat se ON LOWER(se.site) = LOWER(a.site)
        WHERE a.max_trafic IS NOT NULL
        ORDER BY a.date_analyse ASC, a.id ASC
    """

    with connect() as conn:
        with conn.cursor() as cur:
            cur.execute(sql)
            columns = [desc[0] for desc in cur.description]
            rows = cur.fetchall()
    df = pd.DataFrame(rows, columns=columns)

    if df.empty:
        return df

    # Calcul des cibles depuis les règles métier PHP
    df["etat_cible"] = df.apply(lambda r: business_etat(r.to_dict()), axis=1)
    df["statut_cible"] = df.apply(
        lambda r: business_statut(r.to_dict(), int(r.get("duree_jours") or 7)), axis=1
    )
    # Quand etat=A_VERIFIER_CAPACITE → statut vide (aligné sur PHP decide())
    df.loc[df["etat_cible"] == "A_VERIFIER_CAPACITE", "statut_cible"] = "AUCUN"

    return df


# ── Sérialisation numpy → Python natif ──────────────────────────────────────────

def _to_serializable(obj: Any) -> Any:
    """Convertit récursivement les types numpy en types Python natifs pour JSON."""
    if isinstance(obj, dict):
        return {str(k): _to_serializable(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_to_serializable(v) for v in obj]
    if isinstance(obj, np.integer):
        return int(obj)
    if isinstance(obj, np.floating):
        return float(obj)
    if isinstance(obj, np.ndarray):
        return obj.tolist()
    if isinstance(obj, float) and (obj != obj or obj == float("inf") or obj == float("-inf")):
        return None  # NaN / Inf → null JSON
    return obj


# ── Entraînement ────────────────────────────────────────────────────────────────

def _train_one_task(
    X_train: pd.DataFrame,
    X_test: pd.DataFrame,
    y_train: np.ndarray,
    y_test: np.ndarray,
    target_encoder: Any,
    task: str,
    feature_names: List[str],
) -> Dict[str, Any]:
    """Entraîne les 3 modèles pour une tâche (etat ou statut), retourne le meilleur."""
    results = {}
    best_name = None
    best_f1 = -1.0
    best_model = None
    best_scaler = None

    for model_name, cfg in MODEL_CONFIGS.items():
        model = _build_model(model_name)
        if model is None:
            continue

        scaler = StandardScaler()
        if cfg["needs_scaling"]:
            Xtr = scaler.fit_transform(X_train)
            Xte = scaler.transform(X_test)
        else:
            Xtr = X_train.values
            Xte = X_test.values
            scaler = None

        model.fit(Xtr, y_train)
        y_pred = model.predict(Xte)
        acc = float(accuracy_score(y_test, y_pred))
        labels = list(range(len(target_encoder.classes_)))
        f1 = float(f1_score(y_test, y_pred, labels=labels, average="macro", zero_division=0))
        report = classification_report(
            y_test, y_pred, labels=labels,
            target_names=target_encoder.classes_,
            output_dict=True, zero_division=0,
        )
        matrix = confusion_matrix(y_test, y_pred, labels=labels).tolist()

        importance = {}
        if hasattr(model, "feature_importances_"):
            importance = dict(
                sorted(
                    zip(feature_names, [float(v) for v in model.feature_importances_]),
                    key=lambda x: x[1], reverse=True,
                )
            )

        results[model_name] = {
            "accuracy": acc,
            "f1_macro": f1,
            "classification_report": _to_serializable(report),
            "confusion_matrix": matrix,
            "feature_importance": importance,
            "label": cfg["label"],
        }

        # Sauvegarder ce modèle
        joblib.dump(model, MODEL_DIR / f"{task}_{model_name}_model.pkl")
        if scaler is not None:
            joblib.dump(scaler, MODEL_DIR / f"{task}_{model_name}_scaler.pkl")

        if f1 > best_f1:
            best_f1 = f1
            best_name = model_name
            best_model = model
            best_scaler = scaler

    # Sauvegarder le meilleur
    if best_model is not None:
        joblib.dump(best_model, MODEL_DIR / f"{task}_best_model.pkl")
        if best_scaler is not None:
            joblib.dump(best_scaler, MODEL_DIR / f"{task}_best_scaler.pkl")
        (MODEL_DIR / f"{task}_best_name.txt").write_text(best_name or "", encoding="utf-8")

    return {"comparison": results, "best": best_name, "best_f1": best_f1}


def train_model(test_size: float = 0.2, limit: Optional[int] = None) -> Dict[str, Any]:
    """Entraîne sur Fév-Avr 2026, teste sur Mai 2026 (split temporel — pas de fuite de données).
    Les paramètres test_size et limit sont conservés pour compatibilité mais ignorés."""
    if not SKLEARN_OK:
        return {
            "status": "error",
            "message": "Dependances manquantes: pip install -r requirements_ia.txt",
        }

    try:
        df = load_training_dataset()
    except Exception as exc:
        return {"status": "error", "message": f"Erreur chargement donnees: {exc}"}

    if df.empty or len(df) < 30:
        return {
            "status": "error",
            "message": f"Donnees insuffisantes (minimum 30). Actuellement: {len(df)}.",
            "total": int(len(df)),
        }

    # ── Split temporel : train = avant mai 2026, test = mai 2026+ ─────────────
    df["date_analyse"] = pd.to_datetime(df["date_analyse"], errors="coerce")
    CUTOFF = pd.Timestamp("2026-05-01")

    mask_train = (df["date_analyse"] < CUTOFF) & (df["duree_jours"] >= 7)
    mask_test = df["date_analyse"] >= CUTOFF

    df_train_raw = df[mask_train].copy()
    df_test_raw = df[mask_test].copy()
    has_test = len(df_test_raw) >= 5

    if len(df_train_raw) < 30:
        # Pas assez de données dans la fenêtre temporelle → fallback sur tout le dataset
        df_train_raw = df[df["duree_jours"] >= 7].copy()
        if len(df_train_raw) < 30:
            df_train_raw = df.copy()
        df_train_raw = df_train_raw.sort_values("date_analyse").reset_index(drop=True)
        split_idx = max(5, int(len(df_train_raw) * 0.80))
        df_test_raw = df_train_raw.iloc[split_idx:].copy()
        df_train_raw = df_train_raw.iloc[:split_idx].copy()
        has_test = len(df_test_raw) >= 5

    # ── Feature engineering — encodeurs ajustés sur train uniquement ──────────
    try:
        df_train, encoders = _encode_categoricals(df_train_raw)
        df_train = _add_engineered_features(df_train)
        df_train = _add_projected_features(df_train)
    except Exception as exc:
        return {"status": "error", "message": f"Erreur preparation features (train): {exc}"}

    if has_test:
        try:
            df_test, _ = _encode_categoricals(df_test_raw, encoders)
            df_test = _add_engineered_features(df_test)
            df_test = _add_projected_features(df_test)
        except Exception:
            has_test = False

    try:
        # ── Encodage cibles ────────────────────────────────────────────────────
        etat_encoder = LabelEncoder()
        y_tr_e = etat_encoder.fit_transform(df_train["etat_cible"])

        df_tr_s = df_train[df_train["etat_cible"] != "A_VERIFIER_CAPACITE"].copy()
        if len(df_tr_s) < 20:
            df_tr_s = df_train.copy()
        statut_encoder = LabelEncoder()
        y_tr_s = statut_encoder.fit_transform(df_tr_s["statut_cible"])

        # ── Préparer X (train) ─────────────────────────────────────────────────
        X_tr_e = _prepare_X(df_train.copy(), FEATURES_ETAT)
        X_tr_s = _prepare_X(df_tr_s.copy(), FEATURES_ETAT)
        X_tr_w = _prepare_X(df_train.copy(), FEATURES_WEEK)

        # ── Préparer X/y (test) ────────────────────────────────────────────────
        if has_test:
            y_te_e = _safe_encode_labels(etat_encoder, df_test["etat_cible"])
            df_te_s = df_test[df_test["etat_cible"] != "A_VERIFIER_CAPACITE"].copy()
            if len(df_te_s) == 0:
                df_te_s = df_test.copy()
            y_te_s = _safe_encode_labels(statut_encoder, df_te_s["statut_cible"])
            X_te_e = _prepare_X(df_test.copy(), FEATURES_ETAT)
            X_te_s = _prepare_X(df_te_s.copy(), FEATURES_ETAT)
            X_te_w = _prepare_X(df_test.copy(), FEATURES_WEEK)
            y_te_w = y_te_e
        else:
            # Aucune donnée de test : on évalue sur les données train (métriques indicatives)
            X_te_e, y_te_e = X_tr_e.copy(), y_tr_e.copy()
            X_te_s, y_te_s = X_tr_s.copy(), y_tr_s.copy()
            X_te_w, y_te_w = X_tr_w.copy(), y_tr_e.copy()

        res_etat   = _train_one_task(X_tr_e, X_te_e, y_tr_e, y_te_e, etat_encoder,   "etat",      FEATURES_ETAT)
        res_statut = _train_one_task(X_tr_s, X_te_s, y_tr_s, y_te_s, statut_encoder, "statut",    FEATURES_ETAT)
        res_week   = _train_one_task(X_tr_w, X_te_w, y_tr_e, y_te_w, etat_encoder,   "week_etat", FEATURES_WEEK)

        joblib.dump(encoders,        MODEL_DIR / "feature_encoders.pkl")
        joblib.dump(etat_encoder,    MODEL_DIR / "etat_target_encoder.pkl")
        joblib.dump(statut_encoder,  MODEL_DIR / "statut_target_encoder.pkl")

        metadata: Dict[str, Any] = {
            "status": "ok",
            "trained_at": datetime.now().isoformat(),
            "split_method": "temporal",
            "train_cutoff": CUTOFF.isoformat(),
            "total_rows": int(len(df)),
            "train_rows": int(len(df_train)),
            "test_rows": int(len(df_test_raw) if has_test else 0),
            "rows_statut": int(len(df_tr_s)),
            "has_test_data": has_test,
            "etat_classes": etat_encoder.classes_.tolist(),
            "statut_classes": statut_encoder.classes_.tolist(),
            "etat": res_etat,
            "statut": res_statut,
            "week_etat": res_week,
            "features_etat": FEATURES_ETAT,
            "features_week": FEATURES_WEEK,
        }

        metadata = _to_serializable(metadata)
        (MODEL_DIR / "comparison_metadata.json").write_text(
            json.dumps(metadata, indent=2, ensure_ascii=False), encoding="utf-8"
        )
        return metadata

    except Exception as exc:
        return {"status": "error", "message": f"Erreur entrainement: {exc}"}


# ── Chargement des artefacts ────────────────────────────────────────────────────

def _load_task_artifacts(task: str) -> tuple:
    """Charge le meilleur modèle + scaler pour une tâche."""
    model = joblib.load(MODEL_DIR / f"{task}_best_model.pkl")
    scaler_path = MODEL_DIR / f"{task}_best_scaler.pkl"
    scaler = joblib.load(scaler_path) if scaler_path.exists() else None
    return model, scaler


def _load_all_artifacts() -> tuple:
    if not SKLEARN_OK:
        raise RuntimeError("Dependances IA non installees")
    encoders = joblib.load(MODEL_DIR / "feature_encoders.pkl")
    etat_enc = joblib.load(MODEL_DIR / "etat_target_encoder.pkl")
    statut_enc = joblib.load(MODEL_DIR / "statut_target_encoder.pkl")
    etat_model, etat_scaler = _load_task_artifacts("etat")
    statut_model, statut_scaler = _load_task_artifacts("statut")
    week_model, week_scaler = _load_task_artifacts("week_etat")
    return encoders, etat_enc, statut_enc, etat_model, etat_scaler, statut_model, statut_scaler, week_model, week_scaler


def _models_exist() -> bool:
    required = [
        "feature_encoders.pkl",
        "etat_target_encoder.pkl",
        "statut_target_encoder.pkl",
        "etat_best_model.pkl",
        "statut_best_model.pkl",
        "week_etat_best_model.pkl",
        "comparison_metadata.json",
    ]
    return all((MODEL_DIR / f).exists() for f in required)


def _best_model_name(task: str) -> str:
    path = MODEL_DIR / f"{task}_best_name.txt"
    return path.read_text(encoding="utf-8").strip() if path.exists() else "unknown"


# ── Prédiction ──────────────────────────────────────────────────────────────────

def normalize_site(site: Dict[str, Any]) -> Dict[str, Any]:
    return {
        "site": str(site.get("site") or site.get("nom") or "INCONNU"),
        "type_trans": str(site.get("type_trans") or "INCONNU"),
        "classification": str(site.get("classification") or "STANDARD"),
        "taux_utilisation": safe_float(site.get("taux_utilisation")),
        "taux_utilisation_tdd": safe_float(site.get("taux_utilisation_tdd")),
        "taux_utilisation_fdd": safe_float(site.get("taux_utilisation_fdd")),
        "nombre_occurrences": safe_int(site.get("nombre_occurrences")),
        "nombre_occurrences_tdd": safe_int(site.get("nombre_occurrences_tdd") or site.get("occurrences_tdd")),
        "nombre_occurrences_fdd": safe_int(site.get("nombre_occurrences_fdd") or site.get("occurrences_fdd")),
        "duree_jours": safe_int(site.get("duree_jours") or 7) or 7,
        "variation_j1": safe_float(site.get("variation_j1")),
        "variation_j7": safe_float(site.get("variation_j7")),
        "max_trafic": safe_float(site.get("max_trafic") or site.get("trafic_max")),
        "max_trafic_tdd": safe_float(site.get("max_trafic_tdd") or site.get("trafic_tdd")),
        "max_trafic_fdd": safe_float(site.get("max_trafic_fdd") or site.get("trafic_fdd")),
        "capacite_mbps": safe_float(site.get("capacite_mbps")),
        "capacite_tdd_mbps": safe_float(site.get("capacite_tdd_mbps") or site.get("capacite_tdd")),
        "capacite_fdd_mbps": safe_float(site.get("capacite_fdd_mbps") or site.get("capacite_fdd")),
    }


def _predict_with_model(
    row_df: pd.DataFrame,
    features: List[str],
    model: Any,
    scaler: Optional[Any],
    target_encoder: Any,
) -> tuple:
    X = _prepare_X(row_df.copy(), features)
    Xs = scaler.transform(X) if scaler is not None else X.values
    code = model.predict(Xs)[0]
    probas_arr = model.predict_proba(Xs)[0]
    label = str(target_encoder.inverse_transform([code])[0])
    confidence = float(max(probas_arr))
    probas = {
        str(target_encoder.classes_[i]): round(float(p), 4)
        for i, p in enumerate(probas_arr)
    }
    return label, confidence, probas


def _project_site_j7(normalized: Dict[str, Any]) -> Dict[str, Any]:
    """Calcule les features projetées à J+7."""
    growth = max(-0.5, min(1.0, normalized["variation_j7"] / 100))
    mult = 1 + growth
    duree = max(1, normalized["duree_jours"])
    occ_rate = normalized["nombre_occurrences"] / duree
    occ_tdd_rate = normalized["nombre_occurrences_tdd"] / duree
    occ_fdd_rate = normalized["nombre_occurrences_fdd"] / duree
    occ_proj = int(normalized["nombre_occurrences"] + occ_rate * 7)
    occ_tdd_proj = int(normalized["nombre_occurrences_tdd"] + occ_tdd_rate * 7)
    occ_fdd_proj = int(normalized["nombre_occurrences_fdd"] + occ_fdd_rate * 7)
    taux_proj = min(normalized["taux_utilisation"] * mult, 300)
    taux_tdd_proj = min(normalized["taux_utilisation_tdd"] * mult, 300)
    taux_fdd_proj = min(normalized["taux_utilisation_fdd"] * mult, 300)
    return {
        **normalized,
        "taux_proj_j7": taux_proj,
        "taux_tdd_proj_j7": taux_tdd_proj,
        "taux_fdd_proj_j7": taux_fdd_proj,
        "taux_max_proj_j7": max(taux_proj, taux_tdd_proj, taux_fdd_proj),
        "occ_proj_j7": occ_proj,
        "occ_tdd_proj_j7": occ_tdd_proj,
        "occ_fdd_proj_j7": occ_fdd_proj,
        "occ_norm_proj_j7": round(occ_proj / 7 * 7, 2),
        "max_trafic_proj_j7": normalized["max_trafic"] * mult,
    }


def predict_site(site: Dict[str, Any], persist: bool = True) -> Dict[str, Any]:
    start = time.time()
    normalized = normalize_site(site)
    source = "model"
    etat_model_name = "unknown"

    try:
        (
            encoders, etat_enc, statut_enc,
            etat_model, etat_scaler,
            statut_model, statut_scaler,
            week_model, week_scaler,
        ) = _load_all_artifacts()

        row_df = pd.DataFrame([normalized])
        row_df, _ = _encode_categoricals(row_df, encoders)
        row_df = _add_engineered_features(row_df)
        row_df = _add_projected_features(row_df)

        # ── Prédiction état actuel ─────────────────────────────────────────────
        etat, conf_etat, probas_etat = _predict_with_model(row_df, FEATURES_ETAT, etat_model, etat_scaler, etat_enc)

        # ── Prédiction statut actuel ───────────────────────────────────────────
        if etat == "A_VERIFIER_CAPACITE":
            statut = ""
            conf_statut = 1.0
            probas_statut = {"AUCUN": 1.0}
        elif etat == "RISQUE_DE_CONGESTION":
            statut = "SOUS_OBSERVATION"
            conf_statut = 1.0
            probas_statut = {"SOUS_OBSERVATION": 1.0}
        else:
            statut, conf_statut, probas_statut = _predict_with_model(row_df, FEATURES_ETAT, statut_model, statut_scaler, statut_enc)

        # ── Prédiction J+7 ────────────────────────────────────────────────────
        proj_j7 = _project_site_j7(normalized)
        proj_df = pd.DataFrame([proj_j7])
        proj_df, _ = _encode_categoricals(proj_df, encoders)
        etat_j7, conf_j7, _ = _predict_with_model(proj_df, FEATURES_WEEK, week_model, week_scaler, etat_enc)
        statut_j7 = _compute_statut_j7(proj_j7, etat_j7, statut_model, statut_scaler, statut_enc, encoders)

        etat_model_name = _best_model_name("etat")

    except Exception:
        source = "rules_fallback"
        etat = business_etat(normalized)
        statut = "" if etat == "A_VERIFIER_CAPACITE" else business_statut(normalized, normalized["duree_jours"])
        conf_etat = _fallback_confidence(normalized, etat)
        conf_statut = 0.75
        probas_etat = {etat: conf_etat}
        probas_statut = {statut or "AUCUN": conf_statut}

        proj_j7 = _project_site_j7(normalized)
        etat_j7 = business_etat({**normalized,
            "taux_utilisation": proj_j7["taux_proj_j7"],
            "taux_utilisation_tdd": proj_j7["taux_tdd_proj_j7"],
            "taux_utilisation_fdd": proj_j7["taux_fdd_proj_j7"],
            "nombre_occurrences": proj_j7["occ_proj_j7"],
            "nombre_occurrences_tdd": proj_j7["occ_tdd_proj_j7"],
            "nombre_occurrences_fdd": proj_j7["occ_fdd_proj_j7"],
        })
        statut_j7 = "" if etat_j7 == "A_VERIFIER_CAPACITE" else business_statut(
            {**normalized,
             "taux_utilisation": proj_j7["taux_proj_j7"],
             "taux_utilisation_tdd": proj_j7["taux_tdd_proj_j7"],
             "taux_utilisation_fdd": proj_j7["taux_fdd_proj_j7"],
             "nombre_occurrences": proj_j7["occ_proj_j7"],
             "nombre_occurrences_tdd": proj_j7["occ_tdd_proj_j7"],
             "nombre_occurrences_fdd": proj_j7["occ_fdd_proj_j7"]}, 7
        )
        conf_j7 = 0.65

    cause = _determine_cause(normalized, etat)
    risk = _determine_risk(etat, statut, conf_etat)
    action = _recommended_action(etat, cause)
    elapsed_ms = int((time.time() - start) * 1000)

    prediction_semaine = {
        "etat_predit": etat_j7,
        "statut_predit": statut_j7,
        "confiance_etat": round(conf_j7, 4),
        "taux_proj": round(proj_j7["taux_proj_j7"], 2),
        "taux_tdd_proj": round(proj_j7["taux_tdd_proj_j7"], 2),
        "taux_fdd_proj": round(proj_j7["taux_fdd_proj_j7"], 2),
        "occ_proj": proj_j7["occ_proj_j7"],
        "occ_tdd_proj": proj_j7["occ_tdd_proj_j7"],
        "occ_fdd_proj": proj_j7["occ_fdd_proj_j7"],
    }

    result = {
        # Champs compatibles avec l'ancienne API
        "site": normalized["site"],
        "etat_predit": etat,
        "statut_predit": statut,
        "niveau_risque": risk,
        "cause_probable": cause,
        "niveau_confiance": round(conf_etat, 4),
        "action_recommandee": action,
        "probabilites": probas_etat,
        "source": source,
        "temps_calcul_ms": elapsed_ms,
        "entree": normalized,
        # Nouveaux champs
        "modele_utilise": etat_model_name,
        "confiance_etat": round(conf_etat, 4),
        "confiance_statut": round(conf_statut, 4),
        "probabilites_statut": probas_statut,
        "prediction_semaine": prediction_semaine,
    }

    if persist:
        _save_prediction(result)

    return result


def _compute_statut_j7(
    proj_j7: Dict, etat_j7: str,
    statut_model: Any, statut_scaler: Any, statut_enc: Any, encoders: Dict
) -> str:
    if etat_j7 == "A_VERIFIER_CAPACITE":
        return ""
    if etat_j7 == "RISQUE_DE_CONGESTION":
        return "SOUS_OBSERVATION"
    try:
        proj_df = pd.DataFrame([proj_j7])
        proj_df, _ = _encode_categoricals(proj_df, encoders)
        proj_df["taux_utilisation"] = proj_j7.get("taux_proj_j7", 0)
        proj_df["taux_utilisation_tdd"] = proj_j7.get("taux_tdd_proj_j7", 0)
        proj_df["taux_utilisation_fdd"] = proj_j7.get("taux_fdd_proj_j7", 0)
        proj_df["nombre_occurrences"] = proj_j7.get("occ_proj_j7", 0)
        proj_df["nombre_occurrences_tdd"] = proj_j7.get("occ_tdd_proj_j7", 0)
        proj_df["nombre_occurrences_fdd"] = proj_j7.get("occ_fdd_proj_j7", 0)
        proj_df = _add_engineered_features(proj_df)
        label, _, _ = _predict_with_model(proj_df, FEATURES_ETAT, statut_model, statut_scaler, statut_enc)
        return label
    except Exception:
        return business_statut(
            {"type_trans": proj_j7.get("type_trans", ""),
             "classification": proj_j7.get("classification", ""),
             "capacite_mbps": proj_j7.get("capacite_mbps", 0),
             "taux_utilisation": proj_j7.get("taux_proj_j7", 0),
             "taux_utilisation_tdd": proj_j7.get("taux_tdd_proj_j7", 0),
             "taux_utilisation_fdd": proj_j7.get("taux_fdd_proj_j7", 0),
             "nombre_occurrences": proj_j7.get("occ_proj_j7", 0),
             "nombre_occurrences_tdd": proj_j7.get("occ_tdd_proj_j7", 0),
             "nombre_occurrences_fdd": proj_j7.get("occ_fdd_proj_j7", 0)}, 7
        )


# ── Logique métier de support ────────────────────────────────────────────────────

def _determine_cause(site: Dict[str, Any], etat: str) -> str:
    type_trans = str(site.get("type_trans") or "").upper()
    if site["capacite_mbps"] <= 0 and etat != "A_VERIFIER_CAPACITE":
        return "DONNEES_INCOMPLETES"
    if _is_unknown_type(type_trans):
        return "TYPE_TRANSMISSION_MANQUANT"
    if site["nombre_occurrences_tdd"] >= CRITICAL_OCCURRENCES_PER_WEEK:
        return "PROBLEME_TDD"
    if site["nombre_occurrences_fdd"] >= CRITICAL_OCCURRENCES_PER_WEEK:
        return "PROBLEME_FDD"
    if site["variation_j7"] >= 25:
        return "CROISSANCE_TRAFIC"
    if site["taux_utilisation_tdd"] > CONGESTION_RATE_THRESHOLD and site["nombre_occurrences_tdd"] >= 30:
        return "PROBLEME_CAPACITE_TDD"
    if site["taux_utilisation_fdd"] > CONGESTION_RATE_THRESHOLD and site["nombre_occurrences_fdd"] >= 30:
        return "PROBLEME_CAPACITE_FDD"
    return {
        "CONGESTION": "CAPACITE_INSUFFISANTE",
        "RISQUE_DE_CONGESTION": "RISQUE_CONGESTION",
        "BRIDAGE": "BRIDAGE_POSSIBLE",
        "A_VERIFIER_CAPACITE": "CAPACITE_MAL_RENSEIGNEE" if site["taux_utilisation"] > CAPACITY_CHECK_RATE_THRESHOLD else "TYPE_INCONNU",
        "OK": "AUCUNE_ANOMALIE",
    }.get(etat, "AUCUNE_ANOMALIE")


def _determine_risk(etat: str, statut: str, confidence: float) -> str:
    if etat == "CONGESTION" and confidence >= 0.75:
        return "CRITIQUE"
    if etat == "CONGESTION":
        return "ELEVE"
    if etat in {"BRIDAGE", "A_VERIFIER_CAPACITE"}:
        return "ELEVE" if confidence >= 0.7 else "MOYEN"
    if statut == "CRITIQUE":
        return "ELEVE"
    if statut == "SOUS_OBSERVATION":
        return "MOYEN"
    return "FAIBLE"


def _recommended_action(etat: str, cause: str) -> str:
    actions = {
        "CAPACITE_INSUFFISANTE": "Planifier un upgrade capacite et verifier le dimensionnement.",
        "RISQUE_CONGESTION": "Surveiller le site et confirmer l'evolution des occurrences.",
        "BRIDAGE_POSSIBLE": "Verifier les limitations transport, QoS et seuils de bridage.",
        "CAPACITE_MAL_RENSEIGNEE": "Controler la capacite declaree et les ports actifs.",
        "TYPE_INCONNU": "Renseigner le type de transmission du site.",
        "TYPE_TRANSMISSION_MANQUANT": "Renseigner le type de transmission du site.",
        "PROBLEME_TDD": "Diagnostiquer la couche TDD et les ressources radio associees.",
        "PROBLEME_FDD": "Diagnostiquer la couche FDD et la capacite associee.",
        "PROBLEME_CAPACITE_TDD": "Verifier la capacite TDD et les ressources allouees.",
        "PROBLEME_CAPACITE_FDD": "Verifier la capacite FDD et les ressources allouees.",
        "DONNEES_INCOMPLETES": "Completer les donnees : capacite, type transmission, classification.",
        "CROISSANCE_TRAFIC": "Analyser la tendance et anticiper l'augmentation de capacite.",
        "AUCUNE_ANOMALIE": "Surveillance normale.",
    }
    if etat == "OK":
        return "Surveillance normale."
    return actions.get(cause, "Verifier le site et confirmer le diagnostic terrain.")


def _fallback_confidence(site: Dict[str, Any], etat: str) -> float:
    taux_max = max(site["taux_utilisation"], site["taux_utilisation_tdd"], site["taux_utilisation_fdd"])
    occ_max = max(site["nombre_occurrences"], site["nombre_occurrences_tdd"], site["nombre_occurrences_fdd"])
    if etat == "CONGESTION":
        return min(0.95, 0.78 + min(taux_max - 90, 20) / 100 + min(occ_max - 50, 80) / 500)
    if etat == "RISQUE_DE_CONGESTION":
        return 0.74
    if etat == "BRIDAGE":
        return min(0.90, 0.74 + min(occ_max - 50, 100) / 600)
    if etat == "A_VERIFIER_CAPACITE":
        return 0.72
    return 0.86


# ── Statut du modèle ────────────────────────────────────────────────────────────

def model_status() -> Dict[str, Any]:
    metadata_path = MODEL_DIR / "comparison_metadata.json"
    model_loaded = _models_exist()
    metadata = {}
    if metadata_path.exists():
        metadata = json.loads(metadata_path.read_text(encoding="utf-8"))

    return {
        "status": "ok",
        "model_loaded": model_loaded,
        "dependencies_ready": SKLEARN_OK,
        "best_etat_model": _best_model_name("etat") if model_loaded else None,
        "best_statut_model": _best_model_name("statut") if model_loaded else None,
        "best_week_model": _best_model_name("week_etat") if model_loaded else None,
        "metadata": metadata,
    }


# ── Persistance des prédictions ──────────────────────────────────────────────────

def _save_prediction(prediction: Dict[str, Any]) -> None:
    ensure_prediction_table()
    semaine = prediction.get("prediction_semaine") or {}
    with connect() as conn:
        with conn.cursor() as cur:
            cur.execute("""
                INSERT INTO predictions_ia (
                    site, etat_predit, statut_predit,
                    etat_predit_semaine, statut_predit_semaine,
                    niveau_risque, cause_probable,
                    niveau_confiance, confiance_etat, confiance_statut, confiance_semaine,
                    modele_utilise, action_recommandee,
                    probabilites, probabilites_statut, entree, prediction_semaine, temps_calcul_ms
                ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """, (
                prediction["site"],
                prediction["etat_predit"],
                prediction["statut_predit"],
                semaine.get("etat_predit"),
                semaine.get("statut_predit"),
                prediction["niveau_risque"],
                prediction["cause_probable"],
                prediction["niveau_confiance"],
                prediction.get("confiance_etat"),
                prediction.get("confiance_statut"),
                semaine.get("confiance_etat"),
                prediction.get("modele_utilise"),
                prediction["action_recommandee"],
                Json(prediction["probabilites"]),
                Json(prediction.get("probabilites_statut") or {}),
                Json(prediction["entree"]),
                Json(semaine),
                prediction["temps_calcul_ms"],
            ))
        conn.commit()


# ── API publique ─────────────────────────────────────────────────────────────────

def latest_sites(limit: int = 100) -> List[Dict[str, Any]]:
    limit = max(1, min(int(limit), 500))
    sql = """
        SELECT DISTINCT ON (a.site)
            a.site,
            COALESCE(NULLIF(a.type_trans, ''), 'INCONNU')      AS type_trans,
            COALESCE(NULLIF(a.classification, ''), 'STANDARD') AS classification,
            COALESCE(a.taux_utilisation,
                CASE WHEN COALESCE(a.capacite_mbps, 0) > 0
                     THEN ROUND((a.max_trafic / a.capacite_mbps) * 100, 2)
                     ELSE 0 END)                               AS taux_utilisation,
            COALESCE(a.taux_utilisation_tdd, 0)                AS taux_utilisation_tdd,
            COALESCE(a.taux_utilisation_fdd, 0)                AS taux_utilisation_fdd,
            COALESCE(a.nombre_occurrences, 0)                  AS nombre_occurrences,
            COALESCE(a.nombre_occurrences_tdd, 0)              AS nombre_occurrences_tdd,
            COALESCE(a.nombre_occurrences_fdd, 0)              AS nombre_occurrences_fdd,
            COALESCE(NULLIF(a.duree_jours, 0), 7)              AS duree_jours,
            COALESCE(se.variation_j1, 0)                       AS variation_j1,
            COALESCE(se.variation_j7, 0)                       AS variation_j7,
            COALESCE(a.max_trafic, 0)                          AS max_trafic,
            COALESCE(a.max_trafic_tdd, 0)                      AS max_trafic_tdd,
            COALESCE(a.max_trafic_fdd, 0)                      AS max_trafic_fdd,
            COALESCE(a.capacite_mbps, 0)                       AS capacite_mbps,
            COALESCE(a.capacite_tdd_mbps, 0)                   AS capacite_tdd_mbps,
            COALESCE(a.capacite_fdd_mbps, 0)                   AS capacite_fdd_mbps,
            a.date_analyse
        FROM analyse_resultat a
        LEFT JOIN site_etat se ON LOWER(se.site) = LOWER(a.site)
        WHERE a.max_trafic IS NOT NULL
        ORDER BY a.site, a.date_analyse DESC, a.id DESC
        LIMIT %s
    """
    with connect() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, (limit,))
            cols = [d[0] for d in cur.description]
            return [dict(zip(cols, row)) for row in cur.fetchall()]


def predict_sites(sites: List[Dict[str, Any]], persist: bool = True) -> List[Dict[str, Any]]:
    return [predict_site(s, persist=persist) for s in sites]


def prediction_history(limit: int = 100) -> List[Dict[str, Any]]:
    ensure_prediction_table()
    limit = max(1, min(int(limit), 500))
    with connect() as conn:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT site, date_prediction, etat_predit, statut_predit,
                       etat_predit_semaine, statut_predit_semaine,
                       niveau_risque, cause_probable, niveau_confiance,
                       confiance_etat, confiance_statut, confiance_semaine,
                       modele_utilise, action_recommandee,
                       probabilites, probabilites_statut, prediction_semaine, temps_calcul_ms
                FROM predictions_ia
                ORDER BY date_prediction DESC
                LIMIT %s
            """, (limit,))
            cols = [d[0] for d in cur.description]
            rows = []
            for row in cur.fetchall():
                item = dict(zip(cols, row))
                if item.get("date_prediction"):
                    item["date_prediction"] = item["date_prediction"].isoformat()
                for key in ("niveau_confiance", "confiance_etat", "confiance_statut", "confiance_semaine"):
                    if item.get(key) is not None:
                        item[key] = float(item[key])
                rows.append(item)
            return rows


def get_model_comparison() -> Dict[str, Any]:
    """Retourne la comparaison des 3 modèles depuis les métadonnées d'entraînement."""
    path = MODEL_DIR / "comparison_metadata.json"
    if not path.exists():
        return {"status": "no_model", "message": "Aucun modèle entraîné."}
    metadata = json.loads(path.read_text(encoding="utf-8"))
    return {
        "status": "ok",
        "trained_at": metadata.get("trained_at"),
        "best_etat_model": metadata.get("etat", {}).get("best"),
        "best_statut_model": metadata.get("statut", {}).get("best"),
        "best_week_model": metadata.get("week_etat", {}).get("best"),
        "etat_comparison": metadata.get("etat", {}).get("comparison", {}),
        "statut_comparison": metadata.get("statut", {}).get("comparison", {}),
        "week_comparison": metadata.get("week_etat", {}).get("comparison", {}),
        "etat_classes": metadata.get("etat_classes", []),
        "statut_classes": metadata.get("statut_classes", []),
        "total_rows": metadata.get("total_rows"),
    }


# ── Utilitaires ──────────────────────────────────────────────────────────────────

def safe_float(value: Any) -> float:
    try:
        if value is None or value == "":
            return 0.0
        return float(str(value).replace(",", "."))
    except Exception:
        return 0.0


def safe_int(value: Any) -> int:
    try:
        if value is None or value == "":
            return 0
        return int(float(str(value).replace(",", ".")))
    except Exception:
        return 0
