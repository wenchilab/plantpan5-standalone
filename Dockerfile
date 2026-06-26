# syntax=docker/dockerfile:1.6
# PlantPAN 5 -- Offline Edition. Multi-stage:
#   Stage 1: assembles PUBLIC payload only (PWM + slim metadata + match).
#   Stage 2: php:8.2-apache + payload.

FROM debian:bookworm-slim AS builder
WORKDIR /payload
COPY bin/match                                          /payload/bin/match
COPY data/PlantPAN3_v2_matrix_with_TF.dat               /payload/data/PlantPAN3_v2_matrix_with_TF.dat
COPY data/PlantPAN3_v2_matrix_with_TF.dat.minFP.prf     /payload/data/PlantPAN3_v2_matrix_with_TF.dat.minFP.prf
COPY data/motif_family.json                             /payload/data/motif_family.json
COPY data/pattern_seq_uniprot_place.dat                 /payload/data/pattern_seq_uniprot_place.dat
COPY data/pattern_seq_uniprot_place.dat.minFP.prf       /payload/data/pattern_seq_uniprot_place.dat.minFP.prf
COPY data/place_meta.json                               /payload/data/place_meta.json
# Optional bundled extension libraries (Step 26). build.sh guarantees
# data/libraries/ exists (.gitkeep) so this COPY never fails when empty.
# .dockerignore restricts the context to the 4 recognised library filenames.
COPY data/libraries/                                    /payload/data/libraries/
RUN chmod +x /payload/bin/match

FROM php:8.2-apache
LABEL org.opencontainers.image.description="PlantPAN 5 offline promoter scan (no curated annotation)"

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates libstdc++6 gawk curl \
        libzip-dev unzip \
 && docker-php-ext-configure zip \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

RUN { \
      echo 'memory_limit = 2G'; \
      echo 'post_max_size = 110M'; \
      echo 'upload_max_filesize = 110M'; \
      echo 'max_input_time = 600'; \
      echo 'max_execution_time = 1800'; \
    } > /usr/local/etc/php/conf.d/plantpan.ini

COPY --from=builder /payload/bin/match  /opt/plantpan/bin/match
COPY --from=builder /payload/data/      /opt/plantpan/data/

# PHP application. NOTE: COPY --chmod sets file mode but NOT directory mode.
# WSL build contexts often have dirs at 0700, blocking apache (www-data) from
# traversing into /var/www/html/includes/. The chmod -R a+rX after COPY makes
# every dir traversable + every file readable.
COPY app/ /var/www/html/

RUN chmod -R a+rX /var/www/html && \
    chmod 0755 /var/www/html/bin/plantpan-scan && \
    ln -s /var/www/html/bin/plantpan-scan /usr/local/bin/plantpan-scan && \
    mkdir -p /tmp/plantpan && chmod 1777 /tmp/plantpan && \
    mkdir -p /var/www/html/output && \
    chown www-data:www-data /var/www/html/output && \
    chmod 0775 /var/www/html/output

# /opt/plantpan: read-only for www-data; dirs 0755, files 0644, match 0755.
RUN chown -R root:www-data /opt/plantpan && \
    find /opt/plantpan -type d -exec chmod 0755 {} \; && \
    find /opt/plantpan -type f -exec chmod 0644 {} \; && \
    chmod 0755 /opt/plantpan/bin/match

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://localhost/index.php >/dev/null || exit 1

EXPOSE 80
COPY --chmod=0755 entrypoint.sh /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["serve"]
