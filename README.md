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
```

### Arguments

| Argument | Description                                      |
|----------|--------------------------------------------------|
| `secret` | The secret used to verify incoming notifications |

### Options

| Option            | Description                                                                                                                     | Default |
|-------------------|---------------------------------------------------------------------------------------------------------------------------------|---------|
| `--http-port`     | The port on which the HTTP server should listen                                                                                 | `8000`  |
| `--relay-port`    | The port on which the relay server should listen                                                                                | `8100`  |
| `--ping-interval` | The amount of time in seconds before sending a `PING` command to relay connections, and to close connections without a response | `30`    |

## Protocol

The relay protocol is command-based, with each command on its own line terminated by a line feed (`\n`).

During the lifetime of a client connection, the server may send the following commands:

- `PING`: Sent periodically to check that the client connection is still active.
- `RELAY <DATA>`: Used to relay Twitch EventSub notifications, where `<DATA>` is base64-encoded JSON.

The client **MUST** respond to each `PING` command with a `PONG` command. Aside from responding to `PING` commands, the client **MUST NOT** send any data to the server.

If no data is received from the server within a configurable timeout interval, the client **SHOULD** terminate the connection and attempt to reconnect.
