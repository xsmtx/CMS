<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * The measurements this platform keeps, and what each one is called here.
 *
 * A closed list, and that is the whole point of it. Prometheus has forty
 * thousand metric names and Zabbix has its own; a table that accepted any of
 * them would be a time-series database nobody sized, on a MariaDB instance whose
 * job is billing. §14 is explicit that the series stays outside, so what stays
 * here is the short list of readings a *screen* is built from — the ones that
 * answer "is this server in trouble", "is this pool nearly full", "how much
 * bandwidth is this customer using".
 *
 * `aliases()` is the normalizer's table and it is the only reason two adapters
 * can agree. `node_cpu_utilisation`, `system.cpu.util` and `cpu_percent` are
 * three vendors' spelling of one question, and an operator comparing a Proxmox
 * host with a cPanel server should not have to know which.
 *
 * Adding a member is cheap and adding one carelessly is not: every member is a
 * column on a screen somebody has to read. A metric that nothing renders belongs
 * in the monitoring system, which already has it.
 */
enum MetricKind: string
{
    case CpuUtilisation = 'cpu.utilisation';
    case LoadAverage = 'load.average';
    case MemoryUsed = 'memory.used';
    case MemoryTotal = 'memory.total';
    case DiskUsed = 'disk.used';
    case DiskTotal = 'disk.total';
    case DiskIops = 'disk.iops';
    case DiskLatency = 'disk.latency';
    case NetworkIn = 'network.in';
    case NetworkOut = 'network.out';
    case PacketLoss = 'network.packet_loss';
    case Uptime = 'uptime';
    case ResponseLatency = 'response.latency';
    case Sessions = 'sessions';
    case Accounts = 'accounts';
    case RequestRate = 'requests.rate';
    case ErrorRate = 'requests.errors';
    case QueueDepth = 'queue.depth';
    case ReplicationLag = 'replication.lag';
    case CacheHitRatio = 'cache.hit_ratio';
    case Temperature = 'temperature';
    case PowerDraw = 'power.draw';
    case BatteryCharge = 'battery.charge';
    case BatteryRuntime = 'battery.runtime';

    /**
     * Suffixes that name a unit, longest first so `_mbps` is not read as `_mb`.
     *
     * Deliberately short. Anything ambiguous — `_kb` for kilobits or kilobytes,
     * `_m` for megabytes or minutes — is left out, because a wrong unit is a
     * factor nobody can see on a screen.
     */
    private const array SuffixUnits = [
        'percent' => MetricUnit::Percent,
        'pct' => MetricUnit::Percent,
        'ratio' => MetricUnit::Ratio,
        'bytes' => MetricUnit::Bytes,
        'mb' => MetricUnit::Megabytes,
        'gb' => MetricUnit::Gigabytes,
        'tb' => MetricUnit::Terabytes,
        'bps' => MetricUnit::BitsPerSecond,
        'kbps' => MetricUnit::KilobitsPerSecond,
        'mbps' => MetricUnit::MegabitsPerSecond,
        'gbps' => MetricUnit::GigabitsPerSecond,
        'ms' => MetricUnit::Milliseconds,
        'seconds' => MetricUnit::Seconds,
        'secs' => MetricUnit::Seconds,
        'celsius' => MetricUnit::Celsius,
        'watts' => MetricUnit::Watts,
    ];

    /**
     * The unit this metric is stored in. Always canonical.
     */
    public function unit(): MetricUnit
    {
        return match ($this) {
            self::CpuUtilisation, self::PacketLoss,
            self::CacheHitRatio, self::BatteryCharge => MetricUnit::Ratio,
            self::LoadAverage, self::Sessions, self::Accounts, self::QueueDepth => MetricUnit::Count,
            self::MemoryUsed, self::MemoryTotal, self::DiskUsed, self::DiskTotal => MetricUnit::Bytes,
            self::DiskIops, self::RequestRate, self::ErrorRate => MetricUnit::PerSecond,
            self::NetworkIn, self::NetworkOut => MetricUnit::BitsPerSecond,
            self::Uptime, self::ReplicationLag, self::BatteryRuntime => MetricUnit::Seconds,
            self::DiskLatency, self::ResponseLatency => MetricUnit::Milliseconds,
            self::Temperature => MetricUnit::Celsius,
            self::PowerDraw => MetricUnit::Watts,
        };
    }

    /**
     * Whether a bigger number is worse.
     *
     * Not a threshold — there are none in this phase — but the direction a
     * *sparkline and a bar* are drawn in, which the Telemetry screen needs on
     * day one: a memory bar at 90% is red and a cache hit ratio at 90% is not,
     * and getting that wrong makes the screen actively misleading.
     *
     * `null` means neither: a count of accounts or a temperature has no
     * intrinsic direction without a capacity or a limit beside it.
     */
    public function higherIsWorse(): ?bool
    {
        return match ($this) {
            self::CpuUtilisation, self::MemoryUsed, self::DiskUsed, self::DiskLatency,
            self::PacketLoss, self::ResponseLatency, self::ErrorRate,
            self::QueueDepth, self::ReplicationLag => true,
            self::Uptime, self::CacheHitRatio, self::BatteryCharge, self::BatteryRuntime => false,
            default => null,
        };
    }

    /**
     * What other systems call this.
     *
     * Lowercased on both sides when matched, so only one spelling of each is
     * listed. Deliberately conservative: a wrong alias silently attributes one
     * vendor's number to another's question, which is worse than an unmapped
     * metric the Telemetry screen can show as unmapped.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::CpuUtilisation => [
                'cpu', 'cpu_usage', 'cpu_percent', 'cpu_util', 'node_cpu_utilisation',
                'system.cpu.util', 'cpuload', 'processor_load',
            ],
            self::LoadAverage => ['load', 'load1', 'loadavg', 'load_average_1m', 'system.cpu.load'],
            self::MemoryUsed => [
                'memory', 'mem_used', 'memory_used', 'memory_usage', 'used_memory',
                'node_memory_used_bytes', 'vm.memory.used',
            ],
            self::MemoryTotal => ['mem_total', 'memory_total', 'node_memory_total_bytes', 'vm.memory.total'],
            self::DiskUsed => ['disk', 'disk_used', 'disk_usage', 'storage_used', 'used_space', 'vfs.fs.used'],
            self::DiskTotal => ['disk_total', 'storage_total', 'total_space', 'quota', 'vfs.fs.total'],
            self::DiskIops => ['iops', 'disk_iops', 'io_ops'],
            self::DiskLatency => ['disk_latency', 'io_latency', 'await'],
            self::NetworkIn => ['net_in', 'bandwidth_in', 'rx', 'rx_bps', 'ifhcinoctets', 'net.if.in'],
            self::NetworkOut => ['net_out', 'bandwidth_out', 'tx', 'tx_bps', 'ifhcoutoctets', 'net.if.out'],
            self::PacketLoss => ['packet_loss', 'loss', 'icmp_loss'],
            self::Uptime => ['uptime', 'system_uptime', 'system.uptime'],
            self::ResponseLatency => ['latency', 'response_time', 'ttfb', 'rtt', 'ping'],
            self::Sessions => ['sessions', 'connections', 'active_connections', 'threads_connected'],
            self::Accounts => ['accounts', 'account_count', 'domains', 'vhosts'],
            self::RequestRate => ['requests', 'rps', 'qps', 'queries_per_second'],
            self::ErrorRate => ['errors', 'error_rate', 'http_5xx', '5xx'],
            self::QueueDepth => ['queue', 'queue_depth', 'queued', 'mail_queue', 'deferred'],
            self::ReplicationLag => ['replication_lag', 'seconds_behind_master', 'slave_lag', 'lag'],
            self::CacheHitRatio => ['hit_ratio', 'cache_hit_ratio', 'keyspace_hit_ratio', 'buffer_hit_ratio'],
            self::Temperature => ['temp', 'temperature', 'inlet_temp', 'cpu_temp'],
            self::PowerDraw => ['power', 'power_draw', 'watts', 'outlet_load', 'load_watts'],
            self::BatteryCharge => ['battery', 'battery_charge', 'charge_remaining'],
            self::BatteryRuntime => ['battery_runtime', 'runtime_remaining', 'autonomy'],
        };
    }

    /**
     * The kind a source's name means, or null when nothing here does.
     *
     * Null is a real answer and the normalizer keeps it as one: an unmapped
     * metric is counted and shown on the Telemetry screen rather than stored
     * under its own name. A table that grows a column per vendor spelling is a
     * table that has stopped being a fixed set of questions.
     */
    public static function match(string $name): ?self
    {
        $needle = strtolower(trim($name));

        if ($needle === '') {
            return null;
        }

        $exact = self::tryFrom($needle);

        if ($exact instanceof self) {
            return $exact;
        }

        foreach (self::cases() as $kind) {
            if (in_array($needle, $kind->aliases(), strict: true)) {
                return $kind;
            }
        }

        // `memory_used_mb` is `memory_used` with its unit written into the
        // name. Tried last, so an explicit alias always wins.
        $stripped = self::withoutUnitSuffix($needle);

        return $stripped === null ? null : self::match($stripped);
    }

    /**
     * The unit a source wrote into the metric's name, if it did.
     *
     * Reading it is the **opposite** of guessing: `cpu_percent` and
     * `memory_used_mb` say what they are, and a normalizer that ignored them
     * would store forty as a ratio of forty. Every vendor does this and there
     * is no world in which `_mb` means anything else.
     *
     * Only used when the source declared no unit of its own, and the result is
     * still checked against the metric's dimension — so `cpu_bytes` is a
     * mismatch rather than a conversion.
     */
    public static function unitFromName(string $name): ?MetricUnit
    {
        return self::SuffixUnits[self::suffixOf(strtolower(trim($name)))] ?? null;
    }

    private static function withoutUnitSuffix(string $needle): ?string
    {
        $suffix = self::suffixOf($needle);

        if ($suffix === null) {
            return null;
        }

        $stripped = substr($needle, 0, -(strlen($suffix) + 1));

        return $stripped === '' ? null : $stripped;
    }

    private static function suffixOf(string $needle): ?string
    {
        $position = strrpos($needle, '_');

        if ($position === false) {
            return null;
        }

        $suffix = substr($needle, $position + 1);

        return isset(self::SuffixUnits[$suffix]) ? $suffix : null;
    }
}
