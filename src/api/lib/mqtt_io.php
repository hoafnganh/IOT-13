<?php
/* Trait MqttIo: tang doc/ghi goi tin MQTT ra socket.
   - write_packet: ghi fixed header + remaining length + body
   - read_packet / read_exact: doc dung so byte voi timeout (stream_select)
   - write_string / encode_remaining_length: encode truong cua chuan MQTT */

trait MqttIo
{
    private function next_packet_id(): int
    {
        $this->packetId = ($this->packetId % 65535) + 1;
        return $this->packetId;
    }

    /* Ghi mot goi tin ra socket. $header phai la CHUOI mot byte dung chr(). */
    private function write_packet(string $header, string $body): void
    {
        $packet = $header . self::encode_remaining_length(strlen($body)) . $body;
        $length = strlen($packet);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($this->socket, substr($packet, $offset));
            if ($written === false || $written === 0) {
                $this->close();
                throw new MqttException('Mat ket noi MQTT khi ghi du lieu');
            }
            $offset += $written;
        }
    }

    /* Doc mot goi tin MQTT. Tra ve null neu het thoi gian cho (khong co du lieu). */
    private function read_packet(int $timeoutSeconds = 0): ?array
    {
        if (!is_resource($this->socket)) {
            return null;
        }
        $first = $this->read_exact(1, $timeoutSeconds);
        if ($first === null) {
            return null;
        }
        $length = 0;
        $multiplier = 1;
        do {
            $byte = $this->read_exact(1, $timeoutSeconds);
            if ($byte === null) {
                return null;
            }
            $value = ord($byte);
            $length += ($value & 0x7F) * $multiplier;
            $multiplier *= 128;
            if ($multiplier > 2097152) {
                $this->close();
                throw new MqttException('Remaining length MQTT khong hop le');
            }
        } while (($value & 0x80) !== 0);
        $body = $length > 0 ? $this->read_exact($length, max($timeoutSeconds, 5)) : '';
        if ($body === null) {
            $this->close();
            throw new MqttException('Mat ket noi MQTT khi doc du lieu');
        }
        return [
            'type'  => ord($first) & 0xF0,
            'flags' => ord($first) & 0x0F,
            'body'  => $body,
        ];
    }

    /* Doc dung $bytes byte; tra ve null neu qua deadline. */
    private function read_exact(int $bytes, int $timeoutSeconds): ?string
    {
        if ($bytes === 0) {
            return '';
        }
        $data = '';
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        while (strlen($data) < $bytes) {
            if (!is_resource($this->socket)) {
                return null;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $read  = [$this->socket];
            $write = null;
            $except = null;
            $selected = @stream_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                (int) (($remaining - (int) $remaining) * 1000000)
            );
            if ($selected === false || $selected === 0) {
                return null;
            }
            $chunk = @fread($this->socket, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                if (feof($this->socket)) {
                    $this->close();
                    return null;
                }
                usleep(10000);
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /* Chuoi co do dai 2 byte dung truoc (encoding cua chuuan MQTT). */
    private static function write_string(string $value): string
    {
        return pack('n', strlen($value)) . $value;
    }

    private static function encode_remaining_length(int $length): string
    {
        $out = '';
        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $digit |= 0x80;
            }
            $out .= chr($digit);
        } while ($length > 0);
        return $out;
    }
}
