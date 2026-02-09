<?php
/**
 * UA 自动监控 - 后台定时执行版
 * 严格保持原逻辑，实现 24 小时离线监控
 */

// 设置脚本永不超时
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. 引入数据库配置文件 (获取 $pdo)
require __DIR__ . '/db.php';
date_default_timezone_set('PRC'); // 设置为中国时区
// 2. 初始化 kf 库连接 (获取 $db，严格同步 index.php 逻辑)
$db = new mysqli('localhost', 'kf', 'KF123456', 'kf');
if ($db->connect_error) die("DB Connection Failed: " . $db->connect_error);
$db->query("SET NAMES utf8mb4");

echo "--- 监控任务开始 " . date('Y-m-d H:i:s') . " ---\n";

// 3. 获取所有正在运行的任务 (status=1)
$res = $db->query("SELECT * FROM ua_tasks WHERE status = 1");
$today = date('Y-m-d');

if ($res->num_rows === 0) {
    echo "当前无运行中的任务。\n";
}

while ($task = $res->fetch_assoc()) {
    $id = $task['id'];
    $game = $task['game'];
    $platform = $task['platform'];
    $threshold = (int)$task['threshold'];
    $startDate = $task['start_date'];
    $endDate = $task['end_date'];

    echo "正在检查任务 [{$id}] {$game}-{$platform}... ";

    // --- 日期范围校验 ---
    if ($startDate && $today < $startDate) { echo "未到期跳过\n"; continue; }
    if ($endDate && $today > $endDate) {
        $db->query("UPDATE ua_tasks SET status=0, stop_reason='监控周期已结束' WHERE id=$id");
        echo "已到期停止\n";
        continue;
    }

    // --- 每日达标跳过逻辑：如果今日已达标报警过，不再重复请求 ---
    $lastVal = (int)($task['last_count'] ?? 0);
    $lastTime = $task['last_time'] ?? '';
    if ($lastVal >= $threshold && strpos($lastTime, $today) !== false) {
        echo "今日已达标，跳过查询\n";
        continue;
    }

    // --- 抓取逻辑：严格复用原 payload ---
    $stmt_cookie = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'ua_cookie'");
    $stmt_cookie->execute();
    $userCookie = $stmt_cookie->fetchColumn() ?: '';

    if (empty($userCookie)) {
        echo "Cookie缺失，尝试重登... ";
        sendCookieStatusLark_Cron($pdo, '⚠️ Cookie 缺失', '未读取到 Cookie，开始自动登录获取。');
        $userCookie = autoLoginLeniu_Cron($pdo);
        if ($userCookie) {
            echo "重登成功 ";
        } else {
            echo "重登失败\n";
            continue;
        }
    }

    $url = 'https://ad.leniugame.com/ServerDataMonitor/serverData';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['leniuTjGlobalQueryTabHeadTag' => '0', 'date_hidden' => date('Ymd/Ymd'), 'is_roll' => '0']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Cookie: ' . $userCookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
        ]
    ]);
    
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($resp, true);

    $dataValid = is_array($data) && isset($data['data']['rows']) && is_array($data['data']['rows']);

    // 自动重登逻辑
    if ($resp === false || $curlErr || $httpCode >= 400 || !$dataValid || (isset($data['code']) && $data['code'] == 1001)) {
        $detail = "请求异常，HTTP={$httpCode}。";
        if ($curlErr) {
            $detail .= " cURL 错误: {$curlErr}。";
        }
        if (!$dataValid && $resp !== false) {
            $detail .= "响应结构异常或未包含 rows。";
        }
        echo "Cookie失效，尝试重登... ";
        sendCookieStatusLark_Cron($pdo, '⚠️ Cookie 失效', $detail . '开始自动登录获取。');
        $newCookie = autoLoginLeniu_Cron($pdo);
        if ($newCookie) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Cookie: ' . $newCookie,
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
            ]);
            $resp = curl_exec($ch);
            $curlErr = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $data = json_decode($resp, true);
            $dataValid = is_array($data) && isset($data['data']['rows']) && is_array($data['data']['rows']);
            if ($resp === false || $curlErr || $httpCode >= 400 || !$dataValid) {
                sendCookieStatusLark_Cron($pdo, '⚠️ Cookie 获取失败', '重登后接口响应仍异常，无法获取有效数据。');
            }
            echo "重登成功 ";
        } else {
            echo "重登失败 ";
        }
    }
    curl_close($ch);

    // 数据解析
    $count = 0;
    $found = false;
    $serverArr = [];
    if (isset($data['data']['rows']) && is_array($data['data']['rows'])) {
        foreach ($data['data']['rows'] as $row) {
            if (trim($row['game_name']) == $game && trim($row['platform_name']) == $platform) {
                $count += (int)($row['pay_role_new'] ?? 0);
                $serverArr[] = $row['server_name'] ?? '';
                $found = true;
            }
        }
    }

    // 更新 DB
    $time = date('Y-m-d H:i:s');
    $db->query("UPDATE ua_tasks SET last_count='$count', last_time='$time' WHERE id=$id");
    echo "结果: {$count} ";

    // 飞书报警
    if ($found && $count >= $threshold) {
        echo "触发报警！";
        sendLark_Cron($pdo, $game, $platform, $count, $threshold, implode(' / ', array_unique($serverArr)));
    }
    echo "\n";
}
echo "--- 监控任务完成 ---\n";

/** 辅助函数：飞书通知 **/
function sendLark_Cron($pdo, $game, $platform, $count, $threshold, $servers) {
    $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'ua_webhook'");
    $stmt->execute();
    $webhook = $stmt->fetchColumn();
    if (empty($webhook)) return;

    $message = [
        "msg_type" => "interactive",
        "card" => [
            "header" => ["title" => ["tag" => "plain_text", "content" => "🚨 离线监控预警"], "template" => "red"],
            "elements" => [
                ["tag" => "div", "text" => ["tag" => "lark_md", "content" => "**游戏：** $game\n**平台：** $platform\n**区服：** $servers\n**当前首日付费：** **$count**\n**预警阈值：** $threshold\n\n后台自动检出达标。"]]
            ]
        ]
    ];
    $ch = curl_init($webhook);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode($message), CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($ch); curl_close($ch);
}

/** 辅助函数：Cookie 状态通知 **/
function sendCookieStatusLark_Cron($pdo, $title, $detail) {
    $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'ua_webhook'");
    $stmt->execute();
    $webhook = $stmt->fetchColumn();
    if (empty($webhook)) return;

    $message = [
        "msg_type" => "interactive",
        "card" => [
            "header" => ["title" => ["tag" => "plain_text", "content" => $title], "template" => "blue"],
            "elements" => [
                ["tag" => "div", "text" => ["tag" => "lark_md", "content" => $detail]]
            ]
        ]
    ];
    $ch = curl_init($webhook);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode($message), CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($ch); curl_close($ch);
}

/** 辅助函数：重登逻辑 **/
function autoLoginLeniu($pdo) {
    // 1. 创建临时“饼干盒”文件存储 Cookie
    $cookieFile = tempnam(sys_get_temp_dir(), 'ln_');
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    $ch = curl_init();
    
    // --- 第一步：先刷一下登录页，领取 PHPSESSID ---
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://bloc.leniugame.com/Login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_COOKIEJAR => $cookieFile, 
    ]);
    curl_exec($ch);

    // --- 第二步：提交账号密码，并跟随 302 跳转获取完整 Token ---
    $loginUrl = 'https://bloc.leniugame.com/Login/account';
    $postData = 'ln_aaaaa=zhuizyan&ln_ddddd=5f05862b03f97c65b123b56f92d8a284d3e4971a82d4ee76baff98be6f0b1b83785cd91b6b0d9e92dcf7b8c9e71cbf9b2a6bf303fc82ab634473c6bc6c766f7f3eca9ccf5a759fb7971486299fc45cd6c2dd6ff35b45e1100c2c7a18cdd75b89a70163bbd037f48a8ab4c7e15afe591908e401a27910912292f91a5b3fffe808&user_name=&code=&codeType=1&__hash__=67cd93aa40c81b1e2eb4059182c172af_2c239120b8a73d3edbc74d6a20460dd6';

    curl_setopt_array($ch, [
        CURLOPT_URL => $loginUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_COOKIEFILE => $cookieFile, // 带着第一步的 PHPSESSID 去登录
        CURLOPT_COOKIEJAR => $cookieFile,  // 把新产生的 ln_auth 存进去
        CURLOPT_FOLLOWLOCATION => true,    // 关键：跟随跳转，拿齐后续所有 Cookie
        CURLOPT_REFERER => 'https://bloc.leniugame.com/Login',
    ]);
    curl_exec($ch);
    curl_close($ch);

    // --- 第三步：把“饼干盒”里的内容转成数据库需要的字符串 ---
    if (file_exists($cookieFile)) {
        $lines = file($cookieFile);
        $cookieArray = [];
        foreach ($lines as $line) {
            if (substr($line, 0, 1) == '#' || trim($line) == '') continue;
            $parts = preg_split("/\t/", $line);
            if (isset($parts[5]) && isset($parts[6])) {
                $cookieArray[trim($parts[5])] = trim($parts[6]);
            }
        }
        @unlink($cookieFile); // 用完删掉临时文件

        if (!empty($cookieArray)) {
            $finalStr = "";
            foreach ($cookieArray as $k => $v) $finalStr .= "$k=$v; ";
            $finalStr = rtrim($finalStr, "; ");
            
            $pdo->prepare("REPLACE INTO settings (key_name, key_value) VALUES ('ua_cookie', ?)")->execute([$finalStr]);
            return $finalStr;
        }
    }
    return false;
}
