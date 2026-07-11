#!/usr/bin/env python3
"""Sigil certificate issuance driver (ADR-005/ADR-006).

Builds an X.509 certificate whose signature is produced INSIDE a PKCS#11
token — the CA (or self-signing) private key never leaves it. Invoked by
the Symfony app (Certificate module) with a JSON request on stdin; emits a
JSON response on stdout. PINs travel only via that stdin JSON: never argv,
never disk.

Request:
{
  "mode": "ca-selfsign" | "issue",
  "module": "/usr/lib/softhsm/libsofthsm2.so",
  "signer": {"token_label": "...", "key_label": "...", "pin": "..."},
  "subject": {"common_name": "...", "organization_name": "...",
              "organizational_unit_name": "...", "country_name": "BG"},
  "validity_days": 365,
  // mode=issue only:
  "issuer_cert_pem": "-----BEGIN CERTIFICATE-----...",
  "subject_pubkey": {"token_label": "...", "key_label": "..."}
}

Response: {"ok": true, "certificate_pem": "...", "serial_number": "...",
           "subject_dn": "...", "not_before": "...", "not_after": "..."}
or        {"ok": false, "error": "..."}

The signing digest/algorithm follows the SIGNER key type (ECDSA P-384 +
SHA-384 for the default suite). SoftHSM exposes only raw CKM_ECDSA, so the
TBS digest is computed here and signed in-token (same shape as ADR-007).
"""
import datetime
import hashlib
import json
import os
import sys

import pkcs11
from pkcs11 import Attribute, KeyType, Mechanism, ObjectClass
from pkcs11.util.ec import encode_ecdsa_signature
from asn1crypto import algos, core, keys, pem, x509

DIGEST = "sha384"
SIG_ALGO = algos.SignedDigestAlgorithm({"algorithm": "sha384_ecdsa"})


def fail(message: str) -> None:
    json.dump({"ok": False, "error": message}, sys.stdout)
    sys.exit(1)


def name_from(subject: dict) -> x509.Name:
    allowed = ("country_name", "organization_name",
               "organizational_unit_name", "common_name")
    return x509.Name.build(
        {k: v for k, v in subject.items() if k in allowed and v})


def spki_from_token(session, key_label: str) -> keys.PublicKeyInfo:
    pub = session.get_key(object_class=ObjectClass.PUBLIC_KEY, label=key_label)
    if pub.key_type == KeyType.EC:
        point = core.OctetString.load(pub[Attribute.EC_POINT]).native
        return keys.PublicKeyInfo({
            "algorithm": keys.PublicKeyAlgorithm({
                "algorithm": "ec",
                "parameters": keys.ECDomainParameters(
                    name="named", value="secp384r1"),
            }),
            "public_key": point,
        })
    if pub.key_type == KeyType.RSA:
        rsa = keys.RSAPublicKey({
            "modulus": int.from_bytes(pub[Attribute.MODULUS]),
            "public_exponent": int.from_bytes(pub[Attribute.PUBLIC_EXPONENT]),
        })
        return keys.PublicKeyInfo({
            "algorithm": keys.PublicKeyAlgorithm({"algorithm": "rsa"}),
            "public_key": rsa,
        })
    raise ValueError(f"unsupported key type {pub.key_type!r}")


def main() -> None:
    req = json.load(sys.stdin)
    mode = req["mode"]
    is_ca = mode == "ca-selfsign"
    if mode not in ("ca-selfsign", "issue"):
        fail(f"unknown mode {mode!r}")

    lib = pkcs11.lib(req["module"])
    signer = req["signer"]
    token = lib.get_token(token_label=signer["token_label"])

    now = datetime.datetime.now(datetime.timezone.utc)
    not_after = now + datetime.timedelta(days=int(req["validity_days"]))
    serial = int.from_bytes(os.urandom(16)) >> 1
    subject = name_from(req["subject"])

    with token.open(user_pin=signer["pin"]) as session:
        if is_ca:
            issuer_name = subject
            spki = spki_from_token(session, signer["key_label"])
        else:
            _, _, issuer_der = pem.unarmor(req["issuer_cert_pem"].encode())
            issuer_cert = x509.Certificate.load(issuer_der)
            issuer_name = issuer_cert["tbs_certificate"]["subject"]

            sub_token = lib.get_token(
                token_label=req["subject_pubkey"]["token_label"])
            # public objects only — no PIN for the subject's token
            with sub_token.open() as sub_session:
                spki = spki_from_token(
                    sub_session, req["subject_pubkey"]["key_label"])

        extensions = [
            {"extn_id": "basic_constraints", "critical": True,
             "extn_value": x509.BasicConstraints(
                 {"ca": is_ca} | ({"path_len_constraint": 0} if is_ca else {}))},
            {"extn_id": "key_usage", "critical": True,
             "extn_value": x509.KeyUsage(
                 {"key_cert_sign", "crl_sign"} if is_ca
                 else {"digital_signature", "non_repudiation"})},
        ]

        tbs = x509.TbsCertificate({
            "version": "v3",
            "serial_number": serial,
            "signature": SIG_ALGO,
            "issuer": issuer_name,
            "validity": {
                "not_before": x509.Time({"utc_time": now}),
                "not_after": x509.Time({"utc_time": not_after}),
            },
            "subject": subject,
            "subject_public_key_info": spki,
            "extensions": extensions,
        })

        key = session.get_key(object_class=ObjectClass.PRIVATE_KEY,
                              label=signer["key_label"])
        digest = hashlib.new(DIGEST, tbs.dump()).digest()
        raw_sig = key.sign(digest, mechanism=Mechanism.ECDSA)

    cert = x509.Certificate({
        "tbs_certificate": tbs,
        "signature_algorithm": SIG_ALGO,
        "signature_value": encode_ecdsa_signature(raw_sig),
    })

    json.dump({
        "ok": True,
        "certificate_pem": pem.armor("CERTIFICATE", cert.dump()).decode(),
        "serial_number": format(serial, "x"),
        "subject_dn": subject.human_friendly,
        "not_before": now.isoformat(),
        "not_after": not_after.isoformat(),
    }, sys.stdout)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:  # noqa: BLE001 — boundary: report the type ONLY.
        # The exception message can echo input (e.g. the PIN) and this string is
        # audit-logged by CertificateIssuer, so report only the class name -
        # never {exc} - on any stream. Type names (PinIncorrect, NoSuchToken,
        # ...) are informative enough for the audit trail.
        fail(type(exc).__name__)
