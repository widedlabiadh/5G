#!/usr/bin/env python3
"""
Script autonome pour entraîner le modèle IA avec split TRAIN/TEST
Exécuter: python train_prediction_model.py
"""

import sys
import json
from pathlib import Path

# Ajouter le dossier courant au path
sys.path.insert(0, str(Path(__file__).parent))

from ia_prediction import train_model, model_status

print("=" * 70)
print("🚀 ENTRAÎNEMENT DU MODÈLE IA - RANDOM FOREST")
print("=" * 70)
print()

# Vérifier l'état avant entraînement
print("📊 État avant entraînement:")
status = model_status()
print(f"   Modèle chargé: {status.get('model_loaded', False)}")
print(f"   Dépendances: {status.get('dependencies_ready', False)}")
print()

# Lancer l'entraînement
print("🔄 Lancement de l'entraînement avec split TRAIN/TEST (80/20)...")
print()

resultat = train_model(test_size=0.2, limit=None)

print()
print("=" * 70)
print("📈 RÉSULTAT DE L'ENTRAÎNEMENT")
print("=" * 70)

if resultat.get('status') == 'ok':
    print(f"\n✅ SUCCÈS!")
    print(f"\n📊 STATISTIQUES:")
    print(f"   • Total des données: {resultat.get('total_rows', 0)} lignes")
    print(f"   • TRAIN (80%): {resultat.get('train_rows', 0)} échantillons")
    print(f"   • TEST (20%): {resultat.get('test_rows', 0)} échantillons")
    print(f"\n🎯 PERFORMANCE SUR TEST (données jamais vues):")
    print(f"   • Accuracy: {resultat.get('accuracy_test', 0)*100:.2f}%")
    
    print(f"\n📋 Précision par classe:")
    for classe, metrics in resultat.get('classification_report', {}).items():
        if isinstance(metrics, dict) and 'precision' in metrics:
            print(f"   • {classe:20s}: précision={metrics['precision']*100:.1f}%, rappel={metrics['recall']*100:.1f}%")
    
    print(f"\n📊 IMPORTANCE DES CARACTÉRISTIQUES (Top 5):")
    importances = resultat.get('feature_importance', {})
    for i, (feature, importance) in enumerate(sorted(importances.items(), key=lambda x: -x[1])[:5]):
        barre = '█' * int(importance * 50)
        print(f"   {i+1}. {feature:30s}: {importance:.4f} {barre}")
    
    print(f"\n💾 Modèle sauvegardé dans: api_python/models/")
    print(f"   - site_status_model.pkl")
    print(f"   - site_status_scaler.pkl")
    print(f"   - site_status_metadata.json")
    
else:
    print(f"\n❌ ERREUR: {resultat.get('message', 'Inconnue')}")

print()
print("=" * 70)
print("🚀 Pour lancer l'API: python main.py")
print("=" * 70)