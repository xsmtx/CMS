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
        // Written by topology discovery rather than by the projection, which
        // is why they sit here and not in `ResourceKind::Core`: core owns the
        // wording and an adapter owns the rows.
        'network_device' => 'Network device',
        'device_port' => 'Port',
        'vlan' => 'VLAN',
        'ip_address' => 'Address',
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

    /*
     * The device vocabulary (phase C §6). A port, a firewall rule's verdict
     * and a BGP session's state, in words rather than in the protocol's.
     */
    'ports' => [
        'up' => 'Up',
        // Separate from disabled on purpose: down is a cable, an optic or the
        // machine at the far end, disabled is a decision somebody made.
        'down' => 'Down',
        'disabled' => 'Disabled',
        'unknown' => 'Not reported',
    ],

    'firewall_actions' => [
        'allow' => 'Allow',
        // The packet vanishes and the client waits for a timeout.
        'deny' => 'Drop',
        // The packet comes back refused and the client fails at once.
        'reject' => 'Reject',
        'unknown' => 'Not reported',
    ],

    'bgp_states' => [
        'idle' => 'Idle',
        'connect' => 'Connecting',
        'active' => 'Trying',
        'open_sent' => 'Open sent',
        'open_confirm' => 'Open confirmed',
        'established' => 'Established',
        'unknown' => 'Not reported',
    ],

    'units' => [
        'ratio' => 'ratio',
        'percent' => '%',
        'bytes' => 'bytes',
        'kilobytes' => 'KB',
        'megabytes' => 'MB',
        'gigabytes' => 'GB',
        'terabytes' => 'TB',
        'bits_per_second' => 'bit/s',
        'kilobits_per_second' => 'kbit/s',
        'megabits_per_second' => 'Mbit/s',
        'gigabits_per_second' => 'Gbit/s',
        'bytes_per_second' => 'B/s',
        'seconds' => 'seconds',
        'milliseconds' => 'ms',
        'minutes' => 'minutes',
        'hours' => 'hours',
        'count' => '',
        'per_second' => '/s',
        'celsius' => '°C',
        'fahrenheit' => '°F',
        'watts' => 'W',
        'kilowatts' => 'kW',
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
        'network_device.inventory.read' => [
            'label' => 'Read the inventory',
            'description' => 'Ask the device what it is: model, serial, firmware and its interfaces.',
        ],
        'network_device.config.read' => [
            'label' => 'Read the configuration',
            'description' => 'Take a copy of the running configuration. It carries keys and community strings, so it is never rendered or logged.',
        ],
        'firewall.policy.read' => [
            'label' => 'Read the policy',
            'description' => 'List the firewall rules in the order the device evaluates them.',
        ],
        'firewall.session.read' => [
            'label' => 'Read session counts',
            'description' => 'How many sessions the firewall is holding, and how near its limit. Never the session table itself.',
        ],
        'switching.port.read' => [
            'label' => 'Read the ports',
            'description' => 'Which ports exist, whether they are up, and at what speed.',
        ],
        'switching.vlan.read' => [
            'label' => 'Read the VLANs',
            'description' => 'Which VLANs the device actually has, which is how you find the ones nobody recorded here.',
        ],
        'routing.route.read' => [
            'label' => 'Read routes',
            'description' => 'Where a prefix goes, according to the device rather than according to this platform.',
        ],
        'routing.bgp.read' => [
            'label' => 'Read BGP sessions',
            'description' => 'Which neighbours are up and how many prefixes each is sending.',
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
            'operator_state' => 'Operator state',
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
        'set_credential' => 'Set credential',
        'rotate_credential' => 'Rotate credential',
        'credential' => 'Credential',
        'credential_set' => 'A credential is stored. Last changed :when.',
        'credential_unset' => 'No credential stored.',
        'credential_hint' => 'Written to the vault, encrypted, and never shown again. Anything already stored is replaced.',
        'credential_clear_hint' => 'Leave it empty to destroy the stored credential.',
        'credential_saved' => 'Credential stored.',
        'credential_cleared' => 'Credential destroyed.',
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

    'capacity' => [
        'title' => 'Running out',
        'intro' => 'A straight line through the daily averages, and nothing more. What it cannot answer honestly it leaves out.',
        'now' => 'Now',
        'days_left' => 'Time left',
        'full_on' => 'Full on',
        'not_filling' => 'Not filling',
        'in_days' => ':days days',
        'from_days' => 'from :days days',
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
            'kind' => 'Kind',
            'key' => 'Key',
        ],
        'stats' => [
            'measurements' => 'Measurements',
            'sources' => 'Sources',
            'stale' => 'Stale',
            'unwatched' => 'Nothing reporting',
        ],
        'stale' => 'Stale',
        'fresh' => 'Fresh',
        'unwatched_title' => 'Nothing is reporting on these',
        'unwatched_intro' => 'No adapter has ever sent a measurement about them.',
        'unwatched_capped' => 'No adapter has ever sent a measurement about them. Showing :shown of :total.',
    ],

    'errors' => [
        'not_permitted' => 'You do not have permission to do that.',
        'unknown_adapter' => 'No module currently provides that adapter.',
        'no_writes' => 'That adapter has nothing it could change.',
    ],
];
