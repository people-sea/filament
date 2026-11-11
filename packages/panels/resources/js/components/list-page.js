export default ({ channel, echoConfiguration, hasDeferredFilters }) => {
    let echo
    let isDestroyed = false
    let isRefreshing = false
    let needsRefresh = false
    let isSigning = false
    let authorizationRequest
    let authorizationWasRejected = false
    let refreshWasRejected = false
    let revision = 0
    let userRequests = 0
    let registerEchoListener
    let removeMessageInterceptor
    let removeRequestInterceptor
    const removeWatchers = []
    const listeners = new AbortController()
    const methods = ['authorizeListUpdates', 'refreshList']

    const isBackgroundOnly = (message) => {
        const actions = Array.from(message.actions)

        return (
            actions.length > 0 &&
            !Object.keys(message.updates ?? {}).length &&
            actions.every((action) => methods.includes(action.name))
        )
    }

    return {
        init() {
            const owner = this.$wire.$id
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

            removeMessageInterceptor = this.$wire.$interceptMessage(
                ({ message, onSuccess, onFinish }) => {
                    if (message.component.id !== owner) return

                    if (!isBackgroundOnly(message)) {
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
            )

            removeRequestInterceptor = this.$wire.$interceptRequest(
                ({ request, onError }) => {
                    const messages = Array.from(request.messages)

                    if (messages.length && messages.every(isBackgroundOnly)) {
                        onError(({ preventDefault }) => preventDefault())
                    }
                },
            )

            registerEchoListener = () => {
                if (echo || isDestroyed) return

                echo = new window.EchoFactory({
                    ...echoConfiguration,
                    withoutInterceptors: true,
                    channelAuthorization: {
                        customHandler: (
                            { socketId, channelName },
                            callback,
                        ) => {
                            if (channelName !== `private-${channel}`) {
                                callback(
                                    new Error('The list audience has changed.'),
                                )
                                return
                            }

                            authorizationRequest = { socketId, callback }
                            this.resume()
                        },
                    },
                })

                echo.private(channel)
                    .listen('.filament.model-list-changed', () =>
                        this.invalidate(),
                    )
                    .subscribed(() => this.invalidate())
            }

            window.addEventListener('EchoLoaded', registerEchoListener)
            if (window.EchoFactory) registerEchoListener()
        },

        table() {
            const element = this.$el.querySelector('.fi-ta')

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

                if (refreshWasRejected) this.resume()
            }
        },

        destroy() {
            isDestroyed = true
            authorizationRequest = null
            listeners.abort()
            removeMessageInterceptor?.()
            removeRequestInterceptor?.()
            removeWatchers.forEach((remove) => remove?.())
            window.removeEventListener('EchoLoaded', registerEchoListener)
            echo?.disconnect()
        },
    }
}
