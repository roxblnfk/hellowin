#!/usr/bin/env bash
# Rolls the stack out on the server. Forced command of the CI key in root's authorized_keys: takes a `git archive`
# tarball of master on stdin, so the server needs no access to GitHub. Installed as /usr/local/bin/hellowin-deploy;
# after changing this file, copy it there by hand.
set -euo pipefail

root=/opt/hellowin
exec 9>/run/hellowin-deploy.lock
flock 9

incoming=$(mktemp -d)
trap 'rm -rf "$incoming"' EXIT
tar -x -C "$incoming"
[ -f "$incoming/docker-compose.yml" ] || { echo 'no docker-compose.yml in the archive' >&2; exit 1; }

# .env is the server's own: ports, origins, secrets. In place: Centrifugo mounts its config as a single file, a new
# inode would stay unseen by the container.
changed=$(rsync -a --delete --inplace --checksum --exclude=/.env --itemize-changes "$incoming/" "$root/" | awk '{print $2}')

cd "$root"
docker compose up -d --build --remove-orphans

# Not `up --wait`: Compose 5 gives up on the first failed probe, while RoadRunner is still starting its workers.
wait_healthy() {
    for _ in $(seq 60); do
        docker compose ps --format '{{.Health}}' | grep -qv '^healthy$' || return 0
        sleep 2
    done
    echo 'the stack is not healthy after 2 minutes' >&2
    docker compose ps --format 'table {{.Service}}\t{{.Status}}' >&2
    return 1
}
wait_healthy

# Configs mounted from docker/ are read on start only.
for service in centrifugo prometheus grafana; do
    if grep -q "^docker/$service/" <<<"$changed"; then
        docker compose restart "$service"
    fi
done
wait_healthy

docker image prune -f >/dev/null
docker compose ps --format 'table {{.Service}}\t{{.Status}}'
