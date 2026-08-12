FROM php:8.4-cli

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis zip intl gd bcmath pcntl @composer

RUN apt-get update && apt-get install -y --no-install-recommends git unzip gzip \
    && rm -rf /var/lib/apt/lists/*

# pg_dump untuk halaman Cadangan, diambil dari repositori resmi PostgreSQL.
#
# Bukan paket `postgresql-client` bawaan Debian bookworm. Versinya di sana 15,
# sedangkan servernya pgvector/pgvector:pg16, dan pg_dump menolak mentah-mentah
# membaca server yang lebih baru daripada dirinya ("server version mismatch").
# Nomor versinya disebut eksplisit supaya ia ikut berubah bersama versi image
# database di docker-compose.yml, bukan berubah sendiri saat repositori hulu
# merilis versi berikutnya.
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends postgresql-client-16 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
