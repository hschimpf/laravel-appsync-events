#!/usr/bin/env bash
# Stops the AWS Emulator container started by start.sh.

set -euo pipefail

CONTAINER="laravel-appsync-events-emulator"

if docker ps --format '{{.Names}}' | grep -qx "${CONTAINER}"; then
    docker stop "${CONTAINER}" >/dev/null
    echo "Stopped ${CONTAINER}."
else
    echo "Container ${CONTAINER} is not running."
fi
