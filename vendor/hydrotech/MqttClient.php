<?php
/**
 * Hydrotech - Klien MQTT 3.1.1 minimal (satu file, tanpa dependensi).
 *
 * Khusus kebutuhan HydroTech:
 *  - CONNECT dengan username/password + clean session + keepalive otomatis.
 *  - SUBSCRIBE (QoS 0/1) dengan callback per pesan.
 *  - PUBLISH (QoS 0/1) dengan retry PUBACK; retain tersedia (JANGAN dipakai
 *    untuk perintah pompa ON/OFF sesuai ketentuan sistem).
 *  - loop() non-blocking (stream_select) - aman untuk worker jangka-panjang.
 *
 * Tidak mendukung: TLS, QoS 2, will message, sesi persisten (clean session
 * selalu true) - sengaja, agar mudah diaudit. Broker HydroTech berada di
 * jaringan lokal, payload kecil, dan autentikasi ACL di broker.
 */

class MqttClient
{
    private string $host;
    private int $port;
    private string $clientId;
    private ?string $user;
    private ?string $pass;
    private int $keepalive;

    /** @var resource|null */
    private $socket = null;
    private string $buffer = '';
    private int $packetId = 0;
    private float $lastActivity = 0;

    /** @var array<int, array{payload:string, topic:string, at:float, tries:int}> */
    private array $unacked = [];

    /** @var callable|null function(string $topic, string $payload): void */
    private $onMessage = null;

    public function __construct(
        string $host,
        int $port,
        string $clientId,
        ?string $user = null,
        ?string $pass = null,
        int $keepalive = 30
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->clientId = $clientId . '-' . substr(md5((string)mt_rand()), 0, 6); // unique per run
        $this->user = $user;
        $this->pass = $pass;
        $this->keepalive = max(10, $keepalive);
    }

    public function setOnMessage(callable $cb): void
    {
        $this->onMessage = $cb;
    }

    public function connected(): bool
    {
        return is_resource($this->socket);
    }

    /** Koneksi + MQTT CONNECT; true bila CONNACK diterima. */
    public function connect(int $timeoutSec = 5): bool
    {
        $this->socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $timeoutSec
        );
        if (!$this->socket) return false;
        stream_set_timeout($this->socket, $timeoutSec);

        $flags = 0x02;                                   // clean session
        if ($this->user !== null) $flags |= 0x80;
        if ($this->pass !== null) $flags |= 0x40;

        $payload = $this->str($this->clientId);
        if ($this->user !== null) $payload .= $this->str($this->user);
        if ($this->pass !== null) $payload .= $this->str($this->pass);

        $body = $this->str('MQTT') . chr(4) . chr($flags)
            . pack('n', $this->keepalive) . $payload;
        fwrite($this->socket, $this->packet(0x10, $body));

        $this->buffer = '';
        $pkt = $this->readPacket($timeoutSec);
        if ($pkt === null || $pkt['type'] !== 0x02 || ord($pkt['body'][1] ?? "\x01") !== 0) {
            $this->close();
            return false;                                // CONNACK gagal / ditolak
        }
        $this->lastActivity = microtime(true);
        return true;
    }

    public function subscribe(string $topic, int $qos = 1): bool
    {
        if (!$this->connected()) return false;
        $body = pack('n', $this->nextId()) . $this->str($topic) . chr($qos);
        fwrite($this->socket, $this->packet(0x82, $body));
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {            // tunggu SUBACK (tipe 9)
            $pkt = $this->readPacket(1);
            if ($pkt === null) { $this->poll(200); continue; }
            if ($pkt['type'] === 0x09) return true;      // SUBACK
            $this->handlePacket($pkt);
        }
        return false;
    }

    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): bool
    {
        if (!$this->connected()) return false;
        $byte = 0x30 | ($qos === 1 ? 0x02 : 0x00) | ($retain ? 0x01 : 0x00);
        $body = $this->str($topic);
        if ($qos === 1) {
            $id = $this->nextId();
            $body .= pack('n', $id);
            $this->unacked[$id] = ['topic' => $topic, 'payload' => $payload, 'at' => microtime(true), 'tries' => 0];
        }
        $body .= $payload;
        fwrite($this->socket, $this->packet($byte, $body));
        return true;
    }

    /** Pumps jaringan selama $timeoutMs; dispatch pesan masuk; keepalive + retry. */
    public function loop(int $timeoutMs = 500): void
    {
        if (!$this->connected()) return;
        $this->poll($timeoutMs);
        while (($pkt = $this->readPacket(0)) !== null) $this->handlePacket($pkt);

        $now = microtime(true);
        if ($now - $this->lastActivity > $this->keepalive / 2) {
            fwrite($this->socket, pack('CC', 0xC0, 0x00));  // PINGREQ
            $this->lastActivity = $now;
        }
        foreach ($this->unacked as $id => $m) {          // retry PUBLISH QoS 1
            if ($now - $m['at'] >= 5) {
                if (++$m['tries'] > 5) { unset($this->unacked[$id]); continue; }
                $m['at'] = $now;
                $this->unacked[$id] = $m;
                $body = $this->str($m['topic']) . pack('n', $id) . $m['payload'];
                fwrite($this->socket, $this->packet(0x32, $body));
            }
        }
    }

    public function disconnect(): void
    {
        if ($this->connected()) fwrite($this->socket, pack('CC', 0xE0, 0x00));
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) fclose($this->socket);
        $this->socket = null;
    }

    /* ----------------------------------------------------------------- */

    private function handlePacket(array $pkt): void
    {
        switch ($pkt['type']) {
            case 0x03: {                                  // PUBLISH (qos0/qos1)
                [$topic, $offset] = $this->readStr($pkt['body']);
                $qos = ($pkt['header'] >> 1) & 0x03;
                $id = 0;
                if ($qos > 0) {
                    $id = unpack('n', substr($pkt['body'], $offset, 2))[1];
                    $offset += 2;
                }
                $payload = substr($pkt['body'], $offset);
                if ($qos > 0) fwrite($this->socket, $this->packet(0x40, pack('n', $id))); // PUBACK
                if ($this->onMessage) ($this->onMessage)($topic, $payload);
                break;
            }
            case 0x04: {                                  // PUBACK untuk PUBLISH QoS 1
                $id = unpack('n', $pkt['body'])[1];
                unset($this->unacked[$id]);
                break;
            }
        }
    }

    private function poll(int $timeoutMs): void
    {
        if (!$this->connected()) return;
        $r = [$this->socket];
        $w = null; $e = null;
        if (@stream_select($r, $w, $e, 0, $timeoutMs * 1000)) {
            $this->buffer .= (string)fread($this->socket, 65536);
        }
    }

    private function readPacket(int $timeoutSec): ?array
    {
        $this->poll($timeoutSec * 1000);
        if (strlen($this->buffer) < 2) return null;
        $header = ord($this->buffer[0]);
        $mult = 1; $len = 0; $pos = 1;
        do {                                              // varint remaining length
            if (!isset($this->buffer[$pos])) return null;
            $byte = ord($this->buffer[$pos++]);
            $len += ($byte & 0x7F) * $mult;
            $mult *= 128;
        } while ($byte & 0x80);
        if (strlen($this->buffer) < $pos + $len) return null;
        $body = substr($this->buffer, $pos, $len);
        $this->buffer = substr($this->buffer, $pos + $len);
        return ['header' => $header, 'type' => $header >> 4, 'body' => $body];
    }

    private function packet(int $header, string $body): string
    {
        $len = strlen($body);
        $out = chr($header);
        do {                                              // varint encode
            $d = $len % 128; $len = intdiv($len, 128);
            if ($len > 0) $d |= 0x80;
            $out .= chr($d);
        } while ($len > 0);
        return $out . $body;
    }

    private function str(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    private function readStr(string $body): array
    {
        $len = unpack('n', substr($body, 0, 2))[1];
        return [substr($body, 2, $len), 2 + $len];
    }

    private function nextId(): int
    {
        $this->packetId = ($this->packetId % 65535) + 1;
        return $this->packetId;
    }
}
