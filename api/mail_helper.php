<?php
/**
 * Simple SMTP Client for PHP
 * Derived from various minimal implementations to avoid heavy dependencies like PHPMailer
 */
class SimpleSMTP
{
    private $host, $port, $user, $pass, $from, $fromName;

    public function __construct($host, $port, $user, $pass, $from, $fromName)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->from = $from;
        $this->fromName = $fromName;
    }

    public function send($to, $subject, $body)
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $transport = ($this->port == 465) ? 'ssl://' . $this->host : $this->host;
        $socket = stream_socket_client($transport . ':' . $this->port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);

        if (!$socket)
            return ["success" => false, "error" => "Could not connect to $this->host: $errstr"];

        if (substr($this->getResponse($socket), 0, 3) !== '220')
            return ["success" => false, "error" => "Banner failed"];

        if (substr($this->sendCommand($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost')), 0, 3) !== '250')
            return ["success" => false, "error" => "EHLO failed"];

        if ($this->port == 587) {
            $response = $this->sendCommand($socket, "STARTTLS");
            if (substr($response, 0, 3) !== '220')
                return ["success" => false, "error" => "STARTTLS failed: " . trim($response)];

            // Use TLS 1.2 or higher for modern servers like Gmail, fallback if constant missing
            $method = defined('STREAM_CRYPTO_METHOD_TLS_ANY_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_ANY_CLIENT : (defined('STREAM_CRYPTO_METHOD_TLS_CLIENT') ? STREAM_CRYPTO_METHOD_TLS_CLIENT : 0);
            if (!stream_socket_enable_crypto($socket, true, $method))
                return ["success" => false, "error" => "TLS encryption failed"];

            $response = $this->sendCommand($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
            if (substr($response, 0, 3) !== '250')
                return ["success" => false, "error" => "EHLO after TLS failed: " . trim($response)];
        }

        $response = $this->sendCommand($socket, "AUTH LOGIN");
        if (substr($response, 0, 3) !== '334')
            return ["success" => false, "error" => "AUTH LOGIN failed: " . trim($response)];

        $response = $this->sendCommand($socket, base64_encode($this->user));
        if (substr($response, 0, 3) !== '334')
            return ["success" => false, "error" => "Username rejected: " . trim($response)];

        $response = $this->sendCommand($socket, base64_encode($this->pass));
        if (substr($response, 0, 3) !== '235')
            return ["success" => false, "error" => "Password rejected: " . trim($response) . " (Kiểm tra App Password)"];

        if (substr($this->sendCommand($socket, "MAIL FROM: <$this->from>"), 0, 3) !== '250')
            return ["success" => false, "error" => "MAIL FROM rejected: " . trim($response)];
        if (substr($this->sendCommand($socket, "RCPT TO: <$to>"), 0, 3) !== '250')
            return ["success" => false, "error" => "RCPT TO rejected: " . trim($response)];
        if (substr($this->sendCommand($socket, "DATA"), 0, 3) !== '354')
            return ["success" => false, "error" => "DATA command rejected: " . trim($response)];

        $headers = [
            "MIME-Version: 1.0",
            "Content-type: text/html; charset=utf-8",
            "To: <$to>",
            "From: $this->fromName <$this->from>",
            "Subject: $subject",
            "Date: " . date("r")
        ];

        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
        if (substr($this->getResponse($socket), 0, 3) !== '250')
            return ["success" => false, "error" => "Sending failed at end of DATA"];

        $this->sendCommand($socket, "QUIT");
        fclose($socket);

        return ["success" => true];
    }

    private function sendCommand($socket, $cmd)
    {
        fwrite($socket, $cmd . "\r\n");
        return $this->getResponse($socket);
    }

    private function getResponse($socket)
    {
        $response = "";
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == " ")
                break;
        }
        return $response;
    }
}


function sendMail($to, $subject, $body)
{
    require_once __DIR__ . '/mail_config.php';
    $smtp = new SimpleSMTP(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME);
    $result = $smtp->send($to, $subject, $body);

    // Logging for debugging
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir))
        @mkdir($logDir, 0777, true);
    $logFile = $logDir . '/smtp.log';
    $status = $result['success'] ? 'SUCCESS' : 'FAILED - ' . $result['error'];
    $logMsg = "[" . date('Y-m-d H:i:s') . "] To: $to | Subject: $subject | Result: $status\n";
    @file_put_contents($logFile, $logMsg, FILE_APPEND);

    if (!$result['success']) {
        error_log("SMTP Error sending to $to: " . $result['error']);
    }

    return $result;
}

/**
 * Send activation email to user
 */
function sendActivationEmail($to, $planName, $quota, $expiresAt)
{
    $subject = "Tài khoản 11LABS đã được kích hoạt thành công!";

    $expiresFormatted = date('d/m/Y H:i', strtotime($expiresAt));
    $quotaFormatted = number_format($quota, 0, ',', '.') . " ký tự";

    $body = "
    <div style='font-family: sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px; background-color: #f9f9f9;'>
        <div style='text-align: center; margin-bottom: 20px;'>
            <h1 style='color: #8b5cf6; margin: 0;'>11LABS</h1>
            <p style='color: #666; font-size: 1.1rem;'>Dịch vụ Voice Rental cao cấp</p>
        </div>
        
        <div style='background-color: #fff; padding: 30px; border-radius: 8px;'>
            <h2 style='color: #333;'>Chào mừng bạn!</h2>
            <p style='color: #555; line-height: 1.6;'>
                Chúng tôi xin thông báo tài khoản của bạn đã được kích hoạt thành công. Hiện tại bạn có thể bắt đầu sử dụng các tính năng cao cấp của hệ thống.
            </p>
            
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr>
                    <td style='padding: 10px 0; color: #777; border-bottom: 1px solid #eee;'>Gói cước:</td>
                    <td style='padding: 10px 0; color: #333; font-weight: bold; text-align: right; border-bottom: 1px solid #eee;'>" . strtoupper($planName) . "</td>
                </tr>
                <tr>
                    <td style='padding: 10px 0; color: #777; border-bottom: 1px solid #eee;'>Tổng ký tự:</td>
                    <td style='padding: 10px 0; color: #333; font-weight: bold; text-align: right; border-bottom: 1px solid #eee;'>$quotaFormatted</td>
                </tr>
                <tr>
                    <td style='padding: 10px 0; color: #777; border-bottom: 1px solid #eee;'>Hạn sử dụng:</td>
                    <td style='padding: 10px 0; color: #333; font-weight: bold; text-align: right; border-bottom: 1px solid #eee;'>$expiresFormatted</td>
                </tr>
            </table>
            
            <div style='text-align: center; margin-top: 30px;'>
                <a href='https://11labs.id.vn' style='background-color: #8b5cf6; color: #fff; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Bắt đầu ngay</a>
            </div>
        </div>
        
        <div style='text-align: center; margin-top: 20px; color: #999; font-size: 0.8rem;'>
            <p>Nếu bạn có bất kỳ câu hỏi nào, vui lòng liên hệ nhóm hỗ trợ qua Zalo.</p>
            <p>&copy; 2026 11LABS. All rights reserved.</p>
        </div>
    </div>
    ";

    return sendMail($to, $subject, $body);
}

/**
 * Send welcome email for new account
 */
function sendWelcomeEmail($to, $password, $planName, $quota, $expiresAt)
{
    $subject = "Thông tin tài khoản 11LABS của bạn";

    $expiresFormatted = date('d/m/Y H:i', strtotime($expiresAt));
    $quotaFormatted = number_format($quota, 0, ',', '.') . " ký tự";

    $body = "
    <div style='font-family: sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px; background-color: #f9f9f9;'>
        <div style='text-align: center; margin-bottom: 20px;'>
            <h1 style='color: #8b5cf6; margin: 0;'>11LABS</h1>
        </div>
        
        <div style='background-color: #fff; padding: 30px; border-radius: 8px;'>
            <h2 style='color: #333;'>Xin chào!</h2>
            <p style='color: #555; line-height: 1.6;'>
                Tài khoản 11LABS của bạn đã được tạo thành công bởi Quản trị viên. Dưới đây là thông tin đăng nhập của bạn:
            </p>
            
            <div style='background-color: #f0f0f0; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <p style='margin: 5px 0;'><strong>Email:</strong> $to</p>
                <p style='margin: 5px 0;'><strong>Mật khẩu:</strong> $password</p>
            </div>
            
            <p style='color: #e74c3c; font-size: 0.9rem;'>* Vui lòng đổi mật khẩu sau khi đăng nhập lần đầu để bảo mật tài khoản.</p>
            
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr>
                    <td style='padding: 10px 0; color: #777; border-bottom: 1px solid #eee;'>Gói cước:</td>
                    <td style='padding: 10px 0; color: #333; font-weight: bold; text-align: right; border-bottom: 1px solid #eee;'>" . strtoupper($planName) . "</td>
                </tr>
                <tr>
                    <td style='padding: 10px 0; color: #777; border-bottom: 1px solid #eee;'>Dung lượng:</td>
                    <td style='padding: 10px 0; color: #333; font-weight: bold; text-align: right; border-bottom: 1px solid #eee;'>$quotaFormatted</td>
                </tr>
                <tr>
                    <td style='padding: 10px 0; color: #777; border-bottom: 1px solid #eee;'>Hết hạn:</td>
                    <td style='padding: 10px 0; color: #333; font-weight: bold; text-align: right; border-bottom: 1px solid #eee;'>$expiresFormatted</td>
                </tr>
            </table>
            
            <div style='text-align: center; margin-top: 30px;'>
                <a href='https://11labs.id.vn/login.html' style='background-color: #8b5cf6; color: #fff; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Đăng nhập ngay</a>
            </div>
        </div>
    </div>
    ";

    return sendMail($to, $subject, $body);
}

/**
 * Send welcome email with activation link
 */
function sendActivationLink($to, $token)
{
    $subject = "Kích hoạt tài khoản 11LABS của bạn";
    $activationLink = PHP_BACKEND_URL . "/api/activate.php?token=" . $token;

    $body = "
    <div style='font-family: sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px; background-color: #f9f9f9;'>
        <div style='text-align: center; margin-bottom: 20px;'>
            <h1 style='color: #8b5cf6; margin: 0;'>11LABS</h1>
        </div>
        
        <div style='background-color: #fff; padding: 30px; border-radius: 8px;'>
            <h2 style='color: #333;'>Xác nhận đăng ký!</h2>
            <p style='color: #555; line-height: 1.6;'>
                Cảm ơn bạn đã đăng ký tài khoản tại 11LABS. Để bắt đầu sử dụng dịch vụ, vui lòng nhấn vào nút bên dưới để kích hoạt tài khoản của bạn:
            </p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='$activationLink' style='background-color: #8b5cf6; color: #fff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 1.1rem;'>Kích hoạt tài khoản ngay</a>
            </div>

            <p style='color: #888; font-size: 0.8rem; margin-top: 20px;'>
                Link này sẽ hết hạn sau 24 giờ. Nếu bạn không thực hiện đăng ký này, vui lòng bỏ qua email này.
            </p>
        </div>
        
        <div style='text-align: center; margin-top: 20px; color: #999; font-size: 0.8rem;'>
            <p>&copy; 2026 11LABS. All rights reserved.</p>
        </div>
    </div>
    ";

    return sendMail($to, $subject, $body);
}
?>