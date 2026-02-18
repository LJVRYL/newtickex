define([], function () {

    var Notifications = {
        container: '#notifications',
        timeout: 5000,

        _hide: function(div, callback) {
            var clock;
            div.addClass('animated animate fadeOutRight');
            clock = window.setTimeout(function() {
                typeof(callback) === "function" && callback(div);
                div.remove();
                window.clearInterval(clock);
            }, 300);
        },

        _buildAlert: function(msg, className) {
            var self = this;
            var clock;
            var div = $('<div>');
            $(this.container).prepend(div);
            div.attr('class', 'alert alert-notification clearfix animate animated bounceIn ' + className);
            div.append(msg).on('click', function() {
                self._hide(div);
            });
            clock = window.setTimeout(function() {
                self._hide(div);
                window.clearInterval(clock);
            }, this.timeout);
        },

        success: function(msg) {
            msg = '<i class="icon icon-new fa-check-circle"></i> ' + msg;
            this._buildAlert(msg, 'alert-fz-success without-icon');
        },

        error: function(msg) {
            msg = '<i class="icon icon-new fa-exclamation-triangle"></i> ' + msg;
            this._buildAlert(msg, 'alert-fz-error');
        }
    };

    return Notifications;
});