FROM php:8.2-cli
RUN docker-php-ext-install mysqli
COPY . /var/www/html/
WORKDIR /var/www/html

RUN mkdir -p thumbnail video \
    && chown -R 755 thumbnail video
EXPOSE 8080
CMD [ "php", "-S", "0.0.0.0:8080" ]