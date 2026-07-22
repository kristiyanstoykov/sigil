#!/bin/sh
# Generate the dev TSA key + self-signed cert (once, into the /tsa volume), then
# run the responder. The cert carries the CRITICAL timeStamping EKU that RFC 3161
# requires; without it validators reject the token. Dev-only, never committed.
set -e

cd /tsa

if [ ! -f tsa.key ] || [ ! -f tsa.crt ]; then
    echo "[tsa] generating dev TSA key + cert…"
    openssl ecparam -name prime256v1 -genkey -noout -out tsa.key
    openssl req -new -key tsa.key -subj "/O=Sigil Labs/CN=Sigil Dev TSA" -out tsa.csr
    cat > ext.cnf <<'EOF'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature
extendedKeyUsage=critical,timeStamping
EOF
    openssl x509 -req -in tsa.csr -signkey tsa.key -days 3650 -out tsa.crt -extfile ext.cnf
    rm -f tsa.csr ext.cnf
fi

[ -f tsa_serial ] || echo 01 > tsa_serial

echo "[tsa] responder listening on :8318"
exec python3 /tsa-src/tsa_server.py
