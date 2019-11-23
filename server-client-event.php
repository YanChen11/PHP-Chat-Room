<?php
    $connections = [];

    $server = 'tcp://0.0.0.0:8900';

    $socket = stream_socket_server($server, $errno, $errstr);
    stream_set_blocking($socket, false);

    echo '等待客户端连接……' . PHP_EOL;

    //  发生错误时的回调
    function ev_error($listener)
    {
        $base = $listener->getBase();
        echo '连接错误：[' . EventUtil::getLastSocketErrno() . ']' . EventUtil::getLastSocketError() . PHP_EOL;
        $base->exit();
    }

    //  收到客户端连接时的回调
    function ev_accept($listener, $socket, $address, $ctx)
    {
        global $connections;
        echo '客户端 ' . join(':', $address) . ' 建立连接……' . PHP_EOL;

        $base = $listener->getBase();
        $buffer_event = new EventBufferEvent($base, $socket, EventBufferEvent::OPT_CLOSE_ON_FREE);
        $buffer_event->setCallbacks('cb_read', 'cb_write', 'cb_event');
        $buffer_event->enable(Event::READ | Event::WRITE);

        //  此处必须使用全局变量记录客户端连接，否则客户端会主动断开连接
        $connections[$socket] = [
            'buffer' => $buffer_event,
            'client' => current($address),
            'username' => '',
            'count' => 0,
        ];
    }

    //  可读事件回调
    function cb_read($buffer, $ctx)
    {
        global $connections;
        $key = $buffer->fd;
        $input = trim($buffer->getInput()->pullup(-1));

        if (!$connections[$key]['username'] && $input) {
            $connections[$key]['username'] = $input;
            $buffer->getOutput()->add('输入 quit 退出聊天' . PHP_EOL);
            // 当有新人加入聊天时，通知其他人
            foreach ($connections as $k => $connection) {
                if ($k != $key && $connection['username']) {
                    $connection['buffer']->getOutput()->add('来自 ' . $connections[$key]['client'] . ' 的 ' . $connections[$key]['username'] . ' 加入聊天' . PHP_EOL);
                }
            }
        } elseif (!$connections[$key]['username']) {
            // 如果输入回车，需要重新输入用户名
            $buffer->getOutput()->add('请输入用户名：');
        } elseif ($input == 'quit') {
            //  当用户输入 quit 时，家属聊天，断开连接，并且通知其他人
            if (count($connections) > 1) {
                foreach ($connections  as $k => $connection) {
                    if ($k != $key) {
                        $connection['buffer']->getOutput()->add('来自 ' . $connections[$key]['client'] . ' 的 ' . $connections[$key]['username'] . ' 退出聊天' . PHP_EOL);
                    }
                }
                unset($connections[$key]);
            }
        } elseif ($input) {
            //  将用户输入的信息发送给其他人
            $info = $connections[$key]['username'] . '[' . $connections[$key]['client'] .']：' . $input . PHP_EOL;
            foreach ($connections as $k => $connection) {
                if ($k != $key && $connection['username']) {
                    $connection['buffer']->getOutput()->add($info);
                }
            }
        }

        // 每次读操作完成后，清除缓存中的数据
        $buffer->getInput()->drain($buffer->getInput()->length);
    }

    //  可写事件回调
    function cb_write($buffer, $ctx)
    {
        global $connections;
        $key = $buffer->fd;
        //  客户端建立连接后，首先需要输入用户名
        if (!$connections[$key]['username'] && $connections[$key]['count'] == 0) {
            $buffer->getOutput()->add('请输入用户名：');
            $connections[$key]['count'] = 1;
        }
        // 每次写操作完成后，清除缓存中的数据
        $buffer->getOutput()->drain($buffer->getOutput()->length);
    }

    //  状态发生变化的事件回调
    function cb_event($buffer, $events, $ctx)
    {
        if ($events & EventBufferEvent::ERROR) {
            echo '错误：[' . EventUtil::getLastSocketErrno() . ']' . EventUtil::getLastSocketError() . PHP_EOL;
        }
        if ($events & (EventBufferEvent::EOF | EventBufferEvent::ERROR)) {
            $buffer->free();
        }
    }

    $config = new EventConfig();
    //  使用 epoll 模型服务端有时会抛异常
    $config->avoidMethod('epoll');

    $base = new EventBase($config);

    $listener = new EventListener($base, 'ev_accept', null, EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE, -1, $socket);

    $listener->setErrorCallback('ev_error');

    echo '开始运行……' . PHP_EOL;

    $base->dispatch();
