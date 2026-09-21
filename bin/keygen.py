#!/usr/bin/env python3
"""Generate a signing key pair inside a PKCS#11 token, per suite (ADR-014).

Request on stdin, response on stdout; the PIN travels only via that pipe:

    {"module": "...", "token_label": "...", "key_label": "sign", "key_id": "01",
     "pin": "...", "algorithm": <driver spec v1>}

Response: {"ok": true, "public_key_len": N} or {"ok": false, "error": "<type>"}.

One path for every family, because pkcs11-tool cannot generate ML-DSA keys.
The private key is created CKA_SENSITIVE and non-CKA_EXTRACTABLE: it never
leaves the token, only signatures do (ADR-005).
"""
import json
import sys

import pkcs11
from pkcs11 import Attribute, KeyType
from pkcs11.mechanisms import MLDSAParameterSet
from pkcs11.util.ec import encode_named_curve_parameters

from sigil_pkcs11 import find_token

ML_DSA_SETS = {"ML-DSA-44": MLDSAParameterSet.ML_DSA_44, "ML-DSA-65": MLDSAParameterSet.ML_DSA_65, "ML-DSA-87": MLDSAParameterSet.ML_DSA_87}


def fail(error: str) -> None:
    json.dump({"ok": False, "error": error}, sys.stdout)
    sys.exit(1)


def templates(spec: dict, label: str, key_id: bytes) -> tuple[KeyType, dict, dict]:
    public = {Attribute.TOKEN: True, Attribute.LABEL: label, Attribute.ID: key_id, Attribute.VERIFY: True}
    private = {Attribute.TOKEN: True, Attribute.LABEL: label, Attribute.ID: key_id, Attribute.SIGN: True,
               Attribute.PRIVATE: True, Attribute.SENSITIVE: True, Attribute.EXTRACTABLE: False}
    family = spec["family"]
    if family == "ecdsa":
        public[Attribute.EC_PARAMS] = encode_named_curve_parameters(spec["parameter_set"])
        return KeyType.EC, public, private
    if family == "ml-dsa":
        public[Attribute.PARAMETER_SET] = ML_DSA_SETS[spec["parameter_set"]]
        return KeyType.ML_DSA, public, private
    raise KeyError(family)


def main() -> None:
    req = json.load(sys.stdin)
    spec = req["algorithm"]
    if spec.get("spec") != "v1":
        fail("UnsupportedAlgorithm")
    try:
        key_type, public, private = templates(spec, req["key_label"], bytes.fromhex(req["key_id"]))
    except KeyError:
        fail("UnsupportedAlgorithm")

    lib = pkcs11.lib(req["module"])
    token = find_token(lib, req["token_label"])
    with token.open(rw=True, user_pin=req["pin"]) as session:
        pub, _ = session.generate_keypair(key_type, public_template=public, private_template=private)
        json.dump({"ok": True, "public_key_len": len(pub[Attribute.VALUE if key_type == KeyType.ML_DSA else Attribute.EC_POINT])}, sys.stdout)


if __name__ == "__main__":
    try:
        main()
    except pkcs11.exceptions.MechanismInvalid:
        # The token does not implement this suite's mechanism.
        fail("UnsupportedAlgorithm")
    except Exception as e:  # noqa: BLE001 - type only, never the message (it may echo input)
        fail(type(e).__name__)
