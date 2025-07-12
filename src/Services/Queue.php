<?php
declare(strict_types=1);

namespace App\Services;

use Redis;
use function json_encode;
use function time;

/**
 * 通用队列处理类
 * 用于管理 Redis 队列的任务添加、弹出和删除
 * @property string $queueName 队列名称
 * @property int    $ttl       任务存活时间（秒）
 */
final class Queue
{
    // Redis 连接类型
    protected $connection = 'redis';
    // 默认队列名称
    protected $queueName = 'default_queue';
    // Redis 客户端实例
    private $redis;
    // 任务默认存活时间（24小时）
    private $ttl = 86400;

    /**
     * 构造函数，初始化队列
     * @param string $queueName 队列名称
     */
    public function __construct(string $queueName = 'default_queue')
    {
        $this->queueName = $queueName;
        $this->redis = (new Cache())->initRedis();
    }

    /**
     * 添加任务到队列
     * @param array $data 任务数据
     * @param string $type 任务类型
     */
    public function add(array $data, string $type): void
    {
        // 构造任务数据
        $taskData = [
            'id' => $this->generateUniqueId(),
            'type' => $type,
            'data' => $data,
            'time' => time(),
        ];

        // 生成任务键并添加到队列
        $key = $this->queueName . ':' . $taskData['id'];
        $this->redis->rPush($this->queueName, $key);
        $this->redis->setEx($key, $this->ttl, json_encode($taskData));
    }

    /**
     * 获取队列长度
     * @return int 队列中的任务数量
     */
    public function count(): int
    {
        return (int) $this->redis->lLen($this->queueName);
    }

    /**
     * 非阻塞弹出任务
     * @return array|null 任务数据或 null
     */
    public function pop(): ?array
    {
        $key = $this->redis->lPop($this->queueName);
        if ($key === false) {
            return null;
        }
        $data = $this->redis->get($key);
        $this->redis->del($key);
        return $data ? json_decode($data, true) : null;
    }

    /**
     * 阻塞弹出任务
     * @param int $timeout 超时时间（秒）
     * @return array|null 任务数据或 null
     */
    public function blockingPop(int $timeout = 30): ?array
    {
        $result = $this->redis->brPop([$this->queueName], $timeout);

        if ($result === null || count($result) !== 2) {
            return null;
        }

        [$queue, $key] = $result;
        $data = $this->redis->get($key);
        $this->redis->del($key);
        return $data ? json_decode($data, true) : null;
    }

    /**
     * 查询条件过滤（占位，依赖 TTL 自动清理）
     * @param mixed $column 列名
     * @param mixed $operator 操作符
     * @param mixed $value 值
     * @return self
     */
    public function where($column, $operator, $value): self
    {
        return $this;
    }

    /**
     * 删除整个队列及其关联数据
     */
    public function delete(): void
    {
        $keys = $this->redis->lRange($this->queueName, 0, -1);
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
        $this->redis->del($this->queueName);
    }

    /**
     * 生成唯一任务 ID
     * @return string 唯一 ID
     */
    private function generateUniqueId(): string
    {
        return uniqid('task_', true);
    }
}