FROM ghcr.io/onthegomap/planetiler@sha256:db66b104ad08a7cde33ebbb9f371ab0ed2dbe62dc1daeb7a5179709257595e54

RUN apt-get update \
    && apt-get install -y --no-install-recommends sqlite3 util-linux \
    && rm -rf /var/lib/apt/lists/*

COPY scripts/build-map-atomically.sh /usr/local/bin/build-map-atomically
RUN chmod 0755 /usr/local/bin/build-map-atomically

ENTRYPOINT ["build-map-atomically"]
