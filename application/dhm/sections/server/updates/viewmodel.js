define(['knockout'], function(ko) {
    var updatesVM = function() {
        'use strict';

        this.inprocess = ko.observable(0);
        this.updateDone = ko.observable(0);
        this.updateFlag = ko.observable();
    };

    updatesVM.prototype.init = function() {
        var self = this;
        self.checkUpFz();
        self.checklastfzupdate();
        FerozoDhm.checkFzUpdate();
    };

    updatesVM.prototype.checkUpFz = function() {
        $.postJSON("/dhm/checkupdatefzpanelinprogress", function(data) { 
            FerozoDhm.updateInProgress(data.result);
        });
    }

    updatesVM.prototype.updateFz3 = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/updatefzversion", function(data) { 
            self.checkUpFz();
            self.updateDone(data.result);  
        }).always(function(data) {
            self.inprocess(0);
            self.init();
        });
    };


    updatesVM.prototype.checklastfzupdate = function () {
        var self = this;
        $.postJSON("/dhm/lastfzupdate", function(data) { 
            if(data.result){
                self.updateFlag(1);
            }else{
                self.updateFlag(0);
            }
        });
    }
    return updatesVM;
});