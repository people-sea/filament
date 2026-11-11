import echoListener from '../../../../support/resources/js/utilities/echo-listener.js'

export default ({ channel, echoConfiguration }) => {
    let listener
    let isRefreshing = false
    let needsRefresh = false

    return {
        init() {
            listener = echoListener({
                component: this,
                channel,
                eventName: '.filament.model-changed',
                echoConfiguration,
                methods: ['authorizeRecordUpdates', 'refreshRecordPage'],
                authorize: async (socketId, callback) => {
                    let authorization

                    try {
                        authorization =
                            await this.$wire.authorizeRecordUpdates(socketId)
                    } catch (error) {
                        callback(error)
                        return
                    }

                    callback(
                        authorization
                            ? null
                            : new Error('The record is no longer available.'),
                        authorization,
                    )
                },
                receive: () => this.refresh(),
            })
        },

        async refresh() {
            needsRefresh = true

            if (isRefreshing || !listener.isActive()) {
                return
            }

            isRefreshing = true

            try {
                while (needsRefresh && listener.isActive()) {
                    needsRefresh = false

                    try {
                        const title = await this.$wire.refreshRecordPage()

                        if (title === null || !listener.isActive()) {
                            return
                        }

                        document.title = title
                    } catch {
                        // Keep the current page on failure, but still process
                        // any notification received during the failed request.
                    }
                }
            } finally {
                isRefreshing = false
            }
        },

        destroy() {
            listener.destroy()
        },
    }
}
