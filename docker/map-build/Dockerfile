FROM ghcr.io/onthegomap/planetiler@sha256:051fa6fa0db4f7a06e631297f9ac86435db9f9973bb96a8b067d533527b68871

RUN apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 util-linux \
    && rm -rf /var/lib/apt/lists/*

COPY scripts/build-map-atomically.sh /usr/local/bin/build-map-atomically
RUN chmod 0755 /usr/local/bin/build-map-atomically

ENTRYPOINT ["build-map-atomically"]
