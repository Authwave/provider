#!/bin/sh
set -eu

mkdir -p /run/nsd /var/lib/nsd
chown nsd:nsd /run/nsd /var/lib/nsd
# A container may restart after an unclean exit; neither file is persistent state.
rm -f /run/nsd/control.sock /run/nsd/nsd.pid
nsd-checkconf /etc/nsd/nsd.conf
nsd-checkzone validation.example /etc/nsd/shared.zone
exec nsd -d -c /etc/nsd/nsd.conf
