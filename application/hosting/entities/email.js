define(['knockout', 'account', 'mediator', 'input', 'ko.mapping', 'antispamconfig'], function(ko, Account, Mediator, Input, mapping, AntispamConfig) {
    /* ------------ EMAIL -----------------*/
    var Email = function(data) {
        'use strict';
        $('.modal select').change(); //fix select domain/subdomain
        mediator.installTo(this);
        ko.mapping = mapping;
        var self = this;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.id = ko.observable('');
        this.regStatus = ko.observable();
        //this.domain = new Domain;
        this.domain = ko.observable();
        this.subdomain = ko.observable();
        this.type = ko.observable();
        this.subdomainDomain = ko.observable();
        this.account = new Account;
        this.active = ko.observable();
        this.usedQuota = ko.observable();
        this.isDefault = ko.observable();
        this.antiSpamConfig = ko.observable(new AntispamConfig());
        this.notifyConfig = ko.observable(false);

        this.conditionDomain = ko.observable('');
        this.conditionLength = ko.observable(false);
        this.conditionSymbols = ko.observable(false);
        this.conditionChars = ko.observable(false);
        this.conditionSingle = ko.observable(false);

        this.isWPAccount = ko.observable();

        this.scoreText = function() {
            var labels = {
                1: "Extremadamente restrictivo +",
                2: "Extremadamente restrictivo",
                3: "El mas restrictivo",
                4: "Muy restrictivo",
                5: "Valor recomendado",
                6: "Bastante permisivo",
                7: "Permisivo",
                8: "Muy permisivo",
                9: "El mas permisivo",
                10: "Extremadamente permisivo",
                11: "Extremadamente permisivo +",
                12: "Extremadamente permisivo ++"
            };
            return (labels[this.antiSpamConfig().score()] ?
                labels[this.antiSpamConfig().score()] :
                '') + ' ('+this.antiSpamConfig().score()+')';
        };

        this.scoreToMoveText = function() {
            var labels = {
                3: "El mas restrictivo",
                4: "Muy restrictivo",
                5: "Valor recomendado",
                6: "Bastante permisivo",
                7: "Permisivo",
                8: "Muy permisivo",
                9: "El mas permisivo"
            };
            return (labels[this.antiSpamConfig().scoreToMove()] ?
                labels[this.antiSpamConfig().scoreToMove()] :
                '') + ' ('+this.antiSpamConfig().scoreToMove()+')';
        };
        this.scoreToDeleteText = function() {
            var labels = {
                4: "El mas restrictivo",
                5: "Muy restrictivo",
                6: "Bastante restrictivo",
                7: "Poco restrictivo",
                8: "Valor recomendado",
                9: "Permisivo",
                10: "El mas permisivo"
            };
            return (labels[this.antiSpamConfig().scoreToDelete()] ?
                labels[this.antiSpamConfig().scoreToDelete()] :
                '') + ' ('+this.antiSpamConfig().scoreToDelete()+')';
        };
        //this.scoreToDeleteText = function() {
        //    var labels = {
        //        5: "El mas restrictivo",
        //        6: "Muy restrictivo",
        //        7: "Restrictivo",
        //        8: "Bastante restrictivo",
        //        9: "Poco restrictivo",
        //        10: "Valor recomendado",
        //        11: "Permisivo",
        //        12: "El mas permisivo"
        //    };
        //    return (labels[this.antiSpamConfig().scoreToDelete()] ?
        //        labels[this.antiSpamConfig().scoreToDelete()] :
        //        '') + ' ('+this.antiSpamConfig().scoreToDelete()+')';
        //};

        this.usernameSuffix = new Input({
            'content': ''
        });

        this.password = new Input({
            'content': ''
        });

        this.password.content.subscribe(function (newValue) {
            self.conditionDomain(newValue);
            self.conditionSingle(false);

            if(newValue.length >= 8)
                self.conditionLength(true);
            else
                self.conditionLength(false);
            
            if(/^[A-Za-z0-9@*/]*$/.test(newValue) && /[@*/]/.test(newValue))
                self.conditionSymbols(true);
            else
            self.conditionSymbols(false);

            if (/[A-Z]/.test(newValue) && /[a-z]/.test(newValue) && /\d/.test(newValue) && /^(?!.*(012|123|234|345|456|567|678|789)).*$/.test(newValue))
                self.conditionChars(true);
            else
                self.conditionChars(false);

            if(self.conditionChars() && self.conditionSymbols() && self.conditionLength()) {
                var theData = { "params": {
                    "password": self.conditionDomain()
                }};
                $.postJSON('/hosting/email/checksinglepass', theData, function() {
                }).always(function(data) {
                    self.conditionSingle(data.result);
                });
            }
        });

        this.quota = new Input({
            'content': ''
        });

        this.usernamePrefix = new Input({
            'content': ''
        });
        this.notifyConfigEmail = new Input({
            'content': self.getContactEmail()
        });        
 
        this.hasUnlimitedQuota = function() {
            return self.quota.content() == -1;
        };

        this.toggleUnlimitedQuota = function(entity, event) {
            var quotaValue = $('.quota-value');
            if (!$(event.target).is(':checked')) {
                quotaValue.removeAttr('disabled');
                self.quota.content('100');
            } else {
                quotaValue.attr('disabled', 'disabled');
                self.quota.content('-1');
            }
            quotaValue.change();
            return true;
        };

        this.canUseUnlimitedQuota = function() {
            return true;
//            if (FerozoHosting.profileVM() && FerozoHosting.profileVM().user()) {
//                return FerozoHosting.profileVM().user().AsignedSpaceEmail() == -1;
//            }
        };

        this.completeAccount = ko.computed(function() {
            return self.account && self.account.user();
        });

        this.completeAccountIdn = ko.computed(function() {
            if (self.account && self.account.useridn()) {
                return self.account && self.account.useridn();
            } else {
                return '';
            }
        });

        this.getInfocompleteAccountIdn = ko.computed(function() {
            if (self.account && self.account.useridn()) {
                return self.account && self.account.useridn();
            } else {
                return self.account && self.account.user();
            }
        });
        
        this.getInfoDomainIdn = ko.computed(function() {
            if (self.account && self.account.useridn()) {
                return self.account.useridn().split("@")[1];
            } else {
                return self.usernameSuffix.content();
            }
        });

        this.configInfo = function() {
            var pop; var imap; var smtp;
            if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
                if (FerozoHosting.profileVM().user().Server.Type() == 'External') {
                    pop = {dom: FerozoHosting.profileVM().user().Server.Name(), port: 995, ssl: true};
                    imap = {dom: FerozoHosting.profileVM().user().Server.Name(), port: 993, ssl: true};
                    smtp = {dom: FerozoHosting.profileVM().user().Server.Name(), port: 465, ssl: true};
                } else if (FerozoHosting.profileVM().user().Server.Type() == 'Shared') {
                    if (FerozoHosting.profileVM().user().Server.OpSystem() == 'Linux'
                        && FerozoHosting.profileVM().user().PpalDomain.Name()
                        && FerozoHosting.profileVM().user().PpalDomain.Name().indexOf('.ferozo.com') > 0) {                        
                            pop = {dom: FerozoHosting.profileVM().user().PpalDomain.Name(), port: 995, ssl: true};
                            imap = {dom: FerozoHosting.profileVM().user().PpalDomain.Name(), port: 993, ssl: true};
                            smtp = {dom: FerozoHosting.profileVM().user().PpalDomain.Name(), port: 465, ssl: true};                
                    } else {
                        pop = {dom: FerozoHosting.profileVM().user().Server.ShortName()+'.ferozo.com', port: 995, ssl: true};
                        imap = {dom: FerozoHosting.profileVM().user().Server.ShortName()+'.ferozo.com', port: 993, ssl: true};
                        smtp = {dom: FerozoHosting.profileVM().user().Server.ShortName()+'.ferozo.com', port: 465, ssl: true};
                    }                    
                } else{
                    pop = {dom: FerozoHosting.profileVM().user().Server.ShortName()+'.dattaweb.com', port: 995, ssl: true};
                    imap = {dom: FerozoHosting.profileVM().user().Server.ShortName()+'.dattaweb.com', port: 993, ssl: true};
                    smtp = {dom: FerozoHosting.profileVM().user().Server.ShortName()+'.dattaweb.com', port: 465, ssl: true};
                }                    
            } else {
                pop = {dom: '', port: 110, ssl: false};
                imap = {dom: '', port: 143, ssl: false};
                smtp = {dom: '', port: 587, ssl: false};            
            }
                
            return {pop: pop, imap: imap, smtp: smtp};
        };
        
        var mappingRules = {
            'usernameSuffix': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            },
            'password': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            },
            'quota': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
        this.usernamePrefix.content(self.account.user().split("@")[0]);
        this.usernameSuffix.content(self.account.user().split("@")[1]);
    };

    Email.prototype.remove = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idEmail": self.id()
        }};
        $("#confirm-delete").modal('hide');
        FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(1);
        self.regStatus(4);
        $.postJSON('/hosting/email/removeemailaccount', theData, function() {
            //nada por aquí. //deberia listar
        }).fail(function() {
            self.regStatus(1);
        }).always(function(data) {
            FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(0);
            data.error && self.regStatus(1);
        });
    };

    Email.prototype.changeNameEmailAccount = function() {
        'use strict';
        var self = this;
        if (self.domain() != null) {
            var domain = {"id": self.domain(), "type": "domain" };
        } else {
            var domain = {"id": self.subdomain(), "type": "subdomain" };
        }
        var oSelect=self.getDomByType(domain);
        this.usernamePrefix.content(self.account.user().split("@")[0]);
        FerozoHosting.emailaccountsVM().temp(self);
        FerozoHosting.emailaccountsVM().temp().subdomainDomain(oSelect);
        self.checkEmailAccountUsedByWordpress("rename");
    };

    Email.prototype.renameEmailAccount = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idEmail": self.id(),
            "type": self.subdomainDomain().type,
            "idDomain": self.subdomainDomain().id,
            "usernamePrefix": self.usernamePrefix.content()
        }};

        FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(1);
        self.regStatus(3);
        $.postJSON('/hosting/email/rename', theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i].error == "function") {self[i].error("");}});
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
            } else {
                $('#changeNameEmailAccount').modal('hide');
            }
        }).fail(function() {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(0);
        });
    };

    Email.prototype.getDomByType = function(oDom) {
        var oReturn = false;
        if (oDom.type === "domain") {
            $.each(FerozoHosting.emailaccountsVM().SubdomainsDomains.domains(),function(i,oDomain) {
                if (oDomain.id === oDom.id) {
                    oReturn = oDomain;
                    return;
                }
            });
        }
        if (oDom.type === "subdomain") {
            $.each(FerozoHosting.emailaccountsVM().SubdomainsDomains.subdomains(),function(i,oDomain) {
                if (oDomain.id === oDom.id) {
                    oReturn = oDomain;
                    return;
                }
            });
        }
        return oReturn; 
    };

    Email.prototype.genPass = function() {
       'use strict';
       var self = this;
       self.content($.passGen({'length' : 10, 'numeric' : true, 'lowercase' : true, 'uppercase' : true, 'special' : true}) );
    };
    Email.prototype.save = function() {
        'use strict';
        var self = this;
        $('.modal select').change(); //fix select domain/subdomain

        var configEmailTo =  self.notifyConfigEmail.content();
        if (!self.notifyConfig()) {
            configEmailTo = '';
        }

        var theData = { "params": {
            "idDomain": self.subdomainDomain().id,
            "usernamePrefix": self.usernamePrefix.content(),
            "password": self.password.content(),
            "quota": Number(self.quota.content()),
            "type": self.subdomainDomain().type,
            "notifyConfigEmail": configEmailTo
        }};
        FerozoHosting.emailaccountsVM().flagInstall(true);
        FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(1);
        $.postJSON('/hosting/email/createemailaccount', theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i].error == "function") {self[i].error("");}});
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field] && self[this.field].error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshEmailListPaginated');
                $('.modal').modal('hide');
            }
        }).always(function(){
            FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(0);
            FerozoHosting.emailaccountsVM().flagInstall(false);
        });
    };

    Email.prototype.openChangequota = function() {
        'use strict';
        var self = this;
        if (!FerozoHosting.emailaccountsVM().temp().canUseUnlimitedQuota() && self.quota.content() == -1) {
            self.quota.content(100);
        }
        //var oNewEmail = new Email({"quota":self.quota.content(),"id":self.id(),"regStatus":self.regStatus});
        //FerozoHosting.emailaccountsVM().temp(oNewEmail);
        FerozoHosting.emailaccountsVM().temp(self);
        $('#changequota').modal('show');
    };


    Email.prototype.changequota = function() {
        'use strict';
        var self = this;
        var params = {
            "params": {
                "idEmail": self.id(),
                "quota": Number(self.quota.content())
            }
        };
        self.regStatus(3);
        FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(1);
        $.postJSON('/hosting/email/changeemailquota', params, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
            } else {
                //mediator.publish('refreshEmailList');
                $('.modal').modal('hide');
            }
        }).fail(function() {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(0);
        });
    };

    Email.prototype.openChangepassword = function() {
        'use strict';
        var self = this;
        FerozoHosting.emailaccountsVM().temp(self);
        $('#changepassword').modal('show');
        //$('input#fieldPass2').passwordstrength({"valueInPassFieldId":"prefix"});
    };

    Email.prototype.changepassword = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idEmail": self.id(),
            "password": self.password.content()
        }};

        self.regStatus(3);
        FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(1);
        $.postJSON('/hosting/email/changeemailpassword', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
                FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(0);
            } else {
                //mediator.publish('refreshEmailList');
                $('.modal').modal('hide');
            }
        }).fail(function() {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(0);
        });
    };

    Email.prototype.changestatus = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idEmail": self.id()
        }};

        FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(1);
        self.regStatus(3);
        $.postJSON('/hosting/email/changeemailstatus', theData, function(e) {
            //mediator.publish('refreshEmailList');
            $('.modal').modal('hide');
        }).fail(function() {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(0);
        });
    };

    Email.prototype.getContactEmail = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Contact();
        } else {
            return "";
        }
    };
        
    Email.prototype.sendConfig = function() {
        'use strict';
        var self = this;
        FerozoHosting.emailaccountsVM().inprocessSendConfig(1);
        var theData = { "params": {
            "idEmail": self.id(),
            "notifyConfigEmail": self.notifyConfigEmail.content()
        }};
        $('.help-block.error').html('');
        $.postJSON('/hosting/email/sendconfig', theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i].error == "function") {self[i].error("");}});
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field] && self[this.field].error(this.errorDesc);
                });
            } else {
                $('.modal').modal('hide');
            }
        }).always(function(){
            FerozoHosting.emailaccountsVM().inprocessSendConfig(0);
        });        

        return true;
    };

    Email.prototype.disableAuth = function() {
        'use strict';
        var self = this;
        FerozoHosting.emailaccountsVM().inprocessDisableAuth(true);
        var theData = { "params": {
            "idEmail": self.id(),
        }};
        $.postJSON('/hosting/email/disable2fa', theData, function(response) {
            
        }).always(function(){
            FerozoHosting.emailaccountsVM().inprocessDisableAuth(false);
            FerozoHosting.emailaccountsVM().authFlag(false);
        });        

        return true;
    };


    Email.prototype.updateQuota = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "idEmail": self.id()
        }};

        //FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(1);
        //self.regStatus(3);
        $.postJSON('/hosting/email/quota/used/get', theData, function(e) {
            //mediator.publish('refreshEmailList');
        }).fail(function() {
            //self.regStatus(1);
        }).always(function(data) {
            //data.error && self.regStatus(1);
            //FerozoHosting.emailaccountsVM() && FerozoHosting.emailaccountsVM().inprocess(0);
        });
    };

    Email.prototype.checkEmailAccountUsedByWordpress = function(action) {
        self = this;
        self.isWPAccount(0);
        if (self.account.user().startsWith("no-reply@")) {
            var theData = { "params": {
                "idEmail": self.id()
            }};
            $.postJSON('/hosting/email/checkemailaccountusedbywp', theData, function(data) {
                switch (action) {
                    case "delete":
                        if (data.result > 0) {
                            $("#confirm-delete").modal('show');
                        } else {
                            self.remove();
                        }
                        break;
                    case "disable":
                        if (data.result > 0) {
                            self.isWPAccount(1);   
                        }
                        $('#modal-disable-email').modal('show');
                        break;
                    case "rename":
                        if (data.result > 0) {
                            self.isWPAccount(1);
                        }
                        $('#changeNameEmailAccount').modal('show');
                        break;
                }
            });
        } else {
            switch (action) {
                case "delete":
                    self.remove();
                    break;
                case "disable":
                    $('#modal-disable-email').modal('show');
                    break;
                case "rename":
                    $('#changeNameEmailAccount').modal('show');
                    break;
            }
        }
    }

    return Email;
});