#!/usr/bin/env python3
"""
Genera el CV en PDF y DOCX a partir del markdown.

El bloque de contacto (teléfono y correo) NO vive en el markdown, porque el
repositorio es público: se lee de datos-contacto.md, que está en .gitignore, y
se inyecta solo en los ficheros generados.

    python3 cv/generar.py

Salida en cv/build/ (también ignorado por git).
"""
from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.shared import Pt, RGBColor, Cm

BASE = Path(__file__).parent
BUILD = BASE / "build"

ACENTO = RGBColor(0x1A, 0x4B, 0x8C)
GRIS = RGBColor(0x55, 0x55, 0x55)


def leer_contacto() -> list[str]:
    """Líneas del bloque de contacto, sin los comentarios ni el nombre."""
    fichero = BASE / "datos-contacto.md"
    if not fichero.exists():
        sys.exit(f"Falta {fichero}. Sin él no se puede inyectar el contacto.")
    texto = re.sub(r"<!--.*?-->", "", fichero.read_text(encoding="utf8"), flags=re.S)
    lineas = [l.strip() for l in texto.splitlines() if l.strip()]
    return [l.strip("*") for l in lineas if not l.startswith("**")]


# --------------------------------------------------------------------------
#  Markdown -> estructura intermedia
# --------------------------------------------------------------------------
def parsear(md: str) -> dict:
    md = re.sub(r"<!--.*?-->", "", md, flags=re.S)
    lineas = md.splitlines()

    doc: dict = {"nombre": "", "titular": "", "ubicacion": "", "secciones": []}
    seccion = None
    # Bloque abierto: (tipo, [líneas]). En markdown, tanto los párrafos como
    # las viñetas pueden ocupar varias líneas; las de continuación no llevan
    # marca, así que hay que acumularlas en el bloque que esté abierto en vez
    # de tratarlas como bloque nuevo.
    abierto: tuple[str, list[str]] | None = None

    def cerrar():
        nonlocal abierto
        if abierto and seccion is not None:
            tipo, partes = abierto
            seccion["bloques"].append((tipo, " ".join(partes)))
        abierto = None

    for linea in lineas:
        s = linea.strip()
        if s.startswith("# "):
            cerrar()
            doc["nombre"] = s[2:].strip()
        elif s.startswith("## "):
            cerrar()
            seccion = {"titulo": s[3:].strip(), "bloques": []}
            doc["secciones"].append(seccion)
        elif s.startswith("### "):
            cerrar()
            # Siempre de una línea: lo que venga debajo (fecha, descripción)
            # es un bloque nuevo, no continuación del título.
            seccion["bloques"].append(("h3", s[4:].strip()))
        elif s.startswith("- "):
            cerrar()
            abierto = ("li", [s[2:].strip()])
        elif not s:
            cerrar()
        elif seccion is None:
            # Cabecera: titular y luego ubicación
            if not doc["titular"]:
                doc["titular"] = s
            else:
                doc["ubicacion"] = s
        elif abierto is not None:
            abierto[1].append(s)          # continuación del bloque en curso
        else:
            abierto = ("p", [s])

    cerrar()
    return doc


def limpiar(texto: str) -> str:
    """Quita enlaces markdown y la flecha de 'Ficha', que no aplican en papel."""
    texto = re.sub(r"\s*→\s*\[.*?\]\(.*?\)", "", texto)
    texto = re.sub(r"\[([^\]]+)\]\([^)]*\)", r"\1", texto)
    return texto.strip()


def trozos(texto: str):
    """Parte en (texto, negrita) respetando ** ** y ` `."""
    for parte in re.split(r"(\*\*[^*]+\*\*|`[^`]+`)", texto):
        if not parte:
            continue
        if parte.startswith("**"):
            yield parte[2:-2], True
        elif parte.startswith("`"):
            yield parte[1:-1], False
        else:
            yield parte, False


# --------------------------------------------------------------------------
#  DOCX
# --------------------------------------------------------------------------
W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"


def subrayar(parrafo):
    """Línea inferior en el título de sección.

    El esquema de OOXML fija el orden de los hijos de <w:pPr> y exige que
    <w:pBdr> vaya antes de <w:spacing>. Si se añade al final, Word y
    LibreOffice rechazan el documento entero, así que se inserta al principio
    (estos párrafos no llevan pStyle ni numPr, que serían los únicos que
    deberían precederlo).
    """
    pr = parrafo._p.get_or_add_pPr()
    borde = pr.makeelement(f"{W}pBdr", {})
    borde.append(
        borde.makeelement(
            f"{W}bottom",
            {f"{W}val": "single", f"{W}sz": "6", f"{W}color": "1A4B8C"},
        )
    )
    # <w:pStyle> es siempre el primer hijo; el borde va justo detrás.
    pos = 1 if pr.find(f"{W}pStyle") is not None else 0
    pr.insert(pos, borde)


def construir_docx(doc: dict, contacto: list[str], destino: Path):
    d = Document()
    for s in d.sections:
        s.top_margin = s.bottom_margin = Cm(1.6)
        s.left_margin = s.right_margin = Cm(1.8)

    normal = d.styles["Normal"]
    normal.font.name = "Calibri"
    normal.font.size = Pt(10)
    normal.paragraph_format.space_after = Pt(4)

    # Los estilos de encabezado de la plantilla traen Calibri Light y azul
    # propios. Se normalizan para que el documento use una sola fuente: el
    # estilo sigue llamándose Heading, que es lo que lee el ATS, pero se ve
    # como el resto del CV.
    for nombre in ("Title", "Heading 1", "Heading 2"):
        estilo = d.styles[nombre]
        estilo.font.name = "Calibri"
        estilo.font.color.rgb = RGBColor(0, 0, 0)
        estilo.paragraph_format.keep_with_next = True

    # El nombre va con estilo Title y las secciones con Heading: los ATS
    # delimitan las secciones por el estilo del párrafo, no por su aspecto.
    # Con todo en Normal, el CV se lee como un único bloque de texto.
    p = d.add_paragraph(style="Title")
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = p.add_run(doc["nombre"])
    r.bold = True
    r.font.size = Pt(20)
    r.font.color.rgb = ACENTO
    p.paragraph_format.space_after = Pt(2)

    p = d.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = p.add_run(doc["titular"])
    r.font.size = Pt(11)
    r.font.color.rgb = GRIS
    p.paragraph_format.space_after = Pt(2)

    # Contacto separado por barras: es lo que mejor reconocen los parsers al
    # trocear ubicación, teléfono, correo y perfil.
    p = d.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = p.add_run(" | ".join(contacto))
    r.font.size = Pt(9)
    p.paragraph_format.space_after = Pt(10)

    for seccion in doc["secciones"]:
        p = d.add_paragraph(style="Heading 1")
        p.paragraph_format.space_before = Pt(10)
        p.paragraph_format.space_after = Pt(3)
        r = p.add_run(seccion["titulo"])
        r.bold = True
        r.font.size = Pt(12)
        r.font.color.rgb = ACENTO
        subrayar(p)

        for tipo, texto in seccion["bloques"]:
            texto = limpiar(texto)
            if tipo == "h3":
                p = d.add_paragraph(style="Heading 2")
                p.paragraph_format.space_before = Pt(6)
                p.paragraph_format.space_after = Pt(1)
                for t, neg in trozos(texto):
                    r = p.add_run(t)
                    r.bold = True
                    r.font.size = Pt(10.5)
                    r.font.color.rgb = RGBColor(0, 0, 0)
            elif tipo == "li":
                p = d.add_paragraph(style="List Bullet")
                # Sangría francesa: sin esto, la segunda línea de una viñeta
                # larga vuelve al margen y rompe la alineación de la lista.
                p.paragraph_format.left_indent = Cm(0.65)
                p.paragraph_format.first_line_indent = Cm(-0.4)
                p.paragraph_format.space_after = Pt(3)
                for t, neg in trozos(texto):
                    r = p.add_run(t)
                    r.bold = neg
            else:
                p = d.add_paragraph()
                for t, neg in trozos(texto):
                    r = p.add_run(t)
                    r.bold = neg

    d.save(destino)


# --------------------------------------------------------------------------
#  PDF (vía LibreOffice)
# --------------------------------------------------------------------------
def construir_pdf(docx: Path) -> Path:
    """soffice devuelve 0 aunque falle, así que hay que comprobar la salida."""
    pdf = docx.with_suffix(".pdf")
    pdf.unlink(missing_ok=True)
    res = subprocess.run(
        ["soffice", "--headless", "--convert-to", "pdf",
         "--outdir", str(docx.parent), str(docx)],
        capture_output=True, text=True, timeout=180,
    )
    if not pdf.exists():
        sys.exit(
            f"LibreOffice no generó {pdf.name}.\n"
            f"  stdout: {res.stdout.strip()}\n  stderr: {res.stderr.strip()}"
        )
    return pdf


# --------------------------------------------------------------------------
#  Comprobación de compatibilidad ATS
# --------------------------------------------------------------------------
def verificar_ats(docx: Path, pdf: Path) -> list[str]:
    """Devuelve la lista de problemas encontrados. Vacía = compatible.

    Los ATS trocean el CV por el estilo de los párrafos y extraen el texto
    en plano. Lo que los rompe: maquetación en tablas, cuadros de texto,
    datos en el encabezado de página, texto dentro de imágenes, y no usar
    estilos de encabezado (entonces no distinguen dónde empieza cada sección).
    """
    from docx import Document

    fallos = []
    d = Document(docx)

    estilos = {p.style.name for p in d.paragraphs}
    if not any(e.startswith("Heading") for e in estilos):
        fallos.append("sin estilos de encabezado: el ATS no delimita las secciones")
    if d.tables:
        fallos.append(f"{len(d.tables)} tabla(s): la maquetación en tablas se lee desordenada")
    if d.inline_shapes:
        fallos.append(f"{len(d.inline_shapes)} imagen(es): el texto en imágenes no se extrae")
    if "txbxContent" in d.element.xml:
        fallos.append("cuadros de texto: su contenido suele perderse")
    if any(p.text.strip() for s in d.sections for p in s.header.paragraphs):
        fallos.append("datos en el encabezado de página: muchos parsers lo ignoran")

    texto = subprocess.run(
        ["pdftotext", str(pdf), "-"], capture_output=True, text=True
    ).stdout
    if len(texto.strip()) < 500:
        fallos.append("el PDF no expone texto seleccionable")
    if not re.search(r"[\w.+-]+@[\w-]+\.\w+", texto):
        fallos.append("no se detecta el correo en el texto extraído")
    if not re.search(r"\+?\d[\d\s()-]{7,}", texto):
        fallos.append("no se detecta el teléfono en el texto extraído")

    return fallos


def main():
    BUILD.mkdir(exist_ok=True)
    contacto = leer_contacto()

    for fuente, sufijo in [("cv.md", "ES"), ("cv-en.md", "EN")]:
        ruta = BASE / fuente
        if not ruta.exists():
            continue
        doc = parsear(ruta.read_text(encoding="utf8"))
        destino = BUILD / f"CV_Pablo_Gonzalez_Caceres_{sufijo}.docx"
        construir_docx(doc, contacto, destino)
        pdf = construir_pdf(destino)

        fallos = verificar_ats(destino, pdf)
        estado = "ATS OK" if not fallos else "ATS con problemas"
        print(f"  {destino.name} + {pdf.name}  [{estado}]")
        for f in fallos:
            print(f"      - {f}")


if __name__ == "__main__":
    main()
