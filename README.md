# Twitch EventSub Relay

Twitch EventSub Relay is a command-line application that listens for Twitch EventSub webhook callbacks and relays them to connected TCP clients using a custom protocol.

## Features

- Listens for Twitch EventSub webhook callbacks over HTTP
- Relays events to connected clients over a persistent TCP connection
- Supports configurable HTTP and relay server ports
- Periodically checks connection health with a configurable keepalive interval

## Requirements

- PHP 8.1 or greater
- [Composer](https://getcomposer.org/)
- A reverse proxy to handle TLS termination

## Installation

Begin by installing this package using Composer:

```sh
composer require clayfreeman/twitch-eventsub-relay
```

Finally, set up the application to run as a system service, then configure a reverse proxy for HTTPS traffic.

This application listens on all addresses, so a firewall may be advisable.

## Usage

```sh
twitch-eventsub-relay [options] [--] <secret>
twitch-eventsub-relay-client <host> <port> [timeout]
twitch-eventsub-relay-dispatcher <dropins> <host> <port> [timeout]
```

### Server

#### Arguments

| Argument | Description                                      |
|----------|--------------------------------------------------|
| `secret` | The secret used to verify incoming notifications |

#### Options

| Option            | Description                                                                                                                     | Default |
|-------------------|---------------------------------------------------------------------------------------------------------------------------------|---------|
| `--http-port`     | The port on which the HTTP server should listen                                                                                 | `8000`  |
| `--relay-port`    | The port on which the relay server should listen                                                                                | `8100`  |
| `--ping-interval` | The amount of time in seconds before sending a `PING` command to relay connections, and to close connections without a response | `30`    |

### Client

The client can be used to connect to a relay server, respond to `PING` commands, decode relayed payloads, and handle connection timeouts automatically. It is recommended to pipe the output of the client to something which is capable of decoding and processing JSON data (e.g., `jq`).

#### Arguments

| Argument  | Description                                                                                                           |
|-----------|-----------------------------------------------------------------------------------------------------------------------|
| `host`    | The hostname or IP address of the relay server                                                                        |
| `port`    | The port of the relay server                                                                                          |
| `timeout` | The timeout duration in seconds (should be double that of the server's `--ping-interval` value; optional; default=60) |

### Dispatcher

The dispatcher leverages the relay client to listen for data, passing it to standard input for each executable file in the specified directory.

#### Arguments

| Argument  | Description                                                                                                           |
|-----------|-----------------------------------------------------------------------------------------------------------------------|
| `dropins` | The directory containing the executable files to run when data is received                                            |
| `host`    | The hostname or IP address of the relay server                                                                        |
| `port`    | The port of the relay server                                                                                          |
| `timeout` | The timeout duration in seconds (should be double that of the server's `--ping-interval` value; optional; default=60) |

## Protocol

The relay protocol is command-based, with each command on its own line terminated by a line feed (`\n`).

During the lifetime of a client connection, the server may send the following commands:

- `PING`: Sent periodically to check that the client connection is still active.
- `RELAY <DATA>`: Used to relay Twitch EventSub notifications, where `<DATA>` is base64-encoded JSON.

The client **MUST** respond to each `PING` command with a `PONG` command. Aside from responding to `PING` commands, the client **MUST NOT** send any data to the server.

If no data is received from the server within a configurable timeout interval, the client **SHOULD** terminate the connection and attempt to reconnect.
