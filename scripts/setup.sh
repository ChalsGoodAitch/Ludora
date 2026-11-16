#!/usr/bin/env sh
set -eu
mkdir -p themes/ludora themes/ludora-child plugins/ludora-booking tests
docker compose up -d
docker compose run --rm cli core is-installed || docker compose run --rm cli core install --url="http://localhost:${LUDORA_HTTP_PORT:-8095}" --title="Ludora local demo" --admin_user=ludora-admin --admin_email=owner@example.test --skip-email --prompt=admin_password
docker compose run --rm cli plugin activate ludora-booking
docker compose run --rm cli theme activate ludora
docker compose run --rm cli rewrite structure '/%postname%/' --hard
printf '%s\n' 'Ready. Import the editable demo under Ludora > Demo import. No real payments configured.'
