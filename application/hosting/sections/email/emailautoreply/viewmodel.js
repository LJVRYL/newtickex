define(['knockout', 'domain', 'email', 'emailautoreply', 'ko.mapping'], function(ko, Domain, Email, EmailAutoReply, mapping) {

    var EmailAutoReplyVM = function() {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.title = "";
        this.inprocess = ko.observable(1);
        this.data = ko.observableArray([]);
        this.emails = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.temp = ko.observable(new EmailAutoReply());
        this.redirection = ko.observable(''); // Datos de la redirección
        this.emailsource = ko.observable('');
        this.isEdit = ko.observable(false);
        this.fromsize = ko.observable(false);

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.emailAccount.account.user() == right.emailAccount.account.user() ? 0 : (left.emailAccount.account.user() < right.emailAccount.account.user() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.emailAccount.account.user() == right.emailAccount.account.user() ? 0 : (left.emailAccount.account.user() > right.emailAccount.account.user() ? -1 : 1);
                });
            }
        };

        this.subscribe('emailListUpdated', function(emails) {
            'use strict';
            var self = this;
            var mapping = {
                create: function(options) {
                    return new Email(options.data);
                }, key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };
            ko.mapping.fromJS(emails, mapping, self.emails);
        });

        this.subscribe('refreshEmailAutoReply', function() {
            var self = this;
            $.postJSON("/hosting/email/listemailautoreply", function(data) {
                if (data.result) {
                    self.data([]);
                    $.each(data.result, function() {
                        self.data.push(new EmailAutoReply(this));
                    });
                }
            }).always(function(data) {
                self.inprocess(0);
            });
        });

        this.subscribe('EmailAutoReplyDeleted', function() {
            var self = this;
            mediator.publish('refreshEmailAutoReply');
        });
    };
    

    EmailAutoReplyVM.prototype.openEdit = function(obj,event) {
        'use strict';
        var self = this;
        FerozoHosting.emailautoreplyVM().isEdit(true);
        //var cloned = ko.mapping.fromJS(ko.toJS(obj));
        //FerozoHosting.emailautoreplyVM().temp(cloned);
        
        obj.idEmailAccount(obj.emailAccount.id())
                
        FerozoHosting.emailautoreplyVM().temp(obj);
        $('#makeEmailAutoReply').modal('show');
    };


    EmailAutoReplyVM.prototype.openNew = function() {
        'use strict';
        var self = this;
        self.isEdit(false);
        self.temp(new EmailAutoReply());
        $('#makeEmailAutoReply').modal('show');
    };

    EmailAutoReplyVM.prototype.loadDomains = function() {
        var self = this;
        $.postJSON("/hosting/domain/listdomains", function(data) {
            self.domains([]);
            if (data.result) {
                ko.utils.arrayForEach(data.result, function(obj) {
                    obj.regStatus === 1 && self.domains.push(new Domain(obj));
                });
            }
        });
    };
    
    EmailAutoReplyVM.prototype.init = function() {
        'use strict';
        var self = this;
        mediator.publish('refreshEmailList');
        mediator.publish('refreshEmailAutoReply');
        self.loadDomains();
        $('.datepicker').datepicker();
    };
    
    EmailAutoReplyVM.prototype.isWin = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
        } else {
            return false;
        }        
    };

    EmailAutoReply.prototype.checkvalue = function (data, event) {

        //máximo 50
        //console.log(event.target.value.length);
        //console.log(event.target.value);
        var specialCharacter = '';
        regexp = /\W/g;
        var texto = event.target.value;
        specialCharacter = texto.match(regexp);
        total = event.target.value.length
        if (specialCharacter != null) {
            specialCharacter = specialCharacter.filter(function(e) { return e != ' ' });
            totalchars = total - specialCharacter.length + (specialCharacter.length*3);
        } else {
            totalchars = total;
        }
        
        if (totalchars > 55) {
            FerozoHosting.emailautoreplyVM().fromsize(true);
        } else {
            FerozoHosting.emailautoreplyVM().fromsize(false);
        }

        return true;
    }
    
    return EmailAutoReplyVM;

});