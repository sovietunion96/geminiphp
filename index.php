<?php

define('GEMINI_API_HOST', 'generativelanguage.googleapis.com');

try {
    $request_uri = $_SERVER['REQUEST_URI'];
    $request_method = $_SERVER['REQUEST_METHOD'];
    $request_headers = getallheaders();

    if ($request_uri === "/") {
        http_response_code(200);
        echo "https://" . $_SERVER['HTTP_HOST'] . "/v1";
        return;
    }
 
    $new_url_str = "https://" . GEMINI_API_HOST . $request_uri;
    $new_url = parse_url($new_url_str); // 虽然在这里parse_url 看起来没有直接使用，但为了逻辑对等保留

    $ch = curl_init($new_url_str);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // 需要header信息
    curl_setopt($ch, CURLOPT_NOBODY, false); // 需要body信息

    // 转发请求头
    $headers_to_send = array();
    foreach ($request_headers as $name => $value) {
        if (strtolower($name) !== 'host') { // 排除Host头，让curl自动设置
            $headers_to_send[] = "{$name}: {$value}";
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_to_send);

    // 转发请求体 (如果存在)
    $request_body = file_get_contents('php://input');
    if ($request_body !== false && strlen($request_body) > 0) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    }


    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }

    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header_text = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 发送响应头
    $header_lines = explode("\r\n", trim($header_text));
    foreach ($header_lines as $header_line) {
        // 排除 HTTP/1.1 200 OK 这样的状态行，因为 http_response_code 已经设置了状态码
        if (stripos($header_line, 'HTTP/') !== 0) {
            header($header_line);
        }
    }

    http_response_code($http_code); // 设置HTTP状态码

    echo $body; // 输出响应体

    curl_close($ch);


} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage() . "\n\n" . $e->getTraceAsString();
}

?>
