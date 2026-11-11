import echoListener from '../../../../support/resources/js/utilities/echo-listener.js'

export default ({ channel, echoConfiguration, hasDeferredFilters }) => {
    let listener
    let isDestroyed = false
    let isRefreshing = false
    let needsRefresh = false
    let isSigning = false
    let authorizationRequest
    let authorizationWasRejected = false
    let refreshWasRejected = false
    let revision = 0
    let userRequests = 0
    const removeWatchers = []
    const listeners = new AbortController()

    return {
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
                if (isDestroyed || !this.$el.isConnected) return

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

            listener = echoListener({
                component: this,
                channel,
                eventName: '.filament.model-list-changed',
                echoConfiguration,
                methods: ['authorizeListUpdates', 'refreshList'],
                authorize: (socketId, callback) => {
                    authorizationRequest = { socketId, callback }
                    this.resume()
                },
                receive: () => this.invalidate(),
                onMessage: ({ message, isBackground, onSuccess, onFinish }) => {
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

                    // Even renderless signing responses merge a Livewire snapshot.
                    onSuccess(() => {
                        if (
                            isDestroyed ||
                            !this.$el.isConnected ||
                            dispatchedRevision !== revision ||
                            this.busy()
                        ) {
                            for (const action of message.actions) {
                                if (action.name === 'authorizeListUpdates')
                                    authorizationWasRejected = true
                                if (action.name === 'refreshList')
                                    refreshWasRejected = true
                            }

                            needsRefresh = true
                            message.cancel()
                        }
                    })
                },
            })
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

        invalidate() {
            needsRefresh = true
            this.resume()
        },

        resume() {
            if (isDestroyed) return

            this.$nextTick(() => this.drain())
        },

        async drain() {
            if (
                isDestroyed ||
                !this.$el.isConnected ||
                isRefreshing ||
                isSigning ||
                (!needsRefresh && !authorizationRequest) ||
                this.busy()
            )
                return

            if (authorizationRequest) {
                const request = authorizationRequest
                authorizationRequest = null
                authorizationWasRejected = false
                isSigning = true

                try {
                    const authorization = await this.$wire.authorizeListUpdates(
                        request.socketId,
                    )

                    if (!isDestroyed && this.$el.isConnected)
                        request.callback(null, authorization)
                } catch (error) {
                    if (!isDestroyed && authorizationWasRejected)
                        authorizationRequest ??= request
                    else if (!isDestroyed) request.callback(error)
                } finally {
                    isSigning = false
                }

                if (authorizationRequest) {
                    this.resume()
                    return
                }
            }

            isRefreshing = true

            try {
                while (
                    needsRefresh &&
                    !isDestroyed &&
                    this.$el.isConnected &&
                    !this.busy()
                ) {
                    needsRefresh = false
                    refreshWasRejected = false

                    try {
                        await this.$wire.refreshList()
                    } catch {
                        // Keep the dirty flag, but do not spin on transport/server failures.
                        needsRefresh = true
                        break
                    }
                }
            } finally {
                isRefreshing = false

                if (authorizationRequest || refreshWasRejected) this.resume()
            }
        },

        destroy() {
            isDestroyed = true
            authorizationRequest = null
            listeners.abort()
            removeWatchers.forEach((remove) => remove?.())
            listener.destroy()
        },
    }
}
