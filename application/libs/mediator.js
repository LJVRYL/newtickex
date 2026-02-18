define(['knockout'], function (ko) {

    return mediator = (function () {
        var subscribe = function (channel, fn) {
            if (! mediator.channels[channel]) {
                mediator.channels[channel] = [];
            }
            var group = mediator.channels[channel];
            var ignore = false;
            for (var i = 0, l = group.length; i < l; i++) {
                var subscription = group[i];
                //subscription.callback.call(subscription.context, args);
                if (fn.toString().trim() == subscription.callback.toString().trim()) {
                    if (this.constructor.name == subscription.context.constructor.name) {
                        ignore = true;
                    }
                    subscription.context = this;
                    subscription.callback = fn;
                }
            }
            if (ignore) {
                return this;
            }
            mediator.channels[channel].push({
                context: this,
                callback: fn
            });
            return this;
        };

        var publish = function (channel) {
            if (! mediator.channels[channel]) {
                return false;
            }
            var args = Array.prototype.slice.call(arguments, 1);
            for (var i = 0, l = mediator.channels[channel].length; i < l; i++) {
                var subscription = mediator.channels[channel][i];
                if (subscription && subscription.callback && subscription.callback != undefined) {
                    subscription.callback.apply(subscription.context, args);
                }
            }
            return this;
        };

        return {
            channels: {},
            publish: publish,
            subscribe: subscribe,
            installTo: function (obj) {
                obj.subscribe = subscribe;
                obj.publish = publish;
            }
        };
    }());
});