<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ann;
use App\Models\Config;
use App\Models\DetectLog;
use App\Models\HourlyUsage;
use App\Models\Node;
use App\Models\OnlineLog;
use App\Models\Paylist;
use App\Models\SubscribeLog;
use App\Models\User;
use App\Utils\Tools;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function date;
use function str_replace;
use function strtotime;
use function time;
use const PHP_EOL;

final class Cron
{
    public static function cleanDb(): void
    {
        (new SubscribeLog())->where(
            'request_time',
            '<',
            time() - 86400 * Config::obtain('subscribe_log_retention_days')
        )->delete();
        (new HourlyUsage())->where(
            'date',
            '<',
            date('Y-m-d', time() - 86400 * Config::obtain('traffic_log_retention_days'))
        )->delete();
        (new DetectLog())->where('datetime', '<', time() - 86400 * 3)->delete();
        // EmailQueue 使用 TTL 自动清理，无需手动删除
        (new OnlineLog())->where('last_time', '<', time() - 86400)->delete();

        echo Tools::toDateTime(time()) . ' 数据库清理完成' . PHP_EOL;
    }

    public static function detectInactiveUser(): void
    {
        $checkin_days = Config::obtain('detect_inactive_user_checkin_days');
        $login_days = Config::obtain('detect_inactive_user_login_days');
        $use_days = Config::obtain('detect_inactive_user_use_days');

        (new User())->where('is_admin', 0)
            ->where('is_inactive', 0)
            ->where('last_check_in_time', '<', time() - 86400 * $checkin_days)
            ->where('last_login_time', '<', time() - 86400 * $login_days)
            ->where('last_use_time', '<', time() - 86400 * $use_days)
            ->update(['is_inactive' => 1]);

        (new User())->where('is_admin', 0)
            ->where('is_inactive', 1)
            ->where('last_check_in_time', '>', time() - 86400 * $checkin_days)
            ->where('last_login_time', '>', time() - 86400 * $login_days)
            ->where('last_use_time', '>', time() - 86400 * $use_days)
            ->update(['is_inactive' => 0]);

        echo Tools::toDateTime(time()) .
            ' 检测到 ' . (new User())->where('is_inactive', 1)->count() . ' 个账户处于闲置状态' . PHP_EOL;
    }

    public static function detectNodeOffline(): void
    {
        $nodes = (new Node())->where('type', 1)->get();

        foreach ($nodes as $node) {
            if ($node->getNodeOnlineStatus() >= 0 && $node->online === 1) {
                continue;
            }

            if ($node->getNodeOnlineStatus() === -1 && $node->online === 1) {
                echo 'Send Node Offline Email to admin users' . PHP_EOL;

                try {
                    Notification::notifyAdmin(
                        $_ENV['appName'] . '-系统警告',
                        '管理员你好，系统发现节点 ' . $node->name . ' 掉线了，请你及时处理。'
                    );
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                if (Config::obtain('im_bot_group_notify_node_offline')) {
                    try {
                        Notification::notifyUserGroup(
                            str_replace(
                                '%node_name%',
                                $node->name,
                                I18n::trans('bot.node_offline', $_ENV['locale'])
                            ),
                        );
                    } catch (TelegramSDKException | GuzzleException $e) {
                        echo $e->getMessage() . PHP_EOL;
                    }
                }

                $node->online = 0;
                $node->save();

                continue;
            }

            if ($node->getNodeOnlineStatus() === 1 && $node->online === 0) {
                echo 'Send Node Online Email to admin user' . PHP_EOL;

                try {
                    Notification::notifyAdmin(
                        $_ENV['appName'] . '-系统提示',
                        '管理员你好，系统发现节点 ' . $node->name . ' 恢复上线了。'
                    );
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                if (Config::obtain('im_bot_group_notify_node_online')) {
                    try {
                        Notification::notifyUserGroup(
                            str_replace(
                                '%node_name%',
                                $node->name,
                                I18n::trans('bot.node_online', $_ENV['locale'])
                            ),
                        );
                    } catch (TelegramSDKException | GuzzleException $e) {
                        echo $e->getMessage() . PHP_EOL;
                    }
                }

                $node->online = 1;
                $node->save();
            }
        }

        echo Tools::toDateTime(time()) . ' 节点离线检测完成' . PHP_EOL;
    }

    public static function expirePaidUserAccount(): void
    {
        $paidUsers = (new User())->where('class', '>', 0)->get();

        foreach ($paidUsers as $user) {
            if (strtotime($user->class_expire) < time()) {
                $text = '你好，系统发现你的账号等级已经过期了。';
                $reset_traffic = $_ENV['class_expire_reset_traffic'];

                if ($reset_traffic >= 0) {
                    $user->transfer_enable = Tools::gbToB($reset_traffic);
                    $text .= '流量已经被重置为' . $reset_traffic . 'GB。';
                }

                try {
                    Notification::notifyUser($user, $_ENV['appName'] . '-你的账号等级已经过期了', $text);
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                $user->u = 0;
                $user->d = 0;
                $user->transfer_today = 0;
                $user->class = 0;
                $user->save();
            }
        }

        echo Tools::toDateTime(time()) . ' 付费用户过期检测完成' . PHP_EOL;
    }

    public static function removeInactiveUserLinkAndInvite(): void
    {
        $inactive_users = (new User())->where('is_inactive', 1)->get();

        foreach ($inactive_users as $user) {
            $user->removeLink();
            $user->removeInvite();
        }

        echo Tools::toDateTime(time()) . ' Successfully removed inactive user\'s Link and Invite' . PHP_EOL;
    }

    public static function resetNodeBandwidth(): void
    {
        (new Node())->where('bandwidthlimit_resetday', date('d'))->update(['node_bandwidth' => 0]);

        echo Tools::toDateTime(time()) . ' 重设节点流量完成' . PHP_EOL;
    }

    public static function resetTodayBandwidth(): void
    {
        (new User())->query()->update(['transfer_today' => 0]);

        echo Tools::toDateTime(time()) . ' 重设用户每日流量完成' . PHP_EOL;
    }

    public static function resetFreeUserBandwidth(): void
    {
        $freeUsers = (new User())->where('class', 0)
            ->where('auto_reset_day', date('d'))->get();

        foreach ($freeUsers as $user) {
            try {
                Notification::notifyUser(
                    $user,
                    $_ENV['appName'] . '-免费流量重置通知',
                    '你好，你的免费流量已经被重置为' . $user->auto_reset_bandwidth . 'GB。'
                );
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            $user->u = 0;
            $user->d = 0;
            $user->transfer_enable = $user->auto_reset_bandwidth * 1024 * 1024 * 1024;
            $user->save();
        }

        echo Tools::toDateTime(time()) . ' 免费用户流量重置完成' . PHP_EOL;
    }

    public static function sendDailyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 day', $today), $today])->get();

        if (count($paylists) > 0) {
            $text_html = '<table><tr><td>金额</td><td>用户ID</td><td>用户名</td><td>充值时间</td></tr>';

            foreach ($paylists as $paylist) {
                $text_html .= '<tr>';
                $text_html .= '<td>' . $paylist->total . '</td>';
                $text_html .= '<td>' . $paylist->userid . '</td>';
                $text_html .= '<td>' . (new User())->find($paylist->userid)->user_name . '</td>';
                $text_html .= '<td>' . Tools::toDateTime((int) $paylist->datetime) . '</td>';
                $text_html .= '</tr>';
            }

            $text_html .= '</table>';
            $text_html .= '<br>昨日总收入笔数：' . count($paylists) . '<br>昨日总收入金额：' . $paylists->sum('total');

            $text_html = str_replace([
                '<table>',
                '<tr>',
                '<td>',
            ], [
                '<table style="width: 100%;border: 1px solid black;border-collapse: collapse;">',
                '<tr style="border: 1px solid black;padding: 5px;">',
                '<td style="border: 1px solid black;padding: 5px;">',
            ], $text_html);

            echo 'Sending daily finance email to admin user' . PHP_EOL;

            try {
                Notification::notifyAdmin(
                    '财务日报',
                    $text_html,
                    'finance.tpl'
                );
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            echo Tools::toDateTime(time()) . ' Successfully sent daily finance email' . PHP_EOL;
        } else {
            echo 'No paylist found' . PHP_EOL;
        }
    }

    public static function sendWeeklyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 week', $today), $today])
            ->get();

        $text_html = '<br>上周总收入笔数：' . count($paylists) . '<br>上周总收入金额：' . $paylists->sum('total');
        echo 'Sending weekly finance email to admin user' . PHP_EOL;

        try {
            Notification::notifyAdmin(
                '财务周报',
                $text_html,
                'finance.tpl'
            );
        } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 成功发送财务周报' . PHP_EOL;
    }

    public static function sendMonthlyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 month', $today), $today])
            ->get();

        $text_html = '<br>上月总收入笔数：' . count($paylists) . '<br>上月总收入金额：' . $paylists->sum('total');
        echo 'Sending monthly finance email to admin user' . PHP_EOL;

        try {
            Notification::notifyAdmin(
                '财务月报',
                $text_html,
                'finance.tpl'
            );
        } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 成功发送财务月报' . PHP_EOL;
    }

    public static function sendPaidUserUsageLimitNotification(): void
    {
        $paidUsers = (new User())->where('class', '>', 0)->get();

        foreach ($paidUsers as $user) {
            $user_traffic_left = $user->transfer_enable - $user->u - $user->d;
            $under_limit = false;
            $unit_text = '';

            if ($_ENV['notify_limit_mode'] === 'per' &&
                $user_traffic_left / $user->transfer_enable * 100 < $_ENV['notify_limit_value']
            ) {
                $under_limit = true;
                $unit_text = '%';
            } elseif ($_ENV['notify_limit_mode'] === 'mb' &&
                Tools::bToMB($user_traffic_left) < $_ENV['notify_limit_value']
            ) {
                $under_limit = true;
                $unit_text = 'MB';
            }

            if ($under_limit && ! $user->traffic_notified) {
                try {
                    Notification::notifyUser(
                        $user,
                        $_ENV['appName'] . '-你的剩余流量过低',
                        '你好，系统发现你剩余流量已经低于 ' . $_ENV['notify_limit_value'] . $unit_text . ' 。',
                    );

                    $user->traffic_notified = true;
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    $user->traffic_notified = false;
                    echo $e->getMessage() . PHP_EOL;
                }

                $user->save();
            } elseif (! $under_limit && $user->traffic_notified) {
                $user->traffic_notified = false;
                $user->save();
            }
        }

        echo Tools::toDateTime(time()) . ' 付费用户用量限制提醒完成' . PHP_EOL;
    }

    public static function sendDailyTrafficReport(): void
    {
        $users = (new User())->whereIn('daily_mail_enable', [1, 2])->get();
        $ann_latest_raw = (new Ann())->where('status', '>', 0)
            ->orderBy('status', 'desc')
            ->orderBy('sort')
            ->orderBy('date', 'desc')->first();

        if ($ann_latest_raw === null) {
            $ann_latest = '<br><br>';
        } else {
            $ann_latest = $ann_latest_raw->content . '<br><br>';
        }

        foreach ($users as $user) {
            $user->sendDailyNotification($ann_latest);
        }

        echo Tools::toDateTime(time()) . ' Successfully sent daily traffic report' . PHP_EOL;
    }

    public static function sendDailyJobNotification(): void
    {
        try {
            Notification::notifyUserGroup(
                I18n::trans('bot.daily_job_run', $_ENV['locale'])
            );
        } catch (TelegramSDKException | GuzzleException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' Successfully sent daily job notification' . PHP_EOL;
    }

    public static function sendDiaryNotification(): void
    {
        try {
            Notification::notifyUserGroup(
                str_replace(
                    [
                        '%checkin_user%',
                        '%lastday_total%',
                    ],
                    [
                        Analytics::getTodayCheckinUser(),
                        Analytics::getTodayTrafficUsage(),
                    ],
                    I18n::trans('bot.diary', $_ENV['locale'])
                )
            );
        } catch (TelegramSDKException | GuzzleException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' Successfully sent diary notification' . PHP_EOL;
    }

    public static function updateNodeIp(): void
    {
        $nodes = (new Node())->where('type', 1)->get();

        foreach ($nodes as $node) {
            $node->updateNodeIp();
            $node->save();
        }

        echo Tools::toDateTime(time()) . ' 更新节点 IP 完成' . PHP_EOL;
    }
}