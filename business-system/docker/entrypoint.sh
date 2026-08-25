#!/bin/sh
set -e

# 1. APP_KEY 缺失时生成（镜像内只有 .env.example）
if [ -z "$APP_KEY" ]; then
  php artisan key:generate --force
fi

# 2. 等待数据库就绪（最多 30s）
echo "等待数据库就绪..."
php -r '
$host = getenv("DB_HOST") ?: "postgres";
$port = getenv("DB_PORT") ?: "5432";
for ($i = 0; $i < 30; $i++) {
    $conn = @fsockopen($host, (int)$port, $errno, $errstr, 1);
    if ($conn) { fclose($conn); exit(0); }
    sleep(1);
}
fwrite(STDERR, "数据库连接超时\n");
exit(1);
'

# 3. 执行迁移
php artisan migrate --force

# 4. 空库时灌入演示数据（幂等：有订单就跳过）
php artisan tinker --execute="if (\App\Models\Order::count() === 0) { Artisan::call('db:seed', ['--force' => true]); echo 'Seeded demo data.'.PHP_EOL; } else { echo 'Data exists, skip seeding.'.PHP_EOL; }"

# 5. 执行容器主命令（php artisan serve / queue:work 等）
exec "$@"
