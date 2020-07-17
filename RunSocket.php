<?php
/**
 * Created by PhpStorm.
 * User: mf
 * Date: 2020/5/23
 * Time: 17:32
 */

require_once './RedisService.php';

class RunSocket
{
    private $config;
    private $server;
    private $client;
    private $key = "socket:user";

    public function __construct()
    {
        // 实例化配置
        $this->config = [
			'host' => '0.0.0.0',
			'port' => 9501,

			'redis' => [
				'host' => '0.0.0.0',
				'port' => 6379
			],

			'avatar' => [
				'./images/avatar/1.jpg',
				'./images/avatar/2.jpg',
				'./images/avatar/3.jpg',
				'./images/avatar/4.jpg',
				'./images/avatar/5.jpg',
				'./images/avatar/6.jpg'
			],

			'name' => [
				'科比',
				'库里',
				'KD',
				'KG',
				'乔丹',
				'邓肯',
				'格林',
				'汤普森',
				'伊戈达拉',
				'麦迪',
				'艾弗森',
				'卡哇伊',
				'保罗'
			]
		];
        // redis
        $this->initRedis();
        // 初始化，主要是服务端自己关闭不会清空redis
        foreach ($this->allUser() as $item) {
            $this->client->hdel("{$this->key}:{$item['fd']}", 'fd, name, avatar');
        }
    }

    public function run()
    {
        $this->server = new \swoole_websocket_server(
            $this->config['host'],
            $this->config['port']
        );

        $this->server->set(array("open_websocket_close_frame" => true));
        $this->server->on('open', [$this, 'open']);
        $this->server->on('message', [$this, 'message']);
        $this->server->on('close', [$this, 'close']);

        $this->server->start();
    }

    public function open(\swoole_websocket_server $server, \swoole_http_request $request)
    {
        $user = [
            'fd' => $request->fd,
            'name' => $this->config['name'][array_rand($this->config['name'])] . $request->fd,
            'avatar' => $this->config['avatar'][array_rand($this->config['avatar'])]
        ];
        // 放入redis
        $this->client->hMset("{$this->key}:{$user['fd']}", $user);

        // 给每个人推送，包括自己
        foreach ($this->allUser() as $item) {
            $server->push($item['fd'], json_encode([
                'user' => $user,
                'all' => $this->allUser(),
                'datetime' => date('Y-m-d H:i:s'),
                'type' => 'openSuccess'
            ]));
        }
    }

    private function allUser()
    {
        $users = [];
        $keys = $this->client->keys("{$this->key}:*");
        // 所有的key
        foreach ($keys as $k => $item) {
            $users[$k]['fd'] = $this->client->hGet($item, 'fd');
            $users[$k]['name'] = $this->client->hGet($item, 'name');
            $users[$k]['avatar'] = $this->client->hGet($item, 'avatar');
        }
        return $users;
    }

    public function message(\swoole_websocket_server $server, \swoole_websocket_frame $frame)
    {
        $this->pushMessage($server, $frame->data, 'message', $frame->fd);
    }

    /**
     * 推送消息
     *
     * @param \swoole_websocket_server $server
     * @param string $message
     * @param string $type
     * @param int $fd
     */
    private function pushMessage(\swoole_websocket_server $server, string $message, string $type, int $fd)
    {
        $message = htmlspecialchars($message);
        $datetime = date('Y-m-d H:i:s', time());
        $user['fd'] = $this->client->hGet("{$this->key}:{$fd}", 'fd');
        $user['name'] = $this->client->hGet("{$this->key}:{$fd}", 'name');
        $user['avatar'] = $this->client->hGet("{$this->key}:{$fd}", 'avatar');

        //如果是心跳检测，则不推送
        if($message == 'ping') {
            return $server->push($fd, 'pingSuccess');
        }

        foreach ($this->allUser() as $item) {
            // 自己不用发送
            if ($item['fd'] == $fd) {
                continue;
            }

            $is_push = $server->push($item['fd'], json_encode([
                'type' => $type,
                'message' => $message,
                'datetime' => $datetime,
                'user' => $user
            ]));
            // 删除失败的推送
            if (!$is_push) {
                $this->client->hdel("{$this->key}:{$item['fd']}", 'fd, name, avatar');
            }
        }
    }

    /**
     * 客户端关闭的时候
     *
     * @param \swoole_websocket_server $server
     * @param int $fd
     */
    public function close(\swoole_websocket_server $server, int $fd)
    {
        $user['fd'] = $this->client->hGet("{$this->key}:{$fd}", 'fd');
        $user['name'] = $this->client->hGet("{$this->key}:{$fd}", 'name');
        $user['avatar'] = $this->client->hGet("{$this->key}:{$fd}", 'avatar');
        $this->pushMessage($server, "{$user['name']}离开聊天室", 'close', $fd);
        $this->client->hdel("{$this->key}:{$fd}", 'fd, name, avatar');
    }

    /**
     * 初始化redis
     */
    private function initRedis()
    {
        $this->client = RedisService::getInstance($this->config['redis']);
    }
}

$ser = new RunSocket();
$ser->run();