define(['knockout', 'task'], function(ko, Task) {

    var TaskVM = function() {
        var self = this;

        self.lastUpdate = ko.observable();
        self.data = ko.observableArray([]);
        self.commands = ko.observableArray([]);
        self.count =  ko.computed(function() {
            var TaskFiltered = ko.utils.arrayFilter(self.data(), function(task) {
                return task.status() === 0 || task.status() === 10 || task.status() === 100 || task.status() === 300;
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
        $.postJSON("/dhm/command/lastcommands", function(data) {
            var mapping = {
                create: function(options) {
                    if( options.data.commandTemplate.name == "Actualizar Panel Ferozo" || options.data.commandTemplate.name == "Update Ferozo Panel" || options.data.commandTemplate.name == "Atualizar Painel Ferozo"){
                        self.commands.push(options.data);
                    }
                    return new Task(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(data.result, mapping, self.data);
            self.throwNotifications();
            if (self.commands().length > 0){
                self.evalUpdates();
            }
            if (self.count() < FerozoDhm.tasksCount) {
                typeof callback === 'function' && callback(self);
            };
        });
    };

    TaskVM.prototype.evalUpdates = function() {
        var self = this;
        var n = self.commands().length;
        
        if(self.commands()[n-1].status==500){
            self.lastUpdate(1);
        } else{
            self.lastUpdate(0);
        }
    };


    TaskVM.prototype.throwNotifications = function() {
        var self = this;
        $.each(self.data(), function(i, task) {
            task.throwExecutedNotification();
        });
    };

    return TaskVM;
});