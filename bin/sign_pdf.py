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
from pyhanko.sign import fields, signers
from pyhanko.sign.pkcs11 import PKCS11Signer, open_pkcs11_session
from pyhanko.sign.timestamps import HTTPTimeStamper
from pyhanko.stamp import StaticStampStyle

from sigil_stamp import DEFAULT_SCALE, SigilStampContent, dimensions

# The MVP suite is ECDSA P-384 + SHA-384 (ADR-006). SoftHSM exposes ECDSA as the
# raw CKM_ECDSA mechanism, so we name the digest+curve pairing explicitly rather
# than letting pyHanko probe the token.
MD_ALGORITHM = "sha384"
ECDSA_SIG_MECHANISM = algos.SignedDigestAlgorithm({"algorithm": "sha384_ecdsa"})

STAMP_ORIGIN = (36, 36)  # lower-left placement of the stamp on the page
STAMP_TS_FORMAT = "%d.%m.%Y %H:%M:%S Z"  # display only; the crypto TS is the TSA token


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


def main() -> None:
    req = json.load(sys.stdin)
    signer_req = req["signer"]
    appearance = req.get("appearance") or {}

    pdf_bytes = base64.b64decode(req["pdf_b64"])
    writer = IncrementalPdfFileWriter(io.BytesIO(pdf_bytes))

    field_name = req.get("field_name") or "Signature1"
    # The stamp width adapts to the signer name, so size the box from it unless
    # the caller pins an explicit box.
    stamp_w, stamp_h = dimensions(appearance.get("signer_name", ""), DEFAULT_SCALE)
    ox, oy = STAMP_ORIGIN
    box = tuple(req.get("box") or (ox, oy, ox + stamp_w, oy + stamp_h))
    page = resolve_page(writer, int(req.get("page", -1)))

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
            signer_name=appearance.get("signer_name", ""),
            timestamp=stamp_ts,
            scale=DEFAULT_SCALE,
        )
        pdf_signer = signers.PdfSigner(
            signature_meta,
            signer=pkcs11_signer,
            timestamper=timestamper,
            stamp_style=StaticStampStyle(background=stamp, border_width=0),
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
