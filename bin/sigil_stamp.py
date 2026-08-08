#!/usr/bin/env python3
"""The Sigil visible signature appearance (design 'v2').

Hand-drawn PDF appearance matching docs/design/signature/eidas-v2-signed.pdf:
the rounded brand-blue frame (top/bottom strokes broken so header/footer text
sits *on* the line), the Sigil logo mark redrawn as vectors, the bold-italic
"Sigil" wordmark + upright "Signum Veritatis", the signer name in bold, the
qualified-signature + timestamp lines, and the "Compliant with eIDAS." footer.

Text is set in the brand face **Sofia Sans** (embedded from the variable fonts
in assets/fonts). Sofia Sans is narrow, so the name reads large yet still fits;
the frame width adapts to the name so it always wraps the content snugly, like
the reference. Everything is authored at scale 1 and multiplied by `scale`.

Colours are the Sigil tokens (app.css): primary #1E3A8A, headings #0F172A,
secondary text #64748B.
"""
import io
import os

from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from pyhanko.pdf_utils.content import PdfContent, ResourceType
from pyhanko.pdf_utils.generic import (
    ArrayObject, DictionaryObject, NameObject, NumberObject, StreamObject,
    pdf_name,
)
from pyhanko.pdf_utils.layout import BoxConstraints

# --- Sigil brand colours (0..1 RGB) -----------------------------------------
BLUE = (0x1E / 255, 0x3A / 255, 0x8A / 255)   # primary-500 #1E3A8A
INK = (0x0F / 255, 0x17 / 255, 0x2A / 255)    # headings   #0F172A
GREY = (0x47 / 255, 0x55 / 255, 0x69 / 255)   # secondary  #475569
WHITE = (1, 1, 1)

DEFAULT_SCALE = 0.78   # a touch smaller than the reference box
BASE_H = 67.0          # frame height at scale 1 (matches the reference box)

# Sofia Sans logo path from assets/images/sigil-blue-900.svg (viewBox 260x180).
LOGO_START = (168, 38)
LOGO_CURVES = [
    (168, 32, 162, 32, 158, 38), (154, 44, 156, 50, 156, 50),
    (152, 38, 132, 30, 112, 36), (88, 43, 70, 60, 76, 76),
    (82, 90, 104, 90, 122, 84), (138, 79, 152, 84, 148, 100),
    (144, 116, 122, 130, 96, 134), (70, 138, 52, 130, 56, 114),
    (60, 100, 80, 96, 100, 102), (130, 110, 162, 120, 192, 118),
    (212, 116, 226, 110, 234, 102),
]
LOGO_VIEWBOX_H = 180

# --- Sofia Sans embedding ----------------------------------------------------
_FONT_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "assets/fonts/Sofia_Sans")
_UPRIGHT = os.path.join(_FONT_DIR, "SofiaSans-VariableFont_wght.ttf")
_ITALIC = os.path.join(_FONT_DIR, "SofiaSans-Italic-VariableFont_wght.ttf")
# style -> (variable font file, weight, PDF BaseFont name)
_STYLES = {
    "reg": (_UPRIGHT, 400, "SigilSofia-Regular"),
    "bold": (_UPRIGHT, 700, "SigilSofia-Bold"),
    "italic": (_ITALIC, 400, "SigilSofia-Italic"),
    "bolditalic": (_ITALIC, 700, "SigilSofia-BoldItalic"),
}
_FIRST, _LAST = 32, 126        # ASCII printable range we embed widths for
_cache: dict = {}
# Instancing a Sofia Sans weight costs ~4s, and every signature runs as a fresh
# `python3 sign_pdf.py` subprocess - so the in-memory _cache never helps. Persist
# each instanced static TTF to disk (keyed by the source font's mtime so it
# self-invalidates) and reuse it across runs; this turns a ~16s stamp build into
# a fast font load. The dir lives on the repo bind-mount, so it survives
# container restarts / `docker compose down`.
_CACHE_DIR = os.path.join(_FONT_DIR, ".instanced")


def _load(style: str):
    """Instance a Sofia Sans weight; cache the TTF, its bytes and metrics."""
    if style in _cache:
        return _cache[style]
    path, wght, base = _STYLES[style]

    cache_path = os.path.join(_CACHE_DIR, f"{style}-{wght}-{int(os.path.getmtime(path))}.ttf")
    ttf = None
    if os.path.exists(cache_path):
        try:
            ttf = TTFont(cache_path)  # already instanced - just parse (fast)
        except Exception:
            ttf = None
    if ttf is None:
        ttf = TTFont(path)
        instantiateVariableFont(ttf, {"wght": wght}, inplace=True)
        try:  # best-effort cache write - a failure must never break signing
            os.makedirs(_CACHE_DIR, exist_ok=True)
            ttf.save(cache_path)
        except Exception:
            pass
    buf = io.BytesIO(); ttf.save(buf)
    upm = ttf["head"].unitsPerEm
    cmap = ttf.getBestCmap()
    hmtx = ttf["hmtx"]
    widths = {}
    for code in range(_FIRST, _LAST + 1):
        g = cmap.get(code)
        widths[code] = round(hmtx[g][0] * 1000 / upm) if g else 0
    entry = _cache[style] = {
        "ttf": ttf, "bytes": buf.getvalue(), "upm": upm, "base": base,
        "widths": widths, "italic": ttf["post"].italicAngle,
    }
    return entry


def _measure(text: str, size: float, style: str) -> float:
    w = _load(style)["widths"]
    return sum(w.get(ord(c), 500) for c in text) * size / 1000.0


def _embed(writer, style: str):
    e = _load(style)
    ttf, upm = e["ttf"], e["upm"]
    head, post = ttf["head"], ttf["post"]
    os2 = ttf.get("OS/2")
    bbox = [round(v * 1000 / upm) for v in (head.xMin, head.yMin, head.xMax, head.yMax)]
    ff = StreamObject(dict_data={NameObject("/Length1"): NumberObject(len(e["bytes"]))},
                      stream_data=e["bytes"])
    ff_ref = writer.add_object(ff)
    desc = DictionaryObject({
        pdf_name("/Type"): pdf_name("/FontDescriptor"),
        pdf_name("/FontName"): pdf_name("/" + e["base"]),
        pdf_name("/Flags"): NumberObject(96 if e["italic"] else 32),  # nonsymbolic (+italic)
        pdf_name("/FontBBox"): ArrayObject([NumberObject(x) for x in bbox]),
        pdf_name("/ItalicAngle"): NumberObject(round(e["italic"])),
        pdf_name("/Ascent"): NumberObject(round((os2.sTypoAscender if os2 else head.yMax) * 1000 / upm)),
        pdf_name("/Descent"): NumberObject(round((os2.sTypoDescender if os2 else head.yMin) * 1000 / upm)),
        pdf_name("/CapHeight"): NumberObject(round((getattr(os2, "sCapHeight", 700) or 700) * 1000 / upm)),
        pdf_name("/StemV"): NumberObject(90 if e["italic"] else 80),
        pdf_name("/FontFile2"): ff_ref,
    })
    font = DictionaryObject({
        pdf_name("/Type"): pdf_name("/Font"),
        pdf_name("/Subtype"): pdf_name("/TrueType"),
        pdf_name("/BaseFont"): pdf_name("/" + e["base"]),
        pdf_name("/FirstChar"): NumberObject(_FIRST),
        pdf_name("/LastChar"): NumberObject(_LAST),
        pdf_name("/Widths"): ArrayObject([NumberObject(e["widths"][c]) for c in range(_FIRST, _LAST + 1)]),
        pdf_name("/FontDescriptor"): writer.add_object(desc),
        pdf_name("/Encoding"): pdf_name("/WinAnsiEncoding"),
    })
    return writer.add_object(font)


# --- content-stream helpers --------------------------------------------------
def _num(x: float) -> str:
    return f"{x:.2f}".rstrip("0").rstrip(".")


def _esc(text: str) -> str:
    return text.replace("\\", r"\\").replace("(", r"\(").replace(")", r"\)")


def _rgb(op: str, color) -> str:
    r, g, b = color
    return f"{_num(r)} {_num(g)} {_num(b)} {op}"


def _center_baseline(line_y: float, size: float) -> float:
    return line_y - 0.36 * size   # Sofia cap height ~0.72 em


def _rounded_rect(x0, y0, x1, y1, r) -> str:
    k = r * 0.5523
    return (
        f"{_num(x0 + r)} {_num(y0)} m {_num(x1 - r)} {_num(y0)} l "
        f"{_num(x1 - r + k)} {_num(y0)} {_num(x1)} {_num(y0 + r - k)} {_num(x1)} {_num(y0 + r)} c "
        f"{_num(x1)} {_num(y1 - r)} l "
        f"{_num(x1)} {_num(y1 - r + k)} {_num(x1 - r + k)} {_num(y1)} {_num(x1 - r)} {_num(y1)} c "
        f"{_num(x0 + r)} {_num(y1)} l "
        f"{_num(x0 + r - k)} {_num(y1)} {_num(x0)} {_num(y1 - r + k)} {_num(x0)} {_num(y1 - r)} c "
        f"{_num(x0)} {_num(y0 + r)} l "
        f"{_num(x0)} {_num(y0 + r - k)} {_num(x0 + r - k)} {_num(y0)} {_num(x0 + r)} {_num(y0)} c h"
    )


def _text(x, y, size, color, font, text) -> str:
    return (f"BT {_rgb('rg', color)} /{font} {_num(size)} Tf "
            f"1 0 0 1 {_num(x)} {_num(y)} Tm ({_esc(text)}) Tj ET")


def _rect(x, y, w, h, color) -> str:
    return f"{_rgb('rg', color)} {_num(x)} {_num(y)} {_num(w)} {_num(h)} re f"


def _hgap(x_left, x_right, line_y, h) -> str:
    return _rect(x_left, line_y - h / 2, x_right - x_left, h, WHITE)


def _logo(ox, oy, unit, line_w) -> str:
    def tx(x):
        return ox + x * unit

    def ty(y):
        return oy + (LOGO_VIEWBOX_H - y) * unit

    sx, sy = LOGO_START
    ops = [_rgb("RG", BLUE), f"{_num(line_w)} w", "1 J 1 j",
           f"{_num(tx(sx))} {_num(ty(sy))} m"]
    for c in LOGO_CURVES:
        ops.append(f"{_num(tx(c[0]))} {_num(ty(c[1]))} {_num(tx(c[2]))} {_num(ty(c[3]))} "
                   f"{_num(tx(c[4]))} {_num(ty(c[5]))} c")
    ops.append("S")
    return " ".join(ops)


# Font sizes at scale 1 (points).
FS_NAME, FS_SIGIL, FS_TAG, FS_BODY, FS_FOOTER = 11.5, 11.5, 10.5, 7.5, 7.8
PAD = 10.0
RIGHT_INSET = 7.0   # extra right margin so "Signum"/"Compliant" clear the corner
_LOGO_W = (234 - 52) * (11.5 / 108.0)   # logo advance width at scale 1


def stamp_width(signer_name: str, scale: float = DEFAULT_SCALE) -> float:
    """Frame width (points) - driven by the widest line so the frame wraps
    the content snugly, exactly like the reference."""
    name_w = _measure(signer_name.upper(), FS_NAME, "bold")
    header_w = _LOGO_W + 6 + _measure("Sigil", FS_SIGIL, "bolditalic") + 22 \
        + _measure("Signum Veritatis", FS_TAG, "italic") + RIGHT_INSET
    footer_w = _measure("Compliant with eIDAS.", FS_FOOTER, "reg") + RIGHT_INSET
    content = max(name_w, header_w, footer_w)
    return (2 * PAD + content) * scale


# Where the visible ink sits inside the drawn box, at scale 1: the frame is
# stroked a little inside the box and the header/footer text overhangs the
# frame lines. Measured by rasterising a signed page. The appearance must keep
# being drawn into the full box (trimming the box clips the frame), so callers
# that need to butt two stamps together subtract these insets themselves - see
# ink_dimensions() and place_stamp() in sign_pdf.py.
INK_LEFT, INK_RIGHT, INK_BOTTOM, INK_TOP = 2.3, 2.7, 5.4, 3.5


def dimensions(signer_name: str, scale: float = DEFAULT_SCALE) -> tuple[float, float]:
    """Full drawn size, padding included - the box the appearance needs."""
    return stamp_width(signer_name, scale), BASE_H * scale


def ink_dimensions(signer_name: str, scale: float = DEFAULT_SCALE) -> tuple[float, float]:
    """Visible size: the drawn box less its transparent padding."""
    w, h = dimensions(signer_name, scale)
    return w - (INK_LEFT + INK_RIGHT) * scale, h - (INK_BOTTOM + INK_TOP) * scale


def fit_scale(signer_name: str, target_w: float, max_scale: float = DEFAULT_SCALE) -> float:
    """Largest scale up to max_scale whose visible frame fits within target_w.

    Every dimension here is linear in scale, so the fit is exact: the frame
    can be no narrower than its own header ("Sigil" + "Signum Veritatis") and
    footer, which is why a long signer name is what forces the scale down."""
    natural, _ = ink_dimensions(signer_name, 1.0)
    if natural <= 0:
        return max_scale
    return min(max_scale, target_w / natural)


def stamp_ops(signer_name: str, timestamp: str, scale: float = DEFAULT_SCALE) -> bytes:
    s = scale
    W, H = dimensions(signer_name, scale)
    pad = PAD * s
    top_y = H - 10 * s          # top frame line
    bot_y = 9 * s               # bottom frame line
    name = signer_name.upper()
    ops: list[str] = []

    right_x = W - pad - RIGHT_INSET * s   # right edge for "Signum"/"Compliant"

    # 1. Rounded brand-blue frame (top/bottom edges are the header/footer lines).
    ops.append(_rgb("RG", BLUE))
    ops.append(f"{_num(1.1 * s)} w")
    ops.append(_rounded_rect(3 * s, bot_y, W - 3 * s, top_y, 8 * s))
    ops.append("S")

    # 2. Header geometry.
    unit = 11.5 * s / 108.0
    logo_ox = pad - 52 * unit
    wordmark_x = pad + (234 - 52) * unit + 6 * s
    sigil_w = _measure("Sigil", FS_SIGIL, "bolditalic") * s
    tag_w = _measure("Signum Veritatis", FS_TAG, "italic") * s
    footer_w = _measure("Compliant with eIDAS.", FS_FOOTER, "reg") * s

    # 3. White gaps breaking the frame stroke behind header/footer.
    gh = 13 * s
    ops.append(_hgap(pad - 4 * s, wordmark_x + sigil_w + 5 * s, top_y, gh))
    ops.append(_hgap(right_x - tag_w - 5 * s, right_x + 5 * s, top_y, gh))
    ops.append(_hgap(right_x - footer_w - 5 * s, right_x + 5 * s, bot_y, gh))

    # 4. Header on the top line: logo + bold-italic "Sigil" (left),
    #    italic (cursive) "Signum Veritatis" (right).
    ops.append(_logo(logo_ox, top_y - 96 * unit, unit, 1.1 * s))
    ops.append(_text(wordmark_x, _center_baseline(top_y, FS_SIGIL * s), FS_SIGIL * s, BLUE, "F3", "Sigil"))
    ops.append(_text(right_x - tag_w, _center_baseline(top_y, FS_TAG * s), FS_TAG * s, BLUE, "F4", "Signum Veritatis"))

    # 5. Body: signer name (bold), then the two qualified lines.
    ops.append(_text(pad, top_y - 17 * s, FS_NAME * s, INK, "F2", name))
    ops.append(_text(pad, top_y - 28 * s, FS_BODY * s, GREY, "F1", "Qualified electronic signature"))
    ops.append(_text(pad, top_y - 38 * s, FS_BODY * s, GREY, "F1", f"Qualified time-stamped: {timestamp}"))

    # 6. Footer on the bottom line, flush right (smaller), clear of the corner.
    ops.append(_text(right_x - footer_w, _center_baseline(bot_y, FS_FOOTER * s), FS_FOOTER * s, BLUE, "F1", "Compliant with eIDAS."))

    return ("\n".join(ops)).encode("latin-1")


class SigilStampContent(PdfContent):
    """pyHanko PdfContent wrapping the appearance; embeds the Sofia Sans faces."""

    def __init__(self, signer_name: str, timestamp: str, scale: float = DEFAULT_SCALE):
        w, h = dimensions(signer_name, scale)
        super().__init__(box=BoxConstraints(width=w, height=h))
        self._signer = signer_name
        self._ts = timestamp
        self._scale = scale

    def render(self) -> bytes:
        self.set_resource(ResourceType.FONT, NameObject("/F1"), _embed(self.writer, "reg"))
        self.set_resource(ResourceType.FONT, NameObject("/F2"), _embed(self.writer, "bold"))
        self.set_resource(ResourceType.FONT, NameObject("/F3"), _embed(self.writer, "bolditalic"))
        self.set_resource(ResourceType.FONT, NameObject("/F4"), _embed(self.writer, "italic"))
        return stamp_ops(self._signer, self._ts, self._scale)
