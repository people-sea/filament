import eventListener from '../../../../support/resources/js/utilities/event-listener.js'

export default ({ channel, echoConfiguration }) => {
    let owner
    let listener
    let interaction = 0
    let userRequests = 0
    let nextRefresh = 0
    let pending = false
    let refreshing = false
    let timer
    let removeInterceptor
    const lifetime = new AbortController()
    const authorizationMethod = 'authorizeListUpdates'
    const refreshMethod = 'refreshList'

    const paused = () => {
        const wire = owner.$wire
        const root = wire.$el
        const table = Array.from(root.querySelectorAll('.fi-ta')).find(
            (element) => element.closest('[wire\\:id]') === root,
        )
        const state = table && window.Alpine.$data(table)
        const filters = table?.querySelector('.fi-ta-filters-dropdown')

        return (
            document.hidden ||
            userRequests > 0 ||
            !table ||
            wire.isTableReordering ||
            wire.mountedActions?.length ||
            state?.isTrackingDeselectedRecords ||
            state?.selectedRecords?.size ||
            state?.areFiltersOpen ||
            (filters && window.Alpine.$data(filters).isOpen) ||
            document.querySelector('.fi-modal-open, dialog[open]') ||
            (root.contains(document.activeElement) &&
                document.activeElement.matches(
                    'input:not([type="checkbox"]):not([type="radio"]), textarea, select, [contenteditable="true"]',
                )) ||
            (wire.tableDeferredFilters != null &&
                JSON.stringify(wire.tableDeferredFilters) !==
                    JSON.stringify(wire.tableFilters))
        )
    }

    const flush = async () => {
        clearTimeout(timer)
        if (!owner?.$el.isConnected || refreshing || (listener && !pending))
            return

        // Wait before entering the component's shared queue so modals can refresh.
        const cooldown = listener ? nextRefresh - Date.now() : 0
        if (paused() || cooldown > 0) {
            if (!document.hidden)
                timer = setTimeout(flush, Math.max(200, cooldown))
            return
        }

        pending = false
        if (!listener) {
            listener = eventListener({
                channel,
                echoConfiguration,
                channelType: 'private',
                eventName: '.filament.list-changed',
                parameters: [channel],
                authorizationMethod,
                refreshMethod,
                refreshOnSubscription: true,
            })
            listener.init.call(owner)
            return
        }

        refreshing = true
        try {
            // The signal is empty. Compare with the page the user is seeing now.
            await listener.receive.call(
                owner,
                owner.$el.dataset.pageFingerprint,
            )
        } finally {
            refreshing = false
            nextRefresh = Date.now() + 1000
            flush()
        }
    }

    return {
        receive() {
            if (pending) return
            pending = true
            flush()
        },
        init() {
            owner = this
            const interacted = () => {
                interaction++
                queueMicrotask(flush)
            }
            const options = { capture: true, signal: lifetime.signal }
            for (const event of [
                'input',
                'change',
                'focusin',
                'focusout',
                'click',
            ]) {
                this.$wire.$el.addEventListener(event, interacted, options)
            }
            document.addEventListener('visibilitychange', interacted, options)

            removeInterceptor = this.$wire.$interceptMessage(
                ({ message, onSend, onSuccess, onFinish, cancel }) => {
                    const actions = Array.from(message.actions)
                    const listOnly =
                        actions.length &&
                        actions.every(
                            ({ name, params }) =>
                                [refreshMethod, authorizationMethod].includes(
                                    name,
                                ) && params[0] === channel,
                        )
                    if (!listOnly) {
                        interaction++
                        userRequests++
                        onFinish(() => {
                            userRequests--
                            queueMicrotask(flush)
                        })
                        return
                    }

                    const currentListener = listener
                    const revision = interaction
                    const discardIfStale = () => {
                        if (
                            !owner?.$el.isConnected ||
                            !listener ||
                            listener !== currentListener
                        )
                            return cancel()
                        if (revision === interaction && !paused()) return

                        cancel()
                        if (
                            actions.some(
                                ({ name }) => name === authorizationMethod,
                            )
                        ) {
                            // Echo needs a fresh subscription after a discarded signature.
                            listener.destroy()
                            listener = null
                        }
                        pending = true
                        flush()
                    }

                    // Wait for Livewire to register the message before cancelling it.
                    onSend(discardIfStale)
                    // Even a renderless signing response merges a snapshot.
                    onSuccess(discardIfStale)
                },
            )
            flush()
        },
        destroy() {
            owner = null
            clearTimeout(timer)
            listener?.destroy()
            removeInterceptor()
            lifetime.abort()
        },
    }
}
