define(['knockout', 'domain', 'email', 'ko.mapping', 'fzPaginatorAjax'], function(ko, Domain, Email, mapping, fzPaginatorAjax) {
    var emailaccountsVM = function() {
        'use strict';
        var self = this;

        ko.mapping = mapping;
        this.title = "";
        this.data = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.SubdomainsDomains = {"domains":ko.observableArray(),"subdomains":ko.observableArray()};
        this.temp = ko.observable(new Email());
        this.inprocess = ko.observable(1);
        this.inprocessSendConfig = ko.observable(0);
        this.inprocessDisableAuth = ko.observable(false);
        this.suspendedFlag = ko.observable(false);
        this.authFlag = ko.observable(false);
        this.flagInstall = ko.observable(false);
        this.currentAccounts = ko.observable('');
        this.serviceId = ko.observable();
        this.maxAccounts = ko.observable('');
        this.setInprocess =  ko.computed({
            read: function () {
                return false;
            },
            write: function(action) {
                if (action == "+") {
                    self.inprocess(self.inprocess()+1);
                } else {
                    if(self.inprocess() <= 0 ) {
                        self.inprocess(0);
                    } else {
                         self.inprocess(self.inprocess()-1);
                    }
                }
            }
        },this);
        this.redirection = ko.observable('');
        this.domainselected = ko.observable('');
        this.emailAccountSelect = ko.observable(new Email());
        this.query = ko.observable('');
        this.showPassword = ko.observable();
        this.readyView =  ko.computed(function(){
            if (this.inprocess() >= 1  ) return "LOADING";
            if (this.inprocess() < 1 && this.SubdomainsDomains.domains().length <= 0  ) return "WO-DOMAINS";
            if (this.inprocess() < 1 && this.SubdomainsDomains.domains().length > 0  ) return "W-DOMAINS";
            return "LOADING";
        },this);
        mediator.installTo(this);
        this.pagination = new fzPaginatorAjax(function() {
            self.listPaginated();
        });

        this.thereAreDisabledAccounts = ko.computed(function() {
            var arr = self.data();
            for (var i in arr) {
                var email = arr[i];
                if (email && !email.active()) {
                    return true;
                }
            }
        }, this);
        this.externalMX = ko.observable();
        this.externalMXTotal = ko.observable();

        /** FILTRO DE TABLA POR JAVASCRIPT **/
        //this.query = ko.observable('');
        //this.dataSearch = ko.observableArray([]);
        //
        //this.search = function(value) {
        //    if (value) {
        //        var regex = new RegExp(value);
        //        self.dataSearch.removeAll();
        //        ko.utils.arrayForEach(self.data(), function(email) {
        //            email.account.user().match(regex) && self.dataSearch.push(email);
        //        });
        //    } else {
        //        self.syncMaster();
        //    }
        //};
        //
        //this.syncMaster = function() {
        //    self.query('');
        //    self.dataSearch(ko.mapping.fromJS(self.data())());
        //};
        //
        //this.data.subscribe(self.syncMaster);
        //this.query.subscribe(self.search);
        /** /FILTRO DE TABLA POR JAVASCRIPT **/

        this.search = function() {
            self.pagination.pageNumber(1);
            self.listPaginated();
        };

        this.clearSearch = function(query) {
            if (query.length < 1 && self.query().length < 1) {
                self.search();
            }
        };

        this.query.subscribe(self.clearSearch);

        this.listPaginated = function() {
            var theData = {
                "filter": self.query()
            };
            self.pagination.ajaxViewModelListing(this, Email, "/hosting/email/listemailaccounts", theData);
        };

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.account.user() == right.account.user() ? 0 : (left.account.user() < right.account.user() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.account.user() == right.account.user() ? 0 : (left.account.user() > right.account.user() ? -1 : 1);
                });
            }
        };

        this.antispamconf = function(oMail) {
            FerozoHosting.emailaccountsVM().emailAccountSelect(oMail);
            window.location.href= "#email/antispam";
        };

        this.subscribe('refreshEmailListPaginated', function() {
            'use strict';
            this.listPaginated();
        });

        this.newmail = function() {
            'use strict';
            //$('input#fieldPass1').passwordstrength({"valueInPassFieldId":"prefix"});
            FerozoHosting.emailaccountsVM().temp().password.reset();
            FerozoHosting.emailaccountsVM().temp().quota.reset();
            if (FerozoHosting.emailaccountsVM().temp().canUseUnlimitedQuota()) {
                FerozoHosting.emailaccountsVM().temp().quota.content(-1);
            } else {
                FerozoHosting.emailaccountsVM().temp().quota.content(100);
            }
            FerozoHosting.emailaccountsVM().temp().usernamePrefix.reset();
            if (FerozoHosting.emailaccountsVM().temp().subdomainDomain() == "" && FerozoHosting.emailaccountsVM().SubdomainsDomains.domains().length > 0) {
                FerozoHosting.emailaccountsVM().temp().subdomainDomain(FerozoHosting.emailaccountsVM().SubdomainsDomains.domains()[0]);
            }

            $("#crearCuentaEmail").modal('show');
         };

        this.loadSubdomainDomain = function() {
            self= this;
            self.setInprocess("+");
            $.postJSON("/hosting/domain/listsubdomaindomains", function(data) {
                if (data.result) {
                    //self.SubdomainsDomains([]);
                    var _dl = [];
                    $.each(data.result.domains, function(index, val) {
                        if (this.name.indexOf('.ferozo.com') < 0 ) {
                            if (this.name.indexOf('.ferozo.net') < 0) {
                                _dl.push(this);
                            }
                        }
                    });
                    var _sdl = [];
                    $.each(data.result.subdomains, function(index, val) {
                        if (this.name.indexOf('.ferozo.com') < 0 ) {
                            if (this.name.indexOf('.ferozo.net') < 0) {
                                _sdl.push(this);
                            }
                        }
                    });
                    self.SubdomainsDomains.domains(_dl);
                    self.SubdomainsDomains.subdomains(_sdl);
                    self.externalMX(data.result.hasexternalmx.join(", "));
                    self.externalMXTotal(data.result.hasexternalmx.length);
                }
            }).always(function() {
                self.setInprocess("-");
            });
        };

        this.init = function() {
            'use strict';
            //mediator.publish('refreshDomainList');//COMENTAR CUANDO NO SE USE MAS SOLO EL DOMINIO, SINO LA EDICION SE ROMPE.
            mediator.publish('refreshEmailListPaginated');
            self.loadSubdomainDomain();
            self.query('');
            $('.modal select').change(); //fix select domain/subdomain

            if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().getServerType() !== "undefined") {
                self.suspendedFlag(FerozoHosting.profileVM().getServerType()==="Shared");
            }
            self.quotaCheck(); 
            self.setInprocess("-");
        };

        this.listEmails = function() {
        };

        this.quotaCheck = function() {
            self= this;
            self.setInprocess("+");
            $.postJSON("/hosting/account/getinfo", function(data) {
                self.serviceId(data.result.idService);
                self.currentAccounts(data.result.Limites.email.usado);
                self.maxAccounts(data.result.Limites.email.total);
            }).always(function() {
                self.setInprocess("-");
            });
        };

        this.redirectMcSpace = function(entity, event) {
            var self = this;
            window.open('https://micuenta.donweb.com/xx-xx/servicios/sitios/'+FerozoHosting.emailaccountsVM().serviceId()+'/configurar/cambio-servicio', '_blank');
        };

        this.openShowInfo = function(entity, event) {
            var cloned = ko.mapping.fromJS(ko.toJS(entity));
            FerozoHosting.emailaccountsVM().temp(cloned);
            var params = {
                "params": {
                    "idEmail": cloned.id()
                }
            };
            if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().getServerType() !== "undefined") {
                if (FerozoHosting.profileVM().getServerType()==="Shared") {
                    $.postJSON('/hosting/email/check2faenable', params, function(data) {
                        if (data.result)
                            FerozoHosting.emailaccountsVM().authFlag(true);
                        else
                            FerozoHosting.emailaccountsVM().authFlag(false);
                    });
                }
            } 
            FerozoHosting.emailaccountsVM().showPassword(false);
            $("#showinfo").modal('show');
        };
        
        this.getForbiddenWords = function() {
            var forbiddenWords = "";
            var prefix = FerozoHosting.emailaccountsVM().temp().usernamePrefix.content();
            var suffix = "";

            if (FerozoHosting.emailaccountsVM().temp().subdomainDomain()) {
                var subdomDom = FerozoHosting.emailaccountsVM().temp().subdomainDomain().name.split(".");
                suffix = subdomDom[0];
            }            
            
            if (prefix != "" && suffix != "" && suffix != prefix) {
                forbiddenWords = prefix + ' - ' + suffix;
            } else if (prefix == "") {
                forbiddenWords = suffix;
            } else {
                forbiddenWords = prefix;
            }
            
            return forbiddenWords;
        };

        this.getForbiddenWordsChangePass = function(completeAccount) {
            var forbiddenWords = "";
            if (completeAccount) {
                var ea = completeAccount.split("@");
                var prefix = ea[0];
                var suffix = ea[1].split(".");
                var suffix = suffix[0];
                if (prefix != suffix) {
                    forbiddenWords = prefix + ' - ' + suffix;
                } else {
                    forbiddenWords = prefix;
                }
            }
            
            return forbiddenWords;
        };

        this.emailAccountDeleteConfirm = function(entity, event) {
            this.temp(entity);
            entity.checkEmailAccountUsedByWordpress("delete");
        };
        
    };

    emailaccountsVM.prototype.showModalDisableAccount = function(entity, event) {
        self = this;
        self.temp(entity);
        entity.checkEmailAccountUsedByWordpress("disable");
    };

    emailaccountsVM.prototype.isWin = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
        } else {
            return false;
        }        
    };
    
    emailaccountsVM.prototype.getHostingSuspended = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Suspended.Value();
        } else {
            return false;
        }
    };    
    
    emailaccountsVM.prototype.getHostingWhiteLabel = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().WhiteLabel();
        } else {
            return false;
        }
    };     
    
    emailaccountsVM.prototype.getAsignedSpaceEmail = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" 
                && typeof FerozoHosting.profileVM().user() !== "undefined"
                && FerozoHosting.profileVM().user().AsignedSpaceEmail() != -1) {
            return "(" + FerozoHosting.profileVM().user().AsignedSpaceEmail()/1024 + "GB)";
        } else {
            return "(-1GB)";
        }        
    };

    emailaccountsVM.prototype.updatequotas = function() {
        self= this;
        self.setInprocess("+");
        var params = {
            "params": {
                "idEmail": 0
            }
        };
        $.postJSON('/hosting/email/quota/used/get', params, function(data) {
            if (data.result) {
            
            }
        }).always(function() {
            self.setInprocess("-");
        });

    };

    emailaccountsVM.prototype.showUpdateUsedSpaceMassiveBtn = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" 
            && typeof FerozoHosting.profileVM().user() !== "undefined"
            && ((FerozoHosting.profileVM().user().EmailsCount() >= 1 
            && FerozoHosting.profileVM().user().EmailsCount() <= 50)
            || FerozoHosting.profileVM().getServerType() !== "Shared"
            || FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux')) {
            return true;
        } else {
            return false;
        }        
    };
    
    return emailaccountsVM;
});






