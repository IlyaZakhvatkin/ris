server {
    listen 443 ssl;
    listen [::]:443 ssl;

    index index.html index.htm index.nginx-debian.html;
    server_name gorobmen.com www.gorobmen.com gor-obmen.ru www.gor-obmen.ru;

    # ========================================================
    # 1. ЖЕСТКАЯ ЗАЩИТА: Мгновенно блокируем инъекции кода и сканеры
    # ========================================================
    if ($block_malicious_request = 1) {
        return 403;
    }

    # ========================================================
    # УМНЫЙ БЕЛЫЙ СПИСОК USER-AGENT:
    # Разрешаем пустой User-Agent для внутренних SSR-запросов Nuxt
    # ========================================================
    set $block_empty_agent 0;

    if ($http_user_agent = "") {
        set $block_empty_agent 1;
    }
    # Если запрос пришел локально от самого Docker/Nuxt — пропускаем!
    if ($remote_addr ~* "^(127\.0\.0\.1|::1|172\.1[6-9]\.|172\.2[0-9]\.|172\.3[0-1]\.)") {
        set $block_empty_agent 0;
    }

    if ($block_empty_agent = 1) {
        return 403;
    }

    # Блокировка коммерческих парсеров на входе
    if ($http_user_agent ~* (Bytespider|SemrushBot|DotBot|ShopBot|MJ12bot)) {
        return 403;
    }

    # ==========================================
    # Основное Vue3 / Nuxt приложение
    # ==========================================
    location / {
        limit_req zone=front_limit burst=60 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        # Проксирование в Docker-контейнер (порт 3000)
        proxy_pass http://127.0.0.1:3000;

        # Стандартные прозрачные заголовки для Nuxt/Node.js
        proxy_set_header Host               $host;
        proxy_set_header X-Real-IP          $remote_addr;
        proxy_set_header X-Forwarded-For    $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto  $scheme;

        # Настройки WebSocket для Nuxt
        proxy_set_header Upgrade            $http_upgrade;
        proxy_set_header Connection         "upgrade";
        proxy_cache_bypass                  $http_upgrade;

        proxy_connect_timeout 15s;
        proxy_send_timeout 15s;
        proxy_read_timeout 15s;
    }

    # =========================================================================
    # ВЫДЕЛЕННЫЙ ШЛЮЗ ДЛЯ API КАТАЛОГА: Сквозной пропуск без ложных 403
    # =========================================================================
    location /api/ {
        limit_req zone=front_limit burst=100 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        # Перенаправляем напрямую в Docker Nuxt (порт 3000)
        proxy_pass http://127.0.0.1:3000;

        # Стандартные прозрачные заголовки для Nuxt/Node.js (Снимает внутренний 403)
        proxy_set_header Host               $host;
        proxy_set_header X-Real-IP          $remote_addr;
        proxy_set_header X-Forwarded-For    $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto  $scheme;

        proxy_set_header Upgrade            $http_upgrade;
        proxy_set_header Connection         "upgrade";
        proxy_cache_bypass                  $http_upgrade;
    }

    # ==========================================
    # Разрешаем системным файлам Vue/Nuxt загружаться без жестких лимитов скорости
    # ==========================================
    location /_nuxt/ {
        limit_req zone=front_limit burst=200 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
    }

    # ==========================================
    # Защита эндпоинтов авторизации и СМС от спама
    # ==========================================
    location ^~ /api/auth/ {
        limit_req zone=media_limit burst=15 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        # Перенаправляем строго в контейнер авторизации (порт 4000)
        proxy_pass         http://127.0.0.1:4000;

        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }

    # ========================================================
    # Защита внутренних скриптов Битрикса от спама (Прокси на Бэкенд)
    # ========================================================
    location ~* /(bitrix/services/main/ajax.php|bitrix/spread.php) {
        limit_req zone=media_limit burst=30 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        proxy_pass         https://xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_set_header   Host xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   User-Agent "Nuxt-SSR-Agent";
        proxy_ssl_server_name on;
    }

	# ==========================================
    # Изображения
    # ==========================================
    location ^~ /upload/catalog/ {
        limit_req zone=media_limit burst=80 delay=30;
        limit_req_status 429;
        limit_req_log_level warn;
        # -------------------------------------------------------------
        # НАСТРОЙКИ CORS (ДОБАВЛЕНО)
        # Прямо разрешаем доступ для личного кабинета
        # -------------------------------------------------------------
        set $cors_origin "";
        if ($http_origin ~* "^https?://((www\.)?(gorobmen\.com|gor-obmen\.ru)|xn--h1agjfdk9e\.xn--h1ape\.xn--p1ai)$") {
            set $cors_origin $http_origin;
        }

        # Если это предзапрос (OPTIONS) от браузера, сразу отвечаем 204
        if ($request_method = 'OPTIONS') {
            add_header 'Access-Control-Allow-Origin' $cors_origin always;
            add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS' always;
            add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range' always;
            add_header 'Access-Control-Max-Age' 1728000 always;
            add_header 'Content-Type' 'text/plain; charset=utf-8';
            add_header 'Content-Length' 0;
            return 204;
        }

        # Заголовки CORS для обычных GET запросов
        add_header 'Access-Control-Allow-Origin' $cors_origin always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, OPTIONS' always;
        add_header 'Access-Control-Allow-Headers' 'DNT,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Range' always;
        # -------------------------------------------------------------

        # Основные настройки кэширования Nginx
        proxy_cache bitrix_images_cache;
        proxy_cache_valid 200 302 24h;
        proxy_cache_valid 404 10m;

        # Важно: Включаем Origin в ключ кэша, чтобы ответы с CORS-заголовками
        # для разных доменов или без них не смешивались в кэше Nginx
        proxy_cache_key "$host$uri$http_origin";

        # Перенаправляем инструкции S3, но позволяем Nginx управлять логикой сброса
        proxy_ignore_headers Cache-Control Expires Set-Cookie;
        proxy_cache_use_stale error timeout invalid_header updating http_500 http_502 http_503 http_504;

        # МАГИЯ СБРОСА:
        # 1. Если есть ?nocache=1, Nginx ВСЕГДА идет в S3 в обход кэша
        proxy_cache_bypass $arg_nocache;
        # 2. Перезаписываем старый закэшированный файл новым из S3
        proxy_no_cache $arg_nocache;
        # 3. Отключаем revalidate, чтобы Nginx при bypass принудительно выкачал
        # свежий файл целиком, а не получил от S3 ответ 304 Not Modified
        proxy_cache_revalidate off;

        add_header X-Cache-Status $upstream_cache_status;

        # Настройки S3
        # proxy_pass https://s3.twcstorage.ru;
        proxy_pass https://go-photo-objects.s3.twcstorage.ru;
        proxy_set_header Host go-photo-objects.s3.twcstorage.ru;
        proxy_ssl_server_name on;
        # proxy_set_header X-Real-IP $remote_addr;
        # proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Authorization "";

        proxy_hide_header x-amz-id-2;
        proxy_hide_header x-amz-request-id;
        proxy_hide_header Set-Cookie;

        # Также скрываем CORS заголовки, если они вдруг прилетят из самого S3,
        # чтобы не было дублирования заголовков (из-за чего браузер тоже ругается)
        proxy_hide_header 'Access-Control-Allow-Origin';

        add_header Cache-Control "public, max-age=3600";
        expires 1h;
    }

    location ^~ /upload/iblock/ {
        limit_req zone=media_limit burst=80 delay=30;
        limit_req_status 429;
        limit_req_log_level warn; # Не засоряем error.log для Fail2ban

        proxy_cache        bitrix_images_cache;
        proxy_cache_valid  200 302 24h;
        proxy_cache_valid  404 10m;
        proxy_cache_key    "$host$request_uri";
        proxy_ignore_headers Cache-Control Expires Set-Cookie;
        proxy_cache_use_stale error timeout invalid_header updating http_500 http_502 http_503 http_504;
        add_header         X-Cache-Status $upstream_cache_status;

        proxy_pass         https://xn--80aimpg.xn--h1ape.xn--p1ai/upload/iblock/;
        proxy_redirect     off;

        proxy_set_header   Host xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   User-Agent "Nuxt-SSR-Agent";

        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_ssl_server_name on;
    }

    location ^~ /upload/main/ {
        limit_req zone=media_limit burst=80 delay=30;
        limit_req_status 429;
        limit_req_log_level warn;

        proxy_cache        bitrix_images_cache;
        proxy_cache_valid  200 302 24h;
        proxy_cache_valid  404 10m;
        proxy_cache_key    "$host$request_uri";
        proxy_ignore_headers Cache-Control Expires Set-Cookie;
        proxy_cache_use_stale error timeout invalid_header updating http_500 http_502 http_503 http_504;
        add_header         X-Cache-Status $upstream_cache_status;

        proxy_pass         https://xn--80aimpg.xn--h1ape.xn--p1ai/upload/main/;
        proxy_redirect     off;

        proxy_set_header   Host xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   User-Agent "Nuxt-SSR-Agent";

        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_ssl_server_name on;
    }

    location ^~ /images/gor-form/ {
        limit_req zone=media_limit burst=80 delay=30;
        limit_req_status 429;
        limit_req_log_level warn;

        proxy_cache        bitrix_images_cache;
        proxy_cache_valid  200 302 24h;
        proxy_cache_valid  404 10m;
        proxy_cache_key    "$host$request_uri";
        proxy_ignore_headers Cache-Control Expires Set-Cookie;
        proxy_cache_use_stale error timeout invalid_header updating http_500 http_502 http_503 http_504;
        add_header         X-Cache-Status $upstream_cache_status;

        proxy_pass         https://xn--80aimpg.xn--h1ape.xn--p1ai/images/gor-form/;
        proxy_redirect     off;

        proxy_set_header   Host xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   User-Agent "Nuxt-SSR-Agent";

        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_ssl_server_name on;
    }

    location ^~ /feeds/ {
        limit_req zone=media_limit burst=5 nodelay;

        proxy_pass         https://xn--80aimpg.xn--h1ape.xn--p1ai/feeds/;
        proxy_redirect     off;

        proxy_set_header   Host xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   User-Agent "Nuxt-SSR-Agent";

        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_set_header   Content-Type application/xml;
    }

    # managed by Certbot
    ssl_certificate /etc/letsencrypt/live/gorobmen.com/fullchain.pem; # managed by Certbot
    ssl_certificate_key /etc/letsencrypt/live/gorobmen.com/privkey.pem; # managed by Certbot
    include /etc/letsencrypt/options-ssl-nginx.conf; # managed by Certbot
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem; # managed by Certbot

}

server {
    if ($host = www.gorobmen.com) {
        return 301 https://$host$request_uri;
    } # managed by Certbot

    if ($host = gorobmen.com) {
        return 301 https://$host$request_uri;
    } # managed by Certbot

    if ($host = www.gor-obmen.ru) {
        return 301 https://www.gorobmen.com$request_uri;
    } # managed by SKA

    if ($host = gor-obmen.ru) {
        return 301 https://gorobmen.com$request_uri;
    } # managed by SKA

	listen 80;

	server_name gorobmen.com www.gorobmen.com gor-obmen.ru www.gor-obmen.ru;
    return 404; # managed by SKA
}
