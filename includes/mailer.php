<?php

require_once __DIR__ . '/../config/mail.php';

$mailerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($mailerAutoload)) {
    require_once $mailerAutoload;
}

function send_email(PDO $pdo, string $to, string $subject, string $body): bool
{
    $to = trim($to);
    if (!validate_email_address($to)) {
        log_activity(
            $pdo,
            null,
            'email_failed',
            'Invalid recipient email: ' . $to . ' — Subject: ' . $subject
        );
        return false;
    }

    $subject = preg_replace('/[\r\n\x00-\x1F\x7F]/', '', $subject ?? '');
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    if (preg_match('/(?:\r|\n|\x00)/', $body) === 1 && !str_contains($body, "\n")) {
        $body = str_replace(["\r", "\n", "\x00"], '', $body);
    }

    $sent = false;
    $transport = 'mail';

    if (SMTP_HOST !== '' && class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        $transport = 'smtp';
        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = SMTP_HOST;
            $mailer->Port = SMTP_PORT;
            $mailer->SMTPAuth = SMTP_USERNAME !== '';
            $mailer->Username = SMTP_USERNAME;
            $mailer->Password = SMTP_PASSWORD;
            $mailer->CharSet = 'UTF-8';
            if (SMTP_ENCRYPTION === 'ssl') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif (SMTP_ENCRYPTION === 'tls') {
                $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mailer->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->isHTML(false);
            $sent = $mailer->send();
        } catch (Throwable $exception) {
            $sent = false;
        }
    } else {
        $headers = [
            'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    log_activity(
        $pdo,
        null,
        $sent ? 'email_sent' : 'email_failed',
        'Transport: ' . $transport . ' — To: ' . $to . ' — Subject: ' . $subject
    );

    return $sent;
}
