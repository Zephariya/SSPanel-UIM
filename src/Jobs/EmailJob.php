<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\Mail;
use App\Utils\Tools;

/**
 * 邮件任务处理类
 */
final class EmailJob
{
    /**
     * 处理邮件任务
     * @param array $task 任务数据
     * @throws Exception 邮件发送异常
     */
    public static function handle(array $task): void
    {
        $email = $task['data'];

        // 验证邮箱格式
        if (!isset($email['to_email']) || !Tools::isEmail($email['to_email'])) {
            echo Tools::toDateTime(time()) . " 邮箱格式错误或缺失，跳过：" . ($email['to_email'] ?? 'null') . "\n";
            return;
        }

        // 输出发送邮件日志
        echo Tools::toDateTime(time()) . " 准备发送邮件至：{$email['to_email']}\n";

        // 调用邮件服务发送邮件
        Mail::send(
            $email['to_email'],
            $email['subject'],
            $email['template'],
            json_decode($email['array'], true)
        );
    }
}