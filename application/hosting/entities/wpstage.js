define(['knockout', 'ko.mapping', 'subdomain', 'input'], function(ko, mapping, Subdomain, Input) {

    var Wpstage = function(data) {

        var self = this;
        ko.mapping = mapping;

        this.id = ko.observable();
        this.subdomain = ko.observable(null);
        this.folder = ko.observable('');
        this.created = ko.observable('');
        this.origen = ko.observable('');
        this.published = ko.observable('');
        this.status = ko.observable('');
        this.pathorigin = ko.observable(null);
        this.domainName = ko.observable('');
        this.availableSpace = ko.observable(0);
        this.availableSubdomain = ko.observable(null);
        this.domainSearch = ko.observable(false);

        this.email = new Input({
            'content': ''
        });

        this.pathorigin.subscribe(function () {
            if(self.pathorigin()) {
                var aux = self.pathorigin().split('@');
                self.origen(aux[0]);
                self.domainName('.'+aux[1].split('//')[1]);
                var theData = { "params": {
                    "pathorigin": self.origen()
                }};
                $.postJSON('/hosting/webapp/checkstagingspace', theData, function(data) {
                    FerozoHosting.wordpressVM().renderChart(data.result.sizeorigin, data.result.availablespace);
                    self.availableSpace(data.result.stagingavailable ? 1 : null);
                }).fail(function() {
                }).always(function() {
                });
            }
        });

        this.subdomain.subscribe(function () {
            $.postJSON("/hosting/domain/listsubdomains", function(data) {
                self.availableSubdomain(true);
                self.availableSubdomain(true);
                if (data.result) {
                    $.each(data.result, function(index, val) {
                        if (this.name == self.subdomain() && this.domain.domain == self.domainName().slice(1)) {
                            self.availableSubdomain(false);
                        }
                    });
                    self.domainSearch(true);
                }
            }).always(function() {
            });
        });

        ko.mapping.fromJS(data, {}, this);

    };

    Wpstage.prototype.getwordpressVM = function() {
        return FerozoHosting.wordpressVM() && FerozoHosting.wordpressVM();
    };

    Wpstage.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idStaging": self.id()
        }};
        self.regStatus(4);
        self.getwordpressVM().inprocess(1);
        $.postJSON('/hosting/webapp/removestaging', theData, function(data) {
        }).fail(function() {
        }).always(function(data) {
            self.getwordpressVM().inprocess(0);
            data.error && self.regStatus(1);
            self.getwordpressVM().listWpStage();
            window.location.reload();
        });
    };

    Wpstage.prototype.publish = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idStaging": self.id(),
            "email": self.email.content()
        }};
        self.regStatus(3);
        self.getwordpressVM().inprocess(1);
        $.postJSON('/hosting/webapp/publishstaging', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
            } else {
                $('.modal').modal('hide');
            }
        }).fail(function() {
        }).always(function(data) {
            self.getwordpressVM().inprocess(0);
            data.error && self.regStatus(1);
            self.getwordpressVM().listWpStage();
        });
    };

    Wpstage.prototype.setDomain = function() {
        'use strict';
        var self = this;
        var aux = self.pathorigin().split('@');
    };

    Wpstage.prototype.createStage = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "pathorigin": self.origen(),
            "subdomain": self.subdomain(),
            "email": self.email.content()
        }};
        self.getwordpressVM().inprocess(1);
        
        $.postJSON('/hosting/webapp/createstaging', theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                });
            } else {
                $('.modal').modal('hide');
            }
        }).fail(function() {
        }).always(function(data) {
            self.getwordpressVM().inprocess(0);
            FerozoHosting.wordpressVM().listWpStage();
            FerozoHosting.wordpressVM().resetStep();
            data.error;
        });
    };

    return Wpstage;
});