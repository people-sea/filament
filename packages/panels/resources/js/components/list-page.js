import eventListener from '../../../../support/resources/js/utilities/event-listener.js'

export default ({ channel, echoConfiguration, hasDeferredFilters }) => {
    let isDestroyed = false
    let revision = 0
    let userRequests = 0
    let removeMessageInterceptor
    const waitingCalls = new Set()
    const rejectedMethods = new Set()
    const removeWatchers = []
    const listeners = new AbortController()
    const methods = ['authorizeListUpdates', 'refreshList']
    const isActive = (owner) => !isDestroyed && owner.$el.isConnected
    const transport = eventListener({
        channel,
        echoConfiguration,
        channelType: 'private',
        eventName: '.filament.model-list-changed',
        authorizationMethod: 'authorizeListUpdates',
        refreshMethod: 'refreshList',
        passEvent: false,
        refreshOnSubscription: true,
        coalesce: true,
        async callUsing(method, parameters, call) {
            while (isActive(this)) {
                if (this.busy()) {
                    await this.waitForListCall(method)
                }

                if (!isActive(this)) return null
                if (this.busy()) continue

                rejectedMethods.delete(method)

                try {
                    return await call()
                } catch (error) {
                    if (rejectedMethods.has(method)) continue
                    if (method === 'authorizeListUpdates') throw error

                    // Retain the invalidation without spinning on transport failures.
                    await this.waitForListCall(method)
                }
            }

            return null
        },
    })

    return {
        ...transport,

        init() {
            const changed = () => {
                revision++
                this.resume()
            }

            for (const event of [
                'focusin',
                'focusout',
                'input',
                'change',
                'pointerdown',
            ]) {
                this.$wire.$el.addEventListener(event, changed, {
                    signal: listeners.signal,
                })
            }

            for (const property of [
                'mountedActions',
                'tableDeferredFilters',
                'tableFilters',
                'isTableReordering',
            ]) {
                removeWatchers.push(this.$watch(`$wire.${property}`, changed))
            }

            this.$nextTick(() => {
                if (!isActive(this)) return

                const table = this.table()

                if (table) {
                    for (const property of [
                        'selectedRecords',
                        'isTrackingDeselectedRecords',
                        'areFiltersOpen',
                    ]) {
                        removeWatchers.push(
                            Alpine.watch(
                                () => [table[property]?.size, table[property]],
                                changed,
                            ),
                        )
                    }
                }

                for (const element of this.$wire.$el.querySelectorAll(
                    '.fi-ta-filters-dropdown, .fi-modal',
                )) {
                    removeWatchers.push(
                        Alpine.watch(
                            () => Alpine.$data(element).isOpen,
                            changed,
                        ),
                    )
                }
            })

            removeMessageInterceptor = this.$wire.$interceptMessage(
                ({ message, onSuccess, onFinish }) => {
                    if (message.component.id !== this.$wire.$id) return

                    const actions = Array.from(message.actions)
                    const isBackground =
                        actions.length > 0 &&
                        !Object.keys(message.updates ?? {}).length &&
                        actions.every((action) => methods.includes(action.name))

                    if (!isBackground) {
                        userRequests++
                        revision++
                        onFinish(() => {
                            userRequests--
                            this.resume()
                        })
                        return
                    }

                    const dispatchedRevision = revision

                    // Signing responses also merge snapshots. Reject before either can erase newer input.
                    onSuccess(() => {
                        if (
                            !isActive(this) ||
                            dispatchedRevision !== revision ||
                            this.busy()
                        ) {
                            actions.forEach((action) =>
                                rejectedMethods.add(action.name),
                            )
                            message.cancel()
                        }
                    })
                },
            )

            transport.init.call(this)
        },

        table() {
            const element = Array.from(
                this.$el.querySelectorAll('.fi-ta'),
            ).find(
                (element) => element.closest('[wire\\:id]') === this.$wire.$el,
            )

            return element ? Alpine.$data(element) : null
        },

        busy() {
            const table = this.table()
            const focused = document.activeElement
            const isEditing =
                this.$wire.$el.contains(focused) &&
                focused?.matches(
                    'input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]), textarea, select, [contenteditable="true"], [role="combobox"]',
                )
            const isPopupOpen = Array.from(
                this.$wire.$el.querySelectorAll(
                    '.fi-ta-filters-dropdown, .fi-modal',
                ),
            ).some((element) => Alpine.$data(element).isOpen)

            return Boolean(
                userRequests ||
                isEditing ||
                isPopupOpen ||
                this.$wire.mountedActions?.length ||
                this.$wire.isTableReordering ||
                table?.selectedRecords?.size ||
                table?.isTrackingDeselectedRecords ||
                table?.areFiltersOpen ||
                (hasDeferredFilters &&
                    JSON.stringify(this.$wire.tableFilters ?? {}) !==
                        JSON.stringify(this.$wire.tableDeferredFilters ?? {})),
            )
        },

        receive(event) {
            // A call waiting to read already covers every invalidation received before it starts.
            const isRefreshWaiting = Array.from(waitingCalls).some(
                (call) => call.method === 'refreshList',
            )
            this.resume()
            if (!isRefreshWaiting) return transport.receive.call(this, event)
        },

        async waitForListCall(method) {
            let waitingCall
            await new Promise((resolve) => {
                waitingCall = { method, resolve }
                waitingCalls.add(waitingCall)
            })
            waitingCalls.delete(waitingCall)
        },

        resume() {
            if (isDestroyed) return

            this.$nextTick(() => {
                if (!isActive(this) || this.busy()) return

                waitingCalls.forEach((call) => call.resolve())
            })
        },

        destroy() {
            isDestroyed = true
            waitingCalls.forEach((call) => call.resolve())
            waitingCalls.clear()
            listeners.abort()
            removeWatchers.forEach((remove) => remove?.())
            removeMessageInterceptor?.()
            transport.destroy.call(this)
        },
    }
}
