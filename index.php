<?php

// 目标 API 主机
define('GEMINI_API_HOST', 'generativelanguage.googleapis.com');
// --- Helper Function: getallheaders() Polyfill ---
// getallheaders() 在某些 SAPI（如 Nginx + FPM）下可能不可用
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                // 将 HTTP_HOST_NAME 转换为 Host-Name
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            } elseif ($name == "CONTENT_TYPE") {
                $headers["Content-Type"] = $value;
            } elseif ($name == "CONTENT_LENGTH") {
                $headers["Content-Length"] = $value;
            }
        }
        // 如果 'Authorization' 头通过其他方式设置（例如 Apache 配置），尝试获取它
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
             $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['PHP_AUTH_DIGEST'])) {
             $headers['Authorization'] = $_SERVER['PHP_AUTH_DIGEST'];
        } elseif (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
             $headers['Authorization'] = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
             $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}
// --- End Helper Function ---
try {
    // 获取请求路径
    $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // 1. 处理根路径 '/'
    if ($requestPath === '/' || $requestPath === '/index.php' || $requestPath === '') { // 处理常见的根路径变体
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        header("Content-Type: text/plain; charset=utf-8");
        http_response_code(200);
        // 输出类似 Cloudflare Worker 的提示信息
        echo $scheme . '://' . $host . '/v1';
        exit;
    }
    // 2. 构建目标 URL
    // 目标 API 强制使用 HTTPS
    $targetUrl = 'https://' . GEMINI_API_HOST . $_SERVER['REQUEST_URI']; // 包含路径和查询参数
    echo $targetUrl;
    // 3. 初始化 cURL
    $ch = curl_init();
    // 4. 设置 cURL 选项
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // 返回响应而不是直接输出
    curl_setopt($ch, CURLOPT_HEADER, true);         // 包含响应头信息
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']); // 设置请求方法 (GET, POST, etc.)
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 跟随重定向
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);         // 最大重定向次数
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);          // 请求超时时间 (秒)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // 验证 SSL 证书
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);    // 验证 SSL 主机名
    // 5. 处理请求头
    $incomingHeaders = getallheaders();
    $outgoingHeaders = [];
    foreach ($incomingHeaders as $key => $value) {
        // 过滤掉与连接相关的头或由 cURL 自动处理的头
        $lowerKey = strtolower($key);
        if ($lowerKey !== 'host' && $lowerKey !== 'content-length' && $lowerKey !== 'connection' && $lowerKey !== 'expect') {
            $outgoingHeaders[] = "$key: $value";
        }
    }
    // 添加或覆盖 Host 头，指向目标服务器
    $outgoingHeaders[] = 'Host: ' . GEMINI_API_HOST;
    // 添加 Expect: 头，防止 100-continue 状态 (某些服务器可能不支持)
    $outgoingHeaders[] = 'Expect:';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $outgoingHeaders);
    // 6. 处理请求体 (适用于 POST, PUT, PATCH 等)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'PATCH' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $requestBody = file_get_contents('php://input');
        if ($requestBody !== false && strlen($requestBody) > 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            // 如果原始请求有 Content-Type，确保也传递
            // (已在上面的 $outgoingHeaders 处理中包含)
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
             // 发送一个空的POST请求体，如果原始请求没有内容但方法是POST
             curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
    }
    // 7. 执行 cURL 请求
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    // 8. 处理 cURL 错误
    if ($curlErrno) {
        http_response_code(502); // Bad Gateway - 表示代理出错
        header("Content-Type: text/plain; charset=utf-8");
        echo "Proxy Error: Failed to connect to upstream server.\n";
        echo "cURL Error ($curlErrno): " . $curlError;
        curl_close($ch);
        exit;
    }
    // 9. 分离响应头和响应体
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    // 10. 关闭 cURL
    curl_close($ch);
    // 11. 设置响应状态码
    http_response_code($httpCode);
    // 12. 发送从目标服务器收到的响应头
    $headerLines = explode("\r\n", trim($responseHeaders));
    foreach ($headerLines as $line) {
        // 过滤掉 HTTP 状态行 (例如 "HTTP/1.1 200 OK")
        // 过滤掉与连接控制相关的头 (由 Web 服务器处理)
        if (stripos($line, 'HTTP/') === 0) {
            continue;
        }
        $headerParts = explode(':', $line, 2);
        if (count($headerParts) === 2) {
            $headerKey = trim(strtolower($headerParts[0]));
            // 这些头通常由 PHP 或 Web 服务器处理，或者不应直接转发
            $hopByHopHeaders = ['transfer-encoding', 'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailers', 'upgrade'];
            if (!in_array($headerKey, $hopByHopHeaders)) {
                 // false 参数防止覆盖同名头，允许设置多个 Set-Cookie 等
                header(trim($line), false);
            }
        } elseif (trim($line) !== '') {
             // 处理可能存在的非键值对格式的头（虽然不常见）
             header(trim($line), false);
        }
    }
    // 13. 发送响应体
    echo $responseBody;
} catch (Exception $e) {
    // Handle errors
    http_response_code(500);
    header('Content-Type: text/plain');
    echo $e->getMessage();
}
?>