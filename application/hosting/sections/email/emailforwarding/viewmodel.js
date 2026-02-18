define(['knockout', 'domain', 'email', 'emailforwarding', 'ko.mapping', 'fzPaginatorAjax'], function(ko, Domain, Email, EmailForwarding, mapping, fzPaginatorAjax) {

    var EmailForwardingVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;
        this.title = "";
        this.inprocess = ko.observable(1);
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

        this.data = ko.observableArray([]);
        this.emails = ko.observableArray([]);
        this.domains = ko.observableArray([]);
        this.temp = ko.observable(new EmailForwarding());
        this.redirection = ko.observable(''); // Datos de la redirección
        this.emailsource = ko.observable('');
        this.completeAccount = ko.observable('');

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.emailAccount().account.user() == right.emailAccount().account.user() ? 0 : (left.emailAccount().account.user() < right.emailAccount().account.user() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.emailAccount().account.user() == right.emailAccount().account.user() ? 0 : (left.emailAccount().account.user() > right.emailAccount().account.user() ? -1 : 1);
                });
            }
        };

        /* <=[ Subscriptions ]=> */
        this.subscribe('domainListUpdated', function(domainList) {
            'use strict';
            var self = this;


            var mapping = {
                    create: function(options) {
                        return new Domain(options.data);
                    },
                    key: function(item) {
                        return ko.utils.unwrapObservable(item.id);
                    }
            };

            ko.mapping.fromJS(domainList, mapping, self.domains);
            self.setInprocess("-");

        });

        this.subscribe('emailListUpdated', function(emails) {
            'use strict';
            var self = this;


            var mapping = {
                create: function(options) {
                    return new Email(options.data);
                },
                key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };

            ko.mapping.fromJS(emails, mapping, self.emails);

        });

        this.subscribe('refreshEmailForwarding', function() {
            var self = this;
            self.setInprocess("+");
            self.listPaginated();
            self.setInprocess("-");
            // $.postJSON("/hosting/email/listemailforward", function(data) {
            //     if (data.result) {
            //         self.data([]);
            //         $.each(data.result, function() {
            //             self.data.push(new EmailForwarding(this));
            //         });
            //     }
            // }).always(function(data) {
            //     self.setInprocess("-");
            // });
        });

        this.subscribe('emailForwardDeleted', function() {
            var self = this;
            mediator.publish('refreshEmailForwarding');
        });

        this.pagination = new fzPaginatorAjax(function() {
            self.listPaginated();
        });

        this.listPaginated = function() {
            // var theData = {
            //     "filter": self.query()
            // };
            var theData = {};
            
            self.pagination.ajaxViewModelListing(this, EmailForwarding, "/hosting/email/listemailforward", theData);
        };
    };

    EmailForwardingVM.prototype.openNew = function() {
        self=this;
        this.temp(new EmailForwarding());
        $('#crearCuentaEmail').modal('show');
        this.prepareForward();
    }
    EmailForwardingVM.prototype.prepareForward = function(data) {
        if ( typeof data == 'undefined' || typeof self.temp == 'undefined' || typeof data.idEmailAccount == 'undefined') return false;
        var finded=false;
       
        ko.utils.arrayForEach(self.data(), function (item) {
               if (!finded && data.idEmailAccount() == item.emailAccount().id() ) {
                  finded = true;
                  data.keepMailCopy(item.keepMailCopy());
                  self.completeAccount(item.emailAccount().completeAccount());
               }
        });
         if( ! finded ){
              data.disableKeepMailCopy("0");
         }
//        if(finded ){
//            data.disableKeepMailCopy("1");
//        } else {
//                data.disableKeepMailCopy("0");
//        }
   }
   
   EmailForwardingVM.prototype.prepareForwardMessage = function(data) {
        if ( typeof data == 'undefined' || typeof self.temp == 'undefined' || typeof data.idEmailAccount == 'undefined') return false;
        var finded=false;
        var itemFinded=false;
        ko.utils.arrayForEach(self.data(), function (item) {
               if (!finded && data.idEmailAccount() == item.emailAccount().id() ) {
                  finded = true;
                  itemFinded = item;
                  //data.keepMailCopy(item.keepMailCopy());
                  //self.completeAccount(item.emailAccount().completeAccount());
               }
        });
        if( finded ){
            if (itemFinded.keepMailCopy() == data.keepMailCopy()) {
                data.disableKeepMailCopy("0");
            } else {
                data.disableKeepMailCopy("1");
            }
        } else {
                data.disableKeepMailCopy("0");
        }
   }

   EmailForwardingVM.prototype.init = function() {
        'use strict';
        var self = this;

        mediator.publish('refreshEmailList');
        mediator.publish('refreshDomainList',this);
        mediator.publish('refreshEmailForwarding');
        self.setInprocess("-");
    };
    return EmailForwardingVM;
});