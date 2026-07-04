import math
import os
import re
import statistics
from collections import defaultdict
from datetime import datetime, timedelta

import psycopg2
from psycopg2.extras import RealDictCursor

try:
    from sklearn.ensemble import IsolationForest
    from sklearn.preprocessing import StandardScaler
    import joblib
    SKLEARN_AVAILABLE = True
except Exception:
    IsolationForest = None
    StandardScaler = None
    joblib = None
    SKLEARN_AVAILABLE = False

try:
    from statsmodels.tsa.seasonal import STL
    STATSMODELS_AVAILABLE = True
except Exception:
    STL = None
    STATSMODELS_AVAILABLE = False


DB_CONFIG = {
    "host": os.getenv("DB_HOST", "127.0.0.1"),
    "port": int(os.getenv("DB_PORT", "5432")),
    "dbname": os.getenv("DB_NAME", "PFE_5G"),
    "user": os.getenv("DB_USER", "postgres"),
    "password": os.getenv("DB_PASSWORD", "root"),
}

MODELS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "models")

SUFFIX_PATTERN = re.compile(
    r"_(LM2?|LMB|TF|TC|TD|TDD|FDD|TT|BH|BO|TR|OO|OR|L2B)(_[A-Z0-9]+)*$",
    re.IGNORECASE,
)


# ─────────────────────────────────────────────
#  Utilities (unchanged)
# ─────────────────────────────────────────────

def get_connection():
    return psycopg2.connect(**DB_CONFIG)


def clean_site_name(site):
    if not site:
        return ""
    return SUFFIX_PATTERN.sub("", str(site).strip()).upper()


def safe_float(value, default=0.0):
    try:
        if value is None:
            return default
        return float(value)
    except Exception:
        return default


def median(values):
    values = [safe_float(v) for v in values if v is not None]
    return statistics.median(values) if values else 0.0


def mad(values):
    values = [safe_float(v) for v in values if v is not None]
    if len(values) < 3:
        return 0.0
    med = statistics.median(values)
    return statistics.median([abs(v - med) for v in values])


def robust_z_score(value, values):
    values = [safe_float(v) for v in values if v is not None]
    if len(values) < 5:
        return 0.0

    med = statistics.median(values)
    mad_value = mad(values)
    if mad_value > 0:
        return 0.6745 * (safe_float(value) - med) / mad_value

    std_value = statistics.pstdev(values)
    if std_value > 0:
        return (safe_float(value) - statistics.mean(values)) / std_value

    return 0.0 if safe_float(value) == med else 6.0


def percent_change(current, reference):
    current = safe_float(current)
    reference = safe_float(reference)
    if reference <= 0:
        return 0.0 if current <= 0 else 999.0
    return ((current - reference) / reference) * 100.0


# ─────────────────────────────────────────────
#  Database helpers (unchanged)
# ─────────────────────────────────────────────

def get_latest_time(cur):
    cur.execute("SELECT MAX(date_heure) AS latest_time FROM trafic_historique")
    row = cur.fetchone()
    return row["latest_time"] if row and row["latest_time"] else None


def get_latest_capacity_map(cur):
    capacity_map = {}

    cur.execute("""
        SELECT DISTINCT ON (UPPER(site))
            UPPER(site) AS site,
            capacite_mbps,
            type_trans
        FROM capacite_site
        WHERE capacite_mbps IS NOT NULL AND capacite_mbps > 0
        ORDER BY UPPER(site), date_mise_ajour DESC, id DESC
    """)
    for row in cur.fetchall():
        clean = clean_site_name(row["site"])
        capacity_map[clean] = {
            "capacite_mbps": safe_float(row["capacite_mbps"]),
            "classification": None,
            "type_trans": row["type_trans"],
        }

    cur.execute("""
        SELECT DISTINCT ON (UPPER(site))
            UPPER(site) AS site,
            capacite_mbps,
            classification,
            type_trans
        FROM analyse_resultat
        WHERE capacite_mbps IS NOT NULL AND capacite_mbps > 0
        ORDER BY UPPER(site), date_analyse DESC, id DESC
    """)
    for row in cur.fetchall():
        clean = clean_site_name(row["site"])
        if clean not in capacity_map:
            capacity_map[clean] = {
                "capacite_mbps": safe_float(row["capacite_mbps"]),
                "classification": row["classification"],
                "type_trans": row["type_trans"],
            }

    cur.execute("""
        SELECT DISTINCT ON (UPPER(name))
            UPPER(name) AS site,
            COALESCE(capacite_fdd_mbps, 0) + COALESCE(capacite_tdd_mbps, 0) AS capacite_mbps,
            classification,
            type_trans
        FROM managed_site
        ORDER BY UPPER(name), updated_at DESC, id DESC
    """)
    for row in cur.fetchall():
        clean = clean_site_name(row["site"])
        capacite = safe_float(row["capacite_mbps"])
        if capacite > 0 and clean not in capacity_map:
            capacity_map[clean] = {
                "capacite_mbps": capacite,
                "classification": row["classification"],
                "type_trans": row["type_trans"],
            }

    return capacity_map


def get_occurrences_map(cur):
    cur.execute("""
        SELECT DISTINCT ON (UPPER(site))
            UPPER(site) AS site,
            nombre_occurrences
        FROM analyse_resultat
        ORDER BY UPPER(site), date_analyse DESC, id DESC
    """)
    return {
        clean_site_name(row["site"]): int(row["nombre_occurrences"] or 0)
        for row in cur.fetchall()
    }


def load_site_rows(cur, site, start_time, end_time):
    clean_site = clean_site_name(site)
    cur.execute("""
        SELECT
            site,
            REGEXP_REPLACE(
                UPPER(site),
                '_(LM2?|LMB|TF|TC|TD|TDD|FDD|TT|BH|BO|TR|OO|OR|L2B)(_[A-Z0-9]+)*$',
                '',
                'i'
            ) AS clean_site,
            date_heure,
            EXTRACT(HOUR FROM date_heure)::int AS hour,
            EXTRACT(DOW FROM date_heure)::int AS day_of_week,
            max_speed
        FROM trafic_historique
        WHERE REGEXP_REPLACE(
                UPPER(site),
                '_(LM2?|LMB|TF|TC|TD|TDD|FDD|TT|BH|BO|TR|OO|OR|L2B)(_[A-Z0-9]+)*$',
                '',
                'i'
            ) = %s
          AND date_heure >= %s
          AND date_heure <= %s
        ORDER BY date_heure ASC, site ASC
    """, (clean_site, start_time, end_time))
    return cur.fetchall()


def load_active_sites(cur, latest_time, limit_sites):
    start_recent = latest_time - timedelta(days=7)
    cur.execute("""
        SELECT
            REGEXP_REPLACE(
                UPPER(site),
                '_(LM2?|LMB|TF|TC|TD|TDD|FDD|TT|BH|BO|TR|OO|OR|L2B)(_[A-Z0-9]+)*$',
                '',
                'i'
            ) AS clean_site,
            COUNT(*) AS total_points,
            MAX(date_heure) AS last_seen
        FROM trafic_historique
        WHERE date_heure >= %s
        GROUP BY clean_site
        HAVING COUNT(*) >= 12
        ORDER BY last_seen DESC, total_points DESC
        LIMIT %s
    """, (start_recent, limit_sites))
    return [row["clean_site"] for row in cur.fetchall() if row["clean_site"]]


def group_history(rows, current_start):
    by_site = []
    by_hour = {}
    by_day_hour = {}

    for row in rows:
        if row["date_heure"] >= current_start:
            continue

        value = safe_float(row["max_speed"])
        hour = int(row["hour"])
        day = int(row["day_of_week"])

        by_site.append(value)
        by_hour.setdefault(hour, []).append(value)
        by_day_hour.setdefault((day, hour), []).append(value)

    return by_site, by_hour, by_day_hour


def historical_volatility(values, baseline):
    values = [safe_float(v) for v in values if v is not None]
    baseline = safe_float(baseline)
    if len(values) < 5 or baseline <= 0:
        return 35.0

    changes = [abs(percent_change(v, baseline)) for v in values]
    return max(20.0, median(changes) + 2.5 * mad(changes))


def build_ml_features(rows, capacity=0.0):
    features = []
    previous = None
    same_hour_values = {}
    recent_window = []

    for row in rows:
        value = safe_float(row["max_speed"])
        hour = int(row["hour"])
        day = int(row["day_of_week"])
        is_weekend = 1.0 if day in (0, 6) else 0.0
        prev_value = value if previous is None else previous
        hour_values = same_hour_values.get(hour, [])
        hour_baseline = median(hour_values) if hour_values else value

        recent_window.append(value)
        if len(recent_window) > 3:
            recent_window.pop(0)
        slope = (recent_window[-1] - recent_window[0]) / len(recent_window) if len(recent_window) >= 2 else 0.0
        rolling_std = statistics.pstdev(recent_window) if len(recent_window) > 1 else 0.0
        util_rate = min(value / capacity, 2.0) if capacity > 0 else 0.0

        features.append([
            value,
            math.sin((2 * math.pi * hour) / 24),
            math.cos((2 * math.pi * hour) / 24),
            math.sin((2 * math.pi * day) / 7),
            math.cos((2 * math.pi * day) / 7),
            is_weekend,
            percent_change(value, prev_value),
            percent_change(value, hour_baseline),
            slope,
            rolling_std,
            util_rate,
        ])

        same_hour_values.setdefault(hour, []).append(value)
        previous = value

    return features


# ─────────────────────────────────────────────
#  NEW — STL decomposition
#  Absorbs sustained plateau into the trend so
#  residuals normalise → no spam on plateau points.
# ─────────────────────────────────────────────

def _build_aggregated_series(rows):
    """
    Collapse all site variants (TDD/FDD/…) into one series per timestamp
    by taking the max value.  Returns (sorted_timestamps, values).
    """
    time_to_values = defaultdict(list)
    for row in rows:
        time_to_values[row["date_heure"]].append(safe_float(row["max_speed"]))
    sorted_times = sorted(time_to_values.keys())
    values = [max(time_to_values[t]) for t in sorted_times]
    return sorted_times, values


def compute_stl_residuals(rows):
    """
    Fit STL on the full time series (history + current window).
    Returns {timestamp: residual} or {} when data is insufficient.

    After a sustained plateau STL absorbs it into the trend component,
    so residuals become small → plateau points are no longer flagged.
    """
    if not STATSMODELS_AVAILABLE:
        return {}

    timestamps, values = _build_aggregated_series(rows)

    if len(values) < 48:
        return {}

    try:
        import pandas as pd

        # Estimate the period (points per day) from the median interval
        intervals_h = []
        for i in range(1, min(30, len(timestamps))):
            diff_h = (timestamps[i] - timestamps[i - 1]).total_seconds() / 3600
            if 0.1 < diff_h < 3.0:
                intervals_h.append(diff_h)

        avg_interval_h = statistics.median(intervals_h) if intervals_h else 1.0
        period = max(6, int(round(24 / avg_interval_h)))

        if len(values) < period * 2:
            return {}

        # seasonal window must be odd and >= 7
        seasonal_window = max(7, period * 2 + 1)
        if seasonal_window % 2 == 0:
            seasonal_window += 1

        series = pd.Series(values)
        stl = STL(series, period=period, robust=True, seasonal=seasonal_window)
        result = stl.fit()
        residuals = list(result.resid)

        return {timestamps[i]: residuals[i] for i in range(len(timestamps))}

    except Exception:
        return {}


# ─────────────────────────────────────────────
#  NEW — CUSUM change-point detection
#  Detects the exact moment a level shift occurs,
#  then resets its reference → no further trigger
#  during the resulting plateau.
# ─────────────────────────────────────────────

def cusum_changepoints(rows, threshold=4.0, min_gap=10):
    """
    Detect timestamps where a sustained level shift begins.
    Returns a set of datetime objects limited to the current window.

    threshold : sensitivity — lower value = more sensitive (default 4.0)
    drift     : tolerance for natural variation (default 0.1 normalised units)
    min_gap   : minimum points between two consecutive detections
    """
    timestamps, values = _build_aggregated_series(rows)

    if len(values) < 20:
        return set()

    # Use the first 30 % of data as the reference period
    ref_len = max(5, len(values) // 3)
    ref_values = values[:ref_len]
    mean_ref = statistics.mean(ref_values)
    std_ref = statistics.pstdev(ref_values) if len(ref_values) > 1 else 1.0
    if std_ref < 0.001:
        std_ref = max(1.0, abs(mean_ref) * 0.05)

    # Drift adaptatif : proportionnel à la variabilité du site normalisée
    drift = max(0.05, min(0.3, 0.5 * (std_ref / max(mean_ref, 1.0))))

    cusum_pos = 0.0
    cusum_neg = 0.0
    flagged = set()
    last_change_idx = -min_gap

    for i, v in enumerate(values):
        normalised = (v - mean_ref) / std_ref
        cusum_pos = max(0.0, cusum_pos + normalised - drift)
        cusum_neg = max(0.0, cusum_neg - normalised - drift)

        if (cusum_pos > threshold or cusum_neg > threshold) and (i - last_change_idx) >= min_gap:
            flagged.add(timestamps[i])
            # Update reference to the new level so the plateau does not retrigger
            window = values[max(0, i - 5): i + 1]
            mean_ref = statistics.mean(window)
            new_std = statistics.pstdev(window) if len(window) > 1 else std_ref
            if new_std > 0.001:
                std_ref = new_std
            cusum_pos = 0.0
            cusum_neg = 0.0
            last_change_idx = i

    return flagged


# ─────────────────────────────────────────────
#  NEW — Event grouping
#  Collapses consecutive anomalous points into
#  a single event record with phase information.
# ─────────────────────────────────────────────

def _detect_phase(points):
    """Return MONTÉE / PLATEAU / RETOUR for a group of anomaly points."""
    if len(points) <= 1:
        return "MONTÉE"

    variations = [p.get("variation_vs_precedent", 0.0) for p in points]
    first_var_abs = abs(variations[0]) if variations else 0.0
    recent = variations[-3:] if len(variations) >= 3 else variations
    recent_avg_abs = statistics.mean([abs(v) for v in recent]) if recent else 0.0

    # Big initial jump AND stable recently → plateau
    if first_var_abs > 20.0 and recent_avg_abs < 5.0:
        return "PLATEAU"

    # Stable at elevated/depressed level
    if recent_avg_abs < 5.0:
        return "PLATEAU"

    # Trending back toward normal
    if len(points) >= 2:
        vals = [p["trafic_actuel"] for p in points]
        if vals[-1] < vals[0] * 0.85:
            return "RETOUR"

    return "MONTÉE"


def _aggregate_event(points):
    """Merge a list of consecutive anomaly dicts into a single event record."""
    if not points:
        return None

    base = max(points, key=lambda p: p["confiance"])
    peak_value = max(p["trafic_actuel"] for p in points)
    phase = _detect_phase(points)

    try:
        t_start = datetime.fromisoformat(points[0]["date_heure"])
        t_end = datetime.fromisoformat(points[-1]["date_heure"])
        duration_min = int((t_end - t_start).total_seconds() / 60)
    except Exception:
        duration_min = 0

    all_sources = set()
    for p in points:
        all_sources.update(p.get("detection_sources", []))

    if phase == "PLATEAU" and duration_min > 0:
        message = (
            f"Alerte IA : {base['type_anomalie']} sur {base['site']} — "
            f"pic détecté à {points[0]['date_heure'][11:16]}, "
            f"plateau stable depuis {duration_min} min "
            f"({round(peak_value, 1)} Mbps vs habituel "
            f"{round(base['trafic_habituel'], 1)} Mbps)."
        )
    else:
        message = base["message"]

    return {
        **base,
        "trafic_actuel": round(peak_value, 2),
        "phase_actuelle": phase,
        "duree_evenement_min": duration_min,
        "nb_points_anomalies": len(points),
        "detection_sources": sorted(all_sources),
        "message": message,
    }


def group_anomaly_events(anomalies, gap_minutes=90):
    """
    Group consecutive anomaly points into events.
    Points closer than gap_minutes are part of the same event.
    Returns one aggregated record per event instead of one per data point.
    """
    if not anomalies:
        return []

    sorted_pts = sorted(anomalies, key=lambda a: a["date_heure"])
    events = []
    bucket = [sorted_pts[0]]

    for i in range(1, len(sorted_pts)):
        try:
            t_cur = datetime.fromisoformat(sorted_pts[i]["date_heure"])
            t_prev = datetime.fromisoformat(bucket[-1]["date_heure"])
            gap = (t_cur - t_prev).total_seconds() / 60
        except Exception:
            gap = 0

        if gap <= gap_minutes:
            bucket.append(sorted_pts[i])
        else:
            ev = _aggregate_event(bucket)
            if ev:
                events.append(ev)
            bucket = [sorted_pts[i]]

    ev = _aggregate_event(bucket)
    if ev:
        events.append(ev)

    return events


# ─────────────────────────────────────────────
#  MODIFIED — Isolation Forest
#  Now saves / loads model per site (retrain every 7 days).
#  Eliminates redundant training on every API call.
# ─────────────────────────────────────────────

def compute_dynamic_contamination(occurrences: int, nb_points: int) -> float:
    """Estime le taux d'anomalies réel basé sur les occurrences connues du site."""
    if nb_points < 50 or occurrences == 0:
        return 0.05
    estimated_rate = occurrences / max(nb_points, 1)
    return max(0.01, min(0.15, estimated_rate))


def isolation_forest_flags(rows, contamination, site_key="default", capacity=0.0):
    if not SKLEARN_AVAILABLE or len(rows) < 40:
        return {}, {}

    features = build_ml_features(rows, capacity=capacity)
    os.makedirs(MODELS_DIR, exist_ok=True)
    safe_key = re.sub(r"[^A-Za-z0-9_-]", "_", site_key)
    model_path = os.path.join(MODELS_DIR, f"iforest_{safe_key}.pkl")

    predictions = None
    scores = None

    # Try loading a saved model (valid for 7 days)
    if os.path.exists(model_path) and joblib is not None:
        try:
            age_days = (datetime.now().timestamp() - os.path.getmtime(model_path)) / 86400
            if age_days <= 7:
                saved = joblib.load(model_path)
                scaler = saved["scaler"]
                model = saved["model"]
                scaled = scaler.transform(features)
                predictions = model.predict(scaled)
                scores = model.score_samples(scaled)
        except Exception:
            predictions = None

    # Train a new model if nothing was loaded
    if predictions is None:
        # Entraîner sur données propres : exclure les 3% valeurs extrêmes
        values_only = [f[0] for f in features]
        sorted_vals = sorted(values_only)
        q_low  = sorted_vals[max(0, int(len(sorted_vals) * 0.03))]
        q_high = sorted_vals[min(len(sorted_vals) - 1, int(len(sorted_vals) * 0.97))]
        clean_features = [f for f in features if q_low <= f[0] <= q_high]
        if len(clean_features) < 30:
            clean_features = features

        scaler = StandardScaler()
        scaler.fit(clean_features)
        scaled = scaler.transform(features)
        scaled_clean = scaler.transform(clean_features)

        model = IsolationForest(
            contamination=contamination,
            random_state=42,
            n_estimators=200,
            max_samples='auto',
            bootstrap=False,
        )
        model.fit(scaled_clean)
        predictions = model.predict(scaled)
        scores = model.score_samples(scaled)

        if joblib is not None:
            try:
                joblib.dump({"model": model, "scaler": scaler}, model_path)
            except Exception:
                pass

    flags = {}
    score_map = {}
    for idx, row in enumerate(rows):
        key = (row["date_heure"], row["site"])
        flags[key] = predictions[idx] == -1
        score_map[key] = float(scores[idx])

    return flags, score_map


# ─────────────────────────────────────────────
#  Classify & message helpers (unchanged)
# ─────────────────────────────────────────────

def classify_anomaly(current, previous, baseline, capacity, z_site, z_hour, var_prev, var_hist, dynamic_limit):
    if current <= 0 and baseline > 0:
        return "CHUTE_BRUTALE", "Coupure probable ou probleme de collecte", "URGENTE"

    if capacity > 0 and current >= capacity * 0.9:
        return "CONGESTION_IA", "Trafic tres proche de la capacite disponible", "URGENTE"

    if capacity > 0 and current >= capacity * 0.8:
        return "PRE_CONGESTION", "Trafic superieur a 80% de la capacite", "HAUTE"

    if var_prev > 0 and var_hist > 0 and (z_site >= 3 or z_hour >= 3):
        return "PIC_SOUDAIN", "Montee soudaine du trafic par rapport au comportement habituel", "HAUTE"

    if var_prev < 0 and var_hist < 0 and (z_site <= -3 or z_hour <= -3):
        return "CHUTE_BRUTALE", "Baisse soudaine du trafic par rapport au comportement habituel", "HAUTE"

    contextual_limit = max(15.0, dynamic_limit * 0.15)
    if current > baseline and var_hist >= contextual_limit:
        return "PIC_SOUDAIN", "Trafic superieur au profil historique du site", "HAUTE"

    if current < baseline and var_hist <= -contextual_limit:
        return "CHUTE_BRUTALE", "Trafic inferieur au profil historique du site", "HAUTE"

    if current > previous and current > baseline:
        return "PIC_SOUDAIN", "Hausse inhabituelle detectee par le modele IA", "MOYENNE"

    if current < previous and current < baseline:
        return "CHUTE_BRUTALE", "Baisse inhabituelle detectee par le modele IA", "MOYENNE"

    return "TRAFIC_INHABITUEL", "Comportement different de l'historique", "MOYENNE"


def make_message(anomaly_type, site, row, var_hist, cause):
    direction = "hausse" if var_hist >= 0 else "baisse"
    return (
        f"Alerte IA : {anomaly_type} detectee sur {site} a "
        f"{row['date_heure'].strftime('%H:%M')} - trafic anormal avec une "
        f"{direction} de {abs(round(var_hist, 2))}% par rapport a l'historique. "
        f"Cause probable : {cause}."
    )


# ─────────────────────────────────────────────
#  MODIFIED — Core detection function
#  Changes vs original:
#    1. STL residuals replace raw z-score (no plateau spam)
#    2. CUSUM added as 5th detection source
#    3. Isolation Forest model is loaded/saved, not retrained each call
#    4. Consecutive anomaly points are collapsed into events
# ─────────────────────────────────────────────

def detecter_anomalies_site(
    site,
    jours_historique=30,
    contamination=0.05,
    minutes_realtime=720,
    min_points_history=8,
):
    conn = get_connection()
    cur = conn.cursor(cursor_factory=RealDictCursor)

    latest_time = get_latest_time(cur)
    if latest_time is None:
        cur.close()
        conn.close()
        return []

    current_start = latest_time - timedelta(minutes=minutes_realtime)
    history_start = latest_time - timedelta(days=jours_historique)

    rows = load_site_rows(cur, site, history_start, latest_time)
    capacity_map = get_latest_capacity_map(cur)
    occurrences_map = get_occurrences_map(cur)

    cur.close()
    conn.close()

    if len(rows) < min_points_history + 2:
        return []

    clean_site = clean_site_name(site)
    capacity_info = capacity_map.get(clean_site, {})
    capacity = safe_float(capacity_info.get("capacite_mbps"))
    occurrences = occurrences_map.get(clean_site, 0)

    hist_site, hist_hour, hist_day_hour = group_history(rows, current_start)
    if len(hist_site) < min_points_history:
        return []

    # --- STL: residuals on the full series (history + current window) ---
    stl_residual_map = compute_stl_residuals(rows)
    hist_residuals = [
        v for ts, v in stl_residual_map.items()
        if ts < current_start
    ] if stl_residual_map else []
    use_stl = bool(stl_residual_map) and len(hist_residuals) >= 5

    # --- CUSUM: change-point timestamps restricted to current window ---
    all_cusum_times = cusum_changepoints(rows)
    cusum_flagged_times = {ts for ts in all_cusum_times if ts >= current_start}

    # --- Isolation Forest: contamination dynamique + capacité comme feature ---
    dynamic_contamination = compute_dynamic_contamination(occurrences, len(rows))
    if_flags, if_scores = isolation_forest_flags(rows, dynamic_contamination, clean_site, capacity=capacity)

    # --- Auto-detect typical measurement interval for event grouping ---
    agg_times, _ = _build_aggregated_series(rows)
    if len(agg_times) >= 2:
        intervals_min = [
            (agg_times[i] - agg_times[i - 1]).total_seconds() / 60
            for i in range(1, min(20, len(agg_times)))
            if (agg_times[i] - agg_times[i - 1]).total_seconds() > 0
        ]
        typical_interval = statistics.median(intervals_min) if intervals_min else 60
        event_gap = max(90, int(typical_interval * 2.5))
    else:
        event_gap = 90

    raw_anomalies = []
    previous_value = None

    for row in rows:
        current = safe_float(row["max_speed"])
        if previous_value is None:
            previous_value = current
            continue

        if row["date_heure"] < current_start:
            previous_value = current
            continue

        hour = int(row["hour"])
        day = int(row["day_of_week"])
        baseline_values = hist_day_hour.get((day, hour), [])
        if len(baseline_values) < min_points_history:
            baseline_values = hist_hour.get(hour, [])
        if len(baseline_values) < min_points_history:
            baseline_values = hist_site

        baseline = median(baseline_values)
        var_prev = percent_change(current, previous_value)
        var_hist = percent_change(current, baseline)
        dynamic_limit = historical_volatility(baseline_values, baseline)

        key = (row["date_heure"], row["site"])

        # Z-score: use STL residuals when available (seasonality + trend removed)
        # so a stable plateau at a new level produces near-zero residuals.
        if use_stl:
            current_residual = stl_residual_map.get(row["date_heure"], 0.0)
            z_site = robust_z_score(current_residual, hist_residuals)
            z_hour = z_site
        else:
            z_site = robust_z_score(current, hist_site)
            z_hour = robust_z_score(current, baseline_values)

        isolation_hit = if_flags.get(key, False)
        isolation_score = if_scores.get(key)
        cusum_hit = row["date_heure"] in cusum_flagged_times

        strong_dynamic_jump = (
            abs(var_prev) >= dynamic_limit
            and abs(var_hist) >= dynamic_limit * 0.75
            and abs(current - baseline) >= max(1.0, mad(baseline_values) * 2.0)
        )
        strong_statistical_shift = abs(z_site) >= 3.0 or abs(z_hour) >= 3.0
        capacity_risk = capacity > 0 and current >= capacity * 0.8

        if not (isolation_hit or strong_dynamic_jump or strong_statistical_shift
                or capacity_risk or cusum_hit):
            previous_value = current
            continue

        anomaly_type, cause, priority = classify_anomaly(
            current=current,
            previous=previous_value,
            baseline=baseline,
            capacity=capacity,
            z_site=z_site,
            z_hour=z_hour,
            var_prev=var_prev,
            var_hist=var_hist,
            dynamic_limit=dynamic_limit,
        )

        taux_utilisation = round((current / capacity) * 100, 2) if capacity > 0 else None

        # Score de confiance pondéré multi-signaux
        signal_scores = []
        z_max = max(abs(z_site), abs(z_hour))
        if z_max >= 2.0:
            signal_scores.append(min(99, z_max * 15))
        if isolation_hit and isolation_score is not None:
            if_conf = min(99, max(50, (0.5 + isolation_score) * -200 + 99))
            signal_scores.append(if_conf)
        elif isolation_hit:
            signal_scores.append(80)
        if cusum_hit:
            signal_scores.append(87)
        if capacity > 0:
            if current >= capacity:
                signal_scores.append(99)
            elif current >= capacity * 0.9:
                signal_scores.append(92)
            elif current >= capacity * 0.8:
                signal_scores.append(82)
        if abs(var_prev) >= dynamic_limit:
            signal_scores.append(min(95, abs(var_prev) / max(dynamic_limit, 1) * 60))
        if not signal_scores:
            confidence = 50
        elif len(signal_scores) == 1:
            confidence = round(signal_scores[0] * 0.85)
        else:
            base = sum(signal_scores) / len(signal_scores)
            multi_bonus = min(10, (len(signal_scores) - 1) * 4)
            confidence = round(min(99, base + multi_bonus))

        detection_sources = []
        if isolation_hit:
            detection_sources.append("isolation_forest")
        if strong_dynamic_jump:
            detection_sources.append("variation_dynamique")
        if strong_statistical_shift:
            detection_sources.append("stl_residus" if use_stl else "score_robuste")
        if capacity_risk:
            detection_sources.append("capacite")
        if cusum_hit:
            detection_sources.append("cusum")

        # Filtre qualité : priorité MOYENNE exige au moins 2 sources indépendantes
        if anomaly_type not in ("CONGESTION_IA",) and priority == "MOYENNE" and len(detection_sources) < 2:
            previous_value = current
            continue

        raw_anomalies.append({
            "site": clean_site,
            "site_source": row["site"],
            "date_heure": row["date_heure"].isoformat(),
            "trafic_actuel": round(current, 2),
            "trafic_precedent": round(previous_value, 2),
            "trafic_habituel": round(baseline, 2),
            "capacite_mbps": round(capacity, 2) if capacity > 0 else None,
            "taux_utilisation": taux_utilisation,
            # Legacy field names kept for PHP/template compatibility
            "variation_1h": round(var_prev, 2),
            "variation_24h": round(var_hist, 2),
            "variation_vs_precedent": round(var_prev, 2),
            "variation_vs_historique": round(var_hist, 2),
            "score_site": round(z_site, 2),
            "score_heure": round(z_hour, 2),
            "score_isolation_forest": round(isolation_score, 5) if isolation_score is not None else None,
            "seuil_dynamique_variation": round(dynamic_limit, 2),
            "detection_sources": detection_sources,
            "occurrences_recentes": occurrences,
            "classification": capacity_info.get("classification"),
            "type_trans": capacity_info.get("type_trans"),
            "type_anomalie": anomaly_type,
            "priorite": priority,
            "confiance": confidence,
            "cause_probable": cause,
            "message": make_message(anomaly_type, clean_site, row, var_hist, cause),
        })

        previous_value = current

    # Collapse consecutive anomaly points into one record per event
    events = group_anomaly_events(raw_anomalies, gap_minutes=event_gap)

    priority_order = {"URGENTE": 0, "HAUTE": 1, "MOYENNE": 2}
    events.sort(key=lambda item: (priority_order.get(item["priorite"], 3), -item["confiance"]))
    return events


# ─────────────────────────────────────────────
#  Public API functions (unchanged signatures)
# ─────────────────────────────────────────────

def run_detection_site(
    site,
    jours_historique=30,
    contamination=0.05,
    minutes_realtime=240,
):
    anomalies = detecter_anomalies_site(
        site=site,
        jours_historique=jours_historique,
        contamination=contamination,
        minutes_realtime=minutes_realtime,
    )
    return {
        "status": "success",
        "mode": "hybride_temps_reel",
        "sklearn_available": SKLEARN_AVAILABLE,
        "statsmodels_available": STATSMODELS_AVAILABLE,
        "site": clean_site_name(site),
        "minutes_realtime": minutes_realtime,
        "jours_historique": jours_historique,
        "total_anomalies": len(anomalies),
        "anomalies": anomalies,
    }


def detecter_variations_soutenues(limit_sites=200, recent_days=7, threshold_pct=20.0):
    """
    Détecte les sites dont le niveau de trafic moyen a changé de façon soutenue.
    Compare la moyenne des `recent_days` derniers jours à la moyenne des jours
    précédents (jours recent_days+1 à 30). Un pic isolé d'une heure n'affecte
    pas significativement une moyenne sur 7 jours.

    Retourne une liste d'anomalies au même format que detecter_anomalies_site().
    """
    conn = get_connection()
    cur = conn.cursor(cursor_factory=RealDictCursor)

    latest_time = get_latest_time(cur)
    if latest_time is None:
        cur.close()
        conn.close()
        return []

    recent_start   = latest_time - timedelta(days=recent_days)
    baseline_start = latest_time - timedelta(days=30)

    # Moyenne par site sur la période récente
    cur.execute("""
        SELECT
            REGEXP_REPLACE(UPPER(site),
                '_(LM2?|LMB|TF|TC|TD|TDD|FDD|TT|BH|BO|TR|OO|OR|L2B)(_[A-Z0-9]+)*$',
                '') AS clean,
            AVG(max_speed)  AS recent_avg,
            MAX(max_speed)  AS recent_max,
            COUNT(*)        AS nb_recent
        FROM trafic_historique
        WHERE date_heure > %s
          AND max_speed  > 0
        GROUP BY clean
        HAVING COUNT(*) >= 3
    """, (recent_start,))
    recent_rows = {r["clean"]: r for r in cur.fetchall()}

    # Moyenne par site sur la période de référence (avant la période récente)
    cur.execute("""
        SELECT
            REGEXP_REPLACE(UPPER(site),
                '_(LM2?|LMB|TF|TC|TD|TDD|FDD|TT|BH|BO|TR|OO|OR|L2B)(_[A-Z0-9]+)*$',
                '') AS clean,
            AVG(max_speed)  AS hist_avg,
            STDDEV(max_speed) AS hist_std
        FROM trafic_historique
        WHERE date_heure BETWEEN %s AND %s
          AND max_speed > 0
        GROUP BY clean
        HAVING COUNT(*) >= 10
    """, (baseline_start, recent_start))
    hist_rows = {r["clean"]: r for r in cur.fetchall()}

    capacity_map    = get_latest_capacity_map(cur)
    occurrences_map = get_occurrences_map(cur)
    cur.close()
    conn.close()

    anomalies = []
    for clean_site, recent in recent_rows.items():
        hist = hist_rows.get(clean_site)
        if not hist or safe_float(hist["hist_avg"]) < 1.0:
            continue

        recent_avg = safe_float(recent["recent_avg"])
        hist_avg   = safe_float(hist["hist_avg"])
        variation  = (recent_avg - hist_avg) / hist_avg * 100.0

        if abs(variation) < threshold_pct:
            continue

        capacity_info = capacity_map.get(clean_site, {})
        capacity      = safe_float(capacity_info.get("capacite_mbps"))
        occurrences   = occurrences_map.get(clean_site, 0)

        if variation > 0:
            anomaly_type = "HAUSSE_SOUTENUE"
            priority     = "URGENTE" if variation >= 50 else "HAUTE"
            cause        = f"Trafic moyen +{round(variation,1)}% sur {recent_days}j vs période précédente"
        else:
            anomaly_type = "CHUTE_SOUTENUE"
            priority     = "HAUTE" if abs(variation) >= 40 else "MOYENNE"
            cause        = f"Trafic moyen {round(variation,1)}% sur {recent_days}j vs période précédente"

        taux = round((recent_avg / capacity) * 100, 2) if capacity > 0 else None
        confidence = min(99, round(min(abs(variation) * 1.2, 99), 1))

        anomalies.append({
            "site":                    clean_site,
            "site_source":             clean_site,
            "date_heure":              latest_time.isoformat(),
            "trafic_actuel":           round(recent_avg, 2),
            "trafic_precedent":        round(hist_avg, 2),
            "trafic_habituel":         round(hist_avg, 2),
            "capacite_mbps":           round(capacity, 2) if capacity > 0 else None,
            "taux_utilisation":        taux,
            "variation_1h":            round(variation, 2),
            "variation_24h":           round(variation, 2),
            "variation_vs_precedent":  round(variation, 2),
            "variation_vs_historique": round(variation, 2),
            "score_site":              round(variation / max(safe_float(hist["hist_std"]), 1), 2),
            "score_heure":             0.0,
            "score_isolation_forest":  None,
            "seuil_dynamique_variation": threshold_pct,
            "detection_sources":       ["variation_soutenue_7j"],
            "occurrences_recentes":    occurrences,
            "classification":          capacity_info.get("classification"),
            "type_trans":              capacity_info.get("type_trans"),
            "type_anomalie":           anomaly_type,
            "priorite":                priority,
            "confiance":               confidence,
            "cause_probable":          cause,
            "nb_points_anomalies":     int(safe_float(recent["nb_recent"])),
            "phase_actuelle":          "PLATEAU",
            "duree_evenement_min":     recent_days * 24 * 60,
            "message": (
                f"Alerte IA : {anomaly_type} sur {clean_site} — "
                f"trafic moyen {round(recent_avg,1)} Mbps ({'+' if variation>0 else ''}"
                f"{round(variation,1)}% vs période précédente, habituel {round(hist_avg,1)} Mbps)."
            ),
        })

    anomalies.sort(key=lambda a: ({"URGENTE":0,"HAUTE":1,"MOYENNE":2}.get(a["priorite"],3), -a["confiance"]))
    return anomalies


def run_detection_tous_sites(
    limit_sites=50,
    jours_historique=30,
    contamination=0.05,
    inserer_alertes=False,
    minutes_realtime=720,
):
    conn = get_connection()
    cur = conn.cursor(cursor_factory=RealDictCursor)
    latest_time = get_latest_time(cur)
    if latest_time is None:
        cur.close()
        conn.close()
        return {
            "status": "error",
            "message": "Aucune donnee dans trafic_historique",
            "anomalies": [],
        }
    cur.close()
    conn.close()

    # Détection par variation soutenue sur 7 jours (principale)
    all_anomalies = detecter_variations_soutenues(
        limit_sites=limit_sites,
        recent_days=7,
        threshold_pct=20.0,
    )

    priority_order = {"URGENTE": 0, "HAUTE": 1, "MOYENNE": 2}
    all_anomalies.sort(key=lambda item: (priority_order.get(item["priorite"], 3), -item["confiance"]))

    result = {
        "status": "success",
        "mode": "variation_soutenue_7j",
        "sklearn_available": SKLEARN_AVAILABLE,
        "statsmodels_available": STATSMODELS_AVAILABLE,
        "date_detection": datetime.now().isoformat(),
        "total_sites_analyses": limit_sites,
        "total_anomalies": len(all_anomalies),
        "anomalies": all_anomalies,
    }

    if inserer_alertes and all_anomalies:
        result["alertes_inserees"] = inserer_alertes_anomalies(all_anomalies)
    else:
        result["alertes_inserees"] = 0

    return result


# ─────────────────────────────────────────────
#  MODIFIED — Alert insertion
#  Deduplication changed from "same calendar day"
#  to a 4-hour sliding window to allow a second
#  distinct event on the same day while still
#  blocking plateau spam.
# ─────────────────────────────────────────────

def inserer_alertes_anomalies(anomalies):
    if not anomalies:
        return 0

    conn = get_connection()
    cur = conn.cursor()
    inserted = 0

    for anomaly in anomalies:
        # La détection par variation_soutenue_7j garantit déjà la persistance
        # (moyenne sur 7 jours — un pic isolé n'affecte pas cette moyenne).
        # Aucun filtre supplémentaire nécessaire ici.

        etat = str(anomaly["type_anomalie"])[:20]

        cur.execute("""
            SELECT id
            FROM site_alert
            WHERE site = %s
              AND etat = %s
              AND date_alerte >= NOW() - INTERVAL '4 hours'
            LIMIT 1
        """, (anomaly["site"], etat))

        if cur.fetchone():
            continue

        cur.execute("""
            INSERT INTO site_alert (
                site,
                etat,
                trafic_j,
                capacite_mbps,
                taux_utilisation,
                variation_j1,
                variation_j7,
                type_trans,
                classification,
                message,
                date_alerte,
                alerte_lue,
                email_envoye
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), false, false)
        """, (
            anomaly["site"],
            etat,
            safe_float(anomaly["trafic_actuel"]),
            safe_float(anomaly.get("capacite_mbps")),
            safe_float(anomaly.get("taux_utilisation")),
            safe_float(anomaly.get("variation_vs_precedent", anomaly.get("variation_1h", 0))),
            safe_float(anomaly.get("variation_vs_historique", anomaly.get("variation_24h", 0))),
            "IA_ANOMALY",
            anomaly.get("classification") or "IA",
            anomaly["message"],
        ))
        inserted += 1

    conn.commit()
    cur.close()
    conn.close()
    return inserted


def run_detection_quotidienne():
    return run_detection_tous_sites(
        limit_sites=100,
        jours_historique=30,
        contamination=0.05,
        inserer_alertes=True,
        minutes_realtime=24 * 60,
    )


def get_anomalies_stats(jours=30):
    conn = get_connection()
    cur = conn.cursor(cursor_factory=RealDictCursor)
    cur.execute("""
        SELECT
            etat AS type,
            COUNT(*) AS count,
            DATE(date_alerte) AS date
        FROM site_alert
        WHERE (type_trans = 'IA_ANOMALY' OR classification = 'IA')
          AND date_alerte >= NOW() - (%s || ' days')::interval
        GROUP BY etat, DATE(date_alerte)
        ORDER BY date DESC, count DESC
    """, (jours,))
    rows = cur.fetchall()
    cur.close()
    conn.close()

    stats = [
        {
            "type": row["type"],
            "count": int(row["count"]),
            "date": str(row["date"]),
        }
        for row in rows
    ]

    return {
        "status": "success",
        "period": f"{jours} jours",
        "total_types": len({row["type"] for row in stats}),
        "stats": stats,
    }
