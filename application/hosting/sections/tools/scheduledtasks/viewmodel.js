define(['knockout', 'scheduledtasks', 'ko.mapping'], function(ko, ScheduledTasks, mapping) {

    var scheduledtasksVM = function() {
        'use strict';
        mediator.installTo(this);
        ko.mapping = mapping;

        this.data = ko.observableArray();
        this.temp = ko.observable(new ScheduledTasks());
        this.inprocess = ko.observable(1);
        this.regStatus = ko.observable('');
        this.listScheduledTasks = ko.observableArray([]);
        this.minutes = ko.observable('');
        this.hours = ko.observable('');
        this.days = ko.observable('');
        this.months = ko.observable('');
        this.dow = ko.observable('');
        this.scheduleCommand = ko.observable('');
        this.preventEmail = ko.observable('');

        this._default = ko.observable('2');

        this.subscribe('refreshScheduledTasksList', this.list);
        this.subscribe('scheduledtasksDeleted', function() {
            'use strict';
            mediator.publish('refreshScheduledTasksList');
        });

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.scheduleCommand() == right.scheduleCommand() ? 0 : (left.scheduleCommand() < right.scheduleCommand() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.scheduleCommand() == right.scheduleCommand() ? 0 : (left.scheduleCommand() > right.scheduleCommand() ? -1 : 1);
                });
            }
        };

    };

    scheduledtasksVM.prototype.switchValue = function(entity, event) {
        $(event.target).parent().find('input').first().val(
            $(event.target).val()
        ).change();
    };

    scheduledtasksVM.prototype.switchValueExample = function(entity, event) {
        'use strict';
        var params = $(event.target).val().split(' ');
        this.temp().minutes(params[0]);
        this.temp().hours(params[1]);
        this.temp().days(params[2]);
        this.temp().months(params[3]);
        this.temp().dow(params[4]);
    };

    scheduledtasksVM.prototype.list = function() {
        var self = this;
        $.postJSON("/hosting/tools/scheduledtasks/list", function(data) {
            if (data.result) {
                self.data([]);
                $.each(data.result, function() {
                   if (typeof this._default === "undefined") {
                        this._default = false;
                    } else {
                        this.regStatus = 1;
                    }
                    self.data.push(new ScheduledTasks(this));
                });
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    scheduledtasksVM.prototype.init = function() {
        'use strict';
        mediator.publish('refreshScheduledTasksList');
    };

    scheduledtasksVM.prototype.openEdit = function(entity, event) {
        var cloned = ko.mapping.fromJS(ko.toJS(entity));
        this.temp(cloned);
        $('#modal-scheduled-task').modal('show');
    };

    scheduledtasksVM.prototype.openCreate = function(mode) {
        'use strict';
        this.temp(new ScheduledTasks());
//        FerozoHosting.scheduledtasksVM().temp().id=0;
//        FerozoHosting.scheduledtasksVM().temp().minutes("");
//        FerozoHosting.scheduledtasksVM().temp().hours("");
//        FerozoHosting.scheduledtasksVM().temp().months("");
//        FerozoHosting.scheduledtasksVM().temp().days("");
//        FerozoHosting.scheduledtasksVM().temp().scheduleCommand("");
//        FerozoHosting.scheduledtasksVM().temp().dow("");
        $('#modal-scheduled-task').modal('show');
    };

    scheduledtasksVM.prototype.getContactEmail = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Contact();
        } else {
            return "";
        }
    };

    return scheduledtasksVM;
});
//-------- / DomainVM