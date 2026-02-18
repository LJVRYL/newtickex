define(['knockout', 'task'], function(ko, Task) {

    var TaskVM = function() {
        var self = this;

        self.data = ko.observableArray([]);
        self.count =  ko.computed(function() {
            var TaskFiltered = ko.utils.arrayFilter(self.data(), function(task) {
                try {
                    var status = ko.utils.unwrapObservable(task.status);
                    return status === 0 || status === 10 || status === 100 || status === 300 || status === 800;
                } catch(error) {
                    return false;
                }
            });
            return TaskFiltered.length;
        }, self);

        mediator.installTo(self);
        self.subscribe('RefreshTasks', function() {
            self.init();
        });
    };

    TaskVM.prototype.init = function(callback) {
        var self = this;
        $.postJSON("/hosting/command/lastcommands", function(data) {
            var mapping = {
                create: function(options) {
                    return new Task(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(data.result, mapping, self.data);
            self.throwNotifications();
            if (self.count() < FerozoHosting.tasksCount) {
                typeof callback === 'function' && callback(self);
            };
        });
    };

    TaskVM.prototype.throwNotifications = function() {
        var self = this;
        $.each(self.data(), function(i, task) {
            task.throwExecutedNotification();
        });
    };

    return TaskVM;
});