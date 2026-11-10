#!/usr/bin/env sh
# phone-url - sh wrapper. Passes every argument through.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
exec php "$DIR/phone-url.php" "$@"