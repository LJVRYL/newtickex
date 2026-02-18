define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var AccessRecord = function(data) {
        'use strict';
        var self = this;

        self.regStatus = ko.observable(1);
        self.saveInHome = ko.observable(),
        self.removePrevious = ko.observable(),
        self.includeRegAccess = ko.observable(),

        mediator.installTo(self);
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);

        this.save = function() {
            var theData = { "params": {
                "saveInHome": self.saveInHome(),
                "removePrevious": self.removePrevious(),
                "includeRegAccess": self.includeRegAccess()
            }};

            self.getStatisticsVM().inprocess(1);
            $.postJSON("/hosting/tools/statistics/accessrecords/configure", theData, function(data) {
                self.getStatisticsVM().getAccessRecordsConfig();
            }).fail(function(data) {
            }).always(function(data) {
                self.getStatisticsVM().inprocess(0);
            });
        };

    };

    AccessRecord.prototype.hasErrors = function() {
        return ! (this.regStatus() === 1 || this.regStatus() === 6);
    };

    AccessRecord.prototype.getStatisticsVM = function() {
        return window.FerozoHosting.statisticsVM();
    };

    AccessRecord.prototype.remove = function() {

    };

    return AccessRecord;
});