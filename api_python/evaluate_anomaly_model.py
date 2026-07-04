# api_python/evaluate_anomaly_model.py
"""
Évaluation complète du modèle Isolation Forest sur TOUS les sites.
Lance avec : python evaluate_anomaly_model.py
"""

import argparse
import os
import time
from datetime import timedelta

try:
    from sklearn.metrics import (
        accuracy_score, precision_score, recall_score, f1_score,
        roc_auc_score, confusion_matrix, classification_report,
    )
    from sklearn.ensemble import IsolationForest
    from sklearn.preprocessing import StandardScaler
    SKLEARN_OK = True
except ImportError:
    SKLEARN_OK = False
    print("[WARN] scikit-learn non disponible — métriques limitées.")

from anomaly_detection import (
    get_connection, clean_site_name, safe_float,
    build_ml_features, get_latest_capacity_map,
    get_occurrences_map, load_site_rows, get_latest_time,
    compute_dynamic_contamination,
)

from psycopg2.extras import RealDictCursor


# ─── 1. Labels depuis analyse_resultat (TOUS les sites) ──────────────────────

def get_ground_truth_labels(conn):
    cur = conn.cursor(cursor_factory=RealDictCursor)
    cur.execute("""
        SELECT DISTINCT ON (UPPER(site))
            UPPER(site)          AS site,
            etat,
            taux_utilisation,
            capacite_mbps,
            date_calcul
        FROM site_etat
        WHERE etat IS NOT NULL
          AND etat != ''
        ORDER BY UPPER(site), date_calcul DESC, id DESC
    """)
    rows = cur.fetchall()
    cur.close()

    labels = {}
    for row in rows:
        site = clean_site_name(row["site"])
        etat = str(row["etat"]).upper()
        labels[site] = {
            "label": 0 if etat == "OK" else 1,
            "etat": etat,
            "taux": safe_float(row["taux_utilisation"]),
            "capacite": safe_float(row["capacite_mbps"]),
        }
    return labels


# ─── 2. Liste de TOUS les sites actifs ───────────────────────────────────────

def get_all_active_sites(cur, history_start):
    cur.execute("""
        SELECT
            REGEXP_REPLACE(
                UPPER(site),
                '_(LM2?|LMB|TF|TC|TD|TDD|FDD|TT|BH|BO|TR|OO|OR|L2B)(_[A-Z0-9]+)*$',
                '', 'i'
            ) AS clean_site,
            COUNT(*) AS nb
        FROM trafic_historique
        WHERE date_heure >= %s
        GROUP BY clean_site
        HAVING COUNT(*) >= 40
        ORDER BY nb DESC
    """, (history_start,))
    return [r["clean_site"] for r in cur.fetchall() if r["clean_site"]]


# ─── 3. Évaluation principale ────────────────────────────────────────────────

def evaluate_isolation_forest(jours=30, limit_sites=None):
    print("\n" + "=" * 60)
    print("  ÉVALUATION ISOLATION FOREST — TOUS LES SITES")
    print("=" * 60)

    conn = get_connection()
    cur = conn.cursor(cursor_factory=RealDictCursor)

    latest_time = get_latest_time(cur)
    if not latest_time:
        print("[ERREUR] Aucune donnée dans trafic_historique.")
        conn.close()
        return

    history_start  = latest_time - timedelta(days=jours)
    capacity_map   = get_latest_capacity_map(cur)
    occurrences_map = get_occurrences_map(cur)
    ground_truth   = get_ground_truth_labels(conn)
    active_sites   = get_all_active_sites(cur, history_start)

    cur.close()
    conn.close()

    print(f"  Sites avec données suffisantes : {len(active_sites)}")
    print(f"  Sites avec labels (etat)       : {len(ground_truth)}")
    evaluables = [s for s in active_sites if s in ground_truth]
    if limit_sites:
        evaluables = evaluables[:limit_sites]
    print(f"  Sites évaluables (intersection): {len(evaluables)}")
    print(f"  Période historique             : {jours} jours\n")

    y_true, y_pred, scores_list = [], [], []
    results_detail = []
    errors = []

    t0 = time.time()
    for idx, site in enumerate(evaluables, 1):
        if idx % 20 == 0 or idx == 1:
            elapsed = round(time.time() - t0, 1)
            print(f"  [{idx}/{len(evaluables)}] en cours... ({elapsed}s écoulées)")

        try:
            conn2 = get_connection()
            cur2  = conn2.cursor(cursor_factory=RealDictCursor)
            rows  = load_site_rows(cur2, site, history_start, latest_time)
            cur2.close()
            conn2.close()

            if len(rows) < 40:
                continue

            capacity_info  = capacity_map.get(site, {})
            capacity       = safe_float(capacity_info.get("capacite_mbps"))
            occurrences    = occurrences_map.get(site, 0)
            contamination  = compute_dynamic_contamination(occurrences, len(rows))

            features = build_ml_features(rows, capacity=capacity)

            # Entraîner sur données propres (3% extrêmes exclus du fit)
            values_only  = [f[0] for f in features]
            sorted_vals  = sorted(values_only)
            q_low        = sorted_vals[max(0, int(len(sorted_vals) * 0.03))]
            q_high       = sorted_vals[min(len(sorted_vals) - 1, int(len(sorted_vals) * 0.97))]
            clean_feats  = [f for f in features if q_low <= f[0] <= q_high] or features

            scaler       = StandardScaler()
            scaler.fit(clean_feats)
            scaled       = scaler.transform(features)
            scaled_clean = scaler.transform(clean_feats)

            model = IsolationForest(
                contamination=contamination,
                n_estimators=200,
                random_state=42,
                bootstrap=False,
            )
            model.fit(scaled_clean)

            preds        = model.predict(scaled)
            site_scores  = model.score_samples(scaled)

            # Un site est prédit anomalie si ≥1 point est flaggé -1
            if_says_anomaly = int(any(p == -1 for p in preds))
            min_score       = float(min(site_scores))

            true_label = ground_truth[site]["label"]
            y_true.append(true_label)
            y_pred.append(if_says_anomaly)
            scores_list.append(-min_score)

            results_detail.append({
                "site":         site,
                "etat_reel":    ground_truth[site]["etat"],
                "label_reel":   true_label,
                "if_predit":    if_says_anomaly,
                "correct":      true_label == if_says_anomaly,
                "taux":         ground_truth[site]["taux"],
                "nb_points":    len(rows),
                "contamination": round(contamination, 3),
                "min_score":    round(min_score, 4),
            })

        except Exception as exc:
            errors.append({"site": site, "erreur": str(exc)})

    # ─── 4. Métriques ────────────────────────────────────────────────────────
    total_time = round(time.time() - t0, 1)
    n = len(y_true)

    print("\n" + "=" * 60)
    print("  RÉSULTATS")
    print("=" * 60)
    print(f"  Sites évalués       : {n}  (temps : {total_time}s)")
    print(f"  Erreurs ignorées    : {len(errors)}")

    if n == 0:
        print("[ERREUR] Aucun site évaluable.")
        return

    print(f"  Anomalies réelles   : {sum(y_true)} ({round(sum(y_true)/n*100,1)}%)")
    print(f"  Anomalies prédites  : {sum(y_pred)} ({round(sum(y_pred)/n*100,1)}%)")
    print()

    correct = sum(1 for r in results_detail if r["correct"])

    if SKLEARN_OK and len(set(y_true)) > 1:
        accuracy  = accuracy_score(y_true, y_pred)
        precision = precision_score(y_true, y_pred, zero_division=0)
        recall    = recall_score(y_true, y_pred, zero_division=0)
        f1        = f1_score(y_true, y_pred, zero_division=0)
        auc       = roc_auc_score(y_true, scores_list)
        cm        = confusion_matrix(y_true, y_pred)
        tn, fp, fn, tp = cm.ravel()

        print(f"  Accuracy simple     : {round(accuracy*100, 1)}%  ({correct}/{n})")
        print(f"  Precision           : {round(precision*100, 1)}%")
        print(f"  Recall              : {round(recall*100, 1)}%")
        print(f"  F1-Score            : {round(f1*100, 1)}%")
        print(f"  AUC-ROC             : {round(auc, 4)}")
        print()
        print("  Matrice de confusion :")
        print(f"    TN (OK bien classé)    = {tn}")
        print(f"    FP (fausse alarme)     = {fp}")
        print(f"    FN (anomalie manquée)  = {fn}  ← critique")
        print(f"    TP (anomalie détectée) = {tp}")
        print()
        print(classification_report(
            y_true, y_pred,
            target_names=["Normal (OK)", "Anomalie"],
        ))
    else:
        print(f"  Accuracy simple     : {round(correct/n*100, 1)}%  ({correct}/{n})")

    # ─── 5. Détail erreurs ────────────────────────────────────────────────────
    if errors:
        print(f"\n  Sites en erreur ({len(errors)}) :")
        for e in errors[:10]:
            print(f"    {e['site']}: {e['erreur'][:80]}")

    # ─── 6. Faux positifs et faux négatifs ───────────────────────────────────
    fps = [r for r in results_detail if not r["correct"] and r["if_predit"] == 1]
    fns = [r for r in results_detail if not r["correct"] and r["if_predit"] == 0]

    if fps:
        print(f"\n  Fausses alarmes (FP) — {len(fps)} sites :")
        for r in fps[:15]:
            print(f"    {r['site']:<25} taux={r['taux']}%  score_IF={r['min_score']}")

    if fns:
        print(f"\n  Anomalies manquées (FN) — {len(fns)} sites :")
        for r in fns[:15]:
            print(f"    {r['site']:<25} etat={r['etat_reel']:<15} taux={r['taux']}%")

    print("\n" + "=" * 60)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Evaluer la detection d'anomalies Isolation Forest.")
    parser.add_argument("--jours", type=int, default=30, help="Nombre de jours d'historique a utiliser.")
    parser.add_argument("--limit-sites", type=int, default=None, help="Limiter le nombre de sites evalues.")
    args = parser.parse_args()

    evaluate_isolation_forest(jours=args.jours, limit_sites=args.limit_sites)
