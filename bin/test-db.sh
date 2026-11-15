#!/usr/bin/env bash
# Disposable Docker database; never reads development WordPress configuration.
set -euo pipefail
name=swsl-verified-updates-db
case "${1:-}" in
  start)
    if docker container inspect "$name" >/dev/null 2>&1; then
      docker start "$name" >/dev/null
    else
      docker run -d --name "$name" -p 127.0.0.1:33108:3306 \
        -e MARIADB_ROOT_PASSWORD=swsl-test-root -e MARIADB_DATABASE=swsl_wp_tests \
        -e MARIADB_USER=swsl -e MARIADB_PASSWORD=swsl mariadb:11.4 >/dev/null
    fi
    for attempt in {1..60}; do
      if docker exec "$name" mariadb-admin ping -uroot -pswsl-test-root --silent >/dev/null 2>&1; then
        echo 'Disposable database ready at 127.0.0.1:33108.'
        exit 0
      fi
      sleep 0.5
    done
    echo 'Disposable database did not become ready.' >&2
    exit 1
    ;;
  stop) docker stop "$name" ;;
  status) docker inspect --format '{{.State.Status}}' "$name" ;;
  *) echo 'Usage: bash bin/test-db.sh start|stop|status' >&2; exit 1 ;;
esac
