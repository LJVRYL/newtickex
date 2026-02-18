define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    var Task = function(data) {

        var self = this;
        self.status = ko.observable();
        self.prevStatus = ko.observable();
        self.errors = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Task.prototype.throwExecutedNotification = function() {
        var self = this;
        //console.group('command', self.id());
        //console.info('task.prevStatus', self.prevStatus());
        //console.info('task.status', self.status());
        //console.groupEnd();

        if (typeof self.prevStatus() !== 'undefined' && self.status() !== self.prevStatus()) {
            if (self.status() === 200) {
                Notifications.success(
                    self.commandTemplate.name() + ': ' +
                    self.displayValue() + "\n" +
                    self.successMsg()
                );
            }
            if (self.status() === 300 || self.status() === 400 || self.status() === 500) {
                FerozoHosting.appendError(
                    self.commandTemplate.name(),
                    self.displayValue(),
                    self.errors()
                );
            }
        };
        self.prevStatus(self.status());
    };
    return Task;
});