<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/.config.php';
require_once __DIR__ . '/config/appprofile.php';
require_once __DIR__ . '/app/predefine.php';

use App\Services\Boot;
use App\Jobs\EmailJob;
use App\Jobs\OrderJob;
use App\Services\Cache;
use App\Utils\Tools;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * 队列消费者脚本
 * 同时监听多个 Redis 队列（email_queue、order_queue）并处理多种任务类型
 */

// 定义监听的队列名称列表，直接在代码中指定
$queues = ['email_queue', 'order_queue'];

// 初始化系统时间、Sentry 和数据库
Boot::setTime();
Boot::bootSentry();
Boot::bootDb();

// 初始化 Redis 连接
$redis = (new Cache())->initRedis();

try {
    // 测试 Redis 连接
    $redis->ping();
    echo Tools::toDateTime(time()) . " Redis 连接成功\n";
} catch (Exception $e) {
    // 捕获 Redis 连接失败异常并记录
    echo Tools::toDateTime(time()) . " Redis 连接失败：" . $e->getMessage() . "\n";
    \Sentry\captureException($e);
    exit(1);
}

// 输出队列消费者启动日志
echo Tools::toDateTime(time()) . " 队列消费者启动（队列：" . implode(', ', $queues) . "）\n";

/**
 * 处理队列任务
 * @param Redis $redis Redis 客户端实例
 * @param string $key 任务的键
 * @param string $queueName 触发任务的队列名称
 */
function processTask($redis, string $key, string $queueName): void
{
    // 获取任务数据
    $dataJson = $redis->get($key);
    if ($dataJson === null) {
        echo Tools::toDateTime(time()) . " 任务数据缺失，跳过 Key：{$key}（队列：{$queueName}）\n";
        return;
    }

    // 输出任务数据获取日志（截断长数据以避免日志过长）
    echo Tools::toDateTime(time()) . " 获取任务数据：{$key}（队列：{$queueName}）, 数据：" . substr($dataJson, 0, 100) . "...\n";

    // 解析 JSON 数据
    $task = json_decode($dataJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo Tools::toDateTime(time()) . " JSON 解析错误：{$key}（队列：{$queueName}）, 错误：" . json_last_error_msg() . "\n";
        $redis->del([$key]);
        \Sentry\captureMessage("JSON 解析错误：Key={$key}, Queue={$queueName}, 错误=" . json_last_error_msg());
        return;
    }

    // 验证任务结构
    if (!isset($task['type']) || !isset($task['data'])) {
        echo Tools::toDateTime(time()) . " 无效任务结构：{$key}（队列：{$queueName}）\n";
        $redis->del([$key]);
        \Sentry\captureMessage("无效任务结构：Key={$key}, Queue={$queueName}");
        return;
    }

    // 根据任务类型分发处理
    try {
        switch ($task['type']) {
            case 'email':
                EmailJob::handle($task);
                echo Tools::toDateTime(time()) . " 邮件任务处理成功：{$key}（队列：{$queueName}）\n";
                $redis->del([$key]);
                break;
            case 'order':
                OrderJob::handle($task);
                echo Tools::toDateTime(time()) . " 订单任务处理成功：{$key}（队列：{$queueName}）\n";
                $redis->del([$key]);
                break;
            default:
                echo Tools::toDateTime(time()) . " 未知任务类型：{$task['type']}（队列：{$queueName}）\n";
                $redis->del([$key]);
                \Sentry\captureMessage("未知任务类型：Key={$key}, Type={$task['type']}, Queue={$queueName}");
        }
    } catch (Exception | ClientExceptionInterface $ex) {
        // 捕获任务处理异常并重新入队
        echo Tools::toDateTime(time()) . " 任务处理失败：{$key}（队列：{$queueName}）, 错误：" . $ex->getMessage() . "\n";
        \Sentry\captureException($ex);
        $redis->rPush($queueName, $key); // 重新入队到原队列
        echo Tools::toDateTime(time()) . " 任务已重新入队：{$key}（队列：{$queueName}）\n";
        sleep(1); // 短暂休眠以避免快速重试
    }
}

// 主循环，持续处理队列任务
while (true) {
    try {
        // 从多个队列阻塞弹出任务，超时时间 30 秒
        $result = $redis->brPop($queues, 30);

        if ($result === null) {
            echo Tools::toDateTime(time()) . " 无新任务，继续等待...\n";
            continue;
        }

        // 获取任务键和触发队列名称
        [$queueName, $key] = $result;
        echo Tools::toDateTime(time()) . " 从队列获取任务：{$key}（队列：{$queueName}）\n";
        processTask($redis, $key, $queueName);

    } catch (Throwable $e) {
        // 捕获消费进程异常并记录
        echo Tools::toDateTime(time()) . " 消费进程异常：" . $e->getMessage() . "\n";
        \Sentry\captureException($e);
        sleep(5); // 异常后休眠 5 秒以避免频繁重试
    }
}