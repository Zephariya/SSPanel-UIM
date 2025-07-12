<?php
declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Config;
use App\Models\Invoice;
use App\Models\Paylist;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Services\Queue;
use App\Services\Reward;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use voku\helper\AntiXSS;
use function get_called_class;
use function in_array;
use function json_decode;
use function time;
use function json_encode;

/**
 * 支付网关基类
 * 定义支付网关的通用逻辑，包括支付处理、回调处理等
 */
abstract class Base
{
    protected AntiXSS $antiXss;

    abstract public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface;

    abstract public function notify(ServerRequest $request, Response $response, array $args): ResponseInterface;

    /**
     * 支付网关的 codeName
     */
    abstract public static function _name(): string;

    /**
     * 是否启用支付网关
     */
    abstract public static function _enable(): bool;

    /**
     * 显示给用户的名称
     */
    abstract public static function _readableName(): string;

    /**
     * 返回支付结果页面
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     */
    public function getReturnHTML(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write('ok');
    }

    abstract public static function getPurchaseHTML(): string;

    /**
     * 处理支付完成后的逻辑
     * @param string $trade_no 交易编号
     */
    public function postPayment(string $trade_no): void
    {
        // 查找支付记录
        $paylist = (new Paylist())->where('tradeno', $trade_no)->first();

        if ($paylist?->status === 0) {
            $paylist->datetime = time();
            $paylist->status = 1;
            $paylist->save();
        }

        // 更新账单状态
        $invoice = (new Invoice())->where('id', $paylist?->invoice_id)->first();

        if (($invoice?->status === 'unpaid' || $invoice?->status === 'partially_paid') &&
            (int) $paylist?->total >= (int) $invoice?->price) {
            $invoice->status = 'paid_gateway';
            $invoice->update_time = time();
            $invoice->pay_time = time();
            $invoice->save();

            // 将订单 ID 推送到 order_queue 队列
            if ($invoice?->order_id) {
                (new Queue('order_queue'))->add(
                    [
                        'order_id' => $invoice->order_id,
                    ],
                    'order'
                );
            }
        }

        // 处理超额支付
        $user = (new User())->find($paylist?->userid);

        if ($paylist?->total > $invoice?->price) {
            $money_before = $user->money;
            $user->money += $paylist->total - $invoice->price;
            $user->save();
            (new UserMoneyLog())->add(
                $user->id,
                $money_before,
                $user->money,
                $paylist->total - $invoice->price,
                '超额支付账单 #' . $invoice->id
            );
        }

        // 处理邀请奖励
        if ($user !== null && $user->ref_by > 0 && Config::obtain('invite_mode') === 'reward') {
            Reward::issuePaybackReward($user->id, $user->ref_by, $invoice?->price, $paylist?->invoice_id);
        }
    }

    /**
     * 生成唯一交易编号
     * @return string 交易编号
     */
    public static function generateGuid(): string
    {
        return Tools::genRandomChar();
    }

    /**
     * 获取回调 URL
     * @return string 回调 URL
     */
    protected static function getCallbackUrl(): string
    {
        return $_ENV['baseUrl'] . '/payment/notify/' . get_called_class()::_name();
    }

    /**
     * 获取用户返回 URL
     * @return string 用户返回 URL
     */
    protected static function getUserReturnUrl(): string
    {
        return $_ENV['baseUrl'] . '/user/payment/return/' . get_called_class()::_name();
    }

    /**
     * 检查支付网关是否启用
     * @param string $key 支付网关名称
     * @return bool 是否启用
     */
    protected static function getActiveGateway(string $key): bool
    {
        $payment_gateways = (new Config())->where('item', 'payment_gateway')->first();
        $active_gateways = json_decode($payment_gateways->value);

        if (in_array($key, $active_gateways)) {
            return true;
        }

        return false;
    }
}