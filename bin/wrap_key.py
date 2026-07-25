#!/usr/bin/env python3
"""Sigil root-key wrapper driver (ADR-010).

Wraps / unwraps a per-user KEK using a non-exportable AES-256 secret key that
lives INSIDE a PKCS#11 token. The root wrapping key never leaves the token;
only the single KEK of one call ever crosses the boundary. Invoked by the
Symfony app (Pkcs11RootKeyWrapper) with a JSON request on stdin; emits a JSON
response on stdout. The token PIN and the KEK bytes travel only via that pipe
as base64 - never argv (world-readable in /proc), never disk.

Request:
  {"mode": "init" | "wrap" | "unwrap",
   "module": "/usr/lib/softhsm/libsofthsm2.so",
   "token_label": "sigil-root", "key_label": "root-kek-wrap", "pin": "...",
   "aad":  "<base64>",   # wrap/unwrap only - context bound into AES-GCM AAD
   "data": "<base64>"}   # wrap: raw KEK ; unwrap: nonce || ciphertext‖tag

Response:
  init:   {"ok": true, "created": true|false}
  wrap:   {"ok": true, "data": "<base64 nonce || ciphertext‖tag>"}
  unwrap: {"ok": true, "data": "<base64 raw KEK>"}
  error:  {"ok": false, "error": "<ExceptionClassName>"}

The AES key is generated non-exportable (SENSITIVE + not EXTRACTABLE), so the
token will only ever encrypt/decrypt with it - it cannot be read out. AES-GCM
authenticates and binds the AAD, so a wrapped KEK cannot be tampered with or
replayed under a different owner id.
"""
import base64
import json
import os
import sys

import pkcs11
from pkcs11 import Attribute, KeyType, Mechanism, ObjectClass
from pkcs11.exceptions import NoSuchKey
from pkcs11.mechanisms import GCMParams

NONCE_LEN = 12   # 96-bit GCM nonce (ADR-004)
TAG_BITS = 128   # 128-bit GCM tag


def fail(message: str) -> None:
    json.dump({"ok": False, "error": message}, sys.stdout)
    sys.exit(1)


def secret_key(session, label):
    return session.get_key(object_class=ObjectClass.SECRET_KEY,
                           key_type=KeyType.AES, label=label)


def main() -> None:
    req = json.load(sys.stdin)
    mode = req["mode"]
    if mode not in ("init", "wrap", "unwrap"):
        fail(f"unknown mode {mode!r}")

    lib = pkcs11.lib(req["module"])
    token = lib.get_token(token_label=req["token_label"])
    label = req["key_label"]

    with token.open(user_pin=req["pin"], rw=True) as session:
        if mode == "init":
            try:
                secret_key(session, label)
                created = False
            except NoSuchKey:
                session.generate_key(
                    KeyType.AES, 256,
                    label=label,
                    store=True,
                    template={
                        Attribute.TOKEN: True,
                        Attribute.PRIVATE: True,
                        Attribute.SENSITIVE: True,
                        Attribute.EXTRACTABLE: False,
                        Attribute.ENCRYPT: True,
                        Attribute.DECRYPT: True,
                    },
                )
                created = True
            json.dump({"ok": True, "created": created}, sys.stdout)
            return

        key = secret_key(session, label)
        aad = base64.b64decode(req["aad"])
        data = base64.b64decode(req["data"])

        if mode == "wrap":
            nonce = os.urandom(NONCE_LEN)
            ciphertext = key.encrypt(
                data, mechanism=Mechanism.AES_GCM,
                mechanism_param=GCMParams(nonce, aad, TAG_BITS))
            out = nonce + ciphertext
        else:  # unwrap
            nonce, ciphertext = data[:NONCE_LEN], data[NONCE_LEN:]
            out = key.decrypt(
                ciphertext, mechanism=Mechanism.AES_GCM,
                mechanism_param=GCMParams(nonce, aad, TAG_BITS))

        json.dump({"ok": True, "data": base64.b64encode(out).decode()}, sys.stdout)


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:  # noqa: BLE001 - boundary: report the type ONLY.
        # The message can echo input (PIN/key bytes) and is surfaced by the PHP
        # side; report only the class name (NoSuchKey, PinIncorrect, GCM tag
        # failure, ...) - never {exc}.
        fail(type(exc).__name__)
