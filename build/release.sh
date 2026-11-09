#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

TARGET="${1:-}"
if [[ -z "$TARGET" ]]; then
  case "$(uname -s)" in
    Linux) TARGET="x86_64-unknown-linux-gnu" ;;
    Darwin) TARGET="x86_64-apple-darwin" ;;
    CYGWIN*|MINGW*|MSYS*) TARGET="x86_64-pc-windows-msvc" ;;
    *) echo "Unknown OS, specify target explicitly"; exit 1 ;;
  esac
fi

echo "Building native for target: $TARGET"

# Ensure Rust toolchain
if ! command -v cargo &> /dev/null; then
  echo "Cargo not found. Install Rust from https://rustup.rs"
  exit 1
fi

rustup target add "$TARGET" 2>/dev/null || true

# Build native
cd "$ROOT_DIR/native"
cargo build --release --target "$TARGET"

# Determine library name
case "$TARGET" in
  *-windows-*) LIB="raspberry_native.dll" ;;
  *-apple-darwin) LIB="libraspberry_native.dylib" ;;
  *) LIB="libraspberry_native.so" ;;
esac

BUILT="$ROOT_DIR/native/target/$TARGET/release/$LIB"
if [[ ! -f "$BUILT" ]]; then
  echo "Build artifact not found at $BUILT"
  exit 1
fi

echo "Native library built: $BUILT"

# Build PHAR (requires PHP with phar.readonly=0)
cd "$ROOT_DIR"
if command -v php &> /dev/null; then
  php -d phar.readonly=0 build/make-server.php
  PHAR="$ROOT_DIR/RaspberryX.phar"
  if [[ -f "$PHAR" ]]; then
    echo "PHAR built: $PHAR"
    # copy native lib into bin/native for distribution
    mkdir -p "$ROOT_DIR/bin/native"
    cp "$BUILT" "$ROOT_DIR/bin/native/"
    echo "Copied native lib to bin/native/"
  else
    echo "PHAR build failed (RaspberryX.phar not found)"
  fi
else
  echo "PHP not found; skipping PHAR build. Run 'composer make-server' on a machine with PHP 8.5+."
fi

echo "Done."
