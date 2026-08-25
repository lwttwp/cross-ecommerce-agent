<?php

/**
 * laravel-queue-rabbitmq v14.3 与 Laravel 12 兼容补丁（上游未修，官方分支同样损坏）。
 * 在 Dockerfile 构建期执行：
 *   1. $config 属性去掉类型（基类 Illuminate\Queue\Queue::$config 无类型）
 *   2. getConfig() 改为 public（基类为 public）
 *   3. 覆写 setConfig()：Laravel 12 QueueManager 会用原始数组调用 setConfig()，
 *      把 QueueConfig 对象覆盖成数组；这里转回 QueueConfig，保证内部契约不破。
 */

$file = 'vendor/vladimir-yuldashev/laravel-queue-rabbitmq/src/Queue/RabbitMQQueue.php';
$code = file_get_contents($file);

$replacements = [
    'protected QueueConfig $config;' => 'protected $config;',
    'protected function getConfig(): QueueConfig' => 'public function getConfig(): QueueConfig',
];

foreach ($replacements as $from => $to) {
    if (! str_contains($code, $from)) {
        fwrite(STDERR, "PATCH FAIL: anchor not found: {$from}\n");
        exit(1);
    }
    $code = str_replace($from, $to, $code);
}

$anchor = "        \$this->dispatchAfterCommit = \$config->isDispatchAfterCommit();\n    }";
$override = $anchor."\n\n    /**\n     * Laravel 12 兼容：框架 QueueManager 以原始数组调用 setConfig()，\n     * 覆写为转回 QueueConfig 对象，避免 getConfig() 契约被破坏。\n     */\n    public function setConfig(array \$config)\n    {\n        \$this->config = \\VladimirYuldashev\\LaravelQueueRabbitMQ\\Queue\\QueueConfigFactory::make(\$config);\n\n        return \$this;\n    }";

if (! str_contains($code, $anchor)) {
    fwrite(STDERR, "PATCH FAIL: constructor anchor not found\n");
    exit(1);
}
$code = str_replace($anchor, $override, $code);

file_put_contents($file, $code);
echo "rabbitmq driver patched OK\n";
