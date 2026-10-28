#!/usr/bin/env bash
# Hands the certificate RoadRunner keeps in the acme-data volume to Centrifugo and Grafana: copies it to TLS_DIR and
# restarts them when it changed, as they read it on start only. Run by deploy.sh and daily by hellowin-certs.timer,
# RoadRunner renews the certificate a month before it expires. Does nothing without DOMAIN in .env.
set -euo pipefail

root=/opt/hellowin
cd "$root"
domain=$(sed -n 's/^DOMAIN=//p' .env | tr -d '"')
[ -n "$domain" ] || exit 0
tls=$(sed -n 's/^TLS_DIR=//p' .env | tr -d '"')
tls=${tls:-/opt/hellowin-tls}

volume=$(docker volume inspect -f '{{.Mountpoint}}' hellowin_acme-data)
# On the first start RoadRunner is still getting the certificate
for _ in $(seq 60); do
    # The staging and production endpoints keep their certificates apart: the latest one wins
    cert=$(ls -t "$volume"/certificates/*/"$domain/$domain.crt" 2>/dev/null | head -1 || true)
    [ -n "$cert" ] && break
    sleep 2
done
[ -n "$cert" ] || { echo "no certificate for $domain in $volume" >&2; exit 1; }

install -d -m 755 "$tls"
if cmp -s "$cert" "$tls/cert.pem" && cmp -s "${cert%.crt}.key" "$tls/key.pem"; then
    exit 0
fi
# Grafana and Centrifugo run as their own users
install -m 644 "$cert" "$tls/cert.pem"
install -m 644 "${cert%.crt}.key" "$tls/key.pem"
docker compose restart centrifugo grafana
