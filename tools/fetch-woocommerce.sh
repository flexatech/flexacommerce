#!/usr/bin/env bash
# Downloads the WooCommerce release the migration tests run against
# (tests/phpunit-woocommerce.xml.dist) into tests/.cache/woocommerce.
# FXC_WOOCOMMERCE_DIR points the tests at another copy instead.
set -euo pipefail

VERSION="${1:-11.2.1}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/tests/.cache"

if [[ -f "$DEST/woocommerce/woocommerce.php" ]] && grep -q "Version: $VERSION\$" "$DEST/woocommerce/woocommerce.php"; then
	echo "WooCommerce $VERSION is already in $DEST/woocommerce"
	exit 0
fi

mkdir -p "$DEST"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
curl -fsSL "https://downloads.wordpress.org/plugin/woocommerce.$VERSION.zip" -o "$TMP/woocommerce.zip"
unzip -q "$TMP/woocommerce.zip" -d "$TMP"
rm -rf "$DEST/woocommerce"
mv "$TMP/woocommerce" "$DEST/woocommerce"
echo "WooCommerce $VERSION in $DEST/woocommerce"
