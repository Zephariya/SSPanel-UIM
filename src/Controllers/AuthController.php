<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Config;
use App\Models\InviteCode;
use App\Models\LoginIp;
use App\Models\User;
use App\Services\Queue;
use App\Services\Auth;
use App\Services\Cache;
use App\Services\Captcha;
use App\Services\Filter;
use App\Services\Mail;
use App\Services\MFA;
use App\Services\RateLimit;
use App\Services\Reward;
use App\Utils\Cookie;
use App\Utils\Hash;
use App\Utils\ResponseHelper;
use App\Utils\Tools;
use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use RedisException;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function array_rand;
use function date;
use function explode;
use function strlen;
use function strtolower;
use function time;
use function trim;

/**
 * 认证控制器
 * 处理用户登录、注册、登出等认证相关逻辑
 */
final class AuthController extends BaseController
{
    /**
     * 显示登录页面
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     * @throws Exception
     */
    public function login(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $captcha = [];

        // 检查是否启用登录验证码
        if (Config::obtain('enable_login_captcha')) {
            $captcha = Captcha::generate();
        }

        // 渲染登录页面模板
        return $response->write($this->view()
            ->assign('base_url', $_ENV['baseUrl'])
            ->assign('captcha', $captcha)
            ->fetch('auth/login.tpl'));
    }

    /**
     * 处理登录请求
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     */
    public function loginHandle(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        // 验证登录验证码（如果启用）
        if (Config::obtain('enable_login_captcha') && ! Captcha::verify($request->getParams())) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '系统无法接受你的验证结果，请刷新页面后重试。',
            ]);
        }

        // 获取并清理输入参数
        $mfa_code = $this->antiXss->xss_clean($request->getParam('mfa_code'));
        $password = $request->getParam('password');
        $rememberMe = $request->getParam('remember_me') === 'true' ? 1 : 0;
        $email = strtolower(trim($this->antiXss->xss_clean($request->getParam('email'))));
        $redir = $this->antiXss->xss_clean(Cookie::get('redir')) ?? '/user';

        // 查询用户
        $user = (new User())->where('email', $email)->first();
        $loginIp = new LoginIp();

        // 检查用户是否存在
        if ($user === null) {
            $loginIp->collectLoginIP($_SERVER['REMOTE_ADDR'], 1);
            return $response->withJson([
                'ret' => 0,
                'msg' => '邮箱或者密码错误',
            ]);
        }

        // 验证密码
        if (! Hash::checkPassword($user->pass, $password)) {
            $loginIp->collectLoginIP($_SERVER['REMOTE_ADDR'], 1, $user->id);
            return $response->withJson([
                'ret' => 0,
                'msg' => '邮箱或者密码错误',
            ]);
        }

        // 验证两步认证（如果启用）
        if ($user->ga_enable && (strlen($mfa_code) !== 6 || ! MFA::verifyGa($user, $mfa_code))) {
            $loginIp->collectLoginIP($_SERVER['REMOTE_ADDR'], 1, $user->id);
            return $response->withJson([
                'ret' => 0,
                'msg' => '两步验证码错误',
            ]);
        }

        // 设置登录会话时间
        $time = 3600;
        if ($rememberMe) {
            $time = 86400 * ($_ENV['rememberMeDuration'] ?: 7);
        }

        // 执行登录并记录
        Auth::login($user->id, $time);
        $loginIp->collectLoginIP($_SERVER['REMOTE_ADDR'], 0, $user->id);
        $user->last_login_time = time();
        $user->save();

        // 重定向到目标页面
        return $response->withHeader('HX-Redirect', $redir);
    }

    /**
     * 显示注册页面
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     * @throws Exception
     */
    public function register(ServerRequest $request, Response $response, $next): ResponseInterface
    {
        $captcha = [];

        // 检查是否启用注册验证码
        if (Config::obtain('enable_reg_captcha')) {
            $captcha = Captcha::generate();
        }

        // 获取邀请码
        $invite_code = $this->antiXss->xss_clean($request->getParam('code'));

        // 渲染注册页面模板
        return $response->write(
            $this->view()
                ->assign('invite_code', $invite_code)
                ->assign('base_url', $_ENV['baseUrl'])
                ->assign('captcha', $captcha)
                ->fetch('auth/register.tpl')
        );
    }

    /**
     * 发送邮箱验证码
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     * @throws RedisException
     */
    public function sendVerify(ServerRequest $request, Response $response, $next): ResponseInterface
    {
        if (Config::obtain('reg_email_verify')) {
            // 获取并清理邮箱
            $email = strtolower(trim($this->antiXss->xss_clean($request->getParam('email'))));

            // 检查邮箱是否为空
            if ($email === '') {
                return ResponseHelper::error($response, '未填写邮箱');
            }

            // 验证邮箱格式
            $email_check = Filter::checkEmailFilter($email);
            if (! $email_check) {
                return ResponseHelper::error($response, '无效的邮箱');
            }

            // 检查频率限制
            if (! (new RateLimit())->checkRateLimit('email_request_ip', $request->getServerParam('REMOTE_ADDR')) ||
                ! (new RateLimit())->checkRateLimit('email_request_address', $email)
            ) {
                return ResponseHelper::error($response, '你的请求过于频繁，请稍后再试');
            }

            // 检查邮箱是否已注册
            $user = (new User())->where('email', $email)->first();
            if ($user !== null) {
                return ResponseHelper::error($response, '此邮箱已经注册');
            }

            // 生成验证码并存储到 Redis
            $email_code = Tools::genRandomChar(6);
            $redis = (new Cache())->initRedis();
            $redis->setex('email_verify:' . $email_code, Config::obtain('email_verify_code_ttl'), $email);

            // 使用通用 Queue 类添加邮件任务
            $emailQueue = new Queue('email_queue');
            $emailQueue->add(
                [
                    'to_email' => $email,
                    'subject' => $_ENV['appName'] . '- 验证邮件',
                    'template' => 'verify_code.tpl',
                    'array' => json_encode([
                        'code' => $email_code,
                        'expire' => date('Y-m-d H:i:s', time() + Config::obtain('email_verify_code_ttl')),
                    ])
                ],
                'email'
            );

            return ResponseHelper::success($response, '验证码发送成功，请查收邮件。');
        }

        return ResponseHelper::error($response, '站点未启用邮件验证');
    }

    /**
     * 注册用户辅助方法
     * @param Response $response HTTP 响应
     * @param string $name 用户名
     * @param string $email 邮箱
     * @param string $password 密码
     * @param string $invite_code 邀请码
     * @param int $imtype IM 类型
     * @param string $imvalue IM 值
     * @param float $money 初始余额
     * @param bool $is_admin_reg 是否管理员注册
     * @return ResponseInterface
     * @throws Exception
     */
    public function registerHelper(
        Response $response,
                 $name,
                 $email,
                 $password,
                 $invite_code,
                 $imtype,
                 $imvalue,
                 $money,
                 $is_admin_reg
    ): ResponseInterface {
        $redir = $this->antiXss->xss_clean(Cookie::get('redir')) ?? '/user';
        $configs = Config::getClass('reg');

        // 创建新用户
        $user = new User();
        $user->user_name = $name;
        $user->email = $email;
        $user->remark = '';
        $user->pass = Hash::passwordHash($password);
        $user->passwd = Tools::genRandomChar(16);
        $user->uuid = Uuid::uuid4();
        $user->api_token = Tools::genRandomChar(32);
        $user->port = Tools::getSsPort();
        $user->u = 0;
        $user->d = 0;
        $user->method = $configs['reg_method'];
        $user->im_type = $imtype;
        $user->im_value = $imvalue;
        $user->transfer_enable = Tools::gbToB($configs['reg_traffic']);
        $user->auto_reset_day = Config::obtain('free_user_reset_day');
        $user->auto_reset_bandwidth = Config::obtain('free_user_reset_bandwidth');
        $user->daily_mail_enable = $configs['reg_daily_report'];
        $user->money = $money > 0 ? $money : 0;

        // 处理邀请码
        $user->ref_by = 0;
        if ($invite_code !== '') {
            $invite = (new InviteCode())->where('code', $invite_code)->first();
            if ($invite !== null) {
                $user->ref_by = $invite->user_id;
            }
        }

        // 设置用户其他属性
        $user->ga_token = MFA::generateGaToken();
        $user->ga_enable = 0;
        $user->class = $configs['reg_class'];
        $user->class_expire = date('Y-m-d H:i:s', time() + (int) $configs['reg_class_time'] * 86400);
        $user->node_iplimit = $configs['reg_ip_limit'];
        $user->node_speedlimit = $configs['reg_speed_limit'];
        $user->reg_date = date('Y-m-d H:i:s');
        $user->reg_ip = $_SERVER['REMOTE_ADDR'];
        $user->theme = $_ENV['theme'];
        $user->locale = $_ENV['locale'];

        // 设置用户组
        $random_group = Config::obtain('random_group');
        $user->node_group = $random_group === '' ? 0 : $random_group[array_rand(explode(',', $random_group))];
        $user->last_login_time = time();

        // 保存用户并处理登录
        if ($user->save() && ! $is_admin_reg) {
            if ($user->ref_by !== 0) {
                Reward::issueRegReward($user->id, $user->ref_by);
            }
            Auth::login($user->id, 3600);
            (new LoginIp())->collectLoginIP($_SERVER['REMOTE_ADDR'], 0, $user->id);
            return $response->withHeader('HX-Redirect', $redir);
        }

        return ResponseHelper::error($response, '未知错误');
    }

    /**
     * 处理注册请求
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     * @throws RedisException
     * @throws Exception
     */
    public function registerHandle(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        // 检查注册是否开放
        if (Config::obtain('reg_mode') === 'close') {
            return ResponseHelper::error($response, '未开放注册。');
        }

        // 验证注册验证码（如果启用）
        if (Config::obtain('enable_reg_captcha') && ! Captcha::verify($request->getParams())) {
            return ResponseHelper::error($response, '系统无法接受你的验证结果，请刷新页面后重试。');
        }

        // 获取并清理输入参数
        $tos = $request->getParam('tos') === 'true' ? 1 : 0;
        $email = strtolower(trim($this->antiXss->xss_clean($request->getParam('email'))));
        $name = $this->antiXss->xss_clean($request->getParam('name'));
        $password = $request->getParam('password');
        $confirm_password = $request->getParam('confirm_password');
        $invite_code = $this->antiXss->xss_clean(trim($request->getParam('invite_code')));

        // 验证服务条款
        if (! $tos) {
            return ResponseHelper::error($response, '请同意服务条款');
        }

        // 验证密码长度
        if (strlen($password) < 8) {
            return ResponseHelper::error($response, '密码请大于8位');
        }

        // 验证两次密码是否一致
        if ($password !== $confirm_password) {
            return ResponseHelper::error($response, '两次密码输入不符');
        }

        // 检查邀请码（如果需要）
        if ($invite_code === '' && Config::obtain('reg_mode') === 'invite') {
            return ResponseHelper::error($response, '邀请码不能为空');
        }

        if ($invite_code !== '') {
            $invite = (new InviteCode())->where('code', $invite_code)->first();
            if ($invite === null || (new User())->where('id', $invite->user_id)->first() === null) {
                return ResponseHelper::error($response, '邀请码无效');
            }
        }

        // 验证邮箱格式
        $imtype = 0;
        $imvalue = '';
        $email_check = Filter::checkEmailFilter($email);
        if (! $email_check) {
            return ResponseHelper::error($response, '无效的邮箱');
        }

        // 检查邮箱是否已注册
        $user = (new User())->where('email', $email)->first();
        if ($user !== null) {
            return ResponseHelper::error($response, '无效的邮箱');
        }

        // 验证邮箱验证码（如果启用）
        if (Config::obtain('reg_email_verify')) {
            $redis = (new Cache())->initRedis();
            $email_verify_code = trim($this->antiXss->xss_clean($request->getParam('emailcode')));
            $email_verify = $redis->get('email_verify:' . $email_verify_code);
            if (! $email_verify) {
                return ResponseHelper::error($response, '你的邮箱验证码不正确');
            }
            $redis->del('email_verify:' . $email_verify_code);
        }

        // 调用注册辅助方法
        return $this->registerHelper($response, $name, $email, $password, $invite_code, $imtype, $imvalue, 0, 0);
    }

    /**
     * 处理登出请求
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return Response
     */
    public function logout(ServerRequest $request, Response $response, $next): Response
    {
        // 执行登出并重定向到登录页面
        Auth::logout();
        return $response->withStatus(302)->withHeader('Location', '/auth/login');
    }
}