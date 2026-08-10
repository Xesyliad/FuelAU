FROM eclipse-temurin:21-jre-noble@sha256:ca397720325ceefe39ce397f186759fc87d9efafb2dc4ce53315980844c2f4f2

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl util-linux zstd \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 photon \
    && useradd --uid 10001 --gid photon --home-dir /opt/photon --no-create-home --shell /usr/sbin/nologin photon \
    && mkdir -p /opt/photon \
    && chown photon:photon /opt/photon

ADD --checksum=sha256:a89707c0045e4807b2a1180e132e68e108d998709f48b6c94b98a6e281f571a5 \
    https://github.com/komoot/photon/releases/download/1.3.0/photon-1.3.0.jar \
    /opt/photon/photon.jar

COPY docker/photon/entrypoint.sh /usr/local/bin/fuelau-photon-entrypoint
COPY scripts/build-photon-index.sh /usr/local/bin/build-photon-index
COPY scripts/refresh-photon-index.sh /usr/local/bin/refresh-photon-index

RUN chmod 0555 \
        /usr/local/bin/fuelau-photon-entrypoint \
        /usr/local/bin/build-photon-index \
        /usr/local/bin/refresh-photon-index \
    && chmod 0444 /opt/photon/photon.jar

USER 10001:10001
WORKDIR /opt/photon

EXPOSE 2322
ENTRYPOINT ["fuelau-photon-entrypoint"]
CMD ["serve"]
