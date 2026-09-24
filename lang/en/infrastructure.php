<?php

declare(strict_types=1);

/*
 * Operator vocabulary for the Resource Graph and its adapters.
 *
 * `capabilities` is keyed by the dotted capability value and read by
 * `CapabilityNames`, never through `__()` — a key with dots in it asks the
 * translator to walk nesting that is not there. The same rule permissions live
 * under.
 *
 * Only the capabilities that exist as contracts have wording here. The rest read
 * as a derived name ("Policy write") until their phase lands, which is legible;
 * inventing sentences for a contract nobody has written yet would be inventing
 * behaviour to describe.
 */

return [
    'title' => 'Infrastructure',

    'kinds' => [
        'organization' => 'Organization',
        'server' => 'Server',
        'service' => 'Service',
        'customer' => 'Customer',
    ],

    'relations' => [
        'contains' => 'contains',
        'hosts' => 'hosts',
        'powers' => 'powers',
        'connects' => 'connects to',
        'serves' => 'serves',
        'assigned_to' => 'assigned to',
        'depends_on' => 'depends on',
    ],

    'areas' => [
        'monitoring' => 'Monitoring',
        'network_device' => 'Network devices',
        'firewall' => 'Firewalls',
        'switching' => 'Switches',
        'routing' => 'Routing',
        'flow' => 'Traffic flow',
        'ddos' => 'DDoS',
        'dns' => 'DNS',
        'certificate' => 'Certificates',
        'waf' => 'WAF',
        'cdn' => 'CDN',
        'storage' => 'Storage',
        'database_telemetry' => 'Databases',
        'load_balancer' => 'Load balancers',
        'hypervisor' => 'Virtualization',
        'bmc' => 'Bare metal',
        'pdu' => 'Power',
        'ups' => 'UPS',
        'backup' => 'Backup',
        'automation' => 'Configuration',
        'log' => 'Logs',
        'secret' => 'Secrets',
        'metering' => 'Usage metering',
    ],

    'units' => [
        'ratio' => 'ratio',
        'bytes' => 'bytes',
        'bits_per_second' => 'bit/s',
        'seconds' => 'seconds',
        'milliseconds' => 'ms',
        'count' => '',
        'per_second' => '/s',
        'celsius' => '°C',
        'watts' => 'W',
    ],

    'metrics' => [
        'cpu.utilisation' => 'CPU',
        'load.average' => 'Load',
        'memory.used' => 'Memory used',
        'memory.total' => 'Memory',
        'disk.used' => 'Disk used',
        'disk.total' => 'Disk',
        'disk.iops' => 'Disk IOPS',
        'disk.latency' => 'Disk latency',
        'network.in' => 'Traffic in',
        'network.out' => 'Traffic out',
        'network.packet_loss' => 'Packet loss',
        'uptime' => 'Uptime',
        'response.latency' => 'Response time',
        'sessions' => 'Sessions',
        'accounts' => 'Accounts',
        'requests.rate' => 'Requests',
        'requests.errors' => 'Errors',
        'queue.depth' => 'Queue',
        'replication.lag' => 'Replication lag',
        'cache.hit_ratio' => 'Cache hit ratio',
        'temperature' => 'Temperature',
        'power.draw' => 'Power draw',
        'battery.charge' => 'Battery',
        'battery.runtime' => 'Battery runtime',
    ],

    'capabilities' => [
        'monitoring.metrics.read' => [
            'label' => 'Read measurements',
            'description' => 'Ask this source for the current numbers about resources it watches.',
        ],
        'monitoring.alerts.read' => [
            'label' => 'Read alerts',
            'description' => 'See what this source is currently alerting on.',
        ],
        'monitoring.alerts.write' => [
            'label' => 'Acknowledge or silence alerts',
            'description' => 'Change alert state in the monitoring system itself.',
        ],
    ],

    'explorer' => [
        'title' => 'Explorer',
        'intro' => 'Everything this installation knows about, and how it hangs together.',
        'empty' => 'Nothing is in the graph yet. It is built by the resource graph task, hourly.',
        'search' => 'Search by name or key',
        'all_kinds' => 'Every kind',
        'all_health' => 'Any state',
        'columns' => [
            'label' => 'Resource',
            'kind' => 'Kind',
            'health' => 'State',
            'source' => 'Source',
            'seen' => 'Last seen',
        ],
        'stats' => [
            'nodes' => 'Resources',
            'unwatched' => 'Nothing reporting',
            'retired' => 'Retired',
        ],
        'drawer' => [
            'sits_on' => 'Sits on',
            'contains' => 'Contains',
            'impact' => 'If this fails',
            'history' => 'History',
            'metrics' => 'Latest measurements',
            'no_metrics' => 'Nothing is reporting measurements for this.',
            'no_history' => 'No relationship has changed.',
            'services' => 'services',
            'customers' => 'customers',
            'since' => 'since :when',
            'until' => 'until :when',
            'open_subject' => 'Open record',
            'truncated' => 'Stopped at :depth levels; there may be more below.',
        ],
    ],

    'adapters' => [
        'title' => 'Adapters',
        'intro' => 'What this installation can read from, and what it is allowed to change.',
        'empty' => 'No module provides an adapter yet.',
        'columns' => [
            'name' => 'Adapter',
            'vendor' => 'Vendor',
            'module' => 'Module',
            'areas' => 'Covers',
            'health' => 'State',
            'writes' => 'Changes',
        ],
        'read_only' => 'Read only',
        'writes_allowed' => 'May make changes',
        'writes_none' => 'Reads only, by design',
        'allow_writes' => 'Allow changes',
        'revoke_writes' => 'Revoke changes',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'check' => 'Check now',
        'orphaned' => 'No module currently provides this adapter.',
        'unsupported' => 'Version :version is newer than this adapter was written for.',
        'never_checked' => 'Never checked',
        'high_risk' => 'High risk',
        'confirm_writes' => [
            'title' => 'Allow :name to make changes?',
            'body' => 'It will be able to do the following on live infrastructure. Nothing else about this installation changes.',
            'phrase' => 'ALLOW CHANGES',
        ],
    ],

    'telemetry' => [
        'title' => 'Telemetry',
        'intro' => 'What is arriving, from where, and what has stopped.',
        'empty' => 'No measurements have arrived. An adapter has to be enabled first.',
        'columns' => [
            'resource' => 'Resource',
            'metric' => 'Measurement',
            'value' => 'Value',
            'sampled' => 'Taken',
            'source' => 'Source',
        ],
        'stats' => [
            'measurements' => 'Measurements',
            'sources' => 'Sources',
            'stale' => 'Stale',
            'unwatched' => 'Nothing reporting',
        ],
        'stale' => 'Stale',
        'fresh' => 'Fresh',
        'unwatched_intro' => 'Resources nothing is reporting on:',
    ],

    'errors' => [
        'not_permitted' => 'You do not have permission to do that.',
        'unknown_adapter' => 'No module currently provides that adapter.',
        'no_writes' => 'That adapter has nothing it could change.',
    ],
];
