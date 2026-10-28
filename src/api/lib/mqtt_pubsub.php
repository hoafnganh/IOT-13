<?php
/* Trait MqttPubSub: publish (QoS 0/1), subscribe va vong loop nhan ban tin.
   loop() tu dong gui PINGREQ de duy tri keepalive khi khong co traffic. */

trait MqttPubSub
{
    /* Publish mot ban tin; QoS 0 la fire-and-forget, QoS 1 cho PUBACK. */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        if (!is_resource($this->socket)) {
            throw new MqttException('MQTT client chua ket noi');
        }
        $header = chr(self::CMD_PUBLISH | (($qos & 0x03) << 1) | ($retain ? 1 : 0));
        $body   = self::write_string($topic);
        if ($qos > 0) {
            $body .= pack('n', $this->next_packet_id());
        }
        $body .= $payload;
        $this->write_packet($header, $body);
        if ($qos > 0) {
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                $packet = $this->read_packet(2);
                if ($packet === null) {
                    break;
                }
                if ($packet['type'] === self::CMD_PUBACK) {
                    break;
                }
            }
        }
    }

    /* Dang ky nhan cac topic (QoS 0) va cho SUBACK. */
    public function subscribe(array $topics): void
    {
        if (!is_resource($this->socket)) {
            throw new MqttException('MQTT client chua ket noi');
        }
        $body = pack('n', $this->next_packet_id());
        foreach ($topics as $topic) {
            $body .= self::write_string($topic) . chr(0);
        }
        $this->write_packet(chr(self::CMD_SUBSCRIBE), $body);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $packet = $this->read_packet(2);
            if ($packet === null) {
                throw new MqttException('Khong nhan duoc SUBACK tu broker');
            }
            if ($packet['type'] === self::CMD_SUBACK) {
                return;
            }
        }
        throw new MqttException('Het thoi gian cho SUBACK tu broker');
    }

    /* Vong lap nhan ban tin trong $seconds giay; moi PUBLISH goi
       $onMessage(topic, payload). Gui PINGREQ khi nang kep KEEPALIVE/2. */
    public function loop(callable $onMessage, int $seconds): void
    {
        if (!is_resource($this->socket)) {
            throw new MqttException('MQTT client chua ket noi');
        }
        $deadline      = time() + $seconds;
        $lastTraffic   = time();
        while (time() < $deadline) {
            $packet = $this->read_packet(1);
            if ($packet !== null) {
                $lastTraffic = time();
                if ($packet['type'] === self::CMD_PUBLISH) {
                    $decoded = self::decode_publish($packet);
                    if ($decoded !== null) {
                        $onMessage($decoded['topic'], $decoded['payload']);
                        // QoS 1 can PUBACK
                        if (($packet['flags'] & 0x06) === 0x02) {
                            $this->write_packet(chr(self::CMD_PUBACK), pack('n', $decoded['packetId']));
                        }
                    }
                }
                continue;
            }
            if (!is_resource($this->socket)) {
                throw new MqttException('MQTT broker da dong ket noi');
            }
            if (time() - $lastTraffic >= self::KEEPALIVE / 2) {
                $this->write_packet(chr(self::CMD_PINGREQ), '');
                $lastTraffic = time();
            }
        }
    }

    /* Tach topic + payload (+ packet id neu QoS 1) khoi body mot goi PUBLISH. */
    private static function decode_publish(array $packet): ?array
    {
        $body = $packet['body'];
        if (strlen($body) < 2) {
            return null;
        }
        $topicLength = unpack('n', substr($body, 0, 2))[1];
        if (strlen($body) < 2 + $topicLength) {
            return null;
        }
        $topic = substr($body, 2, $topicLength);
        $offset = 2 + $topicLength;
        $packetId = 0;
        if (($packet['flags'] & 0x06) !== 0) {
            if (strlen($body) < $offset + 2) {
                return null;
            }
            $packetId = unpack('n', substr($body, $offset, 2))[1];
            $offset += 2;
        }
        return [
            'topic'     => $topic,
            'payload'   => substr($body, $offset),
            'packetId'  => $packetId,
        ];
    }
}
