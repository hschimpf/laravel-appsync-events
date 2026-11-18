import { Channel as BaseChannel, EventFormatter } from 'laravel-echo';

import type { AppSyncConnector } from './connector.js';

/**
 * An AppSync Events channel subscription.
 *
 * Created by {@link AppSyncConnector.channel} /
 * {@link AppSyncConnector.privateChannel}; instances are also returned by
 * `Echo#channel` / `Echo#private`. Supports multiple listeners per event,
 * `subscribed`/`error` callbacks, and Laravel notifications via the base
 * class's `notification()`.
 */
export class AppSyncChannel extends BaseChannel {
    /**
     * The channel name as it appears on the wire (e.g. `private-orders.1`).
     */
    public readonly name: string;

    /**
     * The connector that owns this channel.
     */
    public readonly connector: AppSyncConnector;

    /**
     * The subscription identifier sent in subscribe/unsubscribe frames.
     */
    public readonly subscriptionId: string;

    /**
     * Formats event names against the configured Echo namespace.
     */
    protected readonly eventFormatter: EventFormatter;

    /**
     * Listener callbacks keyed by formatted event name.
     */
    protected readonly listeners = new Map<string, Set<CallableFunction>>();

    /**
     * Callbacks invoked on `subscribe_success`.
     */
    protected readonly subscribedCallbacks: Array<() => void> = [];

    /**
     * Callbacks invoked on `subscribe_error` or authorization failure.
     */
    protected readonly errorCallbacks: Array<(error: unknown) => void> = [];

    /**
     * Create a new channel instance and register it with the connector.
     *
     * @param connector - The owning connector.
     * @param name - The wire channel name.
     * @param options - The connector options.
     */
    public constructor(connector: AppSyncConnector, name: string, options: AppSyncConnector['options']) {
        super();
        this.connector = connector;
        this.name = name;
        this.options = options;
        this.subscriptionId = crypto.randomUUID();
        this.eventFormatter = new EventFormatter(options.namespace ?? 'App.Events');
        this.subscribe();
    }

    /**
     * Ask the connector to (re)subscribe this channel.
     */
    public subscribe(): void {
        this.connector.subscribe(this);
    }

    /**
     * Listen for an event on the channel.
     *
     * @param event - The event name; prefixed `.`/`\\` names are used
     *     verbatim, others are formatted against the Echo namespace.
     * @param callback - Invoked with the event payload.
     * @returns This channel.
     */
    public listen(event: string, callback: CallableFunction): this {
        return this.on(this.eventFormatter.format(event), callback);
    }

    /**
     * Register a callback under an already-formatted event name.
     *
     * @param event - The formatted event name.
     * @param callback - Invoked with the event payload.
     * @returns This channel.
     */
    public on(event: string, callback: CallableFunction): this {
        let callbacks = this.listeners.get(event);
        if (!callbacks) {
            callbacks = new Set();
            this.listeners.set(event, callbacks);
        }
        callbacks.add(callback);

        return this;
    }

    /**
     * Stop listening to an event on the channel instance.
     *
     * @param event - The event name (formatted like {@link listen}).
     * @param callback - When given, only that listener is removed;
     *     otherwise all listeners for the event are removed.
     * @returns This channel.
     */
    public stopListening(event: string, callback?: CallableFunction): this {
        const formatted = this.eventFormatter.format(event);

        if (callback === undefined) {
            this.listeners.delete(formatted);
        } else {
            this.listeners.get(formatted)?.delete(callback);
        }

        return this;
    }

    /**
     * Dispatch an event payload to all registered listeners. A throwing
     * listener does not prevent the others from running.
     *
     * @param event - The raw event name from the wire.
     * @param data - The event payload.
     */
    public emit(event: string, data: unknown): void {
        const callbacks = this.listeners.get(event);
        if (!callbacks) {
            return;
        }

        for (const callback of callbacks) {
            try {
                callback(data);
            } catch (error) {
                if (this.options.debug) {
                    console.warn('[AppSyncConnector] listener error', event, error);
                }
            }
        }
    }

    /**
     * Register a callback to be called anytime a subscription succeeds.
     *
     * @param callback - Invoked on `subscribe_success`.
     * @returns This channel.
     */
    public subscribed(callback: CallableFunction): this {
        this.subscribedCallbacks.push(callback as () => void);

        return this;
    }

    /**
     * Register a callback to be called anytime an error occurs.
     *
     * @param callback - Invoked with a `subscribe_error` message or an
     *     `auth_error` event when authorization fails.
     * @returns This channel.
     */
    public error(callback: CallableFunction): this {
        this.errorCallbacks.push(callback as (error: unknown) => void);

        return this;
    }

    /**
     * Unsubscribe from this channel.
     */
    public unsubscribe(): void {
        this.connector.unsubscribe(this);
    }

    /**
     * Invoke every `subscribed` callback.
     */
    public notifySubscribed(): void {
        for (const callback of this.subscribedCallbacks) {
            callback();
        }
    }

    /**
     * Invoke every `error` callback.
     *
     * @param error - The error payload (`subscribe_error` message or
     *     `auth_error` event).
     */
    public notifyError(error: unknown): void {
        for (const callback of this.errorCallbacks) {
            callback(error);
        }
    }
}
