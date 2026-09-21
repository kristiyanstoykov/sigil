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
  "profile": "ca" | "signer" | "seal",   // optional, defaults from mode
  "module": "/usr/lib/libkryoptic_pkcs11.so",
  "signer": {"token_label": "...", "key_label": "...", "pin": "..."},
  "subject": {"common_name": "...", "organization_name": "...",
              "organizational_unit_name": "...", "country_name": "BG"},
  "validity_days": 365,
  "subject_algorithm": <driver spec v1>,  // the suite the subject's key is
  "issuer_algorithm": <driver spec v1>,   // optional: the SIGNER key's suite;
                                          // read off the key itself when absent
  // mode=issue only:
  "issuer_cert_pem": "-----BEGIN CERTIFICATE-----...",
  "subject_pubkey": {"token_label": "...", "key_label": "..."}
}

Response: {"ok": true, "certificate_pem": "...", "serial_number": "...",
           "subject_dn": "...", "not_before": "...", "not_after": "..."}
or        {"ok": false, "error": "..."}

Both suites come from PHP (SignatureAlgorithmInterface::toDriverSpec, ADR-014)
and nothing here is hard-coded to one: the subject spec shapes the
SubjectPublicKeyInfo (EC named curve, or the ML-DSA OID of RFC 9881 with the raw
public key), the issuer spec picks the signature algorithm and how the TBS is
signed - prehash (digest here, raw CKM_ECDSA in the token) or pure (the token
signs the whole TBS DER, CKM_ML_DSA). Feeding ML-DSA a digest would sign the
wrong bytes, so the mode is never inferred from the key.
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
# Registers the ML-DSA OIDs (RFC 9881/9882) in asn1crypto's maps; 1.5.x lacks them.
import pyhanko_certvalidator.asn1_types  # noqa: F401

from sigil_pkcs11 import find_token

# What an omitted spec means - the classical suite - so the driver stays usable
# by hand. The app always sends both.
CLASSICAL = {"spec": "v1", "family": "ecdsa", "parameter_set": "secp384r1", "digest": "sha384",
             "signing_mode": "prehash", "signature_algorithm": "sha384_ecdsa"}
FAMILIES = ("ecdsa", "ml-dsa")

# Key usage per certificate profile. A seal carries non_repudiation
# (contentCommitment) exactly like a signer certificate: that bit expresses the
# signing ENTITY's commitment to the content, and a legal person commits as much
# as a natural one - PAdES verifiers require it either way. What separates a seal
# from a signature is the subject (an organisation, no natural-person attributes)
# and the policy, not the key usage. See ADR-012.
KEY_USAGE = {
    "ca": {"key_cert_sign", "crl_sign"},
    "signer": {"digital_signature", "non_repudiation"},
    "seal": {"digital_signature", "non_repudiation"},
}


def fail(message: str) -> None:
    json.dump({"ok": False, "error": message}, sys.stdout)
    sys.exit(1)


def name_from(subject: dict) -> x509.Name:
    allowed = ("country_name", "organization_name",
               "organizational_unit_name", "common_name")
    return x509.Name.build(
        {k: v for k, v in subject.items() if k in allowed and v})


ML_DSA_SPECS = {
    1: {"parameter_set": "ML-DSA-44", "signature_algorithm": "mldsa44"},
    2: {"parameter_set": "ML-DSA-65", "signature_algorithm": "mldsa65"},
    3: {"parameter_set": "ML-DSA-87", "signature_algorithm": "mldsa87"},
}


def check_spec(spec: dict) -> dict:
    if spec.get("spec") != "v1" or spec.get("family") not in FAMILIES:
        fail("UnsupportedAlgorithm")
    return spec


def spec_from_key(key) -> dict:
    """The suite an existing signing key belongs to - a CA key has exactly one.

    Used for the issuer when the app does not say: the CA was provisioned with
    whatever suite was active then, and a later switch must not make the app
    sign with the wrong mode.
    """
    if key.key_type == KeyType.EC:
        return CLASSICAL
    if key.key_type == KeyType.ML_DSA:
        return {"spec": "v1", "family": "ml-dsa", "digest": "sha384", "signing_mode": "pure",
                **ML_DSA_SPECS[int(key[Attribute.PARAMETER_SET])]}
    fail("UnsupportedAlgorithm")


def spki_from_token(session, key_label: str, spec: dict) -> keys.PublicKeyInfo:
    """SubjectPublicKeyInfo for the token's public key, shaped by its suite."""
    pub = session.get_key(object_class=ObjectClass.PUBLIC_KEY, label=key_label)
    if spec["family"] == "ecdsa":
        if pub.key_type != KeyType.EC:
            raise ValueError("key/suite mismatch")
        point = core.OctetString.load(pub[Attribute.EC_POINT]).native
        return keys.PublicKeyInfo({
            "algorithm": keys.PublicKeyAlgorithm({
                "algorithm": "ec",
                "parameters": keys.ECDomainParameters(name="named", value=spec["parameter_set"]),
            }),
            "public_key": point,
        })
    # ML-DSA (RFC 9881): algorithm = id-ml-dsa-NN with absent parameters, key = raw bytes.
    if pub.key_type != KeyType.ML_DSA:
        raise ValueError("key/suite mismatch")
    return keys.PublicKeyInfo({
        "algorithm": keys.PublicKeyAlgorithm({"algorithm": spec["signature_algorithm"]}),
        "public_key": core.OctetBitString(pub[Attribute.VALUE]),
    })


def sign_tbs(key, tbs_der: bytes, spec: dict) -> bytes:
    """Sign the TBS in the token, in the suite's mode; returns the DER signature value."""
    if spec["signing_mode"] == "prehash":
        digest = hashlib.new(spec["digest"], tbs_der).digest()
        return encode_ecdsa_signature(key.sign(digest, mechanism=Mechanism.ECDSA))
    # Pure: FIPS 204 over the whole TBS, and the signature is already a plain octet string.
    return key.sign(tbs_der, mechanism=Mechanism.ML_DSA)


def main() -> None:
    req = json.load(sys.stdin)
    mode = req["mode"]
    is_ca = mode == "ca-selfsign"
    if mode not in ("ca-selfsign", "issue"):
        fail(f"unknown mode {mode!r}")

    # The profile picks the key usage set, and is the seam for the extensions a
    # seal will want later (ETSI EN 319 412-3 organizationIdentifier, QCStatements).
    profile = req.get("profile", "ca" if is_ca else "signer")
    if profile not in KEY_USAGE:
        fail(f"unknown profile {profile!r}")

    subject_spec = check_spec(req.get("subject_algorithm", CLASSICAL))
    issuer_spec = check_spec(req["issuer_algorithm"]) if req.get("issuer_algorithm") else None

    lib = pkcs11.lib(req["module"])
    signer = req["signer"]
    token = find_token(lib, signer["token_label"])

    now = datetime.datetime.now(datetime.timezone.utc)
    not_after = now + datetime.timedelta(days=int(req["validity_days"]))
    serial = int.from_bytes(os.urandom(16)) >> 1
    subject = name_from(req["subject"])

    with token.open(user_pin=signer["pin"]) as session:
        key = session.get_key(object_class=ObjectClass.PRIVATE_KEY, label=signer["key_label"])
        if issuer_spec is None:
            issuer_spec = spec_from_key(key)
        sig_algo = algos.SignedDigestAlgorithm({"algorithm": issuer_spec["signature_algorithm"]})

        if is_ca:
            issuer_name = subject
            spki = spki_from_token(session, signer["key_label"], subject_spec)
        else:
            _, _, issuer_der = pem.unarmor(req["issuer_cert_pem"].encode())
            issuer_cert = x509.Certificate.load(issuer_der)
            issuer_name = issuer_cert["tbs_certificate"]["subject"]

            sub_token = find_token(lib, req["subject_pubkey"]["token_label"])
            # public objects only — no PIN for the subject's token
            with sub_token.open() as sub_session:
                spki = spki_from_token(sub_session, req["subject_pubkey"]["key_label"], subject_spec)

        extensions = [
            {"extn_id": "basic_constraints", "critical": True,
             "extn_value": x509.BasicConstraints(
                 {"ca": is_ca} | ({"path_len_constraint": 0} if is_ca else {}))},
            {"extn_id": "key_usage", "critical": True,
             "extn_value": x509.KeyUsage(KEY_USAGE[profile])},
        ]

        tbs = x509.TbsCertificate({
            "version": "v3",
            "serial_number": serial,
            "signature": sig_algo,
            "issuer": issuer_name,
            "validity": {
                "not_before": x509.Time({"utc_time": now}),
                "not_after": x509.Time({"utc_time": not_after}),
            },
            "subject": subject,
            "subject_public_key_info": spki,
            "extensions": extensions,
        })

        signature = sign_tbs(key, tbs.dump(), issuer_spec)

    cert = x509.Certificate({
        "tbs_certificate": tbs,
        "signature_algorithm": sig_algo,
        "signature_value": core.OctetBitString(signature),
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
    except pkcs11.exceptions.MechanismInvalid:
        # The token does not implement the suite's mechanism.
        fail("UnsupportedAlgorithm")
    except Exception as exc:  # noqa: BLE001 — boundary: report the type ONLY.
        # The exception message can echo input (e.g. the PIN) and this string is
        # audit-logged by CertificateIssuer, so report only the class name -
        # never {exc} - on any stream. Type names (PinIncorrect, NoSuchToken,
        # ...) are informative enough for the audit trail.
        fail(type(exc).__name__)
