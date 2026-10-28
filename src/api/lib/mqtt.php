<?php
/* MQTT 3.1.1 client viet thuan bang PHP (khong can Composer).
   File nay giu vong doi ket noi (connect/disconnect); phan doc/ghi goi tin
   nam trong trait MqttIo (mqtt_io.php), phan publish/subscribe/loop nam
   trong trait MqttPubSub (mqtt_pubsub.php) - ca hai duoc class nay use. */

require_once __DIR__ . '/mqtt_io.php';
require_once __DIR__ . '/mqtt_pubsub.php';

class MqttException extends RuntimeException
{
}

final class MqttClient
{
    use MqttIo, MqttPubSub;

    private const CMD_CONNECT    = 0x10;
    private const CMD_CONNACK    = 0x20;
    private const CMD_PUBLISH    = 0x30;
    private const CMD_PUBACK     = 0x40;
    private const CMD_SUBSCRIBE  = 0x82;
    private const CMD_SUBACK     = 0x90;
    private const CMD_PINGREQ    = 0xC0;
    private const CMD_PINGRESP   = 0xD0;
    private const CMD_DISCONNECT = 0xE0;
    private const KEEPALIVE = 60;

    private $socket = null;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $clientId;
    private int $packetId = 0;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        string $clientId
    ) {
        $this->host     = $host;
        $this->port     = $port;
        $this->username = $username;
        $this->password = $password;
        $this->clientId = $clientId;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /* Ket noi va cho CONNACK. Thu lai toi da $attempts lan. */
    public function connect(int $attempts = 3): bool
    {
        $lastError = '';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', $this->host, $this->port),
                $errno,
                $errstr,
                5
            );
            if ($socket === false) {
                $lastError = $errstr ?: "connect failed (errno {$errno})";
                sleep(1);
                continue;
            }
            stream_set_timeout($socket, self::KEEPALIVE);
            $this->socket = $socket;
            // Connect flags: clean session (bit 1) + username (bit 7) + password (bit 6)
            $flags = 0x02;
            if ($this->username !== '') {
                $flags |= 0x80;
            }
            if ($this->password !== '') {
                $flags |= 0x40;
            }
            $body = self::write_string('MQTT')
                . chr(4)                       // protocol level 4 (3.1.1)
                . chr($flags)
                . pack('n', self::KEEPALIVE)
                . self::write_string($this->clientId)
                . self::write_string($this->username)
                . self::write_string($this->password);
            $this->write_packet(chr(self::CMD_CONNECT), $body);
            $packet = $this->read_packet(5);
            if ($packet === null || $packet['type'] !== self::CMD_CONNACK) {
                $lastError = 'Khong nhan duoc CONNACK tu broker';
                $this->close();
                continue;
            }
            $returnCode = ord($packet['body'][1] ?? "\x01");
            if ($returnCode !== 0) {
                $lastError = sprintf('Broker tu choi ket noi (code %d)', $returnCode);
                $this->close();
                continue;
            }
            return true;
        }
        throw new MqttException("Khong ket noi duoc MQTT broker {$this->host}:{$this->port}: {$lastError}");
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            $this->write_packet(chr(self::CMD_DISCONNECT), '');
        }
        $this->close();
    }
}
