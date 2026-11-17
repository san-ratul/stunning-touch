<?php

namespace Tests\Feature;

use App\Services\Smtp\SmtpConnectionException;
use App\Services\Smtp\SmtpConnectionFactoryInterface;
use App\Services\Smtp\SmtpConnectionInterface;
use App\Services\Smtp\SmtpResponse;
use App\Services\Smtp\SmtpTesterService;
use App\Support\ToolRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Scripted-socket tests for the SMTP tester service and endpoints.
 * No real network traffic and no real emails: the connection factory and
 * transport are faked; automated tests must never send real email.
 */
class SmtpTesterTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Page rendering
    // ------------------------------------------------------------------

    public function test_smtp_tool_page_renders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $response = $this->get('/smtp-connection-tester');

        $response->assertOk();
        $response->assertSee('data-tool="smtp-connection-tester"', false);
    }

    public function test_smtp_endpoints_exist_for_both_modes(): void
    {
        $this->assertTrue(ToolRegistry::exists('smtp-connection-tester'));

        $this->postJson('/api/smtp/test-connection', [])->assertStatus(422);
        $this->postJson('/api/smtp/send-test-email', [])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function test_connection_requires_valid_fields(): void
    {
        $this->postJson('/api/smtp/test-connection', [
            'host' => '1.1.1.1',
            'port' => 70000, // out of range
            'encryption' => 'starttls',
        ])->assertStatus(422);

        $this->postJson('/api/smtp/test-connection', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'bogus',
        ])->assertStatus(422);
    }

    public function test_send_requires_valid_email_addresses(): void
    {
        $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'from_email' => 'not-an-email',
            'to_email' => 'recipient@example.com',
        ])->assertStatus(422);

        $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'from_email' => 'sender@example.com',
            'to_email' => 'also-not-an-email',
        ])->assertStatus(422);
    }

    public function test_send_rejects_multiple_recipients(): void
    {
        // One recipient per send operation — array input must fail.
        $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'from_email' => 'sender@example.com',
            'to_email' => ['a@example.com', 'b@example.com'],
        ])->assertStatus(422);
    }

    public function test_send_rejects_oversized_message(): void
    {
        $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'from_email' => 'sender@example.com',
            'to_email' => 'recipient@example.com',
            'message' => str_repeat('x', 5001),
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // SSRF protection (end-to-end through the endpoint)
    // ------------------------------------------------------------------

    #[DataProvider('blockedDestinationProvider')]
    public function test_endpoints_block_prohibited_destinations(string $host): void
    {
        $payload = [
            'host' => $host,
            'port' => 587,
            'encryption' => 'starttls',
        ];

        $this->postJson('/api/smtp/test-connection', $payload)
            ->assertOk()
            ->assertJsonPath('result.status', 'error')
            ->assertJsonPath('result.message', 'The destination is not allowed.');

        $this->postJson('/api/smtp/send-test-email', $payload + [
            'from_email' => 'sender@example.com',
            'to_email' => 'recipient@example.com',
        ])->assertOk()->assertJsonPath('result.status', 'error');
    }

    public static function blockedDestinationProvider(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private' => ['10.1.2.3'],
            'link-local metadata' => ['169.254.169.254'],
            'metadata hostname' => ['metadata.google.internal'],
            'carrier nat' => ['100.64.0.9'],
        ];
    }

    // ------------------------------------------------------------------
    // Connection flow (scripted socket)
    // ------------------------------------------------------------------

    public function test_connection_flow_reports_greeting_capabilities_tls_and_auth(): void
    {
        $connection = new ScriptedSmtpConnection;
        $connection->push(220, ['mail.example.com ESMTP ready']);
        $connection->push(250, ['mail.example.com', 'STARTTLS', 'AUTH LOGIN PLAIN', 'SIZE 35882577']);
        $connection->push(220, ['Ready to start TLS']);
        $connection->push(250, ['mail.example.com', 'AUTH LOGIN PLAIN']);
        $connection->push(334, ['VXNlcm5hbWU6']);
        $connection->push(334, ['UGFzc3dvcmQ6']);
        $connection->push(235, ['Authentication successful']);

        $this->bindFactory($connection);

        $response = $this->postJson('/api/smtp/test-connection', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'verify_auth' => true,
            'username' => 'user@example.com',
            'password' => 'secret-pass-123',
        ]);

        $response->assertOk();

        $result = $response->json('result');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('negotiated', $result['tls']);
        $this->assertTrue($result['authenticated']);
        $this->assertSame(42, $result['latency_ms']);
        $this->assertStringContainsString('ESMTP ready', $result['greeting']);
        // Post-STARTTLS capability list (re-EHLO inside the TLS channel).
        $this->assertContains('AUTH LOGIN PLAIN', $result['capabilities']);

        // Connection mode must never issue mail-transaction commands.
        foreach ($connection->commands as $command) {
            $this->assertStringNotContainsStringIgnoringCase('MAIL FROM', $command);
            $this->assertStringNotContainsStringIgnoringCase('RCPT TO', $command);
            $this->assertStringNotContainsStringIgnoringCase('DATA', $command);
        }

        $this->assertTrue($connection->closed);
        $this->assertTrue($connection->tlsEnabled);
    }

    public function test_connection_without_auth_verification_skips_auth(): void
    {
        $connection = new ScriptedSmtpConnection;
        $connection->push(220, ['mail.example.com ESMTP ready']);
        $connection->push(250, ['mail.example.com']);

        $this->bindFactory($connection);

        $response = $this->postJson('/api/smtp/test-connection', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'none',
        ]);

        $response->assertOk();
        $result = $response->json('result');
        $this->assertSame('ok', $result['status']);
        $this->assertNull($result['authenticated']);
        $this->assertSame('not-required', $result['tls']);

        $authCommands = array_filter($connection->commands, fn ($c) => str_starts_with($c, 'AUTH'));
        $this->assertCount(0, $authCommands);
    }

    public function test_invalid_greeting_is_reported(): void
    {
        $connection = new ScriptedSmtpConnection;
        $connection->push(554, ['Not welcome here']);

        $this->bindFactory($connection);

        $response = $this->postJson('/api/smtp/test-connection', [
            'host' => '1.1.1.1',
            'port' => 25,
            'encryption' => 'none',
        ]);

        $result = $response->json('result');
        $this->assertSame('error', $result['status']);
        $this->assertSame(554, $result['smtp_code']);
        $this->assertStringContainsString('did not greet', $result['message']);
    }

    public function test_starttls_not_advertised_is_reported(): void
    {
        $connection = new ScriptedSmtpConnection;
        $connection->push(220, ['mail.example.com ESMTP ready']);
        $connection->push(250, ['mail.example.com', 'AUTH PLAIN']);

        $this->bindFactory($connection);

        $response = $this->postJson('/api/smtp/test-connection', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
        ]);

        $result = $response->json('result');
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('does not advertise', $result['message']);
        $this->assertFalse($connection->tlsEnabled);
    }

    public function test_authentication_failure_is_reported_sanitized(): void
    {
        $connection = new ScriptedSmtpConnection;
        $connection->push(220, ['mail.example.com ESMTP ready']);
        $connection->push(250, ['mail.example.com', 'AUTH LOGIN']);
        $connection->push(334, ['VXNlcm5hbWU6']);
        $connection->push(334, ['UGFzc3dvcmQ6']);
        $connection->push(535, ['5.7.8 Bad credentials']);

        $this->bindFactory($connection);

        $response = $this->postJson('/api/smtp/test-connection', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'none',
            'verify_auth' => true,
            'username' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $result = $response->json('result');
        $this->assertSame('warning', $result['status']);
        $this->assertFalse($result['authenticated']);

        $this->assertStringNotContainsString('wrong-password', $response->getContent());
    }

    // ------------------------------------------------------------------
    // Email sending (faked transport)
    // ------------------------------------------------------------------

    public function test_send_test_email_accepts_and_sends_exactly_one_message(): void
    {
        $transport = new RecordingTransport;
        $this->swapService($transport);

        $response = $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => 'user@example.com',
            'password' => 'secret-pass-123',
            'from_email' => 'sender@example.com',
            'to_email' => 'recipient@example.com',
            'subject' => 'My test',
            'message' => 'Hello from the tester.',
        ]);

        $response->assertOk();
        $this->assertSame('ok', $response->json('result.status'));
        $this->assertSame('My test', $response->json('result.subject'));

        $this->assertCount(1, $transport->sent);
        [$from, $recipients, $subject] = $transport->sent[0];

        $this->assertSame('sender@example.com', $from);
        $this->assertSame(['recipient@example.com'], $recipients);
        $this->assertSame('My test', $subject);

        // Credentials must never be echoed back.
        $this->assertStringNotContainsString('secret-pass-123', $response->getContent());
    }

    public function test_send_uses_defaults_for_subject_and_message(): void
    {
        $transport = new RecordingTransport;
        $this->swapService($transport);

        $response = $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'from_email' => 'sender@example.com',
            'to_email' => 'recipient@example.com',
        ]);

        $response->assertOk();
        [$from, $recipients, $subject] = $transport->sent[0];

        $this->assertSame('SMTP test from Stunning Touch', $subject);
        $this->assertStringContainsString('does not guarantee', $transport->bodies[0]);
    }

    #[DataProvider('transportFailureProvider')]
    public function test_send_failures_are_sanitized_without_raw_leakage(string $rawError, string $expectedFragment): void
    {
        $transport = new ThrowingTransport($rawError);
        $this->swapService($transport);

        $response = $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => 'user@example.com',
            'password' => 'super-secret-pass',
            'from_email' => 'sender@example.com',
            'to_email' => 'recipient@example.com',
        ]);

        $response->assertOk();
        $result = $response->json('result');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString($expectedFragment, $result['message']);

        // No raw exception text, no password, no raw server response leak.
        $content = $response->getContent();
        $this->assertStringNotContainsString('super-secret-pass', $content);
        $this->assertStringNotContainsString('INTERNAL-SERVER-DETAIL-MARKER', $content);
    }

    public static function transportFailureProvider(): array
    {
        return [
            'smtp rejection' => ['Unable to send message: expected response code "250" but got code "554", message "INTERNAL-SERVER-DETAIL-MARKER".', '(code 554)'],
            'auth failure' => ['Expected response code "250" but got code "535": Authentication failed. INTERNAL-SERVER-DETAIL-MARKER', 'Authentication was rejected'],
            'timeout' => ['Connection could not be established: Connection timed out INTERNAL-SERVER-DETAIL-MARKER', 'timed out'],
            'connection refused' => ['Connection could not be established: Connection refused INTERNAL-SERVER-DETAIL-MARKER', 'refused'],
            'generic' => ['Some totally unexpected INTERNAL-SERVER-DETAIL-MARKER', 'rejected the send attempt'],
        ];
    }

    // ------------------------------------------------------------------
    // Rate limiting
    // ------------------------------------------------------------------

    public function test_connection_endpoint_is_rate_limited(): void
    {
        $payload = ['host' => 'not-a-valid-host!', 'port' => 587, 'encryption' => 'starttls'];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/smtp/test-connection', $payload)->assertOk();
        }

        $this->postJson('/api/smtp/test-connection', $payload)->assertStatus(429);
    }

    public function test_send_endpoint_is_rate_limited(): void
    {
        $transport = new ThrowingTransport('Connection refused INTERNAL-SERVER-DETAIL-MARKER');
        $this->swapService($transport);

        $payload = [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'from_email' => 'sender@example.com',
            'to_email' => 'recipient@example.com',
        ];

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/smtp/send-test-email', $payload)->assertOk();
        }

        $this->postJson('/api/smtp/send-test-email', $payload)->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // Usage tracking & credential non-persistence
    // ------------------------------------------------------------------

    public function test_successful_send_records_tool_usage(): void
    {
        $this->seed(DatabaseSeeder::class);

        $transport = new RecordingTransport;
        $this->swapService($transport);

        $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'from_email' => 'sender@example.com',
            'to_email' => 'recipient@example.com',
        ])->assertOk();

        $this->assertDatabaseCount('tool_usage', 1);
    }

    public function test_credentials_are_never_persisted_anywhere(): void
    {
        $transport = new RecordingTransport;
        $this->swapService($transport);

        $this->postJson('/api/smtp/send-test-email', [
            'host' => '1.1.1.1',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => 'user@example.com',
            'password' => 'never-persisted-pass',
            'from_email' => 'sender@example.com',
            'to_email' => 'recipient@example.com',
        ])->assertOk();

        // No database table may contain the credential.
        foreach (['users', 'tools', 'tool_usage', 'contact_messages'] as $table) {
            $rows = DB::table($table)->get();
            foreach ($rows as $row) {
                foreach ((array) $row as $value) {
                    if (is_string($value)) {
                        $this->assertStringNotContainsString('never-persisted-pass', $value);
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // DSN construction
    // ------------------------------------------------------------------

    public function test_dsn_builds_correct_schemes_and_credentials(): void
    {
        $service = $this->app->make(SmtpTesterService::class);

        $this->assertSame(
            'smtps://user%40example.com:pa%40ss%2Fword@mail.example.com:465',
            $service->buildDsn(['host' => 'mail.example.com', 'port' => 465, 'username' => 'user@example.com', 'password' => 'pa@ss/word'], 'ssl'),
        );

        $this->assertSame(
            'smtp://user:pass@mail.example.com:587?require_tls=true',
            $service->buildDsn(['host' => 'mail.example.com', 'port' => 587, 'username' => 'user', 'password' => 'pass'], 'starttls'),
        );

        $this->assertSame(
            'smtp://mail.example.com:2525?auto_tls=false',
            $service->buildDsn(['host' => 'mail.example.com', 'port' => 2525, 'username' => '', 'password' => ''], 'none'),
        );

        $this->assertSame(
            'smtp://mail.example.com:25?require_tls=true',
            $service->buildDsn(['host' => 'mail.example.com', 'port' => 25, 'username' => '', 'password' => ''], 'starttls'),
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function bindFactory(ScriptedSmtpConnection $connection): void
    {
        $this->app->instance(
            SmtpConnectionFactoryInterface::class,
            new class($connection) implements SmtpConnectionFactoryInterface
            {
                public function __construct(private readonly ScriptedSmtpConnection $connection) {}

                public function make(string $host, string $ip, int $port, string $encryption, int $timeoutSeconds = 10): SmtpConnectionInterface
                {
                    return $this->connection;
                }
            },
        );
    }

    protected function swapService(TransportInterface $transport): void
    {
        $fake = new class($transport, $this->app->make(SmtpConnectionFactoryInterface::class), logger()) extends SmtpTesterService
        {
            public function __construct(
                private readonly TransportInterface $fakeTransport,
                $factory,
                $logger,
            ) {
                parent::__construct($factory, $logger);
            }

            protected function createTransport(string $dsn, string $host, string $ip, array $config, string $encryption): EsmtpTransport
            {
                // Tests never create real transports. The declared return
                // type is satisfied at runtime because PHP does not enforce
                // covariance on values returned through overridden methods
                // here — we bypass typing via a typed subclass transport.
                $object = (new class($this->fakeTransport) extends EsmtpTransport
                {
                    public function __construct(private readonly TransportInterface $inner) {}

                    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
                    {
                        return $this->inner->send($message, $envelope);
                    }
                });

                return $object;
            }
        };

        $this->app->instance(SmtpTesterService::class, $fake);
    }
}

/**
 * Scripted SMTP connection: serves queued responses, records commands.
 */
class ScriptedSmtpConnection implements SmtpConnectionInterface
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<SmtpResponse> */
    protected array $responses = [];

    protected int $cursor = 0;

    public bool $tlsEnabled = false;

    public bool $closed = false;

    public function push(int $code, array $lines): void
    {
        $this->responses[] = new SmtpResponse($code, $lines);
    }

    public function connect(): void {}

    public function readResponse(): SmtpResponse
    {
        if (! isset($this->responses[$this->cursor])) {
            throw new SmtpConnectionException('Script exhausted — unexpected additional command.', 'response');
        }

        return $this->responses[$this->cursor++];
    }

    public function send(string $command): void
    {
        $this->commands[] = $command;
    }

    public function startTls(): void
    {
        $this->tlsEnabled = true;
    }

    public function latencyMs(): int
    {
        return 42;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

class RecordingTransport implements TransportInterface
{
    /** @var list<array{0: string, 1: list<string>, 2: string}> */
    public array $sent = [];

    /** @var list<string> */
    public array $bodies = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $envelope ??= Envelope::create($message);

        $this->sent[] = [
            $envelope->getSender()->getAddress(),
            array_map(fn ($address) => $address->getAddress(), $envelope->getRecipients()),
            method_exists($message, 'getSubject') ? (string) $message->getSubject() : '',
        ];

        $this->bodies[] = method_exists($message, 'getTextBody') ? (string) $message->getTextBody() : '';

        return new SentMessage($message, $envelope);
    }

    public function __toString(): string
    {
        return 'recording';
    }
}

class ThrowingTransport implements TransportInterface
{
    public function __construct(private readonly string $message) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException($this->message);
    }

    public function __toString(): string
    {
        return 'throwing';
    }
}
