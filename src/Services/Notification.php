<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Config;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function json_encode;

/**
 * 通知服务类
 * 提供向管理员、用户或用户组发送通知的功能，支持邮件和即时消息（IM）
 */
final class Notification
{
    /**
     * 向管理员发送通知
     * @param string $title 通知标题
     * @param string $msg 通知内容
     * @param string $template 邮件模板名称，默认为 'warn.tpl'
     * @throws GuzzleException
     * @throws TelegramSDKException
     * @throws ClientExceptionInterface
     */
    public static function notifyAdmin($title = '', $msg = '', $template = 'warn.tpl'): void
    {
        // 获取所有管理员用户
        $admins = (new User())->where('is_admin', 1)->get();

        foreach ($admins as $admin) {
            // 根据用户偏好的联系方式选择通知方式
            if ($admin->contact_method === 1 || $admin->im_type === 0) {
                // 使用通用 Queue 类添加邮件任务
                (new Queue('email_queue'))->add(
                    [
                        'to_email' => $admin->email,
                        'subject' => $title,
                        'template' => $template,
                        'array' => json_encode([
                            'user' => $admin,
                            'title' => $title,
                            'text' => $msg,
                        ])
                    ],
                    'email'
                );
            } else {
                // 通过 IM 发送通知
                IM::send($admin->im_value, $msg, $admin->im_type);
            }
        }
    }

    /**
     * 向单个用户发送通知
     * @param User $user 用户对象
     * @param string $title 通知标题
     * @param string $msg 通知内容
     * @param string $template 邮件模板名称，默认为 'warn.tpl'
     * @throws GuzzleException
     * @throws TelegramSDKException
     * @throws ClientExceptionInterface
     */
    public static function notifyUser($user, $title = '', $msg = '', $template = 'warn.tpl'): void
    {
        // 根据用户偏好的联系方式选择通知方式
        if ($user->contact_method === 1 || $user->im_type === 0) {
            // 使用通用 Queue 类添加邮件任务
            (new Queue('email_queue'))->add(
                [
                    'to_email' => $user->email,
                    'subject' => $title,
                    'template' => $template,
                    'array' => json_encode([
                        'user' => $user,
                        'title' => $title,
                        'text' => $msg,
                    ])
                ],
                'email'
            );
        } else {
            // 通过 IM 发送通知
            IM::send((int)$user->im_value, $msg, $user->im_type);
        }
    }

    /**
     * 向所有用户发送通知
     * @param string $title 通知标题
     * @param string $msg 通知内容
     * @param string $template 邮件模板名称，默认为 'warn.tpl'
     * @throws GuzzleException
     * @throws TelegramSDKException
     */
    public static function notifyAllUser($title = '', $msg = '', $template = 'warn.tpl'): void
    {
        // 获取所有用户
        $users = User::all();

        foreach ($users as $user) {
            // 根据用户偏好的联系方式选择通知方式
            if ($user->contact_method === 1 || $user->im_type === 0) {
                // 使用通用 Queue 类添加邮件任务
                (new Queue('email_queue'))->add(
                    [
                        'to_email' => $user->email,
                        'subject' => $title,
                        'template' => $template,
                        'array' => json_encode([
                            'user' => $user,
                            'title' => $title,
                            'text' => $msg,
                        ])
                    ],
                    'email'
                );
            } else {
                // 通过 IM 发送通知
                IM::send((int) $user->im_value, $msg, $user->im_type);
            }
        }
    }

    /**
     * 向用户组发送通知（通过 Telegram、Discord 或 Slack）
     * @param string $msg 通知内容
     * @throws GuzzleException
     * @throws TelegramSDKException
     */
    public static function notifyUserGroup(string $msg = ''): void
    {
        // 检查是否启用 Telegram 群组通知
        if (Config::obtain('enable_telegram_group_notify')) {
            IM::send((int) Config::obtain('telegram_chatid'), $msg, 0);
        }

        // 检查是否启用 Discord 频道通知
        if (Config::obtain('enable_discord_channel_notify')) {
            IM::send((int) Config::obtain('discord_channel_id'), $msg, 1);
        }

        // 检查是否启用 Slack 频道通知
        if (Config::obtain('enable_slack_channel_notify')) {
            IM::send((int) Config::obtain('slack_channel_id'), $msg, 2);
        }
    }
}