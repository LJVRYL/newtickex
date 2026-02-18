define(['knockout', 'hosting/entities/record', 'ko.mapping'], function(ko, Record, mapping) {
    var scheduledtasks = function(data) {
        mediator.installTo(this);
        
        this.entitiname = 'scheduledtasks';
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.regStatus = ko.observable(2);
        this.command = ko.observable({});
        this.id = ko.observable('');
        this.minutes = ko.observable('');
        this.hours = ko.observable('');
        this.days = ko.observable('');
        this.months = ko.observable('');
        this.dow = ko.observable('');
        this.notifyEmail = ko.observable(false);
        this.scheduleCommand = ko.observable('');
        this.contactEmail = ko.observable('');
        var pid = window.setInterval(function() {
            this.contactEmail(FerozoHosting.profileVM().user().Contact());
            typeof pid !== 'undefined' && window.clearInterval(pid);
        }.bind(this), 1500);
        
        if (data) {
            data.notifyEmail = !data.preventEmail;
        }
        
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    scheduledtasks.prototype = new Record({});

    scheduledtasks.prototype.constructor = scheduledtasks;

    scheduledtasks.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { params: {
            id: self.id()
        }};

        self.getVM().inprocess(1);
        self.regStatus(4);
        $.postJSON('/hosting/tools/scheduledtasks/remove', theData, function(e) {
            mediator.publish('scheduledtasksDeleted');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            self.getVM().inprocess(0);
        });
    };

    scheduledtasks.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { params: {
            id: self.id(),
            minutes: self.minutes(),
            hours: self.hours(),
            days: self.days(),
            months: self.months(),
            dow: self.dow(),
            scheduleCommand: self.scheduleCommand(),
            preventEmail: !self.notifyEmail(),
            notifyEmail: self.notifyEmail(),
            contactEmail: self.contactEmail() || ''
        }};

        var opType = self.id() ? 'edit' : 'create';
        self.getVM().inprocess(1);
        ko.utils.clearObservableErrors.bind(self).apply();
        $.postJSON('/hosting/tools/scheduledtasks/' + opType, theData, function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            } else {
                mediator.publish('refreshScheduledTasksList');
                $('.modal').modal('hide');
            }
        }).always(function() {
            self.getVM().inprocess(0);
        });
    };

    scheduledtasks.prototype.getVM = function() {
        return FerozoHosting.scheduledtasksVM && FerozoHosting.scheduledtasksVM();
    };

    return scheduledtasks;
});