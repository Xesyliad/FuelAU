FROM ghcr.io/onthegomap/planetiler@sha256:2085f8b965e47bd922f91e1c9d7faca371eb844a4928b52ce002710c395bbd03

RUN apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 util-linux \
    && rm -rf /var/lib/apt/lists/*

COPY scripts/build-map-atomically.sh /usr/local/bin/build-map-atomically
RUN chmod 0755 /usr/local/bin/build-map-atomically

ENTRYPOINT ["build-map-atomically"]
