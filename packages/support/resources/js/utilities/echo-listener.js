// Temporary until the shared `event-listener.js` utility is available.
// Verify its authorization and pre-snapshot cancellation hooks, then migrate
// `record-page.js` and `list-page.js` and remove this helper. Keep each consumer's
// refresh policy, including list interaction guards and catch-up, in that consumer.
export default ({
    component,
    channel,
    eventName,
    echoConfiguration,
    methods,
    authorize,
    receive,
    onMessage,
}) => {
    let echo
    let isDestroyed = false

    const isActive = () => !isDestroyed && component.$el.isConnected
    const isBackgroundOnly = (message) => {
        const actions = Array.from(message.actions)

        return (
            actions.length > 0 &&
            !Object.keys(message.updates ?? {}).length &&
            actions.every((action) => methods.includes(action.name))
        )
    }

    const removeMessageInterceptor = component.$wire.$interceptMessage(
        (interception) => {
            const { message, onSuccess } = interception

            if (message.component.id !== component.$wire.$id) return

            const isBackground = isBackgroundOnly(message)

            if (isBackground) {
                onSuccess(() => {
                    if (!isActive()) message.cancel()
                })
            }

            onMessage?.({ ...interception, isBackground })
        },
    )

    const removeRequestInterceptor = component.$wire.$interceptRequest(
        ({ request, onError }) => {
            const messages = Array.from(request.messages)

            if (messages.length && messages.every(isBackgroundOnly)) {
                onError(({ preventDefault }) => preventDefault())
            }
        },
    )

    const receiveEvent = (event) => {
        if (isActive()) receive(event)
    }

    const registerEchoListener = () => {
        if (echo || !isActive()) return

        echo = new window.EchoFactory({
            ...echoConfiguration,
            withoutInterceptors: true,
            channelAuthorization: {
                customHandler: ({ socketId, channelName }, callback) => {
                    if (!isActive()) return

                    if (channelName !== `private-${channel}`) {
                        callback(new Error('The realtime channel has changed.'))
                        return
                    }

                    authorize(socketId, (...parameters) => {
                        if (isActive()) callback(...parameters)
                    })
                },
            },
        })

        echo.private(channel)
            .listen(eventName, receiveEvent)
            .subscribed(() => receiveEvent())
    }

    window.addEventListener('EchoLoaded', registerEchoListener)
    if (window.EchoFactory) registerEchoListener()

    return {
        isActive,

        destroy() {
            isDestroyed = true
            window.removeEventListener('EchoLoaded', registerEchoListener)
            removeMessageInterceptor?.()
            removeRequestInterceptor?.()
            echo?.disconnect()
        },
    }
}
