<?php
declare(strict_types=1);

namespace App\Service\Bind;


use App\Consts\Hook;
use App\Model\Config as CFG;
use Kernel\Exception\JSONException;
use Kernel\Util\Session;
use PHPMailer\PHPMailer\PHPMailer;

class Email implements \App\Service\Email
{
    private const BCC_BATCH_SIZE = 50;

    /**
     * @param string $email
     * @param string $title
     * @param string $content
     * @return bool
     */
    public function send(string $email, string $title, string $content): bool
    {
        $result = $this->sendRecipients([$email], $title, $content, false);
        return $result['sent'] === 1;
    }

    /**
     * Send the same message to multiple recipients in privacy-safe BCC batches.
     *
     * @param array $emails
     * @param string $title
     * @param string $content
     * @return array{sent: int, failed: int}
     */
    public function sendMany(array $emails, string $title, string $content): array
    {
        return $this->sendRecipients($emails, $title, $content, true);
    }

    /**
     * @param array $emails
     * @param string $title
     * @param string $content
     * @param bool $useBcc
     * @return array{sent: int, failed: int}
     */
    private function sendRecipients(array $emails, string $title, string $content, bool $useBcc): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $config = $this->emailConfig();
        $groups = [];

        foreach ($emails as $recipient) {
            $recipientConfig = $config;
            $recipientEmail = is_string($recipient) ? $recipient : '';
            $recipientTitle = $title;
            $recipientContent = $content;

            try {
                $hook = $this->runHook(
                    Hook::SERVICE_SMTP_SEND_BEFORE,
                    $recipientConfig,
                    $recipientEmail,
                    $recipientTitle,
                    $recipientContent
                );
            } catch (\Throwable $e) {
                $this->recordResult($result, $this->runErrorHook(
                    $recipientConfig,
                    $recipientEmail,
                    $recipientTitle,
                    $recipientContent
                ));
                continue;
            }

            if ($hook !== null) {
                $this->recordResult($result, $hook);
                continue;
            }

            $address = trim($recipientEmail);
            if ($address === '' || !PHPMailer::validateAddress($address)) {
                $this->recordResult($result, $this->runErrorHook(
                    $recipientConfig,
                    $recipientEmail,
                    $recipientTitle,
                    $recipientContent
                ));
                continue;
            }

            $signature = $this->mailerSignature($recipientConfig, $recipientTitle, $recipientContent);
            if (!isset($groups[$signature])) {
                $groups[$signature] = [
                    'config' => $recipientConfig,
                    'title' => $recipientTitle,
                    'content' => $recipientContent,
                    'recipients' => [],
                ];
            }

            $groups[$signature]['recipients'][] = [
                'config' => $recipientConfig,
                'email' => $recipientEmail,
                'address' => $address,
                'title' => $recipientTitle,
                'content' => $recipientContent,
            ];
        }

        foreach ($groups as $group) {
            $mail = null;

            try {
                foreach ($this->recipientBatches($group['recipients']) as $batch) {
                    if (!$mail instanceof PHPMailer) {
                        try {
                            $mail = $this->createMailer($group['config'], $group['title'], $group['content']);
                        } catch (\Throwable $e) {
                            $this->recordBatchResult($result, $batch, false);
                            continue;
                        }
                    }

                    $sent = false;
                    $batchReady = true;
                    $deliveryResults = [];

                    try {
                        $mail->clearAllRecipients();
                        foreach ($batch as $recipient) {
                            $recipientAdded = $useBcc
                                ? $mail->addBCC($recipient['address'])
                                : $mail->addAddress($recipient['address']);
                            if (!$recipientAdded) {
                                $batchReady = false;
                                break;
                            }
                        }

                        if ($batchReady) {
                            $mail->action_function = static function (
                                $isSent,
                                $to,
                                $cc,
                                $bcc
                            ) use (&$deliveryResults): void {
                                foreach (array_merge($to, $cc, $bcc) as $recipient) {
                                    if (!is_array($recipient) || !isset($recipient[0])) {
                                        continue;
                                    }

                                    $address = strtolower(trim((string)$recipient[0]));
                                    if ($address !== '') {
                                        $deliveryResults[$address] = (bool)$isSent;
                                    }
                                }
                            };
                            $sent = $mail->Send();
                        }
                    } catch (\Throwable $e) {
                        $sent = false;
                    }

                    $this->recordBatchResult($result, $batch, $sent, $deliveryResults);

                    if (!$sent || in_array(false, $deliveryResults, true)) {
                        $this->closeMailer($mail);
                    }
                }
            } finally {
                $this->closeMailer($mail);
            }
        }

        return $result;
    }

    /**
     * @param array<int, array{config: array, email: string, address: string, title: string, content: string}> $recipients
     * @return array<int, array<int, array{config: array, email: string, address: string, title: string, content: string}>>
     */
    private function recipientBatches(array $recipients): array
    {
        $batches = [];
        $batch = [];
        $addresses = [];

        foreach ($recipients as $recipient) {
            $addressKey = strtolower($recipient['address']);
            if (count($batch) >= self::BCC_BATCH_SIZE || isset($addresses[$addressKey])) {
                $batches[] = $batch;
                $batch = [];
                $addresses = [];
            }

            $batch[] = $recipient;
            $addresses[$addressKey] = true;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @param array{sent: int, failed: int} $result
     * @param array<int, array{config: array, email: string, address: string, title: string, content: string}> $batch
     * @param array<string, bool> $deliveryResults
     */
    private function recordBatchResult(
        array &$result,
        array $batch,
        bool $sent,
        array $deliveryResults = []
    ): void
    {
        foreach ($batch as $recipient) {
            $recipientConfig = $recipient['config'];
            $recipientEmail = $recipient['email'];
            $recipientTitle = $recipient['title'];
            $recipientContent = $recipient['content'];
            $addressKey = strtolower($recipient['address']);
            $recipientSent = array_key_exists($addressKey, $deliveryResults)
                ? $deliveryResults[$addressKey]
                : ($sent && $deliveryResults === []);

            if (!$recipientSent) {
                $this->recordResult($result, $this->runErrorHook(
                    $recipientConfig,
                    $recipientEmail,
                    $recipientTitle,
                    $recipientContent
                ));
                continue;
            }

            try {
                $hook = $this->runHook(
                    Hook::SERVICE_SMTP_SEND_SUCCESS,
                    $recipientConfig,
                    $recipientEmail,
                    $recipientTitle,
                    $recipientContent
                );
                $this->recordResult($result, $hook ?? true);
            } catch (\Throwable $e) {
                $this->recordResult($result, $this->runErrorHook(
                    $recipientConfig,
                    $recipientEmail,
                    $recipientTitle,
                    $recipientContent
                ));
            }
        }
    }

    /**
     * @return array
     */
    private function emailConfig(): array
    {
        try {
            $config = json_decode((string)CFG::get("email_config"), true);
            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array $config
     * @param string $title
     * @param string $content
     * @return PHPMailer
     */
    private function createMailer(array $config, string $title, string $content): PHPMailer
    {
        foreach (['smtp', 'port', 'username', 'password'] as $key) {
            if (!array_key_exists($key, $config) || !is_scalar($config[$key]) || (string)$config[$key] === '') {
                throw new \RuntimeException('Email configuration is incomplete.');
            }
        }

        if (trim((string)$config['smtp']) === '' || trim((string)$config['username']) === '') {
            throw new \RuntimeException('Email configuration is incomplete.');
        }

        $port = filter_var($config['port'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535]
        ]);
        if ($port === false) {
            throw new \RuntimeException('Email configuration is invalid.');
        }

        $mail = new PHPMailer();
        $mail->CharSet = 'UTF-8';
        $mail->IsSMTP();
        $mail->SMTPDebug = 0;
        $mail->SMTPAuth = true;
        $mail->SMTPKeepAlive = true;
        $secure = isset($config['secure']) && is_scalar($config['secure']) ? (int)$config['secure'] : 0;
        $mail->SMTPSecure = $secure === 0 ? 'ssl' : 'tls';
        $mail->Host = trim((string)$config['smtp']);
        $mail->Port = $port;
        $mail->Username = trim((string)$config['username']);
        $mail->Password = (string)$config['password'];
        $mail->Timeout = 10;
        $mail->Subject = $title;
        $mail->MsgHTML($content);

        if (!$mail->SetFrom($mail->Username, (string)CFG::get("shop_name"))) {
            throw new \RuntimeException('Email sender configuration is invalid.');
        }

        return $mail;
    }

    private function mailerSignature(array $config, string $title, string $content): string
    {
        $signatureConfig = [];
        foreach (['smtp', 'port', 'username', 'password', 'secure'] as $key) {
            $value = $config[$key] ?? null;
            $signatureConfig[$key] = is_scalar($value)
                ? gettype($value) . ':' . (string)$value
                : get_debug_type($value);
        }

        return hash('sha256', serialize([$signatureConfig, $title, $content]));
    }

    private function closeMailer(?PHPMailer &$mail): void
    {
        if (!$mail instanceof PHPMailer) {
            return;
        }

        try {
            $mail->clearAllRecipients();
        } catch (\Throwable $e) {
            // Recipient cleanup must not prevent the SMTP connection from closing.
        }

        try {
            $mail->smtpClose();
        } catch (\Throwable $e) {
            // Closing a failed SMTP connection must not change delivery results.
        } finally {
            $mail = null;
        }
    }

    /**
     * @return bool|null
     */
    private function runHook(
        int $type,
        array &$config,
        string &$email,
        string &$title,
        string &$content
    ): ?bool
    {
        $hook = hook($type, $config, $email, $title, $content);
        return is_bool($hook) ? $hook : null;
    }

    private function runErrorHook(
        array &$config,
        string &$email,
        string &$title,
        string &$content
    ): bool
    {
        try {
            return $this->runHook(Hook::SERVICE_SMTP_SEND_ERROR, $config, $email, $title, $content) ?? false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array{sent: int, failed: int} $result
     */
    private function recordResult(array &$result, bool $success): void
    {
        $result[$success ? 'sent' : 'failed']++;
    }

    /**
     * @param string $email
     * @param int $type
     * @return void
     * @throws JSONException
     */
    public function sendCaptcha(string $email, int $type): void
    {
        $capthca = mt_rand(100000, 999999);
        $key = match ($type) {
            Email::CAPTCHA_REGISTER => sprintf(\App\Consts\Email::CAPTCHA_REGISTER, $email),
            Email::CAPTCHA_FORGET => sprintf(\App\Consts\Email::CAPTCHA_FORGET, $email),
            Email::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Email::CAPTCHA_BIND_NEW, $email),
            Email::CAPTCHA_BIND_OLD => sprintf(\App\Consts\Email::CAPTCHA_BIND_OLD, $email),
        };

        if (Session::has($key)) {
            if (Session::get($key)['time'] + 60 > time()) {
                throw new JSONException("验证码发送频繁，请稍后再试");
            }
        }

        if ($type == Email::CAPTCHA_REGISTER) {
            if (!$this->send($email, "【注册账号】验证您的电子邮件", "您好，您正在进行账号注册，本次验证码为：{$capthca}，有效期为5分钟。")) {
                throw new JSONException("验证码发送失败，请稍后再试");
            }
        } else if ($type == Email::CAPTCHA_FORGET) {
            if (!$this->send($email, "【找回密码】验证您的电子邮件", "您好，您正在找回密码，本次验证码为：{$capthca}，有效期为5分钟。")) {
                throw new JSONException("验证码发送失败，请稍后再试");
            }
        } else if ($type == Email::CAPTCHA_BIND_NEW) {
            if (!$this->send($email, "【绑定新邮箱】验证您的电子邮件", "您好，您正在绑定新邮箱，本次验证码为：{$capthca}，有效期为5分钟。")) {
                throw new JSONException("验证码发送失败，请稍后再试");
            }
        } else if ($type == Email::CAPTCHA_BIND_OLD) {
            if (!$this->send($email, "【修改邮箱】验证您的电子邮件", "您好，您的邮箱正在被修改，本次验证码为：{$capthca}，有效期为5分钟。")) {
                throw new JSONException("验证码发送失败，请稍后再试");
            }
        }

        Session::set($key, ["time" => time(), "code" => $capthca]);
    }


    /**
     * @param string $email
     * @param int $type
     * @param int $code
     * @return bool
     */
    public function checkCaptcha(string $email, int $type, int $code): bool
    {
        $key = match ($type) {
            Email::CAPTCHA_REGISTER => sprintf(\App\Consts\Email::CAPTCHA_REGISTER, $email),
            Email::CAPTCHA_FORGET => sprintf(\App\Consts\Email::CAPTCHA_FORGET, $email),
            Email::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Email::CAPTCHA_BIND_NEW, $email),
            Email::CAPTCHA_BIND_OLD => sprintf(\App\Consts\Email::CAPTCHA_BIND_OLD, $email),
        };

        if (!Session::has($key)) {
            return false;
        }

        if (Session::get($key)['code'] != $code) {
            return false;
        }

        if (Session::get($key)['time'] + 300 < time()) {
            return false;
        }

        return true;
    }

    /**
     * @param string $email
     * @param int $type
     */
    public function destroyCaptcha(string $email, int $type): void
    {
        $key = match ($type) {
            Email::CAPTCHA_REGISTER => sprintf(\App\Consts\Email::CAPTCHA_REGISTER, $email),
            Email::CAPTCHA_FORGET => sprintf(\App\Consts\Email::CAPTCHA_FORGET, $email),
            Email::CAPTCHA_BIND_NEW => sprintf(\App\Consts\Email::CAPTCHA_BIND_NEW, $email),
            Email::CAPTCHA_BIND_OLD => sprintf(\App\Consts\Email::CAPTCHA_BIND_OLD, $email),
        };
        Session::remove($key);
    }
}
