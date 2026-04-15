# main.py
from fastapi import FastAPI, UploadFile, File
from fastapi.middleware.cors import CORSMiddleware
from traitement import traiter_fichiers
import uvicorn

app = FastAPI(title="Orange 5G - Plan Data API", version="1.0.0")

# Configuration CORS pour permettre à Symfony d'appeler l'API
app.add_middleware(
    CORSMiddleware,
    allow_origins=["http://localhost:8000", "http://127.0.0.1:8000", "http://localhost:8001", "http://127.0.0.1:8001"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/")
async def root():
    return {"message": "API Orange 5G - Plan Data", "status": "OK"}

@app.get("/health")
async def health():
    return {"status": "ok", "service": "FastAPI"}

@app.post("/traiter")
async def traiter(
    trafic: UploadFile = File(...),
    port: UploadFile = File(...),
    type_liaison: UploadFile = File(...)
):
    """Endpoint principal pour le traitement des fichiers Excel"""
    trafic_content = await trafic.read()
    port_content = await port.read()
    type_content = await type_liaison.read()
    
    resultats = traiter_fichiers(trafic_content, port_content, type_content)
    
    return resultats

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)