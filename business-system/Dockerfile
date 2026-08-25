# 跨境电商智能订单助手 - 业务系统镜像（PHP-FPM）
# 生产架构：Nginx 容器(静态+转发) + 本容器(PHP-FPM 处理 PHP)
FROM php:8.3-fpm-alpine

# 系统依赖 + PHP 扩展（pgsql 用于 PostgreSQL，zip 用于 Composer 解包）
RUN apk add --no-cache libpq-dev libzip-dev zip unzip \
    && docker-php-ext-install pdo_pgsql pgsql zip \
    && docker-php-ext-enable pdo_pgsql pgsql zip

# PHP-FPM 默认 clear_env=yes 会清掉容器环境变量（DB_HOST 等），改为继承容器环境
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-env.conf

# 官方 Composer 镜像直接拷贝
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 国内镜像加速（构建期安装依赖用）
RUN composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/

WORKDIR /var/www

COPY . .

# 镜像内不携带 .env（避免泄露 token），用 .env.example 兜底，运行期由 compose 注入环境变量
RUN cp .env.example .env \
    && composer install --optimize-autoloader --no-interaction

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
# 默认命令：PHP-FPM（entrypoint 跑完迁移/造数后 exec 到这里）
CMD ["php-fpm"]
