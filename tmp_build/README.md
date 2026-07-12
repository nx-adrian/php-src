# tmp_build — throwaway build helpers for the `php-modules` branch

Convenience only, **not part of the eventual pull request** — this directory exists so
the branch's CLI can be built and played with easily. It builds `sapi/cli/php` (a debug
build, everything disabled except the defaults, opcache compiled in statically so the
module opcache/preload behavior can be exercised). No prebuilt binary is committed: a
Linux binary is architecture-specific and would just bloat the repo, so this ships the
recipe instead.

## Option A — native build (Linux x86_64, or any host with a toolchain)

From the `php-src` repo root:

```sh
sh tmp_build/build.sh
```

Produces `./sapi/cli/php`. Requires a C toolchain plus `autoconf`, `bison`, `re2c`,
`pkg-config`:

- Debian/Ubuntu: `sudo apt-get install build-essential autoconf bison re2c pkg-config`
- Fedora: `sudo dnf install gcc make autoconf bison re2c pkgconf`

If the tree has build artifacts from another architecture, run `CLEAN=1 sh tmp_build/build.sh`.

## Option B — Docker (produces a linux/amd64 binary)

From the `php-src` repo root (build context must be the repo):

```sh
docker build --platform=linux/amd64 -f tmp_build/Dockerfile -t php-modules .

# run a script:
docker run --rm -v "$PWD":/work -w /work php-modules /work/tmp_build/sample.php

# or extract a standalone binary:
id=$(docker create php-modules)
docker cp "$id":/src/sapi/cli/php ./php-modules-cli
docker rm "$id"
./php-modules-cli -v
```

## Try it

```sh
./sapi/cli/php tmp_build/sample.php
```

`sample.php` tours the branch: `module` declarations, `public`/`internal` visibility,
member classes, static functions/properties/constants, `module::` self-references,
chained `Module::Class::member`, `ReflectionModule`, and the enforced boundary. Expected
output ends with each out-of-module access reported as `blocked (...)`.

To exercise **preload** (all module metadata is CE-resident, so a preloaded module
enforces `internal` per request):

```sh
./sapi/cli/php -d opcache.enable_cli=1 -d opcache.preload="$PWD/Zend/tests/modules/module_029_preload.inc" \
  -r 'echo (new ReflectionModule("Vendor\\App"))->getName(), "\n";'
```
