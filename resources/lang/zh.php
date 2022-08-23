<?php
/**
 * 中文语言包
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2018/8/10
 * @time 14:39
 */

return [
    'exception_msg' => [
        '34520001' => '检测到你尚未配置 freenom 账户信息，请修改 .env 文件中与账户相关的项，否则程序无法正常运作',
        '34520002' => '登录 freenom 出错。错误信息：%s',
        '34520003' => '域名数据匹配失败，可能是你暂时没有域名或者页面改版导致正则失效，请及时联系作者：https://github.com/luolongfei/freenom/issues',
        '34520004' => 'token 匹配失败，可能是页面改版导致正则失效，请及时联系作者：https://github.com/luolongfei/freenom/issues',
        '34520005' => 'putenv() 函数被禁用，无法写入环境变量导致程序无法正常运作，请启用 putenv() 函数',
        '34520006' => 'PHP 的版本不允许小于 %s，当前 PHP 版本为 %s，请升级你的 PHP 版本，否则无法正常运行。如果不方便升级 PHP，推荐使用本项目的 Docker 版：https://hub.docker.com/r/luolongfei/freenom',
        '34520007' => sprintf('已自动在%s目录下生成 .env 配置文件，请将配置文件中的各项内容修改为你自己的', ROOT_PATH),
        '34520008' => sprintf('请将%s目录下的 .env.example 文件复制为 .env 文件，并将 .env 文件中的各项内容修改为你自己的', ROOT_PATH),
        '34520009' => '获取域名状态页面出错，可能是未登录或者登录失效，请重试。',
        '34520010' => '缺少 curl 模块，无法发送请求，请检查你的 php 环境并在编译时带上 curl 模块',
        '34520012' => '你尚未配置收信邮箱，可能无法收到通知邮件。请将 .env 文件中的 TO 对应的值改为你最常用的邮箱地址，用于接收机器人邮箱发出的域名相关邮件',
        '34520013' => '获取域名状态页面出错，错误信息：%s',
        '34520014' => '你的账户 %s 名下没有发现域名，可能不存在域名。（%s）',
    ],
    'messages' => [
        '100001' => '未能取得名为 WHMCSZH5eHTGhfvzP 的 cookie 值，故本次登录无效，请检查你的账户或密码是否正确。',
        '100002' => '不允许发送空内容邮件',
        '100003' => '非法消息类型',
        '100004' => "执行出错：\n",
        '100005' => '主人，捕获异常',
        '100006' => '执行出错：<red>%s</red>',
        '100007' => '云函数执行成功。',
        '100008' => '云函数执行失败。',
        '100009' => '检测到当前运行环境非普通 VPS，故所有环境变量将直接从环境中读取，环境中找不到的变量，则直接从 .env.example 文件中读取',
        '100010' => '如果是在腾讯云函数，可以参考此处修改或新增环境变量，无需重建：https://github.com/luolongfei/freenom/blob/main/resources/screenshot/scf03.png',
        '100011' => '如果是在阿里云函数，可以直接在【函数详情】->【函数配置】->【环境信息】处编辑环境变量',
        '100012' => '由于你没有开启升级提醒功能，故无法在有新版本可用时第一时间收到通知。将 .env 文件中 NEW_VERSION_DETECTION 的值改为 1 即可重新开启相关功能。',
        '100013' => '检测到你的 .env 文件内容过旧，程式将根据 .env.example 文件自动更新相关配置项，不要慌张，此操作对已有数据不会有任何影响',
        '100014' => '<green>已完成 .env 文件备份</green>，旧文件位置为 %s/.env.old',
        '100015' => '已生成新 .env 文件',
        '100016' => '<green>数据迁移完成</green>，共迁移 %d 条环境变量数据',
        '100017' => '<green>恭喜，已成功完成 .env 文件升级</green>',
        '100018' => '升级 .env 文件出错：',
        '100019' => '从 .env.example 文件生成 .env 文件时出错',
        '100020' => '备份 .env 文件到 .env.old 文件时出错',
        '100021' => '文件不存在：',
        '100022' => '读取文件内容失败：',
        '100023' => 'Github 返回的数据与预期不一致：',
        '100024' => '检测升级出错：',
        '100025' => "我们在 %s 发布了新版 FreeNom 续期工具 v%s，而你当前正在使用的版本为 v%s，你可以根据自己的实际需要决定是否升级到新版本。今次新版有以下更新或改进：\n\n",
        '100026' => '欲知更多信息，请访问：',
        '100027' => '（本消息针对同一个新版只会推送一次，如果你不想收到新版本通知，将 .env 文件中的 NEW_VERSION_DETECTION 的值设为 0 即可）',
        '100028' => '刚刚',
        '100029' => '分钟前',
        '100030' => '小时前',
        '100031' => '昨天 ',
        '100032' => '转人类友好时间出错：',
        '100033' => 'FreeNom 续期工具有新的版本可用，你当前版本为 v%s，最新版本为 v%s。关于新版的详细信息，请访问：%s',
        '100034' => '<green>FreeNom 续期工具有新的版本可用，最新版本为 v%s（%s）</green>',
        '100035' => '主人，FreeNom 续期工具有新的版本（v%s）可用，新版相关情况已给到你',
        '100036' => '有关新版的信息已送信给到你，请注意查收。',
        '100037' => '升级出错：',
        '100038' => '当前程序版本 %s',
        '100039' => '主人，我刚刚帮你续期域名啦~',
        '100040' => '恭喜，成功续期 <green>%d</green> 个域名，失败 <green>%d</green> 个域名。%s',
        '100041' => '详细的续期结果已送信成功，请注意查收。',
        '100042' => "账户：%s\n续期结果如下：\n",
        '100043' => '报告，今天没有域名需要续期',
        '100044' => '当前通知频率为「仅当有续期操作时」，故本次不会推送通知',
        '100045' => '<bg_light_green>%s</bg_light_green>：<light_green>执行成功，今次没有需要续期的域名。</light_green>',
        '100046' => '续期请求出错：%s，域名 ID：%s（账户：%s）',
        '100047' => '具体是在%s文件的第%d行，抛出了一个异常。异常的内容是%s，快去看看吧。（账户：%s）',
        '100048' => '主人，出错了，',
        '100049' => '共发现 <light_cyan>%d</light_cyan> 个 freenom 账户',
        '100050' => '开始处理第 <light_cyan>%d</light_cyan> 个 freenom 账户：<bg_light_green>%s</bg_light_green> [%d/%d]',
        '100051' => '出错：<red>%s</red>',
        '100052' => '出错：<red>%s</red>',
        '100053' => '类 %s 的实例已存在',
        '100054' => '类 %s 不存在',
        '100055' => '由于没有启用「%s」功能，故本次不通过「%s」送信，尽管检测到相关配置。',
        '100056' => '消息服务类 %s 必须继承并实现 MessageServiceInterface 接口',
        '100057' => '程序意外终止',
        '100058' => '可能存在错误，这边收集到的错误信息为：',
        '100059' => '主人，程序意外终止',
        '100060' => '未捕获的异常：',
        '100061' => "具体的异常内容是：\n",
        '100062' => '主人，未捕获的异常',
        '100063' => '开始执行阿里云函数',
        '100064' => '邮件',
        '100065' => 'Telegram Bot',
        '100066' => '企业微信',
        '100067' => 'Server 酱',
        '100068' => 'Bark',
        '100069' => '目前支持Gmail、QQ邮箱、163邮箱以及Outlook邮箱自动识别配置，其它类型的邮箱或者自建邮箱，请在 .env 文件中追加“自定义邮箱配置”的所有相关项，否则无法使用邮件服务。',
        '100070' => '无数据。',
        '100071' => '<a href="http://%s" rel="noopener" target="_blank">%s</a>还有 <span style="font-weight: bold; font-size: 16px;">%d</span> 天到期，',
        '100072' => '。',
        '100073' => '主人',
        '100074' => '作者',
        '100075' => '续期成功：%s<br>',
        '100076' => '续期出错：%s<br>',
        '100077' => '邮件发送失败：',
        '100078' => "\n更多信息可以参考：https://my.freenom.com/domains.php?a=renewals（点击“复制内容”即可复制此网址）",
        '100080' => "无数据。\n",
        '100081' => '%s 还有 %d 天到期，',
        '100082' => "。\n",
        '100083' => "账户 %s 这次续期的结果如下\n\n",
        '100084' => '续期成功：',
        '100085' => '续期出错：',
        '100086' => "\n今次无需续期的域名及其剩余天数如下所示：\n\n",
        '100087' => "我刚刚帮小主看了一下，账户 %s 今天并没有需要续期的域名。所有域名情况如下：\n\n",
        '100088' => '未知原因',
        '100089' => 'Bark 送信失败：<red>%s</red>',
        '100090' => "我刚刚帮小主看了一下，账户 [%s](#) 今天并没有需要续期的域名。所有域名情况如下：\n\n",
        '100091' => "\n更多信息可以参考 [Freenom官网](https://my.freenom.com/domains.php?a=renewals) 哦~",
        '100093' => "无数据。\n",
        '100094' => '[%s](http://%s) 还有 *%d* 天到期，',
        '100095' => "。\n",
        '100096' => "账户 [%s](#) 这次续期的结果如下\n\n",
        '100097' => '续期成功：',
        '100098' => '续期出错：',
        '100099' => "\n今次无需续期的域名及其剩余天数如下所示：\n\n",
        '100100' => '未知原因',
        '100101' => 'Server酱 消息发送失败：<red>%s</red>',
        '100102' => "我刚刚帮小主看了一下，账户 %s 今天并没有需要续期的域名。所有域名情况如下：\n\n",
        '100103' => "\n更多信息可以参考 [Freenom官网](https://my.freenom.com/domains.php?a=renewals) 哦~",
        '100105' => "无数据。\n",
        '100106' => "[%s](http://%s) 还有 *%d* 天到期，\n",
        '100107' => "。\n",
        '100108' => "账户 %s 这次续期的结果如下\n\n",
        '100109' => '续期成功：',
        '100110' => '续期出错：',
        '100111' => "\n今次无需续期的域名及其剩余天数如下所示：\n\n",
        '100112' => 'Telegram 消息发送失败：<red>%s</red>',
        '100113' => '企业微信 access_token 写入失败：',
        '100114' => '获取企业微信 access_token 失败：',
        '100115' => '未知原因',
        '100116' => "\n更多信息可以参考 <a href=\"https://my.freenom.com/domains.php?a=renewals\">Freenom官网</a> 哦~",
        '100118' => "无数据。\n",
        '100119' => '<a href="http://%s">%s</a> 还有 <a href="http://%s">%d</a> 天到期，',
        '100120' => "。\n",
        '100121' => "账户 <a href=\"https://www.google.com\">%s</a> 这次续期的结果如下\n\n",
        '100122' => '续期成功：',
        '100123' => '续期出错：',
        '100124' => "\n今次无需续期的域名及其剩余天数如下所示：\n\n",
        '100125' => "我刚刚帮小主看了一下，账户 <a href=\"https://www.google.com\">%s</a> 今天并没有需要续期的域名。所有域名情况如下：\n\n",
        '100126' => '企业微信送信失败：<red>%s</red>',
        '100127' => '企业微信接口未返回预期的数据响应，本次响应数据为：',
        '100128' => '检测到多次提示 access_token 失效，可能是未能正确获取 access_token，请介入调查：',
        '100129' => '警告：<light_yellow>%s</light_yellow>',
        '100130' => 'IP：%s  位于：%s',
        '100131' => '未知',
        '100132' => '获取 ip 信息出错：',
        '100133' => '（如果你不想每次执行都收到推送，请将 .env 中 NOTICE_FREQ 的值设为 0，使程序只在有续期操作时才推送）',
        '100134' => '【服务器信息】',
    ],
];
