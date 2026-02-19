FROM php:8.4-cli

# Installation des certificats (curl est déjà inclus dans l'image PHP officielle)
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Crée le répertoire data
RUN mkdir -p /app/data

# Copie le script dans l'image
COPY spoticar_watch.php /app/spoticar_watch.php

CMD ["php", "/app/spoticar_watch.php"]
