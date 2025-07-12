<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use function time;

/**
 * 订单任务处理类
 * 处理 order_queue 队列中的订单状态更新任务
 */
final class OrderJob
{
    /**
     * 处理订单任务
     * @param array $task 任务数据，包含 order_id
     * @throws \Exception 如果订单不存在或更新失败
     */
    public static function handle(array $task): void
    {
        // 验证任务数据
        if (!isset($task['data']['order_id'])) {
            throw new \Exception('任务数据缺少 order_id');
        }

        $order_id = $task['data']['order_id'];

        // 查找订单
        $order = (new Order())->where('id', $order_id)->first();
        if ($order === null) {
            throw new \Exception("订单 #{$order_id} 不存在");
        }

        // 更新订单状态为已激活
        if (in_array($order->status, ['pending_payment', 'pending_activation'])) {
            $order->status = 'activated';
            $order->update_time = time();
            if (!$order->save()) {
                throw new \Exception("订单 #{$order_id} 更新失败");
            }
        }
    }
}