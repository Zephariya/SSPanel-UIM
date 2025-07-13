<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/.config.php';
require_once __DIR__ . '/config/appprofile.php';
require_once __DIR__ . '/app/predefine.php';

use App\Services\Boot;
use App\Jobs\EmailJob;
use App\Jobs\OrderJob;
use App\Utils\Tools;
use function Sentry\captureException;
use function Sentry\captureMessage;

const REDIS_RETRY_INTERVAL = 5;
$queues = ['email_queue', 'order_queue'];

// 初始化环境
Boot::setTime();
Boot::bootSentry();
Boot::bootDb();

/**
 * 日志输出
 */
function logMessage(string $message): void
{
    echo Tools::toDateTime(time()) . ' ' . $message . "\n";
}

/**
 * 初始化 Redis 连接（ext-redis）
 * @return Redis
 * @throws Throwable
 */
function initRedisConnection(): Redis
{
    $redis = new Redis();

    $host = $_ENV['redis_host'] ?? '127.0.0.1';
    $port = (int) ($_ENV['redis_port'] ?? 6379);
    $timeout = (float) ($_ENV['redis_connect_timeout'] ?? 2.0);
    $useSsl = (bool) ($_ENV['redis_ssl'] ?? false);
    $auth = $_ENV['redis_password'] ?? null;
    $username = $_ENV['redis_username'] ?? null;

    if (!$redis->connect($host, $port, $timeout, null, 0, 0, ['ssl' => $useSsl])) {
        throw new RuntimeException("Redis 连接失败");
    }

    if ($username && $auth) {
        if (!$redis->auth([$username, $auth])) {
            throw new RuntimeException("Redis 身份验证失败");
        }
    } elseif ($auth) {
        if (!$redis->auth($auth)) {
            throw new RuntimeException("Redis 身份验证失败");
        }
    }

    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1); // 防止 brPop 报错
    $redis->ping();
    logMessage("Redis（ext-redis）连接成功（{$host}:{$port}）");

    return $redis;
}

/**
 * 处理任务
 */
function processTask(Redis $redis, string $key, string $queueName): void
{
    try {
        $dataJson = $redis->get($key);
        if ($dataJson === false) {
            logMessage("任务数据缺失，跳过 Key：{$key}（队列：{$queueName}）");
            return;
        }

        logMessage("获取任务数据：{$key}（队列：{$queueName}）, 数据：" . substr($dataJson, 0, 100) . "...");

        $task = json_decode($dataJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("JSON 解析错误：{$key}（队列：{$queueName}）, 错误：" . json_last_error_msg());
            $redis->del($key);
            captureMessage("JSON 解析错误：Key={$key}, Queue={$queueName}, 错误=" . json_last_error_msg());
            return;
        }

        if (!isset($task['type'], $task['data'])) {
            logMessage("无效任务结构：{$key}（队列：{$queueName}）");
            $redis->del($key);
            captureMessage("无效任务结构：Key={$key}, Queue={$queueName}");
            return;
        }

        switch ($task['type']) {
            case 'email':
                EmailJob::handle($task);
                logMessage("邮件任务处理成功：{$key}（队列：{$queueName}）");
                break;

            case 'order':
                OrderJob::handle($task);
                logMessage("订单任务处理成功：{$key}（队列：{$queueName}）");
                break;

            default:
                logMessage("未知任务类型：{$task['type']}（队列：{$queueName}）");
                captureMessage("未知任务类型：Key={$key}, Type={$task['type']}, Queue={$queueName}");
        }

        $redis->del($key); // 不论任务类型，都删除 key

    } catch (Throwable $ex) {
        logMessage("任务处理失败：{$key}（队列：{$queueName}）, 错误：" . $ex->getMessage());
        captureException($ex);

        try {
            $redis->rPush($queueName, $key);
            logMessage("任务已重新入队：{$key}（队列：{$queueName}）");
        } catch (Throwable $re) {
            logMessage("任务重新入队失败：{$key}（队列：{$queueName}）, 错误：" . $re->getMessage());
            captureException($re);
        }

        sleep(1);
    }
}

// 启动消费者主循环
try {
    $redis = initRedisConnection();
} catch (Throwable $e) {
    logMessage("初始化 Redis 失败，退出：{$e->getMessage()}");
    exit(1);
}

logMessage("队列消费者启动（队列：" . implode(', ', $queues) . "）");

while (true) {
    try {
        $result = $redis->brPop($queues, 30);

        if ($result === null || count($result) !== 2) {
            logMessage("无新任务，继续等待...");
            continue;
        }

        [$queueName, $key] = $result;
        logMessage("从队列获取任务：{$key}（队列：{$queueName}）");

        processTask($redis, $key, $queueName);
    } catch (Throwable $e) {
        logMessage("消费进程异常：" . $e->getMessage());
        captureException($e);

        sleep(REDIS_RETRY_INTERVAL);

        try {
            $redis = initRedisConnection();
        } catch (Throwable $re) {
            logMessage("Redis 重连失败：" . $re->getMessage());
            captureException($re);
        }
    }
}
