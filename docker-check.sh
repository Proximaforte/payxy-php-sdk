#!/usr/bin/env sh
# Runs the quality gate in Docker, so no local PHP is needed.
# Style + static analysis run once on the newest PHP; the test suite runs on every
# supported version against dependencies resolved for that version.
set -eu

cd "$(dirname "$0")"
VERSIONS="${PHP_VERSIONS:-8.2 8.3 8.4 8.5}"
NEWEST="$(echo "$VERSIONS" | tr ' ' '\n' | tail -n 1)"

for v in $VERSIONS; do
  echo "==> PHP $v"
  vol="payxy-sdk-php$(echo "$v" | tr -d .)"

  # Resolve dependencies for this PHP version in an isolated copy (never touches ./vendor).
  docker run --rm -v "$PWD":/src:ro -v "$vol":/work -w /work composer:2 sh -euc "
    find /work -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf {} +
    cp -r /src/src /src/tests /src/config /src/resources /src/composer.json /src/phpunit.xml.dist \
          /src/phpstan.neon.dist /src/.php-cs-fixer.dist.php /work/
    composer config platform.php $v.0
    composer update --no-interaction --no-progress -q"

  docker run --rm -v "$vol":/work -w /work "php:$v-cli" vendor/bin/phpunit --no-progress

  if [ "$v" = "$NEWEST" ]; then
    docker run --rm -v "$vol":/work -w /work -e PHP_CS_FIXER_IGNORE_ENV=1 "php:$v-cli" sh -euc "
      vendor/bin/php-cs-fixer fix --dry-run --diff
      vendor/bin/phpstan analyse --no-progress --memory-limit=1G"
  fi
done

echo "All checks passed."
