#!/usr/bin/env python3
"""Slot registry for the kryoptic PKCS#11 module (ADR-014).

Kryoptic has no notion of a free slot: every token is a `[[slots]]` block in
the TOML file KRYOPTIC_CONF points at, with its own SQLite file. Sigil creates
a token per certificate, so this script is the one place that file is edited:

    kryoptic_slots.py add <label>      -> prints the slot id it allocated
    kryoptic_slots.py remove <label>   -> drops the block and its database
    kryoptic_slots.py list             -> JSON {label: slot}

Edits happen under an exclusive lock and are written atomically. Every PKCS#11
process re-reads the file at C_Initialize, so nothing has to be restarted.
The block's `description` is the label; the token label proper is set by
`pkcs11-tool --init-token` afterwards.
"""
import fcntl
import json
import os
import sys
import tomllib

CONF = os.environ.get("KRYOPTIC_CONF", "/var/lib/kryoptic/token.conf")
# DER-wrapped CKA_EC_POINT, which is what SoftHSM returned and issue_cert.py expects.
HEADER = '[ec_point_encoding]\nencoding = "Der"\n'


def tokens_dir() -> str:
    return os.path.join(os.path.dirname(CONF), "tokens")


def load() -> list[dict]:
    if not os.path.exists(CONF):
        return []
    with open(CONF, "rb") as fh:
        return tomllib.load(fh).get("slots", [])


def dump(slots: list[dict]) -> None:
    out = [HEADER]
    for s in slots:
        out.append(
            "\n[[slots]]\n"
            f"slot = {int(s['slot'])}\n"
            f"description = {json.dumps(s['description'])}\n"
            'dbtype = "sqlite"\n'
            f"dbargs = {json.dumps(s['dbargs'])}\n"
        )
    tmp = CONF + ".tmp"
    with open(tmp, "w") as fh:
        fh.write("".join(out))
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, CONF)


def add(label: str) -> int:
    slots = load()
    if any(s["description"] == label for s in slots):
        raise SystemExit(f"slot for {label!r} already exists")
    slot_id = max((int(s["slot"]) for s in slots), default=0) + 1
    os.makedirs(tokens_dir(), mode=0o750, exist_ok=True)
    slots.append({"slot": slot_id, "description": label, "dbargs": os.path.join(tokens_dir(), f"{label}.sql")})
    dump(slots)
    return slot_id


def remove(label: str) -> None:
    slots = load()
    keep = [s for s in slots if s["description"] != label]
    for s in slots:
        if s["description"] == label:
            try:
                os.remove(s["dbargs"])
            except FileNotFoundError:
                pass
    dump(keep)


def main(argv: list[str]) -> int:
    if len(argv) < 2 or argv[1] not in ("add", "remove", "list"):
        print(__doc__, file=sys.stderr)
        return 2
    os.makedirs(os.path.dirname(CONF), mode=0o750, exist_ok=True)
    with open(CONF + ".lock", "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if argv[1] == "list":
            print(json.dumps({s["description"]: int(s["slot"]) for s in load()}))
        elif argv[1] == "add":
            print(add(argv[2]))
        else:
            remove(argv[2])
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
