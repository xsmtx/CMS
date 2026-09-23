# ADVANCED HOSTING OPERATIONS PLATFORM --- CLAUDE CODE HANDOFF #2

**Scope:** Advanced extension to Handoff #1: infrastructure,
network/security, datacenter, observability, automation/intelligence,
commercial optimization and iOS/Android mobile applications.

## 0. Rules

-   Read Handoff #1 first and reuse its Application Layer, RBAC, Audit,
    Events, API, Background Operations, Module SDK and entitlements.
-   Build every advanced capability as optional modules. Do not force
    DCIM/network features on small providers.
-   Prefer adapters to mature systems instead of rebuilding Prometheus,
    Zabbix, Veeam, FortiGate, etc.
-   The platform is the **context, correlation, orchestration and
    guarded-control layer**.
-   Every provider/device adapter declares capabilities.
-   Destructive actions require RBAC, audit, validation and
    confirmation/approval.
-   AI may analyze/recommend; high-impact actions require human approval
    unless an explicit narrowly scoped automation policy permits them.
-   Secrets use the central encrypted secret abstraction, optionally
    Vault/KMS-backed.
-   Mobile, web and API call the same backend use cases.

## 1. Product families

`Infrastructure Operations`, `Network & Security Operations`,
`Reliability & Incident Management`, `Datacenter & Hardware`,
`Fleet & Lifecycle`, `Automation & Intelligence`,
`Commercial Intelligence`, `Migration & Reconciliation`,
`Mobile Operations`.

## 2. Hosting Digital Twin / Resource Graph

Model relationships such as:

``` text
Datacenter → Room → Row → Rack → PDU → Physical Server → Hypervisor → VM
→ Hosting Server → Account → Service → Customer

ASN → Prefix → Router → Firewall → Switch → Port → VLAN → Server NIC
Storage → Pool → Volume → VM → Service
Domain → DNS → IP → Load Balancer → Web → Database → Service
```

Link invoices, payments, backups, certificates, tickets, incidents,
changes, abuse cases, alerts, vulnerabilities, licenses, vendors, costs
and revenue. The graph must answer impact questions: affected
customers/services/revenue, historical IP ownership, recent changes,
backup protection and dependency paths.

## 3. Infrastructure Operations Center

Unified
datacenter/cluster/server/hypervisor/VM/hosting/database/cache/load-balancer/storage/service
views with health, CPU/RAM/storage/I/O, bandwidth, capacity,
maintenance, alerts, incidents, recent changes and customer/revenue
impact.

## 4. Smart Provisioning & Capacity

Placement Engine scores targets using CPU, RAM, storage, I/O, bandwidth,
account count, reserved capacity, region, compatibility, health,
maintenance, historical growth and anti-affinity. Persist an explainable
selection reason.

Capacity Planning forecasts clusters, hypervisors, storage, IP pools,
racks, power, bandwidth, backup repositories and software licenses.
Forecasts may create planning/procurement tasks, never automatic
purchases.

## 5. IPAM

IPv4/IPv6 pools, prefixes/subnets, gateways, VLANs, assignments,
reservations, infrastructure/customer IPs, reverse DNS and assignment
history. Historical ownership is mandatory.

## 6. Network & Security Operations

Vendor-neutral adapters/capability discovery for Fortinet/FortiGate,
Juniper, Cisco, MikroTik, Arista, F5 and generic SNMP/NETCONF/API
devices.

Pages: Overview, Topology, Firewalls, Switches, Routers, Load Balancers,
Interfaces, VLANs, Routing/BGP, VPN, Firewall Policies, Traffic, Events,
Configuration, Changes.

Firewall detail: HA, CPU/memory, sessions, interfaces, policies, NAT,
VPN, routes, events, firmware/config revision. Switch/router detail:
ports, VLAN/LAG, MAC/ARP/ND, LLDP/CDP, STP, routing/BGP, optics,
errors/drops, firmware/config.

Optics: module type, serial/vendor, RX/TX power, temperature, voltage,
errors and thresholds.

Topology combines discovery with Digital Twin: Internet → Transit →
Router → Firewall HA → Core → Access Switch → Port → Server → VM →
Service → Customer.

Guarded config workflow:
`Request → Validate → Diff → Authorize → Approval(optional) → Backup → Apply → Verify → Complete/Rollback`.
Persist requester, approver, reason, ticket/change, exact diff and
result. Support scheduled changes and expiring temporary firewall rules
integrated with JIT access.

## 7. Routing, Flow & DDoS

ASN/prefix/BGP/transit/IX/latency views. NetFlow/sFlow/IPFIX integration
for top talkers, destinations, protocols, ASNs and traffic by
customer/service/IP. Prefer external scalable flow storage.

DDoS Event Center stores target, customer/service, duration, peak
Gbps/Mpps, vectors, mitigation/provider and incident link; calculate
impact automatically.

## 8. DNS / SSL / WAF / CDN

Authoritative DNS adapters: PowerDNS, BIND/Knot integrations,
Cloudflare, Route53, etc. Zones, records, DNSSEC, propagation, serial
consistency and bulk changes. Detect MX/SPF/DMARC/DKIM/NS/DNSSEC issues.
Recursive resolver monitoring: QPS, latency, cache hit,
SERVFAIL/NXDOMAIN and validation errors.

SSL fleet: ACME/commercial certs, expiry, failed renewal/deployment,
issuer, SAN/hostname and chain health mapped to
domains/services/customers.

WAF/CDN: request/cache/bandwidth/origin savings and attack categories;
raw high-volume telemetry stays in specialist backends.

## 9. Storage / Database / Cache / Load Balancer

Storage adapters: Ceph, ZFS, TrueNAS, NetApp, Dell, S3-compatible. Track
pools/volumes, capacity, health, latency, IOPS, degraded state and
attached workloads.

MariaDB/MySQL/PostgreSQL: connections, QPS, slow queries,
replication/lag, locks/deadlocks, buffer/cache, I/O and backup health.
Redis: memory, hit ratio, evictions, connections, latency,
replication/persistence.

Load balancers: HAProxy, Nginx, Traefik, Envoy, F5, Citrix ADC. Track
listeners/backends/health/rates/latency/TLS; guarded drain/undrain
integrates with maintenance.

## 10. Virtualization / Bare Metal

Adapters: Proxmox, VMware vSphere/vCenter, Hyper-V, XCP-ng, OpenStack.
Cluster/host/VM health, HA, snapshots and migrations.

BMC: Redfish, IPMI where needed, iDRAC, iLO, Supermicro. Power, sensors,
console, boot device and hardware health. Customer dedicated-server
controls are strictly ownership scoped.

## 11. Datacenter / Hardware / Power

Hardware inventory: servers, CPUs, DIMMs, disks, NICs, PSUs, switches,
routers, optics and spare parts with serial, asset tag,
purchase/warranty, location and replacement history.

DCIM: Datacenter → Room → Row → Rack → U → Device; free U,
power/network/capacity and later visual rack elevation.

PDU integrations (APC/Vertiv modules): A/B feeds, outlet load and device
mapping. UPS: load, battery/runtime and alarms. Environment:
temperature, humidity, leak, smoke, door and airflow.

Remote Hands tasks: rack/device, technician, required part, old/new
serial, timestamps and evidence. Optional physical-access windows link
to Change records.

## 12. Backup & Migration

Backup adapters: Veeam, Acronis, JetBackup, Proxmox Backup Server, Borg,
Restic, S3. Unified protected-resource, success/failure, last-good age,
repository capacity, restore points and restore operations. Detect
stale/unprotected services.

Migration Center supports panel-to-panel, IMAP and WordPress migrations
through:
`Discover → Preflight → Plan → Transfer → Validate → Cutover → Post-Validation → Report`.
Validate files, DB, mail, DNS, SSL, cron and disk where applicable. Jobs
are resumable/per-account.

## 13. Abuse / Mail / Security

Abuse Center: phishing, malware, spam, brute force, compromised
account/mailbox, vulnerability, blocklist and external reports.
Correlate Report → IP → Domain → Account → Service → Customer → Server.
Guarded actions may suspend, disable outbound mail, start credential
reset or contact customer.

Evidence Preservation stores configurable
domain/IP/account/DNS/HTTP/log/mail/timestamp/complaint/action
references under retention/privacy policies.

Mail Operations: Postfix/Exim/Dovecot queues, deferred/rejected,
authentication failures and throughput. Reputation: outbound anomalies,
bounce, RBL, PTR, SPF, DKIM, DMARC. Integrate Rspamd/SpamAssassin/ClamAV
where available.

## 14. Monitoring / Logs / Synthetic

MonitoringProvider adapters: Prometheus, Zabbix, Icinga, Nagios,
LibreNMS and Grafana-compatible sources. The platform enriches metrics
with service/customer/revenue/incident context.

Log integrations: Loki, ELK/OpenSearch/SIEM; do not dump massive raw
logs into MariaDB.

Synthetic monitoring from multiple regions: DNS, TCP, TLS, TTFB, HTTP
and total response. Safe performance metrics may be shown to customers.

## 15. Incident / Status / SLA / Postmortem

Alerts may create incidents, calculate impact, publish status-page
updates and notify only affected customers. SLA Engine calculates
configured eligibility and can propose/apply account credits according
to explicit policy.

Integrate on-call/escalation providers. Runbooks link incident types to
diagnostic/remediation steps. Postmortems contain impact, root cause,
resolution, SLA/customer impact, timeline and preventive actions;
timeline can be auto-built from alerts, changes and recovery.

## 16. Maintenance / Change / Drift / Vulnerability

Rolling Maintenance: `Drain → Change → Health Check → Return → Next`,
pausing on failure.

Change Management records scope, risk, schedule, rollback and approvals;
incidents show recent changes.

Configuration Drift compares expected vs actual state and can integrate
Ansible/AWX/Puppet/Salt. Fleet Patch Management tracks security
updates/reboots. Vulnerability Management correlates CVEs/findings with
affected resources and remediation versions.

## 17. Secrets & JIT Access

Credential Vault stores encrypted references to infrastructure/provider
credentials with optional external Vault/KMS. JIT Access creates
temporary server/network access with reason, ticket/change, expiry and
audit; avoid permanent root credentials.

## 18. WordPress Fleet

Discover WordPress installations; core/plugin/theme versions;
vulnerable/outdated components; backup/update/maintenance operations;
safe panel-login integration and malware findings. Integrate
vulnerability and backup modules.

## 19. Automation Builder

No-code workflow: `WHEN → IF → THEN → WAIT → RECHECK → THEN`. Examples:
overdue invoice suspension flow, disk alert, domain expiry, stale
backup, compromised mailbox.

Require dry-run, audit, permissions, loop prevention, execution limits
and action risk classes.

## 20. AI Operations Assistant

Use normalized operational context to summarize health, explain spikes,
correlate logs/metrics/changes, summarize incidents, propose runbook
steps, identify impact, draft incident communication and explain
capacity/anomalies. Recommendations must reference internal evidence.
Never execute arbitrary AI-generated shell/network commands.

## 21. Commercial Intelligence

Explainable Customer Health signals; unified Customer Timeline;
Cost/Profitability by product/service/node using infrastructure,
payment, license, bandwidth and optional support allocations.

Revenue Leakage detects active services without billing, domains without
renewal billing, addons missing billing, stale pricing/discounts and
unmatched payments.

Orphan Detection finds VMs, hosting accounts, IPs, DNS zones,
certificates etc. without valid platform ownership.

Resource/noisy-neighbour detection identifies disproportionate
CPU/I/O/PHP/DB usage and can propose upgrades.

## 22. Reconciliation Engine

Continuously compare Expected Platform State with Actual Provider State.
Classify `Healthy / Drift / Orphan / Missing / Unknown`.

Examples: platform Active but cPanel Suspended; platform Terminated but
VM still exists; expected 4 GB but provider has 8 GB. Provide safe
remediation proposals and approved automation. High-priority
differentiator.

## 23. Search / Command Palette

Global Ctrl/Cmd+K search across customer, domain, IP, service, server,
VM, invoice, ticket, incident, change and device, showing relationship
context and permitted quick actions.

## 24. Vendor / License / Contract / Procurement

Track operational licenses (cPanel, Plesk, CloudLinux, LiteSpeed,
Imunify, JetBackup, Windows/SQL, Acronis etc.), allocations, renewals,
costs and capacity.

Vendor Management covers datacenter, transit, hardware, software,
registrar, backup, cloud and DDoS providers. Contract Management tracks
expiry, auto-renew and pricing. Optional Procurement: forecast → request
→ approval → quote → PO → delivery → inventory → deployment.

## 25. Usage Billing / IaC / Kubernetes / Calendar

Bandwidth/usage metering feeds the core Billing domain through a stable
Metering contract with immutable invoiced usage snapshots.

Optional Terraform/OpenTofu, Ansible and Git integrations show
plans/diffs and require approval to apply. Kubernetes views focus on
hosting-service context rather than replacing Rancher.

Operations Calendar combines maintenance, changes, incidents, vendor
maintenance, certificate/domain/software renewals, contracts and
datacenter work.

# 26. iOS & Android Mobile Applications

Mobile is a first-class surface for **customers**, **resellers** and
**provider staff**.

Choose Flutter or React Native through an ADR after evaluating team
skills, accessibility, native integration and release tooling. Both apps
use the same `/api/v1`/Application Layer. No direct provider/device
access from phones.

### Customer mobile

-   dashboard;
-   services and status;
-   VPS/dedicated power actions where permitted;
-   usage/performance;
-   domains, DNS and renewals;
-   invoices, payments and credit;
-   tickets/replies/attachments;
-   announcements/status;
-   profile, 2FA/security and sessions;
-   API token overview (secret creation may remain web-only if safer);
-   push notifications.

### Staff mobile

-   operational dashboard;
-   alerts/incidents;
-   impacted customers/services;
-   acknowledge/assign/escalate;
-   incident timeline/status updates;
-   server/device/service health;
-   network/firewall/switch read views;
-   backup failures;
-   abuse cases;
-   tickets;
-   maintenance/change approvals;
-   Remote Hands tasks;
-   barcode/QR asset lookup;
-   rack/server lookup;
-   safe runbook actions;
-   JIT access request/approval.

High-risk actions such as firewall config, device reboot, service
termination, restore, mass actions or physical power control require
step-up authentication and explicit confirmation; some may be web-only
by policy.

### Push notification categories

`invoice`, `payment`, `domain`, `ticket`, `service`, `incident`,
`security`, `backup`, `maintenance`, `approval`, `on_call`.

Support per-category preferences, quiet hours, critical-alert policy
where platform rules permit, deep links and organization scoping.

### Mobile security

-   OAuth/token flow appropriate for first-party mobile;
-   Keychain/Keystore secure storage;
-   short-lived access + refresh rotation;
-   device/session inventory and remote revocation;
-   biometric unlock as local convenience, not sole server
    authentication;
-   step-up 2FA for high-risk operations;
-   certificate/network security best practices;
-   no secrets in logs/analytics/crash reports;
-   optional MDM/enterprise controls later.

### Offline behavior

Cache read-only dashboard/resource summaries and Remote Hands task data
where safe. Queue only explicitly safe offline actions. Never queue
destructive infrastructure changes offline.

### Mobile UX

Use role-aware home screens: - customer: services/billing/support; - NOC
engineer: incidents/alerts/health; - datacenter technician:
tasks/assets/racks; - manager: approvals/capacity/commercial impact.

Provide universal/deep links from push notifications directly to the
authorized resource.

# 27. Adapter Contracts

Create capability-oriented contracts, not one enormous provider
interface:

``` text
MonitoringProvider
NetworkDeviceProvider
FirewallProvider
SwitchProvider
RoutingProvider
FlowProvider
DDoSProvider
DnsProvider
CertificateProvider
WafProvider
CdnProvider
StorageProvider
DatabaseTelemetryProvider
LoadBalancerProvider
HypervisorProvider
BmcProvider
PduProvider
UpsProvider
BackupProvider
AutomationProvider
LogProvider
SecretProvider
MeteringProvider
```

Each adapter publishes capabilities, health, version compatibility, rate
limits and read/write support.

# 28. Priority Roadmap

**Phase A --- Foundation:** Resource Graph/Digital Twin, capability
registry, telemetry normalization, operations UI shell.

**Phase B --- Core Ops:** Infrastructure Center, monitoring adapters,
Smart Placement, capacity, global search.

**Phase C --- Network:** IPAM, FortiGate/Juniper first adapters,
topology, interfaces/VLAN/BGP, guarded config/change.

**Phase D --- Reliability:** incidents, status, SLA, maintenance,
runbooks, postmortems.

**Phase E --- Security:** abuse/mail reputation, vulnerability, WAF/DDoS
context, evidence preservation.

**Phase F --- Data/Platform:** backup, storage, DB/cache/LB,
hypervisors/BMC.

**Phase G --- Datacenter:** DCIM, rack, hardware inventory,
PDU/UPS/environment, Remote Hands.

**Phase H --- Intelligence:** reconciliation, orphan/revenue leakage,
noisy-neighbour, cost/profitability, AI assistant, automation builder.

**Phase I --- Mobile:** customer app first, then staff/NOC/DC
operations; push/deep links, step-up auth and mobile approvals.

**Phase J --- Advanced:** procurement/contracts, IaC/GitOps/Kubernetes,
deeper vendor integrations.

# 29. Definition of Done

Every advanced module must have: - explicit ownership/organization
isolation; - RBAC and audit; - adapter capability tests; - read/write
distinction; - secret redaction; - idempotent background operations; -
retry/timeout/error semantics; - health status; - API/OpenAPI updates; -
web UI loading/empty/error states; - mobile behavior where applicable; -
Digital Twin relationships; - impact calculation where relevant; - tests
for authorization and destructive operations; - operational
documentation and rollback/runbook notes.

# 30. Claude Code Starting Instruction

Do not implement this handoff immediately in full. First create
`docs/architecture/advanced-operations-plan.md`, map every module to
Handoff #1 domains, propose Resource Graph schema/read models, define
adapter capability conventions, identify which telemetry stays external,
create ADRs for Digital Twin and mobile framework choice, and produce a
dependency-aware task breakdown. Then implement only Phase A.
