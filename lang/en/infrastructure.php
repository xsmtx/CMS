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

    'sites' => [
        'components' => [
            'plugin' => 'Plugin',
            'theme' => 'Theme',
        ],
    ],

    'kinds' => [
        'site' => 'Site',
        'pdu' => 'PDU',
        'pdu_outlet' => 'Outlet',
        'ups' => 'UPS',
        'environment_sensor' => 'Sensor',
        'hypervisor_host' => 'Host',
        'virtual_machine' => 'Virtual machine',
        'lb_listener' => 'Listener',
        'lb_backend' => 'Backend',
        'storage_pool' => 'Storage pool',
        'storage_volume' => 'Volume',
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

    'backups' => [
        'title' => 'Backup coverage',
        'intro' => 'What is protected, what is stale and what nothing is backing up. Read by asking each source; nothing here runs a backup.',
        'unprotected' => 'Unprotected',
        'stale' => 'Stale',
        'protected' => 'Protected',
        'unprotected_tab' => 'Nothing is protecting these',
        'stale_tab' => 'Something is trying and not succeeding',
        'protected_tab' => 'Everything with a recent good copy',
        'empty_unprotected' => 'Everything is protected',
        'empty_unprotected_detail' => 'Every running service is named by a live backup source. If no backup source has been configured yet, this says so by listing every service instead — core reads a source rather than taking backups.',
        'empty_stale' => 'Nothing is stale',
        'empty_stale_detail' => 'Every protection here has a good copy inside the window.',
        'empty_protected' => 'Nothing is being backed up',
        'empty_protected_detail' => 'No backup source has reported a resource with a recent good copy. Either none is configured, or every job here is failing — the other two figures say which.',
        'stale_after' => 'A good copy older than :days days is stale',
        'never' => 'Nothing yet',
        'never_detail' => 'Added to a job and not yet succeeded — which is not the same as failing.',
        // “0 days old” beside a date reads as a figure that failed to
        // load rather than as a backup that ran this morning.
        'today' => 'Today',
        'days_old' => ':days days old',
        'unmatched' => 'No service here',
        'columns' => [
            'service' => 'Service',
            'resource' => 'Protected as',
            'customer' => 'Customer',
            'status' => 'Status',
            'outcome' => 'Last run',
            'last_good' => 'Last good copy',
            'restore_points' => 'Restore points',
            'source' => 'Source',
        ],
    ],

    'areas' => [
        'site' => 'Web applications',
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
        'mail' => 'Mail and reputation',
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
        'mail.queue.depth' => 'Mail queue',
        'mail.queue.deferred' => 'Deferred mail',
        'mail.queue.held' => 'Held mail',
        'mail.delivered' => 'Mail delivered',
        'mail.rejected' => 'Mail rejected',
        'mail.bounce_rate' => 'Bounce rate',
        'mail.auth_failures' => 'Failed mail logins',
        'mail.spam_score' => 'Average spam score',
        'db.slow_queries' => 'Slow queries',
        'db.deadlocks' => 'Deadlocks',
        'cache.evictions' => 'Cache evictions',
        'humidity' => 'Humidity',
        'airflow' => 'Airflow',
        'battery.runtime' => 'Battery runtime',
    ],

    'virtualisation' => [
        // Why a power action did not go ahead — `PowerRefused::worded()`.
        'errors' => [
            'not_addressable' => 'This platform does not know how to reach :machine on its hypervisor.',
            'read_only' => 'The hypervisor running :machine cannot be written to.',
            'writes_not_enabled' => 'Writes are not enabled for :adapter.',
            'missing' => 'The hypervisor no longer knows about :machine.',
            'already_there' => ':machine is already :state. Reload the page — it is showing something that is no longer true.',
            'hypervisor_refused' => 'The hypervisor refused to change :machine: :because',
            'unverifiable' => 'The hypervisor took the command and could not then describe :machine, so this platform cannot say what it is doing.',
        ],

        'title' => 'Machines',
        'intro' => 'Every host and the machines on it. What goes down if a host is rebooted is the question this answers — before anybody finds out.',
        'empty' => 'No hypervisor has reported',
        'empty_detail' => 'Either no hypervisor adapter has been configured, or the ones that are have nothing running yet.',
        'running' => 'running',
        'no_machines' => 'Nothing is running on this host.',
        'unplaced' => 'Machines with no host',
        'unplaced_detail' => 'The hypervisor named these and did not say which host they are on. A standalone box answers this way, and so does one this platform could not read the node list from.',
        'power_menu' => 'Power actions for :name',
        'done' => 'The hypervisor now reports :state.',
        'confirm_mismatch' => 'That is not the name of this machine.',

        'confirm' => [
            // A title per action rather than one with the button's label
            // interpolated into it: ":name Shut down?" is not a sentence, and
            // in Turkish the word order is different again. A label is a
            // button word and a title is a question.
            'start' => 'Start :name?',
            'shutdown' => 'Shut :name down?',
            'power_off' => 'Cut the power to :name?',
            'reboot' => 'Restart :name?',

            // And a sentence per action, because the four are four different
            // promises.
            'start_body' => 'This starts the machine. Nothing is lost and nothing else is affected.',
            'shutdown_body' => 'This asks the operating system to stop. It can take several minutes, and whatever is running on this machine will stop serving. Everything on it goes down, not just one customer.',
            'power_off_body' => 'This cuts the power. Anything the machine has not written to disk is lost, and whatever is running on it stops at once. Use this only when the operating system has stopped listening.',
            'reboot_body' => 'This asks the operating system to restart. Everything on this machine stops serving until it comes back, which is usually a minute or two and occasionally never.',
        ],

        'states' => [
            'running' => 'Running',
            // The most common way a cluster runs out of memory with half its
            // machines idle.
            'paused' => 'Paused',
            'stopped' => 'Stopped',
            'unknown' => 'Not reported',
        ],

        'power' => [
            'start' => 'Start',
            'shutdown' => 'Shut down',
            'power_off' => 'Cut the power',
            'reboot' => 'Restart',
        ],

        'columns' => [
            'machine' => 'Machine',
            'kind' => 'Kind',
            'vcpus' => 'vCPU',
            'state' => 'State',
        ],
    ],

    'loadbalancing' => [
        /*
         * Why a drain or an undrain did not go ahead — read through
         * `BackendRefused::worded()`. The exception's own message is English
         * and belongs in the log; this is what somebody reads.
         */
        'errors' => [
            'not_addressable' => 'This platform does not know how to reach :backend on its balancer.',
            'read_only' => 'The balancer holding :backend cannot be written to.',
            'writes_not_enabled' => 'Writes are not enabled for :adapter.',
            'balancer_refused' => 'The balancer refused to change :backend: :because',
            'unverifiable' => 'The balancer took the command and could not then describe :backend, so this platform cannot say what it is doing.',
        ],

        'title' => 'Load balancers',
        'intro' => 'What each balancer is listening on and which machines are behind it. Draining stops new connections; the ones already open are left to finish.',
        'empty' => 'No balancer has reported',
        'empty_detail' => 'Either no load balancer adapter has been configured, or the ones that are have nothing to serve yet.',
        'backends' => 'Backends',
        // Reads after a fraction — “2 / 4 still taking traffic” — so it is
        // not capitalised.
        'serving' => 'still taking traffic',
        'no_backends' => 'Nothing is behind this listener.',
        'drain' => 'Drain',
        'undrain' => 'Put back',
        'done' => 'The balancer now reports :state.',

        'confirm' => [
            'drain_title' => 'Stop sending new connections to :name?',
            // What actually happens, in the words somebody needs before they
            // reboot a machine: the open connections are not cut.
            'drain_body' => 'The balancer will stop sending new connections to this machine. Connections it already has are left to finish, so watch the count come down before you take the machine out of service. Nothing is cut.',
            'undrain_title' => 'Put :name back into the rotation?',
            'undrain_body' => 'The balancer will start sending new connections to this machine again.',
        ],

        'states' => [
            'up' => 'Taking traffic',
            // Neither up nor down: serving what it has, taking nothing new.
            'draining' => 'Draining',
            'down' => 'Failing its health check',
            'disabled' => 'Turned off',
            'unknown' => 'Not reported',
        ],

        'columns' => [
            'backend' => 'Backend',
            'address' => 'Address',
            'weight' => 'Weight',
            'state' => 'State',
        ],
    ],

    'storage' => [
        'health' => [
            'healthy' => 'Healthy',
            // The member a three-state scale loses: a pool rebuilding after a
            // disk failure is serving every read and is one more failure from
            // losing data.
            'degraded' => 'Degraded',
            'critical' => 'Critical',
            'unknown' => 'Not reported',
        ],
    ],

    'backup' => [
        'outcomes' => [
            'succeeded' => 'Succeeded',
            // A partial backup is not a success and not a failure. Collapsing
            // it into either is how somebody finds out at restore time.
            'warning' => 'Partial',
            'failed' => 'Failed',
            'unknown' => 'Not reported',
        ],
    ],

    'capabilities' => [
        'site.inventory.read' => [
            'label' => 'Read installed applications',
            'description' => 'Ask this panel which web applications it is hosting, and which version each one and its plugins and themes are on.',
        ],
        'site.vulnerability.read' => [
            'label' => 'Read published advisories',
            'description' => 'Ask whether anything has been published against a plugin or a theme that is installed. Without this, a site’s advisory count is left unknown rather than reported as zero.',
        ],
        'mail.reputation.read' => [
            'label' => 'Read blocklist listings',
            'description' => 'Ask whether this installation’s own sending addresses are on a blocklist. Nothing here asks for one to be lifted — that is a form with a human on the other end.',
        ],
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
        'attributes' => [
            'model' => 'Model',
            'serial' => 'Serial',
            'firmware' => 'Firmware',
            'hostname' => 'Hostname',
            'vendor' => 'Vendor',
            'state' => 'State',
            'description' => 'Description',
            'speed_mbps' => 'Speed (Mbps)',
            'mac' => 'MAC address',
            'vlan' => 'VLAN',
            'health' => 'Reported health',
            'technology' => 'Technology',
            'replicas' => 'Copies kept',
            'attached_to' => 'Attached to',
            'feed' => 'Feed',
            'breaker' => 'Breaker',
            'on' => 'Switched on',
            'plugged_in' => 'Plugged in',
            'on_battery' => 'On battery',
            'alarm' => 'Alarm',
            'sensor' => 'Measures',
            'location' => 'Where',
            'triggered' => 'Triggered',
        ],

        'values' => [
            // The two attribute keys whose value is an enum rather than a
            // fact. Everything else an adapter reports is its own word — a
            // model number is not ours to translate.
            'health' => [
                'healthy' => 'Healthy',
                'degraded' => 'Degraded',
                'critical' => 'Critical',
                'unknown' => 'Not reported',
            ],
            'feed' => [
                'a' => 'A',
                'b' => 'B',
                // Never guessed: a single-fed device that looked
                // redundant is the one wrong answer that costs an outage.
                'unknown' => 'Not stated',
            ],
            'sensor' => [
                'temperature' => 'Temperature',
                'humidity' => 'Humidity',
                'airflow' => 'Airflow',
                'leak' => 'Water',
                'smoke' => 'Smoke',
                'door' => 'Door',
            ],
            'state' => [
                'up' => 'Up',
                // Administratively up with no cable in it is down, and a
                // port somebody shut deliberately is neither.
                'down' => 'Down',
                'disabled' => 'Disabled',
                'unknown' => 'Not reported',
            ],
        ],

        'drawer' => [
            'reported' => 'What it reported',
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
