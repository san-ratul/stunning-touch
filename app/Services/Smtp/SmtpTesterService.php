<?php

namespace App\Services\Smtp;

use App\Support\SmtpEndpointGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

/**
 * SMTP Connection & Email Tester engine.
 *
 * Two strictly separated operations:
 *  - testConnection(): connectivity/HELO/capability verification. It NEVER
 *    issues MAIL FROM, RCPT TO or DATA, so no email can be sent in this mode.
 *  - sendTestEmail(): sends one real email through Symfony Mailer with a
 *    dedicated, request-scoped transport. The application's global mail
 *    configuration is never touched.
 *
 * Security invariants:
 * - Destinations pass SmtpEndpointGuard (public IPs only); connections pin
 *   the validated IP while TLS keeps verifying the user-supplied hostname.
 * - Credentials are used for the current request only and are never stored,
 *   logged, or echoed back. Raw diagnostics are redacted before logging.
 * - Client-facing messages are sanitized summaries, never raw exceptions.
 */
class SmtpTesterService
{
    private const CONNECT_TIMEOUT = 10;

    private const IO_TIMEOUT = 10;

    public function __construct(
        private readonly SmtpConnectionFactoryInterface $connections,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * MODE A — connection test. Never sends an email.
     *
     * @param  array{host: string, port: int, encryption: string, verify_auth: bool, username: ?string, password: ?string}  $config
     * @return array<string, mixed>
     */
    public function testConnection(array $config): array
    {
        $host = $config['host'];
        $port = (int) $config['port'];
        $encryption = $config['encryption'];

        $result = $this->baseResult($host, $port, $encryption);
        $steps = [];

        // 1. Destination policy (SSRF guard + DNS resolution).
        $guard = SmtpEndpointGuard::resolve($host);

        if ($guard['error'] !== null) {
            $steps[] = ['label' => 'Destination check', 'status' => 'error', 'detail' => $guard['error']];
            $result['status'] = 'error';
            $result['message'] = 'The destination is not allowed.';
            $result['steps'] = $steps;

            return $result;
        }

        $ip = $guard['ips'][0];
        $steps[] = ['label' => 'Destination check', 'status' => 'ok', 'detail' => "Resolved {$host} to a public address."];

        $connection = $this->connections->make($host, $ip, $port, $encryption, self::CONNECT_TIMEOUT);

        $greetingText = null;
        $capabilities = [];

        try {
            // 2. Connect.
            $connection->connect();
            $result['latency_ms'] = $connection->latencyMs();
            $steps[] = ['label' => 'TCP connection', 'status' => 'ok', 'detail' => "Connected to {$ip}:{$port} in {$result['latency_ms']} ms."];

            // 3. Banner.
            $banner = $connection->readResponse();
            $greetingText = $banner->text();

            if ($banner->code !== 220) {
                $result['status'] = 'error';
                $result['message'] = 'The server did not greet with a ready (220) response.';
                $result['smtp_code'] = $banner->code;
                $result['steps'] = $this->withStep($steps, 'Server greeting', 'error', 'Received SMTP code '.$banner->code.'.');
                $result['greeting'] = $greetingText;

                return $result;
            }

            $steps[] = ['label' => 'Server greeting', 'status' => 'ok', 'detail' => 'Ready (220).'];
            $result['greeting'] = $greetingText;

            // 4. EHLO.
            $ehloName = $this->ehloName();
            $connection->send('EHLO '.$ehloName);
            $ehlo = $connection->readResponse();
            $capabilities = $ehlo->capabilities();

            if ($ehlo->code !== 250) {
                // Fall back to HELO for old servers.
                $connection->send('HELO '.$ehloName);
                $helo = $connection->readResponse();

                if ($helo->code !== 250) {
                    $result['status'] = 'error';
                    $result['message'] = 'The server rejected the EHLO/HELO handshake.';
                    $result['smtp_code'] = $helo->code;
                    $result['steps'] = $this->withStep($steps, 'EHLO handshake', 'error', 'Rejected (code '.$helo->code.').');

                    return $result;
                }

                $steps[] = ['label' => 'EHLO handshake', 'status' => 'ok', 'detail' => 'Server only supports HELO (no capability list).'];
            } else {
                $steps[] = ['label' => 'EHLO handshake', 'status' => 'ok', 'detail' => count($capabilities).' capabilities announced.'];
            }

            // 5. STARTTLS when selected (or only option available).
            $tlsStatus = 'not-required';
            $requiresTls = $encryption === 'starttls';

            if ($requiresTls) {
                if (! $this->advertises('STARTTLS', $ehlo->lines)) {
                    $result['status'] = 'error';
                    $result['message'] = 'STARTTLS was selected but the server does not advertise it.';
                    $result['steps'] = $this->withStep($steps, 'STARTTLS', 'error', 'STARTTLS not in server capabilities.');

                    return $result;
                }

                $connection->send('STARTTLS');
                $tlsResponse = $connection->readResponse();

                if ($tlsResponse->code !== 220) {
                    $result['status'] = 'error';
                    $result['message'] = 'The server refused to start TLS negotiation.';
                    $result['smtp_code'] = $tlsResponse->code;
                    $result['steps'] = $this->withStep($steps, 'STARTTLS', 'error', 'Refused (code '.$tlsResponse->code.').');

                    return $result;
                }

                try {
                    $connection->startTls();
                } catch (SmtpConnectionException $exception) {
                    $this->logger->warning('SMTP tester TLS handshake failed', ['detail' => $exception->detail ?? $exception->getMessage()]);

                    $result['status'] = 'error';
                    $result['message'] = 'The TLS handshake failed.';
                    $result['steps'] = $this->withStep($steps, 'STARTTLS', 'error', 'TLS handshake failed.');

                    return $result;
                }

                $tlsStatus = 'negotiated';
                $steps[] = ['label' => 'STARTTLS', 'status' => 'ok', 'detail' => 'TLS negotiated; hostname verified.'];

                // Re-EHLO inside the encrypted channel.
                $connection->send('EHLO '.$ehloName);
                $ehlo = $connection->readResponse();
                $capabilities = $ehlo->capabilities();
            } elseif ($encryption === 'ssl') {
                $tlsStatus = 'implicit';
            }

            $result['tls'] = $tlsStatus;
            $result['capabilities'] = $capabilities;

            // 6. Optional authentication verification (login only; no MAIL FROM).
            if ($config['verify_auth'] ?? false) {
                $auth = $this->verifyAuthentication($connection, $config, $ehlo->lines);
                $result['authenticated'] = $auth['ok'];
                $steps[] = $auth['step'];

                if (! $auth['ok']) {
                    $result['status'] = 'warning';
                    $result['message'] = 'Connection succeeded, but authentication failed.';
                }
            }

            $result['steps'] = $steps;

            if ($result['status'] !== 'warning') {
                $result['status'] = 'ok';
                $result['message'] = 'SMTP connection succeeded.';
            }

            return $result;
        } catch (SmtpConnectionException $exception) {
            $this->logConnectionFailure($exception, $host, $port);

            $result['status'] = 'error';
            $result['message'] = $this->friendlyConnectionError($exception);
            $result['steps'] = $this->withStep($steps, $this->stepLabelFor($exception->kind), 'error', $this->friendlyConnectionError($exception));

            return $result;
        } finally {
            $connection->close();
        }
    }

    /**
     * MODE B — send one real test email.
     *
     * @param  array{host: string, port: int, encryption: string, username: ?string, password: ?string, from_email: string, to_email: string, subject: ?string, message: ?string}  $config
     * @return array<string, mixed>
     */
    public function sendTestEmail(array $config): array
    {
        $host = $config['host'];
        $port = (int) $config['port'];
        $encryption = $config['encryption'];

        $result = $this->baseResult($host, $port, $encryption);
        $steps = [];

        // Re-validate the destination immediately before sending.
        $guard = SmtpEndpointGuard::resolve($host);

        if ($guard['error'] !== null) {
            $steps[] = ['label' => 'Destination check', 'status' => 'error', 'detail' => $guard['error']];
            $result['status'] = 'error';
            $result['message'] = 'The destination is not allowed.';
            $result['steps'] = $steps;

            return $result;
        }

        $ip = $guard['ips'][0];

        $steps[] = ['label' => 'Destination check', 'status' => 'ok', 'detail' => "Resolved {$host} to a public address."];

        $subject = trim((string) ($config['subject'] ?? '')) ?: 'SMTP test from Stunning Touch';
        $bodyText = trim((string) ($config['message'] ?? ''));

        $bodyText = $bodyText !== '' && $bodyText !== '0'
            ? $bodyText
            : "Hello,\n\nThis is a test message sent from the Stunning Touch SMTP Connection & Email Tester. If you are reading this, your SMTP configuration accepted the message.\n\nNote: SMTP acceptance confirms the server queued the message — it does not guarantee final inbox delivery.\n";

        $dsn = $this->buildDsn($config, $encryption);
        $email = (new Email)
            ->from($config['from_email'])
            ->to($config['to_email'])
            ->subject($subject)
            ->text($bodyText);

        try {
            $transport = $this->createTransport($dsn, $host, $ip, $config, $encryption);
            $mailer = new Mailer($transport);
            $mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            return $this->sendFailure($result, $steps, $this->sanitizeTransportError($exception, $config));
        } catch (\Throwable $exception) {
            return $this->sendFailure($result, $steps, $this->sanitizeTransportError($exception, $config));
        }

        $steps[] = ['label' => 'Send test email', 'status' => 'ok', 'detail' => 'The server accepted the message for delivery.'];
        $result['steps'] = $steps;
        $result['status'] = 'ok';
        $result['message'] = 'Test email accepted by the SMTP server. It should arrive in a few minutes — acceptance does not guarantee final inbox delivery.';
        $result['subject'] = $subject;

        return $result;
    }

    /**
     * @return array{ok: bool, step: array{label: string, status: string, detail: string}}
     */
    protected function verifyAuthentication(SmtpConnectionInterface $connection, array $config, array $ehloLines): array
    {
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if ($username === '' || $password === '') {
            return ['ok' => false, 'step' => ['label' => 'Authentication check', 'status' => 'warn', 'detail' => 'Username or password missing.']];
        }

        if (! $this->advertisesAuth($ehloLines)) {
            return ['ok' => false, 'step' => ['label' => 'Authentication check', 'status' => 'warn', 'detail' => 'The server does not advertise AUTH support.']];
        }

        try {
            $connection->send('AUTH LOGIN');
            $prompt = $connection->readResponse();

            if ($prompt->code !== 334) {
                return ['ok' => false, 'step' => ['label' => 'Authentication check', 'status' => 'error', 'detail' => 'Server refused to start authentication (code '.$prompt->code.').']];
            }

            $connection->send(base64_encode($username));
            $userPrompt = $connection->readResponse();

            if ($userPrompt->code !== 334) {
                return ['ok' => false, 'step' => ['label' => 'Authentication check', 'status' => 'error', 'detail' => 'Server rejected the username (code '.$userPrompt->code.').']];
            }

            $connection->send(base64_encode($password));
            $final = $connection->readResponse();

            if ($final->code !== 235) {
                return ['ok' => false, 'step' => ['label' => 'Authentication check', 'status' => 'error', 'detail' => 'Credentials rejected (code '.$final->code.').']];
            }

            return ['ok' => true, 'step' => ['label' => 'Authentication check', 'status' => 'ok', 'detail' => 'Credentials accepted (235).']];
        } catch (SmtpConnectionException $exception) {
            $this->logConnectionFailure($exception, $config['host'], (int) $config['port']);

            return ['ok' => false, 'step' => ['label' => 'Authentication check', 'status' => 'error', 'detail' => 'The connection dropped during authentication.']];
        }
    }

    protected function createTransport(string $dsn, string $host, string $ip, array $config, string $encryption): EsmtpTransport
    {
        $factory = new EsmtpTransportFactory;
        $transport = $factory->create(Dsn::fromString($dsn));

        if (! $transport instanceof EsmtpTransport) {
            throw new \RuntimeException('Unexpected SMTP transport type.');
        }

        /** @var SocketStream $stream */
        $stream = $transport->getStream();
        $stream->setTimeout(self::IO_TIMEOUT);

        // Connect to the previously validated IP while TLS keeps verifying
        // the hostname the user typed (narrow DNS-rebinding window).
        $stream->setHost($ip);

        $options = $stream->getStreamOptions();
        $options['ssl']['peer_name'] = $host;
        $options['ssl']['SNI_enabled'] = true;
        $stream->setStreamOptions($options);

        return $transport;
    }

    /**
     * Build a Symfony SMTP DSN. createTransport overrides the socket host
     * with the validated IP, so the DSN's host part is only parsed (user,
     * password, port, options) — TLS verification uses peer_name.
     */
    public function buildDsn(array $config, string $encryption): string
    {
        $host = $config['host'];
        $port = (int) $config['port'];
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        $auth = '';

        if ($username !== '') {
            $auth = rawurlencode($username);

            if ($password !== '') {
                $auth .= ':'.rawurlencode($password);
            }

            $auth .= '@';
        }

        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $options = [];

        if ($encryption === 'starttls') {
            $options[] = 'require_tls=true';
        }

        if ($encryption === 'none') {
            $options[] = 'auto_tls=false';
        }

        $query = $options !== [] ? '?'.implode('&', $options) : '';

        return sprintf('%s://%s%s:%d%s', $scheme, $auth, $host, $port, $query);
    }

    protected function sanitizeTransportError(\Throwable $exception, array $config): array
    {
        $raw = $exception->getMessage();
        $password = (string) ($config['password'] ?? '');

        if ($password !== '') {
            $raw = str_replace($password, '[redacted]', $raw);
        }

        $this->logger->warning('SMTP tester send failure', ['detail' => $raw]);

        $lower = strtolower($raw);

        if (preg_match('/got code "(\d{3})"/', $raw, $m) === 1) {
            $code = $m[1];
            $detail = match (true) {
                $code === '535' || $code === '534' => 'Authentication was rejected by the server (code '.$code.'). Check the username and password.',
                $code === '554' || $code === '550' => 'The server refused the message (code '.$code.'). The account may not be allowed to send from this address.',
                $code === '553' => 'The sender address was rejected (code '.$code.'). Use the email address of the SMTP account.',
                default => 'The SMTP server rejected the message (code '.$code.').',
            };

            return ['message' => $detail, 'smtp_code' => (int) $code];
        }

        if (str_contains($lower, 'expected response code "235"') || str_contains($lower, 'authentication') || str_contains($lower, 'username and password not accepted')) {
            return ['message' => 'Authentication failed. Check the username and password.'];
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return ['message' => 'The connection timed out. The server may be unreachable or the port may be wrong.'];
        }

        if (str_contains($lower, 'connection refused')) {
            return ['message' => 'The connection was refused. Check the host and port.'];
        }

        if (str_contains($lower, 'starttls') || str_contains($lower, 'tls')) {
            return ['message' => 'The TLS negotiation failed. Try a different encryption setting or check the port (465 for SSL, 587 for STARTTLS).'];
        }

        if (str_contains($lower, 'could not authenticate') || str_contains($lower, 'auth')) {
            return ['message' => 'Authentication failed. Check the username and password.'];
        }

        return ['message' => 'The SMTP server rejected the send attempt. Verify the configuration and try again.'];
    }

    protected function sendFailure(array $result, array $steps, array $sanitized): array
    {
        $steps[] = ['label' => 'Send test email', 'status' => 'error', 'detail' => $sanitized['message']];
        $result['status'] = 'error';
        $result['message'] = $sanitized['message'];

        if (isset($sanitized['smtp_code'])) {
            $result['smtp_code'] = $sanitized['smtp_code'];
        }

        $result['steps'] = $steps;

        return $result;
    }

    protected function friendlyConnectionError(SmtpConnectionException $exception): string
    {
        return match ($exception->kind) {
            'timeout' => 'The connection timed out after '.self::CONNECT_TIMEOUT.' seconds. The server may be unreachable or blocking outbound SMTP.',
            'refused' => 'The connection was refused. Check the host and port — common ports are 25, 465, 587 and 2525.',
            'dns' => 'The SMTP host could not be resolved. Check the spelling or DNS records.',
            'response' => 'The connection dropped before a complete SMTP response arrived.',
            'write' => 'The connection dropped while sending a command.',
            'tls' => 'The TLS handshake failed. Port 465 expects implicit SSL/TLS; port 587 usually expects STARTTLS.',
            default => 'Could not connect to the SMTP server. Check the host, port and encryption settings.',
        };
    }

    protected function stepLabelFor(string $kind): string
    {
        return match ($kind) {
            'dns' => 'DNS resolution',
            'tls' => 'TLS negotiation',
            'timeout', 'refused', 'connection' => 'TCP connection',
            default => 'Connection',
        };
    }

    protected function logConnectionFailure(SmtpConnectionException $exception, string $host, int $port): void
    {
        $this->logger->warning('SMTP tester connection failure', [
            'kind' => $exception->kind,
            'host' => $host,
            'port' => $port,
            'detail' => $exception->detail ?? $exception->getMessage(),
        ]);
    }

    protected function advertises(string $keyword, array $ehloLines): bool
    {
        foreach ($ehloLines as $line) {
            if (strcasecmp(trim($line), $keyword) === 0 || str_starts_with(strtoupper(trim($line)), strtoupper($keyword).' ')) {
                return true;
            }
        }

        return false;
    }

    protected function advertisesAuth(array $ehloLines): bool
    {
        foreach ($ehloLines as $line) {
            if (str_starts_with(strtoupper(trim($line)), 'AUTH')) {
                return true;
            }
        }

        return false;
    }

    protected function ehloName(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'stunningtouch.com';
    }

    protected function baseResult(string $host, int $port, string $encryption): array
    {
        return [
            'status' => 'pending',
            'message' => '',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'greeting' => null,
            'capabilities' => [],
            'tls' => null,
            'authenticated' => null,
            'latency_ms' => null,
            'smtp_code' => null,
            'steps' => [],
        ];
    }

    /**
     * @param  list<array{label: string, status: string, detail: string}>  $steps
     * @return list<array{label: string, status: string, detail: string}>
     */
    protected function withStep(array $steps, string $label, string $status, string $detail): array
    {
        $steps[] = ['label' => $label, 'status' => $status, 'detail' => $detail];

        return $steps;
    }
}
