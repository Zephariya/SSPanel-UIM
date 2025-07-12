<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Ann;
use App\Models\Config;
use App\Services\Queue;
use App\Models\User;
use App\Services\Notification;
use App\Utils\Tools;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use League\HTMLToMarkdown\HtmlConverter;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function in_array;
use function strip_tags;
use function strlen;
use function time;
use function json_encode;
use const PHP_EOL;

/**
 * 公告管理控制器
 * 提供后台公告的创建、编辑、删除和查看功能
 */
final class AnnController extends BaseController
{
    // 公告列表页面显示字段
    private static array $details =
        [
            'field' => [
                'op' => '操作',
                'id' => 'ID',
                'status' => '状态',
                'sort' => '排序',
                'date' => '日期',
                'content' => '内容（节选）',
            ],
        ];

    // 可更新的字段
    private static array $update_field = [
        'status',
        'sort',
    ];

    /**
     * 显示后台公告列表页面
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     * @throws Exception
     */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write(
            $this->view()
                ->assign('details', self::$details)
                ->fetch('admin/announcement/index.tpl')
        );
    }

    /**
     * 显示公告创建页面
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     * @throws Exception
     */
    public function create(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write(
            $this->view()
                ->assign('update_field', self::$update_field)
                ->fetch('admin/announcement/create.tpl')
        );
    }

    /**
     * 添加新公告
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     */
    public function add(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        // 获取请求参数
        $status = (int) $request->getParam('status');
        $sort = (int) $request->getParam('sort');
        $email_notify_class = (int) $request->getParam('email_notify_class');
        $email_notify = $request->getParam('email_notify') === 'true' ? 1 : 0;
        $content = $request->getParam('content');

        // 验证内容是否为空
        if ($content === '') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '内容不能为空',
            ]);
        }

        // 创建并保存公告
        $ann = new Ann();
        $ann->status = in_array($status, [0, 1, 2]) ? $status : 1;
        $ann->sort = $sort > 999 || $sort < 0 ? 0 : $sort;
        $ann->date = Tools::toDateTime(time());
        $ann->content = $content;

        if (! $ann->save()) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '公告保存失败',
            ]);
        }

        // 如果启用邮件通知，向符合条件的用户发送邮件
        if ($email_notify) {
            $users = (new User())->where('class', '>=', $email_notify_class)
                ->where('is_banned', '=', 0)
                ->get();
            $subject = $_ENV['appName'] . ' - 新公告发布';

            foreach ($users as $user) {
                (new Queue('email_queue'))->add(
                    [
                        'to_email' => $user->email,
                        'subject' => $subject,
                        'template' => 'warn.tpl',
                        'array' => json_encode([
                            'user' => $user,
                            'text' => $content,
                        ])
                    ],
                    'email'
                );
            }
        }

        // 如果启用 IM 群组通知，发送公告到群组
        if (Config::obtain('im_bot_group_notify_ann_create')) {
            $converter = new HtmlConverter(['strip_tags' => true]);
            $content = $converter->convert($content);

            try {
                Notification::notifyUserGroup('新公告：' . PHP_EOL . $content);
            } catch (TelegramSDKException | GuzzleException) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => $email_notify === 1 ? '公告添加成功，邮件发送成功，IM Bot 发送失败' : '公告添加成功，IM Bot 发送失败',
                ]);
            }
        }

        // 返回成功响应
        return $response->withJson([
            'ret' => 1,
            'msg' => $email_notify === 1 ? '公告添加成功，邮件发送成功' : '公告添加成功',
        ]);
    }

    /**
     * 显示公告编辑页面
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     * @throws Exception
     */
    public function edit(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->write(
            $this->view()
                ->assign('ann', (new Ann())->find($args['id']))
                ->assign('update_field', self::$update_field)
                ->fetch('admin/announcement/edit.tpl')
        );
    }

    /**
     * 更新公告
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     */
    public function update(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        // 获取请求参数
        $status = (int) $request->getParam('status');
        $sort = (int) $request->getParam('sort');
        $content = $request->getParam('content');

        // 验证内容是否为空
        if ($content === '') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '内容不能为空',
            ]);
        }

        // 查找公告
        $ann = (new Ann())->find($args['id']);
        if ($ann === null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '公告不存在',
            ]);
        }

        // 更新公告字段
        $ann->status = in_array($status, [0, 1, 2]) ? $status : 1;
        $ann->sort = $sort > 999 || $sort < 0 ? 0 : $sort;
        $ann->content = $content;
        $ann->date = Tools::toDateTime(time());

        if (! $ann->save()) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '公告更新失败',
            ]);
        }

        // 如果启用 IM 群组通知，发送更新通知
        if (Config::obtain('im_bot_group_notify_ann_update')) {
            $converter = new HtmlConverter(['strip_tags' => true]);
            $content = $converter->convert($ann->content);

            try {
                Notification::notifyUserGroup('公告更新：' . PHP_EOL . $content);
            } catch (TelegramSDKException | GuzzleException) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '公告更新成功，IM Bot 发送失败',
                ]);
            }
        }

        // 返回成功响应
        return $response->withJson([
            'ret' => 1,
            'msg' => '公告更新成功',
        ]);
    }

    /**
     * 删除公告
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     */
    public function delete(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        // 删除指定公告
        if ((new Ann())->find($args['id'])->delete()) {
            return $response->withJson([
                'ret' => 1,
                'msg' => '删除成功',
            ]);
        }

        return $response->withJson([
            'ret' => 0,
            'msg' => '删除失败',
        ]);
    }

    /**
     * AJAX 获取公告列表
     * @param ServerRequest $request HTTP 请求
     * @param Response $response HTTP 响应
     * @param array $args 路由参数
     * @return ResponseInterface
     */
    public function ajax(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        // 获取所有公告并按 ID 排序
        $anns = (new Ann())->orderBy('id')->get();

        foreach ($anns as $ann) {
            // 添加操作按钮
            $ann->op = '<button class="btn btn-red" id="delete-announcement-' . $ann->id . '" 
            onclick="deleteAnn(' . $ann->id . ')">删除</button>
            <a class="btn btn-primary" href="/admin/announcement/' . $ann->id . '/edit">编辑</a>';
            $ann->status = $ann->status();
            // 截取内容前 40 个字符
            $ann->content = strlen($ann->content) > 40 ? mb_substr(strip_tags($ann->content), 0, 40, 'UTF-8') . '...' : $ann->content;
        }

        // 返回 JSON 响应
        return $response->withJson([
            'anns' => $anns,
        ]);
    }
}