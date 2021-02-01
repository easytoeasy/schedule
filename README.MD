## Schedule
重写一个定时任务。

### 功能介绍
1）基于内存管理定时任务，可以通过web获取到每个子进程的状态。
2）更加方便的查看每个子进程的日志
3）可以分布式管理子任务：公用同一个DB，并且将不同的子任务分`server_id`，则可以在不同的服务器上跑不同的`server_id`下的子任务。
4）在同一个服务器上可以支持某个任务的最大并发数设置，但是不同的服务器之间同时跑一个`server_id`会产生并发。
所以不同服务器上跑不同的`server_id`。
5) 结合之前的报警系统和AMQP的封装，可以将报警的内容发送到AMQP，然后在有报警中心处理AMQP的消息并且发送通知。
报警的内容有：
    - 某个脚本运行的时间超过了定时任务间隔时间
    - 主进程理应每分钟循环一次，如果超过了一分钟则报警
    - 某个子进程如果产生了异常，也可以报警
    - 主进程异常退出报警，可自动重启。当然也可以通过supervisor管理了
6）还可以支持某个任务手动重跑功能：这个是很灵活的了，因为需要一些便于操作的功能可以迭代开发。这就是自己自定义定时任务的好处。
7) 支持标签快速定位查询

### 配置启动
首先配置`schedule.ini`文件，会依次在以下目录寻找该文件：
 1）'src/config/schedule.ini'：项目目录底下的配置文件
 2）'/etc/schedule.ini'
 3）'/etc/config/schedule.ini'
 4）'/etc/schedule/schedule.ini'

 配置的内容如下：
 ```ini
[base]
; 每个server对应一个pid文件，{%d} 是server_id的占位符，必须包含，无默认值。
pidfile = /tmp/schedule{%d}.pid
; {%d} 是server_id的占位符，必须包含。默认是0。
logfile = /var/log/schedule{%d}.log
; 可选值：DEBUG、INFO、NOTICE、WARNING、ERROR、CRITICAL、ALERT、EMERGENCY
loglevel = DEBUG

; server_id的id会自动解析，然后替换`base`模块的占位符
[server_1]
host = 127.0.0.1
port = 3401

[server_2]
host = 127.0.0.1
port = 3402

; 模块名称可以随便取，但是调用时必须一致。
[db1]
dbname = test
user = root
password = 123456
host = 'localhost'
driver = pdo_mysql
 ```