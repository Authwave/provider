FROM debian:trixie-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends nsd \
    && rm -rf /var/lib/apt/lists/*

COPY nsd.conf /etc/nsd/nsd.conf
COPY shared.zone /etc/nsd/shared.zone
COPY entrypoint.sh /usr/local/bin/authwave-nsd-entrypoint
RUN chmod 755 /usr/local/bin/authwave-nsd-entrypoint

EXPOSE 53/udp 53/tcp
ENTRYPOINT ["/usr/local/bin/authwave-nsd-entrypoint"]
