# api_python/main.py
import traceback
from fastapi import FastAPI, UploadFile, File, Query, Request
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel
from typing import Any, Dict, List, Optional
from traitement import traiter_fichiers
from capacite import traiter_capacite
from dropcong import traiter_dropcong
from anomaly_detection import (
    run_detection_site,
    run_detection_tous_sites,
    run_detection_quotidienne,
    get_anomalies_stats
)
from ia_prediction import (
    latest_sites,
    model_status,
    prediction_history,
    predict_site,
    predict_sites,
    train_model,
    get_model_comparison,
    _to_serializable,
)
import uvicorn
import os
from datetime import datetime

# ⚠️ IMPORTANT : Créer l'application FastAPI AVANT d'ajouter les routes
app = FastAPI(title="Orange 5G - Plan Data API", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Handler global : PHP utilise fopen/stream_wrapper qui échoue sur tout non-2xx.
# On force HTTP 200 même pour les erreurs de validation Pydantic (422) et les 500.
@app.exception_handler(RequestValidationError)
async def validation_error_handler(request: Request, exc: RequestValidationError):
    return JSONResponse(status_code=200, content={"status": "error", "message": str(exc)})

@app.exception_handler(Exception)
async def global_error_handler(request: Request, exc: Exception):
    return JSONResponse(status_code=200, content={"status": "error", "message": str(exc), "detail": traceback.format_exc()})


class PredictionBatchRequest(BaseModel):
    sites: List[Dict[str, Any]]
    persist: bool = True


class PredictionTrainRequest(BaseModel):
    test_size: float = 0.2
    limit: Optional[int] = None

# ========== ROUTES EXISTANTES ==========

@app.get("/")
async def root():
    return {"message": "API Orange 5G - Plan Data", "status": "OK"}

@app.get("/health")
async def health():
    return {"status": "ok", "timestamp": datetime.now().isoformat()}

@app.post("/traiter")
async def traiter(
    trafic: UploadFile = File(...),
    port: Optional[UploadFile] = File(None),
    type_liaison: Optional[UploadFile] = File(None),
    gps: Optional[UploadFile] = File(None),
):
    try:
        trafic_content = await trafic.read() if trafic else None
        port_content = await port.read() if port else None
        type_content = await type_liaison.read() if type_liaison else None
        gps_content = await gps.read() if gps else None
        
        result = traiter_fichiers(trafic_content, port_content, type_content, gps_content)
        
        return JSONResponse(content=result)
    
    except Exception as e:
        import traceback
        return JSONResponse(
            status_code=500,
            content={"status": "error", "message": str(e), "detail": traceback.format_exc()}
        )

@app.post("/capacite/fo")
async def importer_capacite_fo(fichier: UploadFile = File(...)):
    content = await fichier.read()
    return traiter_capacite(content)

@app.post("/capacite/fh")
async def importer_capacite_fh(fichier: UploadFile = File(...)):
    content = await fichier.read()
    return traiter_capacite(content)

@app.post("/capacite/backbone")
async def importer_capacite_backbone(fichier: UploadFile = File(...)):
    content = await fichier.read()
    return traiter_capacite(content)

@app.post("/dropcong")
async def importer_dropcong(
    fichier: Optional[UploadFile] = File(None),
    drop1: Optional[UploadFile] = File(None),
    drop2: Optional[UploadFile] = File(None),
):
    contents = []
    for uploaded in (fichier, drop1, drop2):
        if uploaded:
            contents.append(await uploaded.read())
    return traiter_dropcong(*contents)

# ========== NOUVELLES ROUTES IA ==========

@app.get("/ia/anomalies/site/{site}")
async def ia_anomalies_site(
    site: str,
    jours_historique: int = Query(30, ge=7, le=90),
    contamination: float = Query(0.05, ge=0.01, le=0.2),
    minutes_realtime: int = Query(240, ge=30, le=10080)
):
    try:
        result = run_detection_site(site, jours_historique, contamination, minutes_realtime)
        return JSONResponse(content=result)
    except Exception as e:
        return JSONResponse(
            status_code=500,
            content={"status": "error", "message": str(e)}
        )


@app.get("/ia/anomalies/tous")
async def ia_anomalies_tous(
    limit_sites: int = Query(50, ge=1, le=200),
    jours_historique: int = Query(30, ge=7, le=90),
    contamination: float = Query(0.05, ge=0.01, le=0.2),
    inserer_alertes: bool = Query(False),
    minutes_realtime: int = Query(240, ge=30, le=10080)
):
    try:
        result = run_detection_tous_sites(
            limit_sites,
            jours_historique,
            contamination,
            inserer_alertes,
            minutes_realtime
        )
        return JSONResponse(content=result)
    except Exception as e:
        return JSONResponse(
            status_code=500,
            content={"status": "error", "message": str(e)}
        )


@app.post("/ia/anomalies/quotidien")
async def ia_anomalies_quotidiennes():
    try:
        result = run_detection_quotidienne()
        return JSONResponse(content=result)
    except Exception as e:
        return JSONResponse(
            status_code=500,
            content={"status": "error", "message": str(e)}
        )


@app.get("/ia/anomalies/stats")
async def ia_anomalies_stats(
    jours: int = Query(30, ge=1, le=365)
):
    try:
        result = get_anomalies_stats(jours)
        return JSONResponse(content=result)
    except Exception as e:
        return JSONResponse(
            status_code=500,
            content={"status": "error", "message": str(e)}
        )


@app.get("/ia/health")
async def ia_health():
    return JSONResponse(content={
        "status": "ok",
        "message": "Module IA operationnel. Isolation Forest est utilise si scikit-learn est installe, sinon fallback statistique dynamique.",
    })


# ========== ROUTES IA PREDICTION TRAIN/TEST ==========

@app.get("/ia/prediction/health")
async def ia_prediction_health():
    try:
        return JSONResponse(content=model_status())
    except Exception as e:
        return JSONResponse(status_code=500, content={"status": "error", "message": str(e)})


@app.post("/ia/prediction/train")
async def ia_prediction_train(request: Request):
    # Lecture manuelle du body : évite la validation Pydantic (422) qui fait échouer fopen PHP
    try:
        body = {}
        try:
            body = await request.json()
        except Exception:
            pass
        result = train_model()
        return JSONResponse(status_code=200, content=_to_serializable(result))
    except Exception as e:
        return JSONResponse(status_code=200, content={"status": "error", "message": str(e), "detail": traceback.format_exc()})


@app.get("/ia/prediction/sites")
async def ia_prediction_sites(limit: int = Query(100, ge=1, le=500)):
    try:
        return JSONResponse(content={"sites": latest_sites(limit)})
    except Exception as e:
        return JSONResponse(status_code=200, content={"status": "error", "message": str(e)})


@app.post("/ia/prediction/site")
async def ia_prediction_site(payload: Dict[str, Any]):
    try:
        return JSONResponse(content=predict_site(payload, persist=True))
    except Exception as e:
        return JSONResponse(status_code=200, content={"status": "error", "message": str(e)})


@app.post("/ia/prediction/batch")
async def ia_prediction_batch(payload: PredictionBatchRequest):
    try:
        predictions = predict_sites(payload.sites, persist=payload.persist)
        return JSONResponse(content={"total": len(predictions), "predictions": predictions})
    except Exception as e:
        return JSONResponse(status_code=200, content={"status": "error", "sites": [], "message": str(e)})


@app.get("/ia/prediction/history")
async def ia_prediction_history(limit: int = Query(100, ge=1, le=500)):
    try:
        return JSONResponse(content={"predictions": prediction_history(limit)})
    except Exception as e:
        return JSONResponse(status_code=200, content={"predictions": [], "message": str(e)})


@app.get("/ia/prediction/compare")
async def ia_prediction_compare():
    """Retourne la comparaison des 3 modèles ML (accuracy, F1, matrices de confusion)."""
    try:
        return JSONResponse(content=get_model_comparison())
    except Exception as e:
        return JSONResponse(status_code=500, content={"status": "error", "message": str(e)})


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)
