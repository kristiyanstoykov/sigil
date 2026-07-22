#!/usr/bin/env python3
"""Sigil DEV local RFC-3161 timestamp responder.

A tiny HTTP bridge to `openssl ts`: reads a DER TimeStampReq from the POST body,
produces a TimeStampResp signed by the dev TSA key, and returns it. Exists only
to give fast (~sub-100ms), offline PAdES-B-T timestamps in development - the
pluggable TSA backend `local_uts` points here. Not a trusted authority.
"""
import os
import subprocess
import tempfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CONF = "/tsa-src/tsa.cnf"
CERT = "/tsa/tsa.crt"
KEY = "/tsa/tsa.key"
PORT = 8318


class Handler(BaseHTTPRequestHandler):
    def do_POST(self) -> None:  # noqa: N802 - http.server API
        length = int(self.headers.get("Content-Length", 0))
        request_der = self.rfile.read(length)

        with tempfile.TemporaryDirectory() as tmp:
            query = os.path.join(tmp, "request.tsq")
            reply = os.path.join(tmp, "reply.tsr")
            with open(query, "wb") as fh:
                fh.write(request_der)

            result = subprocess.run(
                ["openssl", "ts", "-reply", "-config", CONF, "-queryfile", query,
                 "-signer", CERT, "-inkey", KEY, "-out", reply],
                capture_output=True,
            )
            if result.returncode != 0 or not os.path.exists(reply):
                self.send_response(500)
                self.end_headers()
                self.wfile.write(result.stderr)
                return

            with open(reply, "rb") as fh:
                response_der = fh.read()

        self.send_response(200)
        self.send_header("Content-Type", "application/timestamp-reply")
        self.send_header("Content-Length", str(len(response_der)))
        self.end_headers()
        self.wfile.write(response_der)

    def do_GET(self) -> None:  # noqa: N802 - health check
        self.send_response(200)
        self.send_header("Content-Type", "text/plain")
        self.end_headers()
        self.wfile.write(b"Sigil dev TSA (local_uts) OK\n")

    def log_message(self, *args: object) -> None:  # silence per-request logging
        pass


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
