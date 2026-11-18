/**
 * Wire types for the AWS AppSync Events realtime protocol
 * (`aws-appsync-event-ws` subprotocol family).
 *
 * @see https://docs.aws.amazon.com/appsync/latest/eventapi/event-api-websocket-protocol.html
 */

/**
 * The WebSocket subprotocol negotiated with the Event API.
 */
export const APPSYNC_WS_SUBPROTOCOL = 'aws-appsync-event-ws';

/**
 * WebSocket ready-state constants, declared locally so the connector
 * never relies on the `CONNECTING`/`OPEN`/… statics of an injected
 * WebSocket implementation.
 */
export const WS_CONNECTING = 0;
export const WS_OPEN = 1;
export const WS_CLOSING = 2;
export const WS_CLOSED = 3;

/**
 * `connection_init` — sent right after the socket opens.
 */
export interface ConnectionInitFrame {
    type: 'connection_init';
}

/**
 * `subscribe` — registers a channel subscription.
 */
export interface SubscribeFrame {
    type: 'subscribe';
    /** The per-subscription identifier (a UUID generated per channel). */
    id: string;
    /** The AppSync channel path `/{namespace}/{type}/{segment}`. */
    channel: string;
    /** The authorization headers for this subscription. */
    authorization: Record<string, string>;
}

/**
 * `unsubscribe` — drops a channel subscription.
 */
export interface UnsubscribeFrame {
    type: 'unsubscribe';
    id: string;
}

/**
 * Every frame the connector may send.
 */
export type OutboundFrame = ConnectionInitFrame | SubscribeFrame | UnsubscribeFrame;

/**
 * `connection_ack` — answers `connection_init`.
 */
export interface ConnectionAckMessage {
    type: 'connection_ack';
    connectionTimeoutMs?: number;
}

/**
 * `subscribe_success` — answers `subscribe`.
 */
export interface SubscribeSuccessMessage {
    type: 'subscribe_success';
    id: string;
}

/**
 * `subscribe_error` — the subscription was refused.
 */
export interface SubscribeErrorMessage {
    type: 'subscribe_error';
    id: string;
    [key: string]: unknown;
}

/**
 * `data` — carries broadcast events; `event` is a JSON string (or an
 * array of JSON strings) of `{"event": ..., "channel": ..., "data": ...}`.
 */
export interface DataMessage {
    type: 'data';
    id: string;
    event: string | string[];
}

/**
 * `ka` — keep-alive, re-arms the connection timeout.
 */
export interface KeepAliveMessage {
    type: 'ka';
}

/**
 * `connection_error` — a fatal connection-level error.
 */
export interface ConnectionErrorMessage {
    type: 'connection_error';
    [key: string]: unknown;
}

/**
 * Any message the server may send, including unknown types.
 */
export type InboundMessage =
    | ConnectionAckMessage
    | SubscribeSuccessMessage
    | SubscribeErrorMessage
    | DataMessage
    | KeepAliveMessage
    | ConnectionErrorMessage
    | { type: string; [key: string]: unknown };

/**
 * Synthetic error event delivered to a channel's `error` callbacks when
 * its authorization request fails before a `subscribe` frame is sent.
 */
export interface AuthErrorEvent {
    type: 'auth_error';
    /** The HTTP status of the failed authorization request, when available. */
    status?: number;
    /** The underlying error. */
    error: unknown;
}
