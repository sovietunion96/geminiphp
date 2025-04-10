<?php

// 目标 API 主机
define('GEMINI_API_HOST', 'generativelanguage.googleapis.com');

// --- Helper Function: getallheaders() Polyfill ---
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            } elseif ($name == "CONTENT_TYPE") {
                $headers["Content-Type"] = $value;
            } elseif ($name == "CONTENT_LENGTH") {
                $headers["Content-Length"] = $value;
            }
        }
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

// 辅助函数：从查询字符串中提取 request_url 参数
function getRequestUrlFromQueryString() {
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($queryString, $queryParams);
    
    // 如果直接解析失败，尝试手动处理
    if (empty($queryParams['request_url']) && strpos($queryString, 'request_url=') === 0) {
        $requestUrl = substr($queryString, strlen('request_url='));
        // 解码并重新编码，确保正确性
        $requestUrl = urldecode($requestUrl);
        return $requestUrl;
    }
    
    return $queryParams['request_url'] ?? '';
}

try {
    // 1. 处理根路径 '/'
    if ($_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '/index.php') {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        header("Content-Type: text/plain; charset=utf-8");
        http_response_code(200);
        echo $scheme . '://' . $host . '/v1';
        exit;
    }

    // 2. 获取请求路径（正确处理包含问号的情况）
    $requestPath = getRequestUrlFromQueryString();
    if (empty($requestPath)) {
        $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }

    // 3. 构建目标 URL
    $targetUrl = 'https://' . GEMINI_API_HOST . $requestPath;
    
    // 如果原始请求有查询参数且不是通过 request_url 传递的，附加到目标URL
    if (strpos($requestPath, '?') === false && isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
        $queryString = $_SERVER['QUERY_STRING'];
        // 移除可能已经包含的request_url部分
        if (strpos($queryString, 'request_url=') === 0) {
            $queryString = '';
        } elseif (strpos($queryString, '&request_url=') !== false) {
            $queryString = preg_replace('/&?request_url=[^&]*/', '', $queryString);
        }
        
        if (!empty($queryString)) {
            $targetUrl .= (strpos($targetUrl, '?') === false ? '?' : '&') . $queryString;
        }
    }

    // 4. 初始化 cURL
    $ch = curl_init();
    
    // 5. 设置 cURL 选项
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    // 6. 处理请求头
    $incomingHeaders = getallheaders();
    $outgoingHeaders = [];
    foreach ($incomingHeaders as $key => $value) {
        $lowerKey = strtolower($key);
        if ($lowerKey !== 'host' && $lowerKey !== 'content-length' && $lowerKey !== 'connection' && $lowerKey !== 'expect') {
            $outgoingHeaders[] = "$key: $value";
        }
    }
    $outgoingHeaders[] = 'Host: ' . GEMINI_API_HOST;
    $outgoingHeaders[] = 'Expect:';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $outgoingHeaders);

    // 7. 处理请求体
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        $requestBody = file_get_contents('php://input');
        if ($requestBody !== false && strlen($requestBody) > 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }
    }

    // 8. 执行 cURL 请求
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        http_response_code(502);
        header("Content-Type: text/plain; charset=utf-8");
        echo "Proxy Error: Failed to connect to upstream server.\n";
        echo "cURL Error (" . curl_errno($ch) . "): " . curl_error($ch);
        curl_close($ch);
        exit;
    }

    // 9. 处理响应
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    // 10. 发送响应
    http_response_code($httpCode);
    
    $headerLines = explode("\r\n", trim($responseHeaders));
    foreach ($headerLines as $line) {
        if (stripos($line, 'HTTP/') === 0) {
            continue;
        }
        $headerParts = explode(':', $line, 2);
        if (count($headerParts) === 2) {
            $headerKey = trim(strtolower($headerParts[0]));
            $hopByHopHeaders = ['transfer-encoding', 'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailers', 'upgrade'];
            if (!in_array($headerKey, $hopByHopHeaders)) {
                header(trim($line), false);
            }
        } elseif (trim($line) !== '') {
            header(trim($line), false);
        }
    }
    
    echo $responseBody;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo $e->getMessage();
}