<?php
    $server = 'tcp://0.0.0.0:8900';

    $socket = stream_socket_server($server, $errno, $errstr);
    stream_set_blocking($socket, false);

    echo '等待客户端连接……' . PHP_EOL;

    function ev_accept($socket, $flag)
    {
        $connection = stream_socket_accept($socket);
        $name = stream_socket_get_name($connection, true);

        while ($connection) {
            $contents = trim(fread($connection, 1024));
            if (strlen($contents)) {
                $info = 'From: ' . $name . PHP_EOL . 'Contents: ' . $contents . PHP_EOL . 'Time: ' . date('Y-m-d H:i:s') . PHP_EOL . PHP_EOL;
                echo $info;
                $response = 'Request: ' . $contents . PHP_EOL . 'Response: OK' . PHP_EOL . 'Time: ' . date('Y-m-d H:i:s') . PHP_EOL;
                stream_socket_sendto($connection, $response);
                //  如果客户端输入为 quit，则断开连接
                if ($contents == 'quit') {
                    echo 'disconnect the client ' . $name . PHP_EOL;
                    stream_socket_sendto($connection, 'close the connection to the server');
                    stream_socket_shutdown($connection, STREAM_SHUT_RDWR);
                }
            }
        }
    }

    $base = new EventBase();

    $event = new Event($base, $socket, EV_READ | EV_PERSIST, 'ev_accept');

    $event->add();

    echo '开始运行……' . PHP_EOL;

    $base->loop();
