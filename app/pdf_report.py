from __future__ import annotations

from html import escape
from io import BytesIO
from pathlib import Path
import re
from typing import Any

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    HRFlowable,
    Image,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)


GOLD = colors.HexColor("#C9A45C")
INK = colors.HexColor("#17131F")
MUTED = colors.HexColor("#625D6D")
CREAM = colors.HexColor("#F8F4EA")
LINE = colors.HexColor("#DDD4C1")


def _text(value: Any) -> str:
    return str(value or "").encode("latin-1", "replace").decode("latin-1")


def _paragraphs(value: str, style: ParagraphStyle) -> list[Paragraph | Spacer]:
    blocks: list[Paragraph | Spacer] = []
    for raw in value.replace("\r", "").split("\n"):
        line = raw.strip()
        if not line:
            blocks.append(Spacer(1, 3.5 * mm))
            continue
        line = re.sub(r"^#{1,6}\s*", "", line)
        line = re.sub(r"^[-*+]\s+", "- ", line)
        line = re.sub(r"\*\*(.+?)\*\*", r"\1", line)
        line = re.sub(r"__(.+?)__", r"\1", line)
        line = re.sub(r"(?<!\*)\*([^*]+)\*(?!\*)", r"\1", line)
        blocks.append(Paragraph(escape(_text(line)), style))
        blocks.append(Spacer(1, 1.8 * mm))
    return blocks


def _page(canvas, doc) -> None:
    canvas.saveState()
    width, height = A4
    canvas.setFillColor(INK)
    canvas.rect(0, height - 20 * mm, width, 20 * mm, fill=1, stroke=0)
    canvas.setFillColor(GOLD)
    canvas.setFont("Helvetica-Bold", 13)
    canvas.drawString(18 * mm, height - 12.5 * mm, "MS ASTROLOGIA")
    canvas.setFillColor(MUTED)
    canvas.setFont("Helvetica", 8)
    canvas.drawRightString(width - 18 * mm, 11 * mm, f"Miguel Salazar - Pagina {doc.page}")
    canvas.restoreState()


def _chart_flowable(svg_path: Path):
    try:
        import cairosvg

        source = svg_path.read_text(encoding="utf-8")
        variables = dict(re.findall(r"--([\w-]+)\s*:\s*([^;]+);", source))
        pattern = re.compile(r"var\(--([\w-]+)(?:\s*,\s*([^)]+))?\)")

        def resolve(value: str) -> str:
            current = value.strip()
            for _ in range(20):
                updated = pattern.sub(lambda match: variables.get(match.group(1), match.group(2) or "#777777").strip(), current)
                if updated == current:
                    break
                current = updated
            return current

        resolved = {name: resolve(value) for name, value in variables.items()}
        source = pattern.sub(lambda match: resolved.get(match.group(1), match.group(2) or "#777777"), source)
        png = cairosvg.svg2png(bytestring=source.encode("utf-8"), output_width=1500)
        image = Image(BytesIO(png))
        scale = min(165 * mm / image.imageWidth, 108 * mm / image.imageHeight)
        image.drawWidth = image.imageWidth * scale
        image.drawHeight = image.imageHeight * scale
        image.hAlign = "CENTER"
        return image
    except Exception:
        return None


def generate_reading_pdf(record: dict[str, Any], output_dir: Path, static_dir: Path) -> Path:
    if not record.get("ai_interpretation"):
        raise ValueError("La interpretacion debe estar lista antes de generar el PDF.")
    output_dir.mkdir(parents=True, exist_ok=True)
    output_path = output_dir / f"lectura-{record['public_id']}.pdf"

    styles = getSampleStyleSheet()
    title = ParagraphStyle(
        "TitleMS",
        parent=styles["Title"],
        fontName="Helvetica-Bold",
        fontSize=27,
        leading=31,
        textColor=INK,
        spaceAfter=7 * mm,
    )
    eyebrow = ParagraphStyle(
        "EyebrowMS",
        parent=styles["Normal"],
        fontName="Helvetica-Bold",
        fontSize=8,
        leading=11,
        textColor=GOLD,
        spaceBefore=3 * mm,
        spaceAfter=2 * mm,
    )
    body = ParagraphStyle(
        "BodyMS",
        parent=styles["BodyText"],
        fontName="Helvetica",
        fontSize=10.2,
        leading=15.2,
        textColor=INK,
    )
    intro = ParagraphStyle(
        "IntroMS",
        parent=body,
        fontSize=11.5,
        leading=17,
        textColor=MUTED,
    )
    section = ParagraphStyle(
        "SectionMS",
        parent=styles["Heading2"],
        fontName="Helvetica-Bold",
        fontSize=17,
        leading=21,
        textColor=INK,
        spaceBefore=5 * mm,
        spaceAfter=4 * mm,
    )
    centered = ParagraphStyle("CenterMS", parent=body, alignment=TA_CENTER, textColor=MUTED)

    doc = SimpleDocTemplate(
        str(output_path),
        pagesize=A4,
        rightMargin=18 * mm,
        leftMargin=18 * mm,
        topMargin=29 * mm,
        bottomMargin=20 * mm,
        title=f"Carta natal de {_text(record['full_name'])}",
        author="Miguel Salazar - MS Astrologia",
    )

    story: list[Any] = [
        Paragraph("LECTURA PERSONAL", eyebrow),
        Paragraph(f"Carta natal de<br/><font color='#C9A45C'>{escape(_text(record['full_name']))}</font>", title),
        Paragraph(
            "Una lectura simbolica para reconocer tendencias, fortalezas y preguntas de reflexion. "
            "La astrologia acompana el autoconocimiento y no reemplaza orientacion medica, legal o financiera.",
            intro,
        ),
        Spacer(1, 7 * mm),
    ]

    facts = [
        ["Fecha", _text(record["birth_date"]), "Hora local", _text(record["birth_time"])],
        ["Lugar", _text(record["birthplace"]), "Zona horaria", _text(record["timezone"])],
        ["Sistema", "Zodiaco tropical", "Casas", "Placidus"],
    ]
    table = Table(facts, colWidths=[25 * mm, 61 * mm, 28 * mm, 61 * mm], hAlign="LEFT")
    table.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, -1), CREAM),
                ("BOX", (0, 0), (-1, -1), .6, LINE),
                ("INNERGRID", (0, 0), (-1, -1), .35, LINE),
                ("TEXTCOLOR", (0, 0), (-1, -1), INK),
                ("FONTNAME", (0, 0), (-1, -1), "Helvetica"),
                ("FONTNAME", (0, 0), (0, -1), "Helvetica-Bold"),
                ("FONTNAME", (2, 0), (2, -1), "Helvetica-Bold"),
                ("FONTSIZE", (0, 0), (-1, -1), 8.3),
                ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
                ("TOPPADDING", (0, 0), (-1, -1), 7),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
            ]
        )
    )
    story.extend([table, Spacer(1, 7 * mm)])

    chart_path = static_dir / str(record.get("chart_svg_path") or "")
    chart = _chart_flowable(chart_path) if chart_path.is_file() else None
    if chart:
        story.extend([Paragraph("RUEDA NATAL", eyebrow), Paragraph("Mapa astrologico calculado", section), chart, Spacer(1, 5 * mm), PageBreak()])
    else:
        story.extend([Paragraph("Carta calculada con Kerykeion y Swiss Ephemeris.", centered), Spacer(1, 5 * mm)])

    story.extend([Paragraph("INTERPRETACION", eyebrow), Paragraph("Lectura de tu carta", section)])
    story.extend(_paragraphs(str(record["ai_interpretation"]), body))
    story.extend(
        [
            Spacer(1, 6 * mm),
            HRFlowable(width="100%", thickness=.6, color=LINE),
            Spacer(1, 4 * mm),
            Paragraph(
                "Puedes continuar explorando esta lectura con Aurita desde tu enlace privado de resultados.",
                intro,
            ),
        ]
    )
    doc.build(story, onFirstPage=_page, onLaterPages=_page)
    return output_path
