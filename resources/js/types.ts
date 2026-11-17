export type Args = Record<string, unknown>;

export interface Exchange {
    name: string;
    type: string;
    durable: boolean;
    internal: boolean;
    arguments: Args;
    rateIn: number;
    rateOut: number;
}

export type QueueRole = 'principal' | 'espera' | 'falhas' | 'passiva';

/** What the queue means to the business, from the optional catalog (see catalogo/exemplo.json). */
export interface QueueCatalog {
    name: string | null;
    description: string | null;
    category: string | null;
    role: QueueRole;
    instance: string | null;
}

export interface Category {
    key: string;
    name: string;
    description: string | null;
    color: string | null;
    /** A catch-all or shared area: it never counts as the "main area" of an instance. */
    generic: boolean;
}

export interface Queue {
    name: string;
    catalog: QueueCatalog;
    type: string;
    state: string | null;
    durable: boolean;
    arguments: Args;
    ready: number;
    unacked: number;
    consumers: number;
    rateIn: number;
    rateDeliver: number;
    rateAck: number;
    rateRedeliver?: number;
    /** Share of time the consumers could take a message at once (0..1); null when the broker does not say. */
    consumerCapacity?: number | null;
}

export interface Binding {
    source: string;
    destination: string;
    destinationType: 'queue' | 'exchange';
    routingKey: string;
    arguments: Args;
}

export interface WorkerQueue {
    queue: string;
    consumers: number;
    activeConsumers: number;
    prefetchTotal: number;
    manualAck: boolean;
    tags: string[];
}

export interface Worker {
    id: string;
    name: string;
    user: string | null;
    host: string | null;
    consumers: number;
    activeConsumers: number;
    prefetchMin: number;
    prefetchMax: number;
    manualAck: boolean;
    processingEstimate: number;
    queues: WorkerQueue[];
    /** TCP connections this process holds to the broker. */
    connections: WorkerConnection[];
    /** RabbitMQ nodes those connections are on. */
    nodes: string[];
    /** Name, service and description from the catalog (all null without a rule). */
    catalog: { name: string | null; service: string | null; description: string | null };
}

export interface WorkerConnection {
    name: string;
    host: string | null;
    port: number | null;
    node: string | null;
    consumers: number;
}

export interface Topology {
    broker: { version: string | null; cluster: string | null; vhost: string };
    exchanges: Exchange[];
    queues: Queue[];
    bindings: Binding[];
    workers: Worker[];
    categories: Category[];
    panels: PanelMeta[];
    fetchedAt: string;
}

/** A special view of queues that have no consumer by design (results, errors), defined in the catalog. */
export interface PanelMeta {
    id: string;
    title: string;
    kind: 'resultados' | 'erros';
    description: string | null;
    /** Real queue names this panel covers. */
    queues: string[];
}

export type PanelStatus = 'sucesso' | 'erro' | 'aviso' | 'info';

export interface PanelItem {
    id: number;
    /** When the message was put on the queue (epoch ms). */
    queuedAt: number;
    status: PanelStatus;
    title: string;
    text: string;
    facts: { label: string; value: string }[];
    instance: string | null;
    routingKey: string;
    exchange: string;
    queue: string | null;
}

export interface PanelData {
    panel: PanelMeta;
    queues: { name: string; label: string; ready: number; unacked: number; consumers: number; type: string; ttlMs: number | null }[];
    items: PanelItem[];
    /** Messages of this panel in the history, which can be more than `items`. */
    total: number;
    counts: Partial<Record<PanelStatus, number>>;
    tap: TapStatus;
}

export type NodeKind = 'producer' | 'exchange' | 'queue' | 'worker';
export interface Selection {
    kind: NodeKind | 'message';
    id: string;
}

export interface Delivery {
    queue: string;
    ts: number;
    redelivered: boolean;
}

export interface MessageSummary {
    id: number;
    rev: number;
    ts: number;
    origin: 'copy' | 'trace';
    exchange: string;
    routingKey: string;
    instance: string | null;
    /** The name people know the instance by ("Acme" for "acme.empresa.com.br"). */
    instanceName: string | null;
    priority: number | null;
    job: string | null;
    uuid: string | null;
    size: number;
    queues: string[];
    deliveries: Delivery[];
}

export interface MessageDetail extends MessageSummary {
    properties: { replyTo?: string | null; correlationId?: string | null; expiration?: string | null; persistent?: boolean; contentType?: string | null };
    headers: Args;
    body: string;
    bodyTruncated: boolean;
}

export interface TapStatus {
    enabled: boolean;
    active: boolean;
    error: string | null;
    since: number | null;
    tracing: boolean;
    received: number;
}

/** Best guess of what is inside a queue right now (see lib/estimate.ts). */
export interface QueueEstimate {
    /** Known messages, oldest first. */
    messages: MessageSummary[];
    /** Messages the broker reports as delivered and not acked. */
    unacked: number;
    /** Messages in the queue that were published before the tap started. */
    unknown: number;
    index: Map<number, number>;
}

export interface InstanceStat {
    /** "" stands for messages with no identifiable instance. */
    key: string;
    total: number;
    last: number;
    recent: number;
    processing: number;
    waiting: number;
    queues: Set<string>;
    /** exchange -> routing key -> count */
    exchanges: Map<string, Map<string, number>>;
}

/** One point of the dashboard history (an average of the samples the collector took in that slice). */
export interface MetricPoint {
    t: number;
    ready: number;
    unacked: number;
    consumers: number;
    rateIn: number;
    rateDeliver: number;
    rateAck: number;
    rateRedeliver?: number;
    capacity?: number | null;
    queues?: number;
    /** Messages waiting per catalog area. */
    areas: Record<string, number>;
}

export type MetricRange = '1h' | '6h' | '24h' | '3d';

export interface Metrics {
    range: MetricRange;
    from: number;
    to: number;
    samples: MetricPoint[];
    areas: Category[];
    instances: { key: string; name: string | null; count: number }[];
    published: { t: number; count: number }[];
}
