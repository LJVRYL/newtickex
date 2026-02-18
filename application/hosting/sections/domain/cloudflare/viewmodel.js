define(['knockout', 'domain', 'ko.mapping', 'input', 'fzPaginatorAjax', 'notifications'], function(ko, Domain, mapping, Input, fzPaginatorAjax, Notifications) {
    var cloudflareVM = function(data) {
        
        'use strict';
        var self = this;
        ko.mapping = mapping;
        self.domains = ko.observableArray([]);
        self.inprocess = ko.observable();
        self.errors = ko.observable();
        self.token = ko.observable();
        self.tokenCloud = new Input();
        self.permissions = ko.observable(null);
        self.associatedToken = ko.observable(false);
        self.loading = ko.observable(0);
        self.checkcss = ko.observable(false);
        self.checkjs = ko.observable(false);
        self.checkhtml = ko.observable(false);
        self.currentZone = ko.observable('');
        self.zonesResult = ko.observable('');

        var mappingRules = {
            'tokenCloud': {
                create: function(options) {
                    return new Input({
                        'content': options.data,
                    });
                }
            }   
        };

        ko.mapping.fromJS(data, mappingRules, this);

    };

    cloudflareVM.prototype.init = function() {
        var self = this;
        self.loading(0);
        self.inprocess(0);
        $.postJSON('/hosting/cloudflare/getcurrenttoken', function(data) { 
            if(data.result){
                self.associatedToken(true);
                var tokenaux = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
                var last4 = data.result.slice(data.result.length - 4);
                self.token(tokenaux.concat(last4));
                $.postJSON('/hosting/cloudflare/getdomainsinfo', function(data) { 
                    if(data.result){
                        self.permissions(data.result.user_token_permissions);
                        self.domains(data.result);
                        self.fillDomains();
                        self.inprocess(1);
                        if (self.permissions() != null){
                            $("#tokenAlert").modal('show');
                        }
                    } 
                }).always(function(data) {
                    if(self.inprocess() != 1)
                        self.inprocess(2);
                });;
            }
        });
    };

    cloudflareVM.prototype.fillDomains = function() {
        var self = this;
        $.each(self.domains(), function() {
            this.modStatus = ko.observable(false); 
        });
    }
    
    cloudflareVM.prototype.clearErrors = function() {
        var self = this;
        self.tokenCloud.clearErrors();
    }


    cloudflareVM.prototype.associateCloudToken = function() {
        var self = this;
        self.inprocess(0);
        self.clearErrors();
        var theData = 
        { "params": {
            "token": self.tokenCloud.content(),
        }};
        
        self.inprocess(1);
        $.postJSON('/hosting/cloudflare/verifytoken', theData, function(data) {

            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    self.tokenCloud.error(this.errorDesc);
                });
            } else {
                if(data.result.status==200)
                    {   
                        self.associatedToken(true);
                        self.init();
                    }

                else{
                    $.each(data.result.errors, function() {
                        self.tokenCloud.error(this.message);
                    });
                }  
            };
        }).always(function(data) {
            self.inprocess(0);
        });
        
    };

    cloudflareVM.prototype.removeCloudToken = function() {
        var self = this;
        $("#alertUnlinkCf").modal('show');
        // self.inprocess(0);
        // $.postJSON('/hosting/cloudflare/removetoken', function(data) { 
        //     if(data.result){
        //         self.associatedToken(false);
        //     }    
        // }).always(function(data) {
        //     self.inprocess(0);
        //     $('.modal').modal('hide');
        //     self.init();
        // });
    };

    cloudflareVM.prototype.regenerateCloudToken = function() {
        var self = this;
        self.inprocess(0);
        $.postJSON('/hosting/cloudflare/setfztokenperms', function(data) { 
            if(data.result){
                self.associatedToken(true);
            }    
        }).always(function(data) {
            self.inprocess(0);
            $('.modal').modal('hide');
            self.init();
        });
    };

    cloudflareVM.prototype.deactivateCloudflare = function() {
        var self = this;
        FerozoHosting.cloudflareVM().loading(1);
        var theData2 = 
            { "params": {
                "idZone": FerozoHosting.cloudflareVM().zonesResult[0].id,
            }};
        $.postJSON('/hosting/cloudflare/deletezone', theData2, function(data) {
            FerozoHosting.cloudflareVM().init();
            FerozoHosting.cloudflareVM().loading(0);
            $('.modal').modal('hide');
        });
    }

    cloudflareVM.prototype.unlinkCloudflare = function() {
        var self = this;
        FerozoHosting.cloudflareVM().loading(1);
        $.postJSON('/hosting/cloudflare/removetoken', function(data) { 
            if(data.result){
                FerozoHosting.cloudflareVM().associatedToken(false);
            }    
        }).always(function(data) {
            FerozoHosting.cloudflareVM().loading(0);
            $('.modal').modal('hide');
            FerozoHosting.cloudflareVM().init();
        });
    }

    cloudflareVM.prototype.dismissModal = function() {
        FerozoHosting.cloudflareVM().init();
    }

    cloudflareVM.prototype.activateCloudflare = function() {
        var self = this;
        var theData = 
        { "params": {
            "domain": this.domain,
        }};

        FerozoHosting.cloudflareVM().loading(1);
        this.modStatus(true);

        $.postJSON('/hosting/cloudflare/listzones',theData, function(data) {
            FerozoHosting.cloudflareVM().zonesResult = data.result;
            //Tengo zonaDNS CloudFlare - desactivo
            if(FerozoHosting.cloudflareVM().zonesResult.length > 0) {
                $("#alertCf").modal('show');
            }
            //No tengo zonaDNS CloudFlare - solicito una
            else {  
                $.postJSON('/hosting/cloudflare/createzone', theData, function(data) { 
                }).always(function(data) {
                    FerozoHosting.cloudflareVM().init();
                });
            }
        }).always(function(data) {
            FerozoHosting.cloudflareVM().loading(0);
        });
    }

    cloudflareVM.prototype.changeDevButton = function() {
        var self = this;
        FerozoHosting.cloudflareVM().loading(1);
        this.modStatus(true);
        var theData = 
        { "params": {
            "idZone": this.id,
        }};
        $.postJSON('/hosting/cloudflare/changedevmode', theData, function(data) { 
            FerozoHosting.cloudflareVM().init();
        }).always(function(data) {
            FerozoHosting.cloudflareVM().loading(0);
        });
    };

    cloudflareVM.prototype.changeHttpsButton = function() {
        var self = this;
        FerozoHosting.cloudflareVM().loading(1);
        this.modStatus(true);
        var theData = 
        { "params": {
            "idZone": this.id,
        }};
        $.postJSON('/hosting/cloudflare/changeusehttps', theData, function(data) { 
            FerozoHosting.cloudflareVM().init();
        }).always(function(data) {
            FerozoHosting.cloudflareVM().loading(0);
        });
    };

    cloudflareVM.prototype.changeMinAction = function() {
        var self = this;
        FerozoHosting.cloudflareVM().loading(1);
        var theData = 
        { "params": {
            "idZone": self.currentZone(),
            "css": self.checkcss() ? 'on' : 'off',
            "html": self.checkhtml() ? 'on' : 'off',
            "js": self.checkjs() ? 'on' : 'off'
        }};
        $.postJSON('/hosting/cloudflare/changeminify', theData, function(data) { 
            FerozoHosting.cloudflareVM().init();
            FerozoHosting.cloudflareVM().loading(0);
            $('.modal').modal('hide');
        }).always(function(data) {
            FerozoHosting.cloudflareVM().loading(0);
        });
    };

    cloudflareVM.prototype.changeMinButton = function() {
        var self = this;
        FerozoHosting.cloudflareVM().resetChecks();
        FerozoHosting.cloudflareVM().currentZone(this.id);

        if (this.minify.css == 'on')
            FerozoHosting.cloudflareVM().checkcss(true);
        if (this.minify.js == 'on')
            FerozoHosting.cloudflareVM().checkjs(true);
        if (this.minify.html == 'on')
            FerozoHosting.cloudflareVM().checkhtml(true);

        $("#minifier").modal('show');
    };

    cloudflareVM.prototype.resetChecks = function() {
        var self = this;
        self.checkcss(false);
        self.checkjs(false);
        self.checkhtml(false);
    }

    cloudflareVM.prototype.changeBrotliButton = function() {
        var self = this;
        FerozoHosting.cloudflareVM().loading(1);
        this.modStatus(true);
        var theData = 
        { "params": {
            "idZone": this.id,
        }};
        $.postJSON('/hosting/cloudflare/changebrotli', theData, function(data) { 
            FerozoHosting.cloudflareVM().init();
        }).always(function(data) {
            FerozoHosting.cloudflareVM().loading(0);
        });
    };

    cloudflareVM.prototype.clearCacheButton = function() {
        var self = this;
        var theData = 
        { "params": {
            "idZone": this.id,
        }};
        $.postJSON('/hosting/cloudflare/purgecache', theData, function(data) {  
            Notifications.success(data.result.successMsg);
            FerozoHosting.cloudflareVM().init();
        });
    };

    cloudflareVM.prototype.dnsFerozoButton = function() {
        var self = this;
        FerozoHosting.cloudflareVM().loading(1);
        this.modStatus(true);
        var theData = 
        { "params": {
            "domain": this.domain,
        }};
        $.postJSON('/hosting/cloudflare/parkcfdomain', theData, function(data) { 
            // FerozoHosting.cloudflareVM().init();
        }).always(function(data) {
            FerozoHosting.cloudflareVM().loading(0);
        });
    };

    return cloudflareVM;
});