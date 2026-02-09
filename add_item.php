<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php'; 

function collectFieldLabels($node, array &$map): void {
    if (!is_array($node)) {
        return;
    }

    if (isset($node['label'], $node['props']) && is_array($node['props'])) {
        $key = $node['props']['ui_data_uuid'] ?? $node['props']['resource_key'] ?? null;
        if ($key) {
            $map[$key] = $node['label'];
        }
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            collectFieldLabels($value, $map);
        }
    }
}

try {
    $url_input = $_POST['url'] ?? '';
    if (!preg_match('/detail\/(\d+)/', $url_input, $m)) {
        throw new Exception("解析失败：未从链接中提取到 ID");
    }
    $work_id = (int)$m[1];

    // 1. 获取 Cookie
    $pdo_zm = new PDO("mysql:host=127.0.0.1;dbname=zm;charset=utf8mb4", "zm", "zm123456");
    $cookie = $pdo_zm->query("SELECT key_value FROM settings WHERE key_name = 'feishu_cookie' LIMIT 1")->fetchColumn();
    preg_match('/meego_csrf_token=([^; ]+)/', $cookie, $tok);
    $token = $tok[1] ?? '';

    // 2. 模拟请求（只取基础信息，防止 payload 过于复杂导致报错）
    $payload = json_encode([
        "work_item_id" => $work_id,
        "version" => "2"
    ]);

    $ch = curl_init("https://project.feishu.cn/goapi/v5/workitem/v1/demand_fetch");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Cookie: $cookie",
            "x-meego-csrf-token: $token"
        ]
    ]);
    
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    // 3. 极简解析（Fail-Safe 逻辑）
    // 即使解析失败，我们也给一个默认标题，保证 REPLACE INTO 能执行成功
    $title = "飞书需求 #" . $work_id; 
    $owner = "朱子岩"; // 默认负责人
    $status = "已入库";

    // 尝试从你提供的 JSON 结构路径里抠一下标题
    if (isset($res['data']['work_item_ui_data']['ui_data'])) {
        foreach ($res['data']['work_item_ui_data']['ui_data'] as $f) {
            if (($f['uiType'] ?? '') === 'nameWithComment') {
                $title = $f['uiValue']['nameWithComment']['value'] ?? $title;
            }
        }
    }

    $field_labels = [];
    if (isset($res['data']['modules'])) {
        collectFieldLabels($res['data']['modules'], $field_labels);
    }

    // 4. 强制入库
    $stmt = $pdo->prepare("REPLACE INTO feishu_tasks (work_item_id, title, owner, status, updated_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$work_id, $title, $owner, $status, date("Y-m-d H:i:s")]);

    $response = ['status' => 'success', 'msg' => "添加成功：ID $work_id 已入库"];
    if (!empty($_GET['include_labels'])) {
        $response['field_labels'] = $field_labels;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
