<?php
// Config: Mã bảo mật để tránh người lạ đổi link (Khớp với Colab)
$SECRET_KEY = "5nl7gYxSm2XTqTGR"; 

// 1. Nhận dữ liệu cập nhật từ Colab
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Check bảo mật
    if (!isset($input['secret']) || $input['secret'] !== $SECRET_KEY) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Sai mã bảo mật!']);
        exit;
    }

    if (isset($input['url'])) {
        $data = [
            'url' => rtrim($input['url'], '/'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Lưu vào file JSON
        file_put_contents('server_config.json', json_encode($data));
        
        echo json_encode(['status' => 'success', 'message' => 'Đã cập nhật URL', 'data' => $data]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Thiếu URL']);
    }
    exit;
}

// 2. Trả về URL hiện tại cho Frontend (Frontend gọi GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *'); // Cho phép Frontend gọi ajax
    
    if (file_exists('server_config.json')) {
        echo file_get_contents('server_config.json');
    } else {
        echo json_encode(['url' => null, 'message' => 'Chưa có URL nào được lưu.']);
    }
    exit;
}
?>
