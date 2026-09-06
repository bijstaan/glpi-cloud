#!/bin/sh
# The suites that need nothing but PHP.
#
#   glpi-cloud/tests/run.sh
#
# registry.php covers the provider contract — what is accepted, what is dropped,
# and what is said about it — normalise.php covers the checksum, which is the
# single decision that says whether a sweep writes anything at all, and
# projection.php covers the type map and its narrowing, whose failure mode is
# projecting nothing while looking switched on. None boots GLPI, touches a
# database, or contacts anything.
#
# tests/sync.php is NOT run here. It boots GLPI and writes to the plugin's own
# tables, so it is left for the maintainer to run deliberately:
#
#   docker compose -p glpi exec glpi php /var/www/glpi/plugins/glpicloud/tests/sync.php
set -e

cd "$(dirname "$0")/.."

status=0
php tests/registry.php  || status=1
php tests/normalise.php || status=1
php tests/projection.php || status=1

exit $status
