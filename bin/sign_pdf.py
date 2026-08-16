#!/usr/bin/env python3
"""Sigil PAdES signing driver (ADR-005/ADR-006/ADR-007).

Signs a PDF with a per-user key that lives INSIDE a PKCS#11 token — the
private key never leaves it, only the CMS signature comes out. Invoked by the
Symfony app (Signing module) with a JSON request on stdin; emits a JSON
response on stdout. The PIN travels only via that stdin JSON: never argv,
never disk (same contract as bin/issue_cert.py).

Produces PAdES-B-T: a PAdES (ETSI.CAdES.detached) signature over SHA-384 with a
signature timestamp from an RFC 3161 TSA when one is supplied; without a TSA it
degrades to PAdES-B-B. A visible signature appearance (the Sigil stamp) is drawn
on the chosen page.

Request:
{
  "module": "/usr/lib/softhsm/libsofthsm2.so",
  "pdf_b64": "<base64 of the PDF bytes>",
  "signer": {"token_label": "...", "key_label": "sign",
             "signing_cert_pem": "-----BEGIN CERTIFICATE-----...", "pin": "..."},
  "ca_chain_pem": "-----BEGIN CERTIFICATE-----...",   // issuer chain to embed
  "field_name": "Signature1",
  "reason": "..." | null,
  "location": "..." | null,
  "tsa_url": "http://tsa:318.../" | null,             // null => B-B, no timestamp
  "appearance": {
      "signer_name": "KRISTIYAN LYUBOMIROV STOYKOV",
      "brand": "Sigil", "tagline": "Signum Veritatis",
      "line1": "Qualified electronic signature",
      "footer": "Compliant with eIDAS."
  },
  "page": -1,                       // 0-based; -1 = last page
  "box": [x1, y1, x2, y2]           // PDF user-space points, lower-left origin
}

Response: {"ok": true, "pdf_b64": "..."}  or  {"ok": false, "error": "..."}
"""
import base64
import datetime
import io
import json
import sys

from asn1crypto import algos, pem, x509
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign import fields, signers
from pyhanko.sign.pkcs11 import PKCS11Signer, open_pkcs11_session
from pyhanko.sign.timestamps import HTTPTimeStamper
from pyhanko.pdf_utils import layout
from pyhanko.stamp import StaticStampStyle

from sigil_stamp import (
    BASE_H,
    DEFAULT_SCALE,
    INK_BOTTOM,
    INK_LEFT,
    INK_RIGHT,
    INK_TOP,
    DEFAULT_LINE1,
    SigilStampContent,
    dimensions,
    fit_scale,
    ink_dimensions,
)

# The MVP suite is ECDSA P-384 + SHA-384 (ADR-006). SoftHSM exposes ECDSA as the
# raw CKM_ECDSA mechanism, so we name the digest+curve pairing explicitly rather
# than letting pyHanko probe the token.
MD_ALGORITHM = "sha384"
ECDSA_SIG_MECHANISM = algos.SignedDigestAlgorithm({"algorithm": "sha384_ecdsa"})

STAMP_ORIGIN = (36, 36)  # fallback placement when the grid has no free cell
STAMP_TS_FORMAT = "%d.%m.%Y %H:%M:%S Z"  # display only; the crypto TS is the TSA token

# Stamp placement (see place_stamp). Stamps are packed from the bottom-left of
# the usable page box, left -> right, then up one row. dimensions() reports the
# ink box, so this is the visible gap between two stamps, not box padding plus
# a gap.
GRID_GUTTER = 4.0
GRID_MARGIN = 36.0   # default page inset; content_box may tighten it per edge
# Field-name prefix minted by DocumentSigner - marks a stamp as ours, whose
# box padding we know and can discount when packing against it.
SIGIL_FIELD_PREFIX = "SigilSignature"


def fail(message: str) -> None:
    json.dump({"ok": False, "error": message}, sys.stdout)
    sys.exit(1)


def load_ca_chain(chain_pem: str):
    """Split a PEM bundle into asn1crypto x509.Certificate objects."""
    certs = []
    data = chain_pem.encode() if isinstance(chain_pem, str) else chain_pem
    for _, _, der in pem.unarmor(data, multiple=True):
        certs.append(x509.Certificate.load(der))
    return certs


def load_cert(cert_pem: str) -> x509.Certificate:
    """Load a single PEM certificate into an asn1crypto x509.Certificate."""
    data = cert_pem.encode() if isinstance(cert_pem, str) else cert_pem
    _, _, der = pem.unarmor(data)
    return x509.Certificate.load(der)


def resolve_page(writer: IncrementalPdfFileWriter, page: int) -> int:
    count = writer.root["/Pages"]["/Count"]
    if page < 0:
        return count - 1
    return min(page, count - 1)


def page_rect(writer: IncrementalPdfFileWriter, page: int) -> tuple:
    """The page's /MediaBox, normalised to (x1, y1, x2, y2) with x1<x2, y1<y2."""
    page_obj, _ = writer.find_page_for_modification(page)
    x1, y1, x2, y2 = (float(v) for v in page_obj.get_object()["/MediaBox"].get_object())
    return min(x1, x2), min(y1, y2), max(x1, x2), max(y1, y2)


def occupied_rects(writer: IncrementalPdfFileWriter, page: int) -> list:
    """Visible boxes already taken on a page.

    Existing signature widgets and any other annotation. Every level here can
    be an indirect reference, hence the .get_object() calls. Our own stamps
    are reported as their ink, not their padded box - otherwise each one would
    reserve several points of empty space around itself and the next stamp
    could never sit close to it. Vendor stamps are near enough flush with
    their box to use as-is."""
    page_obj, _ = writer.find_page_for_modification(page)
    annots = page_obj.get_object().get("/Annots")
    if annots is None:
        return []
    boxes = []
    for annot in annots.get_object():
        annot = annot.get_object()
        rect = annot.get("/Rect")
        if rect is None:
            continue
        x1, y1, x2, y2 = (float(v) for v in rect.get_object())
        box = (min(x1, x2), min(y1, y2), max(x1, x2), max(y1, y2))
        if str(annot.get("/T") or "").startswith(SIGIL_FIELD_PREFIX):
            box = ink_box(box)
        # A zero-area rect is an invisible signature - it occupies nothing.
        if box[2] > box[0] and box[3] > box[1]:
            boxes.append(box)
    return boxes


def ink_box(box: tuple) -> tuple:
    """Strip the transparent padding from one of our own stamp boxes.

    The box height is BASE_H * scale by construction, which is what lets us
    recover the scale a past signature was drawn at."""
    scale = (box[3] - box[1]) / BASE_H
    return (
        box[0] + INK_LEFT * scale,
        box[1] + INK_BOTTOM * scale,
        box[2] - INK_RIGHT * scale,
        box[3] - INK_TOP * scale,
    )


def overlaps(a: tuple, b: tuple) -> bool:
    return a[0] < b[2] and b[0] < a[2] and a[1] < b[3] and b[1] < a[3]


def content_box(page: tuple, taken: list) -> tuple:
    """The usable region of the page: our default margin, tightened per edge to
    whatever inset a signature already on the page uses.

    A vendor that hugs an edge closer than we would (Evrotrust sits 15pt from
    the left on A5, 9pt from the bottom on A4) is telling us how much of the
    page is really usable, so we follow it onto its own baseline. Insets are
    read per edge and only ever gain room, never lose it - a stamp sitting
    mid-page must not drag our left margin out to the middle of the page."""
    px1, py1, px2, py2 = page

    def inset(values) -> float:
        return max(0.0, min([GRID_MARGIN, *values]))

    return (
        px1 + inset([t[0] - px1 for t in taken]),
        py1 + inset([t[1] - py1 for t in taken]),
        px2 - inset([px2 - t[2] for t in taken]),
        py2 - inset([py2 - t[3] for t in taken]),
    )


def padded_box(ink: tuple, scale: float) -> tuple:
    """Inverse of ink_box: the box the appearance has to be drawn into.

    Trimming the box instead would clip the frame's own stroke, so the padding
    stays and placement compensates for it."""
    return (
        ink[0] - INK_LEFT * scale,
        ink[1] - INK_BOTTOM * scale,
        ink[2] + INK_RIGHT * scale,
        ink[3] + INK_TOP * scale,
    )


def row_origins(bottom: float, top: float, h: float, taken: list):
    """Baselines to try, upward from the anchor.

    Rows are not a fixed pitch: the next baseline is a gutter above whatever
    box ends lowest above the current one, so a stamp sits directly on top of
    the content below it instead of on a grid line. Falls back to a plain
    stamp-height step once nothing is left to hug (an otherwise empty page)."""
    ledges = sorted({t[3] + GRID_GUTTER for t in taken if t[3] > bottom})
    y = bottom
    while y + h <= top:
        yield y
        above = [c for c in ledges if c > y]
        y = above[0] if above else y + h + GRID_GUTTER


def place_stamp(writer: IncrementalPdfFileWriter, page: int, signer_name: str) -> tuple:
    """First free slot, packed left -> right from the bottom-left, then up a row.

    Returns (box, scale). Always starts at the bottom-left of the usable box,
    whatever is already on the page: signatures off to the right do not move
    our origin, they are just cells to step over. The stamp keeps one size for
    the whole page - sized to the row width, capped at DEFAULT_SCALE - so
    co-signatures stay visually consistent instead of shrinking into whatever
    gap they land in. Note this avoids annotations only, not body text, which
    would need content-stream parsing."""
    taken = occupied_rects(writer, page)
    left, bottom, right, top = content_box(page_rect(writer, page), taken)

    scale = fit_scale(signer_name, right - left)
    # Everything below is in ink coordinates - what the reader actually sees -
    # and only the returned box is padded back out to what the appearance needs
    # to draw into. Spacing therefore means visible spacing.
    w, h = ink_dimensions(signer_name, scale)

    for y in row_origins(bottom, top, h, taken):
        x = left
        while x + w <= right:
            ink = (x, y, x + w, y + h)
            hits = [t for t in taken if overlaps(ink, t)]
            if not hits:
                return padded_box(ink, scale), scale
            # Skip past everything blocking this slot rather than creeping.
            x = max(t[2] for t in hits) + GRID_GUTTER
    # Page full: overlap at the fixed origin rather than fail to sign.
    ox, oy = STAMP_ORIGIN
    w, h = dimensions(signer_name, DEFAULT_SCALE)
    return (ox, oy, ox + w, oy + h), DEFAULT_SCALE


def main() -> None:
    req = json.load(sys.stdin)
    signer_req = req["signer"]
    appearance = req.get("appearance") or {}

    pdf_bytes = base64.b64decode(req["pdf_b64"])

    # A password-protected PDF cannot be signed without its password, and the
    # failure deep inside pyHanko is unreadable. Say so plainly instead.
    if PdfFileReader(io.BytesIO(pdf_bytes)).security_handler is not None:
        fail("EncryptedPdf")

    # strict=False so hybrid cross-reference documents can be signed. Word,
    # LibreOffice and most "print to PDF" paths still emit them, and pyHanko
    # refuses them in strict mode because such a file carries two parallel
    # cross-reference views that a reader could resolve differently.
    #
    # Sigil accepts that risk deliberately: it is a rendering ambiguity in the
    # *input*, not in what gets signed. The signature covers an exact byte
    # range, and Sigil independently records SHA-384 of those same bytes on the
    # DocumentVersion, which the delivery receipt then names - so what was
    # signed stays pinned regardless of how a viewer resolves the xref. See
    # "Hybrid cross-reference PDFs" in CLAUDE.md.
    writer = IncrementalPdfFileWriter(io.BytesIO(pdf_bytes), strict=False)

    field_name = req.get("field_name") or "Signature1"
    page = resolve_page(writer, int(req.get("page", -1)))
    signer_name = appearance.get("signer_name", "")
    # An explicit box from the caller wins; otherwise take the first grid cell
    # that clears the signatures already on the page.
    if req.get("box"):
        box, stamp_scale = tuple(req["box"]), DEFAULT_SCALE
    else:
        box, stamp_scale = place_stamp(writer, page, signer_name)

    # A visible signature field on the chosen page.
    fields.append_signature_field(
        writer,
        sig_field_spec=fields.SigFieldSpec(
            sig_field_name=field_name, on_page=page, box=box
        ),
    )

    session = open_pkcs11_session(
        req["module"],
        token_label=signer_req["token_label"],
        user_pin=signer_req["pin"],
    )
    try:
        ca_chain = load_ca_chain(req["ca_chain_pem"]) if req.get("ca_chain_pem") else ()
        # The signing cert lives in the DB (Certificate.certificatePem), NOT in the
        # token - issue_cert.py provisions the token with the keypair only. Hand the
        # cert to pyHanko directly so it never probes the token for a cert object.
        pkcs11_signer = PKCS11Signer(
            session,
            signing_cert=load_cert(signer_req["signing_cert_pem"]),
            key_label=signer_req["key_label"],
            ca_chain=ca_chain,
            signature_mechanism=ECDSA_SIG_MECHANISM,
            # SoftHSM (and most tokens) expose only the raw CKM_ECDSA mechanism,
            # which signs a pre-computed digest - not CKM_ECDSA_SHA384. Hash here
            # and hand the token raw bytes, exactly as bin/issue_cert.py does.
            use_raw_mechanism=True,
        )

        tsa_url = req.get("tsa_url")
        timestamper = HTTPTimeStamper(tsa_url) if tsa_url else None

        signature_meta = signers.PdfSignatureMetadata(
            field_name=field_name,
            md_algorithm=MD_ALGORITHM,
            subfilter=fields.SigSeedSubFilter.PADES,
            reason=req.get("reason") or None,
            location=req.get("location") or None,
        )

        stamp_ts = datetime.datetime.now(datetime.timezone.utc).strftime(STAMP_TS_FORMAT)
        stamp = SigilStampContent(
            signer_name=signer_name,
            timestamp=stamp_ts,
            line1=appearance.get("line1") or DEFAULT_LINE1,
            # The same scale the box was sized with, or the appearance would be
            # stretched to fit a box it was not drawn for.
            scale=stamp_scale,
        )
        pdf_signer = signers.PdfSigner(
            signature_meta,
            signer=pkcs11_signer,
            timestamper=timestamper,
            stamp_style=StaticStampStyle(
                background=stamp,
                border_width=0,
                # pyHanko's default background layout centres the appearance
                # inside a 5pt margin and SHRINK_TO_FITs it - which rendered
                # our stamp at ~81% of the box and left padding proportional
                # to the box width, so wide names drifted right. We size the
                # box from the appearance ourselves, so draw it 1:1 at the
                # box origin instead.
                background_layout=layout.SimpleBoxLayoutRule(
                    x_align=layout.AxisAlignment.ALIGN_MIN,
                    y_align=layout.AxisAlignment.ALIGN_MIN,
                    margins=layout.Margins.uniform(0),
                    inner_content_scaling=layout.InnerScaling.NO_SCALING,
                ),
            ),
        )

        out = io.BytesIO()
        pdf_signer.sign_pdf(writer, output=out)
    finally:
        session.close()

    json.dump(
        {"ok": True, "pdf_b64": base64.b64encode(out.getvalue()).decode()},
        sys.stdout,
    )


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:  # noqa: BLE001 — boundary: report the type ONLY.
        # The message can echo input (e.g. the PIN) and this string is
        # audit-logged by the caller, so report only the class name.
        fail(type(exc).__name__)
