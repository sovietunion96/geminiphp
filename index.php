<?php

// Get the current request URL and method
$requestUrl = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$hostname = $_SERVER['HTTP_HOST'];

// Define the Gemini API host
$GEMINI_API_HOST = "generativelanguage.googleapis.com";

try {
    // Handle root path request
    if ($requestUrl === '/') {
        header('Content-Type: text/plain');
        echo "https://$hostname/v1";
        exit;
    }

    // Prepare the new URL
    $newUrl = "https://$GEMINI_API_HOST$requestUrl";
    
    // Get all headers
    $headers = getallheaders();
    
    // Get the request body
    $requestBody = file_get_contents('php://input');
    
    // Initialize cURL session
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $newUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestMethod);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    // Set request headers
    $curlHeaders = [];
    foreach ($headers as $key => $value) {
        // Skip some headers that shouldn't be forwarded
        if (strtolower($key) === 'host') continue;
        $curlHeaders[] = "$key: $value";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    
    // Set request body if present
    if (!empty($requestBody)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    }
    
    // Execute the request
    $response = curl_exec($ch);
    
    // Check for cURL errors
    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }
    
    // Get the response status code
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Close cURL session
    curl_close($ch);
    
    // Split response headers and body
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $responseBody = substr($response, $headerSize);
    
    // Forward response headers
    $headerLines = explode("\r\n", $responseHeaders);
    foreach ($headerLines as $headerLine) {
        if (strpos($headerLine, 'HTTP/') === 0) continue; // Skip status line
        if (empty($headerLine)) continue;
        header($headerLine);
    }
    
    // Set the response status code
    http_response_code($statusCode);
    
    // Output the response body
    echo $responseBody;
    
} catch (Exception $e) {
    // Handle errors
    http_response_code(500);
    header('Content-Type: text/plain');
    echo $e->getMessage();
}
?>