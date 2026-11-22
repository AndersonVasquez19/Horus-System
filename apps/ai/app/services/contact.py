"""Canales públicos de Horus Group, leídos de `public_contact` en data/knowledge.json (fuente única).

Laravel valida los enlaces de WhatsApp y los canales contra sus propias constantes; su prueba
ContactoPublicoUnicoTest exige que coincidan con este mismo `public_contact`.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from urllib.parse import quote

DEFAULT_PUBLIC_CONTACT = {"phone": "+51 972 021 374", "email": "horusgroupcajamarca@gmail.com", "contact_page": "/contactos"}


@dataclass(frozen=True)
class PublicContact:
    phone: str
    email: str
    page: str = "/contactos"

    @classmethod
    def from_mapping(cls, data: dict[str, str] | None) -> PublicContact:
        merged = {**DEFAULT_PUBLIC_CONTACT, **(data or {})}
        return cls(phone=merged["phone"], email=merged["email"], page=merged.get("contact_page") or "/contactos")

    @property
    def whatsapp_url(self) -> str:
        return f"https://wa.me/{re.sub(r'[^0-9]', '', self.phone)}"

    def _whatsapp(self, text: str) -> str:
        return f"{self.whatsapp_url}?text={quote(text, safe='')}"

    @property
    def quote_url(self) -> str:
        return self._whatsapp("Hola, deseo solicitar una cotización")

    @property
    def enrollment_url(self) -> str:
        return self._whatsapp("Hola, deseo consultar sobre la matrícula")

    @property
    def confirm_line(self) -> str:
        """Cierre de las respuestas sobre datos que el catálogo no publica."""
        return f"Para confirmar fechas, precios y cupos, escribe al {self.phone} o a {self.email}."

    @property
    def advisor_line(self) -> str:
        """Siguiente paso cuando el asistente no puede confirmar un dato.

        Sin «teléfono» ni «correo» junto a un verbo como «confirmar»: Laravel lee esa combinación como una petición
        de datos personales al visitante y rechaza la respuesta (contenidoPermitido).
        """
        return f"Si necesitas ese dato, un asesor puede ayudarte por WhatsApp al {self.phone} o en {self.email}."
