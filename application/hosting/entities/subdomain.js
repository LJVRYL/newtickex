define(['knockout', 'ko.mapping', 'hosting/entities/record', 'input', 'domain'], function(ko, mapping, Record, Input, Domain) {
    /* ------------ SUBDOMAIN -----------------*/
    var SubDomain = function(data) {
        var self = this;

        mediator.installTo(self);
        ko.mapping = mapping;
        self.rowstatus = ko.observable('0');//0=nada;1=delete
        self.name = new Input();
        self.id = ko.observable();
        self.type = ko.observable(); self.redirectionType = self.type;
        self.redirection =  new Input();
        self.sslCert = ko.observable();
        self.name.content.subscribe(function() {
            if (!self.id() && self.type() === 5) {
                self.redirection.content(self.name.content());
            }
        });
        self.type.subscribe(function() {
            if (!self.id() && self.type() === 5) {
                self.redirection.content(self.name.content());
            }
        });

        self.domain = new Domain();
        self.entitiname = 'domain/subdomain';
        var mappingRules = {
            'name': {
                create: function(options) {
                    return new Input({
                        "content": options.data
                    });
                }
            },
            'redirection': {
                create: function(options) {
                    return new Input({
                        "content": options.data
                    });
                }
            },
            'domain': {
                create: function(options) {
                    return new Domain(options.data);
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, self);
    };

    SubDomain.prototype = new Record({});

    SubDomain.prototype.constructor = SubDomain;

    SubDomain.prototype.remove = function() {
        'use strict';
        var self = this;
        self.regStatus(4);
        var theData = { "params": {
            "id": self.id()
        }};

        //FerozoHosting.subdomainsVM() && FerozoHosting.subdomainsVM().inprocess(1);
        $.postJSON('/hosting/domain/removesubdomain', theData, function(e) {
            mediator.publish('refreshSubDomainList');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            //FerozoHosting.subdomainsVM() && FerozoHosting.subdomainsVM().inprocess(0);
        });
    };

    SubDomain.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "iddomain": self.domain.id,
            'name': self.name.content(),
            'redirection': self.redirection.content(),
            'type': self.type()
        }};

        FerozoHosting.subdomainsVM() && FerozoHosting.subdomainsVM().inprocess(1);
        $.postJSON('/hosting/domain/createsubdomain', theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i].error == "function") {self[i].error("");}});
            if (response.error && response.error.data.inputException) {
                self.handleErrors(response);
                if (typeof FerozoHosting.subdomainsVM().inprocess != "undefined") {FerozoHosting.subdomainsVM().inprocess(0);}
            } else {
                mediator.publish('refreshSubDomainList');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.subdomainsVM() && FerozoHosting.subdomainsVM().inprocess(0);
        });
    };

    SubDomain.prototype.handleErrors = function(response) {
        var self = this;
        $.each(response.error.data.inputException, function() {
            if (self[this.field] && self[this.field].error) {
                //Esto anda si se usa la propiedad de la entity como Input()
                self[this.field].error(this.errorDesc);
            } else {
                throw new Error(this.errorDesc);
            }
        });
    };

    SubDomain.prototype.removeRedirection = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "type": self.type() && self.type().value
        }};

        self.regStatus(3);
        FerozoHosting.subdomainsVM() && FerozoHosting.subdomainsVM().inprocess(1);
        $.postJSON('/hosting/domain/unsetsubdomainredirection', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                self.handleErrors(response);
            } else {
                mediator.publish('refreshSubDomainList');
                $('.modal').modal('hide');
            }
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.subdomainsVM() && FerozoHosting.subdomainsVM().inprocess(0);
        });
    };

    SubDomain.prototype.setredirectionsubdomain = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "id": self.id(),
            "type": self.type(),
            "redirection": self.redirection.content()
        }};

        FerozoHosting.subdomainsVM() && FerozoHosting.subdomainsVM().inprocess(1);
        $.postJSON('/hosting/domain/setsubdomainredirection', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                self.handleErrors(response);
            } else {
                mediator.publish('refreshSubDomainList');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.subdomainsVM() && FerozoHosting.subdomainsVM().inprocess(0);
        });
    };

    SubDomain.prototype.isFzCom = function() {
        var self = this;
        if (((self.name.content()+'.'+self.domain.domain.content()).indexOf('.ferozo.com') > 0) || ((self.name.content()+'.'+self.domain.domain.content()).indexOf('.ferozo.net') > 0)) {
            return true;
        } else {
            return false;
        }        
    };

    return SubDomain;
});