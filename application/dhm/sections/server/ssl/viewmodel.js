define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var sslVM = function() {
        ko.mapping = mapping;

        this.inprocess = ko.observable();
        this.savedConfig = ko.observable(false);
        this.checkedServices = ko.observable();
    

    };

    sslVM.prototype.init = function() {
        this.getSslData();
    };

    sslVM.prototype.getSslData = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/dhm/setup/checksslcertinstallation", function(data) { 
            if (data.result.data.started && data.result.data.cert_type == "letsencrypt") {
                self.checkedServices(true);
            } else {
                self.checkedServices(false);
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    sslVM.prototype.saveConfig = function() {
        var self = this;

        if(this.checkedServices()){
            var theData = {"params": {
                "enabled":1
            }};
        } else{
            var theData = {"params": {
                "enabled":0
            }};
        }

        self.inprocess(1);
        $.postJSON("/dhm/setup/installsslcertdhm", theData, function(data) { 
            self.savedConfig(data.result);
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    sslVM.prototype.resetFlag = function() {
        this.savedConfig(false);
    };


    return sslVM;
});