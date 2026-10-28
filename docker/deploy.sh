#!/usr/bin/env bash
# Rolls the stack out on the server. Forced command of the CI key in root's authorized_keys: takes a `git archive`
# tarball of master on stdin, so the server needs no access to GitHub. Installed as /usr/local/bin/hellowin-deploy and
# reinstalls itself from every release, so a change here takes effect from the next deploy on.
set -euo pipefail

root=/opt/hellowin
exec 9>/run/hellowin-deploy.lock
flock 9

incoming=$(mktemp -d)
trap 'rm -rf "$incoming"' EXIT
tar -x -C "$incoming"
[ -f "$incoming/docker-compose.yml" ] || { echo 'no docker-compose.yml in the archive' >&2; exit 1; }

# .env is the server's own: ports, domain, origins, secrets. In place: Centrifugo mounts its config as a single file, a
# new inode would stay unseen by the container. Only files count as changed: directory times move with every commit.
changed=$(rsync -a --delete --inplace --checksum --exclude=/.env --itemize-changes "$incoming/" "$root/" \
    | awk '$1 ~ /^(>f|\*deleting)/ {print $2}')

cd "$root"
docker compose up -d --build --remove-orphans

install -m 755 docker/certs.sh /usr/local/bin/hellowin-certs
install -m 644 docker/systemd/hellowin-certs.service docker/systemd/hellowin-certs.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now hellowin-certs.timer
/usr/local/bin/hellowin-certs

# Configs mounted from docker/ are read on start only.
for service in centrifugo prometheus grafana; do
    if grep -q "^docker/$service/" <<<"$changed"; then
        docker compose restart "$service"
    fi
done

# Not `up --wait`: Compose 5 gives up on the first failed probe, while RoadRunner is still starting its workers.
healthy=
for _ in $(seq 60); do
    docker compose ps --format '{{.Health}}' | grep -qv '^healthy$' || { healthy=1; break; }
    sleep 2
done

docker image prune -f >/dev/null
docker compose ps --format 'table {{.Service}}\t{{.Status}}'
# Last: bash keeps reading the file it started with, install puts a new one in its place
install -m 755 docker/deploy.sh /usr/local/bin/hellowin-deploy
[ -n "$healthy" ] || { echo 'the stack is not healthy after 2 minutes' >&2; exit 1; }
