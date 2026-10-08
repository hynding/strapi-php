#!/usr/bin/env bash
# Fetches what tests/api needs: upstream strapi/strapi at the tracked tag (.upstream/) and a
# FrankenPHP binary (.bin/frankenphp). Both are cached; delete them to refetch.
set -euo pipefail
cd "$(dirname "$0")/.."

FRANKENPHP_VERSION="${FRANKENPHP_VERSION:-v1.13.0}"
TAG="v$(php -r 'echo preg_replace("/^(\d+\.\d+\.\d+).*/", "$1", json_decode(file_get_contents("../../packages/core/strapi/composer.json"), true)["version"]);')"

if [ -z "${STRAPI_UPSTREAM:-}" ] && [ ! -d .upstream/tests/api ]; then
  echo "Cloning strapi/strapi ${TAG} (tests/api, packages/utils/api-tests and the sources they import)"
  git clone --quiet --depth 1 --branch "$TAG" --filter=blob:limit=2m https://github.com/strapi/strapi .upstream
fi

if [ -z "${FRANKENPHP_BIN:-}" ] && [ ! -x .bin/frankenphp ]; then
  case "$(uname -s)-$(uname -m)" in
    Linux-x86_64) asset=frankenphp-linux-x86_64 ;;
    Linux-aarch64) asset=frankenphp-linux-aarch64 ;;
    Darwin-arm64) asset=frankenphp-mac-arm64 ;;
    Darwin-x86_64) asset=frankenphp-mac-x86_64 ;;
    *) echo "No FrankenPHP build for $(uname -s)-$(uname -m); set FRANKENPHP_BIN" >&2; exit 1 ;;
  esac
  mkdir -p .bin
  echo "Downloading FrankenPHP ${FRANKENPHP_VERSION} (${asset})"
  curl -fsSL -o .bin/frankenphp "https://github.com/php/frankenphp/releases/download/${FRANKENPHP_VERSION}/${asset}"
  chmod +x .bin/frankenphp
fi

[ -d node_modules ] || npm ci --no-audit --no-fund
