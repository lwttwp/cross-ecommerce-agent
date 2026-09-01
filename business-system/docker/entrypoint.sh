#!/bin/sh
set -e

# 1. APP_KEY 缺失时生成（镜像内只有 .env.example）
if [ -z "$APP_KEY" ]; then
  php artisan key:generate --force
fi

# 2. 等待数据库就绪(最多 30s):等"可查询"而不是"可连接"
#    fsockopen 只验端口,PG 在 starting up 阶段端口已监听但拒绝查询,
#    用 PDO 执行 SELECT 1 只有数据库真正 ready 才成功。
echo "等待数据库就绪..."
php -r '
$host = getenv("DB_HOST") ?: "postgres";
$port = getenv("DB_PORT") ?: "5432";
$db   = getenv("DB_DATABASE") ?: "cross_ecommerce";
$user = getenv("DB_USERNAME") ?: "ce_app";
$pass = getenv("DB_PASSWORD") ?: "";
for ($i = 0; $i < 30; $i++) {
    try {
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
        $pdo->query("SELECT 1");
        exit(0);
    } catch (Throwable $e) {
        sleep(1);
    }
}
fwrite(STDERR, "数据库连接超时\n");
exit(1);
'

# 3. 执行迁移
php artisan migrate --force

# 4. 空库时灌入演示数据（幂等：有订单就跳过）
php artisan tinker --execute="if (\App\Models\Order::count() === 0) { Artisan::call('db:seed', ['--force' => true]); echo 'Seeded demo data.'.PHP_EOL; } else { echo 'Data exists, skip seeding.'.PHP_EOL; }"

# 4.5 storage 权限：worker(root) 与 FPM(www-data) 共用，日志/导出文件需双方可读写
chmod -R a+rwX /var/www/storage

# 5. 执行容器主命令（php artisan serve / queue:work 等）
exec "$@"
