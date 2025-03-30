<?php

namespace ClayFreeman\Twitch\EventSub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Io\BufferedBody;
use React\Http\Message\Response;
use React\Socket\ConnectionInterface;
use React\Socket\LimitingServer;
use React\Socket\SocketServer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Listens for webhook callbacks and relays them to connected TCP clients.
 */
final class RelayCommand extends Command {

  /**
   * The value for the notification message type.
   *
   * @var string
   */
  private const MESSAGE_TYPE_NOTIFICATION = 'notification';

  /**
   * The value for the webhook callback verification message type.
   *
   * @var string
   */
  private const MESSAGE_TYPE_VERIFICATION = 'webhook_callback_verification';

  /**
   * The value for the revocation message type.
   *
   * @var string
   */
  private const MESSAGE_TYPE_REVOCATION = 'revocation';

  /**
   * The minimum valid ping interval in seconds.
   *
   * @var int
   */
  private const PING_INTERVAL_MIN = 5;

  /**
   * The maximum valid ping interval in seconds.
   *
   * @var int
   */
  private const PING_INTERVAL_MAX = 300;

  /**
   * The HTTP server used to listen for incoming webhooks callbacks.
   *
   * @var \React\Http\HttpServer
   */
  private readonly HttpServer $http;

  /**
   * The ping interval in seconds for relay connections.
   *
   * @var int
   */
  private readonly int $interval;

  /**
   * The logger for this command.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private readonly LoggerInterface $logger;

  /**
   * The secret used to verify incoming notifications.
   *
   * @var string
   */
  private readonly string $secret;

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addOption(
      name: 'http-port',
      mode: InputOption::VALUE_REQUIRED,
      description: 'The port on which the HTTP server should listen',
      default: 8000,
    );

    $this->addOption(
      name: 'relay-port',
      mode: InputOption::VALUE_REQUIRED,
      description: 'The port on which the relay server should listen',
      default: 8100,
    );

    $this->addOption(
      name: 'ping-interval',
      mode: InputOption::VALUE_REQUIRED,
      description: 'The amount of time in seconds before sending a PING command to relay connections, and to close connections without a response',
      default: 30,
    );

    $this->addArgument(
      name: 'secret',
      mode: InputArgument::REQUIRED,
      description: 'The secret used to verify incoming notifications',
    );
  }

  /**
   * Create a continuous timer used to keep relay connections alive.
   *
   * @param \React\Socket\ConnectionInterface $connection
   *   The connection to periodically check.
   */
  private function createRelayTimer(ConnectionInterface $connection): void {
    // Wait for the configured interval before sending a PING command.
    Loop::addTimer($this->interval, function () use ($connection) {
      if ($connection->isReadable()) {
        // Wait for the configured interval before closing the connection.
        $timer = Loop::addTimer($this->interval, function () use ($connection) {
          if ($connection->isReadable()) {
            $this->logger->info('Relay connection timed out ({address})', [
              'address' => $connection->getRemoteAddress(),
            ]);

            $connection->removeAllListeners();
            $connection->close();
          }
        });

        $this->logger->debug('-> PING ({address})', [
          'address' => $connection->getRemoteAddress(),
        ]);

        // If any data is received, reset the relay timer.
        $connection->once('data', function () use ($connection, $timer) {
          Loop::cancelTimer($timer);

          $this->createRelayTimer($connection);
          $this->logger->debug('<- PONG ({address})', [
            'address' => $connection->getRemoteAddress(),
          ]);
        });

        // Begin the connection check transaction.
        $connection->write("PING\n");
      }
    });
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $this->logger = new ConsoleLogger($output);
    $this->secret = $input->getArgument('secret');
    $this->interval = $input->getOption('ping-interval');

    $http = new SocketServer("tcp://[::]:{$input->getOption('http-port')}");
    $relay = new LimitingServer(new SocketServer("tcp://[::]:{$input->getOption('relay-port')}"), NULL);

    // Set up the relay server to periodically check the health of connections.
    $relay->on('connection', $this->incoming(...));

    // Set up the HTTP server to process incoming requests and relay events.
    $this->http = new HttpServer($this->getResponseForRequest(...));
    $this->http->on('relay', function (string $payload) use ($relay) {
      foreach ($relay->getConnections() as $connection) {
        $connection->write("RELAY {$payload}\n");
      }
    });

    // Listen for new HTTP connections.
    $this->http->listen($http);

    // Log the listening addresses at startup.
    $this->logger->notice('Listening on ports {http} (HTTP), {relay} (relay)', [
      'http' => $input->getOption('http-port'),
      'relay' => $input->getOption('relay-port'),
    ]);

    return 0;
  }

  /**
   * Log when a request fails signature verification.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The request which failed signature verification.
   * @param string $expected
   *   The expected message signature value.
   * @param string $actual
   *   The actual message signature value.
   */
  private function failure(ServerRequestInterface $request, string $expected, string $actual): void {
    $context['actual'] = \json_encode($actual);
    $context['expected'] = \json_encode($expected);

    $this->logger->warning('An incoming request did not pass signature verification (expected: {expected}, actual: {actual})', $context);
    $this->logger->debug('{request}', [
      'request' => $this->getFormattedRequest($request),
    ]);
  }

  /**
   * Compute the expected message signature value for the supplied request.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The request for which to compute its expected message signature value.
   *
   * @return string
   *   The expected message signature value.
   */
  private function getExpectedSignature(ServerRequestInterface $request): string {
    $data[] = $request->getHeaderLine('Twitch-EventSub-Message-ID');
    $data[] = $request->getHeaderLine('Twitch-EventSub-Message-Timestamp');
    $data[] = (string) $request->getBody();

    // Compute the expected value using the configured secret key.
    $expected = \hash_hmac('sha256', \implode($data), $this->secret);

    return "sha256={$expected}";
  }

  /**
   * Format the supplied request as a string.
   *
   * Used to display logical requests for logging purposes. The output is not
   * meant to serve as a byte-for-byte copy of the actual request.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The request to format.
   *
   * @return string
   *   The formatted request.
   */
  private function getFormattedRequest(ServerRequestInterface $request): string {
    $result = "{$request->getMethod()} {$request->getRequestTarget()} HTTP/{$request->getProtocolVersion()}\r\n";

    foreach ($request->getHeaders() as $name => $values) {
      foreach ($values as $value) {
        $result .= "{$name}: {$value}\r\n";
      }
    }

    $result .= "\r\n";
    $result .= (string) $request->getBody();

    return $result;
  }

  /**
   * Handle an incoming HTTP request as a webhook callback.
   *
   * This method checks the incoming request to see if it passes signature
   * verification using the configured secret key.
   *
   * Based on the verification status of the request:
   * 1. A callback is scheduled for additional future processing.
   * 2. A response is immediately returned.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The request to process.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  private function getResponseForRequest(ServerRequestInterface $request): ResponseInterface {
    $signature = $request->getHeaderLine('Twitch-EventSub-Message-Signature');
    $expected = $this->getExpectedSignature($request);

    // Check whether the message signature matches the expected value.
    if (\hash_equals($expected, $signature)) {
      $request = $request->withParsedBody(@\json_decode((string) $request->getBody()) ?? (object) []);
      $type = $request->getHeaderLine('Twitch-EventSub-Message-Type');

      $response = match ($type) {
        static::MESSAGE_TYPE_NOTIFICATION => $this->notify($request),
        static::MESSAGE_TYPE_VERIFICATION => $this->verify($request),
        static::MESSAGE_TYPE_REVOCATION => $this->revoke($request),
      };
    }
    else {
      $response = new Response(Response::STATUS_FORBIDDEN);

      // Delay failure handling so a response can be returned immediately.
      Loop::futureTick(function () use ($request, $expected, $signature): void {
        $this->failure($request, $expected, $signature);
      });
    }

    return $response;
  }

  /**
   * Handle incoming relay connections.
   *
   * @param \React\Socket\ConnectionInterface $connection
   *   The incoming connection.
   */
  private function incoming(ConnectionInterface $connection) {
    $this->createRelayTimer($connection);

    $connection->on('close', function () use ($connection) {
      $this->logger->info('Relay connection reset by peer ({address})', [
        'address' => $connection->getRemoteAddress(),
      ]);
    });

    $this->logger->info('Relay connection accepted ({address})', [
      'address' => $connection->getRemoteAddress(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output): void {
    $secret = $input->getArgument('secret') ?? '';

    // Set the secret using the TWITCH_EVENTSUB_RELAY_SECRET environment
    // variable if the command-line argument was unspecified.
    if ('' === $secret && '' !== $secret = $_ENV['TWITCH_EVENTSUB_RELAY_SECRET'] ?? '') {
      $input->setArgument('secret', $secret);
    }

    $http_port = $input->getOption('http-port');
    $relay_port = $input->getOption('relay-port');

    // Ensure that the HTTP and relay port options are valid ports.
    if (!$this->isValidPort($http_port)) {
      throw new \InvalidArgumentException('--http-port must be a valid port');
    }
    if (!$this->isValidPort($relay_port)) {
      throw new \InvalidArgumentException('--relay-port must be a valid port');
    }

    // Ensure that the HTTP and relay ports aren't equal.
    if ($http_port == $relay_port) {
      throw new \InvalidArgumentException('--http-port and --relay-port cannot be equal');
    }

    $ping_interval = $input->getOption('ping-interval');

    // Ensure that the specified ping interval is valid.
    if (!$this->isValidPingInterval($ping_interval)) {
      $min = static::PING_INTERVAL_MIN;
      $max = static::PING_INTERVAL_MAX;

      throw new \InvalidArgumentException("--ping-interval must be between {$min} and {$max} seconds");
    }
  }

  /**
   * Check if the supplied string is a valid ping interval.
   *
   * @param string $input
   *   The string to validate.
   *
   * @return bool
   *   TRUE if the string is a valid ping interval, FALSE otherwise.
   */
  private function isValidPingInterval(string $input): bool {
    $input = \filter_var($input, \FILTER_VALIDATE_INT);

    if ($input !== FALSE && $input >= static::PING_INTERVAL_MIN && $input <= static::PING_INTERVAL_MAX) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check if the supplied string is a valid port.
   *
   * @param string $input
   *   The string to validate.
   *
   * @return bool
   *   TRUE if the string is a valid port, FALSE otherwise.
   */
  private function isValidPort(string $input): bool {
    $input = \filter_var($input, \FILTER_VALIDATE_INT);

    if ($input !== FALSE && $input > 0 && $input <= 65_535) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Relay event notifications to all connected clients.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The notification request.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  private function notify(ServerRequestInterface $request): ResponseInterface {
    $response = new Response(Response::STATUS_NO_CONTENT);

    // Delay notification relay so a response can be returned immediately.
    Loop::futureTick(function () use ($request) {
      /** @var object{subscription?:object{id?:string,type?:string}} */
      $data = $request->getParsedBody();

      $context['subscription_id'] = $data?->subscription?->id ?? '';
      $context['subscription_type'] = $data?->subscription?->type ?? '';

      if (\count(\array_filter($context)) === \count($context)) {
        $this->logger->info('Relaying event notification for subscription (id: {subscription_id}, type: {subscription_type}) to all connections', $context);
        $this->http->emit('relay', [
          \base64_encode((string) $request->getBody()),
        ]);
      }
      else {
        $this->logger->warning('Invalid event notification received');
      }

      $this->logger->debug('{request}', [
        'request' => $this->getFormattedRequest($request),
      ]);
    });

    return $response;
  }

  /**
   * Log when a subscription is revoked.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The revocation request.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  private function revoke(ServerRequestInterface $request): ResponseInterface {
    $response = new Response(Response::STATUS_INTERNAL_SERVER_ERROR);

    /** @var object{subscription?:object{id?:string,type?:string}} */
    $data = $request->getParsedBody();

    $context['subscription_id'] = $data?->subscription?->id ?? '';
    $context['subscription_type'] = $data?->subscription?->type ?? '';

    if (\count(\array_filter($context)) === \count($context)) {
      $response = new Response(Response::STATUS_NO_CONTENT);

      $this->logger->notice('Subscription revoked (id: {subscription_id}, type: {subscription_type})', $context);
    }
    else {
      $this->logger->warning('Invalid revocation request received');
    }

    $this->logger->debug('{request}', [
      'request' => $this->getFormattedRequest($request),
    ]);

    return $response;
  }

  /**
   * Verify a webhook callback.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The webhook callback verification request.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  private function verify(ServerRequestInterface $request): ResponseInterface {
    $response = new Response(Response::STATUS_INTERNAL_SERVER_ERROR);

    /** @var object{challenge?:string,subscription?:object{id?:string,type?:string}} */
    $data = $request->getParsedBody();

    $context['challenge'] = $data?->challenge ?? '';
    $context['subscription_id'] = $data?->subscription?->id ?? '';
    $context['subscription_type'] = $data?->subscription?->type ?? '';

    if (\count(\array_filter($context)) === \count($context)) {
      $response = new Response(Response::STATUS_OK);
      $response = $response->withHeader('Content-Type', 'text/plain');
      $response = $response->withBody(new BufferedBody($context['challenge']));

      $this->logger->notice('Verifying subscription (challenge: {challenge}, id: {subscription_id}, type: {subscription_type})', $context);
    }
    else {
      $this->logger->warning('Invalid webhook callback verification request received');
    }

    $this->logger->debug('{request}', [
      'request' => $this->getFormattedRequest($request),
    ]);

    return $response;
  }

}
