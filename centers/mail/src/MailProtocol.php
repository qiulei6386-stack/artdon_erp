<?php
declare(strict_types=1);

namespace Artdon\Centers\Mail;

use RuntimeException;

/**
 * 与邮箱服务器通信（IMAP 登录测试、SMTP 登录与发信）。直接用 socket，不依赖 imap 扩展（与旧系统做法一致）。
 * 测试时可以注入假的连接函数。
 */
final class MailProtocol
{
    /** @var callable(string, int, int): resource|false */
    private $open;

    public function __construct(?callable $open = null)
    {
        $this->open = $open ?? static function (string $target, int $port, int $timeout) {
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen($target, $port, $errno, $errstr, $timeout);
            if (!$fp) {
                throw new RuntimeException('连接失败：' . ($errstr ?: '无法连接服务器') . ($errno ? '（' . $errno . '）' : ''));
            }
            stream_set_timeout($fp, 25);
            return $fp;
        };
    }

    /** IMAP 登录测试。 */
    public function testImap(array $account): void
    {
        $fp = $this->connect((string) $account['imap_host'], (int) $account['imap_port'], (string) $account['imap_secure'], 'IMAP');
        try {
            $this->line($fp);
            $user = addcslashes((string) $account['email_username'], '\\"');
            $pass = addcslashes((string) $account['mail_secret'], '\\"');
            $this->imap($fp, 'A001', 'LOGIN "' . $user . '" "' . $pass . '"');
            try {
                $this->imap($fp, 'A002', 'LOGOUT');
            } catch (\Throwable $e) {
                // 已经登录成功，退出失败不影响结果
            }
        } finally {
            @fclose($fp);
        }
    }

    /** SMTP 登录，返回连接（发信用）。 @return resource */
    public function smtpLogin(array $account)
    {
        $secure = strtolower((string) $account['smtp_secure']);
        $fp = $this->connect((string) $account['smtp_host'], (int) $account['smtp_port'], $secure, 'SMTP');
        $this->smtpExpect($fp, ['220']);
        $helo = 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'artdon.local') ?: 'artdon.local');
        $this->smtp($fp, $helo, ['250']);
        if ($secure === 'tls') {
            $this->smtp($fp, 'STARTTLS', ['220']);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP 加密握手失败');
            }
            $this->smtp($fp, $helo, ['250']);
        }
        $this->smtp($fp, 'AUTH LOGIN', ['334']);
        $this->smtp($fp, base64_encode(self::loginEmail($account)), ['334']);
        $this->smtp($fp, base64_encode((string) $account['mail_secret']), ['235']);
        return $fp;
    }

    public function testSmtp(array $account): void
    {
        $fp = $this->smtpLogin($account);
        try {
            $this->smtp($fp, 'QUIT', ['221', '250']);
        } catch (\Throwable $e) {
            // 已经登录成功
        }
        @fclose($fp);
    }

    /**
     * 发一封信。返回服务器回执。DATA 之后出错时抛 DeliveryUncertain（对方可能已经收到，不能直接重发）。
     *
     * @param list<string> $recipients
     */
    public function send(array $account, array $recipients, string $message): string
    {
        $fp = $this->smtpLogin($account);
        $dataStarted = false;
        try {
            $this->smtp($fp, 'MAIL FROM:<' . self::loginEmail($account) . '>', ['250']);
            foreach ($recipients as $r) {
                $this->smtp($fp, 'RCPT TO:<' . $r . '>', ['250', '251']);
            }
            $this->smtp($fp, 'DATA', ['354']);
            $dataStarted = true;
            $wire = (string) preg_replace('/(^|\r\n)\./', '$1..', $message) . "\r\n.\r\n";
            for ($offset = 0, $length = strlen($wire); $offset < $length; $offset += $written) {
                $written = fwrite($fp, substr($wire, $offset, 65536));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('正文传输中断');
                }
            }
            $response = $this->smtpExpect($fp, ['250']);
            try {
                $this->smtp($fp, 'QUIT', ['221', '250']);
            } catch (\Throwable $e) {
                // 已经交付
            }
            @fclose($fp);
            return $response;
        } catch (\Throwable $e) {
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            if ($dataStarted) {
                throw new DeliveryUncertain('邮件已交给邮箱服务器，但没收到确认回执。请先到发件箱或对方那里核对，不要直接重发。', 0, $e);
            }
            throw $e;
        }
    }

    public static function loginEmail(array $account): string
    {
        $login = trim((string) ($account['email_username'] ?? ''));
        return $login !== '' && filter_var($login, FILTER_VALIDATE_EMAIL) ? $login : trim((string) $account['email_address']);
    }

    /** @return resource */
    private function connect(string $host, int $port, string $secure, string $label)
    {
        if ($host === '' || $port <= 0) {
            throw new RuntimeException($label . ' 服务器地址或端口没有填');
        }
        try {
            return ($this->open)(strtolower($secure) === 'ssl' ? 'ssl://' . $host : $host, $port, 15);
        } catch (RuntimeException $e) {
            throw new RuntimeException($label . ' ' . $e->getMessage());
        }
    }

    /** @param resource $fp */
    private function line($fp): string
    {
        $line = fgets($fp, 8192);
        if ($line === false) {
            throw new RuntimeException('邮箱服务器连接中断');
        }
        return rtrim($line, "\r\n");
    }

    /** @param resource $fp */
    private function imap($fp, string $tag, string $command): void
    {
        fwrite($fp, $tag . ' ' . $command . "\r\n");
        while (!feof($fp)) {
            $line = fgets($fp, 8192);
            if ($line === false) {
                break;
            }
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/', $line, $m)) {
                if ($m[1] !== 'OK') {
                    throw new RuntimeException('IMAP 登录失败：' . trim(substr($line, strlen($tag) + 1)));
                }
                return;
            }
        }
        throw new RuntimeException('IMAP 服务器没有响应');
    }

    /** @param resource $fp @param list<string> $codes */
    private function smtpExpect($fp, array $codes): string
    {
        $response = '';
        do {
            $line = $this->line($fp);
            $response .= $line . "\n";
            $code = substr($line, 0, 3);
            $more = isset($line[3]) && $line[3] === '-';
        } while ($more);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP 返回异常：' . trim($response));
        }
        return trim($response);
    }

    /** @param resource $fp @param list<string> $codes */
    private function smtp($fp, string $command, array $codes): string
    {
        fwrite($fp, $command . "\r\n");
        return $this->smtpExpect($fp, $codes);
    }
}
