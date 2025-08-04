# Hosts file:
# 127.0.0.1       borterminal

# cat /etc/nginx/sites-available/borterminal
# sudo ln -s /etc/nginx/sites-available/borterminal /etc/nginx/sites-enabled/
# sudo service nginx restart

server {
    server_name borterminal;
    root /var/www/bor_terminal/public;

    client_max_body_size 100M;

    location / {
        # try to serve file directly, fallback to index.php
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {

        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        #fastcgi_pass unix:/var/run/php-fpm/www.sock;
        #fastcgi_pass php-fpm;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
    #fastcgi_read_timeout 600;
    fastcgi_buffer_size 256k;
    fastcgi_buffers 4 256k;
    fastcgi_busy_buffers_size 256k;
    fastcgi_temp_file_write_size 256k;

        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;

        internal;
    }

    # location ~ \.php$ {
        # return 404;
    # }

    error_log /var/log/nginx/borterminal_error.log;
    access_log /var/log/nginx/borterminal_access.log;

}
