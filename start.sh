#!/usr/bin/env bash
set -euo pipefail

# TodiumX starter (Linux/macOS)
# Detecta PHP embutido em ./bin/php, configura SSL/OpenSSL e executa o PHAR ou source.

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

PHP_DIR="$ROOT_DIR/bin/php"
PHP_BIN="$PHP_DIR/php"
PHP_INI="$PHP_DIR/php.ini"
OPENSSL_CONF="$PHP_DIR/extras/ssl/openssl.cnf"
SSL_CERT_FILE="$PHP_DIR/extras/ssl/cacert.pem"

export OPENSSL_CONF
export SSL_CERT_FILE

# Ensure PHP directory is in PATH for any child processes
export PATH="$PHP_DIR:$PATH"

# Verify required files
for f in "$PHP_BIN" "$PHP_INI" "$OPENSSL_CONF" "$SSL_CERT_FILE"; do
    if [[ ! -f "$f" ]]; then
        echo "[ERROR] Missing file: $f" >&2
        exit 1
    fi
done

# Make sure php binary is executable
chmod +x "$PHP_BIN" 2>/dev/null || true

# Native library path (optional, for FFI)
export LD_LIBRARY_PATH="$PHP_DIR:$ROOT_DIR/bin/native:${LD_LIBRARY_PATH:-}"
export DYLD_LIBRARY_PATH="$ROOT_DIR/bin/native:${DYLD_LIBRARY_PATH:-}"

# PHAR or source entry
PHAR_FILE="$ROOT_DIR/TodiumX.phar"
SRC_ENTRY="$ROOT_DIR/src/raspberry/RaspberryX.php"

if [[ -f "$PHAR_FILE" ]]; then
    exec "$PHP_BIN" -c "$PHP_INI" -r "require 'phar://$PHAR_FILE/src/raspberry/RaspberryX.php';"
elif [[ -f "$SRC_ENTRY" ]]; then
    exec "$PHP_BIN" -c "$PHP_INI" "$SRC_ENTRY"
else
    echo "[ERROR] Neither TodiumX.phar nor source entry point found." >&2
    echo "Expected: $PHAR_FILE or $SRC_ENTRY" >&2
    exit 1
fi
