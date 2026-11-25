# Authwave authoritative DNS

This directory builds NSD in Docker. Every registered customer hostname uses
`shared.zone`, through the `authwave` pattern. Customer hostnames still need to
be registered explicitly; this is not a catch-all DNS server or a recursive resolver.

The configuration and zone template are in Git. Dynamically registered domains
are stored in the `nsd-state` Docker volume at `/var/lib/nsd/zone.list` and survive
restarts, image rebuilds, and container recreation. The volume belongs to the
`authwave-dns` Compose project; keep that project name when deploying updates.

## Start on the server

Install Docker Engine and the Docker Compose plugin, then run from the checkout:

```sh
cd /path/to/provider/container/nsd
docker compose build
```

Before building, review `shared.zone`: the A record currently targets
`67.207.70.225`; the confirmation TXT value must match the provider's
`authwave.domain_confirmation` setting (currently `dev-2611`). The SOA serial
must be increased whenever you change the shared records.

Optionally copy `.env.example` to `.env` to choose a host bind address. The
default publishes on all IPv4 addresses. On a host using systemd-resolved, its
loopback DNS listener can conflict with this; bind to the server's local public
or private interface address instead. For a forwarded/reserved public IP, use
the local address that receives that traffic.

When ready to replace native NSD, run as root (or use sudo):

```sh
systemctl enable --now docker.service
systemctl disable --now nsd.service
docker compose up -d
docker compose ps
docker compose logs --tail=50 nsd
```

`up -d` starts the container in the background. `restart: unless-stopped` restarts
it after failure and after a server reboot once Docker starts. If you explicitly
stop it, run `docker compose up -d` to enable it again. A failed health check is
reported but does not itself trigger a Docker restart.

Allow inbound **UDP and TCP port 53** in the server and cloud firewalls. The
control socket is internal to the container; no administrative TCP port is
published. Keep `ns1.authwave.com` and `ns2.authwave.com` pointed at the address
that routes to this DNS server. Both currently use the same server, so this
setup does not provide a second independent nameserver.

The initial zone list is empty. Native `/etc/nsd` configuration is not imported.
Register every hostname you want to continue serving, for example:

```sh
docker compose exec -T nsd nsd-control addzone login.api.horse authwave
docker compose exec -T nsd nsd-control addzone login.hexform.io authwave
docker compose exec -T nsd nsd-control addzone dev-test.authwave.com authwave
```

Only register customer login zones here. The shared template is not a replacement
for the parent `authwave.com` zone or dedicated nameserver address records.

Confirm readiness with real DNS queries (zone additions are queued, so retry
briefly if necessary):

```sh
dig @ns1.authwave.com login.api.horse A +norecurse
dig @ns2.authwave.com login.api.horse SOA +tcp +norecurse
dig @ns1.authwave.com authwave-confirmation.login.api.horse TXT +norecurse
dig @1.1.1.1 login.api.horse A
```

The authoritative replies should show `NOERROR` and `aa`. Expect the A address
and confirmation marker from `shared.zone`. Existing cached failures at public
resolvers can take time to expire. DNS provisioning does not configure nginx,
obtain a TLS certificate, or bind a hostname to an Authwave customer account.

Once the replacement is working, `apt remove nsd` removes the host package;
the container has its own installation. No server changes are made by these files.

## Domain lifecycle

Run these commands from this directory:

```sh
docker compose exec -T nsd nsd-control addzone login.example.com authwave
docker compose exec -T nsd nsd-control zonestatus login.example.com
docker compose exec -T nsd nsd-control delzone login.example.com
```

No restart is required. `addzone` persists the registration in the volume.
`delzone` removes it persistently. Repeated provisioning should treat an existing
zone with the expected `authwave` pattern as success; do not ignore all errors.
After deletion, cached answers can remain until their TTL expires.

For configuration or shared-record changes, increase the SOA serial if records
changed, then rebuild and recreate:

```sh
docker compose up -d --build
```

The existing volume restores the registered domains, using the new shared file.
`docker compose down` keeps the volume; **`docker compose down -v` deletes it**.
Do not edit `zone.list` while NSD is running. The shared template is suitable for
identical records; per-customer TXT records or DNS-01 certificate challenges need
a different design with separate writable zone files and serial management.

## Deployment domain provisioning

Saving a deployment now runs `nsd-control addzone <hostname> authwave` inline,
using its Authwave host. Removing a deployment runs `delzone <hostname>` if no
remaining deployment uses that hostname. Editing a host adds its replacement
and removes the previous zone when it is no longer referenced. These commands
run after the database transaction commits.

Before each operation, the provider checks whether the Compose `nsd` service is
running. If Docker is unavailable or the service is stopped, provisioning is
skipped with a warning in the PHP application log, allowing local development
without Docker. The helper prints the reason and exits with status 3. There is
no queue, worker or additional migration. Skipped operations are not retried
automatically; save a deployment again to provision its current hostname.

Hostnames are lowercased, with optional ports and trailing dots removed. IPs,
local/test names and the reserved Authwave nameserver zones are skipped. DNS
command failures from a running service are reported; the database change has
already committed. Adding an existing zone accepts only the `authwave` pattern;
removing an absent zone is harmless.

PHP calls `bin/authwave-dns`, which elevates itself with `sudo -n`. Add the
sudoers rule documented at the top of that script using `visudo`. Keep the helper
executable and the checkout unwritable by `www-data`; no Docker group membership
is needed. The helper targets the fixed container `authwave-dns-nsd-1`. Missing
sudo permission is reported as an error rather than silently skipping provisioning. A host change skipped while
NSD is stopped requires manual removal of the old zone, as does a skipped deletion.

Verify domain still checks the delegation and confirmation records. Provisioning
DNS does not configure web routing or HTTPS.

## Local development

Use a different project and a high port to avoid the host's DNS service:

```sh
DNS_BIND_IP=127.0.0.1 DNS_PORT=1053 docker compose -p authwave-dns-dev up -d --build
docker compose -p authwave-dns-dev exec -T nsd nsd-control addzone login.example.com authwave
dig @127.0.0.1 -p 1053 login.example.com A
docker compose -p authwave-dns-dev down
```

## References

- [NSD configuration and patterns](https://nsd.docs.nlnetlabs.nl/en/latest/manpages/nsd.conf.html)
- [NSD control commands and persistent zone registration](https://nsd.docs.nlnetlabs.nl/en/latest/manpages/nsd-control.html)
- [Docker restart policies](https://docs.docker.com/engine/containers/start-containers-automatically/)
