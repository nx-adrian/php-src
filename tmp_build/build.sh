#!/bin/sh
# Build the `php-modules` branch CLI natively (Linux x86_64 or any host with a
# working toolchain). Run from anywhere; it operates on the php-src repo root.
#
#   sh tmp_build/build.sh
#
# Produces ./sapi/cli/php. Reproduces the exact configuration used for the branch:
# debug build, everything disabled except the defaults, opcache built in
# statically (so the opcache/preload module behavior can be exercised).
#
# Requires: a C toolchain (build-essential), autoconf, bison, re2c, pkg-config.
#   Debian/Ubuntu: sudo apt-get install build-essential autoconf bison re2c pkg-config
#   Fedora:        sudo dnf install gcc make autoconf bison re2c pkgconf
set -eu

# cd to the repo root (parent of this script's directory).
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

echo ">> repo: $(pwd)"

# If artifacts from another architecture are present (e.g. copied from an arm64
# box), clear them first. Guarded so a normal same-arch rebuild is incremental.
if [ "${CLEAN:-0}" = "1" ]; then
    echo ">> git clean -xfd (CLEAN=1)"
    git clean -xfd
fi

JOBS="$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 4)"

echo ">> buildconf --force"
./buildconf --force

echo ">> configure"
./configure --enable-debug --disable-all --disable-cgi --disable-phpdbg

echo ">> make -j${JOBS}"
make -j"${JOBS}"

echo
echo ">> built: $(pwd)/sapi/cli/php"
./sapi/cli/php -v
echo
echo ">> try it:  ./sapi/cli/php tmp_build/sample.php"
