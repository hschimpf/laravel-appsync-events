#!/usr/bin/env bash
#
# Starts the AWS Emulator (https://github.com/aws/aws-emulator) in Docker for
# local AppSync Events testing.
#
# Port notes (verified against aws-emulator source, vendor/server):
#   - The control plane (AWS APIs + /__admin/health) listens on -p/--port,
#     default 9297, overridable via EMULATOR_PORT. The same value must be
#     used on both sides of `docker -p` and the emulator's own `-p`,
#     because the emulator writes its port into the files it creates
#     (~/.aws/emulator/<port>/).
#   - Every AppSync Event API gets TWO extra data-plane sockets (one HTTP
#     publish endpoint, one WebSocket realtime endpoint) opened when
#     CreateApi runs. Their ports are OS-assigned UNLESS
#     EMULATOR_LISTENER_PORT_RANGE=LOW-HIGH is set, which confines them to
#     that inclusive range so Docker can publish them deterministically.
#     We reserve 9400-9419 by default (room for ~10 Event APIs, verified
#     free on this machine via `ss -ltn`) and publish the same range.
#     Override via EMULATOR_LISTENER_PORT_RANGE.
#   - With "-b 0.0.0.0" the listeners bind the wildcard address inside the
#     container, but the api.dns.{HTTP,REALTIME} values returned by
#     CreateApi are still reported as "127.0.0.1:<port>" (a wildcard
#     authority is published as loopback), and the Host-header check
#     accepts any Host on a wildcard bind. From the host,
#     127.0.0.1:<published port> reaches the right socket, so no
#     --network host is needed.
#   - The data-plane sockets sniff the first byte: both TLS (https/wss)
#     and plaintext (http/ws) work on the same port.
#
# The emulator writes ca.pem, emulator_credentials and config under
# ~/.aws/emulator/<port>/ inside the container; that dir is bind-mounted to
# tests/emulator/.runtime/ so the host can read them.

set -euo pipefail

IMAGE="laravel-appsync-events-emulator"
CONTAINER="laravel-appsync-events-emulator"
PORT="${EMULATOR_PORT:-9297}"
LISTENER_PORT_RANGE="${EMULATOR_LISTENER_PORT_RANGE:-9400-9419}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR="${SCRIPT_DIR}/.runtime"
mkdir -p "${RUNTIME_DIR}"

# Fail fast if any port we are about to publish is already taken on the host.
ports_in_use=""
for port in "${PORT}" $(seq "${LISTENER_PORT_RANGE%-*}" "${LISTENER_PORT_RANGE#*-}"); do
    if ss -ltn | awk '{print $4}' | grep -qE "[:.]${port}\$|^${port}\$"; then
        ports_in_use="${ports_in_use} ${port}"
    fi
done
if [[ -n "${ports_in_use}" ]]; then
    echo "Refusing to start: port(s)${ports_in_use} already in use on the host." >&2
    echo "Override with EMULATOR_PORT / EMULATOR_LISTENER_PORT_RANGE." >&2
    exit 1
fi

if ! docker image inspect "${IMAGE}" >/dev/null 2>&1; then
    echo "Building ${IMAGE}..." >&2
    docker build -t "${IMAGE}" "${SCRIPT_DIR}"
fi

if docker ps --format '{{.Names}}' | grep -qx "${CONTAINER}"; then
    echo "Container ${CONTAINER} is already running." >&2
else
    docker run -d --rm \
        --name "${CONTAINER}" \
        --memory=2g --memory-swap=2g \
        --user "$(id -u):$(id -g)" \
        -e HOME=/home/emulator \
        -e EMULATOR_LISTENER_PORT_RANGE="${LISTENER_PORT_RANGE}" \
        -p "127.0.0.1:${PORT}:${PORT}" \
        -p "127.0.0.1:${LISTENER_PORT_RANGE}:${LISTENER_PORT_RANGE}" \
        -v "${RUNTIME_DIR}:/home/emulator/.aws/emulator" \
        "${IMAGE}" -p "${PORT}" --telemetry off --idle-timeout 3600 >/dev/null
fi

CA_FILE="${RUNTIME_DIR}/${PORT}/ca.pem"
CREDENTIALS_FILE="${RUNTIME_DIR}/${PORT}/emulator_credentials"

# Wait for the certificate and credentials files, then for the health route.
for _ in $(seq 1 60); do
    [[ -f "${CA_FILE}" && -f "${CREDENTIALS_FILE}" ]] && break
    sleep 0.5
done
for _ in $(seq 1 60); do
    if curl -sf --cacert "${CA_FILE}" "https://127.0.0.1:${PORT}/__admin/health" >/dev/null 2>&1; then
        break
    fi
    sleep 0.5
done

if ! curl -sf --cacert "${CA_FILE}" "https://127.0.0.1:${PORT}/__admin/health" >/dev/null 2>&1; then
    echo "Emulator did not become healthy; see: docker logs ${CONTAINER}" >&2
    exit 1
fi

AWS_ACCESS_KEY_ID="$(awk -F' *= *' '/aws_access_key_id/{print $2}' "${CREDENTIALS_FILE}")"
AWS_SECRET_ACCESS_KEY="$(awk -F' *= *' '/aws_secret_access_key/{print $2}' "${CREDENTIALS_FILE}")"

cat <<EOF
Emulator is healthy on https://127.0.0.1:${PORT} (data-plane ports: ${LISTENER_PORT_RANGE}).

Export these to point the AWS CLI/SDKs at it:

export AWS_ENDPOINT_URL="https://127.0.0.1:${PORT}"
export AWS_CA_BUNDLE="${CA_FILE}"
export AWS_ACCESS_KEY_ID="${AWS_ACCESS_KEY_ID}"
export AWS_SECRET_ACCESS_KEY="${AWS_SECRET_ACCESS_KEY}"
export AWS_REGION="us-east-1"
EOF
