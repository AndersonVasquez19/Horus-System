"""Arranque con validación de host:  python -m app"""

import uvicorn

from app.core.config import get_settings

if __name__ == "__main__":
    settings = get_settings()  # rechaza hosts no loopback
    uvicorn.run("app.main:app", host=settings.host, port=settings.port, workers=1, proxy_headers=False)
