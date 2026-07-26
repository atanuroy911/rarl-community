<?php
/**
 * RARL — Minimal SMTP client
 * No Composer/PHPMailer dependency. Sends an HTML email, optionally with file
 * attachments (multipart/mixed), over an authenticated SMTP connection
 * (STARTTLS on 587, or implicit TLS on 465).
 */
class SmtpMailer {
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $secure; // 'tls' (STARTTLS) or 'ssl' (implicit TLS)
    private int $timeout;

    public function __construct(string $host, int $port, string $user, string $pass, string $secure = 'tls', int $timeout = 15) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->secure = $secure;
        $this->timeout = $timeout;
    }

    /**
     * @param array<int,array{path:string,filename?:string,mime?:string}> $attachments
     * @throws RuntimeException on any SMTP failure
     */
    public function send(string $fromEmail, string $fromName, string $to, string $toName, string $subject, string $htmlBody, array $attachments = []): void {
        $transport = $this->secure === 'ssl' ? 'ssl://' . $this->host : $this->host;
        $sock = @stream_socket_client("{$transport}:{$this->port}", $errno, $errstr, $this->timeout);
        if (!$sock) throw new RuntimeException("Connection failed: {$errstr} ({$errno})");
        stream_set_timeout($sock, $this->timeout);

        $this->expect($sock, '220');
        $this->command($sock, "EHLO {$this->host}", '250');

        if ($this->secure === 'tls') {
            $this->command($sock, 'STARTTLS', '220');
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $this->command($sock, "EHLO {$this->host}", '250');
        }

        $this->command($sock, 'AUTH LOGIN', '334');
        $this->command($sock, base64_encode($this->user), '334');
        $this->command($sock, base64_encode($this->pass), '235');

        $this->command($sock, "MAIL FROM:<{$fromEmail}>", '250');
        $this->command($sock, "RCPT TO:<{$to}>", ['250', '251']);
        $this->command($sock, 'DATA', '354');

        [$headers, $body] = self::buildMime($fromEmail, $fromName, $to, $toName, $subject, $htmlBody, $attachments);
        $body = str_replace("\n.", "\n..", $body); // dot-stuffing
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        $this->command($sock, $message, '250');

        $this->command($sock, 'QUIT', '221');
        fclose($sock);
    }

    /**
     * Builds MIME headers + body shared by both the SMTP path and the mail()
     * fallback in functions.php::sendEmail(). Plain text/html when there are
     * no attachments; multipart/mixed with base64-encoded parts otherwise.
     * @param array<int,array{path:string,filename?:string,mime?:string}> $attachments
     * @return array{0:string[],1:string}
     */
    public static function buildMime(string $fromEmail, string $fromName, string $to, string $toName, string $subject, string $htmlBody, array $attachments = []): array {
        $baseHeaders = [
            'MIME-Version: 1.0',
            'From: ' . self::encodeHeader($fromName) . " <{$fromEmail}>",
            'To: ' . self::encodeHeader($toName) . " <{$to}>",
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . date('r'),
            'X-Mailer: RARL-SmtpMailer',
        ];

        if (empty($attachments)) {
            $baseHeaders[] = 'Content-Type: text/html; charset=UTF-8';
            return [$baseHeaders, $htmlBody];
        }

        $boundary = 'rarl-' . bin2hex(random_bytes(16));
        $baseHeaders[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

        $parts = "--{$boundary}\r\n";
        $parts .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $parts .= $htmlBody . "\r\n";

        foreach ($attachments as $att) {
            if (!is_file($att['path'])) continue;
            $filename = $att['filename'] ?? basename($att['path']);
            $mime = $att['mime'] ?? (function_exists('mime_content_type') ? (mime_content_type($att['path']) ?: 'application/octet-stream') : 'application/octet-stream');
            $data = chunk_split(base64_encode(file_get_contents($att['path'])));

            $parts .= "--{$boundary}\r\n";
            $parts .= "Content-Type: {$mime}; name=\"" . self::encodeHeader($filename) . "\"\r\n";
            $parts .= "Content-Transfer-Encoding: base64\r\n";
            $parts .= "Content-Disposition: attachment; filename=\"" . self::encodeHeader($filename) . "\"\r\n\r\n";
            $parts .= $data . "\r\n";
        }
        $parts .= "--{$boundary}--";

        return [$baseHeaders, $parts];
    }

    private static function encodeHeader(string $value): string {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function readLine($sock): string {
        $line = fgets($sock, 512);
        if ($line === false) throw new RuntimeException('Connection closed unexpectedly');
        return $line;
    }

    private function expect($sock, string|array $codes): string {
        $codes = (array) $codes;
        $response = '';
        do {
            $line = $this->readLine($sock);
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException("SMTP error, expected " . implode('/', $codes) . ", got: {$response}");
        }
        return $response;
    }

    private function command($sock, string $cmd, string|array $expectCodes): string {
        fwrite($sock, $cmd . "\r\n");
        return $this->expect($sock, $expectCodes);
    }
}
