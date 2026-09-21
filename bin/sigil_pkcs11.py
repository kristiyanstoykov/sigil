"""Shared PKCS#11 helpers for the Sigil drivers."""
import pkcs11


def find_token(lib: pkcs11.lib, label: str) -> pkcs11.Token:
    """Locate a token by label without touching the other slots.

    python-pkcs11's lib.get_token() queries every slot's mechanism list on the
    way, which fails outright on an uninitialised slot - and a kryoptic slot
    whose init was interrupted is exactly that. Compare labels only.
    """
    for slot in lib.get_slots(token_present=True):
        try:
            token = slot.get_token()
        except pkcs11.exceptions.PKCS11Error:
            continue
        if token.label == label:
            return token
    raise pkcs11.exceptions.NoSuchToken(label)
