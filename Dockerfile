# Dockerfile
FROM nimmis/apache-php5
MAINTAINER Koushik Biswas <kbxkb@yahoo.com>

RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html

COPY code/ /var/www/html/

EXPOSE 80

CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
