<?php

namespace App\Services\Smtp;

final class SocketSmtpConnectionFactory implements SmtpConnectionFactoryInterface
{
    public function make(string $host, string $ip, int $port, string $encryption, int $timeoutSeconds = 10): SmtpConnectionInterface
    {
        return new SocketSmtpConnection($host, $ip, $port, $encryption, $timeoutSeconds);
    }
}
