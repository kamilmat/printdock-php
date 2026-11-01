#!/bin/sh
# Runs the MySQL variants of the adapter tests against a throw-away MySQL 8 container on its own network.
# Usage: tests/run-mysql.sh <dev-image> <uid:gid>   (called by `make test-mysql`)
set -eu

IMAGE="$1"
USER_ID="$2"
NET=printdock-test-net
DB=printdock-test-mysql

cleanup() {
    docker rm -f "$DB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MYSQL_ROOT_PASSWORD=pap -e MYSQL_DATABASE=pap mysql:8.4 >/dev/null

tries=0
until docker exec "$DB" mysql -h127.0.0.1 -uroot -ppap -e 'SELECT 1' pap >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -gt 60 ]; then
        echo "MySQL did not become ready" >&2
        exit 1
    fi
    sleep 2
done

docker run --rm --user "$USER_ID" --network "$NET" -e HOME=/tmp \
    -e "PRINTDOCK_TEST_MYSQL_DSN=mysql:host=$DB;dbname=pap;charset=utf8mb4" \
    -e PRINTDOCK_TEST_MYSQL_USER=root -e PRINTDOCK_TEST_MYSQL_PASSWORD=pap \
    -v "$(pwd):/app" -w /app "$IMAGE" vendor/bin/phpunit --filter Mysql
