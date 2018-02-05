#!/bin/sh

set -e

until mysql --user=webmention --password=webmention --host=db --execute="\q"; do
	>&2 echo "Database is not available yet; waiting 2 seconds"
	sleep 2
done
>&2 echo "Database is available"

bundle exec rake db:bootstrap
/app/start.sh
