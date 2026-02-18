define(['knockout', 'domain', 'ftp', 'ko.mapping'], function(ko, Domain, Ftp, mapping) {

    var ftpVM = function() {
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;
        this.title = "";
        this.inprocess = ko.observable(1);
        this.data = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.domainselected = ko.observable();
        this.currentAccounts = ko.observable('');
        this.serviceId = ko.observable();
        this.maxAccounts = ko.observable('');
        this.temp = ko.observable(new Ftp());
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
        this.readyView =  ko.computed(function(){
            if (this.inprocess() >= 1  ) return "LOADING";
            if (this.inprocess() < 1 && this.domains().length <= 0  ) return "WO-DOMAINS";
            if (this.inprocess() < 1 && this.domains().length > 0  ) return "W-DOMAINS";
            return "LOADING";
        },this);
        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.account().user == right.account().user ? 0 : (left.account().user < right.account().user ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.account().user == right.account().user ? 0 : (left.account().user > right.account().user ? -1 : 1);
                });
            }
        };

        this.subscribe('refreshFtp', function() {
            var self = this;
             self.setInprocess("+");
            $.postJSON("/hosting/ftp/listftpaccounts", function(data) {
                if (data.result) {
                    var mappingRules = {
                        create: function(options) {
                            return new Ftp(options.data);
                        }, key: function(item) {
                            return ko.utils.unwrapObservable(item.id);
                        }
                    };
                    ko.mapping.fromJS(data.result, mappingRules, self.data);
                    FerozoHosting.profileVM().user().updateFtps(data.result);
                }
            }).always(function(data) {
                 self.setInprocess("-");
            });
        });

        //domainListUpdated: callback al cargar los dominios desde el subscribe('refreshDomainList' en ferozohostingapp.js
        this.subscribe('domainListUpdated', function(domainList) {
            var _dl = [];
            $.each(domainList, function(index, val) {
                if (this.regStatus == 1) {
                    _dl.push(this);
                }
            });

            var mapping = {
                create: function(options) {
                    return new Domain(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(_dl, mapping, self.domains);
             self.setInprocess("-");//la + es en subscribe('refreshDomainList' en ferozohostingapp.js
        });

        this.newftp = function() {
            this.temp(new Ftp());
            $("#apuntar-nuevo").modal();
            $('.modal select').change();
        };

        this.init = function() {
            self.quotaCheck();
            mediator.publish('refreshFtp');
            mediator.publish('refreshDomainList',this);
            self.setInprocess("-");
        };

        this.quotaCheck = function() {
            self= this;
            self.setInprocess("+");
            $.postJSON("/hosting/account/getinfo", function(data) {
                self.serviceId(data.result.idService);
                self.currentAccounts(data.result.Limites.ftp.usado);
                self.maxAccounts(data.result.Limites.ftp.total);
            }).always(function() {
                self.setInprocess("-");
            });
        };

        this.redirectMcSpace = function(entity, event) {
            var self = this;
            window.open('https://micuenta.donweb.com/xx-xx/servicios/sitios/'+FerozoHosting.ftpVM().serviceId()+'/configurar/cambio-servicio', '_blank');
        };

    };

	ftpVM.prototype.modify = function(ftp) {
		'use strict';
		var self = this;
		FerozoHosting.ftpVM().temp(ftp);
		$('#cambiar-passftp').modal('show');
    };

    ftpVM.prototype.showUserInfo = function(obj) {
        'use strict';
        FerozoHosting.ftpVM().temp(obj);
        $('#info-userFTP').modal('show');
    };

    ftpVM.prototype.getPpalDomain = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().PpalDomain.Name();
        } else {
            return "";
        }
    };
    
    return ftpVM;
});