FROM node:22-alpine AS frontend
WORKDIR /build/frontend
COPY frontend/package*.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev ca-certificates \
 && docker-php-ext-install mysqli curl mbstring opcache \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . ./
COPY --from=frontend /build/frontend/dist/assets ./public/assets
COPY --from=frontend /build/frontend/dist/index.html ./public/app.html
COPY deploy/production.ini /usr/local/etc/php/conf.d/dormfind.ini
COPY deploy/apache.conf /etc/apache2/conf-available/dormfind.conf
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
 && a2enconf dormfind \
 && chmod +x deploy/start.sh \
 && mkdir -p runtime/cache runtime/logs runtime/session \
 && chown -R www-data:www-data runtime
ENV APP_ENV=production
EXPOSE 10000
CMD ["/var/www/html/deploy/start.sh"]
