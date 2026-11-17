server {
    listen 443 ssl;
    listen [::]:443 ssl;

    server_name xn--h1ape.xn--p1ai www.xn--h1ape.xn--p1ai;

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
    # Основное Vue3 / Nuxt приложение (Строго порт 4000)
    # ==========================================
    location / {
        limit_req zone=front_limit burst=60 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        proxy_pass http://127.0.0.1:4000;

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
    # ВЫДЕЛЕННЫЙ ШЛЮЗ ДЛЯ API КАТАЛОГА: Сквозной пропуск (Строго порт 4000)
    # =========================================================================
    location /api/ {
        limit_req zone=front_limit burst=100 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        proxy_pass http://127.0.0.1:4000;

        proxy_set_header Host               $host;
        proxy_set_header X-Real-IP          $remote_addr;
        proxy_set_header X-Forwarded-For    $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto  $scheme;

        proxy_set_header Upgrade            $http_upgrade;
        proxy_set_header Connection         "upgrade";
        proxy_cache_bypass                  $http_upgrade;
    }

    # ==========================================
    # Системные JS-файлы Vue/Nuxt (Строго порт 4000)
    # ==========================================
    location /_nuxt/ {
        limit_req zone=front_limit burst=200 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        proxy_pass http://127.0.0.1:4000;
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
    }

    # ==========================================
    # Защита эндпоинтов авторизации и СМС от спама (Строго порт 4000)
    # ==========================================
    location ^~ /api/auth/ {
        limit_req zone=media_limit burst=15 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        proxy_pass         http://127.0.0.1:4000;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }

    # ========================================================
    # Защита внутренних скриптов Битрикса (Исправлен proxy_pass на Бэкенд)
    # ========================================================
    location ~* /(bitrix/services/main/ajax.php|bitrix/spread.php) {
        limit_req zone=media_limit burst=30 nodelay;
        limit_req_status 429;
        limit_req_log_level warn;

        # Шлем запрос на реальный бэкенд, убирая внутреннюю петлю
        proxy_pass         https://xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_set_header   Host xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   User-Agent "Nuxt-SSR-Agent";
        proxy_ssl_server_name on;
    }

    # ========================================================
    # ИЗОБРАЖЕНИЯ
    # ========================================================
    location ^~ /upload/iblock/ {
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

        proxy_pass         https://xn--80aimpg.xn--h1ape.xn--p1ai/upload/iblock/;
        proxy_redirect     off;

        proxy_set_header   Host xn--80aimpg.xn--h1ape.xn--p1ai;
        proxy_set_header   User-Agent "Nuxt-SSR-Agent";

        proxy_set_header   X-Real-IP $remote_addr;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }

    # Настройки SSL от Certbot
    ssl_certificate /etc/letsencrypt/live/xn--h1ape.xn--p1ai/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/xn--h1ape.xn--p1ai/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}


# Блок редиректа с HTTP на HTTPS (оставлен без изменений, тут всё ок)
server {
    if ($host = www.xn--h1ape.xn--p1ai) {
        return 301 https://$host$request_uri;
    } # managed by Certbot

    if ($host = xn--h1ape.xn--p1ai) {
        return 301 https://$host$request_uri;
    } # managed by Certbot

    listen 80;
    listen [::]:80;

    server_name xn--h1ape.xn--p1ai www.xn--h1ape.xn--p1ai;
    return 404; # managed by Certbot
}

