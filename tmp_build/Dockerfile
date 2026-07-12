# syntax=docker/dockerfile:1
#
# Throwaway build of the `php-modules` branch CLI (PHP 8.6-dev, debug, opcache
# built in) for linux/amd64. NOT part of the eventual pull request.
#
# From the php-src repo root (build context must be the repo, so the branch
# source is available):
#
#   docker build --platform=linux/amd64 -f tmp_build/Dockerfile -t php-modules .
#
# Run a script:
#   docker run --rm -v "$PWD":/work -w /work php-modules /work/tmp_build/sample.php
#
# Or extract a standalone x86_64 binary:
#   id=$(docker create php-modules); docker cp "$id":/src/sapi/cli/php ./php-modules-cli; docker rm "$id"
#   ./php-modules-cli -v

FROM --platform=linux/amd64 debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential autoconf bison re2c pkg-config git file ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY . /src
WORKDIR /src

# Strip any build artifacts copied from another arch (e.g. the arm64 sandbox),
# then reproduce the exact branch build configuration.
RUN (git clean -xfd >/dev/null 2>&1 || true) \
    && ./buildconf --force \
    && ./configure --enable-debug --disable-all --disable-cgi --disable-phpdbg \
    && make -j"$(nproc)" \
    && ./sapi/cli/php -v

ENTRYPOINT ["/src/sapi/cli/php"]
